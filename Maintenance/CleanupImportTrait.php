<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Maintenance;

use Sylphen\DataBridgeBundle\SylphenDataBridgeBundle;
use Sylphen\DataBridgeBundle\Command\QueueProcessorCommand;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\ApplicationLoggerDb;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use DirectoryIterator;
use Doctrine\DBAL\Exception\RetryableException;
use GlobIterator;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;
use OpenDxp;
use OpenDxp\Config;
use OpenDxp\Db;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Logger;
use OpenDxp\Mail;
use OpenDxp\Model\Asset\Folder;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Tool\Lock;
use OpenDxp\Model\User;
use OpenDxp\Model\User\Listing;
use OpenDxp\Tool;
use SplFileInfo;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Throwable;

trait CleanupImportTrait
{
    /** @var int interval to clean up importstatus table in days */
    private $cleanupInterval;

    /** @var int imports get aborted when this amount of seconds gets exceeded while processing a raw data item */
    private $abortionThreshold;

    public function __construct($cleanupInterval, $abortionThreshold)
    {
        if((int)$cleanupInterval <= 0) {
            $cleanupInterval = 30;
        }

        $this->cleanupInterval = $cleanupInterval;

        if((int)$abortionThreshold <= 0) {
            $abortionThreshold = 300;
        }
        $this->abortionThreshold = $abortionThreshold;
    }

    public function execute(): void
    {
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);
        Helper::setMemoryLimit();

        // actually we could use NOW() but database timezone is often configured wrong
        $now = new DateTimeImmutable();
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

        Logger::info('Deleting history items and log files which are older than '.$this->cleanupInterval.' days (but keep 100 most recent jobs per dataport)');

        $dataports = Dataport::getInstance();
        foreach ($dataports->find() as $dataport) {
            try {
                $applicationLogStorage = Helper::getApplicationLogStorage();
                $oldStatusEntries = PimcoreDbRepository::getInstance()->findInSql('SELECT `key`, dataport_id FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE dataport_id=? AND `lastUpdate` <= \''.$nowUtc->format('Y-m-d H:i:s').'\'-INTERVAL '.($this->cleanupInterval).' DAY ORDER BY lastUpdate DESC LIMIT 100, 1000', [$dataport['id']]);
                $statusKeys = [];
                foreach ($oldStatusEntries as $oldStatusEntry) {
                    $statusKeys[] = $oldStatusEntry['key'];
                    $this->deleteLogFile($applicationLogStorage, $oldStatusEntry['dataport_id'].'/'.$oldStatusEntry['key']);
                }

                if ($statusKeys) {
                    PimcoreDbRepository::retry(static function () use ($statusKeys) {
                        PimcoreDbRepository::getInstance()->execute(
                            'DELETE FROM `'.Installer::TABLE_IMPORTSTATUS.'` WHERE `key` IN (?)', [$statusKeys]
                        );
                    });
                }
            } catch (RetryableException $e) {
            }
        }

        Logger::info('Deleting finished history jobs without items');
        $oldStatusEntries = PimcoreDbRepository::getInstance()->findInSql(
            'SELECT `key`, dataport_id FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE `status` = \''.ImportStatus::STATUS_FINISHED.'\' AND worst_error IS NULL AND `totalItems` = 0 AND `lastUpdate` <= \''.$nowUtc->format('Y-m-d H:i:s').'\' - INTERVAL 1 DAY ORDER BY lastUpdate LIMIT 1000'
        );
        $applicationLogStorage = Helper::getApplicationLogStorage();
        $statusKeys = [];
        foreach ($oldStatusEntries as $oldStatusEntry) {
            $statusKeys[] = $oldStatusEntry['key'];
            $this->deleteLogFile($applicationLogStorage, $oldStatusEntry['dataport_id'].'/'.$oldStatusEntry['key']);
        }

        if ($statusKeys) {
            PimcoreDbRepository::retry(static function () use ($statusKeys) {
                PimcoreDbRepository::getInstance()->execute(
                    'DELETE FROM `'.Installer::TABLE_IMPORTSTATUS.'` WHERE `key` IN (?)', [$statusKeys]
                );
            });
        }

        Logger::info('Delete application_logger items for Data Bridge bundle which are older than '.$this->cleanupInterval.' days');
        PimcoreDbRepository::getInstance()->execute(
            'DELETE FROM '.ApplicationLoggerDb::TABLE_NAME.' WHERE `component` = \'Sylphen/DataBridgeBundle\' AND `timestamp` <= \''.$now->format('Y-m-d H:i:s').'\' - INTERVAL '.$this->cleanupInterval.' DAY'
        );


