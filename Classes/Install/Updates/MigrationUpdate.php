<?php
namespace Kitzberger\RecordMigration\Install\Updates;

use TYPO3\CMS\Install\Updates\AbstractUpdate;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 */
class MigrationUpdate extends AbstractUpdate
{
	/**
	 * @var string
	 */
	protected $title = 'Record migration';

	/**
	 * @var \Kitzberger\RecordMigration\Service\MigrationService
	 */
	protected $migrationService;

	/**
	 * Function which checks if update is needed. Called in the beginning of an update process.
	 *
	 * @param string $description Pointer to description for the update
	 * @return bool TRUE if update is needs to be performed, FALSE otherwise.
	 */
	public function checkForUpdate(&$description)
	{
		$this->init();

		$result = false;

		$migrations = $this->migrationService->getAvailableMigrations();

		if (count($migrations)) {
			$description  = "<p>Available migrations</p>\n";
			$description .= "<ul>";
			$description .= "<li>".join("</li>\n<li>", $migrations)."</li>";
			$description .= "</ul>";
		} else {
			$description = "<p>None available</p>";
		}

		$result = true;

		return $result;
	}

	/**
	 * Second step: Ask user to install the extension
	 *
	 * @param string $inputPrefix input prefix, all names of form fields have to start with this. Append custom name in [ ... ]
	 * @return string HTML output
	 */
	public function getUserInput($inputPrefix)
	{
		$this->init();

		$migrations = $this->migrationService->getAvailableMigrations();

		$fileRadios = [];
		foreach ($migrations as $migration) {
			$fileRadios[] = '
				<div class="radio">
					<label>
						<input type="radio" name="' . $inputPrefix . '[file]" value="'.$migration.'" /> ' . $migration . '
					</label>
				</div>';
		}
		$dryRunRadios = [];
		foreach ([1 => 'yes'] as $dryRun => $label) {
			$dryRunRadios[] = '
				<div class="checkbox">
					<label>
						<input type="checkbox" name="' . $inputPrefix . '[dryRun]" value="'.$dryRun.'" /> ' . $label . '
					</label>
				</div>';
		}

		return '
			<div class="panel panel-danger">
				<div class="panel-heading">Caution!</div>
				<div class="panel-body">Please backup your DB beforehand.</div>
			</div>
			<div class="panel panel-info">
				<div class="panel-heading">Migration parameters</div>
				<div class="panel-body">
					<p>Please specify the one you wanna perform.</p>
					<div class="form-group">
						' . join('', $fileRadios) . '
					</div>

					<p>Dry run?</p>
					<div class="form-group">
						' . join('', $dryRunRadios) . '
					</div>
				</div>
			</div>
		';
	}

	/**
	 * Performs the update itself
	 *
	 * @param array $dbQueries Pointer where to insert all DB queries made, so they can be shown to the user if wanted
	 * @param string $customMessages Pointer to output custom messages
	 * @return bool TRUE if update succeeded, FALSE otherwise
	 */
	public function performUpdate(array &$dbQueries, &$customMessages)
	{
		$this->init();

		$requestParams = GeneralUtility::_GP('install');
		$requestParamsIdentifier = $requestParams['values']['identifier'];
		if (isset($requestParams['values'][$requestParamsIdentifier]['file'])) {
			$file = $requestParams['values'][$requestParamsIdentifier]['file'];
		}
		if (isset($requestParams['values'][$requestParamsIdentifier]['dryRun'])) {
			$dryRun = $requestParams['values'][$requestParamsIdentifier]['dryRun'];
		}

		if ($file) {
			$customMessages = $this->migrationService->performMigration($file, $dryRun);
		} else {
			$customMessages = 'Please select a migration!';
		}

		#$dbQueries[] = 'UPDATE xxx SET yyy=zzz;';
		#$this->markWizardAsDone();

		return empty($customMessages);
	}

	protected function init()
	{
		$this->migrationService = GeneralUtility::makeInstance(\Kitzberger\RecordMigration\Service\MigrationService::class);
	}
}
