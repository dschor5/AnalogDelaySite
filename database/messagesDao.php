<?php

class MessagesDao extends Dao
{
    /**
     * Singleton instance for MessageDao object.
     * @access private
     * @var ConversationsDao
     **/
    private static $instance = null;

    /**
     * Returns singleton instance of this object. 
     * 
     * @return Delay object
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new MessagesDao();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent multiple instances of this object.
     **/
    protected function __construct()
    {
        parent::__construct('messages', 'message_id');
    }

    /**
     * Write new message to the database. 
     *
     * @param array $msgData Associative array with message fields. 
     * @param array $fileData Associative array with file attachment fields.
     * @return int|bool New message id on success. False otherwise. 
     **/
    public function sendMessage(array $msgData, array $fileData=array())
    {
        $messageStatusDao = MessageStatusDao::getInstance();
        $conversationsDao = ConversationsDao::getInstance();
        $participantsDao = ParticipantsDao::getInstance();
        $msgFileDao = MessageFileDao::getInstance();

        
        $this->database->queryExceptionEnabled(true);
        try 
        {
            $this->startTransaction();
            $messageId = $this->insert($msgData);
            if($messageId !== false)
            {
                if(count($fileData) > 0)
                {
                    $fileData['message_id'] = $messageId;
                    $msgFileDao->insert($fileData);
                }

                $participants = $participantsDao->getParticipantIds($msgData['conversation_id']);
                $msgStatusData = array();
                foreach($participants as $userId => $isCrew)
                {
                    $msgStatusData[] = array(
                        'message_id' => $messageId,
                        'user_id' => $userId,
                        'is_read' => ($userId == $msgData['user_id']),
                    );
                }
                $messageStatusDao->insertMultiple($msgStatusData);
                $conversationsDao->update(array('last_message'=>$msgData['sent_time']), 'conversation_id='.$msgData['conversation_id']);
                $this->endTransaction();
            }
            else
            {
                $messageId = false;
                $this->endTransaction(false);
            }
        }
        catch(Exception $e)
        {
            $messageId = false;
            $this->endTransaction(false);
            Logger::warning('messagesDao::sendMessage failed.', $e);
        }
        $this->database->queryExceptionEnabled(false);

        return $messageId;
    }



    public function newUserAccessToPrevMessages(int $convoId, int $userId)
    {
        $qConvoId = '\''.$this->database->prepareStatement($convoId).'\'';
        $msgStatusDao = MessageStatusDao::getInstance();
        
        // TODO - Inneficient if there are a large number of messages. 
        //        Would need to get count and use that to break up the 
        //        request into batches. 
        if (($result = $this->select('message_id', 'conversation_id='.$qConvoId)) !== false)
        {
            if ($result->num_rows > 0)
            {
                $msgStatus = array();
                while(($msgData = $result->fetch_assoc()) != null)
                {
                    $msgStatus[] = array(
                        'message_id' => $msgData['message_id'],
                        'user_id' => $userId,
                        'is_read' => 0
                    );
                }
                $msgStatusDao->insertMultiple($msgStatus);
            }
        }
    }   

    public function getNewMessages(array $convoIds, int $userId, bool $isCrew, string $toDate, int $offset=0) : array
    {
        $qConvoIds = implode(',',$convoIds);
        $qUserId  = '\''.$this->database->prepareStatement($userId).'\'';
        $qOffset  = $this->database->prepareStatement($offset);
        $qRefTime = $isCrew ? 'recv_time_hab' : 'recv_time_mcc';
        $qToDate   = 'CAST(\''.$this->database->prepareStatement($toDate).'\' AS DATETIME)';
        $qFromDate = 'SUBTIME(CAST(\''.$toDate.'\' AS DATETIME), \'00:00:03\')';

        $queryStr = 'SELECT messages.*, '. 
                        'users.username, users.alias, users.is_crew, msg_status.is_read, '.
                        'msg_files.original_name, msg_files.server_name, msg_files.mime_type '.
                    'FROM messages '.
                    'JOIN users ON users.user_id=messages.user_id '.
                    'JOIN msg_status ON messages.message_id=msg_status.message_id '.
                        'AND msg_status.user_id='.$qUserId.' '.
                    'LEFT JOIN msg_files ON messages.message_id=msg_files.message_id '.
                    'WHERE messages.conversation_id IN ('.$qConvoIds.') '.
                        'AND msg_status.is_read=0 '.    
                        'AND (messages.'.$qRefTime.' BETWEEN '.$qFromDate.' AND '.$qToDate.') '.
                    'ORDER BY messages.'.$qRefTime.' ASC, messages.message_id ASC '.
                    'LIMIT '.$qOffset.', 25';
        
        $messages = array();

    
        $this->startTransaction();

        if(($result = $this->database->query($queryStr)) !== false)
        {
            if($result->num_rows > 0)
            {
                while(($rowData=$result->fetch_assoc()) != null)
                {
                    $messages[$rowData['message_id']] = new Message($rowData);
                }
            }
        }
        
        if(count($messages) > 0)
        {
            $messageIds = '('.implode(', ', array_keys($messages)).')';
            $messageStatusDao = MessageStatusDao::getInstance();
            $messageStatusDao->update(array('is_read'=>'1'), 'user_id='.$qUserId.' AND message_id IN '.$messageIds);
        }
        
        $this->endTransaction();
        
        return $messages;
    }

