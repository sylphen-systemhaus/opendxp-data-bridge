<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Tools;

use Sylphen\DataBridgeBundle\SylphenDataBridgeBundle;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\LockableTrait;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Composer\Script\Event;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use OpenDxp;
use OpenDxp\Db;
use OpenDxp\Extension\Bundle\Installer\AbstractInstaller;
use OpenDxp\Extension\Bundle\Installer\Exception\InstallationException;
use OpenDxp\Model\User\Permission\Definition;
use OpenDxp\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Doctrine\DBAL\Migrations\Version;

class Installer extends AbstractInstaller {
    use LockableTrait;

	const TABLE_DATAPORT = 'sylphen_dd_dataport';
    const TABLE_DATAPORT_RESOURCE = 'sylphen_dd_dataport_resource';
	const TABLE_RAWITEM = 'sylphen_dd_rawitem';
	const TABLE_RAWITEMDATA = 'sylphen_dd_rawitem_data';
	const TABLE_RAWITEMFIELD = 'sylphen_dd_rawitem_field';
	const TABLE_FIELDMAPPING = 'sylphen_dd_fieldmapping';
    const TABLE_IMPORTSTATUS = 'sylphen_dd_importstatus';
    const TABLE_QUEUE = 'sylphen_dd_queue';
    const TABLE_API_KEYS = 'sylphen_dd_api_keys';
    const TABLE_FAVORITES = 'sylphen_dd_favorites';
    const TABLE_IMPORT_IGNORE = 'sylphen_dd_import_ignore';
    const TABLE_TRANSLATION_CACHE = 'sylphen_dd_translation_cache';
    const TABLE_TRANSLATION_GLOSSARY = 'sylphen_dd_translation_glossary';

    /** @var array<string, string> Blackbit DB table names → Sylphen v1.0 table names */
    public const LEGACY_TABLE_RENAMES = [
        'plugin_pim_dataport' => self::TABLE_DATAPORT,
        'plugin_pim_dataport_resource' => self::TABLE_DATAPORT_RESOURCE,
        'plugin_pim_rawitem' => self::TABLE_RAWITEM,
        'plugin_pim_rawitemData' => self::TABLE_RAWITEMDATA,
        'plugin_pim_rawitemField' => self::TABLE_RAWITEMFIELD,
        'plugin_pim_fieldmapping' => self::TABLE_FIELDMAPPING,
        'plugin_pim_importstatus' => self::TABLE_IMPORTSTATUS,
        'plugin_pim_queue' => self::TABLE_QUEUE,
        'plugin_pim_api_keys' => self::TABLE_API_KEYS,
        'plugin_pim_favorites' => self::TABLE_FAVORITES,
        'plugin_pim_import_ignore' => self::TABLE_IMPORT_IGNORE,
        'translations_DataDirector_Cache' => self::TABLE_TRANSLATION_CACHE,
        'translations_DataDirector_Glossary' => self::TABLE_TRANSLATION_GLOSSARY,
    ];

    const INSTALL_LOCK_KEY = 'SylphenDataBridge_Installer';

    /**
     * Map legacy v0.9 export JSON keys (plugin_pim_*) to v1.0 sylphen_dd_* keys.
     */
    public static function normalizeExportData(array $data): array
    {
        foreach (self::LEGACY_TABLE_RENAMES as $legacyKey => $newKey) {
            if (!isset($data[$legacyKey])) {
                continue;
            }

            if (!isset($data[$newKey])) {
                $data[$newKey] = $data[$legacyKey];
            } elseif (is_array($data[$newKey]) && is_array($data[$legacyKey])) {
                $data[$newKey] = array_merge($data[$legacyKey], $data[$newKey]);
            }

            unset($data[$legacyKey]);
        }

        return $data;
    }

	/** @var BundleInterface */
	private $bundle;

    public function __construct(
        BundleInterface $bundle
    ) {
        parent::__construct();
        $this->bundle = $bundle;
    }

