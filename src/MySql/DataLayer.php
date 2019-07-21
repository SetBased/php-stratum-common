<?php
declare(strict_types=1);

namespace SetBased\Stratum\MySql;

use mysqli_stmt;
use SetBased\Exception\FallenException;
use SetBased\Exception\RuntimeException;
use SetBased\Stratum\BulkHandler;
use SetBased\Stratum\Exception\ResultException;
use SetBased\Stratum\MySql\Exception\DataLayerException;

/**
 * Supper class for routine wrapper classes.
 */
class DataLayer
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The default character set to be used when sending data from and to the MySQL instance.
   *
   * @var string
   *
   * @since 1.0.0
   * @api
   */
  public $charSet = 'utf8';

  /**
   * If set queries must be logged.
   *
   * @var bool
   *
   * @since 1.0.0
   * @api
   */
  public $logQueries = false;

  /**
   * The options to be set.
   *
   * @var array
   */
  public $options = [MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true];

  /**
   * The SQL mode of the MySQL instance.
   *
   * @var string
   *
   * @since 1.0.0
   * @api
   */
  public $sqlMode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY';

  /**
   * The transaction isolation level. Possible values are:
   * <ul>
   * <li> REPEATABLE-READ
   * <li> READ-COMMITTED
   * <li> READ-UNCOMMITTED
   * <li> SERIALIZABLE
   * </ul>
   *
   * @var string
   *
   * @since 1.0.0
   * @api
   */
  public $transactionIsolationLevel = 'READ-COMMITTED';

  /**
   * Chunk size when transmitting LOB to the MySQL instance. Must be less than max_allowed_packet.
   *
   * @var int
   */
  protected $chunkSize;

  /**
   * True if method mysqli_result::fetch_all exists (i.e. we are using MySQL native driver).
   *
   * @var bool
   */
  protected $haveFetchAll;

  /**
   * Value of variable max_allowed_packet
   *
   * @var int
   */
  protected $maxAllowedPacket;

  /**
   * The connection between PHP and the MySQL instance.
   *
   * @var \mysqli
   */
  protected $mysqli;

  /**
   * The query log.
   *
   * @var array[]
   */
  protected $queryLog = [];

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Starts a transaction.
   *
   * Wrapper around [mysqli::autocommit](http://php.net/manual/mysqli.autocommit.php), however on failure an exception
   * is thrown.
   *
   * @since 1.0.0
   * @api
   */
  public function begin(): void
  {
    $ret = $this->mysqli->autocommit(false);
    if (!$ret) $this->mySqlError('mysqli::autocommit');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * @param \mysqli_stmt $stmt
   * @param array        $out
   */
  public function bindAssoc(\mysqli_stmt $stmt, array &$out): void
  {
    $data = $stmt->result_metadata();
    if (!$data) $this->mySqlError('mysqli_stmt::result_metadata');

    $fields = [];
    $out    = [];

    while (($field = $data->fetch_field()))
    {
      $fields[] = &$out[$field->name];
    }

    $b = call_user_func_array([$stmt, 'bind_result'], $fields);
    if ($b===false) $this->mySqlError('mysqli_stmt::bind_result');

    $data->free();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Commits the current transaction (and starts a new transaction).
   *
   * Wrapper around [mysqli::commit](http://php.net/manual/mysqli.commit.php), however on failure an exception is
   * thrown.
   *
   * @since 1.0.0
   * @api
   */
  public function commit(): void
  {
    $ret = $this->mysqli->commit();
    if (!$ret) $this->mySqlError('mysqli::commit');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Connects to a MySQL instance.
   *
   * Wrapper around [mysqli::__construct](http://php.net/manual/mysqli.construct.php), however on failure an exception
   * is thrown.
   *
   * @param string $host     The hostname.
   * @param string $user     The MySQL user name.
   * @param string $password The password.
   * @param string $database The default database.
   * @param int    $port     The port number.
   *
   * @since 1.0.0
   * @api
   */
  public function connect(string $host, string $user, string $password, string $database, int $port = 3306): void
  {
    $this->mysqli = new \mysqli($host, $user, $password, $database, $port);
    if ($this->mysqli->connect_errno)
    {
      $message = 'MySQL Error no: '.$this->mysqli->connect_errno."\n";
      $message .= str_replace('%', '%%', $this->mysqli->connect_error);
      $message .= "\n";

      throw new RuntimeException($message);
    }

    // Set the options.
    foreach ($this->options as $option => $value)
    {
      $this->mysqli->options($option, $value);
    }

    // Set the default character set.
    $ret = $this->mysqli->set_charset($this->charSet);
    if (!$ret) $this->mySqlError('mysqli::set_charset');

    // Set the SQL mode.
    $this->executeNone("set sql_mode = '".$this->sqlMode."'");

    // Set transaction isolation level.
    $this->executeNone("set session tx_isolation = '".$this->transactionIsolationLevel."'");

    // Set flag to use method mysqli_result::fetch_all if we are using MySQL native driver.
    $this->haveFetchAll = method_exists('mysqli_result', 'fetch_all');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Closes the connection to the MySQL instance, if connected.
   *
   * @since 1.0.0
   * @api
   */
  public function disconnect(): void
  {
    if ($this->mysqli!==null)
    {
      $this->mysqli->close();
      $this->mysqli = null;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query using a bulk handler.
   *
   * @param BulkHandler $bulkHandler The bulk handler.
   * @param string      $query       The SQL statement.
   *
   * @since 1.0.0
   * @api
   */
  public function executeBulk(BulkHandler $bulkHandler, string $query): void
  {
    $this->realQuery($query);

    $bulkHandler->start();

    $result = $this->mysqli->use_result();
    while (($row = $result->fetch_assoc()))
    {
      $bulkHandler->row($row);
    }
    $result->free();

    $bulkHandler->stop();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query and logs the result set.
   *
   * @param string $queries The query or multi query.
   *
   * @return int The total number of rows selected/logged.
   *
   * @since 1.0.0
   * @api
   */
  public function executeLog(string $queries): int
  {
    // Counter for the number of rows written/logged.
    $n = 0;

    $this->multiQuery($queries);
    do
    {
      $result = $this->mysqli->store_result();
      if ($this->mysqli->errno) $this->mySqlError('mysqli::store_result');
      if ($result)
      {
        $fields = $result->fetch_fields();
        while (($row = $result->fetch_row()))
        {
          $line = '';
          foreach ($row as $i => $field)
          {
            if ($i>0) $line .= ' ';
            $line .= str_pad((string)$field, $fields[$i]->max_length);
          }
          echo date('Y-m-d H:i:s'), ' ', $line, "\n";
          $n++;
        }
        $result->free();
      }

      $continue = $this->mysqli->more_results();
      if ($continue)
      {
        $tmp = $this->mysqli->next_result();
        if ($tmp===false) $this->mySqlError('mysqli::next_result');
      }
    } while ($continue);

    return $n;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes multiple queries and returns an array with the "result" of each query, i.e. the length of the returned
   * array equals the number of queries. For SELECT, SHOW, DESCRIBE or EXPLAIN queries the "result" is the selected
   * rows (i.e. an array of arrays), for other queries the "result" is the number of effected rows.
   *
   * @param string $queries The SQL statements.
   *
   * @return array
   *
   * @since 1.0.0
   * @api
   */
  public function executeMulti(string $queries): array
  {
    $ret = [];

    $this->multiQuery($queries);
    do
    {
      $result = $this->mysqli->store_result();
      if ($this->mysqli->errno) $this->mySqlError('mysqli::store_result');
      if ($result)
      {
        if ($this->haveFetchAll)
        {
          $ret[] = $result->fetch_all(MYSQLI_ASSOC);
        }
        else
        {
          $tmp = [];
          while (($row = $result->fetch_assoc()))
          {
            $tmp[] = $row;
          }

          $ret[] = $tmp;
        }
        $result->free();
      }
      else
      {
        $ret[] = $this->mysqli->affected_rows;
      }

      $continue = $this->mysqli->more_results();
      if ($continue)
      {
        $tmp = $this->mysqli->next_result();
        if ($tmp===false) $this->mySqlError('mysqli::next_result');
      }
    } while ($continue);

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that does not select any rows.
   *
   * @param string $query The SQL statement.
   *
   * @return int The number of affected rows (if any).
   *
   * @since 1.0.0
   * @api
   */
  public function executeNone(string $query): int
  {
    $this->realQuery($query);

    $n = $this->mysqli->affected_rows;

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    return $n;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 0 or 1 row.
   * Throws an exception if the query selects 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array|null The selected row.
   *
   * @since 1.0.0
   * @api
   */
  public function executeRow0(string $query): ?array
  {
    $result = $this->query($query);
    $row    = $result->fetch_assoc();
    $n      = $result->num_rows;
    $result->free();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    if (!($n==0 || $n==1))
    {
      throw new ResultException('0 or 1', $n, $query);
    }

    return $row;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 1 and only 1 row.
   * Throws an exception if the query selects none, 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array The selected row.
   *
   * @since 1.0.0
   * @api
   */
  public function executeRow1(string $query): array
  {
    $result = $this->query($query);
    $row    = $result->fetch_assoc();
    $n      = $result->num_rows;
    $result->free();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    if ($n!=1)
    {
      throw new ResultException('1', $n, $query);
    }

    return $row;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 0 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array[] The selected rows.
   *
   * @since 1.0.0
   * @api
   */
  public function executeRows(string $query): array
  {
    $result = $this->query($query);
    if ($this->haveFetchAll)
    {
      $ret = $result->fetch_all(MYSQLI_ASSOC);
    }
    else
    {
      $ret = [];
      while (($row = $result->fetch_assoc()))
      {
        $ret[] = $row;
      }
    }
    $result->free();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 0 or 1 row with one column.
   * Throws an exception if the query selects 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return mixed The selected value.
   *
   * @since 1.0.0
   * @api
   */
  public function executeSingleton0(string $query)
  {
    $result = $this->query($query);
    $row    = $result->fetch_array(MYSQLI_NUM);
    $n      = $result->num_rows;
    $result->free();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    if (!($n==0 || $n==1))
    {
      throw new ResultException('0 or 1', $n, $query);
    }

    return $row[0];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 1 and only 1 row with 1 column.
   * Throws an exception if the query selects none, 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return mixed The selected value.
   *
   * @since 1.0.0
   * @api
   */
  public function executeSingleton1(string $query)
  {
    $result = $this->query($query);
    $row    = $result->fetch_array(MYSQLI_NUM);
    $n      = $result->num_rows;
    $result->free();

    if ($this->mysqli->more_results()) $this->mysqli->next_result();

    if ($n!=1)
    {
      throw new ResultException('1', $n, $query);
    }

    return $row[0];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query and shows the data in a formatted in a table (like mysql's default pager) of in multiple tables
   * (in case of a multi query).
   *
   * @param string $query The query.
   *
   * @return int The total number of rows in the tables.
   *
   * @since 1.0.0
   * @api
   */
  public function executeTable(string $query): int
  {
    $row_count = 0;

    $this->multiQuery($query);
    do
    {
      $result = $this->mysqli->store_result();
      if ($this->mysqli->errno) $this->mySqlError('mysqli::store_result');
      if ($result)
      {
        $columns = [];

        // Get metadata to array.
        foreach ($result->fetch_fields() as $str_num => $column)
        {
          $columns[$str_num]['header'] = $column->name;
          $columns[$str_num]['type']   = $column->type;
          $columns[$str_num]['length'] = max(4, $column->max_length, mb_strlen($column->name));
        }

        // Show the table header.
        $this->executeTableShowHeader($columns);

        // Show for all rows all columns.
        while (($row = $result->fetch_row()))
        {
          $row_count++;

          // First row separator.
          echo '|';

          foreach ($row as $i => $value)
          {
            $this->executeTableShowTableColumn($columns[$i], $value);
            echo '|';
          }

          echo "\n";
        }

        // Show the table footer.
        $this->executeTableShowFooter($columns);
      }

      $continue = $this->mysqli->more_results();
      if ($continue)
      {
        $tmp = $this->mysqli->next_result();
        if ($tmp===false) $this->mySqlError('mysqli::next_result');
      }
    } while ($continue);

    return $row_count;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the value of the MySQL variable max_allowed_packet.
   *
   * @return int
   */
  public function getMaxAllowedPacket(): int
  {
    if (!isset($this->maxAllowedPacket))
    {
      $query              = "show variables like 'max_allowed_packet'";
      $max_allowed_packet = $this->executeRow1($query);

      $this->maxAllowedPacket = $max_allowed_packet['Value'];

      // Note: When setting $chunkSize equal to $maxAllowedPacket it is not possible to transmit a LOB
      // with size $maxAllowedPacket bytes (but only $maxAllowedPacket - 8 bytes). But when setting the size of
      // $chunkSize less than $maxAllowedPacket than it is possible to transmit a LOB with size
      // $maxAllowedPacket bytes.
      $this->chunkSize = (int)min($this->maxAllowedPacket - 8, 1024 * 1024);
    }

    return (int)$this->maxAllowedPacket;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the query log.
   *
   * To enable the query log set {@link $queryLog} to true.
   *
   * @return array[]
   *
   * @since 1.0.0
   * @api
   */
  public function getQueryLog(): array
  {
    return $this->queryLog;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the first row in a row set for which a column has a specific value.
   *
   * Throws an exception if now row is found.
   *
   * @param string  $columnName The column name (or in PHP terms the key in an row (i.e. array) in the row set).
   * @param mixed   $value      The value to be found.
   * @param array[] $rowSet     The row set.
   *
   * @return array
   *
   * @since 1.0.0
   * @api
   */
  public function getRowInRowSet(string $columnName, $value, array $rowSet): array
  {
    if (is_array($rowSet))
    {
      foreach ($rowSet as $row)
      {
        if ((string)$row[$columnName]==(string)$value)
        {
          return $row;
        }
      }
    }

    throw new RuntimeException("Value '%s' for column '%s' not found in row set.", $value, $columnName);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a hexadecimal literal for a binary value that can be safely used in SQL statements.
   *
   * @param string|null $value The binary value.
   *
   * @return string
   */
  public function quoteBinary(?string $value): string
  {
    if ($value===null || $value==='') return 'null';

    return '0x'.bin2hex($value);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a bit value that can be safely used in SQL statements.
   *
   * @param string|null $bits The bit value.
   *
   * @return string
   */
  public function quoteBit(?string $bits): string
  {
    if ($bits===null || $bits==='')
    {
      return 'null';
    }

    return "b'".$this->mysqli->real_escape_string($bits)."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a decimal value that can be safely used in SQL statements.
   *
   * @param float|int|string|null $value The value.
   *
   * @return string
   */
  public function quoteDecimal($value): string
  {
    if ($value===null || $value==='') return 'null';

    if (is_int($value) || is_float($value)) return (string)$value;

    return "'".$this->mysqli->real_escape_string($value)."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a float value that can be safely used in SQL statements.
   *
   * @param float|null $value The float value.
   *
   * @return string
   */
  public function quoteFloat(?float $value): string
  {
    if ($value===null) return 'null';

    return (string)$value;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for an integer value that can be safely used in SQL statements.
   *
   * @param int|null $value The integer value.
   *
   * @return string
   */
  public function quoteInt(?int $value): string
  {
    if ($value===null) return 'null';

    return (string)$value;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for an expression with a separated list of integers that can be safely used in SQL
   * statements. Throws an exception if the value is a list of integers.
   *
   * @param string|array $list      The list of integers.
   * @param string       $delimiter The field delimiter (one character only).
   * @param string       $enclosure The field enclosure character (one character only).
   * @param string       $escape    The escape character (one character only)
   *
   * @return string
   */
  public function quoteListOfInt($list, string $delimiter, string $enclosure, string $escape): string
  {
    if ($list===null || $list===false || $list==='' || $list===[])
    {
      return 'null';
    }

    $ret = '';
    if (is_scalar($list))
    {
      $list = str_getcsv($list, $delimiter, $enclosure, $escape);
    }
    elseif (is_array($list))
    {
      // Nothing to do.
      ;
    }
    else
    {
      throw new RuntimeException("Unexpected parameter type '%s'. Array or scalar expected.", gettype($list));
    }

    foreach ($list as $number)
    {
      if ($list===null || $list===false || $list==='')
      {
        throw new RuntimeException('Empty values are not allowed.');
      }
      if (!is_numeric($number))
      {
        throw new RuntimeException("Value '%s' is not a number.", (is_scalar($number)) ? $number : gettype($number));
      }

      if ($ret) $ret .= ',';
      $ret .= $number;
    }

    return $this->quoteString($ret);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a string value that can be safely used in SQL statements.
   *
   * @param string|null $value The value.
   *
   * @return string
   */
  public function quoteString(?string $value): string
  {
    if ($value===null || $value==='') return 'null';

    return "'".$this->mysqli->real_escape_string($value)."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Escapes special characters in a string such that it can be safely used in SQL statements.
   *
   * Wrapper around [mysqli::real_escape_string](http://php.net/manual/mysqli.real-escape-string.php).
   *
   * @param string $string The string.
   *
   * @return string
   */
  public function realEscapeString(string $string): string
  {
    return $this->mysqli->real_escape_string($string);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Rollbacks the current transaction (and starts a new transaction).
   *
   * Wrapper around [mysqli::rollback](http://php.net/manual/en/mysqli.rollback.php), however on failure an exception
   * is thrown.
   *
   * @since 1.0.0
   * @api
   */
  public function rollback(): void
  {
    $ret = $this->mysqli->rollback();
    if (!$ret) $this->mySqlError('mysqli::rollback');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the key of the first row in a row set for which a column has a specific value. Returns null if no row is
   * found.
   *
   * @param string  $columnName The column name (or in PHP terms the key in an row (i.e. array) in the row set).
   * @param mixed   $value      The value to be found.
   * @param array[] $rowSet     The row set.
   *
   * @return int|string|null
   *
   * @deprecated
   */
  public function searchInRowSet(string $columnName, $value, array $rowSet)
  {
    if (is_array($rowSet))
    {
      foreach ($rowSet as $key => $row)
      {
        if ((string)$row[$columnName]===(string)$value)
        {
          return $key;
        }
      }
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the warnings of the last executed SQL statement.
   *
   * Wrapper around the SQL statement [show warnings](https://dev.mysql.com/doc/refman/5.6/en/show-warnings.html).
   *
   * @since 1.0.0
   * @api
   */
  public function showWarnings(): void
  {
    $this->executeLog('show warnings');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes multiple SQL statements.
   *
   * Wrapper around [multi_mysqli::query](http://php.net/manual/mysqli.multi-query.php), however on failure an exception
   * is thrown.
   *
   * @param string $queries The SQL statements.
   *
   * @return void
   */
  protected function multiQuery(string $queries): void
  {
    if ($this->logQueries)
    {
      $time0 = microtime(true);

      $tmp = $this->mysqli->multi_query($queries);
      if ($tmp===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $queries);
      }

      $this->queryLog[] = ['query' => $queries, 'time' => microtime(true) - $time0];
    }
    else
    {
      $tmp = $this->mysqli->multi_query($queries);
      if ($tmp===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $queries);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Throws an exception with error information provided by MySQL/[mysqli](http://php.net/manual/en/class.mysqli.php).
   *
   * This method must called after a method of [mysqli](http://php.net/manual/en/class.mysqli.php) returns an
   * error only.
   *
   * @param string $method The name of the method that has failed.
   */
  protected function mySqlError(string $method): void
  {
    $message = 'MySQL Error no: '.$this->mysqli->errno."\n";
    $message .= $this->mysqli->error;
    $message .= "\n";
    $message .= $method;
    $message .= "\n";

    throw new RuntimeException('%s', $message);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query (i.e. SELECT, SHOW, DESCRIBE or EXPLAIN) with a result set.
   *
   * Wrapper around [mysqli::query](http://php.net/manual/mysqli.query.php), however on failure an exception is thrown.
   *
   * For other SQL statements, see @realQuery.
   *
   * @param string $query The SQL statement.
   *
   * @return \mysqli_result
   */
  protected function query(string $query): \mysqli_result
  {
    if ($this->logQueries)
    {
      $time0 = microtime(true);

      $ret = $this->mysqli->query($query);
      if ($ret===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $query);
      }

      $this->queryLog[] = ['query' => $query, 'time' => microtime(true) - $time0];
    }
    else
    {
      $ret = $this->mysqli->query($query);
      if ($ret===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $query);
      }
    }

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Execute a query without a result set.
   *
   * Wrapper around [mysqli::real_query](http://php.net/manual/en/mysqli.real-query.php), however on failure an
   * exception is thrown.
   *
   * For SELECT, SHOW, DESCRIBE or EXPLAIN queries, see @query.
   *
   * @param string $query The SQL statement.
   */
  protected function realQuery(string $query): void
  {
    if ($this->logQueries)
    {
      $time0 = microtime(true);

      $tmp = $this->mysqli->real_query($query);
      if ($tmp===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $query);
      }

      $this->queryLog[] = ['query' => $query,
                           'time'  => microtime(true) - $time0];
    }
    else
    {
      $tmp = $this->mysqli->real_query($query);
      if ($tmp===false)
      {
        throw new DataLayerException($this->mysqli->errno, $this->mysqli->error, $query);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Send data in blocks to the MySQL server.
   *
   * Wrapper around [mysqli_stmt::send_long_data](http://php.net/manual/mysqli-stmt.send-long-data.php).
   *
   * @param mysqli_stmt $statement The prepared statement.
   * @param int         $paramNr   The 0-indexed parameter number.
   * @param string|null $data      The data.
   */
  protected function sendLongData(mysqli_stmt $statement, int $paramNr, ?string $data): void
  {
    if ($data!==null)
    {
      $n = strlen($data);
      $p = 0;
      while ($p<$n)
      {
        $b = $statement->send_long_data($paramNr, substr($data, $p, $this->chunkSize));
        if (!$b) $this->mySqlError('mysqli_stmt::send_long_data');
        $p += $this->chunkSize;
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Helper method for method executeTable. Shows table footer.
   *
   * @param array $columns
   */
  private function executeTableShowFooter(array $columns): void
  {
    $separator = '+';

    foreach ($columns as $column)
    {
      $separator .= str_repeat('-', $column['length'] + 2).'+';
    }
    echo $separator, "\n";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Helper method for method executeTable. Shows table header.
   *
   * @param array $columns
   */
  private function executeTableShowHeader(array $columns): void
  {
    $separator = '+';
    $header    = '|';

    foreach ($columns as $column)
    {
      $separator .= str_repeat('-', $column['length'] + 2).'+';
      $spaces    = ($column['length'] + 2) - mb_strlen((string)$column['header']);

      $spacesLeft  = (int)floor($spaces / 2);
      $spacesRight = (int)ceil($spaces / 2);

      $fillerLeft  = ($spacesLeft>0) ? str_repeat(' ', $spacesLeft) : '';
      $fillerRight = ($spacesRight>0) ? str_repeat(' ', $spacesRight) : '';

      $header .= $fillerLeft.$column['header'].$fillerRight.'|';
    }

    echo "\n", $separator, "\n";
    echo $header, "\n";
    echo $separator, "\n";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Helper method for method executeTable. Shows table cell with data.
   *
   * @param array $column The metadata of the column.
   * @param mixed $value  The value of the table cell.
   */
  private function executeTableShowTableColumn(array $column, $value): void
  {
    $spaces = str_repeat(' ', $column['length'] - mb_strlen((string)$value));

    switch ($column['type'])
    {
      case 1: // tinyint
      case 2: // smallint
      case 3: // int
      case 4: // float
      case 5: // double
      case 8: // bigint
      case 9: // mediumint
      case 246: // decimal
        echo ' ', $spaces.$value, ' ';
        break;

      case 7: // timestamp
      case 10: // date
      case 11: // time
      case 12: // datetime
      case 13: // year
      case 16: // bit
      case 252: // is currently mapped to all text and blob types (MySQL 5.0.51a)
      case 253: // varchar
      case 254: // char
        echo ' ', $value.$spaces, ' ';
        break;

      default:
        throw new FallenException('data type id', $column['type']);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
