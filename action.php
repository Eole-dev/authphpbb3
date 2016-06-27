<?php
/**
* Action Plugin for authphpbb3.
*
* @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
* @author   Eole <eole.dev@outlook.com>
*/

if (!defined('DOKU_INC') || !defined('DOKU_URL')) {
    die();
}

/**
* Action class for authphpbb3 plugin.
*/
class action_plugin_authphpbb3 extends DokuWiki_Plugin {

    /**
    * Registers a callback function for a given event.
    *
    * @param Doku_Event_Handler $controller.
    */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('HTML_LOGINFORM_OUTPUT', 'BEFORE', $this, 'handle_login_form');
        $controller->register_hook('COMMON_USER_LINK', 'AFTER', $this, 'handle_user_link');
    }

    /**
    * Replaces the DokuWiki form by the phpBB login form.
    *
    * @param Doku_Event    $event    Event.
    * @param object        $param    Parameters.
    */
    public function handle_login_form(&$event, $param) {
        global $auth;
        $url = $this->getConf('phpbb_root_path');
        $cache = null;
        $elem = '';
        $pos = 0;

        if (empty($url)) {
            return ;
        }
        $url = rtrim($url, '/') . '/ucp.php?mode=login';
        // Form's PHP script.
        $event->data->params['action'] = $url;
        // Username field.
        $elem = '<label class="block" for="username"><span style="padding-right:10px">' . $this->getLang('login_login') .
                '</span><input type="text" tabindex="1" name="username" id="username" value="" class="edit"></label><br/>';
        $pos = $event->data->findElementByAttribute('name', 'u');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Password field.
        $elem = '<label class="block" for="password"><span style="padding-right:10px">' . $this->getLang('login_password') .
                '</span><input type="password" tabindex="2" id="password" name="password" autocomplete="off" class="edit"></label><br/>';
        $pos = $event->data->findElementByAttribute('name', 'p');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Remember me check box.
        $elem = '<label class="simple" style="margin-left:20%;" for="autologin">' .
                '<input type="checkbox" name="autologin" id="autologin" tabindex="3">' .
                '<span style="padding-left:5px">' . $this->getLang('login_remember') . '</span></label>';
        $pos = $event->data->findElementByAttribute('name', 'r');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // View online check box.
        $elem = '<label class="simple" style="margin-left:20%;margin-bottom:10px;" for="viewonline">' .
                '<input type="checkbox" name="viewonline" id="viewonline" tabindex="4">' .
                '<span style="padding-left:5px">' . $this->getLang('login_viewonline') . '</span></label>';
        $event->data->insertElement($pos + 1, $elem);
        // Log in button.
        $elem = '<button type="submit" name="login" tabindex="5" value="' . $this->getLang('login_button') . '">' .
                $this->getLang('login_button') . '</button>';
        $pos = $event->data->findElementByType('button');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Hidden field for redirection.
        $elem = '<input type="hidden" name="redirect" value="' . DOKU_URL . '">';
        $event->data->insertElement($pos - 1, $elem);
        // Forum URL.
        $url = $auth->get_phpbb_url();
        if ($url !== false) {
            $event->data->addElement('<p>' . sprintf($this->getLang('login_bottom_text'), $url) . '</p>');
        }
    }

    /**
    * Adds a link to phpBB profile on all users' names.
    *
    * @param Doku_Event    $event    Event.
    * @param object        $param    Parameters.
    */
    public function handle_user_link(&$event, $param) {
        global $auth, $conf;
        $profile = '<a href="%s" class="interwiki iw_user" rel="nofollow" target="_blank">%s</a>';

        if ($conf['showuseras'] !== 'username_link') {
            return ;
        }
        if (empty($event->data['name'])) {
            $event->data['name'] = $event->data['username'];
        }
        $data = $auth->getUserData($event->data['username']);
        if (is_array($data) &&
            array_key_exists('phpbb_profile', $data) &&
            array_key_exists('name', $data) &&
            !empty($data['phpbb_profile']) &&
            !empty($data['name'])) {
            $profile = sprintf($profile, $data['phpbb_profile'], $data['name']);
        } else {
            $profile = sprintf($profile, '#', $event->data['name']);
        }
        $event->data = array(
            'username'    => $event->data['username'],
            'name'        => $event->data['name'],
            'link'        => $event->data['link'],
            'userlink'    => $profile,
            'textonly'    => $event->data['textonly']
        );
    }
}
