<?php

/**
 * HomeModule manages main screen, login, and logout.
 * 
 * @link https://github.com/dschor5/ECHO 
 */
class HomeModule extends DefaultModule
{
    /**
     * Constructor. 
     *
     * @param User|null $user Current logged in user or null. 
     */
    public function __construct(?User &$user)
    {
        parent::__construct($user);

        // Acceptable request types. 
        $this->subJsonRequests = array(
            'reset'      => 'resetPassword',
            'login'      => 'login',
            'heartbeat'  => 'heartbeat');
        $this->subHtmlRequests = array(
            'logout'     => 'logout',
            'checkLogin' => 'checkLogin',
            'default'    => 'showHomepage');
    }

    /**
     * Return page header.
     *
     * @return string
     */
    public function getHeader() : string
    {
        return '';
    }

    /**
     * Compile page. 
     *
     * @param string $subaction 
     * @return string
     */
    public function compileHtml(string $subaction): string
    {
        global $server;
        if($subaction == 'logout')
        {
            $this->logout();
        }
        elseif($subaction == 'checkLogin')
        {
            if($this->user != null && $this->user->is_password_reset)
            {
                return $this->showResetPage();
            }
            else
            {
                header('Location: '.$server['http'].$server['site_url'].'/chat');
            }
        }
        else if($this->user != null)
        {
            header('Location: '.$server['http'].$server['site_url'].'/chat');
        }

        return $this->showHomepage();
    }

    /**
     * Returns 
     *
     * @return string
     */
    protected function checkLogin() : string 
    {
        global $server;

        if($this->user->reset_password == 0) 
        {
            header('Location: '.$server['http'].$server['site_url'].'/chat');
        }
        
        return 'RESET';
    }

    /**
     * Show page ot reset the user password. 
     *
     * @return string HTML page. 
     */
    protected function showResetPage() : string
    {
        $this->addTemplates('login-reset.js', 'login.css');
        return Main::loadTemplate('home-reset.txt', array('/%username%/' => $this->user->username));
    }

    /**
     * Show homepage. 
     *
     * @return string HTML page
     */
    protected function showHomepage() : string
    {
        global $server;
        $content = '';

        if($this->user != null)
        {
            if($this->user->is_password_reset)
            {
                $content = $this->showResetPage();
            }
            else
            {
                header('Location: '.$server['http'].$server['site_url'].'/chat');
            }
        }
        else
        {
            $this->addTemplates('login.js', 'login.css');
            $content = Main::loadTemplate('home.txt', array());
        }
        
        return $content;
    }

    /**
     * Handle AJAX request to reset the user password. 
     * Returns success=true if the user password was successfully changed. 
     * 
     * Password rules enforced:
     * - Min 8 characters
     * - 1+ uppercase character
     * - 1+ lowercase character
     * - 1+ number
     * - 1+ symbol
     *
     * @return array Associative array for JSON response.
     */
    protected function resetPassword() : array
    {
        global $config;
        $response = array('success' => false);

        // If the two user passwords are set. 
        if(isset($_POST['password1']) && isset($_POST['password2']))
        {
            // Passwords must be equal.
            if($_POST['password1'] != $_POST['password2'])
            {
                $response['message'] = 'Passwords do not match.';
            }
            // Passwords must be at least 8 chars long.
            else if(strlen($_POST['password1']) < 8)
            {
                $response['message'] = 'Password must be at least 8 characters long.';
            }
            else
            {
                $uppercase = preg_match('/[A-Z]/', $_POST['password1']);
                $lowercase = preg_match('/[a-z]/', $_POST['password1']);
                $number    = preg_match('/[0-9]/', $_POST['password1']);
                $special   = preg_match('/[\W_]/', $_POST['password1']);

                if(!$uppercase || !$lowercase || !$number || !$special)
                {
                    $response['message'] = 'Password must contain at least '.
                                           'one upper case letter, '. 
                                           'one lower case letter, '. 
                                           'one number, and '. 
                                           'one special character.';
                }
                else
                {
                    // Update user password. 
                    if($this->user !== false && $this->user != null)
                    {
                        $usersDao = UsersDao::getInstance();
                        $password = User::encryptPassword($_POST['password1']);

                        if(($response['success'] = $usersDao->resetPassword($password, false, $this->user->user_id)) !== false)
                        {
                            // Delete the current cookie and force the user to login again.
                            Main::deleteCookie();
                        }
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Handle AJAX request to reset the user password. 
     * Returns success=true if the username & password are found in the database.
     * 
     * @return array Associative array for JSON response.
     */
    protected function login() : array
    {
        global $config;
        $response = array('login' => false);

        // Check that the username and password were submitted.
        if(isset($_POST['uname']) && isset($_POST['upass']))
        {
            // Get the user by that name. 
            $usersDao = UsersDao::getInstance();
            $user = $usersDao->getByUsername($_POST['uname']);

            // Check if the password provided matches what the user account.
            if($user != null && $user->isValidPassword($_POST['upass']))
            {
                // If so, crease a new session and update the database.
                $this->user = $user;
                $sessionId = $user->createNewSession();

                Logger::info('User '.$user->username.' logged in from '.$_SERVER['REMOTE_ADDR'].'.');
   
                if ($usersDao->updateLoginInfo($sessionId, $this->user->user_id) !== false)
                {
                    Main::setSiteCookie(array('sessionId'=>$sessionId,'username'=>$_POST['uname']));
                    $response['login'] = true;
                }
            }
        }

        return $response;
    }

    /**
     * Logout and delete the session cookie. 
     *
     * @return string Message to be display while redirecting. 
     */
    protected function logout() : string
    {
        global $server;

        $this->user = null;
        Main::deleteCookie();
        header('Location: '.$server['http'].$server['site_url']);
        return 'Logging out, please wait while you are redirected to the homepage.';
    }    

    /**
     * Handle AJAX request to reset the user password. 
     * The only purpose is to reset the cookie. 
     *
     * @return array
     */
    protected function heartbeat() : array 
    {
        return array('success' => ($this->user != null));
    }
}

?>