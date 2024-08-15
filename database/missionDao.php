<?php

/**
 * Data Abstraction Object for the mission_config table. Implements custom 
 * queries to search and update conversations as needed. 
 * 
 * @link https://github.com/dschor5/ECHO
 */
class MissionDao extends Dao
{
    /**
     * Singleton instance for MissionDao object.
     * @access private
     * @var ConversationsDao
     **/
    private static $instance = null;

    /**
     * Returns singleton instance of this object. 
     * 
     * @return MissionDao
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            self::$instance = new MissionDao();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent multiple instances of this object.
     **/
    protected function __construct()
    {
        parent::__construct('mission_config');
    }

    /**
     * Read the mission configuration into an associative array.
     * 
     * @return array Associative array[name] = array(type=>, value=>)
     **/
    public function readMissionConfig() : array
    {
        $missionData = array();
        if(($result = $this->select('*','*')) !== false)
        {   
            if($result->num_rows > 0)
            {
                while(($data = $result->fetch_assoc()) != null)
                {
                    $missionData[$data['name']] = array(
                            'type'  => $data['type'],
                            'value' => $data['value']
                        );
                }
            }
        }

        return $missionData;
    }

    /**
     * Update mission configuration data. 
     *
     * @param array $data Associative array of name/value pairs. 
     * @return bool True on success. 
     **/
    public function updateMissionConfig(array $data) : bool
    {
        // Use case statements to update multiple fields in the table
        // in a single query. 
        $queryStr = "UPDATE {$this->prefix}mission_config SET value = ( CASE ";
        $qIn = array();
        foreach($data as $name => $value) 
        {
            $qName = '\''.$this->database->prepareStatement($name).'\'';
            $qIn[] = $qName;
            $qValue = '\''.$this->database->prepareStatement($value).'\'';
            $queryStr .= 'WHEN name='.$qName.' THEN '.$qValue.' ';
        }
        $queryStr .= 'END) WHERE name IN ('.implode(',', $qIn).')';

        return ($this->database->query($queryStr) !== false);
    }
}

?>