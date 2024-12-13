<?php

/**
 * ErrorModule displays the error page and logs an error.
 * 
 * @link https://github.com/dschor5/ECHO
 */
class ErrorModule extends DefaultModule
{
    /**
     * Constructor. 
     *
     * @param User $user Current logged in user (if any)
     */
    public function __construct(&$user)
    {
        parent::__construct($user);
        $this->subJsonRequests = array();
        $this->subHtmlRequests = array(
            'default'      => 'showError');
    }

    /**
     * Show error page and log the event.
     *
     * @return string
     */
    protected function showError() : string
    {
        $this->addTemplates('common.css', 'settings.css');
        $username = ($this->user == null) ? 'n/a' : $this->user->username;
        Logger::warning('error:compileHtml user='.$username.
            ', GET='.json_encode($_GET).
            ', POST='.json_encode($_POST). 
            ', SERVER_REQUEST_URI='.json_encode($_SERVER['REQUEST_URI']). 
            ', SERVER_REDIRECT_URL='.json_encode($_SERVER['REDIRECT_URL']));
        return Main::loadTemplate('error.txt');
    }    
}


?>