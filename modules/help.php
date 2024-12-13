<?php

/**
 * HelpModule displays the help pages.
 * 
 * @link https://github.com/dschor5/ECHO 
 */
class HelpModule extends DefaultModule
{
    /**
     * Constructor. Loads valid options based on whether the 
     * user is an administrator and what options are enabled.
     *
     * @param User $user Current logged in user. 
     */
    public function __construct(&$user)
    {
        parent::__construct($user);
        $this->subJsonRequests = array();
        $this->subHtmlRequests = array(
            'default'      => 'showHelpOverview');

        $mission = MissionConfig::getInstance();
        if($mission->feat_markdown_support)
        {
            $this->subHtmlRequests['markdown'] = 'showHelpMarkdown';
        }

        if($this->user->is_admin)
        {            
            $this->subHtmlRequests['users'] = 'showHelpAdmUsers';
            $this->subHtmlRequests['delay'] = 'showHelpAdmDelay';
            $this->subHtmlRequests['mission'] = 'showHelpAdmMission';
            $this->subHtmlRequests['data'] = 'showHelpAdmData';
        }
    }

    /**
     * Show help overview page.
     *
     * @return string HTML output
     */
    protected function showHelpOverview() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        $mission = MissionConfig::getInstance();

        $markdown = '';
        if($mission->feat_markdown_support)
        {
            $markdown = Main::loadTemplate('help-overview-markdown.txt');
        }

        $important = '';
        if($mission->feat_important_msgs)
        {
            $important = Main::loadTemplate('help-overview-important.txt');
        }

        $threads = '';
        if($mission->feat_convo_threads)
        {
            $threads = Main::loadTemplate('help-overview-threads.txt');
        }

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-overview.txt'),
            '/%markdown%/' => $markdown,
            '/%important%/' => $important,
            '/%threads%/' => $threads
        ));
    }

    /**
     * Show help Markdown page
     *
     * @return string HTML output
     */
    protected function showHelpMarkdown() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-markdown.txt'),
        ));
    }

    /**
     * Show help page for managing user accounts.
     *
     * @return string HTML output
     */
    protected function showHelpAdmUsers() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-adm-users.txt'),
        ));
    }

    /**
     * Show help page for managing communication delay settings.
     *
     * @return string HTML output
     */
    protected function showHelpAdmDelay() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-adm-delay.txt'),
        ));
    }

    /**
     * Show help page for managing mission settings.
     *
     * @return string HTML output
     */
    protected function showHelpAdmMission() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-adm-mission.txt'),
        ));
    }

    /**
     * Show help page for saving archives, SQL backups, and logs.
     * 
     * @return string HTML output
     */
    protected function showHelpAdmData() : string
    {
        $this->addTemplates('common.css', 'settings.css');

        return Main::loadTemplate('help.txt', array(
            '/%menu%/' => $this->showHelpMenu(),
            '/%content%/' => Main::loadTemplate('help-adm-data.txt'),
        ));
    }

    /**
     * Builds links for help menu.
     *
     * @return string HTML output
     */
    private function showHelpMenu() : string 
    {
        $mission = MissionConfig::getInstance();

        $links = array(
            'overview' => 'Getting Started'
        );

        if($this->user->is_admin || $mission->feat_markdown_support)
        {
            $links['markdown'] = 'Markdown Basics';
        }

        if($this->user->is_admin)
        {
            $links['users'] = 'User Accounts';
            $links['delay'] = 'Delay Settings';
            $links['mission'] = 'Mission Settings';
            $links['data'] = 'Data Management';
        }

        $current = $_GET['subaction'] ?? 'overview';

        $linkHtml = '';
        foreach($links as $url => $text)
        {
            if($current == $url)
            {
                $linkHtml .= '<li><b>'.$text.'</b></li>';
            }
            else
            {
                $linkHtml .= '<li><a href="%http%%site_url%/help/'.$url.'">'.$text.'</a></li>';
            }
        }

        return $linkHtml;
    }
}


?>