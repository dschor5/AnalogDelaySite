<?php

require_once("database/database.php");

/* ABSTRACT CLASS: Dao

   PURPOSE: This class is a base class for all Database Abstraction Objects
            It contains a few basic methods for executing common queries.

   NOTE:    If you are ever rewriting this for another type of database,
            its important that you maintain the same interface.
 */
abstract class Dao
{
    protected $database = null;  // Database resource
    private $name     = null;    // Table name
    private $id       = null;    // Name to be used as index (by default, 'id')

    const SEARCH_ANY = 0;
    const SEARCH_ALL = 1;
    const SEARCH_PHRASE = 2;

    /* PUBLIC: Dao
    PURPOSE: Constructor.
    @param: Database $database - reference to the database object (database.php)
    @param: The name of the table this Dao represents
    @param: The field to use as the 'ID' (id by default)
    */
    protected function __construct(string $name, string $id = 'id')
    {
        $this->database = Database::getInstance();
        $this->name     = $name;
        $this->id       = $id;
    }


    /* PUBLIC: startTransaction
    PURPOSE: Begins a transaction (duh..)
    @return void;
    */
    public function startTransaction()
    {
        $this->database->query('START TRANSACTION',0);
    }


    /* PUBLIC: endTransaction
    PURPOSE: ends a transaction and either commits the changes or rolls them back
        depending on the specified parameter (defaults to commit if not specified)
    @param boolean commit
    @return void
    */
    public function endTransaction($commit=true)
    {
        $this->database->query(($commit)?'COMMIT;':'ROLLBACK;');
    }


    /* PUBLIC: Drop
    PURPOSE: Drops the specified entries from the table
    @param: $id - a string (or int) specifying either the "where" clause or
             or the ID of the field(s) to drop.  By default, deletes everything
             (be careful)

    @return boolean - true on success, false on failure.
    */
    public function drop($id = '*')
    {

        $query = "delete from `{$this->name}`";

        // we know which exact entry we want.
        if (intval($id) > 0)
            $query .= " where `{$this->id}` = '$id'";

        // we have been given a where clause to go by.
        // if $id == '*' then we want everything in the table.
        else if ($id != '*')
            $query .= " where ".$id;


        return $this->database->query($query,0);
    }


    /* PUBLIC: select
    PURPOSE: Runs a select query to get a list of entries (in a result object)
    @param: string $what     - Which fields do you want? (* for all)
    @param: string $where    - A where clause (* for all) or integer ID
    @param: string $sort     - Which field to sort on? By default sort on the ID field specified above.
    @param: string $sort     - Which way to sort Ascending or Descending (ASC or DESC respectively)
    @param: int $limit_start - Allows you to get just a subselection of the results,
                  eg, 10 starts the result listing after the 10th matching entry
    @param: int $limit_count - How many results to return (ie n results after the $limit_start)

    @return Result object
    */
    public function select($what = '*',$where = '*',$sort = '', $order = 'ASC',$limit_start = null,$limit_count = null)
    {

        $query = "select $what from `{$this->name}`";

        // we know exactly which ID we want...
        if (intval($where) > 0)
            $query .= " where `{$this->id}` = '$where'";

        // we have been given a where clause to go by.
        // if $where == '*' then we want everything in the table.
        else if ($where != '*')
            $query .= " where " . $where;

        if (is_array($sort))
        {
            if (count($sort)>0)
                $query .= ' order by ';
            for ($i=0;$i<count($sort);$i++)
            {
                $query .= '`'.$sort[$i].'` ';
                $query .= (is_array($order))?$order[$i]:$order;
                if ($i < count($sort)-1)
                    $query .= ', ';
            }

        } else if ($sort != '')
            $query .= " order by `{$sort}` $order";

        if ($limit_start >= 0 && $limit_count > 0)
            $query .= " LIMIT $limit_start,$limit_count";

        //add the allmighty semicolon to the end
        $query .= ';';

        //run it!
        
        
        return $this->database->query($query);
    }


