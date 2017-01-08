<?php
/**
* Authentication Plugin for authphpbb3.
*
* @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
* @author   Eole <eole.dev@outlook.com>
*/

if (!defined('DOKU_INC')) {
    die();
}

/**
* phpBB 3.x Authentication class.
*/
class auth_plugin_authphpbb3 extends DokuWiki_Auth_Plugin {
    // @var object    phpBB database connection.
    protected $_phpbb_sql_link = null;
    // @var array    phpBB configuration (cached).
    protected $_phpbb_conf = array(
        // @var string    phpBB root path.
        'root_path'     => '',
        // @var string    phpBB URL.
        'url'           => '',
        // @var string     php extension.
        'phpEx'         => '',
        // @var string    phpBB database's host.
        'dbhost'        => '',
        // @var string    phpBB database's name.
        'dbname'        => '',
        // @var string    phpBB database's user.
        'dbuser'        => '',
        // @var string    phpBB database's password.
        'dbpasswd'      => '',
        // @var string    phpBB database's table prefix.
        'table_prefix'  => '',
        // @var string    phpBB cookie's name.
        'cookie_name'   => ''
    );
    // @var int     phpBB user ID.
    protected $_phpbb_user_id = 0;
    // @var int     phpBB user session ID.
    protected $_phpbb_user_session_id = '';
    // @var int     phpBB user type (0 = normal, 1 = inactive, 2 = bot, 3 = founder).
    protected $_phpbb_user_type = 0;
    // @var string  phpBB user name.
    protected $_phpbb_username = '';
    // @var string  phpBB user mail.
    protected $_phpbb_user_email = '';
    // @var array   phpBB user's groups.
    protected $_phpbb_groups = array();
    // @var long    phpBB user session time.
    protected $_phpbb_sessiontime = 0;
    // @var cache   DokuWiki cache object.
    protected $_cache = null;
    // @var int     Cache duration.
    protected $_cache_duration = 0;
    // @var int     Cache extension file name.
    protected $_cache_ext_name = '.phpbb3cache';
    // @var int     Cache unit constant.
    CONST CACHE_DURATION_UNIT = 86400; /* 3600 * 24 = 1 day */

    /**
    * Constructor.
    */
    public function __construct() {
        parent::__construct();
        // Set capabilities accordingly.
        $this->cando['addUser']     = false;    // can Users be created?
        $this->cando['delUser']     = false;    // can Users be deleted?
        $this->cando['modLogin']    = false;    // can login names be changed?
        $this->cando['modPass']     = false;    // can passwords be changed?
        $this->cando['modName']     = false;    // can real names be changed?
        $this->cando['modMail']     = false;    // can emails be changed?
        $this->cando['modGroups']   = false;    // can groups be changed?
        $this->cando['getUsers']    = false;    // can a (filtered) list of users be retrieved?
        $this->cando['getUserCount']= false;    // can the number of users be retrieved?
        $this->cando['getGroups']   = false;    // can a list of available groups be retrieved?
        $this->cando['external']    = true;     // does the module do external auth checking?
        $this->cando['logout']      = true;     // can the user logout again?
        // Load plugin configuration.
        $this->success = $this->load_configuration();
        if (!$this->success) {
            msg($lang['config_error'], -1);
        }
    }

    /**
    * Destructor.
    */
    public function __destruct() {
        $this->phpbb_disconnect();
        $this->_cache = null;
    }

    /**
    * Writes debug informations.
    *
    * @param    string  $msg    Message to write.
    */
    public function dbglog($msg) {
        $class_name = @get_class($this);

        if ($class_name !== false) {
            $msg = $class_name . ': ' . $msg;
        }
        dbglog($msg); 
    }

    /**
    * Sanitizes a given username.
    *
    * @param    string  $username   Username to clean.
    * @return   string              Clean username.
    */
    public function clean_username($username) {
        $username = preg_replace('#(?:[\x00-\x1F\x7F]+|(?:\xC2[\x80-\x9F])+)#', '', $username);
        $username = preg_replace('# {2,}#', ' ', $username);
        $username = trim($username);
        return strtolower($username);
    }