    public function install(): void
    {
        if (!$this->canBeInstalled()) {
            throw new InstallationException(sprintf('Bundle "%s" can\'t be installed', $this->bundle->getName()));
        }

        try {
            if ($this->allMigrationsExecuted()) {
                return;
            }

            if(self::isInMaintenanceMode()) {
                return;
            }

            if (!$this->lock(self::INSTALL_LOCK_KEY)) {
                throw new InstallationException('Other process currently runs (automatic) installation');
            }

            if(php_sapi_name() === 'cli' && !in_array('--json', $_SERVER['argv'], true)) {
                echo Cli::exec('"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" doctrine:migrations:migrate -n --prefix='.escapeshellarg(str_replace('\\Tools', '', __NAMESPACE__)).' 1>&2');
            } else {
                Cli::exec('"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" doctrine:migrations:migrate -n --prefix='.escapeshellarg(str_replace('\\Tools', '', __NAMESPACE__)).' 1>&2');
            }
        } catch (\Throwable $e) {
            throw new InstallationException("Failed to install: ".$e);
        } finally {
            $this->release(self::INSTALL_LOCK_KEY);
        }
    }

    /**
	 * @return boolean
	 */
	public function isInstalled(): bool {
        try {
            $this->install();
        } catch(\Throwable $e) {
        }

        return $this->allMigrationsExecuted();
	}

    private function allMigrationsExecuted() {
        $cachedStatus = OpenDxp\Cache::load('data_bridge_installed');
        if($cachedStatus) {
            return (bool)$cachedStatus;
        }

        $permission = Definition::getByKey('plugin_sylphen_data_bridge');

        if (!$permission instanceof Definition) {
            return false;
        }

        $command = '"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" doctrine:migrations:up-to-date --prefix='.escapeshellarg(str_replace('\\Tools', '', __NAMESPACE__)).' --ignore-maintenance-mode 2>&1';
        try {
            $process = Process::fromShellCommandline($command, OPENDXP_PROJECT_ROOT);
        } catch (\Throwable $e) {
            $process = new Process($command, OPENDXP_PROJECT_ROOT);
        }

        $result = $process->run();
        if($result !== 0 && strpos($process->getOutput(), 'Out-of-date') === false) {
            throw new \Exception('Failed to check if bundle is installed: '.$process->getOutput().PHP_EOL.PHP_EOL.'Command executed: '.$command);
        }
        OpenDxp\Cache::save((int)($result === 0), 'data_bridge_installed', [], 86400, 0, true);

        return $result === 0;
    }

	public function needsReloadAfterInstall(): bool {
		return true;
	}

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function uninstall(): void
    {
        if (!$this->canBeUninstalled()) {
            throw new InstallationException(sprintf('Bundle "%s" can\'t be uninstalled', $this->bundle->getName()));
        }

        PimcoreDbRepository::getInstance()->execute(
            'DROP TABLE IF EXISTS
            `'.self::TABLE_IMPORTSTATUS.'`,
            `'.self::TABLE_FIELDMAPPING.'`,
            `'.self::TABLE_RAWITEMFIELD.'`,
            `'.self::TABLE_RAWITEMDATA.'`,
            `'.self::TABLE_RAWITEM.'`,
            `'.self::TABLE_DATAPORT_RESOURCE.'`,
            `'.self::TABLE_FAVORITES.'`,
            `'.self::TABLE_IMPORT_IGNORE.'`,
            `'.self::TABLE_DATAPORT.'`,
            `'.self::TABLE_QUEUE.'`,
            `'.self::TABLE_API_KEYS.'`,
            `'.self::TABLE_TRANSLATION_CACHE.'`,
            `'.self::TABLE_TRANSLATION_GLOSSARY.'`'
        );

        try {
            PimcoreDbRepository::getInstance()->execute('DELETE FROM migration_versions WHERE version LIKE ? OR version LIKE ?', ['Blackbit\\\\DataDirectorBundle%', 'Sylphen\\\\DataBridgeBundle%']);
        } catch (\Exception $e) {
        }

        try {
            PimcoreDbRepository::getInstance()->execute(
                'DELETE FROM users_permission_definitions WHERE `key` IN (?, ?)',
                ['plugin_sylphen_data_bridge', 'plugin_sylphen_data_bridge_admin_permission']
            );
        } catch (\Exception $e) {
        }

        OpenDxp\Cache::remove('data_bridge_installed');
    }