    /* PUBLIC:  insert
    PURPOSE: Inserts into the current table.
    @param:  string[] - an array of strings corresponding to each field in the table.
    @return  int or boolean - If successfully, returns the ID of the new entry, otherwise
        false.
    */
    public function insert($fields)
    {
        $query = "insert into `{$this->name}` (";

        $keys = array();
        $values = array();
        foreach ($fields as $key => $value)
        {
            $keys[] = '`'.$key.'`';
            if ($value === null)
                $values[] = 'NULL';
            else
                $values[] = '"'.$this->database->prepareStatement($value).'"';
        }

        $query .= join(',',$keys).') values ('.join(',',$values).');';
        if ($this->database->query($query,0))
        {
            // get the insert ID.
            $id = $this->database->getLastInsertId();
            return $id;

        } 
        return false;
    }

    public function insertMultiple($entries)
    {
        $valuesStr = array();
        
        foreach($entries as $fields)
        {
            $values = array();
            $keys = array();
            foreach ($fields as $key => $value)
            {
                $keys[] = '`'.$key.'`';
                if ($value === null)
                    $values[] = 'NULL';
                else
                    $values[] = '"'.$this->database->prepareStatement($value).'"';
            }
            $keysStr = join(',', $keys);
            $valuesStr[] = '('.join(',', $values).')';
        }

        $query = 'INSERT INTO `'.$this->name.'` '.
                    '('.$keysStr.') VALUES '.join(',', $valuesStr).';';

        if ($this->database->query($query, false))
        {
            return $this->database->getNumRowsAffected();
        } 
        return false;
    }


    /* PUBLIC:  replace
    PURPOSE: Replaces into the current table.
    @param:  string[] - an array of strings corresponding to each field in the table.
    @return  int or boolean - If successfully, returns the ID of the new entry, otherwise
        false.
    */
    public function replace($fields)
    {
        $query = "replace into `{$this->name}` values(";

        $values = array();
        foreach ($fields as $value)
            $values[] = '"'.$this->database->prepareStatement($value).'"';

        $query .= join(',',$values) . ');';

        if ($this->database->query($query,0))
        {
            // get the insert ID.
            $id = $this->database->insert_id();
            return $id;

        } else
            return false;
    }


    /* PUBLIC: update
    PURPOSE: Updates the entries in a table as specified.
    @param  string[] - An associative array whose keys are the names
              of the fields to change and the values are the new
              values to use.

    @param  string   - The where clause (* for all) or specific ID to use
    @return boolean
    */
    public function update($fields,$where = '*')
    {

        $query = "update `{$this->name}` set ";

        $tmp = array();
        foreach ($fields as $key=>$value)
            $tmp[]= ($value === null)?
                "`$key`=null":
                "`$key`='".$this->database->prepareStatement($value)."'";

        $query .= join(', ',$tmp);

        if (intval($where) > 0)
            $query .= " where `{$this->id}` = '$where'";
        else if ($where != '*')
            $query .= " where " . $where;

        $query.=';';

        if ($this->database->query($query,0))
            return true;
        else
            return false;
    }


    /* PUBLIC searchClause
       PURPOSE: Generates a where clause used for searching.

       method == self::SEARCH_ANY
       method == self::SEARCH_ALL
       method == self::SEARCH_PHRASE

       @param String[]  columns
       @param String searchString
       @param int method
       @result String whereClause
     */
    protected function searchClause(array $search, string $keywords, int $method=0)
    {
        if (count($search) > 0)
        {
            $keywords=trim($keywords);
            $search_terms = array();

            $keywords = ($method == self::SEARCH_PHRASE ? array($this->database->prepareStatement($keywords)):preg_split("/\s+/",$keywords));
            foreach ($keywords as $key)
            {
                if (strlen($key) >= 3)
                {
                    $tmp = array();
                    foreach ($search as $col)
                        if ($method == self::SEARCH_ALL)
                            $tmp[] = $col . " like '%" .$this->database->prepareStatement($key). "%'";
                        else
                            $search_terms[] = $col . " like '%" .$this->database->prepareStatement($key). "%'";

                    if ($method == self::SEARCH_ALL)
                        $search_terms[] = '('.join(' or ',$tmp).')';
                }
            }
            return (count($search_terms) > 0)? join(($method == self::SEARCH_ALL)? " and ": " or ",$search_terms) : '*';
        }
        return '*';
    }
}

?>