    /**
    * Gets phpBB URL.
    *
    * @return   string|false    phpBB URL if success, false otherwise.
    */
    public function get_phpbb_url() {
        if (!empty($this->_phpbb_conf['url'])) {
            return $this->_phpbb_conf['url'];
        }
        if ($this->use_phpbb_cache()) {
            $result = unserialize($this->_cache->retrieveCache(false));
            if (is_array($result) && array_key_exists('url', $result)) {
                $this->_phpbb_conf['url'] = $result['url'];
            }
        }
        if (!empty($this->_phpbb_conf['url'])) {
            return $this->_phpbb_conf['url'];
        }
        if (!$this->phpbb_connect()) {
            return false;
        }
        $query = "SELECT config_name, config_value
                  FROM {$this->_phpbb_conf['table_prefix']}config
                  WHERE config_name IN ('server_protocol', 'server_name', 'script_path', 'server_port')";
        $result = $this->_phpbb_sql_link->query($query);
        if (!$result) {
            $this->dbglog('no user found in database.');
            return false;
        }
        $server_protocol = '';
        $server_name = '';
        $script_path = '';
        $server_port = '';
        while ($row = $result->fetch_object()) {
            switch ($row->config_name) {
                case 'server_protocol':
                    $server_protocol = trim($row->config_value);
                    break;
                case 'server_name':
                    $server_name = rtrim(trim($row->config_value), '/');
                    break;
                case 'script_path':
                    $script_path = trim($row->config_value);
                    break;
                case 'server_port':
                    $server_port = intval($row->config_value);
                    break;
                default:
                    break;
            }
        }
        if (empty($server_port)) {
            $server_port = '80';
        }
        $server_name = rtrim($server_protocol . $server_name . ':' . $server_port . $script_path, '/');
        $this->_phpbb_conf['url'] = $server_name;
        $result->close();
        unset($row);
        return $this->_phpbb_conf['url'];
    }

    /**
    * Authenticates the user. Called on every page load.
    *
    * @param    string  $user   Case sensitive user name.
    * @param    string  $pass   Plain text password for the user.
    * @param    boolean $sticky Remember login?
    * @return   boolean         True for match, false for everything else.
    */
    public function trustExternal($user, $pass, $sticky = false) {
        global $USERINFO;
        $b = false;

        $this->_phpbb_username = '';
        $this->_phpbb_user_email = '';
        $this->_phpbb_user_session_id = '';
        if (empty($user)) {
            $b = $this->do_login_cookie();
        }
        if (!$b ||
            empty($this->_phpbb_username) ||
            empty($this->_phpbb_user_email)) {
            return false;
        }
        $USERINFO['name'] = utf8_encode($this->_phpbb_username);
        $USERINFO['mail'] = $this->_phpbb_user_email;
        $USERINFO['grps'] = $this->_phpbb_groups;
        $_SERVER['REMOTE_USER'] = $USERINFO['name'];
        $_SESSION[DOKU_COOKIE]['auth']['user'] = $USERINFO['name'];
        $_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;
        return true;
    }

