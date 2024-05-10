<?php

/**
 * Data Abstraction Object for the msg_file table. Implements custom 
 * queries to search and update conversations as needed. 
 * 
 * @link https://github.com/dschor5/ECHO
 */
class MessageFileDao extends Dao
{
    /**
     * Singleton instance for MessageFileDao object.
     * @access private
     * @var ConversationsDao
     **/
    private static $instance = null;

    /**
     * Returns singleton instance of this object. 
     * 
     * @return MessageFileDao
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new MessageFileDao();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent multiple instances of this object.
     **/
    protected function __construct()
    {
        parent::__construct('msg_files');
    }

    /**
     * Get files associated with a particular message id and visible by the given user id. 
     *
     * @param int $messageId Message id containing the file attachment. 
     * @param int $userId Checks that the given user can read the file in this conversation. 
     * @return FileUpload|null 
     **/
    public function getFile(int $messageId, int $userId) 
    {
        $qMessageId = '\''.$this->database->prepareStatement($messageId).'\'';
        $qUserId = '\''.$this->database->prepareStatement($userId).'\'';
        
        // Get files with the matching message_id and ensure the user is
        // part of the conversation (thus having read access).
        $queryStr = "SELECT {$this->prefix}msg_files.* " .
        "FROM {$this->prefix}msg_files " .
        "JOIN {$this->prefix}messages " .
        "ON {$this->prefix}messages.message_id={$this->prefix}msg_files.message_id " .
        "JOIN {$this->prefix}participants " .
        "ON {$this->prefix}participants.conversation_id={$this->prefix}messages.conversation_id " .
        "WHERE {$this->prefix}msg_files.message_id={$qMessageId} " .
        "AND {$this->prefix}participants.user_id={$qUserId}";

        $file = null;

        if(($result = $this->database->query($queryStr)) !== false)
        {
            if($result->num_rows > 0)
            {
                $file = new FileUpload($result->fetch_assoc());
            }
        }

        return $file;
    }
}

?>
