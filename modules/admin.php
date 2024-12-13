<?php

class AdminModule extends DefaultModule
{
    /**
     * Options for the login timeout. 
     */
    const TIMEOUT_OPS_SEC = array(
            '20' => '20 sec',
          '1800' => '30 min', 
          '3600' => '60 min (1 hr)', 
          '7200' => '120 min (2 hr)', 
         '86400' => '1440 min (24 hr)', 
        '172800' => '2880 min (48 hr)', 
        '604800' => '10080 min (7 days)'
    );

    /**
     *  Regex to validate a floating point number.
     */
    const FLOAT_FORMAT_REGEX  = '/^[\d]*[\.]?[\d]+$/';

    

    public function __construct(&$user)
    {
        parent::__construct($user);
        $this->subJsonRequests = array(
            // Mission Settings
            'save_mission'   => 'saveMissionSettings', 
            // Delay Settings
            'save_delay'     => 'saveDelaySettings', 
            // User Settings
            'getuser'        => 'getUser', 
            'edituser'       => 'editUser',
            'deleteuser'     => 'deleteUser', 
            'resetuser'      => 'resetUserPassword', 
            'activateuser'   => 'activateUser',
            'deactivateuser' => 'deactivateUser',
            // Data Management
            'clear'          => 'clearMissionData',
            'resetlog'       => 'resetSystemLog', 
            'backupsql'      => 'backupSqlDatabase', 
            'backupconvo'    => 'backupConversations',
            'backuplog'      => 'backupSystemLog',
            'deletearchive'  => 'deleteArchive',  
            'backupstatus'   => 'backupConversationStatus');

        $this->subHtmlRequests = array(
            // Mission settings
            'mission'      => 'editMissionSettings', 
            // Delay settings
            'delay'        => 'editDelaySettings', 
            // User Settings
            'users'        => 'listUsers',
            // Data Management
            'data'         => 'editDataManagement',
            // Default
            'default'      => 'editMissionSettings',
        );
    }

    /***********************/
    /* Mission Settings    */
    /***********************/

    protected function saveMissionSettings(): array
    {
        $response = array(
            'success' => false, 
            'error'   => array()
        );

        $data = array();

        $STR_FMT    = '/^.+$/';
        $DATE_FMT   = '/^[\d]{4}-[\d]{2}-[\d]{2}$/';
        
        // List of fields to validate automatically. 
        $fields = array(
            'name'          => array('name'=>'Mission Name',              'format'=>$STR_FMT),
            'date_start'    => array('name'=>'Mission Start Date',        'format'=>$DATE_FMT),
            'date_end'      => array('name'=>'Mission End Date',          'format'=>$DATE_FMT),
            'mcc_name'      => array('name'=>'Mission Control Name',      'format'=>$STR_FMT),
            'mcc_planet'    => array('name'=>'Mission Control Planet',    'format'=>$STR_FMT),
            'mcc_user_role' => array('name'=>'Mission Control User Role', 'format'=>$STR_FMT),
            'mcc_timezone'  => array('name'=>'Mission Control Timezone',  'format'=>$STR_FMT),
            'hab_name'      => array('name'=>'Analog Habitat Name',       'format'=>$STR_FMT),
            'hab_planet'    => array('name'=>'Analog Habitat Planet',     'format'=>$STR_FMT),
            'hab_user_role' => array('name'=>'Analog Habitat User Role',  'format'=>$STR_FMT),
            'hab_timezone'  => array('name'=>'Analog Habitat Timezone',   'format'=>$STR_FMT),
            'hab_day_name'  => array('name'=>'Analog Habitat Day Name',   'format'=>$STR_FMT),
            'login_timeout' => array('name'=>'Config Timeout',            'format'=>$STR_FMT),
        );

        foreach($fields as $name => $validation)
        {
            $temp = $_POST[$name] ?? '';
            $temp = trim($temp);
            if(strlen($temp) == 0)
            {  
                $response['error'][] = 'Field "'.$validation['name'].'" cannot be empty. ('.$temp.')';
            }
            elseif(!preg_match($validation['format'], $temp))
            {
                $response['error'][] = 'The format for "'.$validation['name'].'" is invalid. ('.$temp.')';
            }
            else
            {
                $data[$name] = $temp;
            }
        }

        $featureEnableState = array(
            'feat_audio_notification',
            'feat_badge_notification',
            'feat_unread_msg_counts',
            'feat_convo_list_order',
            'feat_est_delivery_status',
            'feat_progress_bar',
            'feat_markdown_support',
            'feat_important_msgs',
            'feat_convo_threads',    
            'feat_out_of_seq',
            'feat_convo_threads_all',    
            'debug'        
        );

        foreach($featureEnableState as $feature)
        {
            $data[$feature] = $_POST[$feature] ?? '0';
        }

        // Additional checks if all required fields are filled and the right format.
        if(count($response['error']) == 0)
        {
            $data['date_start'] = $data['date_start'].' 00:00:00';
            $data['date_end'] = $data['date_end'].' 23:59:59';
            $dateStart = new DateTime($data['date_start']);
            $dateEnd = new DateTime($data['date_end']);
            if($dateStart->getTimestamp() > $dateEnd->getTimestamp()) 
            {
                $response['error'][] = 'The "Mission Start Date" cannot be after the "Mission End Date".';
            }

            $timezones = DateTimeZone::listIdentifiers();
            if(!in_array($data['mcc_timezone'], $timezones))
            {
                $response['error'][] = 'Invalid "Mission Control Timezone" selected.';
            }
            if(!in_array($data['hab_timezone'], $timezones))
            {
                $response['error'][] = 'Invalid "Analog Habitat Timezone" selected.';
            }

            if(!array_key_exists($data['login_timeout'], self::TIMEOUT_OPS_SEC))
            {
                $response['error'][] = 'Invalid "Login Timeout" selected.';
            }
        }

        $response['success'] = count($response['error']) == 0;
        if($response['success'])
        {
            $mission = MissionConfig::getInstance();
            $data['date_start'] = DelayTime::convertTimestampTimezone(
                $data['date_start'], $mission->hab_timezone, 'UTC');
            $data['date_end'] = DelayTime::convertTimestampTimezone(
                $data['date_end'], $mission->hab_timezone, 'UTC');

            $messagesDao = MessagesDao::getInstance();
            if($data['feat_convo_threads'] != $mission->feat_convo_threads)
            {
                // Turning off threads
                if($data['feat_convo_threads'] == '0')
                {                    
                    $messagesDao->renumberSiteMessageId(false);
                }
                // Enabling threads
                else
                {
                    $messagesDao->renumberSiteMessageId(true);
                }
            }

            $missionDao = MissionDao::getInstance();
            $missionDao->updateMissionConfig($data);
            Logger::info('Save mission settings.', $data);
        }
        
        return $response;
    }

