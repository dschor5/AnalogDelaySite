<?php

/**
 * Message objects represent one message within the chat application.
 * Encapsulates 'messages' row from database. 
 * 
 * Table Structure: 'messages'
 * - message_id                 (int)       Global message id unique to this table.
 * - user_id                    (int)       User id who authored the message.
 * - conversation_id            (int)       Conversation where the message belongs.
 * - text                       (text)      Text stored in the message.
 * - type                       (enum)      Enumerated value indicating:
 *                                          - TEXT - plaintext message
 *                                          - IMPORTANT - plaintext message but important
 *                                          - VIDEO - video recording
 *                                          - AUDIO - audio recording
 *                                          - FILE - any other file attachment
 * - from_crew                  (bool)      Boolean to indicate the message was sent from the crew (HAB)
 * - message_id_alt             (int)       Alternate message id (HAB-# or MCC-#) that is
 *                                          unique to each conversation/thread. 
 *                                          Note the same conversaiton can have a HAB-1 and MCC-1 
 *                                          because that's the id they were assigned by the sender. 
 * - recv_time_hab              (datetime)  UTC timestamp when the message is visible by HAB
 * - recv_time_mcc              (datetime)  UTC timestamp when the message is visible by MCC
 * 
 * Additional Fields:
 * - sent_time                  (datetime)  if(from_crew) ? recv_time_hab : recv_time_mcc
 * - users.username             (string)    Username for message author
 * - users.alias                (string)    Alias for message author
 * - users.is_active            (bool)      Is sender an active user
 * - msg_files.original_name    (string)    Original filename for attachment (if any)
 * - msg_files.server_name      (string)    Server filename for attachment (if any)
 * - msg_files.mime_type        (string)    Mime type for attachment (if any)
 * 
 * Implementation Notes:
 * - Each message is assigned a type from: TEXT, IMPORTANT, FILE, AUDIO, or VIDEO. 
 * 
 * @link https://github.com/dschor5/ECHO
 */
class Message
{
    /**
     * Constant message type: TEXT
     * @access public
     * @var string
     */
    const TEXT = 'text';

    /**
     * Constant message type: IMPORTANT
     * @access public
     * @var string
     */
    const IMPORTANT = 'important';

    /**
     * Constant message type: FILE
     * @access public
     * @var string
     */
    const FILE = 'file';

    /**
     * Constant message type: AUDIO
     * @access public
     * @var string
     */
    const AUDIO = 'audio';

    /**
     * Constant message type: VIDEO
     * @access public
     * @var string
     */
    const VIDEO = 'video';

    /**
     * Constant message status delivered. 
     * @access private
     * @var string
     */
    const MSG_STATUS_DELIVERED = 'Delivered';

    /**
     * Constant message status on-transit. 
     * @access private
     * @var string
     */
    const MSG_STATUS_TRANSIT = 'Transit';

    /**
     * Data from 'messages' database table. 
     * @access private
     * @var array
     */
    private $data;

    /**
     * Attachment associated with this message.
     * @access private
     * @var FileUpload|null
     */
    private $file;

    /**
     * Message Constructor. If the message contains an attachment, this will
     * also build the FileUpload object.
     *
     * @param array $data Row from 'messages' database table. 
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->file = null;

        // If the message contains an attachment, load teh corresponding fields. 
        if($this->data['type'] != self::TEXT && $this->data['type'] != self::IMPORTANT)
        {
            $this->file = new FileUpload(
                array_intersect_key($this->data, 
                    array_flip(array('message_id', 'server_name', 'original_name', 'mime_type')))
            );
        }

        // Dynamically extract the time the message was sent.
        $this->data['sent_time'] = ($this->data['from_crew'] == 0) ? $this->data['recv_time_mcc'] : $this->data['recv_time_hab'];
    }

    /**
     * Accessor for Message fields. Returns value stored in the field $name 
     * or null if the field does not exist. 
     * 
     * @param string $name Name of field being requested. 
     * @return mixed Value contained by the field requested. 
     */
    public function __get($name)
    {
        $result = null;

        if(array_key_exists($name, $this->data)) 
        {
            $result = $this->data[$name];
        }
        else
        {
            Logger::warning('Message __get("'.$name.'")', $this->data);
        }

        return $result;
    }