    public function getOldMessages(array $convoIds, int $userId, bool $isCrew, string $toDate, int $lastMsgId=PHP_INT_MAX, int $numMsgs=20) : array
    {
        $qConvoIds = implode(',',$convoIds);
        $qUserId  = '\''.$this->database->prepareStatement($userId).'\'';
        $qlastMsgId  = '\''.$this->database->prepareStatement($lastMsgId).'\'';
        $qRefTime = $isCrew ? 'recv_time_hab' : 'recv_time_mcc';
        $qToDate   = '\''.$this->database->prepareStatement($toDate).'\'';

        $queryStr = 'SELECT messages.*, '. 
                        'users.username, users.alias, users.is_crew, msg_status.is_read, '.
                        'msg_files.original_name, msg_files.server_name, msg_files.mime_type '.
                    'FROM messages '.
                    'JOIN users ON users.user_id=messages.user_id '.
                    'JOIN msg_status ON messages.message_id=msg_status.message_id '.
                        'AND msg_status.user_id='.$qUserId.' '.
                    'LEFT JOIN msg_files ON messages.message_id=msg_files.message_id '.
                    'WHERE messages.conversation_id IN ('.$qConvoIds.') '.
                        'AND messages.'.$qRefTime.' <= '.$qToDate.' '.
                        'AND messages.message_id < '.$qlastMsgId.' '.
                    'ORDER BY messages.'.$qRefTime.' DESC, messages.message_id DESC '.
                    'LIMIT 0, '.$numMsgs;
        
        $messages = array();

        $this->database->queryExceptionEnabled(true);
        try
        {
            $this->startTransaction();

            if(($result = $this->database->query($queryStr)) !== false)
            {
                if($result->num_rows > 0)
                {
                    while(($rowData=$result->fetch_assoc()) != null)
                    {
                        $messages[$rowData['message_id']] = new Message($rowData);
                    }
                }
            }
            
            $queryStr = 'UPDATE msg_status '.
                'JOIN messages ON msg_status.message_id=messages.message_id '.
                'SET msg_status.is_read=1 '. 
                'WHERE msg_status.user_id='.$qUserId.' '.
                    'AND messages.conversation_id IN ('.$qConvoIds.') '. 
                    'AND messages.'.$qRefTime.' <= '.$qToDate;

            $this->database->query($queryStr);
            $this->endTransaction();

            
        } 
        catch (Exception $e) 
        {
            $this->endTransaction(false);
            Logger::warning('messagesDao::getOldMessages failed.', $e);
        }
        $this->database->queryExceptionEnabled(false);

        return array_reverse($messages, true);
    }

    public function getMsgNotifications(int $conversationId, int $userId, bool $isCrew, string $toDate)
    {
        $notifications = array();

        $qConvoId = '\''.$this->database->prepareStatement($conversationId).'\'';
        $qUserId  = '\''.$this->database->prepareStatement($userId).'\'';
        $qRefTime = $isCrew ? 'recv_time_hab' : 'recv_time_mcc';
        $qToDate   = '\''.$this->database->prepareStatement($toDate).'\'';

        $queryStr = 'SELECT messages.conversation_id, '. 
                        'COUNT(*) AS num_new, '. 
                        "SUM(IF(messages.type = 'important', 1, 0)) AS num_important ".
                    'FROM messages '.
                    'JOIN msg_status ON messages.message_id=msg_status.message_id '. 
                    'WHERE messages.conversation_id<>'.$qConvoId.' '. 
                        'AND msg_status.is_read=0 '.
                        'AND msg_status.user_id='.$qUserId.' '. 
                        'AND messages.'.$qRefTime.' <= '.$qToDate.' '. 
                    'GROUP BY messages.conversation_id '.
                    'ORDER BY messages.conversation_id';
        
        if(($result = $this->database->query($queryStr)) !== false)
        {
            if($result->num_rows > 0)
            {
                while(($rowData=$result->fetch_assoc()) != null)
                {
                    $notifications[$rowData['conversation_id']] = array(
                        'num_new' => $rowData['num_new'], 
                        'num_important' => $rowData['num_important']
                    );
                }
            }
        }       

        return $notifications;
    }

    public function clearMessagesAndThreads()
    {
        $conversationsDao = ConversationsDao::getInstance();

        $this->startTransaction();
        $this->database->query('DELETE FROM messages');
        $this->database->query('ALTER TABLE messages AUTO_INCREMENT = 1');
        $this->database->query('DELETE FROM conversations WHERE parent_conversation_id IS NOT NULL');
        $conversationsDao->update(
            array(
                'date_created' => '0000-00-00 00:00:00',
                'last_message' => '0000-00-00 00:00:00',
            )
        );
        $this->endTransaction();
    }

    public function getMessagesForConvo(array $convoIds, bool $isCrew, int $offset, int $numMsgs) : array
    {
        $qConvoIds = implode(',',$convoIds);
        $qRefTime = $isCrew ? 'recv_time_hab' : 'recv_time_mcc';
        
        $queryStr = 'SELECT messages.*, '. 
                        'msg_files.original_name, msg_files.server_name, msg_files.mime_type '.
                    'FROM messages '.
                    'LEFT JOIN msg_files ON messages.message_id=msg_files.message_id '.
                    'WHERE messages.conversation_id IN ('.$qConvoIds.') '.
                    'ORDER BY messages.'.$qRefTime.' ASC, messages.message_id ASC '.
                    'LIMIT '.$offset.', '.$numMsgs;
        
        $messages = array();

        if(($result = $this->database->query($queryStr)) !== false)
        {
            if($result->num_rows > 0)
            {
                while(($rowData=$result->fetch_assoc()) != null)
                {
                    $messages[$rowData['message_id']] = new Message($rowData);
                }
            }
        }
     
        return $messages;
    }

}

?>
