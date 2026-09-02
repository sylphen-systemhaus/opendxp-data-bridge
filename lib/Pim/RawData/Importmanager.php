<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\RawData;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\EmailReportingLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\RawItemLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\WorstErrorImportStatusLogger;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\DeadlockPrevention;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateInterval;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InSquare\OpendxpProcessManagerBundle\Model\MonitoringItem;
use ErrorException;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Db;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Model\User;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profiler;

class Importmanager implements LoggerAwareInterface {
    use LoggerAwareTrait;

	private $_overrideFile;
	private $_removeFileAfterImport = false;
	private $locale;
    private $limit = '0,INF';

	/** @var bool  */
	private $force = false;

	/** @var MonitoringItem */
	private $monitoringItem;

    public function __construct(LoggerInterface $logger, ?Profiler $profiler)
    {
        $this->logger = $logger;

        // null object if no monitoring item has been set
        $this->monitoringItem = new class {
            const STATUS_FAILED = 'failed';

            public function __call($name, $arguments)
            {
                return $this;
            }
        };

        if($profiler) {
            $profiler->disable();
        }
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Importiert alle Rohdaten aus der Datenquelle des Datenports
     * @param int $id dataport ID
     * @param int $importType import type
     * @param null|int $dataportResourceId manually specify dataport resource id (skip automatic detection via import source / locale)
     * @param null|string $statusKey
     *
     * @return int Dataport Resource Id
     */
	public function importDataport($id, $importType = ImportStatus::TYPE_RAWDATA, $dataportResourceId = null, $statusKey = null) {
        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);
        gc_enable();
        if (method_exists(Db::getConnection()->getConfiguration(), 'setSQLLogger')) {
            Db::getConnection()->getConfiguration()->setSQLLogger(null);
        }
        if (class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }
        if (method_exists(\Doctrine\Deprecations\Deprecation::class, 'disable')) {
            \Doctrine\Deprecations\Deprecation::disable();
        }

        $statusModel = ImportStatus::getInstance();

        if ($statusKey === null) {
            $statusKey = uniqid('', true);
        }

        $abortFunction = function ($restart = true) use ($statusKey, $statusModel) {
            $updatedRows = $statusModel->update(
                [
                    'status' => ImportStatus::STATUS_ABORTED,
                    'endDate' => new \DateTimeImmutable(),
                ],
                ['key' => $statusKey, 'status' => ImportStatus::STATUS_RUNNING]
            );

            if ($updatedRows > 0) {
                $this->logger->error('Process got aborted');

                if ($restart && $statusModel->queueRestart($statusKey)) {
                    $this->logger->error('Command restarted because original process was terminated unintentionally');
                }

                $this->monitoringItem->setStatus($this->monitoringItem::STATUS_FAILED);
                $this->monitoringItem->setReportedDate(time());
                $this->monitoringItem->setMessage('Process got aborted');
                $this->monitoringItem->save();

                http_response_code(500);
            }

            while (Db::get()->isTransactionActive()) {
                Db::get()->commit();
            }

            if ($updatedRows > 0) {
                exit(1);
            }
        };
        register_shutdown_function($abortFunction);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $cliAbortFunction = static function () use ($abortFunction) {
                $abortFunction();
                exit(1);
            };
            pcntl_signal(SIGINT, $cliAbortFunction);
            pcntl_signal(SIGTERM, $cliAbortFunction);
            pcntl_signal(SIGHUP, $cliAbortFunction);
        }

		$table = Dataport::getInstance();
		$dataport = $table->get($id);

		if (!$dataport) {
            $this->logger->error('Dataport #'.$id.' not found');
            return null;
		}

        $sourceConfig = $dataport['sourceconfig'];

        $resource = [];
        try {
            $parser = $table->getParser($id, $this->logger);
        } catch(\Throwable $e) {
            $this->logger->error($e->getMessage());
            return null;
        }

        $rawItems = RawItem::getInstance();

        $dataportResources = DataportResource::getInstance();