    /**
    * Fetchs user details from phpBB3.
    *
    * @param    string          $user           Case sensitive username.
    * @param    boolean         $requireGroups  Whether or not the returned data must include groups.
    * @return   array/boolean                   False for error conditions and an array for success.
    *                                           array['name']           string  User's name.
    *                                           array['username']       string  User's name.
    *                                           array['email']          string  User's email address.
    *                                           array['phpbb_user_id']  string  User's ID.
    *                                           array['phpbb_profile']  string  User's link to profile.
    *                                           array['grps']           array   Group names the user belongs to.
    */
    public function getUserData($user, $requireGroups = true) {
        if (empty($user)) {
            return false;
        }
        $this->_cache_duration = intval($this->getConf('phpbb_cache'));
        $depends = array('age' => self::CACHE_DURATION_UNIT * $this->_cache_duration);
        $cache = new cache('authphpbb3_getUserData_' . $user, $this->_cache_ext_name);
        $user_data = false;

        if (($this->_cache_duration > 0) && $cache->useCache($depends)) {
            $user_data = unserialize($cache->retrieveCache(false));
        } else {
            $cache->removeCache();
            if (!$this->phpbb_connect()) {
                return false;
            }
            $user = $this->_phpbb_sql_link->real_escape_string($this->clean_username($user));
            $query = "SELECT user_id, username, username_clean, user_email, user_password, user_type
                      FROM {$this->_phpbb_conf['table_prefix']}users
                      WHERE username_clean = '{$user}'";
            $result = $this->_phpbb_sql_link->query($query);
            if (!$result) {
                $this->dbglog('no user found in database.');
                return false;
            }
            $row = $result->fetch_object();
            $this->_phpbb_user_type = (int)$row->user_type;
            $this->_phpbb_user_id = (int)$row->user_id;
            $this->_phpbb_username = $row->username;
            $this->_phpbb_user_email = $row->user_email;
            $result->close();
            unset($row);
            $this->get_phpbb_user_groups();
            $this->get_phpbb_url();
            $user_data = array(
                'name'          => $this->_phpbb_username,
                'username'      => $this->_phpbb_username,
                'email'         => $this->_phpbb_user_email,
                'phpbb_user_id' => $this->_phpbb_user_id,
                'phpbb_profile' => $this->_phpbb_conf['url'] . '/memberlist.php?mode=viewprofile&u=' .
                                   $this->_phpbb_user_id,
                'grps'          => $this->_phpbb_groups
            );
            $cache->storeCache(serialize($user_data));
        }
        $cache = null;
        return $user_data;
    }

    /**
    * Logs off the user.
    */
    public function logOff() {
        if (empty($this->_phpbb_user_session_id)) {
            $this->get_phpbb_cookie_name();
            if (empty($this->_phpbb_conf['cookie_name'])) {
                return ;
            }
            $phpbb_cookie_sid_name = $this->_phpbb_conf['cookie_name'] . '_sid';
            if (array_key_exists($phpbb_cookie_sid_name, $_COOKIE)) {
                $this->_phpbb_user_session_id = $_COOKIE[$phpbb_cookie_sid_name];
            }
        }
        if (!empty($this->_phpbb_user_session_id) && ($this->get_phpbb_url() !== false) && $this->_phpbb_user_id) {
            //global $ID;

            $url = $this->_phpbb_conf['url'] . '/ucp.php?mode=logout&sid=' . $this->_phpbb_user_session_id;
            // phpBB doesn't natively support the logout redirection yet.
            //$url .= '&redirect=' . urlencode(wl($ID, '', true));
            send_redirect($url);
        }
    }

    /**
    * Loads the plugin configuration.
    *
    * @return   boolean   True on success, false otherwise.
    */
    private function load_configuration() {
        if ($this->use_phpbb_cache()) {
            $this->_phpbb_conf = unserialize($this->_cache->retrieveCache(false));
        } else {
            $this->_cache->removeCache();
            $this->_phpbb_conf['root_path'] = DOKU_INC . rtrim(trim($this->getConf('phpbb_root_path')), '/') . '/';
            $this->_phpbb_conf['phpEx'] = substr(strrchr(__FILE__, '.'), 1);
            if (!@file_exists($this->_phpbb_conf['root_path'] . 'config.' . $this->_phpbb_conf['phpEx'])) {
                $this->dbglog('phpBB3 installation cannot be found.');
                return false;
            }
            include($this->_phpbb_conf['root_path'] . 'config.' . $this->_phpbb_conf['phpEx']);
            $this->_phpbb_conf['dbhost'] = $dbhost;
            $this->_phpbb_conf['dbname'] = $dbname;
            $this->_phpbb_conf['dbuser'] = $dbuser;
            $this->_phpbb_conf['dbpasswd'] = $dbpasswd;
            $this->_phpbb_conf['table_prefix'] = $table_prefix;
            foreach (array('dbhost', 'dbname', 'dbuser', 'dbpasswd', 'table_prefix') as $member) {
                if (empty($this->_phpbb_conf[$member])) {
                    $this->dbglog("phpBB3 config variable {$member} not set.");
                    return false;
                }
            }
            if ($this->get_phpbb_url() === false) {
                $this->dbglog('cannot get phpBB URL.');
                return false;
            }
            if (!$this->get_phpbb_cookie_name()) {
                $this->dbglog('cannot get phpBB cookie name.');
                return false;
            }
            $this->_cache->storeCache(serialize($this->_phpbb_conf));
        }
        return (!empty($this->_phpbb_conf['url']) &&
                !empty($this->_phpbb_conf['cookie_name']));
    }