    private function getTimezoneList()
    {
        $list = DateTimeZone::listAbbreviations();
        $idents = DateTimeZone::listIdentifiers();
        $data = array();
        foreach ($list as $key => $zones) {
            foreach ($zones as $id => $zone) {
                if ($zone['timezone_id'] and in_array($zone['timezone_id'], $idents)) {
                    $offset = round(abs($zone['offset'] / 3600));
                    $sign = $zone['offset'] > 0 ? '+' : '-';
                    if ($offset == 0) {
                        $sign = ' ';
                        $offset = '';
                    }
                    $zone['label'] = 'GMT' . $sign . $offset . ' - ' . $zone['timezone_id'];
                    $data[$zone['offset']][$zone['timezone_id']] = $zone;
                }
            }
        }
        ksort($data);
        $timezones = array();
        foreach ($data as $offsets) {
            ksort($offsets);
            foreach ($offsets as $zone) {
                $timezones[] = $zone;
            }
        }
        return $timezones;
    }

    protected function editMissionSettings() : string
    {
        $this->addTemplates('settings.css', 'admin.js', 'globalize.js', 'globalize.culture.de-DE.js');
        $mission = MissionConfig::getInstance();
        
        $timezoneData = $this->getTimezoneList();
        $mccTimezoneOptions = '';
        $habTimezoneOptions = '';
        foreach($timezoneData as $tz) 
        {
            $mccTimezoneOptions .= $this->makeSelectOption($tz['timezone_id'], $tz['label'], $mission->mcc_timezone == $tz['timezone_id']);
            $habTimezoneOptions .= $this->makeSelectOption($tz['timezone_id'], $tz['label'], $mission->hab_timezone == $tz['timezone_id']);
        }

        $timeoutOptions = '';
        foreach(self::TIMEOUT_OPS_SEC as $timeout_sec => $timeout_label)
        {
            $timeoutOptions .= $this->makeSelectOption($timeout_sec, $timeout_label, $mission->login_timeout == intval($timeout_sec));
        }

        $missionStartDate = DelayTime::convertTimestampTimezone(
            $mission->date_start, 'UTC', $mission->hab_timezone);
        $missionEndDate = DelayTime::convertTimestampTimezone(
            $mission->date_end, 'UTC', $mission->hab_timezone);
        
        return Main::loadTemplate('admin-mission.txt', array(
            '/%name%/'            => $mission->name,
            '/%date_start%/'      => substr($missionStartDate, 0, 10),
            '/%date_end%/'        => substr($missionEndDate, 0, 10),
            '/%mcc_name%/'        => $mission->mcc_name,
            '/%mcc_planet%/'      => $mission->mcc_planet,
            '/%mcc_user_role%/'   => $mission->mcc_user_role,
            '/%mcc_timezone%/'    => $mccTimezoneOptions,
            '/%hab_name%/'        => $mission->hab_name,
            '/%hab_planet%/'      => $mission->hab_planet,
            '/%hab_user_role%/'   => $mission->hab_user_role,
            '/%hab_timezone%/'    => $habTimezoneOptions,
            '/%hab_day_name%/'    => $mission->hab_day_name,
            '/%timeout-options%/' => $timeoutOptions,
            '/%feat_audio_notification_checked%/'  => $mission->feat_audio_notification  == '1' ? 'checked' : '',
            '/%feat_badge_notification_checked%/'  => $mission->feat_badge_notification  == '1' ? 'checked' : '',
            '/%feat_unread_msg_counts_checked%/'   => $mission->feat_unread_msg_counts   == '1' ? 'checked' : '',
            '/%feat_convo_list_order_checked%/'    => $mission->feat_convo_list_order    == '1' ? 'checked' : '',
            '/%feat_est_delivery_status_checked%/' => $mission->feat_est_delivery_status == '1' ? 'checked' : '',
            '/%feat_progress_bar_checked%/'        => $mission->feat_progress_bar        == '1' ? 'checked' : '',
            '/%feat_markdown_support_checked%/'    => $mission->feat_markdown_support    == '1' ? 'checked' : '',
            '/%feat_important_msgs_checked%/'      => $mission->feat_important_msgs      == '1' ? 'checked' : '',
            '/%feat_convo_threads_checked%/'       => $mission->feat_convo_threads       == '1' ? 'checked' : '',
            '/%feat_out_of_seq_checked%/'          => $mission->feat_out_of_seq          == '1' ? 'checked' : '',
            '/%feat_convo_threads_all_checked%/'   => $mission->feat_convo_threads_all   == '1' ? 'checked' : '',
            '/%debug_checked%/'                    => $mission->debug                    == '1' ? 'checked' : '',
        ));
    }

