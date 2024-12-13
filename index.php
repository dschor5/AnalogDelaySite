<?php

error_reporting(E_ALL);
header('Pragma: no-cache');
date_default_timezone_set("UTC");
require_once('config.inc.php');
header('Access-Control-Allow-Origin: '.$server['http'].$server['site_url']);

function echoErrorHandler($errno, $errstr, $errfile, $errline)
{    
    global $server;
    Logger::error('Main::compile()', 
        array('errno'=>$errno, 'errstr'=>$errstr, 'errfile'=>$errfile, 'errline'=>$errline));
    header('Location: '.$server['http'].$server['site_url'].'/error');
}
set_error_handler("echoErrorHandler");

try
{
    // Force HTTPS. 
    if ((strstr($server['http'], 'https') !== false) && 
        (!isset($_SERVER['HTTPS']) || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off"))
    {
        header('HTTP/1.1 301 Moved Permanently');
        if(isset($_SERVER['HTTP_HOST']))
        {
            header('Location: '.$server['http'].$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']); 
        }
        else
        {
            header('Location: '.$server['http'].$server['site_url']);
        }
        exit;
    }
    $main = Main::getInstance()->compile();
}
catch (Exception $e) 
{
    Logger::error("Main::compile()", array($e));
    header('Location: '.$server['http'].$server['site_url'].'/error');
}

/**
 * Main class.
 */
class Main
{
    /** 
     * Instance of current user. Defaults to null if no user is logged in. 
     */
    private $user = null;

    /**
     * Local copy of parameters read/saved from the website cookie. 
     */
    private static $cookie = array();

    /** 
     * Singleton instance for Main class.
     */
    private static $instance = null;

    /**
     * Singleton constructor for Main class. 
     * Read website cookie and validate user session. 
     */
    private function __construct()
    {
        Logger::init();
        $this->readCookie();
        $this->checkLogin();
    }

    /**
     * Returns singleton instance of Main class. 
     */
    public static function getInstance() : Main
    {
        if(self::$instance == null)
        {
            self::$instance = new Main();
        }

        return self::$instance;
    }

    /**
     * Get list of valid modules for current user.
     * 
     * @return array List of valid modules.
     */
    private function getValidModulesForUser() : array
    {
        global $config;

        $validModules = $config['modules_public'];

        if($this->user != null) 
        {
            if($this->user->is_admin)
            {
                $validModules = $config['modules_admin'];
            }
            else
            {
                $validModules = $config['modules_user'];
            }
        }
        
        return $validModules;
    } 

    /**
     * Load and compile current module. 
     */
    public function compile() 
    {
        global $config;
        $validModules = $this->getValidModulesForUser($this->user);

        // Select current module. 
        $moduleName = 'home';
        if(isset($_POST['action']) && in_array($_POST['action'], $validModules))
        {
            $moduleName = $_POST['action'];
        }
        else if(isset($_GET['action']) && in_array($_GET['action'], $validModules))
        {
            $moduleName = $_GET['action'];
        }
        
        // Load module
        require_once($config['modules_dir'].'/'.$moduleName.'.php');
        $moduleClassName = $moduleName.'Module';
        $module = new $moduleClassName($this->user);

        // Compile module.
        $module->compile();
    }

    /**
     * Read cookie and validate session for current user. If successful, 
     * set $this->user to the current User. 
     * Assumes the website cookie (username & sessionId) were already read.
     */
    public function checkLogin()
    {
        global $config;

        if(isset(self::$cookie['username']) && isset(self::$cookie['sessionId']))
        {
            $username = trim(self::$cookie['username']);
            $sessionId = trim(self::$cookie['sessionId']);

            $usersDao = UsersDao::getInstance();
            $this->user = $usersDao->getByUsername($username, $sessionId);

            if($this->user != null)
            {
                $subaction = $_GET['subaction'] ?? '';
                if($subaction != 'stream')
                {
                    $this->setSiteCookie(array('sessionId'=>$sessionId, 'username'=>$username));
                }
            }
            else
            {
                $this->user = null;
                $this->deleteCookie();
            }

        }
    }

    /** 
     * Set website cookie with associative array. 
     * Saves a local copy of the array so that this function can be called 
     * multiple times with new parameters if needed. 
     * 
     * @param array $data Associative array of key->value pairs to add to the cookie.
     */
    public static function setSiteCookie($data)
    {
        global $config;

        $missionConfig = MissionConfig::getInstance();

        // Note: The cookie expires in 10 min. If the user is logged in an active, 
        // the heartbeat messages should keep that going longer. 
        self::$cookie['expiration'] = time() + $missionConfig->login_timeout * 60;
        foreach ($data as $key => $val)
        {
            self::$cookie[$key] = $val;
        }

        $cookieStr = http_build_query(self::$cookie);

        setcookie(
            $config['cookie_name'], 
            $cookieStr, 
            self::$cookie['expiration'],
            '/');
            /*, 
            $server['site_url'],
            ($server['http'] == 'https://'),
            true
        );*/
    }

    /**
     * Delete a cookie. Clear data and set it to expire.
     *
     * @return void
     */
    public static function deleteCookie()
    {
        global $config;
        setcookie($config['cookie_name'], '', -1, '/');
    }

    /**
     * Accessor for cookie contents. 
     *
     * @param string $key
     * @return string|null
     */
    public static function getCookieValue(string $key)  
    {
        return self::$cookie[$key] ?? null;
    }

    /**
     * Read the site cookie and save content into a static variable. 
     *
     * @return void
     */
    private function readCookie()
    {
        global $config;

        if(isset($_COOKIE[$config['cookie_name']]))
        {
            self::$cookie = array();
            parse_str($_COOKIE[$config['cookie_name']], self::$cookie);
        }
    }

    /**
     * Load a template file and replace content before returning it to 
     * display/send to the user. 
     *
     * @param string $template Template to load
     * @param array $replace Associative array with keywords to replace. 
     * @param string $dir Directory where the template is stored
     * @return string Tenmplate contents with parameters replaced
     */
    public static function loadTemplate(string $template, array $replace=null, string $dir='modules/') : string 
    {
        global $config;
        global $server;

        // Load the file
        $template = file_get_contents($config['templates_dir'].'/'.$dir.$template);

        // Local keywords to replace
        if($replace != null)
        {
            $template = preg_replace(array_keys($replace), array_values($replace), $template);
        }

        // Global replace keywords
        $replace = array(
            '/%http%/'             => $server['http'],
            '/%site_url%/'         => $server['site_url'],
            '/%templates_dir%/'    => $config['templates_dir']);
        $template = preg_replace(array_keys($replace), array_values($replace), $template);

        return $template;
    }

    /**
     * Detect mobile device. 
     *
     * @return Returns true if it is a mobile device.
     */
    static function detectMobile()
    {
        $isMobile = false;
        $useragent=$_SERVER['HTTP_USER_AGENT'];
        
        // Regex copied from http://detectmobilebrowsers.com/
        if(preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry'. 
                      '|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|'. 
                      'iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|'. 
                      'opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pock'. 
                      'et|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodaf'. 
                      'one|wap|windows ce|xda|xiino/i',$useragent) ||
           preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac'. 
                      '(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu'. 
                      '|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|'. 
                      'nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|cap'. 
                      'i|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll'. 
                      '|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|'. 
                      'ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly'. 
                      '(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|ha'. 
                      'ie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c('. 
                      '\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |'. 
                      '\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|j'. 
                      'bro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k'. 
                      ')|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-'. 
                      'w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi'. 
                      '(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p'. 
                      '1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7('. 
                      '0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|'. 
                      'op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c'. 
                      '))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g'. 
                      '|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks'. 
                      '|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|'. 
                      'p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)'. 
                      '|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v'. 
                      '\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|t'. 
                      'dg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-'. 
                      '9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3'. 
                      ']|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w'. 
                      '3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your'. 
                      '|zeto|zte\-/i', substr($useragent,0,4))
            )
        {
            $isMobile = true;
        }

        return $isMobile;
    }
}

?>
