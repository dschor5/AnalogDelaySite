<?php

use function PHPSTORM_META\map;

/**
 * Main chat window that processes new messages/files and displays 
 * any messages received.
 */
class ChatModule extends DefaultModule
{
    /**
     * Array of all conversations for current user. 
     * Arranged as [convoId => Conversation Object].
     * @access private
     * @var array
     */
    private $conversations;

    /**
     * Reference to current conversation object from $this->conversations list. 
     * @access private
     * @var Conversation
     */
    private $currConversation;

    /**
     * Wait time (in sec) before repeating event stream loop. 
     * @access private
     * @var float
     */
    const STREAM_WAIT_BETWEEN_ITER_SEC = 1;

    /**
     * Wait time (in sec) before entering the event stream infinity loop. 
     * @access private
     * @var int 
     */
    const STREAM_INIT_DELAY_SEC = 0.5; 

    /**
     * Conversion from sec to milliseconds. 
     * @access private
     * @var int
     */
    const SEC_TO_MSEC = 1000000;

    /**
     * Send keep alive event stream message every X iterations. 
     * Should be set with STREAM_WAIT_BETWEEN_ITER_SEC in mind. 
     * 
     * "Legacy proxy servers are known to, in certain cases, drop
     * HTTP connections after a short timeout. To protect against
     * such proxy servers, authors can include a comment line (one
     * starting with a ':' character) every 15 seconds or so."
     * Ref: https://html.spec.whatwg.org/multipage/server-sent-events.html
     * 
     * Purposely made shorter to account for reconnect timeout
     * on the client side. So, worst-case total before retries is 15sec.
     * 
     * @access private
     * @var int
     */
    const STREAM_MAX_ITER_BETWEEN_KEEP_ALIVE = 10;    

    /**
     * Constructor. Loads current conversation information from DB. 
     *
     * @param User $user Current logged in user. 
     */
    public function __construct(User &$user)
    {
        parent::__construct($user);
        
        $this->subJsonRequests = array(
            'send'      => 'textMessage', 
            'upload'    => 'uploadFile',
            'prevMsgs'  => 'getPrevMessages',
            'newThread' => 'createNewThread'
        );
        $this->subHtmlRequests = array(
            'default' => 'showChat'
        );
        $this->subStreamRequests = array(
            'refresh' => 'compileStream'
        );

        // Initialize to invalid conversation id. 
        $conversationId = -1;

        // The core chat application relies on the current conversationId 
        // to find appropriate users, poll for messages, and send messages. 
        // In order of precedences, the conversationId is loaded from:
        // 1. POST requests responding to a form
        if(isset($_POST['conversation_id']) && intval($_POST['conversation_id']) > 0)
        {
            $conversationId = intval($_POST['conversation_id']);
        }
        // 2. GET requests when loading a page
        elseif(isset($_GET['conversation_id']) && intval($_GET['conversation_id']) > 0)
        {
            $conversationId = intval($_GET['conversation_id']);
        }
        // 3. If it not provided then check the website cookie. 
        elseif(Main::getCookieValue('conversation_id') != null)
        {
            $conversationId = intval(Main::getCookieValue('conversation_id'));
        }
        // 4. Finally, if all else fails, default to the mission convo. 
        else
        {
            $conversationId = 1;
        }
        
        // Get a listing of all the conversation the current user belongs to.
        $conversationsDao = ConversationsDao::getInstance();
        $this->conversations = $conversationsDao->getConversations($this->user->user_id);

        // Check that the current user is a participant to the conversationId selected above. 
        if(isset($this->conversations[$conversationId]))
        {
            $this->currConversation = &$this->conversations[$conversationId];
        }
        // If not, then automatically select the first valid conversation that
        // was returned from the database. 
        elseif(count($this->conversations) > 0)
        {
            $firstConvo = array_keys($this->conversations)[0];
            $this->currConversation = &$this->conversations[$firstConvo];
            $conversationId = $this->currConversation->conversation_id;
        }
        // If all else fails, the conversation is set to null such that
        // the corresponding subfunctions catch the error. 
        else
        {
            $this->currConversation = null;
        }

        // Update the site cookie with the new conversationId. 
        Main::setSiteCookie(array('conversation_id'=>$conversationId));
    }

    /**
     * Compile AJAX responses. 
     *
     * @param string $subaction
     * @return array Associative array with response. 
     */
    public function compileJson(string $subaction): array
    {
        $response = array('success' => false);

        if($this->currConversation != null)
        {
            $response['conversation_id'] = $this->currConversation->conversation_id;
            $response = array_merge($response, parent::compileJson($subaction));
        }
        else
        {
            $response['error'] = 'User cannot access conversation_id='.$this->currConversation->conversation_id;
        }

        return $response;
    }

