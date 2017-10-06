<?php
namespace Kitzberger\RecordMigration\Service;

use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Controller for scheduled execution
 */
class MigrationService
{
	/**
	 * Configuration
	 *
	 * @var array
	 */
	protected $conf;

	/**
	 * @var array
	 */
	protected $logs;

	/**
	 * @var \TYPO3\CMS\Core\Log\Logger
	 */
	protected $logger;

	/**
	 * @var \TYPO3\CMS\Core\Database\DatabaseConnection;
	 */
	protected $db;

	/**
	 * @var \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
	 */
	protected $cObj;

	protected $sourceTable;

	protected $sourceTableWhere = 'deleted=0';

	protected $targetTable;

	protected $sourceTableHasDeletedColumn = true;
	protected $targetTableHasDeletedColumn = true;
	protected $targetTableHasTstampColumn = true;
	protected $targetTableHasImportSourceColumn = true;
	protected $sysFileReferenceTableHasImportSourceColumn = true;

	protected $folder;

	protected $modifications = [
		'ifEmpty'        => 'IF|empty',
		'ifNotEmpty'     => 'IF|!empty',
		'formatDate'     => 'PHP|date|Y-m-d',
		'formatDateTime' => 'PHP|date|Y-m-d H:i:s',
	];

	/* */
	protected $mapping = [
		'pid'                             => '{pid}',
		'hidden'                          => '{hidden}',
		'crdate'                          => '{crdate}',
		'tstamp'                          => '{tstamp}',
		'cruser_id'                       => '{cruser_id}',
		'tx_recordmigration_importsource' => NULL,
	];

	protected $iteration = null;

	protected $dryRun = false;

	protected $logLevel = 0;

	protected $falRelations = [];

