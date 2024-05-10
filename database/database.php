<?php

/**
 * Database wrapper that handles connection and queries. 
 * All Database Abstraction Objects (DAOs) use this connection
 * to interact with the database. 
 * 
 * @link https://github.com/dschor5/ECHO
 */
class Database
{
    /**
     * Reference to database object. 
     * @access private
     * @var mysqli 
     */
    private $db = null;

    /**
     * Count number of queries executed.
     * @access private
     * @var int
     */
    private $query_count;    

    /**
     * Cumulative time spent executing queries. 
     * @access private
     * @var int
     */
    private $query_time;

    /**
     * If true, throw an Exception for database errors. Otherwise just log errors. 
     * @access private
     * @var bool
     */
    private $throwException;

    /**
     * Table prefix to use.
     * @access private
     * @var string
     */
    private $prefix;

    /**
     * Singleton instance of Database object.
     * @access private
     * @var Object
     */
    private static $instance = null;

    /**
     * Constructor. Initialize connection to the database. 
     * 
     * @param string $user Username for database connection
     * @param string $pass Password for database connection
     * @param string $dbname Database name
     * @param string $hostname Hostname where database is stored
     * @param string $tblprefix Optinal prefix for all tables in the database
     */
    private function __construct(string $user, string $pass, 
        string $dbname, string $hostname,  string $tblprefix='')
    {
        // Initialize variables
        $this->query_count = 0;
        $this->query_time = 0;
        $this->throwException = false;
        $this->prefix = $tblprefix;
        
        // Establish databse connection.
        $this->db = new mysqli($hostname, $user, $pass, $dbname);

        // Log error if the connection fails. 
        if(mysqli_connect_errno())
        {
            Logger::error('Could not connect to database.', 
                array('user'=>$user, 'dbname'=>$dbname, 'host'=>$hostname, 
                      'error'=>mysqli_connect_error()));
        }
        else
        {
            // Set the current session to use UTC timestamps. 
            $this->query("SET @@session.time_zone='+00:00'");
        }
    }

    /**
     * Returns singleton instance of Database object. 
     * 
     * @global $database 
     * @return Database
     */
    public static function getInstance()
    {
        if(self::$instance == null)
        {
            global $database;
            self::$instance = new Database(
                $database['db_user'],
                $database['db_pass'],
                $database['db_name'],
                $database['db_host']);
        }

        return self::$instance;
    }

    /**
     * Get the table prefix. 
     * 
     * @return string Table prefix. 
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Destructor closes database connection.
     */
    public function __destruct()
    {
        $this->db->close();
    }

    /**
     * Flag to enable throwing an exception if a query fails instead
     * of just logging an error. 
     * 
     * This is used to simplify the code in scenarios requiring multiple
     * sequential queries to succeed. For instance, when creating a new
     * user the system will:
     * - Add a row to the users table and get the user_id. 
     * - Create new conversations as needed and get the conversation_id. 
     * - Use the conversation_id and user_id to add participants. 
     * The exception is less elegant (because it does not handle each 
     * special case), but it simplifies the code. The risk associated
     * with the broad handling of the exception is accepteed because 
     * these are operations that are not expected to fail.
     * 
     * @param bool $state Enable (true) or disable (false) exceptions.
     */
    public function queryExceptionEnabled(bool $state)
    {
        $this->throwException = $state;
    }

    /**
     * Run a database query and return the result. 
     * While public, the intent is to only use this function within the 
     * Database Abstraction Objects (DAOs).
     * 
     * @param string $queryStr Query string to execute. 
     * @return mysqli_result|bool Result from query or bool if not keeping results.
     */
    public function query(string $queryStr) 
    {
        // Track time required to execute the query
        $time_start = microtime(true);
        $result = $this->db->query($queryStr);
        $time_end = microtime(true);
    
        // Update debugging variables
        $this->query_time += $time_end - $time_start;
        $this->query_count++;

        // Log all database errors. 
        if ($result === false || $this->db->error != '')
        {
            Logger::error('Query failed.', array(
                'query'  => $queryStr,
                'error'  => $this->db->error,
                'trace'  => debug_backtrace(),
                'qtime'  => $this->query_time,
                'qcount' => $this->query_count,
            ));

            // If enabled, throw an exception. 
            if($this->throwException)
            {
                throw new Exception($queryStr);
            }
        }
        // If it was not an error, track queries for debugging purposes. 
        else
        {
            Logger::debug('Query', array(
                'query'  => $queryStr,
                'qtime'  => $this->query_time,
                'qcount' => $this->query_count,
            ));
        }

        //if we need to keep the result, create
        //a result object and return it.
        return $result;
    }

    /**
     * Sanitize string to be used as part of a database query to 
     * avoid malicious attacks. 
     * 
     * @param string $value Value to sanitize. 
     * @return string Sanitized value. 
     */
    public function prepareStatement(string $value): string
    {
        return $this->db->real_escape_string($value);
    }

    /**
     * Get the id of the last row inserted. 
     * Only relevnat for tables with an auto_increment unique id. 
     * Intended to be used by the DAOs only. 
     * 
     * @return int ID for last row inserted.
     */
    public function getLastInsertId()
    {
        return $this->db->insert_id;
    }

    /**
     * Returns the number of rows affected by a query. 
     * Intended to be used by the DAOs only. 
     * 
     * @return int Number of rows affected by a query.
     */
    public function getNumRowsAffected()
    {
        return $this->db->affected_rows;
    }
}
?>
