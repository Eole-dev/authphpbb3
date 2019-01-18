<?php
/**
 * Action Plugin for authphpbb3.
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Eole <eole.dev@outlook.com>
 */

if (!defined('DOKU_INC')) {
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
        global $auth;
        
        if (!$auth || (get_class($auth) !== 'auth_plugin_authphpbb3')) {
            return;
        }
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
        global $ID;
        $use_inline_css = $this->getConf('phpbb_inline_style');
        $inline_css1 = '';
        $inline_css2 = '';
        $phpbb_url = '';
        $cache = null;
        $elem = '';
        $pos = 0;

        if (!$auth || (get_class($auth) !== 'auth_plugin_authphpbb3')) {
            return;
        }
        $phpbb_url = $auth->get_phpbb_url();
        if ($phpbb_url === false) {
            return ;
        }
        $phpbb_url = rtrim($phpbb_url, '/');
        // Form's PHP script.
        $event->data->params['action'] = $phpbb_url . '/ucp.php?mode=login';
        // Username field.
        $inline_css1 = ($use_inline_css ? ' style="padding-right:10px"' : '');
        $elem = '<label class="block" for="username">' .
                    '<span' . $inline_css1 . '>' . $this->getLang('login_login') . '</span>' .
                    '<input type="text" tabindex="1" name="username" id="username" class="edit">' .
                '</label><br/>';
        $pos = $event->data->findElementByAttribute('name', 'u');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Password field.
        $inline_css1 = ($use_inline_css ? ' style="padding-right:10px"' : '');
        $elem = '<label class="block" for="password">' .
                    '<span' . $inline_css1 . '>' . $this->getLang('login_password') . '</span>' .
                    '<input type="password" tabindex="2" name="password" id="password" class="edit">' .
                '</label><br/>';
        $pos = $event->data->findElementByAttribute('name', 'p');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Remember me check box.
        $inline_css1 = ($use_inline_css ? ' style="margin-left:20%;margin-bottom:10px;"' : '');
        $inline_css2 = ($use_inline_css ? ' style="padding-left:5px"' : '');
        $elem = '<label class="simple"' . $inline_css1 . ' for="autologin">' .
                    '<input type="checkbox" name="autologin" id="autologin" tabindex="3">' .
                    '<span' . $inline_css2 . '>' . $this->getLang('login_remember') . '</span>' .
                '</label>';
        $pos = $event->data->findElementByAttribute('name', 'r');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // View online check box.
        $inline_css1 = ($use_inline_css ? ' style="margin-left:20%;margin-bottom:10px;"' : '');
        $inline_css2 = ($use_inline_css ? ' style="padding-left:5px"' : '');
        $elem = '<label class="simple"' . $inline_css1 . ' for="viewonline">' .
                    '<input type="checkbox" name="viewonline" id="viewonline" tabindex="4">' .
                    '<span' . $inline_css2 . '>' . $this->getLang('login_viewonline') . '</span>' .
                '</label>';
        $event->data->insertElement($pos + 1, $elem);
        // Log in button.
        $elem = '<button type="submit" name="login" tabindex="5" value="' . $this->getLang('login_button') . '">' .
                    $this->getLang('login_button') .
                '</button>';
        $pos = $event->data->findElementByType('button');
        if ($pos === false) {
            return ;
        }
        $event->data->replaceElement($pos, null);
        $event->data->insertElement($pos, $elem);
        // Hidden field for redirection.
        $elem = '<input type="hidden" name="redirect" value="' . wl($ID, '', true) . '">';
        $event->data->insertElement($pos - 1, $elem);
        // Forum URL.
        $event->data->addElement('<p>' . sprintf($this->getLang('login_bottom_text'), $phpbb_url) . '</p>');
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

        if (($conf['showuseras'] !== 'username_link') || $event->data['textonly']) {
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
