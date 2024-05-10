<?php

/**
 * Data Abstraction Object for the mission_archives table. Implements custom 
 * queries to search and update conversations as needed. 
 * 
 * @link https://github.com/dschor5/ECHO
 */
class ArchiveDao extends Dao
{
    /**
     * Singleton instance for MessageFileDao object.
     * @access private
     * @var ArchiveDao
     **/
    private static $instance = null;

    /**
     * Returns singleton instance of this object. 
     * 
     * @return ArchiveDao
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new ArchiveDao();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent multiple instances of this object.
     **/
    protected function __construct()
    {
        parent::__construct('mission_archives', 'archive_id');
    }

    /**
     * Get files associated with a particular mission archive.
     *
     * @param int $archiveId Archive id containing the mission archive.
     * @param int $userId Checks that the given user can download the archive.
     * @return MissionArchive|null 
     **/
    public function getArchive(int $archiveId, int $userId) 
    {
        $qArchiveId = '\''.$this->database->prepareStatement($archiveId).'\'';
        $qUserId = '\''.$this->database->prepareStatement($userId).'\'';
        
        // Get files with the matching archive_id and ensure the user is
        // an admin that can read the archive.
        $queryStr = "SELECT mission_archives.* ". 
                    "FROM `{$this->prefix}mission_archives`, `{$this->prefix}users` ". 
                    "WHERE {$this->prefix}mission_archives.archive_id={$qArchiveId} AND ". 
                    "{$this->prefix}users.user_id={$qUserId} AND ". 
                    "{$this->prefix}users.is_admin=1";

        $file = null;

        if(($result = $this->database->query($queryStr)) !== false)
        {
            if($result->num_rows > 0)
            {
                $file = new MissionArchive($result->fetch_assoc());
            }
        }

        return $file;
    }

    /**
     * Get list of all archives. 
     *
     * @return array 
     */
    public function getArchives(): array
    {
        $archives = array();
        if(($result = $this->select('*','*','timestamp','ASC')) !== false)
        {   
            if($result->num_rows > 0)
            {
                while(($data = $result->fetch_assoc()) != null)
                {
                    $archives[$data['archive_id']] = new MissionArchive($data);
                }
            }
        }

        return $archives;
    }
}

?>