    /**
     * Construct options for dropdown menu. 
     *
     * @param string $value Value if selected
     * @param string $label Label for option
     * @param boolean $selected True if selected
     * @return string HTML for select option
     */
    private function makeSelectOption(string $value, string $label, bool $selected) : string 
    {
        return '<option value="'.$value.'" '.($selected ? 'selected="selected"' : '').'>'.$label.'</option>'.PHP_EOL;
    }    

    /***********************/
    /* Delay Settings      */
    /***********************/    

    /**
     * Returns true if the given equation defining the delay is valid. 
     *
     * @param string $eq Equation to validate
     * @return boolean True if valid
     */
    private function isValidDelayEquationOfTime(string $eq)
    {
        $eq = preg_replace('/\s+/', '', $eq);

        $number = '(?:\d+(?:[,.]\d+)?|pi|π|time)'; // What is a number
        $functions = '(?:sinh?|cosh?|tanh?|abs|acosh?|asinh?|atanh?|exp|log10|deg2rad|rad2deg|sqrt|ceil|floor|round)'; // Allowed PHP functions
        $operators = '[+\/*\^%-]'; // Allowed math operators
        $regexp = '/^(('.$number.'|'.$functions.'\s*\((?1)+\)|\((?1)+\))(?:'.$operators.'(?2))?)+$/i'; // Final regexp, heavily using recursive patterns

        return preg_match($regexp, $eq);
    }



    /**
     * Save delay_type and delay_time to the databse. 
     *
     * @return array JSON response. 
     */
    protected function saveDelaySettings(): array
    {
        $mission = MissionConfig::getInstance();

        

        // Default response
        $response = array(
            'success' => false, 
            'error'   => array()
        );

        $data = array();

        // Manual delay
        if(isset($_POST['delay_type']) && $_POST['delay_type'] == Delay::MANUAL)
        {
            // Set delay type
            $data['delay_type'] = Delay::MANUAL;

            // Extract value selected by the user
            $temp = $_POST['delay_manual'] ?? '';
            $temp = trim($temp);

            // User input must be a number. 
            if(!preg_match(AdminModule::FLOAT_FORMAT_REGEX, $temp))
            {
                $response['error'][] = 'Invalid "Manual Delay" entered. Only numbers allowed.';
            }
            else
            {
                // 
                $currTimeObj = new DelayTime();
                $currTime = $currTimeObj->getTimestamp();

                $startTime = DelayTime::getStartTimeUTC();
                $endTime = DelayTime::getEndTimeUTC();

                if($startTime < $currTime && $currTime <= $endTime)
                {
                    $delayConfig = array_merge(
                        $mission->delay_config, 
                        array(array('ts'=>$currTimeObj->getTime(), 'eq'=>floatval($temp)))
                    );
                }
                else
                {
                    $delayConfig = array(array('ts'=>$currTimeObj->getTime(), 'eq'=>floatval($temp)));
                }
                
            }
        }
        elseif(isset($_POST['delay_type']) && $_POST['delay_type'] == Delay::TIMED)
        {
            $data['delay_type'] = Delay::TIMED;
            if(count($_POST['delay_time']) != count($_POST['delay_eq']) && 
               count($_POST['delay_time']) != count($_POST['delay_date']))
            {
                $response['error'][] = 'Invalid/incomplete piece-wise delay definitions.';
            }
            elseif(count($_POST['delay_time']) == 0)
            {
                $response['error'][] = 'At least one piece-wise definition is needed.';
            }
            else
            {
                for($i = 0; $i < count($_POST['delay_time']); $i++)
                {
                    $delayConfig[$i] = array(
                        'ts' => $_POST['delay_date'][$i].' '.$_POST['delay_time'][$i].':00', 
                        'eq' => $_POST['delay_eq'][$i]
                    );
                }
                
                for($i = 0; $i < count($_POST['delay_time']); $i++)
                {
                    if(!preg_match(DelayTime::DATE_FORMAT_REGEX, $delayConfig[$i]['ts']))
                    {
                        $response['error'][] = 'Invalid piece-wise date/time entry in row '.($i+1).'.';
                    }
                    elseif(!$this->isValidDelayEquationOfTime($delayConfig[$i]['eq']))
                    {
                        $response['error'][] = 'Invalid piece-wise f(time) equation in row '.($i+1).'.';
                    }
                    else
                    {
                        $delayConfig[$i]['ts'] = DelayTime::convertTimestampTimezone(
                            $delayConfig[$i]['ts'], $mission->mcc_timezone, 'UTC');
                    }
                }
            }
        }
        else if(isset($_POST['delay_type']) && $_POST['delay_type'] == Delay::MARS)
        {
            $data['delay_type'] = Delay::MARS;
            $currTimeObj = new DelayTime();
            $delayConfig = array(array('ts'=>$currTimeObj->getTime(), 'eq'=>0));
        }
        else
        {
            $response['error'][] = 'Field "Delay Configuration" cannot be empty.';
        }

        $response['success'] = count($response['error']) == 0;
        if($response['success'])
        {
            uasort($delayConfig, array('Delay', 'sortAutoDelay'));
            $data['delay_config'] = json_encode($delayConfig);
            $missionDao = MissionDao::getInstance();
            $missionDao->updateMissionConfig($data);
            Logger::info('Saved delay settings.', $delayConfig);
        }
        
        return $response;
    }

