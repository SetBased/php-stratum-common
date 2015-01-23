<?php
//----------------------------------------------------------------------------------------------------------------------
/**
 * phpStratum
 *
 * @copyright 2005-2015 Paul Water / Set Based IT Consultancy (https://www.setbased.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link
 */
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Stratum\Generator;

use phpDocumentor\Reflection\DocBlock;
use SetBased\Stratum\StaticDataLayer as DataLayer;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for loading a single stored routine into a MySQL instance from pseudo SQL file.
 */
class MySqlRoutineLoaderHelper
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The default character set under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCharacterSet;

  /**
   * The default collate under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCollate;

  /**
   * The key or index columns (depending on the designation type) of the stored routine .
   *
   * @var string
   */
  private $myColumns;

  /**
   * The column types of columns of the table for bulk insert of the stored routine.
   *
   * @var string
   */
  private $myColumnsTypes;

  /**
   * The designation type of the stored routine.
   *
   * @var string
   */
  private $myDesignationType;

  /**
   * The keys in the PHP array for bulk insert.
   *
   * @var string
   */
  private $myFields;

  /**
   * The last modification time of the source file.
   *
   * @var int
   */
  private $myMTime;

  /**
   * The metadata of the stored routine.
   *
   * @var array
   */
  private $myMetadata;

  /**
   * The old metadata of the stored routine.
   *
   * @var array
   */
  private $myOldMetadata;

  /**
   * The old information about the stored routine.
   *
   * @var array
   */
  private $myOldRoutineInfo;

  /**
   * The placeholders in the source file.
   *
   * @var array
   */
  private $myPlaceholders;

  /**
   * The replace pairs (i.e. placeholders and their actual values, see strst).
   *
   * @var array
   */
  private $myReplace = array();

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $myReplacePairs = array();

  /**
   * The name of the stored routine.
   *
   * @var string
   */
  private $myRoutineName;

  /**
   * The database types of the parameters of the stored routine.
   *
   * @var array
   */
  private $myRoutineParameterColumnTypes = array();

  /**
   * The names of parameters of the stored routine.
   *
   * @var array
   */
  private $myRoutineParameterNames = array();

  /**
   * The PHP types of the parameters of the stored routine.
   *
   * @var array
   */
  private $myRoutineParameterTypes = array();

  /**
   * The tags for the PhpDoc block for the wrapper of this stored routine.
   *
   * @var array
   */
  private $myRoutinePhpDoc = array();

  /**
   * The source code as a single string of the stored routine.
   *
   * @var string
   */
  private $myRoutineSourceCode;

  /**
   * The source code as an array of lines string of the stored routine.
   *
   * @var array
   */
  private $myRoutineSourceCodeLines;

  /**
   * The stored routine type (i.e. procedure or function) of the stored routine.
   *
   * @var string
   */
  private $myRoutineType;

  /**
   * The source filename holding the stored routine.
   *
   * @var string
   */
  private $mySourceFilename;

  /**
   * The SQL mode under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $mySqlMode;

  /**
   * If designation type is bulk_insert the table name for bulk insert.
   *
   * @var string
   */
  private $myTableName;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads a single stored routine into MySQL.
   *
   * @param string $theRoutineFilename      The filename of the source of the stored routine.
   * @param string $theRoutineFileExtension The extension of the source file of the stored routine.
   * @param array  $theMetadata             The metadata of the stored routine.
   * @param array  $theReplacePairs         A map from placeholders to their actual values.
   * @param array  $theOldRoutineInfo       The old information about the stored routine.
   * @param string $theSqlMode              The SQL mode under which the stored routine will be loaded and run.
   * @param string $theCharacterSet         The default character set under which the stored routine will be loaded and
   *                                        run.
   * @param string $theCollate              The key or index columns (depending on the designation type) of the stored
   *                                        routine.
   *
   * @return \SetBased\Stratum\Generator\MySqlRoutineLoaderHelper
   */
  public function __construct( $theRoutineFilename,
                               $theRoutineFileExtension,
                               $theMetadata,
                               $theReplacePairs,
                               $theOldRoutineInfo,
                               $theSqlMode,
                               $theCharacterSet,
                               $theCollate
  )
  {
    $this->mySourceFilename      = $theRoutineFilename;
    $this->mySourceFileExtension = $theRoutineFileExtension;
    $this->myMetadata            = $theMetadata;
    $this->myReplacePairs        = $theReplacePairs;
    $this->myOldRoutineInfo      = $theOldRoutineInfo;
    $this->mySqlMode             = $theSqlMode;
    $this->myCharacterSet        = $theCharacterSet;
    $this->myCollate             = $theCollate;
  }

