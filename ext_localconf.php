<?php
defined('TYPO3_MODE') || exit('Access denied.');

$_EXTCONF = unserialize($_EXTCONF);

if (TYPO3_MODE === 'BE' || TYPO3_MODE === 'CLI') {
	$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'][] = \Kitzberger\RecordMigration\Command\MigrationCommandController::class;
	$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['recordMigration'] = \Kitzberger\RecordMigration\Install\Updates\MigrationUpdate::class;

	if (isset($_EXTCONF['enableLogging']) && $_EXTCONF['enableLogging']) {
		$GLOBALS['TYPO3_CONF_VARS']['LOG']['Kitzberger']['RecordMigration']['Service']['writerConfiguration'] = [
			\TYPO3\CMS\Core\Log\LogLevel::INFO => [
				\TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
					'logFile' => 'typo3temp/logs/record-migration.log'
				],
			],
		];
	}
}