    /**
     * @return bool
     */
    public function canBeInstalled(): bool
    {
        $callingClasses = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $requestByOpenDxpBundleManager = false;
        foreach($callingClasses as $callingClass) {
            if(in_array($callingClass['class'], [\OpenDxp\Extension\Bundle\OpenDxpBundleManager::class], true)) {
                $requestByOpenDxpBundleManager = true;
                break;
            }
        }

        if($requestByOpenDxpBundleManager) {
            return !$this->isInstalled();
        }
        return true;
    }

    /**
     * @return bool
     */
    public function canBeUninstalled(): bool
    {
        return $this->isInstalled();
    }

    public static function getConfigPath()
    {
        $configDir = implode(DIRECTORY_SEPARATOR, array(OPENDXP_PRIVATE_VAR, 'bundles', 'SylphenDataBridge'));
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $configDir));
        }
        return $configDir;
    }

    public static function getConfigVersionPath()
    {
        if(defined('OPENDXP_PRIVATE_VAR/versions')) {
            $versionDirectory = OPENDXP_PRIVATE_VAR/versions;
        } else {
            $versionDirectory = OPENDXP_PRIVATE_VAR.'/versions';
        }

        $configDir = implode(DIRECTORY_SEPARATOR, array($versionDirectory, 'SylphenDataBridge'));
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $configDir));
        }
        return $configDir;
    }

	public static function getCachePath() {
	    $cacheDir = implode(DIRECTORY_SEPARATOR, array(\OPENDXP_SYMFONY_CACHE_DIRECTORY, 'SylphenDataBridge'));
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $cacheDir));
        }
        return $cacheDir;
    }

    public static function getResultDocumentPath()
    {
        $resultDocumentDir = implode(DIRECTORY_SEPARATOR, array(\OPENDXP_LOG_DIRECTORY, 'SylphenDataBridge'));
        if (!is_dir($resultDocumentDir) && !mkdir($resultDocumentDir, 0755, true) && !is_dir($resultDocumentDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $resultDocumentDir));
        }
        return $resultDocumentDir;
    }

    public function getMigrationVersion() {
        return '00000001';
    }

    public function migrateinstall(Schema $schema, Version $version)
    {
    }

    public function migrateUninstall(Schema $schema, Version $version) {
    }

    /**
     * @param Event $event
     */
    public static function executeMigrationsUp(Event $event)
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$phpBinaryPath = $phpFinder->find()) {
            throw new \RuntimeException('The php executable could not be found, add it to your PATH environment variable and try again');
        }

        $console = escapeshellarg('bin/console');
        if ($event->getIO()->isDecorated()) {
            $console .= ' --ansi';
        }

        $cmd = 'opendxp:bundle:install SylphenDataBridgeBundle';
        try {
            $process = Process::fromShellCommandline($phpBinaryPath.' '.$console.' '.$cmd);
        } catch (\Throwable $e) {
            $process = new Process($phpBinaryPath.' '.$console.' '.$cmd);
        }
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) use ($event) {
            $event->getIO()->write($buffer, false);
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf("An error occurred when executing the Data Bridge migration command \"%s\":\n\n%s\n\n%s", escapeshellarg($cmd), preg_replace("/\033\[[^m]*m/", '', $process->getOutput()), preg_replace("/\033\[[^m]*m/", '', $process->getErrorOutput())));
        }

        return $process;
    }

    public static function isInMaintenanceMode(): bool
    {
        try {
            $maintenanceModeHelper = \OpenDxp::getContainer()->get(MaintenanceModeHelperInterface::class);
            return $maintenanceModeHelper->isActive();
        } catch (ServiceNotFoundException $e) {
            return \OpenDxp\Tool\Admin::isInMaintenanceMode();
        }
    }
}
