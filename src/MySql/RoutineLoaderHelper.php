<?php
declare(strict_types=1);

namespace SetBased\Stratum\MySql;

use SetBased\Exception\FallenException;
use SetBased\Stratum\Exception\RoutineLoaderException;
use SetBased\Stratum\MySql\Helper\DataTypeHelper;
use SetBased\Stratum\MySql\MetadataDataLayer as MetaDataLayer;
use SetBased\Stratum\Style\StratumStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Zend\Code\Reflection\DocBlock\Tag\ParamTag;
use Zend\Code\Reflection\DocBlockReflection;

/**
 * Class for loading a single stored routine into a MySQL instance from pseudo SQL file.
 */
class RoutineLoaderHelper
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The metadata of the table columns of the table for bulk insert.
   *
   * @var array[]
   */
  private $bulkInsertColumns;

  /**
   * The keys in the nested array for bulk inserting data.
   *
   * @var array[]
   */
  private $bulkInsertKeys;

  /**
   * The name of table for bulk insert.
   *
   * @var string
   */
  private $bulkInsertTableName;

  /**
   * The default character set under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $characterSet;

  /**
   * The default collate under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $collate;

  /**
   * The designation type of the stored routine.
   *
   * @var string
   */
  private $designationType;

  /**
   * All DocBlock parts as found in the source of the stored routine.
   *
   * @var array
   */
  private $docBlockPartsSource = [];

  /**
   * The DocBlock parts to be used by the wrapper generator.
   *
   * @var array
   */
  private $docBlockPartsWrapper;

  /**
   * Information about parameters with specific format (string in CSV format etc.) pass to the stored routine.
   *
   * @var array
   */
  private $extendedParameters;

  /**
   * The last modification time of the source file.
   *
   * @var int
   */
  private $filemtime;

  /**
   * The key or index columns (depending on the designation type) of the stored routine.
   *
   * @var string[]
   */
  private $indexColumns;

  /**
   * The Output decorator
   *
   * @var StratumStyle
   */
  private $io;

  /**
   * The information about the parameters of the stored routine.
   *
   * @var array[]
   */
  private $parameters = [];

  /**
   * The metadata of the stored routine. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array|null
   */
  private $phpStratumMetadata;

  /**
   * The old metadata of the stored routine.  Note: this data comes from the metadata file.
   *
   * @var array
   */
  private $phpStratumOldMetadata;

  /**
   * The old metadata of the stored routine. Note: this data comes from information_schema.ROUTINES.
   *
   * @var array|null
   */
  private $rdbmsOldRoutineMetadata;

  /**
   * The replace pairs (i.e. placeholders and their actual values, see strst).
   *
   * @var array
   */
  private $replace = [];

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $replacePairs = [];

  /**
   * The return type of the stored routine (only if designation type singleton0, singleton1, or function).
   *
   * @var string|null
   */
  private $returnType;

  /**
   * The name of the stored routine.
   *
   * @var string
   */
  private $routineName;

  /**
   * The source code as a single string of the stored routine.
   *
   * @var string
   */
  private $routineSourceCode;

  /**
   * The source code as an array of lines string of the stored routine.
   *
   * @var array
   */
  private $routineSourceCodeLines;

  /**
   * The stored routine type (i.e. procedure or function) of the stored routine.
   *
   * @var string
   */
  private $routineType;

  /**
   * The source filename holding the stored routine.
   *
   * @var string
   */
  private $sourceFilename;

  /**
   * The SQL mode under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $sqlMode;

  //--------------------------------------------------------------------------------------------------------------------

  /**
   * Object constructor.
   *
   * @param StratumStyle $io                            The output for log messages.
   * @param string       $routineFilename               The filename of the source of the stored routine.
   * @param array        $phpStratumMetadata            The metadata of the stored routine from PhpStratum.
   * @param array        $replacePairs                  A map from placeholders to their actual values.
   * @param array        $rdbmsOldRoutineMetadata       The old metadata of the stored routine from MySQL.
   * @param string       $sqlMode                       The SQL mode under which the stored routine will be loaded and
   *                                                    run.
   * @param string       $characterSet                  The default character set under which the stored routine will
   *                                                    be loaded and run.
   * @param string       $collate                       The key or index columns (depending on the designation type) of
   *                                                    the stored routine.
   */
  public function __construct(StratumStyle $io,
                              string $routineFilename,
                              ?array $phpStratumMetadata,
                              array $replacePairs,
                              ?array $rdbmsOldRoutineMetadata,
                              string $sqlMode,
                              string $characterSet,
                              string $collate)
  {
    $this->io                      = $io;
    $this->sourceFilename          = $routineFilename;
    $this->phpStratumMetadata      = $phpStratumMetadata;
    $this->replacePairs            = $replacePairs;
    $this->rdbmsOldRoutineMetadata = $rdbmsOldRoutineMetadata;
    $this->sqlMode                 = $sqlMode;
    $this->characterSet            = $characterSet;
    $this->collate                 = $collate;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extract column metadata from the rows returend by the SQL statement 'describe table'.
   *
   * @param array $description The description of the table.
   *
   * @return array
   */
  private static function extractColumnsFromTableDescription(array $description): array
  {
    $ret = [];

    foreach ($description as $column)
    {
      preg_match('/^(\w+)(.*)?$/', $column['Type'], $parts1);

      $tmp = ['column_name'       => $column['Field'],
              'data_type'         => $parts1[1],
              'numeric_precision' => null,
              'numeric_scale'     => null,
              'dtd_identifier'    => $column['Type']];

      switch ($parts1[1])
      {
        case 'tinyint':
        case 'smallint':
        case 'mediumint':
        case 'int':
        case 'bigint':
          preg_match('/^\((\d+)\)/', $parts1[2], $parts2);
          $tmp['numeric_precision'] = (int)$parts2[1];
          $tmp['numeric_scale']     = 0;
          break;

        case 'year':
          // Nothing to do.
          break;

        case 'float':
          $tmp['numeric_precision'] = 12;
          break;

        case 'double':
          $tmp['numeric_precision'] = 22;
          break;

        case 'binary':
        case 'char':
        case 'varbinary':
        case 'varchar':
          // Nothing to do.
          break;

        case 'decimal':
          preg_match('/^\((\d+),(\d+)\)$/', $parts1[2], $parts2);
          $tmp['numeric_precision'] = (int)$parts2[1];
          $tmp['numeric_scale']     = (int)$parts2[2];;
          break;

        case 'time':
        case 'timestamp':
        case 'date':
        case 'datetime':
          // Nothing to do.
          break;

        case 'enum':
        case 'set':
          // Nothing to do.
          break;

        case 'bit':
          preg_match('/^\((\d+)\)$/', $parts1[2], $parts2);
          $tmp['numeric_precision'] = (int)$parts2[1];
          break;

        case 'tinytext':
        case 'text':
        case 'mediumtext':
        case 'longtext':
        case 'tinyblob':
        case 'blob':
        case 'mediumblob':
        case 'longblob':
          // Nothing to do.
          break;

        default:
          throw new FallenException('data type', $parts1[1]);
      }

      $ret[] = $tmp;
    }

    return $ret;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the instance of MySQL and returns the metadata of the stored routine.
   *
   * @return array
   */
  public function loadStoredRoutine(): array
  {
    $this->routineName           = pathinfo($this->sourceFilename, PATHINFO_FILENAME);
    $this->phpStratumOldMetadata = $this->phpStratumMetadata;
    $this->filemtime             = filemtime($this->sourceFilename);

    $load = $this->mustLoadStoredRoutine();
    if ($load)
    {
      $this->io->text(sprintf('Loading routine <dbo>%s</dbo>', OutputFormatter::escape($this->routineName)));

      $this->readSourceCode();
      $this->extractPlaceholders();
      $this->extractDesignationType();
      $this->extractReturnType();
      $this->extractRoutineTypeAndName();
      $this->validateReturnType();
      $this->loadRoutineFile();
      $this->extractBulkInsertTableColumnsInfo();
      $this->extractExtendedParametersInfo();
      $this->extractRoutineParametersInfo();
      $this->extractDocBlockPartsWrapper();
      $this->validateParameterLists();
      $this->updateMetadata();
    }

    return $this->phpStratumMetadata;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops the stored routine if it exists.
   */
  private function dropRoutine(): void
  {
    if (isset($this->rdbmsOldRoutineMetadata))
    {
      MetaDataLayer::dropRoutine($this->rdbmsOldRoutineMetadata['routine_type'], $this->routineName);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Extracts the column names and column types of the current table for bulk insert.
   */
  private function extractBulkInsertTableColumnsInfo(): void
  {
    // Return immediately if designation type is not appropriate for this method.
    if ($this->designationType!='bulk_insert') return;

    // Check if table is a temporary table or a non-temporary table.
    $table_is_non_temporary = MetaDataLayer::checkTableExists($this->bulkInsertTableName);

    // Create temporary table if table is non-temporary table.
    if (!$table_is_non_temporary)
    {
      MetaDataLayer::callProcedure($this->routineName);
    }

    // Get information about the columns of the table.
    $description = MetaDataLayer::describeTable($this->bulkInsertTableName);

    // Drop temporary table if table is non-temporary.
    if (!$table_is_non_temporary)
    {
      MetaDataLayer::dropTemporaryTable($this->bulkInsertTableName);
    }

    // Check number of columns in the table match the number of fields given in the designation type.
    $n1 = count($this->bulkInsertKeys);
    $n2 = count($description);
    if ($n1!=$n2)
    {
      throw new RoutineLoaderException("Number of fields %d and number of columns %d don't match.", $n1, $n2);
    }

    $this->bulkInsertColumns = self::extractColumnsFromTableDescription($description);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the designation type of the stored routine.
   */
  private function extractDesignationType(): void
  {
    $found = true;
    $key   = array_search('begin', $this->routineSourceCodeLines);

    if ($key!==false)
    {
      for ($i = 1; $i<$key; $i++)
      {
        $n = preg_match('/^\s*--\s+type:\s*(\w+)\s*(.+)?\s*$/',
                        $this->routineSourceCodeLines[$key - $i],
                        $matches);
        if ($n==1)
        {
          $this->designationType = $matches[1];
          switch ($this->designationType)
          {
            case 'bulk_insert':
              $m = preg_match('/^([a-zA-Z0-9_]+)\s+([a-zA-Z0-9_,]+)$/',
                              $matches[2],
                              $info);
              if ($m==0)
              {
                throw new RoutineLoaderException('Error: Expected: -- type: bulk_insert <table_name> <columns>');
              }
              $this->bulkInsertTableName = $info[1];
              $this->bulkInsertKeys      = explode(',', $info[2]);
              break;

            case 'rows_with_key':
            case 'rows_with_index':
              $this->indexColumns = explode(',', $matches[2]);
              break;

            default:
              if (isset($matches[2])) $found = false;
          }
          break;
        }
        if ($i==($key - 1)) $found = false;
      }
    }
    else
    {
      $found = false;
    }

    if ($found===false)
    {
      throw new RoutineLoaderException('Unable to find the designation type of the stored routine');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Extracts the DocBlock (in parts) from the source of the stored routine.
   */
  private function extractDocBlockPartsSource(): void
  {
    // Get the DocBlock for the source.
    $tmp = PHP_EOL;
    foreach ($this->routineSourceCodeLines as $line)
    {
      $n = preg_match('/create\\s+(procedure|function)\\s+([a-zA-Z0-9_]+)/i', $line);
      if ($n) break;

      $tmp .= $line;
      $tmp .= PHP_EOL;
    }

    $phpdoc = new DocBlockReflection($tmp);

    // Get the short description.
    $this->docBlockPartsSource['sort_description'] = $phpdoc->getShortDescription();

    // Get the long description.
    $this->docBlockPartsSource['long_description'] = $phpdoc->getLongDescription();

    // Get the description for each parameter of the stored routine.
    foreach ($phpdoc->getTags() as $key => $tag)
    {
      if ($tag->getName()=='param')
      {
        /* @var $tag ParamTag */
        $this->docBlockPartsSource['parameters'][$key] = ['name'        => $tag->getTypes()[0],
                                                          'description' => $tag->getDescription()];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Extracts DocBlock parts to be used by the wrapper generator.
   */
  private function extractDocBlockPartsWrapper(): void
  {
    // Get the DocBlock parts from the source of the stored routine.
    $this->extractDocBlockPartsSource();

    // Generate the parameters parts of the DocBlock to be used by the wrapper.
    $parameters = [];
    foreach ($this->parameters as $parameter_info)
    {
      $parameters[] = ['parameter_name'       => $parameter_info['parameter_name'],
                       'php_type'             => DataTypeHelper::columnTypeToPhpTypeHinting($parameter_info).'|null',
                       'data_type_descriptor' => $parameter_info['data_type_descriptor'],
                       'description'          => $this->getParameterDocDescription($parameter_info['parameter_name'])];
    }

    // Compose all the DocBlock parts to be used by the wrapper generator.
    $this->docBlockPartsWrapper = ['sort_description' => $this->docBlockPartsSource['sort_description'],
                                   'long_description' => $this->docBlockPartsSource['long_description'],
                                   'parameters'       => $parameters];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts extended info of the routine parameters.
   */
  private function extractExtendedParametersInfo(): void
  {
    $key = array_search('begin', $this->routineSourceCodeLines);

    if ($key!==false)
    {
      for ($i = 1; $i<$key; $i++)
      {
        $k = preg_match('/^\s*--\s+param:(?:\s*(\w+)\s+(\w+)(?:(?:\s+([^\s-])\s+([^\s-])\s+([^\s-])\s*$)|(?:\s*$)))?/',
                        $this->routineSourceCodeLines[$key - $i + 1],
                        $matches);

        if ($k==1)
        {
          $count = count($matches);
          if ($count==3 || $count==6)
          {
            $parameter_name = $matches[1];
            $data_type      = $matches[2];

            if ($count==6)
            {
              $list_delimiter = $matches[3];
              $list_enclosure = $matches[4];
              $list_escape    = $matches[5];
            }
            else
            {
              $list_delimiter = ',';
              $list_enclosure = '"';
              $list_escape    = '\\';
            }

            if (!isset($this->extendedParameters[$parameter_name]))
            {
              $this->extendedParameters[$parameter_name] = ['name'      => $parameter_name,
                                                            'data_type' => $data_type,
                                                            'delimiter' => $list_delimiter,
                                                            'enclosure' => $list_enclosure,
                                                            'escape'    => $list_escape];
            }
            else
            {
              throw new RoutineLoaderException("Duplicate parameter '%s'", $parameter_name);
            }
          }
          else
          {
            throw new RoutineLoaderException('Error: Expected: -- param: <field_name> <type_of_list> [delimiter enclosure escape]');
          }
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the placeholders from the stored routine source.
   */
  private function extractPlaceholders(): void
  {
    $unknown = [];

    preg_match_all('(@[A-Za-z0-9\_\.]+(\%type)?@)', $this->routineSourceCode, $matches);
    if (!empty($matches[0]))
    {
      foreach ($matches[0] as $placeholder)
      {
        if (isset($this->replacePairs[strtoupper($placeholder)]))
        {
          $this->replace[$placeholder] = $this->replacePairs[strtoupper($placeholder)];
        }
        else
        {
          $unknown[] = $placeholder;
        }
      }
    }

    $this->logUnknownPlaceholders($unknown);
  }


  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the return type of the stored routine.
   */
  private function extractReturnType(): void
  {
    // Return immediately if designation type is not appropriate for this method.
    if (!in_array($this->designationType, ['function', 'singleton0', 'singleton1'])) return;

    $key = array_search('begin', $this->routineSourceCodeLines);

    if ($key!==false)
    {
      for ($i = 1; $i<$key; $i++)
      {
        $n = preg_match('/^\s*--\s+return:\s*((\w|\|)+)\s*$/',
                        $this->routineSourceCodeLines[$key - $i],
                        $matches);
        if ($n==1)
        {
          $this->returnType = $matches[1];

          break;
        }
      }
    }

    if ($this->returnType===null)
    {
      $this->returnType = 'mixed';

      $this->io->logNote('Unable to find the return type of stored routine');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts info about the parameters of the stored routine.
   */
  private function extractRoutineParametersInfo(): void
  {
    $routine_parameters = MetaDataLayer::getRoutineParameters($this->routineName);
    foreach ($routine_parameters as $key => $routine_parameter)
    {
      if ($routine_parameter['parameter_name'])
      {
        $data_type_descriptor = $routine_parameter['dtd_identifier'];
        if (isset($routine_parameter['character_set_name']))
        {
          $data_type_descriptor .= ' character set '.$routine_parameter['character_set_name'];
        }
        if (isset($routine_parameter['collation_name']))
        {
          $data_type_descriptor .= ' collation '.$routine_parameter['collation_name'];
        }

        $routine_parameter['data_type_descriptor'] = $data_type_descriptor;

        $this->parameters[$key] = $routine_parameter;
      }
    }

    $this->updateParametersInfo();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the name of the stored routine and the stored routine type (i.e. procedure or function) source.
   */
  private function extractRoutineTypeAndName(): void
  {
    $n = preg_match('/create\\s+(procedure|function)\\s+([a-zA-Z0-9_]+)/i', $this->routineSourceCode, $matches);
    if ($n==1)
    {
      $this->routineType = strtolower($matches[1]);

      if ($this->routineName!=$matches[2])
      {
        throw new RoutineLoaderException("Stored routine name '%s' does not corresponds with filename", $matches[2]);
      }
    }
    else
    {
      throw new RoutineLoaderException('Unable to find the stored routine name and type');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets description by name of the parameter as found in the DocBlock of the stored routine.
   *
   * @param string $name Name of the parameter.
   *
   * @return string|null
   */
  private function getParameterDocDescription(string $name): ?string
  {
    if (isset($this->docBlockPartsSource['parameters']))
    {
      foreach ($this->docBlockPartsSource['parameters'] as $parameter_doc_info)
      {
        if ($parameter_doc_info['name']===$name) return $parameter_doc_info['description'];
      }
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the database.
   */
  private function loadRoutineFile(): void
  {
    // Set magic constants specific for this stored routine.
    $this->setMagicConstants();

    // Replace all place holders with their values.
    $lines          = explode("\n", $this->routineSourceCode);
    $routine_source = [];
    foreach ($lines as $i => &$line)
    {
      $this->replace['__LINE__'] = $i + 1;
      $routine_source[$i]        = strtr($line, $this->replace);
    }
    $routine_source = implode("\n", $routine_source);

    // Unset magic constants specific for this stored routine.
    $this->unsetMagicConstants();

    // Drop the stored procedure or function if its exists.
    $this->dropRoutine();

    // Set the SQL-mode under which the stored routine will run.
    MetaDataLayer::setSqlMode($this->sqlMode);

    // Set the default character set and collate under which the store routine will run.
    MetaDataLayer::setCharacterSet($this->characterSet, $this->collate);

    // Finally, execute the SQL code for loading the stored routine.
    MetaDataLayer::loadRoutine($routine_source);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the unknown placeholder (if any).
   *
   * @param array $unknown The unknown placeholders.
   */
  private function logUnknownPlaceholders(array $unknown): void
  {
    // Return immediately if there are no unknown placeholders.
    if (empty($unknown)) return;

    sort($unknown);
    $this->io->text('Unknown placeholder(s):');
    $this->io->listing($unknown);

    $replace = [];
    foreach ($unknown as $placeholder)
    {
      $replace[$placeholder] = '<error>'.$placeholder.'</error>';
    }
    $code = strtr(OutputFormatter::escape($this->routineSourceCode), $replace);

    $this->io->text(explode(PHP_EOL, $code));

    throw new RoutineLoaderException('Unknown placeholder(s) found');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns true if the source file must be load or reloaded. Otherwise returns false.
   *
   * @return bool
   */
  private function mustLoadStoredRoutine(): bool
  {
    // If this is the first time we see the source file it must be loaded.
    if (!isset($this->phpStratumOldMetadata)) return true;

    // If the source file has changed the source file must be loaded.
    if ($this->phpStratumOldMetadata['timestamp']!=$this->filemtime) return true;

    // If the value of a placeholder has changed the source file must be loaded.
    foreach ($this->phpStratumOldMetadata['replace'] as $place_holder => $old_value)
    {
      if (!isset($this->replacePairs[strtoupper($place_holder)]) ||
        $this->replacePairs[strtoupper($place_holder)]!==$old_value)
      {
        return true;
      }
    }

    // If stored routine not exists in database the source file must be loaded.
    if (!isset($this->rdbmsOldRoutineMetadata)) return true;

    // If current sql-mode is different the source file must reload.
    if ($this->rdbmsOldRoutineMetadata['sql_mode']!=$this->sqlMode) return true;

    // If current character set is different the source file must reload.
    if ($this->rdbmsOldRoutineMetadata['character_set_client']!=$this->characterSet) return true;

    // If current collation is different the source file must reload.
    if ($this->rdbmsOldRoutineMetadata['collation_connection']!=$this->collate) return true;

    return false;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the source code of the stored routine.
   */
  private function readSourceCode(): void
  {
    $this->routineSourceCode      = file_get_contents($this->sourceFilename);
    $this->routineSourceCodeLines = explode("\n", $this->routineSourceCode);

    if ($this->routineSourceCodeLines===false)
    {
      throw new RoutineLoaderException('Source file is empty');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds magic constants to replace list.
   */
  private function setMagicConstants(): void
  {
    $real_path = realpath($this->sourceFilename);

    $this->replace['__FILE__']    = "'".MetaDataLayer::realEscapeString($real_path)."'";
    $this->replace['__ROUTINE__'] = "'".$this->routineName."'";
    $this->replace['__DIR__']     = "'".MetaDataLayer::realEscapeString(dirname($real_path))."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes magic constants from current replace list.
   */
  private function unsetMagicConstants(): void
  {
    unset($this->replace['__FILE__']);
    unset($this->replace['__ROUTINE__']);
    unset($this->replace['__DIR__']);
    unset($this->replace['__LINE__']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Updates the metadata for the stored routine.
   */
  private function updateMetadata(): void
  {
    $this->phpStratumMetadata['routine_name']           = $this->routineName;
    $this->phpStratumMetadata['designation']            = $this->designationType;
    $this->phpStratumMetadata['return']                 = $this->returnType;
    $this->phpStratumMetadata['parameters']             = $this->parameters;
    $this->phpStratumMetadata['timestamp']              = $this->filemtime;
    $this->phpStratumMetadata['replace']                = $this->replace;
    $this->phpStratumMetadata['phpdoc']                 = $this->docBlockPartsWrapper;
    $this->phpStratumMetadata['spec_params']            = $this->extendedParameters;
    $this->phpStratumMetadata['index_columns']          = $this->indexColumns;
    $this->phpStratumMetadata['bulk_insert_table_name'] = $this->bulkInsertTableName;
    $this->phpStratumMetadata['bulk_insert_columns']    = $this->bulkInsertColumns;
    $this->phpStratumMetadata['bulk_insert_keys']       = $this->bulkInsertKeys;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Update information about specific parameters of stored routine.
   */
  private function updateParametersInfo(): void
  {
    if (!empty($this->extendedParameters))
    {
      foreach ($this->extendedParameters as $spec_param_name => $spec_param_info)
      {
        $param_not_exist = true;
        foreach ($this->parameters as $key => $param_info)
        {
          if ($param_info['parameter_name']==$spec_param_name)
          {
            $this->parameters[$key] = array_merge($this->parameters[$key], $spec_param_info);
            $param_not_exist        = false;
            break;
          }
        }
        if ($param_not_exist)
        {
          throw new RoutineLoaderException("Specific parameter '%s' does not exist", $spec_param_name);
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Validates the parameters found the DocBlock in the source of the stored routine against the parameters from the
   * metadata of MySQL and reports missing and unknown parameters names.
   */
  private function validateParameterLists(): void
  {
    // Make list with names of parameters used in database.
    $database_parameters_names = [];
    foreach ($this->parameters as $parameter_info)
    {
      $database_parameters_names[] = $parameter_info['parameter_name'];
    }

    // Make list with names of parameters used in dock block of routine.
    $doc_block_parameters_names = [];
    if (isset($this->docBlockPartsSource['parameters']))
    {
      foreach ($this->docBlockPartsSource['parameters'] as $parameter)
      {
        $doc_block_parameters_names[] = $parameter['name'];
      }
    }

    // Check and show warning if any parameters is missing in doc block.
    $tmp = array_diff($database_parameters_names, $doc_block_parameters_names);
    foreach ($tmp as $name)
    {
      $this->io->logNote('Parameter <dbo>%s</dbo> is missing from doc block', $name);
    }

    // Check and show warning if find unknown parameters in doc block.
    $tmp = array_diff($doc_block_parameters_names, $database_parameters_names);
    foreach ($tmp as $name)
    {
      $this->io->logNote('Unknown parameter <dbo>%s</dbo> found in doc block', $name);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Validates the specified return type of the stored routine.
   */
  private function validateReturnType(): void
  {
    // Return immediately if designation type is not appropriate for this method.
    if (!in_array($this->designationType, ['function', 'singleton0', 'singleton1'])) return;

    $types = explode('|', $this->returnType);
    $diff  = array_diff($types, ['string', 'int', 'float', 'double', 'bool', 'null']);

    if (!($this->returnType=='mixed' || $this->returnType=='bool' || empty($diff)))
    {
      throw new RoutineLoaderException("Return type must be 'mixed', 'bool', or a combination of 'int', 'float', 'string', and 'null'");
    }

    // The following tests are applicable for singleton0 routines only.
    if (!in_array($this->designationType, ['singleton0'])) return;

    // Return mixed is OK.
    if (in_array($this->returnType, ['bool', 'mixed'])) return;

    // In all other cases return type mus contain null.
    $parts = explode('|', $this->returnType);
    $key   = array_search('null', $parts);
    if ($key===false)
    {
      throw new RoutineLoaderException("Return type must be 'mixed', 'bool', or contain 'null' (with a combination of 'int', 'float', and 'string')");
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