    protected function editDelaySettings() : string
    {
        $this->addTemplates('settings.css', 'admin.js', 'globalize.js', 'globalize.culture.de-DE.js');
        $mission = MissionConfig::getInstance();

        $delayIsManualOptions = 
            $this->makeSelectOption(Delay::MANUAL,  
                'Manual Delay Configuration',    
                ($mission->delay_type == Delay::MANUAL)).
            $this->makeSelectOption(Delay::TIMED,   
                'Automatic Delay Configuration', 
                ($mission->delay_type == Delay::TIMED)).
            $this->makeSelectOption(Delay::MARS,    
                'Current Mars Delay', 
                ($mission->delay_type == Delay::MARS));

        if($mission->delay_type == Delay::MANUAL)
        {
            $delayManual = floatval(Delay::getInstance()->getDelay());

            $delayAuto = Main::loadTemplate('admin-delay-config.txt', array(
                '/%delay-date-id%/'    => 'id="delay-date-0"',
                '/%delay-time-id%/'    => 'id="delay-time-0"',
                '/%delay-cfg-id%/'     => 'id="delay-cfg-0"',
                '/%delay-date-value%/' => substr($mission->date_start, 0, 10),
                '/%delay-time-value%/' => '00:00:00',
                '/%delay-cfg-value%/'  => 0,
            ));
        }
        else if($mission->delay_type == Delay::MARS)
        {
            $delayManual = 0;

            $delayAuto = Main::loadTemplate('admin-delay-config.txt', array(
                '/%delay-date-id%/'    => 'id="delay-date-0"',
                '/%delay-time-id%/'    => 'id="delay-time-0"',
                '/%delay-cfg-id%/'     => 'id="delay-cfg-0"',
                '/%delay-date-value%/' => substr($mission->date_start, 0, 10),
                '/%delay-time-value%/' => '00:00:00',
                '/%delay-cfg-value%/'  => 0,
            ));
        }
        else
        {
            $delayManual  = 0;
            $delayOptions = $mission->delay_config;
            $delayAuto    = '';
            foreach($delayOptions as $id => $cfg)
            {
                $cfg['ts'] = DelayTime::convertTimestampTimezone(
                    $cfg['ts'], 'UTC', $mission->mcc_timezone);

                $dateTime = explode(' ', $cfg['ts']);

                $delayAuto .= Main::loadTemplate('admin-delay-config.txt', array(
                    '/%delay-date-id%/'    => 'id="delay-date-'.$id.'"',
                    '/%delay-time-id%/'    => 'id="delay-time-'.$id.'"',
                    '/%delay-cfg-id%/'     => 'id="delay-cfg-'.$id.'"',
                    '/%delay-date-value%/' => $dateTime[0],
                    '/%delay-time-value%/' => substr($dateTime[1], 0, 5),
                    '/%delay-cfg-value%/'  => $cfg['eq'],
                ));
            }
        }

        $delayAutoTemplate = Main::loadTemplate('admin-delay-config.txt', array(
            '/%delay-date-id%/'    => '',
            '/%delay-time-id%/'    => '',
            '/%delay-cfg-id%/'     => '',
            '/%delay-date-value%/' => '',
            '/%delay-time-value%/' => '',
            '/%delay-cfg-value%/'  => '',
        ));

        return Main::loadTemplate('admin-delay.txt', array(
            '/%delay_type%/'      => $delayIsManualOptions,
            '/%delay_manual%/'    => $delayManual,
            '/%delay_auto%/'      => $delayAuto,
            '/%delay_auto_tmp%/'  => $delayAutoTemplate,
        ));        
    }

    /***********************/
    /* User Settings       */
    /***********************/        

    protected function resetUserPassword()
    {
        global $server;
        $usersDao = UsersDao::getInstance();

        $response = array('success'=>false, 'error'=>'');

        $user_id = $_POST['user_id'] ?? 0;

        if($user_id > 0 && $user_id != $this->user->user_id)
        {
            global $admin;
            $defaultPassword = User::encryptPassword($admin['default_password']);

            if($usersDao->resetPassword($defaultPassword, true, $user_id) !== true)
            {
                $response['error'] = 'Failed to reset user password (user_id='.$user_id.').';
            } 
            else
            {
                Logger::info('Force password reset for user_id='.$user_id);
            }
        }
        else
        {
            $response['error'] = 'Cannot reset your own account.';
        }

        return $response; 
    }