        if(function_exists('gzopen')) {
            Logger::info('Zipping logs to save disk space');
            $applicationLogStorage = Helper::getApplicationLogStorage();

            $unzippedLogFileIterator = $applicationLogStorage->listContents('', FilesystemReader::LIST_DEEP)->filter(static function (StorageAttributes $attributes) {
                return $attributes->isFile() && substr($attributes->path(), -3) !== '.gz';
            });

            $start = time();
            foreach ($unzippedLogFileIterator as $unzippedFileObject) {
                if(ImportStatus::getInstance()->findOne(['`key` = ?' => basename($unzippedFileObject->path()), 'status = ?' => ImportStatus::STATUS_RUNNING])) {
                    // skip files which are still in use
                    continue;
                }

                $localGzFile = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/'.basename($unzippedFileObject->path()).'.gz';
                $gzHandle = gzopen($localGzFile, 'wb9');

                $fileObjectStream = $applicationLogStorage->readStream($unzippedFileObject->path());
                while (!feof($fileObjectStream)) {
                    $data = fread($fileObjectStream, 8192);
                    gzwrite($gzHandle, $data);
                }

                gzclose($gzHandle);
                fclose($fileObjectStream);

                $gzFileHandle = fopen($localGzFile, 'rb');
                $applicationLogStorage->writeStream($unzippedFileObject->path().'.gz', $gzFileHandle);
                fclose($gzFileHandle);

                unlink($localGzFile);
                $applicationLogStorage->delete($unzippedFileObject->path());

                PimcoreDbRepository::getInstance()->execute('UPDATE '.ApplicationLoggerDb::TABLE_NAME.' SET fileobject = ? WHERE fileobject = ?', [$unzippedFileObject->path().'.gz', $unzippedFileObject->path()]);

                if(time() - $start > 60) {
                    break;
                }
            }
        }

        Logger::info('Aborting dataport runs where current batch runtime is much longer than previous ones -> probably processes got killed');

