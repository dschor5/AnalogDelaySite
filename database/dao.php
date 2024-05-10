<?php

/**
 * Abstract base class containing common properties/functionality for all 
 * Database Abstraction Objects (DAO). 
 */
abstract class Dao
{

    /**
     * Reference to database connection. 
     * @access protected
     * @var Database
     */
    protected $database; 

    /**
     * Name of table represented by this DAO.
     * @access private
     * @var string
     */
    private $name;

    /**
     * Table prefix used
     * @access protected
     * @var string
     */
    protected $prefix;

    /**
     * Name of primary id for the table.
     * @access private
     * @var string
     */
    private $id;

    /**
     * Search for any of these keywords. 
     * @access private
     * @var int
     */
    const SEARCH_ANY = 0;

    /**
     * Search for all of these keywords. 
     * @access private
     * @var int
     */
    const SEARCH_ALL = 1;

    /**
     * Search for exact phrase. 
     * @access private
     * @var int
     */
    const SEARCH_PHRASE = 2;

    /**
     * Constructor. Initializes the name of the database table and the primary id. 
     * 
     * @param string $name Name of database table represented by this DAO. 
     * @param string|null $id Field name used as primary id. 
     */
    protected function __construct(string $name, ?string $id=null)
    {
        $this->database = Database::getInstance();
        $this->name     = $name;
        $this->id       = $id;
        $this->prefix   = $this->database->getPrefix();
    }

    /**
     * Start/End transaction methods used to group transactions as an atomic operation. 
     */
    public function startTransaction()
    {
        $this->database->query('START TRANSACTION',0);
    }

    /**
     * Start/End transaction methods used to group transactions as an atomic operation. 
     * 
     * @param boolean $commit True to commit operation. False to rollback changes.
     */
    public function endTransaction($commit=true)
    {
        $this->database->query(($commit) ? 'COMMIT;' : 'ROLLBACK;');
    }

    /** 
     * Drop a field from the table. 
     * 
     * @param string|int If an int is provided, then treat it as the unique id
     *                   to drop. Otherwise, assume it is the WHERE clause.
     *                   Default to '*' which would drop all rows.
     * @return bool True on success 
     */
    public function drop($id = '*') : bool
    {
        // Build query for this table. 
        $query = "delete from `{$this->prefix}{$this->name}`";

        // If the id (int) is provided, then delete that row only. 
        if (intval($id) > 0)
        {
            $query .= " where `{$this->id}` = '$id'";
        }
        // Otherwise, assume it is a WHERE clause to apply in the operation. 
        else if ($id != '*')
        {
            $query .= " where ".$id;
        }

        return $this->database->query($query, false);
    }

    /**
     * Select / query this database table. 
     * 
     * @param string $what Fields to select from this table. Default to '*' for all. 
     * @param string|int $where Where clause to select rows from the table. 
     *                      If an int is provided, then treat it as the unique id
     *                      to drop. Otherwise, assume it is the WHERE clause.
     *                      Default to '*' which would select all rows.
     * @param string|array $sort  Name of field(s) to sort results by.
     * @param string|array $order 'ASC' or 'DSC' order to sort each of the $sort fields.
     * @param int $limit_start Select subset of matched rows. 
     *                      Limit start is the index of the first row to return. 
     *                      Default to null which means use database default.
     * @param int $limit_count Select number of rows to return. 
     *                      Default to null which means use database default.
     * @return mysqli_result|bool Associative array of database rows returned.                       
     */
    public function select(string $what = '*', $where = '*', $sort = '', 
        string $order = 'ASC', ?int $limit_start = null, ?int $limit_count = null) 
    {
        // Form query string for this table. 
        $query = "select $what from `{$this->prefix}{$this->name}`";

        // Integer where clause means select by primary id. 
        if (intval($where) > 0)
        {
            $query .= " where `{$this->id}` = '$where'";
        }
        // Otherwise, if where clause is not a wildcard, assume it is a well 
        // constructed database statement.
        elseif ($where != '*')
        {
            $query .= " where " . $where;
        }

        // Add multiple sort conditions in the order provided.
        if (is_array($sort))
        {
            if (count($sort) > 0)
            {
                $query .= ' order by ';
            }

            // Add each sort condition in order. 
            for ($i=0;$i<count($sort);$i++)
            {
                $query .= '`'.$sort[$i].'` ';
                $query .= (is_array($order))?$order[$i]:$order;
                if ($i < count($sort)-1)
                {
                    $query .= ', ';
                }
            }

        } 
        // Add a single sort condition. 
        elseif ($sort != '')
        {
            $query .= " order by `{$sort}` $order";
        }

        // Add limit conditions. 
        if ($limit_start >= 0 && $limit_count > 0)
        {
            $query .= " LIMIT $limit_start,$limit_count";
        }

        // Run the query
        return $this->database->query($query.';');
    }