    protected function activateUser(bool $active=true)
    {
        $usersDao = UsersDao::getInstance();
        $missionCfg = MissionConfig::getInstance();

        $response = array('success'=>false, 'error'=>'');

        $userId = (isset($_POST['user_id']) && $_POST['user_id'] != null) ? intval($_POST['user_id']) : 0;

        if($userId > 0 && $userId != $this->user->user_id)
        {
            // Delete associated images. 
            if($usersDao->setActiveFlag($userId, $active) !== true)
            {
                $response['error'] = 'Failed to modify user account. (user_id='.$userId.')';
            }
            else
            {
                Logger::info('Set user_id='.$userId.' to '.($active ? 'active' : 'inactive').'.');
            }
        }
        else
        {
            $response['error'] = 'Cannot activate/deactivate yourself.';
        }

        return $response;
    }

    protected function deactivateUser() 
    {
        return $this->activateUser(false);
    }

    protected function deleteUser()
    {
        $usersDao = UsersDao::getInstance();
        
        $response = array('success'=>false, 'error'=>'');

        $userId = (isset($_POST['user_id']) && $_POST['user_id'] != null) ? intval($_POST['user_id']) : 0;

        if($userId > 0 && $userId != $this->user->user_id)
        {
            // Delete associated images. 
            if($usersDao->deleteUser($userId) !== true)
            {
                $response['error'] = 'Failed to delete user. (user_id='.$userId.')';
            }
            else
            {
                Logger::info('Deleted user_id='.$userId);
            }
        }
        else
        {
            $response['error'] = 'Cannot delete yourself.';
        }

        return $response;
    }

    protected function editUser()
    {
        $userId = $_POST['user_id'] ?? 0;
        $username = $_POST['username'] ?? '';
        $alias = $_POST['alias'] ?? '';
        $isCrew = $_POST['is_crew'] ?? 1;
        $isAdmin = $_POST['is_admin'] ?? 0;

        $response = array('success'=>false, 'error'=>'');

        $usersDao = UsersDao::getInstance();
        if($userId == 0)
        {
            $user = $usersDao->getByUsername($username);
        }
        else
        {
            $user = $usersDao->getById($userId);
        }
        
        if($username == '' || strlen($username) < 4 || strlen($username) > 12 || !ctype_alnum($username))
        {
            $response['error'] = 'Invalid username. Requires min 4 / max 12 alphanumeric characters.';
        }
        elseif($user != null && $user->user_id != $userId && $user->username == $username)
        {
            $response['error'] = 'Username already in use.';
        }
        elseif($user != null && $this->user->user_id == $userId && $user->username != $username)
        {
            $response['error'] = 'Cannot change username for logged in user.';
        }
        elseif($user != null && $this->user->username == $username && $user->is_admin != $isAdmin)
        {
            $response['error'] = 'Cannot remove your own admin priviledges.';
        }   
        else
        {
            $fields = array(
                'username'  => $username, 
                'alias'     => $alias,
                'is_crew'   => $isCrew,
                'is_admin'  => $isAdmin,
            );
            if($userId == 0)
            {
                global $admin;
                $fields['user_id'] = null;
                $fields['password'] = User::encryptPassword($admin['default_password']);
                $fields['is_password_reset'] = 1;
                $fields['is_active'] = 1;
                
                if(($userId = $usersDao->createNewUser($fields)) > 0)
                {
                    $response = array('success'=>true, 'error'=>'');
                    unset($fields['password']);
                    $fields['user_id'] = $userId;
                    Logger::info('Created new user "'.$fields['username'].'"', $fields);
                }
                else
                {
                    $response['error'] = 'Failed to create new user (username='.$username.')';
                }
            }
            else
            {
                if($usersDao->update($fields, $userId) === true)
                {
                    $response = array('success'=>true, 'error'=>'');
                    Logger::info('Updated user_id='.$userId, $fields);
                }
                else
                {
                    $response['error'] = 'Failed to update user (user_id='.$userId.')';
                }
            }
        }

        return $response;
    }

    protected function getUser()
    {
        $id = $_POST['user_id'] ?? 0;
        $usersDao = UsersDao::getInstance();
        
        $response = array('success'=>false);

        $user = $usersDao->getById($id);
        if($user != null)
        {
            $response = array(
                'success'  => true,
                'user_id'  => $id,
                'username' => $user->username,
                'alias'    => $user->alias,
                'is_admin' => $user->is_admin ? 1 : 0,
                'is_crew'  => $user->is_crew ? 1 : 0,
            );
        }

        return $response;
    }