    /**
    * Gets the phpBB configuration cache.
    *
    * @return object    Cache of the phpBB configuration.
    */
    private function get_phpbb_cache() {
        if ($this->_cache === null) {
            $this->_cache = new cache('authphpbb3', $this->_cache_ext_name);
        }
        return $this->_cache;
    }

    /**
    * Can use the phpBB configuration cache.
    *
    * @return object    Cache of the phpBB configuration.
    */
    private function use_phpbb_cache() {
        $depends = array();

        $this->get_phpbb_cache();
        $this->_cache_duration = intval($this->getConf('phpbb_cache'));
        if ($this->_cache_duration > 0) {
            $depends['age'] = self::CACHE_DURATION_UNIT * $this->_cache_duration;
        } else {
            $depends['purge'] = true;
        }
        return $this->_cache->useCache($depends);
    }

    /**
    * Connects to phpBB database.
    *
    * @return   boolean True on success, false otherwise.
    */
    private function phpbb_connect() {
        if (!$this->_phpbb_sql_link) {
            $this->_phpbb_sql_link = new mysqli(
                $this->_phpbb_conf['dbhost'], $this->_phpbb_conf['dbuser'],
                $this->_phpbb_conf['dbpasswd'], $this->_phpbb_conf['dbname']
            );
            if (!$this->_phpbb_sql_link || $this->_phpbb_sql_link->connect_error) {
                $this->dbglog('cannot connect to database server (' . $this->_phpbb_sql_link->connect_errno .')');
                msg($lang['database_error'], -1);
                $this->_phpbb_sql_link = null;
                return false;
            }
            $this->_phpbb_sql_link->set_charset('utf8');
        }
        return ($this->_phpbb_sql_link && $this->_phpbb_sql_link->ping());
    }

    /**
    * Disconnects from phpBB database.
    */
    private function phpbb_disconnect() {
        if ($this->_phpbb_sql_link !== null) {
            $this->_phpbb_sql_link->close();
        }
    }

    /**
    * Gets phpBB cookie's name.
    *
    * @return   boolean True for success, false otherwise.
    */
    private function get_phpbb_cookie_name() {
        if (!empty($this->_phpbb_conf['cookie_name'])) {
            return true;
        }
        if ($this->use_phpbb_cache()) {
            $result = unserialize($this->_cache->retrieveCache(false));
            if (is_array($result) && array_key_exists('cookie_name', $result)) {
                $this->_phpbb_conf['cookie_name'] = $result['cookie_name'];
            }
        }
        if (!empty($this->_phpbb_conf['cookie_name'])) {
            return true;
        }
        if (!$this->phpbb_connect()) {
            return false;
        }
        // Query for cookie_name.
        $query = "SELECT config_name, config_value
                  FROM {$this->_phpbb_conf['table_prefix']}config
                  WHERE config_name = 'cookie_name'";
        $result = $this->_phpbb_sql_link->query($query);
        if (!$result) {
            $this->dbglog('database structure error.');
            return false;
        }
        $row = $result->fetch_object();
        $this->_phpbb_conf['cookie_name'] = $row->config_value;
        $result->close();
        unset($row);
        return true;
    }