    /**
     * Insert fields and variables (optional) into the current table.
     *
     * @param array Associative array with field names and values to be sanitized.
     * @param array Associative array with field names and values that are neither
     *              sanitized nor put in quotes. This can include both expressions
     *              or MySQL variables.
     * @return int|false ID of row inserted or false on error
     */
    public function insert(array $fields, array $variables=array()) 
    {
        // Build query string
        $query = "insert into `{$this->prefix}{$this->name}` (";

        // Get all fields to enter
        $keys = array();
        $values = array();
        foreach ($fields as $key => $value)
        {
            $keys[] = '`'.$key.'`';
            if ($value === null)
            {
                $values[] = 'NULL';
            }
            else
            {
                $values[] = '"'.$this->database->prepareStatement($value).'"';
            }
        }

        // Get all variables to enter
        foreach($variables as $key => $variable)
        {
            $keys[] = '`'.$key.'`';
            $values[] = $variable;
        }

        // Finish building the query and then execute it.
        $query .= join(',',$keys).') values ('.join(',',$values).');';
        if ($this->database->query($query,0))
        {
            // Always get the last insert id.
            $id = $this->database->getLastInsertId();
            return $id;

        } 
        return false;
    }

    /**
     * Insert multiple rows into a table in a single database transaction.
     *
     * @param array $colNames Array of column names.
     * @param array $rowEntries 2D associative array containing rows with field names 
     *              and values to be sanitized.
     * @return int|false Number of rows inserted or false on errors.
     */
    public function insertMultiple(array $colNames, array $rowEntries) 
    {
        $valuesStr = array();
        
        // There must be at least one row to enter.
        if(count($rowEntries) == 0)
        {
            return 0;
        }

        // Sanitize the keys for the insert operation.
        $keys = array();
        foreach($colNames as $col)
        {
            $keys[] = '`'.$col.'`';
        }
        $keysStr = join(',', $keys);

        // Iterate through each row and make sure they all 
        // contain the same fields in the same order. 
        // If not, return false and log an error.
        foreach($rowEntries as $row)
        {
            // Iterate through each column in that row. 
            $values = array();
            foreach($colNames as $col)
            {
                if(isset($row[$col]))
                {
                    if ($row[$col] === null)
                    {
                        $values[] = 'NULL';
                    }
                    else
                    {
                        $values[] = '"'.$this->database->prepareStatement($row[$col]).'"';
                    }
                }
                else
                {
                    Logger::error('Dao::insertMultiple() invalid column. ', array($this->name, $row, $col));
                    return false;
                }
            }
            $valuesStr[] = '('.join(',', $values).')';
        }

        // Create query. 
        $query = "INSERT INTO `{$this->prefix}{$this->name}` ".
                    "({$keysStr}) VALUES ".join(',', $valuesStr).";";

        
        if ($this->database->query($query, false))
        {
            return $this->database->getNumRowsAffected();
        } 
        return false;
    }

    /**
     * Update specific fields in the database.
     *
     * @param array $fields Associative array of fields to update in the database. 
     * @param string $where Clause used to select which rows to update in the table.
     *                      If an int is provided, then treat it as the unique id
     *                      to drop. Otherwise, assume it is the WHERE clause.
     *                      Default to '*' which would select all rows.
     * @return mysqli_result|bool Result from query or bool if not keeping results.
     */
    public function update(array $fields, string $where = '*')
    {
        // Build update query 
        $query = "update `{$this->prefix}{$this->name}` set ";

        // Sanitize values to update based on the key-value pairs.
        $tmp = array();
        foreach ($fields as $key=>$value)
        {
            if($value === null)
            {
                $tmp[] = '`'.$key.'`=null';
            }
            else
            {
                $tmp[] = '`'.$key.'`="'.$this->database->prepareStatement($value).'"';
            }
        }

        $query .= join(', ',$tmp);

        // Apply where clause. 
        if (intval($where) > 0)
        {
            $query .= " where `{$this->id}` = '$where'";
        }
        else if ($where != '*')
        {
            $query .= " where " . $where;
        }

        $query.=';';

        return $this->database->query($query, false);
    }

    /** 
     * Build a search clause to use in a query. 
     *
     * @param array $search Column names to search within the table.
     * @param string $keywords Keywords to search for. 
     * @param int $method SEARCH_ANY, SEARCH_ALL, or SEARCH_PHRASE.
     * @return string Search string to use in WHERE clause. 
     **/
    protected function searchClause(array $search, string $keywords, int $method=0) : string 
    {
        // Must search at least one column.
        if (count($search) > 0)
        {
            $keywords=trim($keywords);
            $search_terms = array();

            // If searching for a PHRASE, then sanitize the statement. 
            // Otherwise, split all the keywords.
            $keywords = ($method == self::SEARCH_PHRASE ? array($this->database->prepareStatement($keywords)):preg_split("/\s+/",$keywords));
            foreach ($keywords as $key)
            {
                // Skip keywords shorter than 3 letters (conjunctions)
                if (strlen($key) >= 3)
                {
                    // Build search string depending on whether we need ALL or ANY of the keywords
                    $tmp = array();
                    foreach ($search as $col)
                    {
                        if ($method == self::SEARCH_ALL)
                        {
                            $tmp[] = $col . " like '%" .$this->database->prepareStatement($key). "%'";
                        }
                        else
                        {
                            $search_terms[] = $col . " like '%" .$this->database->prepareStatement($key). "%'";
                        }
                    }

                    if ($method == self::SEARCH_ALL)
                    {
                        $search_terms[] = '('.join(' or ',$tmp).')';
                    }
                }
            }

            // Build the search query and return it.
            return (count($search_terms) > 0)? join(($method == self::SEARCH_ALL)? " and ": " or ",$search_terms) : '*';
        }
        return '*';
    }
}

?>