        $otherDataportResourceIds = [];
        if($dataportResourceId !== null) {
            $otherDataportResourceIds = explode(',', $dataportResourceId);

            $otherDataportResourceIds = array_column($dataportResources->find(['id IN ('.rtrim(str_repeat('?,', count($otherDataportResourceIds)), ',').')' => array_map('intval', $otherDataportResourceIds)]), 'id');

            $dataportResourceId = array_shift($otherDataportResourceIds);

            if(!$dataportResourceId) {
                $this->logger->error('Dataport Resource Id does not exist (anymore)');
                return null;
            }
        }

        $targetConfig = $dataport['targetconfig'];
        if($dataportResourceId !== null && empty($targetConfig['itemClass']) && empty($sourceConfig['incrementalExport'])) {
            // complete import for automatic exports whose raw data has never been imported completely yet
            $rawItemExists = $rawItems->findOne(['dataport_resource_id = ?' => $dataportResourceId]);
            if(!$rawItemExists) {
                $dataportResource = $dataportResources->get($dataportResourceId);
                if($dataportResource) {
                    $resource = \json_decode($dataportResource['resource'], true);
                    $this->setOverrideFile($resource['file'] ?? null);
                    $this->locale = $resource['locale'] ?? null;
                } else {
                    $dataportResourceId = null;
                }
            }
        }

		if($this->getOverrideFile() && isset($sourceConfig['file']) && $this->getOverrideFile() !== $sourceConfig['file'] && \method_exists($parser, 'setSourceFile')) {
            $parser->setSourceFile($this->getOverrideFile());
        }
        $resource['file'] = $parser->getResource();

		if(method_exists($parser, 'setForce')) {
		    $parser->setForce($this->getForce());
        }

        $maxResults = INF;
        $limit = explode(',', $this->getLimit());
        $offset = (int)$limit[0];
        if ($limit[1] !== 'INF') {
            $maxResults = (int)$limit[1];
        }

        $parser->setLimit($maxResults);
        $parser->setOffset($offset);

        if($this->locale) {
            $resource['locale'] = $this->locale;
            \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($this->locale);
        } elseif($dataport['sourcetype'] === 'pimcore') {
            $resource['locale'] = Helper::getRequest()->getLocale();
            if($resource['locale'] === null) {
                $resource['locale'] = Tool::getDefaultLanguage();
            }
        }

        if(!empty($resource['locale']) && !Tool::isValidLanguage($resource['locale'])) {
            foreach (OpenDxp\Tool::getValidLanguages() as $languageCandidate) {
                if (strpos($languageCandidate, $resource['locale'].'_') === 0) {
                    $resource['locale'] = $languageCandidate;
                    \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($resource['locale']);
                    break;
                }
            }
        }

        $request = Helper::getRequest();
        if (empty($sourceConfig['incrementalExport'])) {
            $parameters = [Helper::getEnvironmentVariables()];
            if ($request instanceof Request) {
                $parameters[] = $request->request->all();
                $parameters[] = $request->query->all();
                $parameters = array_diff_key(
                    $parameters,
                    array_flip([
                        'dataportId',
                        'importType',
                        'force',
                        'csrfToken',
                        'bundle',
                        'importfile',
                        'locale',
                        'apikey'])
                );
                $parameters[] = $request->attributes->all();
            }
            $resource['parameters'] = array_replace(...$parameters);
            $user = Helper::getUser();
            if($user->getId()) {
                $resource['parameters']['userId'] = $user->getId();
            }
            $resource['parameters'] = array_filter($resource['parameters'], static function($parameterValue) {
                return $parameterValue !== null && $parameterValue !== '';
            });

            $fieldMappings = Fieldmapping::getInstance()->find(
                [
                    'dataportId = ?' => $dataport['id']
                ]
            );
            $fileTwigVariables = [];
            if(!empty($sourceConfig['file'])) {
                $fileTwigVariables = \Sylphen\DataBridgeBundle\lib\Pim\Item\Importer::getTwigVariables($sourceConfig['file']);
            } elseif($dataport['sourcetype'] === 'object-wizard') {
                $fileTwigVariables = array_map(static function($field) {
                    return $field['definition']['name'];
                }, $sourceConfig['fields']);
            }

            foreach(array_keys($resource['parameters']) as $resourceParameter) {
                $keepParameter = in_array($resourceParameter, $fileTwigVariables, true);

                if(!$keepParameter) {
                    foreach($sourceConfig['fields'] as $rawDataField) {
                        foreach($rawDataField as $rawDataFieldParameter) {
                            if(is_string($rawDataFieldParameter) && in_array($resourceParameter, \Sylphen\DataBridgeBundle\lib\Pim\Item\Importer::getTwigVariables($rawDataFieldParameter), true)) {
                                $keepParameter = true;
                                break 2;
                            }
                        }
                    }
                }

                if(!$keepParameter) {
                    foreach($fieldMappings as $fieldMapping) {
                        try {
                            if ($fieldMapping['calculation'] && in_array($resourceParameter, \Sylphen\DataBridgeBundle\lib\Pim\Item\Importer::getTwigVariables($fieldMapping['calculation']), true)) {
                                $keepParameter = true;
                                break;
                            }
                        } catch(\Throwable $e) {
                        }
                    }
                }

                if(!$keepParameter) {
                    unset($resource['parameters'][$resourceParameter]);
                }
            }
        }