        try {
            PimcoreDbRepository::retry(function () use ($nowUtc) {
                $sql = 'SELECT `key` 
                FROM '.Installer::TABLE_IMPORTSTATUS.'
                WHERE lastUpdate < \''.$nowUtc->format('Y-m-d H:i:s').'\' - INTERVAL (GREATEST(IF(doneItems>0 AND totalItems > 100, TIMESTAMPDIFF(SECOND, startDate, lastUpdate) * 100 * 6 / doneItems, '.$this->abortionThreshold.'), '.$this->abortionThreshold.')) SECOND
                AND (totalItems < 100 OR doneItems < IF(totalItems > 100, totalItems - 100, totalItems) OR lastUpdate < \''.$nowUtc->format('Y-m-d H:i:s').'\' - INTERVAL 3600 SECOND)
                AND `status` = ?';

                $statusRepository = ImportStatus::getInstance();
                foreach ($statusRepository->findInSql($sql, [ImportStatus::STATUS_RUNNING]) as $status) {
                    $updatedRows = $statusRepository->update(
                        [
                            'status' => ImportStatus::STATUS_ABORTED,
                            'endDate' => new DateTimeImmutable(),
                        ],
                        ['key' => $status['key'], 'status' => ImportStatus::STATUS_RUNNING]
                    );

                    if ($updatedRows > 0) {
                        $statusRepository->queueRestart($status['key']);
                    }
                }
            });
        } catch (RetryableException $e) {
        }

        $dataportResources = DataportResource::getInstance();
        foreach ($dataports->find() as $dataport) {
            $sourceConfig = $dataport['sourceconfig'];
            if ($dataport['sourcetype'] === 'grid' && strpos($dataport['name'], 'Ad-hoc export ') === 0) {
                try {
                    $gridConfig = OpenDxp\Bundle\AdminBundle\Model\GridConfig::getById((int)$sourceConfig['file']);
                } catch (\Throwable $e) {
                    $gridConfig = null;
                }

                if ($gridConfig && $gridConfig->getModificationDate() < time() - 86400) {
                    // delete raw items, otherwise the foreign key restricts deleting the dataport
                    $dataportResources->deleteWhere(['dataportId' => $dataport['id']]);

                    $dataports->delete($dataport['id']);

                    $queueRepository = Queue::getInstance();
                    $queueItems = $queueRepository->find();
                    foreach ($queueItems as $queueItem) {
                        $queueItemDataportId = null;
                        if (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim) "?(\d+)"?/', $queueItem['command'], $matches)) {
                            $queueItemDataportId = $matches[1];
                        } elseif (preg_match('/^(?:data-bridge):delete-rawdata --dataport-resource-id="?(\d+)"?/', $queueItem['command'], $matches)) {
                            $queueItemDataportId = $dataportResources->get($matches[1])['dataportId'] ?? 'unknown';
                        }

                        if ($queueItemDataportId == $dataport['id']) {
                            $queueRepository->delete($queueItem['id']);
                        }
                    }

                    if (file_exists(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json')) {
                        unlink(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json');
                    }

                    $fileIterator = new \GlobIterator(Installer::getConfigVersionPath().'/dataport_'.$dataport['id'].'_*.json', \GlobIterator::SKIP_DOTS);
                    /** @var SplFileInfo $fileInfo */
                    foreach ($fileIterator as $fileInfo) {
                        unlink($fileInfo->getPathname());
                    }
                }
            }

            Logger::info('Cleaning up archive and temporary files from dataport '.$dataport['id']);

            $oldestModificationTimestamp = (new \DateTime())->sub(new \DateInterval('P'.$this->cleanupInterval.'D'))->getTimestamp();

            if (!empty($sourceConfig['archiveFolder'])) {
                $archiveFolder = Folder::getByPath($sourceConfig['archiveFolder']);
                if ($archiveFolder instanceof Folder) {
                    $oldArchiveFilesListing = new OpenDxp\Model\Asset\Listing();
                    $oldArchiveFilesListing->addConditionParam('path LIKE ?', $archiveFolder->getFullPath().'/%');
                    $oldArchiveFilesListing->addConditionParam('modificationDate < ?', $oldestModificationTimestamp);
                    $oldArchiveFilesListing->addConditionParam('type != \'folder\'');
                    $oldArchiveFilesListing->setLimit(1000);
                    foreach ($oldArchiveFilesListing->load() as $archivedFile) {
                        $archivedFile->delete();
                    }

                    $oldArchiveFolderListing = new OpenDxp\Model\Asset\Listing();
                    $oldArchiveFolderListing->addConditionParam('path LIKE ?', $archiveFolder->getFullPath().'/%');
                    $oldArchiveFolderListing->addConditionParam('type = \'folder\'');
                    $oldArchiveFolderListing->addConditionParam('modificationDate < ?', $oldestModificationTimestamp);
                    $oldArchiveFolderListing->addConditionParam('id NOT IN (SELECT parentId FROM assets)');
                    foreach ($oldArchiveFolderListing->load() as $archivedFolder) {
                        $archivedFolder->delete();
                    }
                } elseif (strpos($sourceConfig['archiveFolder'], 'ftp://') !== 0 && strpos($sourceConfig['archiveFolder'], 'ftps://') !== 0 && strpos($sourceConfig['archiveFolder'], 'sftp://') !== 0 && strpos($sourceConfig['archiveFolder'], 's3://') !== 0 && @\is_dir(
                        $sourceConfig['archiveFolder']
                    )) {
                    foreach (new \DirectoryIterator($sourceConfig['archiveFolder']) as $fileInfo) {
                        if ($fileInfo->getMTime() < $oldestModificationTimestamp) {
                            @unlink($fileInfo->getPathname());
                        }
                    }
                }
            }

            // error suppression because otherwise error "open_basedir restriction in effect" could appear
            if (isset($sourceConfig['file']) && strpos($sourceConfig['file'], 'ftp://') !== 0 && strpos($sourceConfig['file'], 'ftps://') !== 0 && strpos($sourceConfig['file'], 'sftp://') !== 0 && strpos($sourceConfig['file'], 's3://') !== 0 && @is_dir($sourceConfig['file']) && @is_dir(
                    $sourceConfig['file'].'/archive'
                )) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceConfig['file'].'/archive', \RecursiveDirectoryIterator::SKIP_DOTS)) as $fileInfo) {
                    if ($fileInfo->getMTime() < $oldestModificationTimestamp) {
                        @unlink($fileInfo->getPathname());
                    }
                }
            }

            $sourceConfig = $dataport['sourceconfig'];
            $usesSourceInRawData = false;
            foreach (($sourceConfig['fields'] ?? []) as $field) {
                if (($field['xpath'] ?? $field['column'] ?? $field['json-path'] ?? $field['length'] ?? null) === '__source') {
                    $usesSourceInRawData = true;
                    break;
                }
            }
            // prevent deleting files which are necessary for a running import, e.g. files which got extracted from a zip file
            $dataportIsCurrentlyRunning = ImportStatus::getInstance()->findOne(['dataport_id = ?' => $dataport['id'], 'status = ?' => ImportStatus::STATUS_RUNNING]);
            $fileIterator = new \GlobIterator(\OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataport['id'].'_*', \GlobIterator::SKIP_DOTS);
            if (!$dataportIsCurrentlyRunning) {
                /** @var \SplFileInfo $fileInfo */
                foreach ($fileIterator as $fileInfo) {
                    try {
                        if ($fileInfo->getMTime() < time() - 300) {
                            $fileUsedInRawData = false;
                            if ($usesSourceInRawData) {
                                $fileUsedInRawData = PimcoreDbRepository::getInstance()->findOneInSql(
                                    'SELECT 1 
                                    FROM '.Installer::TABLE_RAWITEMDATA.' raw_item_data 
                                    INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON raw_item_data.rawItemId=rawitem.id 
                                    INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id 
                                    WHERE dataport_resource.dataportId=?
                                    AND raw_item_data.value = ?
                                    LIMIT 1',
                                    [$dataport['id'], $fileInfo->getPathname()]
                                );
                            }
                            if (!$fileUsedInRawData) {
                                if (is_dir($fileInfo->getPathname())) {
                                    recursiveDelete($fileInfo->getPathname());
                                } else {
                                    @unlink($fileInfo->getPathname());
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }

            // response files
            $fileIterator = new \GlobIterator(Installer::getResultDocumentPath().'/response_'.$dataport['id'].'_*', \GlobIterator::SKIP_DOTS);
            /** @var \SplFileInfo $fileInfo */
            foreach ($fileIterator as $fileInfo) {
                try {
                    if ($fileInfo->getATime() < time() - 86400) {
                        @unlink($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                }
            }

            // result cache
            $fileIterator = new \GlobIterator(Installer::getResultDocumentPath().'/result_'.$dataport['id'].'_*', \GlobIterator::SKIP_DOTS);
            /** @var \SplFileInfo $fileInfo */
            foreach ($fileIterator as $fileInfo) {
                try {
                    if ($fileInfo->getATime() < time() - 86400 * 3) {
                        @unlink($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                }
            }

            foreach (new GlobIterator(Installer::getConfigVersionPath().'/dataport_'.$dataport['id'].'_*.json') as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                try {
                    if ($fileInfo->getMTime() < time() - 86400 * 90) {
                        @unlink($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                }
            }

            // temporary import files (actually get cleaned up on register_shutdown but not if the process gets killed)
            $fileIterator = new \GlobIterator(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/temp-dd-*', \GlobIterator::SKIP_DOTS);
            /** @var \SplFileInfo $fileInfo */
            foreach ($fileIterator as $fileInfo) {
                try {
                    if ($fileInfo->getATime() < time() - 86400 * 3) {
                        @unlink($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                }
            }

            // temporary FileSystemParser import files
            if (is_dir(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/SylphenDataBridge')) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/SylphenDataBridge', \RecursiveDirectoryIterator::SKIP_DOTS)) as $fileInfo) {
                    try {
                        if ($fileInfo->getATime() < time() - 86400 * 3) {
                            @unlink($fileInfo->getPathname());
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }

            // other temp files, for example created by Pimcore itself
            $fileIterator = new \GlobIterator(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/temp-file-*', \GlobIterator::SKIP_DOTS);
            /** @var \SplFileInfo $fileInfo */
            foreach ($fileIterator as $fileInfo) {
                try {
                    if ($fileInfo->getATime() < time() - 86400 * 30) {
                        @unlink($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        Logger::info('Deleting dataport resources which did not get accessed within '.$this->cleanupInterval.' days');

        $dataportResourceIdsUnused = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT id FROM '.Installer::TABLE_DATAPORT_RESOURCE.' WHERE lastAccess < NOW() - INTERVAL ? DAY AND id IN (SELECT dataport_resource_id FROM '.Installer::TABLE_RAWITEM.')', [$this->cleanupInterval]);
        foreach ($dataportResourceIdsUnused as $dataportResourceId) {
            do {
                $deletedRows = $dataportResources->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id = ? LIMIT 10000', [$dataportResourceId]);
            } while ($deletedRows === 10000);
        }

        PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_DATAPORT_RESOURCE.' WHERE lastAccess < NOW() - INTERVAL ? DAY LIMIT 1000', [$this->cleanupInterval]);

        $dataportResourcesWithUnusedGridExportTemporaryTable = PimcoreDbRepository::getInstance()->findInSql('SELECT id, resource FROM '.Installer::TABLE_DATAPORT_RESOURCE.' WHERE (resource LIKE \'%data_bridge_grid_export_%\' OR resource LIKE \'%data_director_grid_export_%\') AND lastAccess < NOW() - INTERVAL 1 DAY');
        $dataportResources = DataportResource::getInstance();
        foreach ($dataportResourcesWithUnusedGridExportTemporaryTable as $dataportResourceWithUnusedGridExportTemporaryTable) {
            $resource = \json_decode($dataportResourceWithUnusedGridExportTemporaryTable['resource'], true);
            if (strpos($resource['file'], 'data_bridge_grid_export_') !== false || strpos($resource['file'], 'data_director_grid_export_') !== false) {
                PimcoreDbRepository::retry(function () use ($dataportResources, $dataportResourceWithUnusedGridExportTemporaryTable, $resource) {
                    $dataportResources->delete($dataportResourceWithUnusedGridExportTemporaryTable['id']);
                    PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_QUEUE.' WHERE command LIKE ?', ['%'.$resource['file'].'%']);
                });
            }
        }

        Logger::info('Deleting tag assignments which have not been reassigned for '.$this->cleanupInterval.' days');
        PimcoreDbRepository::getInstance()->execute('DELETE FROM `tags_assignment` WHERE dd_modificationDate < NOW() - INTERVAL ? DAY', [$this->cleanupInterval]);

        Logger::info('Deleting Data Bridge tags which are not assigned to any element');
        $dataBridgeTagId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM tags WHERE name=?', ['Data Bridge']);
        if ($dataBridgeTagId) {
            $tagsWithoutChildren = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT tags.id FROM tags LEFT JOIN tags parent_tags ON tags.id=parent_tags.parentId WHERE parent_tags.parentId IS NULL AND tags.idPath LIKE ?', ['/'.$dataBridgeTagId.'/%']);

            PimcoreDbRepository::getInstance()->execute('DELETE `tags` FROM `tags` LEFT JOIN `tags_assignment` ON `tags`.`id`=tags_assignment.tagid WHERE tags_assignment.tagid IS NULL AND `tags`.`id` IN (?)', [$tagsWithoutChildren]);
        }

        Logger::info('Deleting logged error notification mails');
        foreach ($dataports->find() as $dataport) {
            $oldEmails = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT id FROM email_log WHERE sentDate < ? AND subject LIKE ?', [time() - $this->cleanupInterval * 86400, '['.$dataport['name'].'] %']);
            foreach ($oldEmails as $oldEmailId) {
                if (file_exists(OPENDXP_PRIVATE_VAR.'/email/email-'.$oldEmailId.'-html.log')) {
                    unlink(OPENDXP_PRIVATE_VAR.'/email/email-'.$oldEmailId.'-html.log');
                }

                if (file_exists(OPENDXP_PRIVATE_VAR.'/email/email-'.$oldEmailId.'-txt.log')) {
                    unlink(OPENDXP_PRIVATE_VAR.'/email/email-'.$oldEmailId.'-txt.log');
                }
            }
            PimcoreDbRepository::getInstance()->execute('DELETE FROM email_log WHERE sentDate < ? AND subject LIKE ?', [time() - $this->cleanupInterval * 86400, '['.$dataport['name'].'] %']);
        }

        Logger::info('Deleting old edit_lock records');
        PimcoreDbRepository::getInstance()->execute('DELETE FROM edit_lock WHERE date<?', [time() - 3600]);

        if (OpenDxp::getContainer()->getParameter('data_bridge.config')['queue_processing']['automatic_start'] && !Installer::isInMaintenanceMode()) {
            $queue = Queue::getInstance();

            $queueItemExists = $queue->findOne(['queued_at < ?' => (new DateTimeImmutable('@'.(time() - 60), new DateTimeZone('UTC')))->format('Y-m-d H:i:s')], 'queued_at');

            if ($queueItemExists) {
                if ((new DateTimeImmutable($queueItemExists['queued_at'], new DateTimeZone('UTC')))->getTimestamp() <= time() - 86400 * 3) {
                    $phpProcesses = Cli::exec('ps -ww -C '.basename(Cli::getPhpCli()).' -o pid=,etimes=,user=,args=');

                    foreach (explode(PHP_EOL, $phpProcesses) as $process) {
                        $processParts = explode(' ', preg_replace('/\s{2,}/', ' ', trim($process)));

                        if (count($processParts) >= 3 && $processParts[2] === get_current_user()) {
                            $processParts = [
                                $processParts[0],
                                $processParts[1],
                                implode(' ', array_slice($processParts, 3))
                            ];
                            if (strpos($processParts[2], 'data-bridge:process-queue') !== false && $processParts[1] > 86400) {
                                Logger::info('Queue Processor hangs -> Restarting it');
                                Cli::exec('kill '.$processParts[0]);

                                $queueItemExists['started_at'] = null;
                            }
                        }
                    }
                }

                if (!$queueItemExists['started_at']) {
                    try {
                        $lockFactory = OpenDxp::getContainer()->get(LockFactory::class);
                    } catch (\Throwable $e) {
                        $store = new FlockStore(OPENDXP_SYSTEM_TEMP_DIRECTORY);
                        $lockFactory = new \Symfony\Component\Lock\Factory($store);
                    }

                    $queueProcessorLock = $lockFactory->createLock(QueueProcessorCommand::LOCK_KEY);

                    $queueProcessorRunning = !$queueProcessorLock->acquire(false);
                    if (!$queueProcessorRunning) {
                        $queueProcessorLock->release();

                        if (OpenDxp::getContainer()->getParameter('data_bridge.config')['queue_processing']['automatic_start']) {
                            Logger::info('Starting queue processing');
                            Queue::startQueueProcessor();
                            sleep(5);
                            $queueItemExists = $queue->findOne(['id = ?' => $queueItemExists['id']]);
                        } else {
                            $queueItemExists = false;
                        }

                        if ($queueItemExists) {
                            $queueProcessorRunning = !$queueProcessorLock->acquire(false);
                            if (!$queueProcessorRunning) {
                                $queueProcessorLock->release();
                            }
                        } else {
                            $queueProcessorRunning = true;
                        }
                    }

                    if (!$queueProcessorRunning) {
                        Logger::info('Starting queue processing failed, sending mail to admins');

                        $domain = parse_url(Helper::getHostUrl(), PHP_URL_HOST);
                        if (empty($domain)) {
                            $domain = Helper::getPimcoreSystemConfiguration('general')['domain'];
                        }
                        if (empty($domain)) {
                            $domain = Tool::getHostname();
                        }

                        $mail = new \Sylphen\DataBridgeBundle\lib\Pim\Mail();
                        if (empty($mail->getFrom())) {
                            $mailFrom = Helper::getPimcoreSystemConfiguration('email')['debug']['email_addresses'];
                            if (empty($mailFrom) && $domain) {
                                $mailFrom = 'no-reply@'.$domain;
                            }
                            if (empty($mailFrom)) {
                                $mailFrom = 'no-reply@Pimcore';
                            }

                            if (method_exists($mail, 'from')) {
                                $mail->from($mailFrom);
                            } else {
                                $mail->setFrom($mailFrom);
                            }
                        }

                        $mail->setSubject('Data Bridge queue processor not running '.($domain ? ' ('.$domain.')' : ''));

                        $configFilePath = OPENDXP_PROJECT_ROOT.'/config/services.yaml';

                        $html = OpenDxp::getContainer()->get('opendxp.templating.engine.delegating')->render(
                            '@SylphenDataBridge/email-queue-processor-not-running.html.twig',
                            [
                                'startQueueProcessorUrl' => Helper::getHostUrl().OpenDxp::getContainer()->get('router')->generate('start-queue-processor'),
                                'configFilePath' => $configFilePath,
                                'opendxpRootFolder' => OPENDXP_PROJECT_ROOT,
                                'webserverUser' => get_current_user(),
                                'queueItem' => $queueItemExists
                            ]
                        );

                        $mail->setBodyHtml($html);

                        $dataBridgeErrorRecipients = [[]];
                        foreach ($dataports->find() as $dataport) {
                            $dataBridgeErrorRecipients[] = $dataport['targetconfig']['errorRecipients'];
                        }
                        $dataBridgeErrorRecipients = array_unique(array_filter(array_merge(...$dataBridgeErrorRecipients)));

                        $userListing = new Listing();
                        $userListing->addConditionParam('admin=1 AND active=1 AND email!=\'\' AND email IS NOT NULL');
                        if ($dataBridgeErrorRecipients) {
                            $userListing->addConditionParam('id IN ('.rtrim(str_repeat('?,', count($dataBridgeErrorRecipients)), ',').')', $dataBridgeErrorRecipients);
                        }
                        foreach ($userListing as $user) {
                            try {
                                $mail->addTo($user->getEmail());
                            } catch (\Throwable $e) {
                            }
                        }

                        try {
                            $mail->send();
                        } catch (\Exception $e) {
                        }
                    } else {
                        foreach ($queue->find(['queued_at < ?' => (new DateTimeImmutable('@'.(time() - 86400 * 3), new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]) as $nonExecutableTask) {
                            $queue->delete($nonExecutableTask['id']);
                        }
                    }
                }
            }
        }

        if (!PimcoreDbRepository::getInstance()->findOneInSql('SHOW INDEX FROM '.ApplicationLoggerDb::TABLE_NAME.' WHERE Key_name = \'sylphen_data_bridge_fileobject\'')) {
            Logger::info('Add indexes to ApplicationLoggerBundle');
            PimcoreDbRepository::getInstance()->execute('ALTER TABLE '.ApplicationLoggerDb::TABLE_NAME.' ADD INDEX `sylphen_data_bridge_fileobject` (`fileObject` (191))');
        }

        if(!PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM information_schema.table_constraints WHERE table_name=\''.Installer::TABLE_RAWITEM.'\' AND constraint_name=\'fk_rawItem_dataport_resource\'')) {
            Logger::info('Checking database indexes / foreign keys ('.Installer::TABLE_RAWITEM.')');
            do {
                $deletedRows = PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id NOT IN (SELECT id FROM '.Installer::TABLE_DATAPORT_RESOURCE.') LIMIT 10000');
            } while ($deletedRows === 10000);

            PimcoreDbRepository::getInstance()->execute('ALTER TABLE '.Installer::TABLE_RAWITEM.' ADD CONSTRAINT `fk_rawItem_dataport_resource` FOREIGN KEY (`dataport_resource_id`) REFERENCES '.Installer::TABLE_DATAPORT_RESOURCE.' (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }

        if (!PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM information_schema.table_constraints WHERE table_name=\''.Installer::TABLE_RAWITEMDATA.'\' AND constraint_name=\'fk_rawitemdata_rawitem\'')) {
            Logger::info('Checking database indexes / foreign keys ('.Installer::TABLE_RAWITEMDATA.')');
            do {
                $deletedRows = PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_RAWITEMDATA.' WHERE rawItemId NOT IN (SELECT id FROM '.Installer::TABLE_RAWITEM.') LIMIT 10000');
            } while ($deletedRows === 10000);

            PimcoreDbRepository::getInstance()->execute('ALTER TABLE `'.Installer::TABLE_RAWITEMDATA.'` ADD CONSTRAINT `fk_rawitemdata_rawitem` FOREIGN KEY (`rawItemId`) REFERENCES '.Installer::TABLE_RAWITEM.' (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }


        $bundles = OpenDxp::getContainer()->getParameter('kernel.bundles');
        $backendSearchActive = isset($bundles['OpenDxpSimpleBackendSearchBundle']);

        if ($backendSearchActive) {
            $messengerMessagesTableExists = PimcoreDbRepository::getInstance()->findOneInSql('SHOW TABLES LIKE \'messenger_messages\';');
            if ($messengerMessagesTableExists) {
                $oldSearchIndexJobsExist = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM messenger_messages WHERE body LIKE ? AND available_at < NOW() - INTERVAL 1 DAY LIMIT 1', ['%SearchBackendMessage%']);

                if($oldSearchIndexJobsExist) {
                    Logger::info('Helping backend search to index missing data');
                    $allElementsProcessed = true;
                    foreach (['object', 'asset', 'document'] as $elementType) {
                        try {
                            $elementIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                                'SELECT elements.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id FROM `'.$elementType.'s` elements LEFT JOIN search_backend_data ON elements.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').'=search_backend_data.id AND search_backend_data.mainType=? WHERE search_backend_data.id IS NULL LIMIT 10000',
                                [$elementType]
                            );

                            $elementsTotal = count($elementIds);
                            if($elementsTotal === 0) {
                                $elementIds = PimcoreDbRepository::getInstance()->findColumnInSql('WITH RECURSIVE elements AS (
                                    SELECT current.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id, current.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').' AS modificationDate, current.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId
                                    FROM `'.$elementType.'s` current
                                    WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').'=1
                                    UNION ALL
                                    SELECT descendants.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').', GREATEST(elements.modificationDate, descendants.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').'), descendants.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').'
                                    FROM `'.$elementType.'s` descendants
                                    INNER JOIN elements ON descendants.'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' = elements.id
                                )
                                SELECT elements.id
                                FROM elements
                                LEFT JOIN search_backend_data ON elements.id=search_backend_data.id
                                    AND search_backend_data.mainType=\''.$elementType.'\'
                                WHERE search_backend_data.id IS NULL
                                    OR search_backend_data.modificationDate < elements.modificationDate
                                ORDER BY elements.modificationDate DESC
                                LIMIT 10000'
                                );
                                $elementsTotal = count($elementIds);

                                if ($elementsTotal === 10000) {
                                    $allElementsProcessed = false;
                                }
                            } else {
                                $allElementsProcessed = false;
                            }

                            foreach ($elementIds as $i => $elementId) {

                                if ($i % 100 === 0) {
                                    \OpenDxp::collectGarbage();
                                    Logger::info('Processing '.$elementType.': '.min($i + 100, count($elementIds)).'/'.$elementsTotal);
                                }

                                try {
                                    $element = Service::getElementById($elementType, $elementId);
                                    if (!$element instanceof OpenDxp\Model\Element\ElementInterface) {
                                        continue;
                                    }
                                    $searchEntry = new OpenDxp\Bundle\SimpleBackendSearchBundle\Model\Search\Backend\Data();
                                    $searchEntry->setDataFromElement($element);
                                    $searchEntry->save();
                                } catch (\Throwable $e) {
                                    Logger::err((string)$e);
                                }
                            }
                        } catch (\Exception $e) {
                        }
                    }

                    if ($allElementsProcessed) {
                        // search index is up to date
                        PimcoreDbRepository::getInstance()->execute('DELETE FROM messenger_messages WHERE body LIKE ?', ['%SearchBackendMessage%']);
                    }
                }
            }
        }

        if (defined('OPENDXP_PRIVATE_VAR/versions')) {
            $versionDirectory = OPENDXP_PRIVATE_VAR/versions;
        } else {
            $versionDirectory = OPENDXP_PRIVATE_VAR.'/versions';
        }

        foreach(['asset','document','object'] as $elementType) {
            $elementVersionDirectory = $versionDirectory.'/'.$elementType;
            if (is_dir($elementVersionDirectory)) {
                $groupDirectories = [];
                foreach (new DirectoryIterator($elementVersionDirectory) as $groupDirectoryFileInfo) {
                    if ($groupDirectoryFileInfo->isDir() && !$groupDirectoryFileInfo->isDot()) {
                        $groupDirectories[] = $groupDirectoryFileInfo->getPathname();
                    }
                }

                if(count($groupDirectories) === 0) {
                    continue;
                }

                shuffle($groupDirectories);
                $groupDirectory = $groupDirectories[0]; // only take one group to not let this job run forever

                Logger::info('Deleting versions of '.$elementType.'s which do not exist anymore');
                $elementIds = [];
                foreach (new DirectoryIterator($groupDirectory) as $elementVersionDirectoryFileInfo) {
                    if ($elementVersionDirectoryFileInfo->isDir() && !$elementVersionDirectoryFileInfo->isDot()) {
                        $elementId = $elementVersionDirectoryFileInfo->getFilename();
                        $elementIds[] = $elementId;
                    }
                }

                $existingElements = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' FROM '.$elementType.'s WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (?)', [$elementIds]);
                foreach(array_diff($elementIds, $existingElements) as $elementId) {
                    Logger::info('Deleting versions in '.$groupDirectory.'/'.$elementId);
                    recursiveDelete($groupDirectory.'/'.$elementId);
                }
            }
        }
    }

    private function deleteLogFile(FilesystemOperator $applicationLogStorage, string $logFilePath): void
    {
        try {
            if ($applicationLogStorage->fileExists($logFilePath)) {
                Logger::info('Deleting log file ' . $logFilePath);
                $applicationLogStorage->delete($logFilePath);
            } else {
                $logFilePath .= '.gz';
                if ($applicationLogStorage->fileExists($logFilePath)) {
                    Logger::info('Deleting zipped log file ' . $logFilePath);
                    $applicationLogStorage->delete($logFilePath);
                }
            }
        } catch (Throwable $e) {
            if (defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY')) {
                @unlink(rtrim(OPENDXP_LOG_FILEOBJECT_DIRECTORY, '/') . '/' . $logFilePath);
            } else {
                @unlink(OPENDXP_PRIVATE_VAR . '/application-logger/' . $logFilePath);
            }
        }
    }
}