//--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the instance of MySQL.
   *
   * @return array|bool If the stored routine is loaded successfully the new mata data of the stored routine. Otherwise
   *                    false.
   */
  public function loadStoredRoutine()
  {
    try
    {
      // We assume that the basename of the routine file and routine name are equal.
      $this->myRoutineName = basename( $this->mySourceFilename, $this->mySourceFileExtension );

      // Save old metadata.
      $this->myOldMetadata = $this->myMetadata;

      // Get modification time of the source file.
      $this->myMTime = filemtime( $this->mySourceFilename );
      if ($this->myMTime===false) set_assert_failed( "Unable to get mtime of file '%s'.",
                                                     $this->mySourceFilename );

      // Load the stored routine into MySQL only if the source has changed or the value of a placeholder.
      $load = $this->getMustReload();
      if ($load)
      {
        // Read the stored routine source code.
        $this->myRoutineSourceCode = file_get_contents( $this->mySourceFilename );
        if ($this->myRoutineSourceCode===false)
        {
          set_assert_failed( "Unable to read file '%s'.", $this->mySourceFilename );
        }

        // Split the stored routine source code into lines.
        $this->myRoutineSourceCodeLines = explode( "\n", $this->myRoutineSourceCode );
        if ($this->myRoutineSourceCodeLines===false) return false;

        // Extract placeholders from the stored routine source code.
        $ok = $this->getPlaceholders( $this->myRoutineSourceCode, $this->mySourceFilename );
        if ($ok===false) return false;

        // Extract the designation type and key or index columns from the stored routine source code.
        $ok = $this->getDesignationType();
        if ($ok===false) return false;

        // Extract the stored routine type (procedure or function) and stored routine name from the source code.
        $ok = $this->getName();
        if ($ok===false) return false;

        // Load the stored routine into MySQL.
        $this->loadRoutineFile();

        // If the stored routine is a bulk insert stored procedure, enhance metadata with table columns information.
        if ($this->myDesignationType=='bulk_insert')
        {
          $this->getBulkInsertTableColumnsInfo();
        }

        // Get the parameters types of the stored routine from metadata of MySQL.
        $this->getRoutineParametersInfo();

        // Get the stored routine documentation from the source file.
        $this->getPhpDocBlock();

        // Update Metadata of the stored routine.
        $this->updateMetadata();
      }

      return $this->myMetadata;
    }
    catch (\Exception $e)
    {
      echo $e->getMessage(), "\n";

      return false;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Converts MySQL data type to the PHP data type.
   *
   * @param string $theType
   *
   * @return string
   * @throws \Exception
   */
  private function columnTypeToPhpType( $theType )
  {
    $php_type = '';

    switch ($theType)
    {
      case 'tinyint':
      case 'smallint':
      case 'mediumint':
      case 'int':
      case 'bigint':

      case 'year':

      case 'bit':
        $php_type = 'int';
        break;

      case 'decimal':
      case 'float':
      case 'double':
        $php_type = 'float';
        break;

      case 'varbinary':
      case 'binary':

      case 'char':
      case 'varchar':

      case 'time':
      case 'timestamp':

      case 'date':
      case 'datetime':

      case 'enum':
      case 'set':

      case 'tinytext':
      case 'text':
      case 'mediumtext':
      case 'longtext':

      case 'tinyblob':
      case 'blob':
      case 'mediumblob':
      case 'longblob':
        $php_type = 'string';
        break;

      default:
        set_assert_failed( "Unknown MySQL type '%s'.", $theType );
    }

    return $php_type;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops the stored routine if it exists.
   */
  private function dropRoutine()
  {
    if (isset($this->myOldRoutineInfo))
    {
      $sql = sprintf( "drop %s if exists %s",
                      $this->myOldRoutineInfo['routine_type'],
                      $this->myRoutineName );

      DataLayer::executeNone( $sql );
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Gets the column names and column types of the current table for bulk insert.
   */
  private function getBulkInsertTableColumnsInfo()
  {
    // Check if table is a temporary table or a non-temporary table.
    $query                  = sprintf( '
select 1
from   information_schema.TABLES
where table_schema = database()
and   table_name   = %s', DataLayer::quoteString( $this->myTableName ) );
    $table_is_non_temporary = DataLayer::executeRow0( $query );

    // Create temporary table if table is non-temporary table.
    if (!$table_is_non_temporary)
    {
      $query = 'call '.$this->myRoutineName.'()';
      DataLayer::executeNone( $query );
    }

    // Get information about the columns of the table.
    $query   = sprintf( "describe `%s`", $this->myTableName );
    $columns = DataLayer::executeRows( $query );

    // Drop temporary table if table is non-temporary.
    if (!$table_is_non_temporary)
    {
      $query = sprintf( "drop temporary table `%s`", $this->myTableName );
      DataLayer::executeNone( $query );
    }

    // Check number of columns in the table match the number of fields given in the designation type.
    $n1 = count( $this->myColumns );
    $n2 = count( $columns );
    if ($n1!=$n2) set_assert_failed( "Number of fields %d and number of columns %d don't match.", $n1, $n2 );

    // Fill arrays with column names and column types.
    $tmp_column_types = array();
    $tmp_fields       = array();
    foreach ($columns as $column)
    {
      preg_match( "(\\w+)", $column['Type'], $type );
      $tmp_column_types[] = $type['0'];
      $tmp_fields[]       = $column['Field'];
    }

    $this->myColumnsTypes = $tmp_column_types;
    $this->myFields       = $tmp_fields;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the designation type of the stored routine.
   *
   * @return bool True on success. Otherwise returns false.
   */
  private function getDesignationType()
  {
    $ret = true;
    $key = array_search( 'begin', $this->myRoutineSourceCodeLines );

    if ($key!==false)
    {
      $n = preg_match( '/^\s*--\s+type:\s*(\w+)\s*(.+)?\s*$/', $this->myRoutineSourceCodeLines[$key - 1],
                       $matches );

      if ($n===false) set_assert_failed( "Internal error." );

      if ($n==1)
      {
        $this->myDesignationType = $matches[1];
        switch ($this->myDesignationType)
        {
          case 'bulk_insert':
            $m = preg_match( '/^([a-zA-Z0-9_]+)\s+([a-zA-Z0-9_,]+)$/', $matches[2], $info );
            if ($m===false) set_assert_failed( "Internal error." );
            if ($m==0) set_assert_failed( sprintf( "Error: Expected: -- type: bulk_insert <table_name> <columns> in file '%s'.\n",
                                                   $this->mySourceFilename ) );
            $this->myTableName = $info[1];
            $this->myColumns   = explode( ',', $info[2] );
            break;

          case 'rows_with_key':
          case 'rows_with_index':
            $this->myColumns = explode( ',', $matches[2] );
            break;

          default:
            if (isset($matches[2])) $ret = false;
        }
      }
      else
      {
        $ret = false;
      }
    }
    else
    {
      $ret = false;
    }

    if ($ret===false)
    {
      echo sprintf( "Error: Unable to find the designation type of the stored routine in file '%s'.\n",
                    $this->mySourceFilename );
    }

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns true if the source file must be load or reloaded. Otherwise returns false.
   *
   * @return bool
   */
  private function getMustReload()
  {
    // If this is the first time we see the source file it must be loaded.
    if (!isset($this->myOldMetadata)) return true;

    // If the source file has changed the source file must be loaded.
    if ($this->myOldMetadata['timestamp']!=$this->myMTime) return true;

    // If the value of a placeholder has changed the source file must be loaded.
    foreach ($this->myOldMetadata['replace'] as $place_holder => $old_value)
    {
      if (!isset($this->myReplacePairs[strtoupper( $place_holder )]) ||
        $this->myReplacePairs[strtoupper( $place_holder )]!==$old_value
      )
      {
        return true;
      }
    }

    // If stored routine not exists in database the source file must be loaded.
    if (!isset($this->myOldRoutineInfo)) return true;

    // If current sql-mode is different the source file must reload.
    if ($this->myOldRoutineInfo['sql_mode']!=$this->mySqlMode) return true;

    // If current character is different the source file must reload.
    if ($this->myOldRoutineInfo['character_set_client']!=$this->myCharacterSet) return true;

    // If current collation is different the source file must reload.
    if ($this->myOldRoutineInfo['collation_connection']!=$this->myCollate) return true;

    return false;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the name of the stored routine and the stored routine type (i.e. procedure or function) source.
   *
   * @todo Skip comments and string literals.
   * @return bool Returns true on success, false otherwise.
   */
  private function getName()
  {
    $ret = true;

    $n = preg_match( "/create\\s+(procedure|function)\\s+([a-zA-Z0-9_]+)/i", $this->myRoutineSourceCode, $matches );
    if ($n===false) set_assert_failed( "Internal error." );

    if ($n==1)
    {
      $this->myRoutineType = strtolower( $matches[1] );

      if ($this->myRoutineName!=$matches[2])
      {
        echo sprintf( "Error: Stored routine name '%s' does not match filename in file '%s'.\n",
                      $matches[2],
                      $this->mySourceFilename );
        $ret = false;
      }
    }
    else
    {
      $ret = false;
    }

    if (!isset($this->myRoutineType))
    {
      echo sprintf( "Error: Unable to find the stored routine name and type in file '%s'.\n",
                    $this->mySourceFilename );
    }

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Gets the phpDoc block (in parts) of stored routine from the source file.
   */
  private function getPhpDocBlock()
  {
    // Gets phpDoc block for the source.
    $tmp = '';
    foreach ($this->myRoutineSourceCodeLines as $line)
    {
      $n = preg_match( "/create\\s+(procedure|function)\\s+([a-zA-Z0-9_]+)/i", $line );
      if ($n) break;
      else $tmp .= $line."\n";
    }

    $phpdoc = new DocBlock( $tmp );

    // Gets short description.
    $this->myRoutinePhpDoc['sort_description'] = $phpdoc->getShortDescription();

    // Gets long description.
    $this->myRoutinePhpDoc['long_description'] = $phpdoc->getLongDescription()->getContents();

    // Gets description for each parameter of the stored routine.
    foreach ($phpdoc->getTags() as $tag)
    {
      if ($tag->getName()=='param')
      {
        $content     = $tag->getContent();
        $description = $tag->getDescription();
        $name        = trim( substr( $content, 0, strlen( $content ) - strlen( $description ) ) );

        $key = array_search( $name, $this->myRoutineParameterNames );
        if ($key!==false)
        {
          $this->myRoutinePhpDoc['parameters'][] = array('name'        => $name,
                                                         'php_type'    => $this->columnTypeToPhpType( $this->myRoutineParameterTypes[$key] ),
                                                         'mysql_type' => $this->myRoutineParameterColumnTypes[$key],
                                                         'description' => $description);
        }
        else
        {
          $this->myRoutinePhpDoc['parameters'][] = array('name'        => $name,
                                                         'php_type'    => '',
                                                         'mysql_type' => '',
                                                         'description' => $description);
        }
      }
    }

    // Compare parameters.
    $this->validateParameterLists();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the placeholders from the stored routine source and stored them.
   *
   * @return bool True if all placeholders are defined, false otherwise.
   */
  private function getPlaceholders()
  {
    $err = preg_match_all( '(@[A-Za-z0-9\_\.]+(\%type)?@)', $this->myRoutineSourceCode, $matches );
    if ($err===false) set_assert_failed( "Internal error." );

    $ret                  = true;
    $this->myPlaceholders = array();

    if (!empty($matches[0]))
    {
      foreach ($matches[0] as $placeholder)
      {
        if (!isset($this->myReplacePairs[strtoupper( $placeholder )]))
        {
          echo sprintf( "Error: Unknown placeholder '%s' in file '%s'.\n", $placeholder, $this->mySourceFilename );
          $ret = false;
        }

        if (!isset($this->myPlaceholders[$placeholder]))
        {
          $this->myPlaceholders[$placeholder] = $placeholder;
        }
      }
    }

    if ($ret===true)
    {
      foreach ($this->myPlaceholders as $placeholder)
      {
        $this->myReplace[$placeholder] = $this->myReplacePairs[strtoupper( $placeholder )];
      }
      $ok = ksort( $this->myReplace );
      if ($ok===false) set_assert_failed( "Internal error." );
    }

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the parameters of the stored routine.
   */
  private function getRoutineParametersInfo()
  {
    $query = sprintf( "
select t2.parameter_name   parameter_name
,      t2.data_type        parameter_type
,      t2.dtd_identifier   column_type
from            information_schema.ROUTINES   t1
left outer join information_schema.PARAMETERS t2  on  t2.specific_schema = t1.routine_schema and
                                                      t2.specific_name   = t1.routine_name and
                                                      t2.parameter_mode   is not null
where t1.routine_schema = database()
and   t1.routine_name   = '%s'", $this->myRoutineName );

    $routine_parameters = DataLayer::executeRows( $query );

    foreach ($routine_parameters as $routine_parameter)
    {
      if ($routine_parameter['parameter_name'])
      {
        $this->myRoutineParameterNames[]       = $routine_parameter['parameter_name'];
        $this->myRoutineParameterTypes[]       = $routine_parameter['parameter_type'];
        $this->myRoutineParameterColumnTypes[] = $routine_parameter['column_type'];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the database.
   */
  private function loadRoutineFile()
  {
    echo sprintf( "Loading %s %s\n",
                  $this->myRoutineType,
                  $this->myRoutineName );

    // Set magic constants specific for this stored routine.
    $this->setMagicConstants();

    // Replace all place holders with their values.
    $lines          = explode( "\n", $this->myRoutineSourceCode );
    $routine_source = array();
    foreach ($lines as $i => &$line)
    {
      $this->myReplace['__LINE__'] = $i + 1;
      $routine_source[$i]          = strtr( $line, $this->myReplace );
    }
    $routine_source = implode( "\n", $routine_source );

    // Unset magic constants specific for this stored routine.
    $this->unsetMagicConstants();

    // Drop the stored procedure or function if its exists.
    $this->dropRoutine();

    // Set the SQL-mode under which the stored routine will run.
    $sql = sprintf( "set sql_mode ='%s'", $this->mySqlMode );
    DataLayer::executeNone( $sql );

    // Set the default character set and collate under which the store routine will run.
    $sql = sprintf( "set names '%s' collate '%s'", $this->myCharacterSet, $this->myCollate );
    DataLayer::executeNone( $sql );

    // Finally, execute the SQL code for loading the stored routine.
    DataLayer::executeNone( $routine_source );
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Add magic constants to replace list.
   */
  private function setMagicConstants()
  {
    $real_path = realpath( $this->mySourceFilename );

    $this->myReplace['__FILE__']    = "'".DataLayer::realEscapeString( $real_path )."'";
    $this->myReplace['__ROUTINE__'] = "'".$this->myRoutineName."'";
    $this->myReplace['__DIR__']     = "'".DataLayer::realEscapeString( dirname( $real_path ) )."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes magic constants from current replace list.
   */
  private function unsetMagicConstants()
  {
    unset($this->myReplace['__FILE__']);
    unset($this->myReplace['__ROUTINE__']);
    unset($this->myReplace['__DIR__']);
    unset($this->myReplace['__LINE__']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Updates the metadata for the stored routine.
   */
  private function updateMetadata()
  {
    $this->myMetadata['routine_name']    = $this->myRoutineName;
    $this->myMetadata['designation']     = $this->myDesignationType;
    $this->myMetadata['table_name']      = $this->myTableName;
    $this->myMetadata['parameter_names'] = $this->myRoutineParameterNames;
    $this->myMetadata['parameter_types'] = $this->myRoutineParameterTypes;
    $this->myMetadata['columns']         = $this->myColumns;
    $this->myMetadata['fields']          = $this->myFields;
    $this->myMetadata['column_types']    = $this->myColumnsTypes;
    $this->myMetadata['timestamp']       = $this->myMTime;
    $this->myMetadata['replace']         = $this->myReplace;
    $this->myMetadata['phpdoc']          = $this->myRoutinePhpDoc;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Compares lists of stored routine parameters and parameters from doc block.
   */
  private function validateParameterLists()
  {
    $parameters_names = array();
    if (isset($this->myRoutinePhpDoc['parameters']))
    {
      foreach ($this->myRoutinePhpDoc['parameters'] as $parameter)
      {
        $parameters_names[] = $parameter['name'];
      }
    }

    $tmp = array_diff( $this->myRoutineParameterNames, $parameters_names );
    foreach ($tmp as $name)
    {
      echo sprintf( "  Warning: parameter '%s' is missing from doc block.\n", $name );
    }

    $tmp = array_diff( $parameters_names, $this->myRoutineParameterNames );
    foreach ($tmp as $name)
    {
      echo sprintf( "  Warning: unknown parameter '%s' found in doc block.\n", $name );
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