        if($dataportResourceId === null) {
            if(isset($resource['parameters'])) {
                ksort($resource['parameters']);
            }

            $dataportResourceId = $dataportResources->create(
                [
                    'dataportId' => $id,
                    'resource' => \json_encode($resource, JSON_UNESCAPED_SLASHES)
                ]
            )['id'];

            if(!empty($sourceConfig['autoImport']) && empty($sourceConfig['incrementalExport']) && empty($targetConfig['itemClass'])) {
                $rawItemExists = $rawItems->findOne(['dataport_resource_id = ?' => $dataportResourceId]);
                if ($rawItemExists) {
                    $lastCompleteRawDataRun = $statusModel->findOne([
                        'dataport_resource_id = ?' => $dataportResourceId, 
                        'importType & ? = '.ImportStatus::TYPE_COMPLETE => ImportStatus::TYPE_COMPLETE, 
                        'totalItems > ?' => 0,
                        'status = ?' => ImportStatus::STATUS_FINISHED,
                    ], 'startDate DESC');
                    
                    $rawItemExists = ($lastCompleteRawDataRun['totalItems'] ?? nulL) === ($lastCompleteRawDataRun['doneItems'] ?? null);
                }

                if($rawItemExists) {
                    if($parser->gotoNextImportResource()) {
                        $this->importDataport($id, $importType, null, $statusKey);
                    }

                    $dbNow = new \DateTime();
                    $statusModel->update(array(
                        'status' => ImportStatus::STATUS_FINISHED,
                        'dataport_resource_id' => $dataportResourceId,
                        'lastUpdate' => $dbNow,
                        'endDate' => $dbNow,
                        'doneItems' => 0,
                        'totalItems' => 0,
                    ), ['key' => $statusKey]);

                    return $dataportResourceId;
                }
            }
        }

        if(\method_exists($parser, 'removeFileAfterImport')) {
            $parser->removeFileAfterImport($this->_removeFileAfterImport === true);
        }

        $dbNow = new \DateTime();

        if(method_exists($parser, 'setStatusKey')) {
            $parser->setStatusKey($statusKey);
        }

        $logFileObject = $statusModel->create([
            'key' => $statusKey,
            'dataport_id' => $id,
            'dataport_resource_id' => $dataportResourceId,
            'startDate' => $dbNow,
            'lastUpdate' => $dbNow,
            'importType' => $importType,
        ]);

        if (method_exists($this->logger, 'setLogFileObject')) {
            $this->logger->setLogFileObject($logFileObject);
        }