    /**
     * Response for asynchronous javascript POST request 
     * to create a new thread. 
     * 
     * The function can be initiated from either another thread
     * or a parent conversation, however, threads are always added
     * to the parent conversation. 
     * 
     * The thread names must be unique and have 0 < name < 100 chars. 
     * 
     * @return array Associative array with response. 
     */
    protected function createNewThread() : array
    {
        $conversationsDao = ConversationsDao::getInstance();

        // Get the name of the thread
        $threadName = $_POST['thread_name'] ?? '';
        $response = array(
            'success' => true,
        );

        // Find the parent conversation to which the thread will be added.
        $currConvo = &$this->currConversation;
        if($this->currConversation->parent_conversation_id != null)
        {
            $currConvo = &$this->conversations[$this->currConversation->parent_conversation_id];
        }

        // Validate the thread name length
        if(strlen($threadName) > 0 && strlen($threadName) < 100)
        {
            // Iterate through all other threads in this conversation 
            // to ensure the name is unique.
            foreach($currConvo->thread_ids as $threadId)
            {
                if($this->conversations[$threadId]->name == $threadName)
                {
                    $response['success'] = false;
                    $response['error'] = 'Thread name already taken.';
                }
            }

            // If all the checks passed, then insert the new thread 
            // into the database and add the participants to it.
            if($response['success'])
            {
                $threadId = $conversationsDao->newThread($currConvo, $threadName);

                // Generate AJAX responses.
                if($threadId === false)
                {
                    $response['success'] = false;
                    $response['error'] = 'Failed to create new thread.';
                }
                else
                {
                    $response['success'] = true;
                    $response['thread_id'] = $threadId;
                    $response['error'] = '';
                }
            }
        }
        else
        {
            $response['success'] = false;
            $response['error'] = 'Invalid thread name ['.htmlspecialchars($threadName).'].';
        }

        return $response;
    }

    /**
     * Response for asynchronous javascript POST request 
     * for previous messages in this chat.
     * 
     * Since it is difficult to predict how many messages there will be in each 
     * conversation, we don't return all the previous messages, but rather 
     * break up the functionality into two cases:
     *  1. On page load - Defaults to load the 25 most recent messages. 
     *  2. Scroll up    - When the user scrolls up, load 10 additional messages. 
     *                    Use the oldest message id already loaded as the index.
     * 
     * @return array Associative array of messages. 
     */
    protected function getPrevMessages() : array
    {
        // Default settings used when the page loads
        $msgId   = PHP_INT_MAX;
        $dt = new DelayTime();
        $msgsRecvBefore = $dt->getTime();
        $numMsgs = 25;

        // If the oldest message time was provided, use that to refine hte 
        // search and load older messages. 
        // Limit load to 10 messages at a time. 
        if(isset($_POST['last_recv_time']) && preg_match(DelayTime::DATE_FORMAT_MS_REGEX, $_POST['last_recv_time']))
        {
            $msgsRecvBefore = str_replace('T', ' ', substr($_POST['last_recv_time'], 0, -1));
            $msgId   = PHP_INT_MAX;
            $numMsgs = 10;
        }
        // If the oldest message date was not given, then consider 
        // using the id of the oldest messages received by the client. 
        // Note that this alone is not reliable as it is possible to 
        // generate conditions in which messages are in-transit while the 
        // delay settings are changed, so you get cross-overs messages
        // within the same site. 
        else if(isset($_POST['message_id']) && 
            intval($_POST['message_id']) > 0 && 
            intval($_POST['message_id']) < PHP_INT_MAX) 
        {
            $msgId   = intval($_POST['message_id']);
            $numMsgs = 10;
        }

        // The query is further constrained by the current timestamp. 
        $response = array();

        // Query for old messages received. 
        $messagesDao = MessagesDao::getInstance();
        $mission = MissionConfig::getInstance();
        $convoIds = array();
        $convoIds[] = $this->currConversation->conversation_id;
        if(!$mission->feat_convo_threads)
        {
            $convoIds = array_merge($convoIds, $this->currConversation->thread_ids);
        }
        $messages = $messagesDao->getOldMessages(
            $convoIds, $this->user->user_id, 
            $this->user->is_crew, $msgsRecvBefore, $msgId, $numMsgs);
        
        // Build response with an array of messages. 
        $response['success'] = true;
        $response['messages'] = array();

        foreach($messages as $msg)
        {
            $response['messages'][] = $msg->compileArray($this->user, 
                $this->currConversation->participants_both_sites);
        }
        
        // Flag 'reg' is true if on-page load it found fewer than 25 messages. 
        // This is used by the javascript to adjust the page layout. 
        $response['req'] = (count($messages) < $numMsgs) && ($msgId == PHP_INT_MAX);

        return $response; 
    }