    /**
    * Gets phpBB user's groups.
    *
    * @return   boolean True for success, false otherwise.
    */
    private function get_phpbb_user_groups() {
        $this->_phpbb_groups = array();
        $this->_phpbb_user_id = filter_var($this->_phpbb_user_id, FILTER_VALIDATE_INT);
        if (!$this->_phpbb_user_id) {
            return false;
        }
        if (!$this->phpbb_connect()) {
            return false;
        }
        $query = "SELECT *
                  FROM {$this->_phpbb_conf['table_prefix']}groups g, {$this->_phpbb_conf['table_prefix']}users u,
                       {$this->_phpbb_conf['table_prefix']}user_group ug
                  WHERE u.user_id = ug.user_id AND g.group_id = ug.group_id AND u.user_id = {$this->_phpbb_user_id}";
        $result = $this->_phpbb_sql_link->query($query);
        if (!$result) {
            $this->dbglog('cannot get user\'s groups.');
            return false;
        }
        while ($row = $result->fetch_object()) {
            $this->_phpbb_groups[] = $row->group_name;
        }
        // If the user is a founder.
        if ($this->_phpbb_user_type === 3) {
            $this->_phpbb_groups[] = 'admin';
        }
        $result->close();
        unset($row);
        return true;
    }

    /**
    * Authenticate the user using cookie. Called on every page load.
    *
    * @return   boolean True for success, false otherwise.
    */
    private function do_login_cookie() {
        if (!$this->phpbb_connect()) {
            return false;
        }
        if (!$this->get_phpbb_cookie_name()) {
            return false;
        }
        $phpbb_cookie_user_sid = $this->_phpbb_conf['cookie_name'] . '_sid';
        $phpbb_cookie_user_id = $this->_phpbb_conf['cookie_name'] . '_u';
        $this->_phpbb_user_session_id = array_key_exists($phpbb_cookie_user_sid, $_COOKIE) ? $_COOKIE[$phpbb_cookie_user_sid] : null;
        $phpbb_cookie_user_id = array_key_exists($phpbb_cookie_user_id, $_COOKIE) ? intval($_COOKIE[$phpbb_cookie_user_id]) : null;
        if (empty($this->_phpbb_user_session_id) || !ctype_xdigit($this->_phpbb_user_session_id)) {
            $this->dbglog('invalid SID in user\'s cookie.');
            return false;
        }
        // Get session data from database.
        $query = "SELECT session_id, session_user_id
                  FROM {$this->_phpbb_conf['table_prefix']}sessions
                  WHERE session_id = '{$this->_phpbb_user_session_id}'";
        $result = $this->_phpbb_sql_link->query($query);
        if (!$result) {
            $this->dbglog('no session found in database.');
            return false;
        }
        $row = $result->fetch_object();
        if ($phpbb_cookie_user_id !== (int)$row->session_user_id) {
            $this->dbglog('invalid SID/User ID pair.');
            $result->close();
            unset($row);
            return false;
        }
        $this->_phpbb_user_id = (int)$row->session_user_id;
        $this->_phpbb_sessiontime = $row->session_time;
        $result->close();
        unset($row);
        // Update session time.
        $current_time = time();
        if ($current_time > $this->_phpbb_sessiontime) {
            $query = "UPDATE {$this->_phpbb_conf['table_prefix']}sessions
                      SET session_time = '{$current_time}'
                      WHERE session_id = '{$this->_phpbb_user_session_id}'";
            $result = $this->_phpbb_sql_link->query($query);
            if (!$result) {
                $this->dbglog('cannot update session.');
            }
        }
        // Check for guest session.
        if ($this->_phpbb_user_id === 1) {
            return false;
        }
        // Get username from database.
        $query = "SELECT user_id, username, user_email, user_type
                  FROM {$this->_phpbb_conf['table_prefix']}users
                  WHERE user_id = '{$this->_phpbb_user_id}'";
        $result = $this->_phpbb_sql_link->query($query);
        if (!$result) {
            $this->dbglog('no user found in database.');
            return false;
        }
        $row = $result->fetch_object();
        $this->_phpbb_user_type = (int)$row->user_type;
        $this->_phpbb_username = $row->username;
        $this->_phpbb_user_email = $row->user_email;
        $result->close();
        unset($row);
        // Get user groups from database.
        $this->get_phpbb_user_groups();
        return true;
    }
}
?>
