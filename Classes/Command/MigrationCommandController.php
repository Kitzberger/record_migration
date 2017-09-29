<?php
namespace Kitzberger\RecordMigration\Command;

use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Controller for scheduled execution
 */
class MigrationCommandController extends \TYPO3\CMS\Extbase\Mvc\Controller\CommandController
{
	/**
	 * @var \Kitzberger\RecordMigration\Service\MigrationService
	 */
	protected $migrationService;

	/**
	 * Init
	 */
	protected function init()
	{
		$this->migrationService = GeneralUtility::makeInstance(\Kitzberger\RecordMigration\Service\MigrationService::class);
	}

	protected function log($logLevel = LogLevel::INFO, $logMsg = '')
	{
		if ($logLevel <= LogLevel::ERROR) {
			$this->outputLine('<error>' . $logMsg . '</error>');
		}
		if ($logLevel == LogLevel::WARNING) {
			$this->outputLine('<comment>' . $logMsg . '</comment>');
		}
		if ($logLevel == LogLevel::NOTICE) {
			$this->outputLine('<info>' . $logMsg . '</info>');
		}
		if ($logLevel == LogLevel::INFO) {
			$this->outputLine($logMsg);
		}
		if ($logLevel == LogLevel::DEBUG) {
			$this->outputLine('<fg=cyan>' . $logMsg. '</>');
		}

	}

	/**
	 * Lists all available record migrations
	 */
	public function listCommand()
	{
		$this->init();

		$migrations = $this->migrationService->getAvailableMigrations();

		if (count($migrations)) {
			foreach ($migrations as $migration) {
				$this->log(LogLevel::NOTICE, $migration);
			}
		} else {
			$this->log(LogLevel::WARNING, 'None available');
		}

		$this->quit(0);
	}

	/**
	 * Performs a record migration
	 *
	 * @param string $migration
	 * @param boolean $dryRun only simulate DB operations. Default: false
	 * @param int $verboseLevel be talkative on operations. Default: 0. Others: 1, 2, 3
	 * @param int $sourcePid recursive
	 * @param int $limit maximum number of records to be imported. Default: 0 = all
	 * @param boolean $deleteSources delete source records after convertion? Default: false
	 */
	public function performCommand($migration, $dryRun = false, $verboseLevel = 0, $sourcePid = 0, $limit = 0, $deleteSources = false)
	{
		$this->init();

		$errorMessage = $this->migrationService->performMigration($migration, $dryRun, $verboseLevel, $sourcePid, $limit, $deleteSources);

		if ($errorMessage) {
			$this->log(LogLevel::ERROR, $errorMessage);
			$this->quit(1);
		} else {
			if ($verboseLevel) {
				if ($logs = $this->migrationService->getLogs()) {
					foreach ($logs as $log) {
						$this->log($log['level'], $log['message']);
					}
				}
			}
			$this->quit(0);
		}
	}
}