    /**
     * Response for asynchronous javascript POST request 
     * to upload a file (sent as a new message).
     * 
     * The funciton validates the upload (type, name, size) before
     * moving it to the uploads directory and entering the information 
     * into the database. 
     * 
     * Implementation Notes:
     * - The original plan was to return success/failure along with 
     *   an error message, then leave the polling function get the 
     *   message to display on the screen. Unfortunately, that led to 
     *   race conditions depending on the polling & database insertion. 
     *   So on some occations, the message was not displayed to the user. 
     *   The problem was resolved by returning the status along with 
     *   the new message. 
     * - All messages get an MCC and HAB timestamp regardless of where
     *   the conversation participants are. Checks are performed when
     *   polling for new messages. 
     *
     * @return array Associative array of messages. 
     *               Required fields: 
     *                  - success = true/false 
     *               Optional fields:
     *                  - error = error message
     *                  - msg fields
     */
    protected function uploadFile() : array
    {
        global $config;
        global $server;

        // Get the file type. 
        $fileType  = trim($_POST['type'] ?? Message::FILE);
        $fileExt1 = '';
        $fileExt2 = '';
        $fileNameParts = 1;

        // Start building the response. 
        $result = array('success' => false);

        // Trap in case the upload failed due to file sizes
        if($_FILES['data']['tmp_name'] == '')
        {
            $result['error'] = 'Failed to upload file. Check the file size.';
            return $result;
        }

        // The VIDEO and AUDIO types are fixed for Google Chrome. 
        // Future versions of ECHO can expand this to work with more browsers. 
        if($fileType == Message::VIDEO)
        {
            $fileExt1  = 'mkv';
            $fileExt2  = '';
            $numFileNameParts = 2;
            $fileMime = 'video/webm';
            $dt = new DateTime('NOW');
            $fileName = $fileType.'_'.$dt->format('YmdHisv').'.'.$fileExt1;
        }
        elseif($fileType == Message::AUDIO)
        {
            $fileExt1  = 'mkv';
            $fileExt2  = '';
            $numFileNameParts = 2;
            $fileMime = 'audio/webm';
            $dt = new DateTime('NOW');
            $fileName = $fileType.'_'.$dt->format('YmdHisv').'.'.$fileExt1;
        }
        else // Catch-all for regular attachments
        {
            $fileType = Message::FILE;
            $fileName = trim($_FILES['data']['name'] ?? '');

            $fileNameParts = explode('.', strtolower($fileName), 3);
            $numFileNameParts = count($fileNameParts);
            if($numFileNameParts == 1)
            {
                $fileExt1 = $fileNameParts[0];
                $fileExt2 = '';
            }
            else if($numFileNameParts == 2)
            {
                $fileExt1 = $fileNameParts[1];
                $fileExt2 = '';
            }
            else
            {
                $fileExt1 = $fileNameParts[2];
                $fileExt2 = $fileNameParts[1];
            }

            if(finfo_open(FILEINFO_MIME_TYPE) !== false)
            {
                $fileMime  = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $_FILES['data']['tmp_name']);
            }
            else
            {
                $fileMime = array_search(
                    strtolower(pathinfo($_FILES['data']['tmp_name'], PATHINFO_EXTENSION)),
                    $config['uploads_allowed'], 
                    true);
                if($fileMime === false) 
                {
                    $fileMime = 'unknown';
                }
            }            
        }
        
        $fileMime = strtolower($fileMime);

        // Get the file size regardless of the type. 
        $fileSize  = intval($_FILES['data']['size'] ?? 0);

        // All files will be renamed and stored in the uploads directory on the server. 
        // This prevents overwriting files if different versions of the same document 
        // are uploaded and also makes it harder for people to guess filenames. 
        $serverName = ServerFile::generateFilename($config['uploads_dir']);
        $fullPath = $server['host_address'].$config['uploads_dir'].'/'.$serverName;
        
