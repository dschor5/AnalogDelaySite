<?php

/**
 * Data Abstraction Object for the users table. Implements custom 
 * queries to get users, create, and delete users.
 * 
 * @link https://github.com/dschor5/ECHO
 */
class UsersDao extends Dao
{
    /**
     * Singleton instance for UsersDao object.
     * @access private
     * @var ConversationsDao
     **/
    private static $instance = null;

    /**
     * Cache users retrieved from database into an associative array 
     * arranged by user id. 
     * @access private
     * @var array 
     **/
    private static $cache = array();


    private static $cacheFull = false;

    /**
     * Returns singleton instance of this object. 
     * 
     * @return UsersDao
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new UsersDao();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent multiple instances of this object.
     **/
    protected function __construct()
    {
        parent::__construct('users', 'user_id');
    }

    /**
     * Get User by its user_id field.
     *
     * @param integer $id
     * @return User|null
     */
    public function getById(int $id) 
    {
        // Sanitize value
        $id = intval($id);
        $user = null;

        // Send cache User if available.
        if(isset(self::$cache[$id]))
        {
            $user = self::$cache[$id];
        }
        else
        {
            // Query database.
            $queryStr = 
                "SELECT {$this->prefix}users.*, (". 
                    "SELECT GROUP_CONCAT({$this->prefix}participants.conversation_id) "."FROM {$this->prefix}participants ". 
                    "WHERE {$this->prefix}participants.user_id={$this->prefix}users.user_id) AS conversations ". 
                "FROM {$this->prefix}users WHERE {$this->prefix}users.user_id=".$id;

            if (($result = $this->database->query($queryStr)) !== false)
            {
                if ($result->num_rows > 0)
                {
                    $userData = $result->fetch_assoc();
                    self::$cache[$userData['user_id']] = new User($userData);
                    $user = self::$cache[$userData['user_id']];
                }
            }
        }
        return $user;
    }

    /**
     * Get User by its username field.
     *
     * @param string $username
     * @param string $session_id
     * @return User|null
     */
    public function getByUsername(string $username, string $session_id=null)
    {
        $user = null;

        // Check if the user is already in the cache.
        foreach(self::$cache as $userId => $cachedUser)
        {
            if(strcmp($cachedUser->username, $username) === 0)
            {
                $user = $cachedUser;
                break;
            }
        }

        if($user == null)
        {
            // Query database for user.
            $qUsername = '\''.$this->database->prepareStatement($username).'\'';

            $queryStr = "SELECT {$this->prefix}users.*, (". 
                            "SELECT GROUP_CONCAT({$this->prefix}participants.conversation_id) FROM {$this->prefix}participants ". 
                            "WHERE {$this->prefix}participants.user_id={$this->prefix}users.user_id) AS conversations ". 
                        "FROM {$this->prefix}users WHERE {$this->prefix}users.username=".$qUsername;
            
            // If provided, the session id must also match for the query to succeed. 
            if($session_id != null)
            {
                $qSessionId = '\''.$this->database->prepareStatement($session_id).'\'';
                $queryStr .= " AND {$this->prefix}users.session_id=".$qSessionId." AND {$this->prefix}users.is_active=1 ";
            }   

            if (($result = $this->database->query($queryStr)) !== false)
            {
                if ($result->num_rows > 0)
                {
                    $userData = $result->fetch_assoc();
                    self::$cache[$userData['user_id']] = new User($userData);
                    $user = self::$cache[$userData['user_id']];
                }
            }
        }

        return $user;
    }

    /**
     * Get all users.
     *
     * @param string $sort Name of field to sort list by.
     * @param string $order 'ASC' or 'DESC' to order list.
     * @return array
     */
    public function getUsers(string $sort='user_id', string $order='ASC'): array
    {
        // If teh cache is full (flag set by this funciton only), then 
        // return list of users. Otherwise, query database and update
        // the cache.
        if(self::$cacheFull === false)
        {
            if(($result = $this->select('*','*',$sort,$order)) !== false)
            {   
                if($result->num_rows > 0)
                {
                    // Override cache
                    self::$cache = array();
                    while(($data = $result->fetch_assoc()) != null)
                    {
                        self::$cache[$data['user_id']] = new User($data);
                    }
                    self::$cacheFull = true;
                }
            }
        }

        return self::$cache;
    }