    /**
     * Get the message received time.
     * 
     * @param bool $remoteDest True if sending to a remote destination.
     */
    private function getReceivedTime(bool $remoteDest) : string
    {
        // Message was sent to remote destination, so use the receive timestamp 
        // that does not match the originator timestamp. 
        if($remoteDest)
        {
            $receiveTime = $this->from_crew ? $this->recv_time_mcc : $this->recv_time_hab;
        }
        // Message was sent to the same destination, so use the receive timestamp 
        // matching the originator timestamp.
        else
        {
            $receiveTime = $this->from_crew ? $this->recv_time_hab : $this->recv_time_mcc;
        }

        return $receiveTime;
    }

    /**
     * Get the message status (delivered or transit) from the perspective of 
     * the person receiving the message.
     *
     * @param bool $remoteDest True if sending to a remote destination.
     * @return string
     */
    private function getMsgStatus(bool $remoteDest) : string
    {
        // Get current time. 
        $time = new DelayTime();

        // Get receive time.
        $recvTime = $this->getReceivedTime($remoteDest);

        // Assign and return the status
        $ret = self::MSG_STATUS_DELIVERED;
        if($recvTime > $time->getTime())
        {
            $ret = self::MSG_STATUS_TRANSIT;
        }
        return $ret;
    }

    /**
     * Archive the message by (i) returning a string representation of the content
     * and (ii) adding any attachments to the zip file. 
     *
     * @param ConversationArchiveMaker $zip Zip file to which attachments are added
     * @param string $folder Folder within the zip file to add attachments
     * @param array $participants List of participants to get usename for message author
     * @param string $tz Timezone to use when displaying the send/recv time. 
     * @return string HTML representation of the message of false on error.
     */
    public function archiveMessage(ConversationArchiveMaker &$zip, 
        string $folder, array &$participants, string $tz) 
    {
        // Compile message text
        $msg = $this->compileMsgText();

        // If the message had an attachment, then copy the file to the correct folder in the archive.
        if($this->type != self::TEXT && $this->type != self::IMPORTANT && $this->file != null && $this->file->exists())
        {
            $filepath = $this->file->getServerPath();

            // Add the message id (unique) to the file name in case an attachment 
            // was sent more than once in the same conversation with the same name.
            $filename = $folder.'/'.sprintf('%05d', $this->message_id).'-'.$this->file->original_name;

            // Text to add to the message. 
            $msg = $filename.' ('.$this->file->getSize().')';

            if(!$zip->addFile($filepath, $filename))
            {
                Logger::warning('message::archiveMessage failed to add file '.$filepath.' as '.$filename.'.');
                return false;
            }
        }

        // Add indicator that this message was sent with high importance.
        $important = '';
        if($this->type == self::IMPORTANT)
        {
            $important = '<p style="color: red; font-weight: bold; font-size: 140%;">IMPORTANT:</p>';
        }

        // Compile message HTML for archive 
        return Main::loadTemplate('admin-data-save-msg.txt', 
            array('/%message_id%/'     => $this->message_id,
                  '/%message_id_alt%/' => $this->formatAltMessageId(),
                  '/%from-user%/'      => $participants[$this->user_id]['username'],
                  '/%sent-time%/'      => DelayTime::convertTimestampTimezone($this->sent_time, 'UTC', $tz),
                  '/%recv-time-mcc%/'  => DelayTime::convertTimestampTimezone($this->recv_time_mcc, 'UTC', $tz),
                  '/%recv-time-hab%/'  => DelayTime::convertTimestampTimezone($this->recv_time_hab, 'UTC', $tz),
                  '/%msg%/'            => $msg,
                  '/%important%/'      => $important));
    }

