<?php

  /**
   * Ruckusing
   *
   * @category   Ruckusing
   * @package    Ruckusing_Adapter
   * @subpackage PgSQL
   * @author     Cody Caughlan <codycaughlan % gmail . com>
   * @link       https://github.com/ruckus/ruckusing-migrations
   */

  // max length of an identifier like a column or index name
  define('PG_MAX_IDENTIFIER_LENGTH', 64);

  /**
   * Implementation of Ruckusing_Adapter_PgSQL_Base
   *
   * @category   Ruckusing
   * @package    Ruckusing_Adapter
   * @subpackage PgSQL
   * @author     Cody Caughlan <codycaughlan % gmail . com>
   * @link       https://github.com/ruckus/ruckusing-migrations
   */
  class Ruckusing_Adapter_PgSQL_Base extends Ruckusing_Adapter_Base implements Ruckusing_Adapter_Interface {
    /**
     * Name of adapter
     *
     * @var string
     */
    private $_name = "Postgres";

    /**
     * tables
     *
     * @var array
     */
    private $_tables = [];

    /**
     * tables_loaded
     *
     * @var boolean
     */
    private $_tables_loaded = false;

    /**
     * version
     *
     * @var string
     */
    private $_version = '1.0';

    /**
     * Indicate if is in transaction
     *
     * @var boolean
     */
    private $_in_trx = false;

    /**
     * Creates an instance of Ruckusing_Adapter_PgSQL_Base
     *
     * @param array $dsn The current dsn being used
     * @param Ruckusing_Util_Logger $logger the current logger
     *
     * @return Ruckusing_Adapter_PgSQL_Base
     */
    public function __construct($dsn, $logger) {
      parent::__construct($dsn);
      $this->connect($dsn);
      $this->set_logger($logger);
    }

    /**
     * Get the current db name
     *
     * @return string
     */
    public function get_database_name() {
      return $this->db_info['database'];
    }

    /**
     * Check support for migrations
     *
     * @return boolean
     */
    public function supports_migrations() {
      return true;
    }

    /**
     * Get the column native types
     *
     * @return array
     */
    public function native_database_types() {
      $types = [
        'primary_key'   => [ 'name' => 'serial' ],
        'string'        => [ 'name' => 'varchar', 'limit' => 255 ],
        'text'          => [ 'name' => 'text' ],
        'tinytext'      => [ 'name' => 'text' ],
        'mediumtext'    => [ 'name' => 'text' ],
        'integer'       => [ 'name' => 'integer' ],
        'tinyinteger'   => [ 'name' => 'smallint' ],
        'smallinteger'  => [ 'name' => 'smallint' ],
        'mediuminteger' => [ 'name' => 'integer' ],
        'biginteger'    => [ 'name' => 'bigint' ],
        'float'         => [ 'name' => 'float' ],
        'decimal'       => [ 'name' => 'decimal', 'scale' => 0, 'precision' => 10 ],
        'datetime'      => [ 'name' => 'timestamptz' ],
        'timestamp'     => [ 'name' => 'timestamp' ],
        'time'          => [ 'name' => 'time' ],
        'date'          => [ 'name' => 'date' ],
        'binary'        => [ 'name' => 'bytea' ],
        'tinybinary'    => [ 'name' => "bytea" ],
        'mediumbinary'  => [ 'name' => "bytea" ],
        'longbinary'    => [ 'name' => "bytea" ],
        'boolean'       => [ 'name' => 'boolean' ],
        'tsvector'      => [ 'name' => 'tsvector' ],
        'uuid'          => [ 'name' => 'uuid' ],
        'money'         => [ 'name' => 'money' ],
      ];

      return $types;
    }

    //-----------------------------------
    // PUBLIC METHODS
    //-----------------------------------

    /**
     * Create the schema table, if necessary
     */
    public function create_schema_version_table() {
      if ( !$this->has_table($this->get_schema_version_table_name()) ) {
        $t = $this->create_table($this->get_schema_version_table_name(), [ 'id' => false ]);
        $t->column('version', 'string');
        $t->finish();
        $this->add_index($this->get_schema_version_table_name(), 'version', [ 'unique' => true ]);
      }
    }

    /**
     * Start Transaction
     */
    public function start_transaction() {
      if ( $this->inTransaction() === false ) {
        $this->beginTransaction();
      }
    }

    /**
     * Commit Transaction
     */
    public function commit_transaction() {
      if ( $this->inTransaction() ) {
        $this->commit();
      }
    }

    /**
     * Rollback Transaction
     */
    public function rollback_transaction() {
      if ( $this->inTransaction() ) {
        $this->rollback();
      }
    }

    /**
     * Column definition
     *
     * @param string $column_name the column name
     * @param string $type the type of the column
     * @param array $options column options
     *
     * @return string
     */
    public function column_definition($column_name, $type, $options = null) {
      $col = new Ruckusing_Adapter_ColumnDefinition($this, $column_name, $type, $options);

      return $col->__toString();
    }

    /**
     * Returns a table's primary key and belonging sequence.
     *
     * @param string $table the table name
     *
     * @return array
     */
    public function pk_and_sequence_for($table) {
      $sql    = <<<SQL
      SELECT attr.attname, seq.relname
      FROM pg_class      seq,
           pg_attribute  attr,
           pg_depend     dep,
           pg_namespace  name,
           pg_constraint cons
      WHERE seq.oid           = dep.objid
        AND seq.relkind       = 'S'
        AND attr.attrelid     = dep.refobjid
        AND attr.attnum       = dep.refobjsubid
        AND attr.attrelid     = cons.conrelid
        AND attr.attnum       = cons.conkey[1]
        AND cons.contype      = 'p'
        AND dep.refobjid      = '%s'::regclass
SQL;
      $sql    = sprintf($sql, $table);
      $result = $this->select_one($sql);
      if ( $result ) {
        return ( [ $result['attname'], $result['relname'] ] );
      } else {
        return [];
      }
    }

    //-------- DATABASE LEVEL OPERATIONS

    /**
     * Create database cannot run in a transaction block so if we're in a transaction
     * than commit it, do our thing and then re-invoke the transaction
     *
     * @param string $db the db name
     *
     * @param array $options
     *
     * @return boolean
     */
    public function create_database($db, $options = []) {
      $was_in_transaction = false;
      if ( $this->inTransaction() ) {
        $this->commit_transaction();
        $was_in_transaction = true;
      }

      if ( !array_key_exists('encoding', $options) ) {
        $options['encoding'] = 'utf8';
      }
      $ddl = sprintf("CREATE DATABASE %s", $this->identifier($db));
      if ( array_key_exists('owner', $options) ) {
        $ddl .= " OWNER = \"{$options['owner']}\"";
      }
      if ( array_key_exists('template', $options) ) {
        $ddl .= " TEMPLATE = \"{$options['template']}\"";
      }
      if ( array_key_exists('encoding', $options) ) {
        $ddl .= " ENCODING = '{$options['encoding']}'";
      }
      if ( array_key_exists('tablespace', $options) ) {
        $ddl .= " TABLESPACE = \"{$options['tablespace']}\"";
      }
      if ( array_key_exists('connection_limit', $options) ) {
        $connlimit = intval($options['connection_limit']);
        $ddl       .= " CONNECTION LIMIT = {$connlimit}";
      }
      $result = $this->query($ddl);

      if ( $was_in_transaction ) {
        $this->start_transaction();
        $was_in_transaction = false;
      }

      return ( $result === true );
    }

    /**
     * Check if a db exists
     *
     * @param string $db the db name
     *
     * @return boolean
     */
    public function database_exists($db) {
      $sql    = sprintf("SELECT datname FROM pg_database WHERE datname = '%s'", $db);
      $result = $this->select_one($sql);

      return ( count($result) == 1 && $result['datname'] == $db );
    }

    /**
     * Drop a database
     *
     * @param string $db the db name
     *
     * @return boolean
     */
    public function drop_database($db) {
      if ( !$this->database_exists($db) ) {
        return false;
      }
      $ddl    = sprintf("DROP DATABASE IF EXISTS %s", $this->quote_table_name($db));
      $result = $this->query($ddl);

      return ( $result === true );
    }

    /**
     * Dump the complete schema of the DB. This is really just all of the
     * CREATE TABLE statements for all of the tables in the DB.
     * NOTE: this does NOT include any INSERT statements or the actual data
     *
     * @param string $output_file the filepath to output to
     *
     * @return int|FALSE
     */
    public function schema($output_file) {
      $command = sprintf("pg_dump -U %s -Fp -s -f '%s' %s --host %s", $this->db_info['user'], $output_file, $this->db_info['database'], $this->db_info['host']);

      return system($command);
    }

    /**
     * Check if a table exists
     *
     * @param string $tbl the table name
     * @param boolean $reload_tables reload table or not
     *
     * @return boolean
     */
    public function table_exists($tbl, $reload_tables = false) {
      $this->load_tables($reload_tables);

      return array_key_exists($tbl, $this->_tables);
    }

    public function execute($query) {
      return $this->query($query);
    }

    /**
     * Wrapper to execute a query
     *
     * @param string $query query to run
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function query($query) {
      $this->logger->log($query);
      $query_type = $this->determine_query_type($query);
      $data       = [];
      if ( $query_type == SQL_SELECT || $query_type == SQL_SHOW ) {
        $res = pg_query($this->conn, $query);
        if ( $this->isError($res) ) {
          throw new Ruckusing_Exception(sprintf("Error executing 'query' with:\n%s\n\nReason: %s\n\n", $query, pg_last_error($this->conn)), Ruckusing_Exception::QUERY_ERROR);
        }
        while ( $row = pg_fetch_assoc($res) ) {
          $data[] = $row;
        }

        return $data;
      } else {
        // INSERT, DELETE, etc...
        $res = pg_query($this->conn, $query);
        if ( $this->isError($res) ) {
          throw new Ruckusing_Exception(sprintf("Error executing 'query' with:\n%s\n\nReason: %s\n\n", $query, pg_last_error($this->conn)), Ruckusing_Exception::QUERY_ERROR);
        }
        // if the query contained a 'RETURNING' class then grab its value
        $returning_regex = '/ RETURNING \"(.+)\"$/';
        $matches         = [];
        if ( preg_match($returning_regex, $query, $matches) ) {
          if ( count($matches) == 2 ) {
            $returning_column_value = pg_fetch_result($res, 0, $matches[1]);

            return ( $returning_column_value );
          }
        }

        return true;
      }
    }

    /**
     * Execute several queries
     *
     * @param string $queries queries to run
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function multi_query($queries) {
      $res = pg_query($this->conn, $queries);
      if ( $this->isError($res) ) {
        throw new Ruckusing_Exception(sprintf("Error executing 'query' with:\n%s\n\nReason: %s\n\n", $queries, pg_last_error($this->conn)), Ruckusing_Exception::QUERY_ERROR);
      }

      return true;
    }

    /**
     * Select one
     *
     * @param string $query query to run
     *
     * @return array
     * @throws Ruckusing_Exception
     */
    public function select_one($query) {
      $this->logger->log($query);
      $query_type = $this->determine_query_type($query);
      if ( $query_type == SQL_SELECT || $query_type == SQL_SHOW ) {
        $res = pg_query($this->conn, $query);
        if ( $this->isError($res) ) {
          throw new Ruckusing_Exception(sprintf("Error executing 'query' with:\n%s\n\nReason: %s\n\n", $query, pg_last_error($this->conn)), Ruckusing_Exception::QUERY_ERROR);
        }

        return pg_fetch_assoc($res);
      } else {
        throw new Ruckusing_Exception("Query for select_one() is not one of SELECT or SHOW: $query", Ruckusing_Exception::QUERY_ERROR);
      }
    }

    /**
     * Select all
     *
     * @param string $query query to run
     *
     * @return array
     */
    public function select_all($query) {
      return $this->query($query);
    }

    /**
     * Use this method for non-SELECT queries
     * Or anything where you dont necessarily expect a result string, e.g. DROPs, CREATEs, etc.
     *
     * @param string $ddl query to run
     *
     * @return boolean
     */
    public function execute_ddl($ddl) {
      $result = $this->query($ddl);

      return true;
    }

    /**
     * Drop table
     *
     * @param string $tbl the table name
     *
     * @return boolean
     */
    public function drop_table($tbl) {
      $ddl    = sprintf("DROP TABLE IF EXISTS %s", $this->quote_table_name($tbl));
      $result = $this->query($ddl);

      return true;
    }

    /**
     * Create table
     *
     * @param string $table_name the table name
     * @param array $options the options
     *
     * @return bool|Ruckusing_Adapter_PgSQL_TableDefinition
     */
    public function create_table($table_name, $options = []) {
      return new Ruckusing_Adapter_PgSQL_TableDefinition($this, $table_name, $options);
    }

    /**
     * Escape a string for mysql
     *
     * @param string $string the string
     *
     * @return string
     */
    public function quote_string($string) {
      return pg_escape_string($string);
    }

    /**
     * Quote a string
     *
     * @param string $string the string
     *
     * @return string
     */
    public function identifier($string) {
      return '"' . $string . '"';
    }

    /**
     * Quote table name
     *
     * @param string $string the string
     *
     * @return string
     */
    public function quote_table_name($string) {
      return '"' . $string . '"';
    }

    /**
     * Quote column name
     *
     * @param string $string the string
     *
     * @return string
     */
    public function quote_column_name($string) {
      return '"' . $string . '"';
    }

    /**
     * Quote a string
     *
     * @param string $value the string
     * @param string $column the column
     *
     * @return string
     */
    public function quote($value, $column = null) {
      $type = gettype($value);
      if ( $type == "double" ) {
        return ( "'{$value}'" );
      } elseif ( $type == "integer" ) {
        return ( "'{$value}'" );
      } else {
        // TODO: this global else is probably going to be problematic.
        // I think eventually we'll need to do more introspection and handle all possible types
        return ( "'{$value}'" );
      }
      /*
       "boolean"
      "integer"
      "double" (for historical reasons "double" is returned in case of a float, and not simply "float")
      "string"
      "array"
      "object"
      "resource"
      "NULL"
      "unknown type"
      */
    }

    /*

    */
    /**
     * Renames a table.
     * Also renames a table's primary key sequence if the sequence name matches the Ruckusing Migrations default.
     *
     * @param string $name the current table name
     * @param string $new_name the new table name
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function rename_table($name, $new_name) {
      if ( empty($name) ) {
        throw new Ruckusing_Exception("Missing original column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($new_name) ) {
        throw new Ruckusing_Exception("Missing new column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      $sql = sprintf("ALTER TABLE %s RENAME TO %s", $this->identifier($name), $this->identifier($new_name));
      $this->execute_ddl($sql);
      $pk_and_sequence_for = $this->pk_and_sequence_for($new_name);
      if ( !empty($pk_and_sequence_for) ) {
        list($pk, $seq) = $pk_and_sequence_for;
        if ( $seq == "{$name}_{$pk}_seq" ) {
          $new_seq = "{$new_name}_{$pk}_seq";
          $this->execute_ddl("ALTER TABLE $seq RENAME TO $new_seq");
        }
      }
    }

    /**
     * Add a column
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param string $type the column type
     * @param array $options column options
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function add_column($table_name, $column_name, $type, $options = []) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($type) ) {
        throw new Ruckusing_Exception("Missing type parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      //default types
      if ( !array_key_exists('limit', $options) ) {
        $options['limit'] = null;
      }
      if ( !array_key_exists('precision', $options) ) {
        $options['precision'] = null;
      }
      if ( !array_key_exists('scale', $options) ) {
        $options['scale'] = null;
      }
      $sql = sprintf("ALTER TABLE %s ADD COLUMN %s %s", $this->quote_table_name($table_name), $this->quote_column_name($column_name), $this->type_to_sql($type, $options));
      $sql .= $this->add_column_options($type, $options);

      return $this->execute_ddl($sql);
    }

    /**
     * Drop a column
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     *
     * @return boolean
     */
    public function remove_column($table_name, $column_name) {
      $sql = sprintf("ALTER TABLE %s DROP COLUMN %s", $this->quote_table_name($table_name), $this->quote_column_name($column_name));

      return $this->execute_ddl($sql);
    }

    /**
     * Rename a column
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param string $new_column_name the new column name
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function rename_column($table_name, $column_name, $new_column_name) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing original column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($new_column_name) ) {
        throw new Ruckusing_Exception("Missing new column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      $column_info  = $this->column_info($table_name, $column_name);
      $current_type = $column_info['type'];
      $sql          = sprintf("ALTER TABLE %s RENAME COLUMN %s TO %s", $this->quote_table_name($table_name), $this->quote_column_name($column_name), $this->quote_column_name($new_column_name));

      return $this->execute_ddl($sql);
    }

    /**
     * Change a column
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param string $type the column type
     * @param array $options column options
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function change_column($table_name, $column_name, $type, $options = []) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing original column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($type) ) {
        throw new Ruckusing_Exception("Missing type parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      $column_info = $this->column_info($table_name, $column_name);
      //default types
      if ( !array_key_exists('limit', $options) ) {
        $options['limit'] = null;
      }
      if ( !array_key_exists('precision', $options) ) {
        $options['precision'] = null;
      }
      if ( !array_key_exists('scale', $options) ) {
        $options['scale'] = null;
      }
      $sql = sprintf("ALTER TABLE %s ALTER COLUMN %s TYPE %s", $this->quote_table_name($table_name), $this->quote_column_name($column_name), $this->type_to_sql($type, $options));
      $sql .= $this->add_column_options($type, $options, true);

      if ( array_key_exists('default', $options) ) {
        $this->change_column_default($table_name, $column_name, $options['default']);
      }
      if ( array_key_exists('null', $options) ) {
        $default = array_key_exists('default', $options) ? $options['default'] : null;
        $this->change_column_null($table_name, $column_name, $options['null'], $default);
      }

      return $this->execute_ddl($sql);
    }

    /**
     * Change column default
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param string $default
     *
     * @return boolean
     */
    private function change_column_default($table_name, $column_name, $default) {
      $sql = sprintf("ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s", $this->quote_table_name($table_name), $this->quote_column_name($column_name), $this->quote($default));
      $this->execute_ddl($sql);
    }

    /**
     * Change column null
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param string $null
     * @param string $default
     *
     * @return boolean
     */
    private function change_column_null($table_name, $column_name, $null, $default = null) {
      if ( ( $null === false ) || ( $default !== null ) ) {
        $sql = sprintf("UPDATE %s SET %s=%s WHERE %s IS NULL", $this->quote_table_name($table_name), $this->quote_column_name($column_name), $this->quote($default), $this->quote_column_name($column_name));
        $this->query($sql);
      }
      $sql = sprintf("ALTER TABLE %s ALTER %s %s NOT NULL", $this->quote_table_name($table_name), $this->quote_column_name($column_name), ( $null ? 'DROP' : 'SET' ));
      $this->query($sql);
    }

    /**
     * Get a column info
     *
     * @param string $table the table name
     * @param string $column the column name
     *
     * @return array
     * @throws Ruckusing_Exception
     */
    public function column_info($table, $column) {
      if ( empty($table) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column) ) {
        throw new Ruckusing_Exception("Missing original column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      try {
        $sql    = <<<SQL
      SELECT a.attname, format_type(a.atttypid, a.atttypmod), d.adsrc, a.attnotnull
        FROM pg_attribute a LEFT JOIN pg_attrdef d
          ON a.attrelid = d.adrelid AND a.attnum = d.adnum
       WHERE a.attrelid = '%s'::regclass
         AND a.attname = '%s'
         AND a.attnum > 0 AND NOT a.attisdropped
       ORDER BY a.attnum
SQL;
        $sql    = sprintf($sql, $this->quote_table_name($table), $column);
        $result = $this->select_one($sql);
        $data   = [];
        if ( is_array($result) ) {
          $data['type']    = $result['format_type'];
          $data['name']    = $column;
          $data['field']   = $column;
          $data['null']    = $result['attnotnull'] == 'f';
          $data['default'] = $result['adsrc'];
        } else {
          $data = null;
        }

        return $data;
      } catch ( Exception $e ) {
        return null;
      }
    }

    /**
     * Add an index
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param array $options index options
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function add_index($table_name, $column_name, $options = []) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      //unique index?
      if ( is_array($options) && array_key_exists('unique', $options) && $options['unique'] === true ) {
        $unique = true;
      } else {
        $unique = false;
      }

      //did the user specify an index name?
      if ( is_array($options) && array_key_exists('name', $options) ) {
        $index_name = $options['name'];
      } else {
        $index_name = Ruckusing_Util_Naming::index_name($table_name, $column_name);
      }

      if ( strlen($index_name) > PG_MAX_IDENTIFIER_LENGTH ) {
        $msg = "The auto-generated index name is too long for Postgres (max is 64 chars). ";
        $msg .= "Considering using 'name' option parameter to specify a custom name for this index.";
        $msg .= " Note: you will also need to specify";
        $msg .= " this custom name in a drop_index() - if you have one.";
        throw new Ruckusing_Exception($msg, Ruckusing_Exception::INVALID_INDEX_NAME);
      }
      if ( !is_array($column_name) ) {
        $column_names = [ $column_name ];
      } else {
        $column_names = $column_name;
      }
      $cols = [];
      foreach ( $column_names as $name ) {
        $cols[] = $this->quote_column_name($name);
      }
      $sql = sprintf("CREATE %sINDEX %s ON %s(%s)", $unique ? "UNIQUE " : "", $this->quote_column_name($index_name), $this->quote_column_name($table_name), join(", ", $cols));

      return $this->execute_ddl($sql);
    }

    /**
     * Drop an index
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param array $options index options
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function remove_index($table_name, $column_name, $options = []) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      //did the user specify an index name?
      if ( is_array($options) && array_key_exists('name', $options) ) {
        $index_name = $options['name'];
      } else {
        $index_name = Ruckusing_Util_Naming::index_name($table_name, $column_name);
      }
      $sql = sprintf("DROP INDEX %s", $this->quote_column_name($index_name));

      return $this->execute_ddl($sql);
    }

    /**
     * Add timestamps
     *
     * @param string $table_name The table name
     * @param string $created_column_name Created at column name
     * @param string $updated_column_name Updated at column name
     *
     * @return boolean
     */
    public function add_timestamps($table_name, $created_column_name, $updated_column_name) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($created_column_name) ) {
        throw new Ruckusing_Exception("Missing created at column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($updated_column_name) ) {
        throw new Ruckusing_Exception("Missing updated at column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      $created_at = $this->add_column($table_name, $created_column_name, "datetime", [ "null" => false ]);
      $updated_at = $this->add_column($table_name, $updated_column_name, "datetime", [ "null" => false ]);

      return $created_at && $updated_at;
    }

    /**
     * Remove timestamps
     *
     * @param string $table_name The table name
     * @param string $created_column_name Created at column name
     * @param string $updated_column_name Updated at column name
     *
     * @return boolean
     */
    public function remove_timestamps($table_name, $created_column_name, $updated_column_name) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($created_column_name) ) {
        throw new Ruckusing_Exception("Missing created at column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($updated_column_name) ) {
        throw new Ruckusing_Exception("Missing updated at column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      $created_at = $this->remove_column($table_name, $created_column_name);
      $updated_at = $this->remove_column($table_name, $updated_column_name);

      return $created_at && $updated_at;
    }

    /**
     * Check an index
     *
     * @param string $table_name the table name
     * @param string $column_name the column name
     * @param array $options index options
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    public function has_index($table_name, $column_name, $options = []) {
      if ( empty($table_name) ) {
        throw new Ruckusing_Exception("Missing table name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($column_name) ) {
        throw new Ruckusing_Exception("Missing column name parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      //did the user specify an index name?
      if ( is_array($options) && array_key_exists('name', $options) ) {
        $index_name = $options['name'];
      } else {
        $index_name = Ruckusing_Util_Naming::index_name($table_name, $column_name);
      }
      $indexes = $this->indexes($table_name);
      foreach ( $indexes as $idx ) {
        if ( $idx['name'] == $index_name ) {
          return true;
        }
      }

      return false;
    }

    /**
     * Return all indexes of a table
     *
     * @param string $table_name the table name
     *
     * @return array
     */
    public function indexes($table_name) {
      $sql    = <<<SQL
       SELECT distinct i.relname, d.indisunique, d.indkey, pg_get_indexdef(d.indexrelid), t.oid
       FROM pg_class t
       INNER JOIN pg_index d ON t.oid = d.indrelid
       INNER JOIN pg_class i ON d.indexrelid = i.oid
       WHERE i.relkind = 'i'
         AND d.indisprimary = 'f'
         AND t.relname = '%s'
         AND i.relnamespace IN (SELECT oid FROM pg_namespace WHERE nspname = ANY (current_schemas(false)) )
      ORDER BY i.relname
SQL;
      $sql    = sprintf($sql, $table_name);
      $result = $this->select_all($sql);

      $indexes = [];
      foreach ( $result as $row ) {
        $indexes[] = [
          'name'   => $row['relname'],
          'unique' => $row['indisunique'] == 't' ? true : false,
        ];
      }

      return $indexes;
    }

    /**
     * get primary keys
     *
     * @param string $table_name the table name
     *
     * @return array
     */
    public function primary_keys($table_name) {
      $sql    = <<<SQL
      SELECT
        pg_attribute.attname,
        format_type(pg_attribute.atttypid, pg_attribute.atttypmod)
      FROM pg_index, pg_class, pg_attribute
      WHERE
        pg_class.oid = '%s'::regclass AND
        indrelid = pg_class.oid AND
        pg_attribute.attrelid = pg_class.oid AND
        pg_attribute.attnum = any(pg_index.indkey)
        AND indisprimary
SQL;
      $sql    = sprintf($sql, $table_name);
      $result = $this->select_all($sql);

      $primary_keys = [];
      foreach ( $result as $row ) {
        $primary_keys[] = [
          'name' => $row['attname'],
          'type' => $row['format_type'],
        ];
      }

      return $primary_keys;
    }

    /**
     * Convert type to sql
     *
     * @param string $type the native type
     * @param array $options
     *
     * @return string
     * @throws Ruckusing_Exception
     */
    public function type_to_sql($type, $options = []) {
      $natives = $this->native_database_types();
      if ( !array_key_exists($type, $natives) ) {
        $error = sprintf("Error: I dont know what column type of '%s' maps to for Postgres.", $type);
        $error .= "\nYou provided: {$type}\n";
        $error .= "Valid types are: \n";
        $types = array_keys($natives);
        foreach ( $types as $t ) {
          if ( $t == 'primary_key' ) {
            continue;
          }
          $error .= "\t{$t}\n";
        }
        throw new Ruckusing_Exception($error, Ruckusing_Exception::INVALID_ARGUMENT);
      }

      $scale     = null;
      $precision = null;
      $limit     = null;

      if ( isset($options['precision']) ) {
        $precision = $options['precision'];
      }
      if ( isset($options['scale']) ) {
        $scale = $options['scale'];
      }
      if ( isset($options['limit']) ) {
        $limit = $options['limit'];
      }

      $native_type = $natives[ $type ];
      if ( is_array($native_type) && array_key_exists('name', $native_type) ) {
        $column_type_sql = $native_type['name'];
      } else {
        return $native_type;
      }
      if ( $type == "decimal" ) {
        //ignore limit, use precison and scale
        if ( $precision == null && array_key_exists('precision', $native_type) ) {
          $precision = $native_type['precision'];
        }
        if ( $scale == null && array_key_exists('scale', $native_type) ) {
          $scale = $native_type['scale'];
        }
        if ( $precision != null ) {
          if ( is_int($scale) ) {
            $column_type_sql .= sprintf("(%d, %d)", $precision, $scale);
          } else {
            $column_type_sql .= sprintf("(%d)", $precision);
          }//scale
        } else {
          if ( $scale ) {
            throw new Ruckusing_Exception("Error adding decimal column: precision cannot be empty if scale is specified", Ruckusing_Exception::INVALID_ARGUMENT);
          }
        }//pre
      }
      // integer columns dont support limit (sizing)
      if ( $native_type['name'] != "integer" ) {
        if ( $limit == null && array_key_exists('limit', $native_type) ) {
          $limit = $native_type['limit'];
        }
        if ( $limit ) {
          $column_type_sql .= sprintf("(%d)", $limit);
        }
      }

      return $column_type_sql;
    }//type_to_sql

    /**
     * Add column options
     *
     * @param string $type the native type
     * @param array $options
     * @param boolean $performing_change
     *
     * @return string
     */
    public function add_column_options($type, $options, $performing_change = false) {
      $sql = "";

      if ( !is_array($options) ) {
        return $sql;
      }
      if ( !$performing_change ) {
        if ( array_key_exists('default', $options) && $options['default'] !== null ) {
          if ( is_int($options['default']) ) {
            $default_format = '%d';
          } elseif ( is_bool($options['default']) ) {
            $default_format = "'%d'";
          } else {
            $default_format = "'%s'";
          }
          $default_value = sprintf($default_format, $options['default']);
          $sql           .= sprintf(" DEFAULT %s", $default_value);
        }

        if ( array_key_exists('null', $options) && $options['null'] === false ) {
          $sql .= " NOT NULL";
        }
      }

      return $sql;
    }//add_column_options

    /**
     * Set current version
     *
     * @param string $version the version
     *
     * @return boolean
     */
    public function set_current_version($version) {
      $sql = sprintf("INSERT INTO %s (version) VALUES ('%s')", $this->get_schema_version_table_name(), $version);

      return $this->execute_ddl($sql);
    }

    /**
     * remove a version
     *
     * @param string $version the version
     *
     * @return boolean
     */
    public function remove_version($version) {
      $sql = sprintf("DELETE FROM %s WHERE version = '%s'", $this->get_schema_version_table_name(), $version);

      return $this->execute_ddl($sql);
    }

    /**
     * Return a message displaying the current version
     *
     * @return string
     */
    public function __toString() {
      return "Ruckusing_Adapter_PgSQL_Base, version " . $this->_version;
    }

    //-----------------------------------
    // PRIVATE METHODS
    //-----------------------------------

    /**
     * Connect to the db
     *
     * @param string $dsn the current dsn
     */
    private function connect($dsn) {
      $this->db_connect($dsn);
    }

    /**
     * Connect to the db
     *
     * @param string $dsn the current dsn
     *
     * @return boolean
     * @throws Ruckusing_Exception
     */
    private function db_connect($dsn) {
      if ( !function_exists('pg_connect') ) {
        throw new Ruckusing_Exception("\nIt appears you have not compiled PHP with Postgres support: missing function pg_connect()", Ruckusing_Exception::INVALID_CONFIG);
      }
      $db_info = $this->get_dsn();
      if ( $db_info ) {
        $this->db_info = $db_info;
        $conninfo      = sprintf('host=%s port=%s dbname=%s user=%s password=%s', $db_info['host'], ( !empty($db_info['port']) ? $db_info['port'] : '5432' ), $db_info['database'], $db_info['user'], $db_info['password']);
        $this->conn    = pg_connect($conninfo);
        if ( $this->conn === false ) {
          throw new Ruckusing_Exception("\n\nCould not connect to the DB, check host / user / password\n\n", Ruckusing_Exception::INVALID_CONFIG);
        }

        return true;
      } else {
        throw new Ruckusing_Exception("\n\nCould not extract DB connection information from: {$dsn}\n\n", Ruckusing_Exception::INVALID_CONFIG);
      }
    }

    /**
     * Delegate to PEAR
     *
     * @param boolean $o
     *
     * @return boolean
     */
    private function isError($o) {
      return $o === false;
    }

    /**
     * Initialize an array of table names
     *
     * @param boolean $reload
     */
    private function load_tables($reload = true) {
      if ( $this->_tables_loaded == false || $reload ) {
        $this->_tables = []; //clear existing structure
        $sql           = "SELECT tablename FROM pg_tables WHERE schemaname = ANY (current_schemas(false))";

        $res = pg_query($this->conn, $sql);
        while ( $row = pg_fetch_row($res) ) {
          $table                   = $row[0];
          $this->_tables[ $table ] = true;
        }
      }
    }

    /**
     * Check query type
     *
     * @param string $query query to run
     *
     * @return int
     */
    private function determine_query_type($query) {
      $query = strtolower(trim($query));
      $match = [];
      preg_match('/^(\w)*/i', $query, $match);
      $type = $match[0];
      switch ( $type ) {
        case 'select':
          return SQL_SELECT;
        case 'update':
          return SQL_UPDATE;
        case 'delete':
          return SQL_DELETE;
        case 'insert':
          return SQL_INSERT;
        case 'alter':
          return SQL_ALTER;
        case 'drop':
          return SQL_DROP;
        case 'create':
          return SQL_CREATE;
        case 'show':
          return SQL_SHOW;
        case 'rename':
          return SQL_RENAME;
        case 'set':
          return SQL_SET;
        default:
          return SQL_UNKNOWN_QUERY_TYPE;
      }
    }

    private function is_select($query_type) {
      return ( $query_type == SQL_SELECT );
    }

    /**
     * Detect whether or not the string represents a function call and if so
     * do not wrap it in single-quotes, otherwise do wrap in single quotes.
     *
     * @param string $str
     *
     * @return boolean
     */
    private function is_sql_method_call($str) {
      $str = trim($str);

      return ( substr($str, -2, 2) == "()" );
    }

    /**
     * Check if in transaction
     *
     * @return boolean
     */
    private function inTransaction() {
      return $this->_in_trx;
    }

    /**
     * Start transaction
     */
    private function beginTransaction() {
      if ( $this->_in_trx === true ) {
        throw new Ruckusing_Exception('Transaction already started', Ruckusing_Exception::QUERY_ERROR);
      }
      pg_query($this->conn, "BEGIN");
      $this->_in_trx = true;
    }

    /**
     * Commit a transaction
     */
    private function commit() {
      if ( $this->_in_trx === false ) {
        throw new Ruckusing_Exception('Transaction not started', Ruckusing_Exception::QUERY_ERROR);
      }
      pg_query($this->conn, "COMMIT");
      $this->_in_trx = false;
    }

    /**
     * Rollback a transaction
     */
    private function rollback() {
      if ( $this->_in_trx === false ) {
        throw new Ruckusing_Exception('Transaction not started', Ruckusing_Exception::QUERY_ERROR);
      }
      pg_query($this->conn, "ROLLBACK");
      $this->_in_trx = false;
    }

    /**
     * @param string $fromTable
     * @param string $fromColumn
     * @param string $toTable
     * @param string $toColumn
     *
     * @param string $onUpdate
     * @param string $onDelete
     *
     * @return bool|mixed
     * @throws Ruckusing_Exception
     */
    public function foreignKey($fromTable, $fromColumn, $toTable, $toColumn, $onUpdate = 'cascade', $onDelete = '') {
      if ( empty($fromTable) ) {
        throw new Ruckusing_Exception("Missing fromTable parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($fromColumn) ) {
        throw new Ruckusing_Exception("Missing fromColumn parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($toTable) ) {
        throw new Ruckusing_Exception("Missing toTable parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }
      if ( empty($toColumn) ) {
        throw new Ruckusing_Exception("Missing toColumn parameter", Ruckusing_Exception::INVALID_ARGUMENT);
      }

      $sql = "ALTER TABLE %s ADD FOREIGN KEY (%s) REFERENCES %s (%s) ";

      if ( $onUpdate ) {
        $sql .= ' ON UPDATE ' . $onUpdate;
      }
      if ( $onDelete ) {
        $sql .= ' ON DELETE ' . $onDelete;
      }

      $sql = sprintf($sql, $this->quote_table_name($fromTable), $this->quote_column_name($fromColumn), $this->quote_table_name($toTable), $this->quote_column_name($toColumn));

      return $this->execute_ddl($sql);
    }

    /**
     * @param string $fromTable
     * @param string $fromColumn
     * @param string $onUpdate
     * @param string $onDelete
     *
     * @return mixed
     * @throws Ruckusing_Exception
     */
    public function quickForeignKey($fromTable, $fromColumn, $onUpdate = 'cascade', $onDelete = '') {
      $index = strrpos($fromColumn, '_');

      if ( $index === false ) {
        throw new Ruckusing_Exception('Cant make quick foreign key. Wrong $fromColumn format. Should be tablename_id', Ruckusing_Exception::INVALID_ARGUMENT);
      }

      return $this->foreignKey($fromTable, $fromColumn, substr($fromColumn, 0, $index), 'id', $onUpdate, $onDelete);
    }
  }