    /**
     *  Create a new user. 
     *
     * @param array $fields Associative array of fields to create a new user.
     * @return int User id added or -1 on error.
     */
    public function createNewUser(array $fields)
    {
        $conversationsDao = ConversationsDao::getInstance();
        $participantsDao = ParticipantsDao::getInstance();
        $messagesDao = MessagesDao::getInstance();
        
        $this->database->queryExceptionEnabled(true);

        try
        {
            $this->startTransaction();
            
            // Add user to database.
            $newUserId = $this->insert($fields);

            // Get list of all users to create new private conversations 
            // with the newly added user account.
            $users = $this->getUsers();

            // Give the new user access to all the previous mission messges
            $convos = $conversationsDao->getGlobalConvos();
            
            // Create msg_status entries for all the old messages 
            // already in the database Mission Chat and its threads.
            $newParticipants = array();
            foreach($convos as $convoId)
            {
                $newParticipants[] = array(
                    'conversation_id' => $convoId,
                    'user_id' => $newUserId,
                );
                $messagesDao->newUserAccessToPrevMessages($convoId, $newUserId);
            }
            $keys = array('conversation_id', 'user_id');
            $participantsDao->insertMultiple($keys, $newParticipants);
            
            // Create new private conversations with the new user. 
            foreach($users as $otherUserId=>$user)
            {
                if($newUserId != $user->user_id)
                {
                    // Create new conversation
                    $newConvoData = array(
                        'name' => $user->alias.'-'.$users[$newUserId]->alias,
                        'parent_conversation_id' => null,
                    );
                    $newConvoVars = array(
                        'date_created' => 'UTC_TIMESTAMP(3)',
                        'last_message' => 'UTC_TIMESTAMP(3)',
                    );
                    $newConvoId = $conversationsDao->insert($newConvoData, $newConvoVars);

                    // Add list of participants for the new private conversation.
                    $newParticipants = array(
                        array(
                            'conversation_id' => $newConvoId,
                            'user_id' => $newUserId,
                        ),
                        array(
                            'conversation_id' => $newConvoId,
                            'user_id' => $otherUserId,
                        ),
                    );
                    $keys = array('conversation_id', 'user_id');
                    $participantsDao->insertMultiple($keys, $newParticipants);
                }
            }
            $this->endTransaction(true);
        }
        // Catch errors.
        catch(Exception $e)
        {
            $this->endTransaction(false);
            Logger::warning('usersDao::createNewUser', array($e));
            $newUserId = -1;
        }

        $this->database->queryExceptionEnabled(false);

        return $newUserId;
    }    

    /**
     * Delete a user by its user_id. The database schema will automatically delete
     * all of the user's messages and conversaitons to which they belong. 
     * This means that if you had a private conversation with this user, that 
     * conversation will also be deleted along with all the messages therein.
     *
     * @param int $userId
     * @return bool True on success
     */
    public function deleteUser(int $userId) : bool
    {
        $result = false;
        $participantsDao = ParticipantsDao::getInstance();
        $conversationsDao = ConversationsDao::getInstance();

        $this->database->queryExceptionEnabled(true);
        try 
        {
            $this->startTransaction();

            // Delete user. 
            $this->drop($userId);

            // Delete all convos with a single participant except for the mission chat and its threads
            $convosToDelete = $participantsDao->getConvosWithSingleParticipant();
            if(count($convosToDelete) > 0)
            {
                $conversationsDao->drop('conversation_id IN ('.implode(',', $convosToDelete).')');
            }
            $this->endTransaction();
            $result = true;
        }
        catch(Exception $e)
        {
            $this->endTransaction(false);
            Logger::warning('usersDao::deleteUser', array($e));
        }
        $this->database->queryExceptionEnabled(false);

        return $result;
    }

    public function updateLoginInfo(string $sessionId, int $userId) : bool
    {
        $qUserId = '\''.$this->database->prepareStatement($userId).'\'';
        $qSessionId = '\''.$this->database->prepareStatement($sessionId).'\'';

        $queryStr = "UPDATE {$this->prefix}users SET ". 
            "session_id=".$qSessionId.", ". 
            "last_login=UTC_TIMESTAMP(3) ". 
            "WHERE user_id=".$qUserId;
                
        return ($this->database->query($queryStr) !== false);
    }

    public function resetPassword(string $newPassword, bool $forceReset, int $userId) : bool
    {
        $qUserId = '\''.$this->database->prepareStatement($userId).'\'';
        $qPassword = '\''.$this->database->prepareStatement($newPassword).'\'';

        $queryStr = "UPDATE {$this->prefix}users SET ". 
            "password=".$qPassword.", ". 
            "is_password_reset=".($forceReset ? "1" : "0").", ".
            "last_login=NULL ".
            "WHERE user_id=".$qUserId;

        return ($this->database->query($queryStr) !== false);
    }

    public function setActiveFlag(int $userId, bool $active) : bool 
    {
        $qUserId = '\''.$this->database->prepareStatement($userId).'\'';
        $qActive = $active ? '1' : '0';

        $queryStr = "UPDATE {$this->prefix}users SET ". 
            "is_active=".$qActive." ". 
            "WHERE user_id=".$qUserId;
                
        return ($this->database->query($queryStr) !== false);
    }
}

?>