    /**
     * Use Parsedown class to render TEXT messages using markdown formatting. 
     *
     * @return string
     */
    private function compileMsgText() : string
    {
        $missionConfig = MissionConfig::getInstance();
        $result = htmlspecialchars($this->text);

        // If markdown is enabled, then parse that and convert that to HTML 
        // before returning the text.
        if($missionConfig->feat_markdown_support)
        {
            $parsedown = new Parsedown();
            $parsedown->setSafeMode(true);
            $parsedown->setBreaksEnabled(true);
            $result = $parsedown->text(htmlspecialchars(str_replace('\\', '\\\\', $this->text)));
        }
        else
        {
            $result = preg_replace('/(\r\n)|(\n)|(\r)/','<br>',$result);
        }
        
        return $result;
    }

    /**
     * Get message id from sender (HAB or MCC) to display on the screen.
     *
     * @return string 
     */
    public function formatAltMessageId() : string
    {
        return (($this->from_crew) ? 'HAB-' : 'MCC-').$this->message_id_alt;    
    }

    /**
     * Get alias to display with messages that shows whether the user is inactive.
     *
     * @return string
     */
    public function getAliasWithStatus() : string 
    {
        return htmlspecialchars($this->alias).($this->is_active ? '' : '&nbsp;<i>[inactive]</i>');
    }

    /**
     * Return associative array with message contents to display on the chat applicaiton.
     *
     * @param User $userPerspective Format message data from perspective of logged in user.
     * @param boolean $remoteDest Format message for remote destination. 
     * @return array Associative array of message parameters for display. 
     */
    public function compileArray(User &$userPerspective, bool $remoteDest) : array
    {
        $msgData = array(
            'message_id'       => $this->message_id,
            'message_id_alt'   => $this->formatAltMessageId(),
            'user_id'          => $this->user_id,
            'from_crew'        => $this->from_crew,
            'author'           => $this->getAliasWithStatus(),
            'message'          => $this->compileMsgText(),
            'type'             => self::TEXT,
            'sent_time'        => DelayTime::convertTsForJs($this->sent_time),
            'recv_time_mcc'    => DelayTime::convertTsForJs($this->recv_time_mcc),
            'recv_time_hab'    => DelayTime::convertTsForJs($this->recv_time_hab),
            'recv_time_local'  => DelayTime::convertTsForJs($userPerspective->is_crew ? $this->recv_time_hab : $this->recv_time_mcc),
            'recv_time'        => DelayTime::convertTsForJs($this->getReceivedTime($remoteDest)),
            'delivered_status' => $this->getMsgStatus($remoteDest),
            'remoteDest'       => $remoteDest,
            'send_notification'=> ($userPerspective->user_id != $this->user_id) ? true : false);
            
        // Flag as important
        if($this->type == self::IMPORTANT) 
        {
            $msgData['type'] = self::IMPORTANT;
        }
        
        // If not null, add the details on the file attachment. 
        if($this->type != self::TEXT && $this->type != self::IMPORTANT && $this->file != null)
        {
            if($this->file->exists())
            {
                $msgData['filename'] = $this->file->original_name;
                $msgData['filesize'] = $this->file->getSize();
                $msgData['type'] = $this->file->getTemplateType();
                $msgData['mime_type'] = $this->file->mime_type;
            }
        }

        // Set the format of who authored the message. 
        // Sending the avatars is somewhat redundant given the source field, 
        // however, this may provide more functionality in future releases.
        if($userPerspective->user_id == $this->user_id)
        {
            // Current user
            $msgData['source'] = 'usr';
            $msgData['avatar'] = '';
        }
        elseif($this->from_crew)
        {
            // Someone elese in the habitat
            $msgData['source'] = 'hab';
            $msgData['avatar'] = 'user-hab.jpg';
        }
        else
        {
            // Someone else in MCC
            $msgData['source'] = 'mcc';
            $msgData['avatar'] = 'user-mcc.jpg';
        }

        return $msgData;
    }
}

?>