    protected function listUsers() : string
    {
        $this->addTemplates('settings.css', 'users.js');
        $mission = MissionConfig::getInstance();

        $sort = $_GET['sort'] ?? 'user_id';
        $order = $_GET['order'] ?? 'ASC';

        $usersDao = UsersDao::getInstance();
        $users = $usersDao->getUsers($sort, $order);
        
        $headers = array(
            'id' => 'ID',
            'username' => 'Username (alias)',
            'is_crew'  => 'Role',
            'is_admin' => 'Admin',
            'is_active' => 'Active',
            'last_login' => 'Last Login <br/> (MCC timezone)',
            'tools'    => 'Actions'
        );
        
        $list  = new ListGenerator($headers);

        foreach($users as $id => $user)
        {
            $altUser = htmlspecialchars('"'.$user->username.'"');

            $tools = array();
            $tools[] = Main::loadTemplate('link-js.txt', array(
                '/%onclick%/' => 'getUser('.$id.')', 
                '/%text%/'    => '',
                '/%alt%/'     => 'Edit User '.$altUser,
                '/%icon%/'    => 'wrench',
                '/%class%/'   => 'button-black'
            ));

            if($this->user->user_id != $id)
            {
                if(!$mission->isMissionActive())
                {
                    $tools[] = Main::loadTemplate('link-js.txt', array(
                        '/%onclick%/' => 'confirmAction(\'deleteuser\', '.$id.', \''.$user->username.'\')', 
                        '/%text%/'    => '',
                        '/%alt%/'     => 'Delete User '.$altUser,
                        '/%icon%/'    => 'close',
                        '/%class%/'   => 'button-red'
                    ));
                }
                
                if($user->is_active)
                {
                    $tools[] = Main::loadTemplate('link-js.txt', array(
                        '/%onclick%/' => 'confirmAction(\'deactivateuser\', '.$id.', \''.$user->username.'\')', 
                        '/%text%/'    => '',
                        '/%alt%/'     => 'De-activate User '.$altUser,
                        '/%icon%/'    => 'power',
                        '/%class%/'   => 'button-red'
                    ));
                }
                else 
                {
                    $tools[] = Main::loadTemplate('link-js.txt', array(
                        '/%onclick%/' => 'confirmAction(\'activateuser\', '.$id.', \''.$user->username.'\')', 
                        '/%text%/'    => '',
                        '/%alt%/'     => 'Activate User '.$altUser,
                        '/%icon%/'    => 'power',
                        '/%class%/'   => 'button-green'
                        
                    ));
                }
                $tools[] = Main::loadTemplate('link-js.txt', array(
                    '/%onclick%/' => 'confirmAction(\'resetuser\', '.$id.', \''.$user->username.'\')', 
                    '/%text%/'    => '',
                    '/%alt%/'     => 'Reset Password for User '.$altUser,
                    '/%icon%/'    => 'seek-first',
                    '/%class%/'   => 'button-red'
                ));
            }

            $list->addRow(array(
                'id' => $id,
                'username' => htmlspecialchars($user->username).' <br/> ('.htmlspecialchars($user->alias).')',
                'is_crew' => $user->is_crew ? 'HAB' : 'MCC',
                'is_admin' => $user->is_admin ? 'Yes' : 'No',
                'is_active' => $user->is_active ? 'Yes' : 'No',
                'last_login' => substr($user->getLastLogin(), 0, 16),
                'tools' => join('&nbsp;&nbsp;', $tools),
            ));
        }

        return Main::loadTemplate('admin-users.txt', array(
            '/%list-users%/'=>$list->build(),
            '/%role_mcc%/'=>htmlspecialchars($mission->mcc_user_role),
            '/%role_hab%/'=>htmlspecialchars($mission->hab_user_role),
        ));
    }


    protected function editDataManagement() : string 
    {
        $this->addTemplates('settings.css', 'data-management.js');
        $mission = MissionConfig::getInstance();

        $timezoneData = $this->getTimezoneList();
        $archiveTzOptions = '';
        foreach($timezoneData as $tz) 
        {
            $archiveTzOptions .= $this->makeSelectOption($tz['timezone_id'], $tz['label'], $mission->mcc_timezone == $tz['timezone_id']);
        }
        
        $headersZip = array(
            'id' => 'ID',
            'type' => 'Type',
            'note' => 'Notes',
            'timestamp' => 'Date Created (UTC)',
            'tools' => 'Actions'
        );
        
        $list = new ListGenerator($headersZip);

        $archiveDao = ArchiveDao::getInstance();
        $archives = $archiveDao->getArchives();

        foreach($archives as $id => $archive)
        {
            $tools = array();
            $tools[] = Main::loadTemplate('link-url.txt', array(
                '/%path%/'=>'archive/'.$archive->archive_id, 
                '/%text%/'    => '',
                '/%alt%/'     => 'Download archive '.$id,
                '/%icon%/'    => 'arrowthickstop-1-s',
                '/%class%/'   => 'button-green'
            ));

            $tools[] = Main::loadTemplate('link-js.txt', array(
                '/%onclick%/' => 'confirmAction(\'deletearchive\', '.$id.', \''.$archive->getType().' created on '.$archive->getTimestamp().'\')', 
                '/%text%/'    => '',
                '/%alt%/'     => 'Delete archive '.$id,
                '/%icon%/'    => 'close',
                '/%class%/'   => 'button-red'
            ));

            $list->addRow(array(
                'id' => $id,
                'type' => $archive->getType(),
                'note' => $archive->notes,
                'timestamp' => $archive->getTimestamp(),
                'tools' => join('&nbsp;&nbsp;', $tools),
            ));
        }

        // Get a copy of the system log to display
        $logNum = 50;
        $logEntries = Logger::tailLog($logNum);

        return Main::loadTemplate('admin-data.txt', array(
            '/%archives%/' => $list->build(),
            '/%archive-name%/' => $mission->name,
            '/%log-num%/' => $logNum,
            '/%log-entries%/' => $logEntries,
            '/%archive_tz%/'=>$archiveTzOptions,
        ));
    }

    /**
     * Delete all messages and threads while keeping user accounts. 
     * 
     * @return array
     */
    protected function clearMissionData() : array
    {
        global $config;
        global $server;
        
        // Delete all messages and threads. 
        $messagesDao = MessagesDao::getInstance();
        $messagesDao->clearMessagesAndThreads(); 

        // Delete all message attachments. 
        $files = scandir($server['host_address'].$config['uploads_dir']);
        foreach($files as $f)
        {
            if($f != '.' && $f != '..' && $f != 'dummy.txt')
            {
                unlink($server['host_address'].$config['uploads_dir'].'/'.$f);
            }
        }

        // Report status. 
        Logger::info("Cleared mission data.");

        return array('success' => true);
    }