        if (method_exists($this->logger, 'addLogger')) {
            if (!$this->getForce()) {
                $reportingLogger = OpenDxp::getContainer()->get(EmailReportingLogger::class);
                $reportingLogger->setDataportId($id);
                $reportingLogger->setLogFileObject($logFileObject);
                $this->logger->addLogger($reportingLogger);
            }

            $worstErrorLogger = OpenDxp::getContainer()->get(WorstErrorImportStatusLogger::class);
            $this->logger->addLogger($worstErrorLogger);
            $worstErrorLogger->setStatusKey($statusKey);

            $this->logger->addLogger(RawItemLogger::getInstance());
        }


        $done = 0;

        if(empty($sourceConfig['autoImport']) || !empty($targetConfig['itemClass'])) {
            try {
                $initAction = Fieldmapping::getInstance()->findOne(
                    [
                        'dataportId = ?' => $dataport['id'],
                        'fieldName = ?' => '__init_action'
                    ]
                );
                if (!empty($initAction['calculation']) && CallbackFunction::isEngineAvailable($dataport['targetconfig']['javascriptEngine'])) {
                    /** @var \Sylphen\DataBridgeBundle\lib\Pim\Helper $helper */
                    $helper = Helper::getInstance();
                    $helper->setLogger($this->logger);
                    $result = $helper->runInitFunction($dataport, $statusKey);

                    $websiteSettingName = 'next-execution-dataport-'.$dataport['id'];
                    if($importType == ImportStatus::TYPE_RAWDATA && !($importType & ImportStatus::TYPE_DRY_RUN) && strpos($initAction['calculation'], $websiteSettingName) !== false) {
                        $websiteSetting = \OpenDxp\Model\WebsiteSetting::getByName($websiteSettingName);
                        if ($websiteSetting) {
                            $websiteSetting->delete();
                        }
                    }

                    if ($result === false) {
                        $this->logger->info('Dataport run skipped because initialization function returned "false"');
                        $dataportResourceId = null;
                        goto finishImport;
                    }
                }
            } catch (\Throwable $ex) {
                if (!$ex instanceof ErrorException || !in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                    $this->logger->error('Error when executing initialization function: '.$ex);
                } else {
                    $this->logger->warning('Error when executing initialization function: '.$ex);
                }

                $dataportResourceId = null;

                goto finishImport;
            }
        }


