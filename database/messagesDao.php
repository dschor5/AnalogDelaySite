<?php

class MessagesDao extends Dao
{
    private static $instance = null;

    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new MessagesDao();
        }
        return self::$instance;
    }

    protected function __construct()
    {
        parent::__construct('messages');
    }
    
}

?>