    /**
     * Create a backup of the MySQL database. 
     *
     * @return array 
     */
    protected function backupSqlDatabase() : array 
    {
        global $config;
        global $server;
        global $database;
        
        $response = array(
            'success' => true,
        );

        $currTime = new DelayTime();
        $archiveData = array();
        $archiveData['archive_id'] = 0;
        $archiveData['server_name'] = ServerFile::generateFilename($config['logs_dir']);
        $archiveData['notes'] = ''; 
        $archiveData['mime_type'] = 'application/sql';
        $archiveData['timestamp'] = $currTime->getTime();
        $archiveData['content_tz'] = 'UTC';

        $filePath    = $server['host_address'].$config['logs_dir'].'/'.$archiveData['server_name'];

        $command = 'mysqldump --no-tablespaces'. 
                            ' --host=\''.$database['db_host'].'\''.
                            ' --user=\''.$database['db_user'].'\''.
                            ' --password=\''.$database['db_pass'].'\''.
                            ' \''.$database['db_name'].'\''.
                            ' > '.$filePath;
        $startTime = microtime(true);
        exec($command, $output, $worked);
        $response['time'] = microtime(true) - $startTime;

        if($worked == 0)
        {
            // Add file notes including size and MD5
            $missionCfg = MissionConfig::getInstance();
            $archiveData['notes'] = 'Mission: '.$missionCfg->name.'<br/>'. 
                                    'Archive Timezone: UTC<br/>'. 
                                    'Size: '.ServerFile::getHumanReadableSize(filesize($filePath)).'<br/>'. 
                                    'MD5: '.md5_file($filePath);

            $archiveDao = ArchiveDao::getInstance();
            $result = $archiveDao->insert($archiveData);
            
            if($result === false)
            {
                unlink($filePath);
                Logger::warning('admin::backupSqlDatabase failed to add archive to database.');
                $response['success'] = false;
                $response['error'] = 'Failed to create archive. See system log for details.';
            }
        }
        else
        {
            Logger::warning('admin::backupSqlDatabase failed to create "'.$archiveData['server_name'].'"', 
                array('output'=>$output, 'worked'=>$worked));
            unlink($filePath);    
            $response['success'] = false;
            $response['error'] = 'Failed to create archive. See system log for details.';
        }

        Logger::info('admin::backupSqlDatabase finished for "'. $archiveData['server_name'].
        '" in '.$response['time'].' sec. ('.$result.')');

        return $response;
    }

    protected function resetSystemLog() : array
    {
        global $config;
        global $server;

        $response = array(
            'success' => true,
        );

        $filepath = $server['host_address'].$config['logs_dir'].'/'.$config['log_file'];
        if(file_exists($filepath))
        {
            $response['success'] = unlink($filepath);
        }
        Logger::info("Reset System Log.");

        return $response;
    }

    /**
     * Create a backup of the System Log. 
     *
     * @return array 
     */
    protected function backupSystemLog() : array 
    {
        global $config;
        global $server;
        
        $currTime = new DelayTime();
        $archiveData = array();
        $archiveData['archive_id'] = 0;
        $archiveData['server_name'] = ServerFile::generateFilename($config['logs_dir']);
        $archiveData['notes'] = '';
        $archiveData['mime_type'] = 'application/txt';
        $archiveData['timestamp'] = $currTime->getTime();
        $archiveData['content_tz'] = 'UTC';

        $fromPath = $server['host_address'].$config['logs_dir'].'/'.$config['log_file'];
        $toPath = $server['host_address'].$config['logs_dir'].'/'.$archiveData['server_name'];

        $response = array('success' => true);

        if(copy($fromPath, $toPath))
        {
            // Add file notes including size and MD5
            $missionCfg = MissionConfig::getInstance();
            $archiveData['notes'] = 'Mission: '.$missionCfg->name.'<br/>'. 
                                    'Archive Timezone: UTC<br/>'. 
                                    'Size: '.ServerFile::getHumanReadableSize(filesize($toPath)).'<br/>'.
                                    'MD5: '.md5_file($toPath);

            $archiveDao = ArchiveDao::getInstance();
            $result = $archiveDao->insert($archiveData);
            
            if($result === false)
            {
                unlink($toPath);
                Logger::warning('admin::backupSystemLog failed to add archive to database.');
                $response['success'] = false;
                $response['error'] = 'Failed to create archive. See system log for details.';
            }
        }
        else
        {
            Logger::warning('admin::backupSystemLog failed to create "'.$archiveData['server_name'].'"');
            unlink($toPath);    
            $response['success'] = false;
            $response['error'] = 'Failed to create archive. See system log for details.';
        }

        Logger::info('admin::backupSystemLog finished for "'. $archiveData['server_name'].'". ('.$result.')');

        return $response;
    }

    /**
     * Return the current conversation download status. 
     * Useful for large backups that take minutes to completed.
     *
     * @return array
     */
    protected function backupConversationStatus() : array
    {
        $ret = array('success' => true);
        $missionCfg = MissionConfig::getInstance();
        if(isset($missionCfg->download_status))
        {
            $ret = array_merge($ret, $missionCfg->download_status);
            $ret['inprogress'] = true;
        }
        else
        {
            $ret['inprogress'] = false;
        }

        return $ret;
    }

