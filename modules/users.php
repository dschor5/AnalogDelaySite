<?php

require_once('index.php');
require_once('modules/default.php');

class UsersModule extends DefaultModule
{
    public function __construct(&$main, &$user)
    {
        parent::__construct($main, $user);
        $this->subJsonRequests = array('getuser', 'edituser', 'deleteuser', 'resetuser');
        $this->subHtmlRequests = array('list');
    }

    public function compileJson(string $subaction): array
    {
        $response = array();
        switch($subaction)
        {
            case 'getuser':
            {
                $response = $this->getUser();
                break;   
            }
            case 'edituser':
            {
                $response = $this->editUser();
                break;
            }
            case 'deleteuser':
            {
                $response = $this->deleteUser();
                break;
            }
            case 'resetuser':
            {
                $response = $this->resetUserPassword();
                break;
            }
            default:
            {
                $response['success'] = false;
                $response['error'] = 'Unknown request.';
            }
        }

        return $response;
    }

    public function compileHtml(string $subaction) : string
    {
        $this->addCss('common');
        $this->addCss('settings');
        $this->addJavascript('jquery-3.6.0.min');
        $this->addJavascript('users');

        $this->addHeaderMenu('Chat', 'chat');
        $this->addHeaderMenu('Mission Settings', 'mission');

        return $this->listUsers();
    }

    private function resetUserPassword()
    {
        global $server;

        $response = array('success'=>false, 'error'=>'');

        $user_id = $_POST['user_id'] ?? 0;

        if($user_id > 0 && $user_id != $this->user->getId())
        {
            $usersDao = UsersDao::getInstance();
            $user = $usersDao->update(array('password_reset'=>1), $user_id);
            $response['success'] = true;
        }
        else
        {
            $response['error'] = 'Cannot reset your own account.';
        }

        return $response; 
    }

    private function deleteUser()
    {
        global $server;

        $response = array('success'=>false, 'error'=>'');

        $user_id = $_POST['user_id'] ?? 0;

        if($user_id > 0 && $user_id != $this->user->getId())
        {
            $usersDao = UsersDao::getInstance();
            $user = $usersDao->drop($user_id);
            $response['success'] = true;
        }
        else
        {
            $response['error'] = 'Cannot delete yourself.';
        }

        return $response;
    }

    private function editUser()
    {
        $user_id = $_POST['user_id'] ?? 0;
        $username = $_POST['username'] ?? '';
        $isCrew = $_POST['is_crew'] ?? 1;
        $isAdmin = $_POST['is_admin'] ?? 0;

        $response = array('success'=>false, 'error'=>'');

        $usersDao = UsersDao::getInstance();
        $user = $usersDao->getByUsername($username);

        if($username == '' || strlen($username) < 4)
        {
            $response['error'] = 'Invalid username. Min 4 characters.';
        }
        elseif($user != null && $user->getId() != $user_id && $user->getUsername() == $username)
        {
            $response['error'] = 'Username already in use.';
        }
        elseif($user != null && $user->isAdmin() != $isAdmin)
        {
            $response['error'] = 'Cannot remove your own admin priviledges.';
        }
        else
        {
            $fields = array(
                'username' => $username, 
                'is_crew'  => $isCrew,
                'is_admin' => $isAdmin
            );
            if($user_id == 0)
            {
                global $admin;
                $fields['user_id'] = null;
                $fields['password'] = md5($admin['default_password']);
                $fields['password_reset'] = 1;
                $ret = $this->createNewUser($fields);
            }
            else
            {
                $ret = $usersDao->update($fields, 'user_id='.$user_id);
            }

            if($ret !== false)
            {
                $response = array('success'=>true, 'error'=>'');
            }
        }

        return $response;
    }

    private function createNewUser($fields)
    {
        $usersDao = UsersDao::getInstance();
        $ret = $usersDao->insert($fields);

        // Create conversations. 
        // Add participants to conversations. 
        return $ret;
    }

    private function getUser()
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
                'username' => $user->getUsername(),
                'is_admin' => $user->isAdmin() ? 1 : 0,
                'is_crew'  => $user->isCrew() ? 1 : 0,
            );
        }

        return $response;
    }

    private function listUsers() : string
    {
        global $mission;

        $sort = $_GET['sort'] ?? 'user_id';
        $order = $_GET['order'] ?? 'ASC';

        $usersDao = UsersDao::getInstance();
        $users = $usersDao->getUsers($sort, $order);
        
        $headers = array(
            'id' => 'ID',
            'username' => 'Username',
            'is_crew'  => 'Role',
            'is_admin' => 'Admin',
            'tools'    => 'Actions'
        );
        
        $list = new ListGenerator($headers);

        foreach($users as $id => $user)
        {
            $tools = array();
            $tools[] = Main::loadTemplate('modules/link-js.txt', array(
                '/%onclick%/'=>'getUser('.$id.')', 
                '/%text%/'=>'Edit'
            ));

            if($this->user->getId() != $id)
            {
                $tools[] = Main::loadTemplate('modules/link-js.txt', array(
                    '/%onclick%/'=>'confirmAction(\'deleteuser\', '.$id.', \''.$user->getUsername().'\')', 
                    '/%text%/'=>'Delete'
                ));
                $tools[] = Main::loadTemplate('modules/link-js.txt', array(
                    '/%onclick%/'=>'confirmAction(\'resetuser\', '.$id.', \''.$user->getUsername().'\')', 
                    '/%text%/'=>'Reset Password'
                ));
            }

            $list->addRow(array(
                'id' => $id,
                'username' => $user->getUsername(),
                'is_crew' => $user->isCrew() ? $mission['role_hab'] : $mission['role_mcc'],
                'is_admin' => $user->isAdmin() ? 'Yes' : 'No',
                'tools' => join(', ', $tools),
            ));
        }

        return Main::loadTemplate('modules/users.txt', array(
            '/%content%/'=>$list->build(),
            '/%role_mcc%/'=>$mission['role_mcc'],
            '/%role_hab%/'=>$mission['role_hab'],
        ));
    }
}

?>