        // Validate upload type. 
        if(!in_array(strtolower($fileType), array(Message::FILE, Message::VIDEO, Message::AUDIO)))
        {
            $result['error'] = 'Invalid upload type.';
        }
        // Validate filename. Min 1 char filename + period + extension. 
        else if(strlen($fileName) < 3 && strlen($fileName) >= 240)
        {
            $result['error'] = 'Invalid filename (3 < length =< 240).';
        }
        // Validate file type. 
        else if($numFileNameParts == 3 && $fileExt2 != '7z' && !isset($config['uploads_allowed'][$fileMime]))
        {
            $result['error'] = 'Invalid file type uploaded. (MimeType='.$fileMime.')';
        }
        // Validate extension
        else if(($numFileNameParts == 2 && !in_array($fileExt1, array_merge($config['uploads_allowed'], $config['uploads_allowed_partial']))) ||
                ($numFileNameParts == 3 && !in_array($fileExt2, array_merge($config['uploads_allowed'], $config['uploads_allowed_partial']))))
        {
            $result['error'] = 'Invalid file type uploaded. (Extension='.$fileName.' - '.$fileExt1.' - '.$numFileNameParts.')';
        }
        // Validate filesize. 
        else if($fileSize <= 0 || $fileSize > ServerFile::getMaxUploadSize())
        {
            $result['error'] = 'Invalid file size (0 < size < '.ServerFile::getHumanReadableSize(ServerFile::getMaxUploadSize()).')';
        }
        // Move the file to the uploads directory. 
        else if(!move_uploaded_file($_FILES['data']['tmp_name'], $fullPath))
        {
            $result['error'] = 'Error writing file.';
        }
        else
        {
            // If all the previous checks passed and we successfully moved the 
            // file to the uploads directory, then the last step is to add the 
            // information to the database. 

            $msgText = $_POST['caption'] ?? '';

            // Create entry for messages table. 
            $currTime = new DelayTime();
            $msgData = array(
                'user_id'         => $this->user->user_id,
                'from_crew'       => ($this->user->is_crew) ? '1' : '0',
                'conversation_id' => $this->currConversation->conversation_id,
                'text'            => $msgText,
                'type'            => $fileType,
            );
            
            // Create entry for the msg_files table. 
            $fileData = array(
                'message_id'    => 0,
                'server_name'   => $serverName,
                'original_name' => $fileName,
                'mime_type'     => $fileMime,
            );

            // Execute both database queries. 
            $messagesDao = MessagesDao::getInstance();
            if(($messageId = $messagesDao->sendMessage($this->user, $msgData, $fileData)) !== false)
            {
                $result = array(
                    'success' => true, 
                    'message_id' => $messageId,
                );

                // Get the last message and return it in the ajax call.
                if(($lastMessage = $messagesDao->getLastMessage($messageId))!== false)
                {
                    $result = array_merge($result, 
                        $lastMessage->compileArray($this->user, 
                        $this->currConversation->participants_both_sites));
                }
            }
            else
            {
                $result['error'] = 'Database error.';
                Logger::error('Failed to send message', array_merge($msgData, $fileData));
            }
        }