    protected function backupConversations() : array
    {
        $missionCfg = MissionConfig::getInstance();

        $response = array(
            'success' => true, 
            'time'    => 0, 
            'error'   => '');

        if(isset($missionCfg->download_status))
        {
            $backupTime = strtotime($missionCfg->download_status['date']);
            $currTime  = strtotime((new DelayTime())->getTime());
            $differenceInSeconds = $currTime - $backupTime;
            if($differenceInSeconds > ConversationArchiveMaker::MAX_EXECUTION_TIME)
            {
                Logger::warning('admin::backupConversations Incomplete archive.', $missionCfg->download_status);
                unset($missionCfg->download_status);
            }
            else
            {
                $response['success'] = false;
                $response['error'] = 'Generating archive. Only one archive can be created at a time.';
                return $response;
            }
        }

        $tzSelected = $_POST['timezone'] ?? '';
        $isCrew = !(isset($_POST['perspective']) && $_POST['perspective'] == 'mcc');
        $includePrivate = (isset($_POST['scope']) && $_POST['scope'] == 'convo-all');
        
        $archiveName = $_POST['name'] ?? '';
        $notes = 'Mission: '.$missionCfg->name.'<br/>'. 
                 'Archive Perspective: '.($isCrew ? 'HAB' : 'MCC'). '<br/>'. 
                 'Archive Timezone: '.$tzSelected. '<br/>'. 
                 'Include: '.($includePrivate ? 'Private & Public' : 'Public Only').'<br/>'. 
                 'Other: '.$archiveName;
        
        $timezones = DateTimeZone::listIdentifiers();
        if(!in_array($tzSelected, $timezones))
        {
            $response['success'] = false;
            $response['error'] = 'Invalid "Timezone" selected.';
        }
        else
        {


            Logger::info('admin::backupConversations started');
            $startTime = microtime(true);

            $conversationsDao = ConversationsDao::getInstance();
            $conversations = $conversationsDao->getConversations(null, $includePrivate);

            $messagesDao = MessagesDao::getInstance();
            $numMsgs = $messagesDao->countMessagesInConvo(array_keys($conversations));

            $sepThreads = $missionCfg->feat_convo_threads;
            $zipMaker = new ConversationArchiveMaker($notes, $tzSelected, $numMsgs + count($conversations));
            
            $missionCfg->download_status = $zipMaker->getDownloadStatus();

            foreach($conversations as $convoId => $convo)
            {
                $parentName = ($convo->parent_conversation_id != null) ? 
                    $conversations[$convo->parent_conversation_id]->name : '';
                if(!$sepThreads && $convo->parent_conversation_id != null)
                {
                    continue;
                }

                if(!$convo->archiveConvo($zipMaker, $tzSelected, $sepThreads, $parentName, $isCrew))
                {
                    Logger::warning('conversation::archiveConvo failed to save '.$convoId.'.');
                    $response['success'] = false;
                    break;
                }
                else
                {
                    // Update status
                    $missionCfg->download_status = $zipMaker->getDownloadStatus();
                }
            }
            $archiveData = $zipMaker->close();
            $response['time'] = microtime(true) - $startTime;

            if($response['success'] === true)
            {
                $archiveDao = ArchiveDao::getInstance();
                $result = $archiveDao->insertMultiple(array_keys($archiveData[0]), $archiveData);

                if($result === false)
                {
                    $zipMaker->deleteArchives();
                    Logger::warning('conversation::saveArchive failed to add archive to database.');
                    $response['success'] = false;
                    $response['error'] = 'Failed to create archive. See system log for details.';
                }
            }
            else
            {
                $zipMaker->deleteArchives();
                $response['success'] = false;
                $response['error'] = 'Failed to create archive. See system log for details.';
            }
        }
        
        unset($missionCfg->download_status);
        Logger::info('admin::backupConversations finished in '.$response['time'].' sec. ('.$result.')');

        return $response;
    }

    protected function deleteArchive()
    {
        global $config;
        global $server;
        $archiveDao = ArchiveDao::getInstance();
        
        $response = array('success'=>false, 'error'=>'');

        $archiveId = $_POST['archive_id'] ?? 0;

        if($archiveId > 0)
        {
            $archive = $archiveDao->getArchive($archiveId, $this->user->user_id);
            if($archive != null)
            {
                $filepath = $server['host_address'].$config['logs_dir'].'/'.$archive->server_name;
                
                if(!unlink($filepath))
                {
                    Logger::warning('admin::deleteArchive failed to delete '.$archive->server_name.'.');
                    $response['error'] = 'Failed to delete archive. (archive_id='.$archiveId.')';
                }
                else if($archiveDao->drop($archiveId) !== true)
                {
                    Logger::warning('admin::deleteArchive failed to delete '.$archive->archive_id.' db entry.');
                    $response['error'] = 'Failed to delete archive. (archive_id='.$archiveId.')';
                }
                else
                {
                    $response['success'] = true;
                    Logger::info('Delete archive_id='.$archiveId);
                }
            }
            else
            {
                Logger::warning('admin::deleteArchive failed to delete '.$archiveId.' (obj null).');
            }
        }
        else
        {
            Logger::warning('admin::deleteArchive '.$archiveId.' <= 0.');
        }

        return $response;
    }
}

?>
