# From https://richjenks.com/change-mysql-database-table-prefix/

###############################################
# Change table prefix
###############################################
SET @database   = "database_name";
SET @old_prefix = "old_prefix_";
SET @new_prefix = "new_prefix_";
 
SELECT
    concat(
        "RENAME TABLE ",
        TABLE_NAME,
        " TO ",
        replace(TABLE_NAME, @old_prefix, @new_prefix),
        ';'
    ) AS "SQL"
FROM information_schema.TABLES WHERE TABLE_SCHEMA = @database;

###############################################
# Add table prefix
###############################################
SET @database = "database_name";
SET @prefix   = "prefix_";
 
SELECT
    concat(
        "RENAME TABLE ",
        TABLE_NAME,
        " TO ",
        @prefix,
        TABLE_NAME,
        ';'
    ) AS "SQL"
FROM information_schema.TABLES WHERE TABLE_SCHEMA = @database;