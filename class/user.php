<?php

require_once('database/usersDao.php');

class User
{
    private $data;

    public function __construct($data)
    {
        $this->data = $data;
        if(isset($data['conversations']))
        {
            $this->data['conversations'] = explode(',', $this->data['conversations']);
        }
        else
        {
            $this->data['conversations'] = array();
        }
    }

    public function __get($name) : mixed
    {
        $result = null;

        if(array_key_exists($name, $this->data)) 
        {
            if(str_starts_with($this->data[$name], 'is_')
            {
                $result = ($this->data[$name] == 1);
            }
            else
            {
                $result = $this->data[$name];
            }
        }

        return $result;
    }

    public function getLocation(): string
    {
        global $mission;
        $location = $mission['home_planet'];
        if($this->isCrew())
        {
            $location = $mission['away_planet'];
        }
        return $location;
    }

    public function isValidPassword(string $password): bool
    {
        return (md5($password) == $this->data['password']);
    }

    public function createNewSession()
    {
        $this->data['session_id'] = dechex(rand(0,time())).dechex(rand(0,time())).dechex(rand(0,time()));
        return $this->data['session_id'];
    }

    public function isValidSession($cmpKey)
    {
        $valid=false;
        if ($cmpKey == $this->data['session_id'])
        {
            $valid=true;
        }

        return $valid;
    }
}

?>