        $transactionItems = [];
        try {
            $total = count($parser);
            PimcoreDbRepository::retry(static function() use ($statusModel, $total, $statusKey) {
                $statusModel->update(
                    ['totalItems' => $total],
                    ['key' => $statusKey]
                );
            });

            $this->monitoringItem->setTotalSteps($total);
            $this->monitoringItem->setTotalWorkload($total);
            $this->monitoringItem->save();

            // mark raw items which previously existed
            $currentlyRunningPimImport = null;
            if(empty($sourceConfig['autoImport']) || !empty($targetConfig['itemClass'])) {
                $currentlyRunningPimImport = $statusModel->findOne(
                    [
                        'dataport_resource_id = ?' => $dataportResourceId,
                        'status = ?' => ImportStatus::STATUS_RUNNING,
                        'importType & ?' => ImportStatus::TYPE_PIM,
                        '`key` != ?' => $statusKey
                    ]
                );

                if(empty($currentlyRunningPimImport)) {
                    try {
                        PimcoreDbRepository::getInstance()->execute('UPDATE '.Installer::TABLE_RAWITEM.' SET `toBeDeleted` = 1 WHERE dataport_resource_id = ? AND toBeDeleted=0', [$dataportResourceId]);
                    } catch(\Throwable $e) {}
                }
            }

            $importer = new Importer($dataportResourceId, $this->logger);

            $chunkSize = 100;
            foreach ($parser as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $transactionItems[] = $item;

                if(count($transactionItems) === $chunkSize) {
                    $this->processChunk($parser, $done, $transactionItems, $importer, $otherDataportResourceIds, $statusModel, $statusKey);
                    $transactionItems = [];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Unable to import raw data: '.$e);
        }

        if(count($transactionItems) > 0) {
            $this->processChunk($parser, $done, $transactionItems, $importer, $otherDataportResourceIds, $statusModel, $statusKey);
        }

		// remove raw items which existed before this import run
        if(empty($sourceConfig['autoImport']) || !empty($targetConfig['itemClass'])) {
            if(empty($currentlyRunningPimImport)) {
                try {
                    PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id = ? AND toBeDeleted=1', [$dataportResourceId]);
                } catch (\Throwable $e) {
                }

                Cache::clearTag('mapping-preview-'.$dataport['id']);
            }
        }

        if($done === 0) {
            $this->logger->info('Could not find any data (or it is not readable)');
        } else {
            $this->logger->info('Extracted ' . $done . ' raw data items');
        }

        finishImport:
        $dbNow = new \DateTime();
		$statusModel->update(array(
			'status' => ImportStatus::STATUS_FINISHED,
			'lastUpdate' => $dbNow,
			'endDate' => $dbNow,
			'doneItems' => $done,
            'totalItems' => $done,
		), ['key' => $statusKey]);

		return $dataportResourceId;
	}

	private function processChunk($parser, &$done, $transactionItems, Importer $importer, $otherDataportResourceIds, $statusModel, $statusKey) {
	    $totalCount = count($parser);

        $this->monitoringItem->setTotalSteps($totalCount);
        $this->monitoringItem->setTotalWorkload($totalCount);
        $this->monitoringItem->setCurrentStep($done + 1);
        $this->monitoringItem->setCurrentWorkload($done + 1);
        $this->monitoringItem->setMessage('Processing '.implode(', ', (array)reset($transactionItems)));
        $this->monitoringItem->setModificationDate(time());
        $this->monitoringItem->save();

        $otherDataportResourceImporters = array_map(function($otherDataportResourceId) {
            return new Importer($otherDataportResourceId, $this->logger);
        }, $otherDataportResourceIds);

        foreach ($transactionItems as $item) {
            $importer->insert($item, true);

            foreach ($otherDataportResourceImporters as $otherDataportResourceImporter) {
                $otherDataportResourceImporter->insert($item);
            }
            $done++;
        }

        $importer->writeBuffer();

        PimcoreDbRepository::clearPreparedStatements();
        OpenDxp::getContainer()->get(OpenDxp\Helper\LongRunningHelper::class)->cleanUp();

        $importAborted = $statusModel->findOne(['`key` = ?' => $statusKey, 'status = ?' => ImportStatus::STATUS_ABORTED]);
        if ($importAborted) {
            exit(1);
        }

        /**
         * Ebenfalls Status und Endzeitpunkt zurücksetzen, falls dies zwischenzeitlich durch den Maintenance-Job als
         * abgebrochener Prozess markiert wurde
         * also update totalItems to allow multiple files being imported at once
         */
        $statusModel->update(
            array(
                'status' => ImportStatus::STATUS_RUNNING,
                'endDate' => null,
                'lastUpdate' => new \DateTime(),
                'doneItems' => $done,
                'totalItems' => $totalCount,
            ), ['key' => $statusKey]
        );

        return true;
    }

	/**
	 * @return null
	 */
	public function getOverrideFile() {
		return $this->_overrideFile;
	}

	/**
	 * @param string $overrideFile
	 */
	public function setOverrideFile($overrideFile) {
	    $this->_overrideFile = $overrideFile;
	}

    /**
     * @param bool $removeFileAfterImport
     */
    public function setRemoveFileAfterImport($removeFileAfterImport)
    {
        $this->_removeFileAfterImport = (bool)$removeFileAfterImport;
    }

    /**
     * @return bool
     */
    public function getForce(): bool
    {
        return $this->force;
    }

    /**
     * @param bool $force
     */
    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    /**
     * @param MonitoringItem $monitoringItem
     */
    public function setMonitoringItem(MonitoringItem $monitoringItem)
    {
        $this->monitoringItem = $monitoringItem;
    }

    /**
     * @param string $locale
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
    }

    public function getLimit(): string
    {
        return $this->limit;
    }

    public function setLimit(?string $limit = null): void
    {
        if($limit) {
            $limit = explode(',', $limit);
            if(count($limit) === 2) {
                $limit = $limit[0].','.$limit[1];
            } else {
                $limit = '0,'.$limit[0];
            }

            $this->limit = $limit;
        }
    }
}