        return $result;
    }

    /**
     * Response for asynchronous javascript POST request 
     * to send a text message to the current conversation. 
     * 
     * Implementation Notes:
     * - The original plan was to return success/failure along with 
     *   an error message, then leave the polling function get the 
     *   message to display on the screen. Unfortunately, that led to 
     *   race conditions depending on the polling & database insertion. 
     *   So on some occations, the message was not displayed to the user. 
     *   The problem was resolved by returning the status along with 
     *   the new message. 
     * - All messages get an MCC and HAB timestamp regardless of where
     *   the conversation participants are. Checks are performed when
     *   polling for new messages. 
     *
     * @return array Associative array of messages. 
     *               Required fields: 
     *                  - success = true/false 
     *               Optional fields:
     *                  - error = error message
     *                  - msg fields
     */
    protected function textMessage() : array
    {
        $messagesDao = MessagesDao::getInstance();

        // Get message type and body.
        $msgText = $_POST['msgBody'] ?? '';
        $msgImportant = filter_var($_POST['msgType'] ?? false, FILTER_VALIDATE_BOOLEAN) ?
            Message::IMPORTANT : Message::TEXT;

        $result = array(
            'success' => false, 
            'message_id' => -1
        );

        // Message has to have at least 1 character (e.g., "k")
        if(strlen($msgText) > 0)
        {
            // Fields to enter into the database.
            $msgData = array(
                'user_id'         => $this->user->user_id,
                'from_crew'       => ($this->user->is_crew) ? '1' : '0',
                'conversation_id' => $this->currConversation->conversation_id,
                'text'            => $msgText,
                'type'            => $msgImportant,
            );

            // Send message.
            if(($messageId = $messagesDao->sendMessage($this->user, $msgData)) !== false)
            {
                $result = array(
                    'success' => true, 
                    'message_id' => $messageId,
                );

                // Get the last message and return it in the ajax call.
                if(($lastMessage = $messagesDao->getLastMessage($messageId))!== false)
                {
                    $result = array_merge($result, 
                        $lastMessage->compileArray($this->user, 
                        $this->currConversation->participants_both_sites));
                }
            }
            else
            {
                $result['error'] = 'Database error.';
                Logger::error('Failed to send message', $msgData);
            }
        }
        
        return $result;
    }

    /**
     * Infinite loop where the server sends a stream of events rather than 
     * responding to periodic polls from the application for new data. 
     * 
     * Types of messages supported:
     * - delay        - Updates current communication delay and distance between HAB and MCC. 
     * - msg          - New message received. Multiple instances of this messages can be sent
     *                  per second and each contains a unique id to ensure the client knows 
     *                  there is no duplicate informaiton. 
     * - notification - Notifies the client that the current user received messages in 
     *                  another conversation. 
     * - keep-alive   - Empty message sent if no activity was recorded for more than X sec
     *                  to ensure the conneciton is kept alive. 
     * 
     * Implementation Notes:
     * - To avoid interfering with the on page load request for messages, 
     *   there is a hardcoded 0.5sec delay before starting the infinite loop. 
     * - Inifinite loop executing at 1Hz. 
     * 
     * Reference:
     * - https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events
     *
     * 
     * @return void
     */
    public function compileStream() 
    {
        // Block invalid access. 
        if($this->currConversation == null)
        {
            return;
        }

        while (ob_get_level() > 0) 
        {
            ob_end_clean();
        }
        
        $this->sendRoomMenus();

        // Iteration counter. Used to send keep-alive messages 
        // every few seconds. 
        $iter = 1;

        // Set reconnection retry 
        $this->sendEventStreamRetry(5);

        // Check if the server sent a last-event-id header indicating it is reconnecting
        $headers = getallheaders();
        $lastEventId = $headers['Last-Event-Id'] ?? -1;
        $this->sendMissedMessages(intval($lastEventId));
        
        // Infinite loop processing data. 
        while(true)
        {
            // Force update on delay settings regularly to keep the connection alive
            $forceIfNoChange = false;
            if($iter == self::STREAM_MAX_ITER_BETWEEN_KEEP_ALIVE)
            {
                $iter = 1;
                $forceIfNoChange = true;    
            }

            // Send events with updates. 
            $this->sendDelayEvents($forceIfNoChange);
            $this->sendNewMsgEvents();
            $this->sendNewConversations();
            $this->sendNotificationEvents();

            // Flush output to the user. 
            while (ob_get_level() > 0) 
            {
                ob_flush();
            }
            flush();

            // Check if the connection was aborted by the user (e.g., closed browser)
            if(connection_status() != CONNECTION_NORMAL)
            {
                break;
            }

            // Repeat in 1sec
            usleep(self::STREAM_WAIT_BETWEEN_ITER_SEC * self::SEC_TO_MSEC);
            $iter++;
        } 
    }

    private function sendRoomMenus() 
    {
        // Initialize conversations menu
        foreach($this->conversations as $convoId => $convo)
        {
            if($convo->parent_conversation_id == null && $convo->countActiveParticipants() >= 1)
            {
                $roomSelected = $this->currConversation->conversation_id == $convoId;
                
                $this->sendRoom(
                    $convoId, 
                    $convo->getName($this->user->user_id),
                    $roomSelected || $this->currConversation->parent_conversation_id == $convoId,
                    $roomSelected,
                );

                $mission = MissionConfig::getInstance();
                if($mission->feat_convo_threads)
                {
                    foreach($convo->thread_ids as $threadId)
                    {
                        $this->sendThread(
                            $convoId,
                            $threadId,
                            htmlspecialchars($this->conversations[$threadId]->name),
                            $this->currConversation->conversation_id == $threadId,
                        );
                    }
                }
            }
            
        }
    }

    private function sendRoom(int $convoId, string $name, bool $current=false, bool $selected=false)
    {
        $this->sendEventStream(
            'room', 
            array(
                'convo_id' => $convoId,
                'convo_name' => htmlspecialchars($name),
                'convo_current' => $current,
                'convo_selected' => $selected
            )
        );
    }

    private function sendThread(int $convoId, int $threadId, string $name, bool $selected=false)
    {
        if($convoId == $this->currConversation->conversation_id || $convoId == $this->currConversation->parent_conversation_id)
        {
            $this->sendEventStream(
                'thread', 
                array(
                    'convo_id'    => $convoId,
                    'thread_id'   => $threadId,
                    'thread_name' => htmlspecialchars($name),
                    'thread_selected' => $selected,
                )
            );
        }
    }

    /**
     * Sends event stream message 'delay' anytime the current 
     * communicaiton delay changes. 
     */
    private function sendNewConversations()
    {
        $mission = MissionConfig::getInstance();

        // Only execute if threads are enabled.
        if($mission->feat_convo_threads)
        {
            $conversationsDao = ConversationsDao::getInstance();

            // Gets new threads. The query excludes all known conversation ids
            // to only get the new threads.
            $newConvos = $conversationsDao->getNewConversations(
                array_keys($this->conversations), $this->user->user_id);

            // For each new thread
            foreach($newConvos as $convoId => $convo)
            {
                // Update our cached knowledge base
                $this->conversations[$convoId] = $convo;

                if($convo->parent_conversation_id == null)
                {
                    $this->sendRoom($convo->conversation_id, $convo->getName($this->user->user_id));
                }
                // If it does not have a parent, then send it even if it does not belong to the active convo.
                else
                {
                    $this->conversations[$convo->parent_conversation_id]->addThreadId($convoId);
                    $this->sendThread($convo->parent_conversation_id, $convo->conversation_id, htmlspecialchars($convo->name));
                }
            }
        }
    }

    /**
     * Sends event stream message 'delay' anytime the current 
     * communicaiton delay changes. 
     * 
     * @param bool $forceIfNoChange Sends message even if the delay has not changed.
     */
    private function sendDelayEvents($forceIfNoChange=false)
    {
        // Keep track of previous delay as long as the object is active. 
        static $prevDelay = -1;

        // Get the current delay. 
        $delayObj = Delay::getInstance();
        $delay = $delayObj->getDelay();

        // Send event if the value differs from the past messages. 
        if($delay != $prevDelay || $forceIfNoChange)
        {
            $this->sendEventStream(
                'delay', 
                array(
                    'delay'    => $delayObj->getDelayStr(), 
                    'distance' => $delayObj->getDistanceStr()
                )
            );
            $prevDelay = $delay;
        }
    }

    /**
     * Send messages starting at lastId (as set by the HTTP header Last-Event-Id)
     * that were missed because of a connection problem with the server. 
     * 
     * If there are no messages to send, then seed the value for Last-Event-Id 
     * based on the last message received in this conversation. 
     * 
     * @param int lastId 
     */
    private function sendMissedMessages(int $lastId = -1)
    {
        $messagesDao = MessagesDao::getInstance();
        $mission = MissionConfig::getInstance();
              
        // Conversation to query for messages
        $convoIds = array();
        $convoIds[] = $this->currConversation->conversation_id;
        if(!$mission->feat_convo_threads)
        {
            $convoIds = array_merge($convoIds, $this->currConversation->thread_ids);
        }

        // If the header Last-Event-Id was set, then use that to query for new
        // messages. Operation is throttled to avoid large data structures. 
        if($lastId >= 0)
        {
            $offset = 0;
            $messages = $messagesDao->getMissedMessages(
                $convoIds, $this->user->user_id, $this->user->is_crew, $lastId, $offset);

            while(count($messages) > 0)
            {
                // Iterate through the new messages and send a unique event 
                // for each one where the msg data is JSON encoded. 
                // Use the id field to identify unique events 
                foreach($messages as $msgId => $msg)
                {
                    $this->sendEventStream(
                        'msg', 
                        $msg->compileArray($this->user, $this->currConversation->participants_both_sites),
                        $msgId, 
                    );

                    // Flush output to the user. 
                    while (ob_get_level() > 0) 
                    {
                        ob_flush();
                    }
                    flush();
                }

                // Any more messages?
                $offset += count($messages);
                $messages = $messagesDao->getMissedMessages(
                    $convoIds, $this->user->user_id, $this->user->is_crew, $lastId, $offset);
            }
        }
        else
        {
            // Get last event id from current time to seed value on client side.
            $messageId = $messagesDao->getLastMessageId(
                $convoIds, $this->user->user_id, $this->user->is_crew);
    
            // Seed last-event id by sending an empty message that is just the event id.
            $this->setLastEventId($messageId);
        }
        
    }

    /**
     * Sends event stream message 'msg' for each new message 
     * received for the current conversation. 
     */
    private function sendNewMsgEvents()
    {
        // Get new messages
        $messagesDao = MessagesDao::getInstance();
        $mission = MissionConfig::getInstance();
        $convoIds = array();
        $convoIds[] = $this->currConversation->conversation_id;
        if(!$mission->feat_convo_threads)
        {
            $convoIds = array_merge($convoIds, $this->currConversation->thread_ids);
        }
        $messages = $messagesDao->getNewMessages(
            $convoIds, $this->user->user_id, $this->user->is_crew);

        // Iterate through the new messages and send a unique event 
        // for each one where the msg data is JSON encoded. 
        // Use the id field to identify unique events 
        foreach($messages as $msgId => $msg)
        {
            $this->sendEventStream(
                'msg', 
                $msg->compileArray($this->user, $this->currConversation->participants_both_sites),
                $msgId, 
            );
            
            // Flush output to the user. 
            while (ob_get_level() > 0) 
            {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * Sends event stream message 'notification' anytime new messages
     * are received on other conversations. 
     */
    private function sendNotificationEvents()
    {
        // Keep track of previous notifications sent to avoid duplicates. 
        static $prevNotifications = array();

        // Poll database for new messages for each conversation. 
        $messagesDao = MessagesDao::getInstance();
        $currNotifications = $messagesDao->getMsgNotifications(
            $this->currConversation->conversation_id, $this->user->user_id, $this->user->is_crew);

        if(count($currNotifications) > 0)
        {
            // Create array containing the current parent & thread ids. 
            $thisConvoAndThreads = array();
            if($this->currConversation->parent_conversation_id == null)
            {
                $thisConvoAndThreads = array_merge(
                    array($this->currConversation->conversation_id),
                    $this->currConversation->thread_ids);
            }
            else
            {
                $thisConvoAndThreads = array_merge(
                    array($this->currConversation->parent_conversation_id),
                    $this->conversations[$this->currConversation->parent_conversation_id]->thread_ids);
            }

            // Consolidate thread notifications with parent if either:
            //      a) threads are disabled OR
            //      b) the user is viewing a different convo altogether
            $mission = MissionConfig::getInstance();
            $tempNotifications = array();
            foreach($currNotifications as $convoId => $convo)
            {
                if(!isset($this->conversations[$convoId]))
                {
                    $conversationsDao = ConversationsDao::getInstance();
                    $this->conversations = $conversationsDao->getConversations($this->user->user_id);
                    $this->sendNewConversations();
                }

                // If no threads AND message received in a thread (would only happen if disabling threads during a mission)
                // OR threads are enabled and you receive a message in a different coversation, then 
                // consolidate notifications for parent. 
                if((!$mission->feat_convo_threads && $this->conversations[$convoId]->parent_conversation_id != null) ||
                   ($mission->feat_convo_threads && !in_array($convoId, $thisConvoAndThreads)))
                {
                    // Consolidate notifications for parent conversation
                    $id = $convoId;
                    if($this->conversations[$convoId]->parent_conversation_id != null)
                    {
                        $id = $this->conversations[$convoId]->parent_conversation_id;
                    }

                    // Initialize the notification for that convo
                    if(!isset($tempNotifications[$id]))
                    {
                        $tempNotifications[$id]['num_new'] = 0;
                        $tempNotifications[$id]['num_important'] = 0;
                    }

                    // Assign results from query for number of new messages and important messages
                    $tempNotifications[$id]['num_new'] += $convo['num_new'];
                    $tempNotifications[$id]['num_important'] += $convo['num_important'];
                }
                // Otherwise, assume the notifications can be sent with the thread/conversation id. 
                else
                {
                    $tempNotifications[$convoId] = $convo;
                }
            }

            // Save notifications. 
            $currNotifications = $tempNotifications;

            // Ensure we only send new notifications by iterating through the list of new
            // notificaitons and comparing that to the previous list sent. 
            // Note that num_important is sent as a binary flag (either there were important 
            // messages or not).
            $newNotifications = array();
            foreach($currNotifications as $convoId => $msgs)
            {
                if(count($prevNotifications) == 0)
                {
                    $newNotifications[$convoId] = $currNotifications[$convoId];
                    $newNotifications[$convoId]['notif_important'] = ($msgs['num_important'] > 0) ? 1:0;
                }
                else if(!isset($prevNotifications[$convoId]))
                {
                    $newNotifications[$convoId] = $currNotifications[$convoId];
                    $newNotifications[$convoId]['notif_important'] = ($currNotifications[$convoId]['num_important']) ? 1:0;
                }
                else if($prevNotifications[$convoId]['num_new'] != $currNotifications[$convoId]['num_new'])
                {
                    $newNotifications[$convoId] = $currNotifications[$convoId];
                    $newNotifications[$convoId]['notif_important'] = 
                        ($prevNotifications[$convoId]['num_important'] != $currNotifications[$convoId]['num_important']) ? 1:0;
                }
            }

            // Send a new message indicating the conversation id and num messages. 
            foreach($newNotifications as $convoId=>$numMsgs)
            {
                if($convoId != $this->currConversation->conversation_id)
                {
                    $this->sendEventStream(
                        'notification', 
                        array(
                            'conversation_id' => $convoId,
                            'num_messages'    => $numMsgs['num_new'],
                            'num_important'   => $numMsgs['num_important'],
                            'notif_important' => $numMsgs['notif_important'],
                        )
                    );
                }
            }

            // Track notifications already sent. 
            $prevNotifications = $currNotifications;
        }
    }

    /**
     * Compile HTML for Chat module. 
     *
     * @param string $subaction N/A for this module. 
     * @return string HTML output. 
     */
    public function compileHtml(string $subaction) : string
    {
        global $config;

        // The the mission settings
        $mission = MissionConfig::getInstance();

        // Add templates for this module. 
        $this->addTemplates('simplemde.min.css', 'simplemde.min.js', 'chat.css', 'chat.js', 'media.js', 'time.js');

        // Only add theads if enabled. 
        if($mission->feat_convo_threads)
        {
            $this->addTemplates('threads.js');
        }

        // Add flags & templates for all features enabled. 
        // The flags can be used by javascripts to enable/disable features.
        $featuresEnabled = ''.
            (($mission->feat_audio_notification)  ? Main::loadTemplate('chat-feat-audio-notification.txt')  : '').
            (($mission->feat_badge_notification)  ? Main::loadTemplate('chat-feat-badge-notification.txt')  : '').
            (($mission->feat_unread_msg_counts)   ? Main::loadTemplate('chat-feat-unread-msg-counts.txt')   : '').
            (($mission->feat_convo_list_order)    ? Main::loadTemplate('chat-feat-convo-list-order.txt')    : '').
            (($mission->feat_est_delivery_status) ? Main::loadTemplate('chat-feat-est-delivery-status.txt') : '').
            (($mission->feat_progress_bar)        ? Main::loadTemplate('chat-feat-progress-bar.txt')        : '').
            (($mission->feat_important_msgs)      ? Main::loadTemplate('chat-feat-important-msgs.txt')      : '').
            (($mission->feat_out_of_seq)          ? Main::loadTemplate('chat-feat-out-of-seq.txt')          : '').
            (($mission->feat_convo_threads)       ? Main::loadTemplate('chat-feat-convo-threads.txt')       : '').
            // Note: only enable browser-side support if not on mobile devices as that was reported 
            //       to cause issues on some touch interfaces.
            (($mission->feat_markdown_support && !Main::detectMobile())    
                                                  ? Main::loadTemplate('chat-feat-markdown-support.txt')    : '');

        // Determine who can add new threads if the feature is enabled.
        if($mission->feat_convo_threads && ($this->user->is_admin || $mission->feat_convo_threads_all))
        {
            $featuresEnabled .= Main::loadTemplate('chat-feat-convo-threads-all.txt');
        }   

        // Load template. 
        return Main::loadTemplate('chat.txt', 
            array('/%username%/'           => htmlspecialchars($this->user->username),
                  '/%delay_src%/'          => $this->user->is_crew ? $mission->hab_name : $mission->mcc_name,
                  '/%convo_id%/'           => $this->currConversation->conversation_id,
                  '/%hab_day_name%/'       => $mission->hab_day_name,
                  '/%max_upload_size%/'    => ServerFile::getHumanReadableSize(ServerFile::getMaxUploadSize()),
                  '/%max_upload_size_bytes%/' => ServerFile::getMaxUploadSize(),
                  '/%allowed_file_types%/' => implode(', ', $config['uploads_allowed']),
                  '/%download-link%/'      => Main::loadTemplate('download-link.txt', 
                                              array('/%link%/' => '#', '/%filename%/' => '', '/%filesize%/' => '')),
                  '/%features_enabled%/'  => $featuresEnabled
                ));
    }
}

?>