	public function __construct()
	{
		$this->conf            = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['record_migration']);
		$this->logger          = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);
		$this->db              = $GLOBALS['TYPO3_DB'];
		$this->cObj            = GeneralUtility::makeInstance(\TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::class);

		if (!$this->conf['folder']) {
			$logMsg = 'No folder for instructions configurated in extension manager!';
			$this->log(LogLevel::ERROR, $logMsg);
		}

		$this->folder = PATH_site . $this->conf['folder'];
		$this->folder = realpath($this->folder);

		if (!is_dir($this->folder)) {
			$logMsg = 'Folder "'.$this->folder.'" doesn\'t exists!';
			$this->log(LogLevel::ERROR, $logMsg);
		}
	}

	/**
	 *
	 * @return []
	 */
	public function getAvailableMigrations()
	{
		chdir($this->folder);
		$files = glob("*.json");

		if (count($files) === 0) {
			$this->log(LogLevel::ERROR, 'No migrations found in "'.$this->folder.'"!');
		}

		return $files;
	}

	/**
	 * @param string $table
	 */
	public function addImportSourceColumnToTable($table)
	{
		$this->sqlQuery("ALTER TABLE ".$table." ADD COLUMN tx_recordmigration_importsource VARCHAR(255) DEFAULT NULL");
		if ($this->db->sql_error()) {
			$this->log(LogLevel::ERROR, $this->db->sql_error());
			return false;
		}
		$this->sqlQuery("ALTER TABLE ".$table." ADD UNIQUE tx_recordmigration_importsource (tx_recordmigration_importsource)");
		if ($this->db->sql_error()) {
			$this->log(LogLevel::ERROR, $this->db->sql_error());
			return false;
		}
		return true;
	}

	public function getTimeZoneInfo()
	{
		// Timezone info
		$this->log(LogLevel::NOTICE, '<fg=cyan>--------------------------------------------------------</>');
		$this->log(LogLevel::NOTICE, '<fg=cyan>Timezone (PHP):</>      ' . date_default_timezone_get());
		$ret = $this->sqlQuery('SELECT @@global.time_zone as global, @@session.time_zone as session;');
		if ($row = $this->db->sql_fetch_assoc($ret)) {
			$this->log(LogLevel::NOTICE, '<fg=cyan>Timezone (Datebase):</> ' . $row['session']);
		}
		$this->log(LogLevel::NOTICE, '<fg=cyan>--------------------------------------------------------</>');
	}

	/**
	 * @param string $file
	 * @param boolean $dryRun only simulate DB operations. Default: false
	 * @param int $verboseLevel be talkative on operations. Default: 0. Others: 1, 2, 3
	 * @param int $sourcePid recursive
	 * @param int $limit maximum number of records to be imported. Default: 0 = all
	 * @param boolean $deleteSources delete source records after convertion? Default: false
	 *
	 * @return  string error message
	 */
	public function performMigration($file, $dryRun = false, $verboseLevel = 0, $sourcePid = 0, $limit = 0, $deleteSources = false)
	{
		$errorMessage = '';

		$this->dryRun = $dryRun;
		$this->logLevel = $verboseLevel ? $verboseLevel + 4 : 0;

		chdir($this->folder);
		if (!file_exists($file)) {
			$errorMessage = 'File "'.$this->folder.'/'.$file.'" doesn\'t exists!';
			$this->log(LogLevel::ERROR, $errorMessage);
			return $errorMessage;
		}

		try {
			$this->log(LogLevel::NOTICE, '');
			$this->log(LogLevel::NOTICE, 'Reading instructions from file "'.$file.'"');

			$json = file_get_contents($file);
			$json = json_decode($json, true);

			$this->targetTable = $json['target'];
			$this->sourceTable = $json['source'];
			if (array_key_exists('sourceWhere', $json)) {
				$this->sourceTableWhere = $json['sourceWhere'];
			}

			if (isset($json['modifications'])) {
				$this->log(LogLevel::NOTICE, ' * Adding custom modifications');
				$this->modifications = array_merge($this->modifications, $json['modifications']);
			}

			$overrideMapping = $json['mapping'];

			if ($overrideMapping) {
				if (is_array($overrideMapping)) {
					$this->log(LogLevel::NOTICE, ' * Overriding mapping');
					$this->mapping = array_merge($this->mapping, $overrideMapping);
				} else {
					$this->log(LogLevel::ERROR, 'Mapping illegal: ' . print_r($overrideMapping, true));
					$this->quit(1);
				}
			}

			foreach ($this->mapping as $columnName => $mapping) {
				$ret = $this->sqlQuery('SHOW COLUMNS FROM `'.$this->targetTable.'` LIKE "'.$columnName.'"');
				if (!$ret->num_rows) {
					$this->log(LogLevel::NOTICE, ' * Removed column \''.$columnName.'\' from mapping since it\'s missing in target table');
					unset($this->mapping[$columnName]);
				}
			}

			if (array_key_exists('iteration', $json)) {
				$this->iteration = $json['iteration'];
			}

			$ret = $this->sqlQuery('SHOW COLUMNS FROM `'.$this->sourceTable.'` LIKE "deleted"');
			if (!$ret->num_rows) {
				$this->sourceTableHasDeletedColumn = false;
			}

			$ret = $this->sqlQuery('SHOW COLUMNS FROM `'.$this->targetTable.'` LIKE "deleted"');
			if (!$ret->num_rows) {
				$this->targetTableHasDeletedColumn = false;
			}

			$ret = $this->sqlQuery('SHOW COLUMNS FROM `'.$this->targetTable.'` LIKE "tstamp"');
			if (!$ret->num_rows) {
				$this->targetTableHasTstampColumn = false;
			}

			$ret = $this->sqlQuery('SHOW COLUMNS FROM `'.$this->targetTable.'` LIKE "tx_recordmigration_importsource"');
			if (!$ret->num_rows) {
				$this->targetTableHasImportSourceColumn = $this->addImportSourceColumnToTable($this->targetTable);
			}

			$ret = $this->sqlQuery('SHOW COLUMNS FROM `sys_file_reference` LIKE "tx_recordmigration_importsource"');
			if (!$ret->num_rows) {
				$this->sysFileReferenceTableHasImportSourceColumn = $this->addImportSourceColumnToTable('sys_file_reference');
			}

			$this->log(LogLevel::NOTICE, ' * Using mapping: ' . trim(print_r($this->mapping, true)));

			$this->log(LogLevel::NOTICE, '');
			$this->log(LogLevel::NOTICE, 'Fetching records from DB table "' . $this->sourceTable . '"');
			$records = $this->getSourceRecords($sourcePid, $limit);
			$this->log(LogLevel::NOTICE, ' * Found ' . count($records) . ' records');

			if ($this->iteration) {
				$this->log(LogLevel::NOTICE, '');
				$this->log(LogLevel::NOTICE, 'Multiplying records accordion to iteration "' . $this->iteration . '"');
				$records = $this->applyIteration($records);
				$this->log(LogLevel::NOTICE, ' * Created ' . count($records) . ' records');
			}

			$this->log(LogLevel::NOTICE, '');
			$this->log(LogLevel::NOTICE, 'Importing records into DB table "' . $this->targetTable . '"');
			$this->convertRecords($records, $deleteSources);

			if (count($this->falRelations)) {
				$this->createFalRelations();
			}

			$this->log(LogLevel::NOTICE, '');
			if (!$this->dryRun) {
				$logMsg = '<info>Record convertion successfully finished</info>';
			} else {
				$logMsg = '<comment>Record convertion skipped due to --dry-run</comment>';
			}
			$this->log(LogLevel::NOTICE, $logMsg);
		} catch (\Exception $e) {
			$this->log(LogLevel::ERROR, '<error>Exception: ' . $e->getMessage().'</error>');
		}

		$errors = [];
		if (!empty($this->logs)) {
			foreach ($this->logs as $log) {
				if ($log['level'] <= LogLevel::ERROR) {
					$errors[] = $log['message'];
				}
			}
		}

		return join("\n", $errors);
	}

	/**
	 * Fetch source records from DB
	 *
	 * @param int $pid
	 * @param int $depth
	 * @param int $limit
	 */
	protected function getSourceRecords($pid = 0, $limit = 0, $depth = 100) {
		if ($pid) {
			$queryGenerator = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\QueryGenerator');
			$pidList = $queryGenerator->getTreeList($pid, $depth, 0, 1);
			$where .= ' AND pid in (' . $pidList . ')';
		}
		return $this->db->exec_SELECTgetRows('*', $this->sourceTable, $this->sourceTableWhere, '', '', $limit ?: '');
	}

	/**
	 * Convert records from source table to az records
	 *
	 * @param array $records
	 * @param boolean $deleteSources
	 *
	 * @throws \Exception
	 */
	protected function convertRecords($records, $deleteSources)
	{
		try {
			$this->sqlQuery('START TRANSACTION');

			foreach ($records as $record) {
				$upsertQuery = 'INSERT INTO '.$this->targetTable.' (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s';

				// ####################
				// Records
				// ####################

				$this->log(LogLevel::INFO, '---------------------------------------------------------------------');
				$this->log(LogLevel::INFO, 'Record ' . $this->sourceTable . ':' . $record['uid']);
				$this->log(LogLevel::INFO, '---------------------------------------------------------------------');
				$this->log(LogLevel::DEBUG, print_r($record, true));

				// Create INSERT and UPDATE data
				$insertData = [];
				$updateData = [];
				foreach ($this->mapping as $fieldNameTarget => $fieldConfigSource) {
					$this->log(LogLevel::DEBUG, '- composing target field: ' . $fieldNameTarget);

					if ($fieldConfigSource === null) {
						if ($fieldNameTarget === 'tx_recordmigration_importsource') {
							if ($record['uid']) {
								$key = $record['uid'];
							} else {
								$key = md5(serialize($record));
							}

							$insertData['tx_recordmigration_importsource'] = $this->sourceTable . ':' . $key;

							if ($this->iteration) {
								$insertData['tx_recordmigration_importsource'] .= ':' . $record['iteration_index'];
							}
						} else {
							$insertData[$fieldNameTarget] = null;
						}
					} else {

						// ###################
						// Detect if-blocks
						// ###################
						$ifPattern = '/({if:[a-z0-9:_,!]+})(.*)({\/if})/iU';
						$fieldConfigSource = preg_replace_callback(
							$ifPattern,
							function($ifBlock) use ($record) {
								// var_dump($ifBlock);
								$ifFields = trim($ifBlock[1], '{}');
								$ifFields = explode(':', $ifFields)[1];
								$ifFields = explode(',', $ifFields);
								$ifResult = true;
								foreach ($ifFields as $ifField) {
									if (substr($ifField, 0, 1) === '!') {
										$ifField = trim($ifField, '!');
										if (!empty($record[$ifField])) {
											$ifResult = false;
										}
									} else {
										if (empty($record[$ifField])) {
											$ifResult = false;
										}
									}
								}
								if ($ifResult) {
									return $ifBlock[2];
								} else {
									return '';
								}
							},
							$fieldConfigSource
						);

						// ####################
						// Detect modifications
						// ####################
						$pattern = '/({[a-z0-9:_\|]+})/i';

						// replace all marker in $fieldConfigSource
						preg_match_all($pattern, $fieldConfigSource, $fields);
						foreach ($fields[0] as &$field) {
							$field = trim($field, '{}'); // strip { and }
							//var_dump($field);
							if (strpos($field, '|') === FALSE) {
								$this->log(LogLevel::DEBUG, '  reading source field:   ' . $field);
								$field = $record[$field];
							} else {
								list($field, $modifications) = explode('|', $field, 2);
								$this->log(LogLevel::DEBUG, '  reading source field:   ' . $field);
								$field = $this->applyModifications($fieldNameTarget, $record, $field, explode('|', $modifications));
							}
						}
						unset($field);
						$fieldConfigSource = preg_replace($pattern, '%s', $fieldConfigSource);
						$fieldValueTarget = vsprintf($fieldConfigSource, $fields[0]);

						$insertData[$fieldNameTarget] = $fieldValueTarget;
						// for updates we take the value of the field that we used in the
						// insert statement, hence the weird update statement ;-)
						$updateData[$fieldNameTarget] = $fieldNameTarget . ' = VALUES(' . $fieldNameTarget . ')';
					}
				}

				if ($this->targetTableHasTstampColumn) {
					$insertData['tstamp'] = $_SERVER['REQUEST_TIME'];
				}

				if ($this->targetTableHasDeletedColumn) {
					$updateData['deleted'] = 'deleted=0';
				}

				// Quoting
				$insertData = $this->db->fullQuoteArray($insertData, $this->targetTable);

				// Build querys
				$upsertQuery = sprintf(
					$upsertQuery,
					implode(',', array_keys($this->mapping)),
					implode(',', $insertData),
					implode(',', $updateData)
				);
				$deleteQuery = 'UPDATE ' . $this->sourceTable . ' SET deleted=1 WHERE uid=' . $record['uid'];

				$this->log(LogLevel::NOTICE, $upsertQuery);
				if ($deleteSources) {
					$this->log(LogLevel::NOTICE, $deleteQuery);
				}

				// Execute query
				if (!$this->dryRun) {
					$this->sqlQuery($upsertQuery);

					if ($deleteSources) {
						$this->sqlQuery($deleteQuery);
					}
				} else {
					$this->log(LogLevel::NOTICE, '<comment>=> Skipping DB operations due to --dry-run</comment>');
				}

				$this->log(LogLevel::NOTICE, '');
			}

			$this->sqlQuery('COMMIT');
		} catch (\Exception $e) {
			$this->sqlQuery('ROLLBACK');
			throw $e;
		}
	}

	protected function createFalRelations()
	{
		try {
			$this->sqlQuery('START TRANSACTION');

			foreach ($this->falRelations as $falRelation) {
				$upsertQuery = 'INSERT INTO sys_file_reference (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s';

				// ####################
				// FAL relation
				// ####################

					$this->log(LogLevel::NOTICE, '---------------------------------------------------------------------');
					$this->log(LogLevel::NOTICE, 'FAL Relation for ' . $falRelation['tx_recordmigration_importsource']);
					$this->log(LogLevel::NOTICE, '---------------------------------------------------------------------');
					$this->log(LogLevel::INFO, print_r($falRelation, true));

				$record = $this->db->exec_SELECTgetSingleRow('uid', $this->targetTable, 'tx_recordmigration_importsource="'.$falRelation['tx_recordmigration_importsource'].'"');

				// Create INSERT and UPDATE data
				$insertData = [];
				$updateData = [];

				$insertData['pid'] = 0;
				$insertData['hidden'] = 0;
				$insertData['crdate'] = $_SERVER['REQUEST_TIME'];
				$insertData['tstamp'] = $_SERVER['REQUEST_TIME'];
				$insertData['uid_local'] = $falRelation['sys_file'];
				$insertData['uid_foreign'] = $record['uid'];
				$insertData['tablenames'] = $this->targetTable;
				$insertData['fieldname'] = $falRelation['field'];
				$insertData['table_local'] = 'sys_file';
				$insertData['tx_recordmigration_importsource'] = $falRelation['tx_recordmigration_importsource'];
				$updateData['deleted'] = 'deleted=0';

				// Quoting
				$insertData = $this->db->fullQuoteArray($insertData, 'sys_file_reference');

				// Build querys
				$upsertQuery = sprintf(
					$upsertQuery,
					implode(',', array_keys($insertData)),
					implode(',', $insertData),
					implode(',', $updateData)
				);

				$this->log(LogLevel::NOTICE, $upsertQuery);
				if ($deleteSources) {
					$this->log(LogLevel::NOTICE, $deleteQuery);
				}

				// Execute query
				if (!$this->dryRun) {
					$this->sqlQuery($upsertQuery);
				} else {
					$this->log(LogLevel::NOTICE, '<comment>=> Skipping DB operations due to --dry-run</comment>');
				}

				$this->log(LogLevel::NOTICE, '');
			}

			$this->sqlQuery('COMMIT');
		} catch (\Exception $e) {
			$this->sqlQuery('ROLLBACK');
			throw $e;
		}
	}

	/**
	 * SQL Query (with exception support)
	 *
	 * @param string $query SQL query
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	protected function sqlQuery($query)
	{
		$ret = $this->db->sql_query($query);

		if (!$ret) {
			throw new \Exception(sprintf(
				'SQL-Error: %s [%s]',
				$this->db->sql_error(),
				$this->db->sql_errno()
			));
		}

		return $ret;
	}

	protected function applyModifications($fieldNameTarget, $record, $field, $modificationStack) {

		$modification = array_shift($modificationStack);

		$this->log(LogLevel::DEBUG, '  applying modification:  ' . $modification);

		$result = '';

		if (!isset($this->modifications[$modification])) {
			$logMsg = 'Unknown modification "'.$modification.'"';
			$this->log(LogLevel::WARNING, $logMsg);
		} else {
			if (is_array($this->modifications[$modification])) {
				$array = $this->modifications[$modification];
				if (array_key_exists($record[$field], $array)) {
					$result = $array[$record[$field]];
				}
			} else {
				$modConfig = explode('|', $this->modifications[$modification]);
				switch ($modConfig[0]) {
					case 'IF':
						switch ($modConfig[1]) {
							case 'empty':
								if (empty($record[$field])) {
									$result = $this->applyModifications($fieldNameTarget, $record, $field, $modificationStack);
								}
								break;
							case '!empty':
								if (!empty($record[$field])) {
									$result = $this->applyModifications($fieldNameTarget, $record, $field, $modificationStack);
								}
								break;
						}
						break;
					case 'PHP':
						switch ($modConfig[1]) {
							case 'date':
								$result = date($modConfig[2], $record[$field]);
								break;
							case 'explode':
								$explodedParts = explode($modConfig[2], $record[$field]);
								if ($explodedParts && is_array($explodedParts)) {
									$items = [];
									foreach ($explodedParts as $item) {
										// apply next modification on stack to all items
										$items[] = $this->applyModifications($fieldNameTarget, ['key' => $item], 'key', $modificationStack);
									}
									array_shift($modificationStack); // remove recently applied modification from stack
									$result = $this->applyModifications($fieldNameTarget, $items, null, $modificationStack);
								} else {
									$logMsg = 'PHP explode returned empty array!';
									$this->log(LogLevel::WARNING, '<comment>'.$logMsg.'</comment>');
								}
								break;
							case 'join':
								$result = join($modConfig[2], $record);
								break;
							case 'strip_tags':
								$result = strip_tags($record[$field]);
								break;
							case 'preg_replace':
								$result = preg_replace($modConfig[2], $modConfig[3], $record[$field]);
								break;
							default:
								$logMsg = 'PHP modification "'.$modConfig[1].'" not implemented yet!';
								$this->log(LogLevel::WARNING, '<comment>'.$logMsg.'</comment>');
						}
						break;
					case 'DB':
						$row = $this->db->exec_SELECTgetSingleRow('*', $modConfig[1], $modConfig[2].'="'.$record[$field].'"');
						if ($row) {
							$result = $row[$modConfig[3]];
						} else {
							$logMsg = 'No record found when looking up "'.$modification.'" for ' . $record[$field];
							$this->log(LogLevel::WARNING, '<comment>'.$logMsg.'</comment>');
						}
						break;
					case 'TYPO3_FAL':
						if ($this->targetTableHasImportSourceColumn === false) {
							$logMsg  = "For FAL operations to work the target table needs to have a column called 'tx_recordmigration_importsource'!\n";
							$logMsg .= "Please run: hammer:preparetable " . $this->targetTable;
							$this->log(LogLevel::ERROR, '<error>'.$logMsg.'</error>');
							$this->quit(1);
						}
						if ($this->sysFileReferenceTableHasImportSourceColumn === false) {
							$logMsg  = "For FAL operations to work the sys_file_reference table needs to have a column called 'tx_recordmigration_importsource'!\n";
							$logMsg .= "Please run: hammer:preparetable sys_file_reference";
							$this->log(LogLevel::ERROR, '<error>'.$logMsg.'</error>');
							$this->quit(1);
						}

						if ($record[$field]) {
							$fileIdentifier = $modConfig[1] . '/' . $record[$field];
							try {
								$file = $this->getResourceFactory()->getFileObjectFromCombinedIdentifier($fileIdentifier);
							} catch (\Exception $e) {
								$file = false;
							}
							if ($file) {
								$this->falRelations[] = [
									'tx_recordmigration_importsource' => $this->sourceTable . ':' . $record['uid'],
									'sys_file' => $file->getUid(),
									'field' => $fieldNameTarget,
								];
							} else {
								$logMsg = 'File cannot be found: ' . $fileIdentifier;
								$this->log(LogLevel::WARNING, '<comment>'.$logMsg.'</comment>');
							}

							$result = 1;
						} else {
							$result = 0;
						}
						break;
					default:
						$logMsg = 'Unknown modification "'.$modConfig[0].'"';
						$this->log(LogLevel::WARNING, '<comment>'.$logMsg.'</comment>');
				}
			}
		}

		// Recursive call when still modifications on stack
		if (count($modificationStack)) {
			// use $result as input instead of whole record
			$result = $this->applyModifications($fieldNameTarget, compact('result'), 'result', $modificationStack);
		}

		return $result;
	}

	protected function applyIteration($records = [])
	{
		$newRecords = [];

		// Detect "FOR {fieldName|explode} AS {asName}"
		if (preg_match('/FOR\s*{([a-z0-9]+)\|explode}\s*AS\s*{([a-z0-9]+)}/i', $this->iteration, $matches)) {
			$fieldName = $matches[1];
			$asName = $matches[2];

			foreach ($records as $record) {
				// comma separated field
				$field = $record[$fieldName];

				$i = 0;
				foreach (GeneralUtility::intExplode(',', $field, true) as $value) {
					$record[$asName] = $value;
					$record['iteration_index'] = $i++;
					$newRecords[] = $record;
				}
			}
		}

		return $newRecords;
	}

	/**
	 * @return ResourceFactory
	 */
	protected function getResourceFactory()
	{
		return ResourceFactory::getInstance();
	}

	protected function log($logLevel = LogLevel::INFO, $logMsg = '')
	{
		// if it's an EMERGENCY, ALERT, CRITICAL, ERROR, WARNING or if user wishes to be informed
		if ($logLevel <= LogLevel::WARNING || $logLevel <= $this->logLevel) {
			// then keep this log entry
			$this->logs[] = ['level' => $logLevel, 'message' => $logMsg];
		}
		// pass everything to TYPO3 logger api though
		$this->logger->log($logLevel, $logMsg);
	}

	public function getLogs()
	{
		return $this->logs;
	}
}
