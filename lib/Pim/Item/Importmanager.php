<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;
use Sylphen\DataBridgeBundle\SylphenDataBridgeBundle;
use Sylphen\DataBridgeBundle\lib\Pim\BackingUpResponse;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\EmailReportingLogger;
use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\RawItemLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\WorstErrorImportStatusLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\DeadlockPrevention;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use InSquare\OpendxpProcessManagerBundle\Model\MonitoringItem;
use ErrorException;
use Exception;
use LogicException;
use PDOException;
use OpenDxp;
use OpenDxp\Config;
use OpenDxp\Db;
use OpenDxp\Helper\LongRunningHelper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Mail;
use OpenDxp\Model\Document\Page;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Tool;
use OpenDxp\Tool\Mime;
use OpenDxp\Logger;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use ReflectionException;
use ReflectionObject;
use stdClass;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class Importmanager
{
    /** @var ImporterInterface */
    private $importer;

    /** @var LoggerInterface */
    private $logger;

    /** @var MonitoringItem */
    private $monitoringItem;

    /** @var bool */
    private $force;

    /** @var TranslationHelper */
    private $translationHelper;

    /**
     * Importmanager constructor.
     *
     * @param ImporterInterface $importer
     * @param LoggerInterface   $logger
     * @param bool              $force
     */
    public function __construct(ImporterInterface $importer, LoggerInterface $logger, $force = false)
    {
        $this->importer = $importer;
        if (\method_exists($this->importer, 'setIgnoreHashCheck')) {
            $this->importer->setIgnoreHashCheck($force);
        }
        if (\method_exists($this->importer, 'setForce')) {
            $this->importer->setForce($force);
        }
        if ($logger instanceof LoggerInterface && $this->importer instanceof LoggerAwareInterface) {
            $this->importer->setLogger($logger);
        }
        $this->logger = $logger;

        // null object if no monitoring item has been set
        $this->monitoringItem = new class {
            const STATUS_FAILED = 'failed';
            public function __call($name, $arguments)
            {
                return $this;
            }
        };

        $this->force = $force;

        $this->translationHelper = OpenDxp::getContainer()->get(TranslationHelper::class);

        // prevent stack trace from being added to versions -> is visible in database only -> it is more helpful to the user if we tag version with dataport id and user who started import
        $configObject = new Config();

        Helper::getPimcoreSystemConfiguration();
        try {
            $configReflection = new ReflectionObject($configObject);
            $systemConfigProperty = $configReflection->getProperty('systemConfig');
            $systemConfigProperty->setAccessible(true);
            $systemConfig = $systemConfigProperty->getValue();
            $systemConfig['assets']['versions']['disable_stack_trace'] = true;
            $systemConfig['objects']['versions']['disable_stack_trace'] = true;
            $systemConfig['documents']['versions']['disable_stack_trace'] = true;

            $systemConfig['assets']['image']['low_quality_image_preview']['enabled'] = false;
            $systemConfig['assets']['image']['focal_point_detection']['enabled'] = false;
            $systemConfig['assets']['image']['max_pixels'] = PHP_INT_MAX;
            $systemConfigProperty->setValue(null, $systemConfig);
        } catch (ReflectionException $e) {
        }

        if (RuntimeCache::isRegistered('pimcore_config_system')) {
            $systemConfigCache = RuntimeCache::get('pimcore_config_system');
            if ($systemConfigCache) {
                /** @var Config\Config $systemConfigCache */
                foreach (['assets', 'objects', 'documents'] as $elementType) {
                    try {
                        $configReflection = new ReflectionObject($systemConfigCache[$elementType]['versions']);
                        $systemConfigProperty = $configReflection->getProperty('data');
                        $systemConfigProperty->setAccessible(true);
                        $systemConfig = $systemConfigProperty->getValue($systemConfigCache[$elementType]['versions']);
                        $systemConfig['disable_stack_trace'] = true;
                        $systemConfigProperty->setValue($systemConfigCache[$elementType]['versions'], $systemConfig);
                    } catch (Throwable $e) {
                    }
                }
            }
        }
    }

    /**
     * @param $dataportResourceIds
     * @param $importType
     * @param array|null $rawItemIds
     * @param $statusKey
     * @param $limit
     * @param array $contextParams
     * @return BackingUpResponse|mixed|Response
     * @throws \DateMalformedStringException
     */
    public function importDataport(
        $dataportResourceIds,
        $importType = ImportStatus::TYPE_PIM,
        array $rawItemIds = null,
        $statusKey = null,
        $limit = null,
        array $contextParams = []
    ) {
        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);
        gc_enable();
        if (method_exists(Db::getConnection()->getConfiguration(), 'setSQLLogger')) {
            Db::getConnection()->getConfiguration()->setSQLLogger(null);
        }
        if(class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }
        if(method_exists(\Doctrine\Deprecations\Deprecation::class, 'disable')) {
            \Doctrine\Deprecations\Deprecation::disable();
        }

        OpenDxp\Cache::disable(); // unserializing PHP object in most cases takes longer than loading the few used import fields from database

        if ($statusKey === null) {
            $statusKey = uniqid('', true);
        }

        $statusModel = ImportStatus::getInstance();
        $done = 0;

        $abortFunction = function ($restart = true) use ($statusKey, $statusModel) {
            $updatedRows = $statusModel->update(
                [
                    'status' => ImportStatus::STATUS_ABORTED,
                    'endDate' => new \DateTime(),
                ],
                ['key' => $statusKey, 'status' => ImportStatus::STATUS_RUNNING]
            );

            PimcoreDbRepository::getInstance()->execute('DELETE FROM edit_lock WHERE sessionId LIKE ?', [getmypid().'-%']);

            if ($updatedRows > 0) {
                $this->logger->error('Process got aborted');
                $this->monitoringItem->setStatus($this->monitoringItem::STATUS_FAILED);
                $this->monitoringItem->setReportedDate(time());
                $this->monitoringItem->setMessage('Process got aborted');
                $this->monitoringItem->save();

                if ($restart && $statusModel->queueRestart($statusKey)) {
                    $this->logger->error('Command restarted because original process was terminated unintentionally');
                }

                http_response_code(500);
            }

            for ($transactionLevel = PimcoreDbRepository::getInstance()->getTransactionNestingLevel(); $transactionLevel > 0; $transactionLevel--) {
                try {
                    PimcoreDbRepository::getInstance()->commit();
                } catch (\Throwable $e) {
                    // transaction got committed by implicit commit (e.g. via database change)

                    PimcoreDbRepository::getInstance()->close();
                    PimcoreDbRepository::getInstance()->beginTransaction();
                }
            }

            if($updatedRows > 0) {
                exit(1);
            }
        };

        register_shutdown_function($abortFunction);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $cliAbortFunction = static function ($restart = false) use ($abortFunction) {
                $abortFunction($restart);
                exit(1);
            };
            pcntl_signal(SIGINT, static function () use ($cliAbortFunction) {
                $cliAbortFunction(false);
            }); // SIGINT is sent by the TTY driver to the current foreground job when the interactive attention character (typically ^C, which has ASCII code 3) appears in the input stream
            pcntl_signal(SIGTERM, static function () use ($cliAbortFunction) {
                // Symfony process first tries to stop process with SIGTERM in Process::stop()
                $cliAbortFunction(false);
            });
            pcntl_signal(SIGHUP, $cliAbortFunction); // SIGHUP is sent by the UART driver to the entire session when a hangup condition has been detected.
        }

        if (empty($dataportResourceIds)) {
            $response = new Response('No dataport resource IDs given', Response::HTTP_NOT_FOUND);
            goto finishImport;
        }

        $dataportResourceRepository = DataportResource::getInstance();
        $dataportResources = $dataportResourceRepository->find(['id IN (?)' => $dataportResourceIds]);
        if (!$dataportResources) {
            $response = new Response('None of the given dataport resources exist', Response::HTTP_NOT_FOUND);
            goto finishImport;
        }

        $dataportId = $dataportResources[0]['dataportId'];

        $reportingLogger = null;
        if (!$this->force && method_exists($this->logger, 'addLogger')) {
            $reportingLogger = OpenDxp::getContainer()->get(EmailReportingLogger::class);
            $reportingLogger->setDataportId($dataportId);
            $this->logger->addLogger($reportingLogger);
        }

        $worstErrorLogger = null;
        if (method_exists($this->logger, 'addLogger')) {
            $worstErrorLogger = OpenDxp::getContainer()->get(WorstErrorImportStatusLogger::class);
            $this->logger->addLogger($worstErrorLogger);

            $this->logger->addLogger(RawItemLogger::getInstance());
        }

        $table = Dataport::getInstance();
        $dataport = $table->get($dataportId);

        $startTime = new \DateTimeImmutable();
        $targetconfig = $dataport['targetconfig'];

        $rawItemTable = RawItem::getInstance();
        $response = new BackingUpResponse($dataportId, $statusKey);

        $fieldMappingTable = Fieldmapping::getInstance();
        $resultAction = $fieldMappingTable->findOne(
            [
                'dataportId = ?' => $dataportId,
                'fieldName = ?' => '__result_action'
            ]
        );

        $resultCallback = $fieldMappingTable->findOne(
            [
                'dataportId = ?' => $dataportId,
                'fieldName = ?' => '__result_callback'
            ]
        );

        $response->headers->set('Cache-Control', 'no-cache'); // always ask server for validity of cached resource

        $itemCondition = [
            'dataport_resource_id IN (?)' => $dataportResourceIds
        ];

        $offset = 0;
        $maxResults = INF;
        if (!empty($rawItemIds)) {
            $itemCondition['id IN (?)'] = $rawItemIds;
            $maxResults = INF;
        } elseif(is_array($limit)) {
            if (!empty($limit['offset'])) {
                $offset = (int)$limit['offset'];
            }
            if (!empty($limit['limit']) && $limit['limit'] !== INF) {
                $maxResults = (int)$limit['limit'];
            }
        } elseif(is_string($limit)) {
            $limit = explode(',', $limit);
            if(count($limit) === 1 && $limit[0] > 0) {
                $offset = 0;
                $maxResults = (int)$limit[0];
            } elseif(count($limit) === 2) {
                $offset = (int)$limit[0];
                if($limit[1] !== 'INF') {
                    $maxResults = (int)$limit[1];
                }
            }
        } elseif((int)$limit > 0 && (int)$limit < PHP_INT_MAX) {
            $offset = 0;
            $maxResults = (int)$limit;
        }

        $totalOfAllDataportResources = $rawItemTable->countRows($itemCondition, count($dataportResources) > 1 ? 'hash' : null);
        $total = $totalOfAllDataportResources - $offset;
        if ($total <= 0) {
            $maxResults = 0;
        }

        if($total > $maxResults) {
            $total = $maxResults;
        }

        $request = \Sylphen\DataBridgeBundle\lib\Pim\Helper::getRequest();

        $sourceConfig = $dataport['sourceconfig'];
        if (empty($targetconfig['itemClass']) && $total > 0 && $offset === 0 && $maxResults === INF && (empty($resultAction['calculation']) || $resultAction['calculation'] === 'return $params[\'response\'];' || strpos($resultAction['calculation'], 'dependentDataportId') !== false)) {
            $lastModifiedRawDataItem = $rawItemTable->findOne($itemCondition, 'updated DESC');

            if(!$this->force && $request instanceof Request) {
                $lastModified = new \DateTime($lastModifiedRawDataItem['updated']);
                $dataportDefinitionFile = Installer::getConfigPath().'/dataport_'.$dataportId.'.json';
                if(file_exists($dataportDefinitionFile)) {
                    $lastModifiedDataport = (new \DateTime('@0'))->setTimestamp(filemtime($dataportDefinitionFile));
                    if ($lastModifiedDataport > $lastModified) {
                        $lastModified = $lastModifiedDataport;
                    }
                }

                $response->setLastModified($lastModified);
                if ($response->isNotModified($request)) {
                    if($statusKey) {
                        $dbNow = new \DateTime();
                        $statusModel->update(
                            [
                                'status' => ImportStatus::STATUS_FINISHED,
                                'lastUpdate' => $dbNow,
                                'endDate' => $dbNow,
                            ], ['key' => $statusKey]
                        );
                    }
                    $this->logger->info('Export document returned from browser cache as raw data did not change since last successful run. To enforce execution of raw data processing, run the dataport with --force option');
                    return $response;
                }
            }

            $rawItemDataRepository = RawItemData::getInstance();
            $rawItemDataRows = $rawItemDataRepository->find(
                [
                    'rawItemId = ?' => $lastModifiedRawDataItem['id'],
                ]
            );
            $lastModifiedRawDataItemAllFieldsHash = md5(implode('_', array_map(static function($rawItemData) {
                return $rawItemData['fieldNo'].' '.$rawItemData['value'];
            }, $rawItemDataRows)));

            $outCacheFilePrefix = Installer::getResultDocumentPath() . '/result_' . $dataportId . '_' . md5(implode('_', $dataportResourceIds));
            $outputCacheFile = $outCacheFilePrefix.'_'. $lastModifiedRawDataItemAllFieldsHash;

            if(!$this->force && \file_exists($outputCacheFile)) {
                $outputCacheFileHandle = null;
                try {
                    // async process with predefined status key
                    if($statusKey) {
                        $linkTarget = Installer::getResultDocumentPath().'/result_'.$dataportId.'_'.$statusKey;
                        $outputCacheFileHandle = fopen($outputCacheFile, 'rb', LOCK_EX);
                        file_put_contents($linkTarget, $outputCacheFileHandle);

                        $dbNow = new \DateTime();
                        $statusModel->update(
                            [
                                'status' => ImportStatus::STATUS_FINISHED,
                                'lastUpdate' => $dbNow,
                                'endDate' => $dbNow,
                            ], ['key' => $statusKey]
                        );
                    }

                    $response = \unserialize(\file_get_contents($outputCacheFile));

                    if(!$response instanceof Response || ($response instanceof BackingUpResponse && !$response->hasContent())) {
                        @unlink($outputCacheFile);
                        if ($statusKey) {
                            @unlink($linkTarget);
                        }
                    } else {
                        $this->logger->info('Export document returned from server cache as raw data did not change since last successful run. To enforce execution of raw data processing, run the dataport with --force option');
                        return $response;
                    }
                } catch(\Throwable $e) {
                    $this->logger->info('Could not unserialize "'.$outputCacheFile.'"'. $e->getMessage().'. Continuing to process raw data for export ...');
                } finally {
                    if(is_resource($outputCacheFileHandle)) {
                        fclose($outputCacheFileHandle);
                    }
                }
            }

            $fileIterator = new \GlobIterator($outCacheFilePrefix . '_*', \GlobIterator::SKIP_DOTS);
            /** @var \SplFileInfo $fileInfo */
            foreach ($fileIterator as $fileInfo) {
                @unlink($fileInfo->getPathname());
            }
        }

        $transfer = $request->attributes->get('transfer');

        $event = new GenericEvent(
            $this, [
                'dataportId' => $dataportId,
                'rawitemCount' => $total,
            ]
        );
        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($event, 'pim.preImport');

        $this->monitoringItem->setTotalSteps($total);
        $this->monitoringItem->setTotalWorkload($total);
        $this->monitoringItem->save();
        $changedObjectIds = []; // Important when transfer after pim import

        $virtualFieldFallbackCache = []; // for used virtual fields which have not been populated -> value will be the same for all objects

        if (empty($targetconfig['itemClass']) && empty($rawItemIds) && (empty($sourceConfig['autoImport']) || !empty($sourceConfig['incrementalExport'])) && ($dataport['targetconfig']['parallelProcesses'] ?? 1) == 1) {
            do {
                $currentlyRunningRawdataImport = $statusModel->findOne(
                    [
                        'dataport_resource_id IN (?)' => $dataportResourceIds,
                        'status = ?' => ImportStatus::STATUS_RUNNING,
                        'importType & ?' => ImportStatus::TYPE_RAWDATA,
                        '`key` != ?' => $statusKey
                    ]
                );

                if (!$currentlyRunningRawdataImport) {
                    break;
                }

                $totalOfAllDataportResources = $rawItemTable->countRows($itemCondition, count($dataportResources) > 1 ? 'hash' : null);
                $total = $totalOfAllDataportResources - $offset;
                if ($total > $maxResults) {
                    $total = $maxResults;
                }

                $this->logger->info('Waiting until raw data import for export is done');

                sleep(1);
            } while (true);
        }

        $logFileObject = $statusModel->create(
            [
                'key' => $statusKey,
                'dataport_id' => $dataportId,
                'dataport_resource_id' => reset($dataportResources)['id'],
                'startDate' => $startTime,
                'lastUpdate' => $startTime,
                'importType' => $importType,
                'totalItems' => $total,
            ]
        );

        if ($logFileObject instanceof FileObject && method_exists($this->logger, 'setLogFileObject')) {
            $this->logger->setLogFileObject($logFileObject);
        }

        if ($reportingLogger !== null && $logFileObject instanceof FileObject) {
            $reportingLogger->setLogFileObject($logFileObject);
        }

        if($importType & ImportStatus::TYPE_DRY_RUN) {
            $this->logger->info('Executed as dry-run -> changes do not get saved');
        }

        if($worstErrorLogger !== null) {
            $worstErrorLogger->setStatusKey($statusKey);
        }

        try {
            $initAction = $fieldMappingTable->findOne(
                [
                    'dataportId = ?' => $dataportId,
                    'fieldName = ?' => '__init_action'
                ]
            );
            if (!empty($initAction['calculation']) && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                /** @var \Sylphen\DataBridgeBundle\lib\Pim\Helper $helper */
                $helper = Helper::getInstance();
                $helper->setLogger($this->logger);
                $result = $helper->runInitFunction($dataport, $statusKey);

                if ($result === false) {
                    $this->logger->info('Dataport run skipped because initialization function returned "false"');
                    goto finishImport;
                }

                if (is_scalar($result) && !is_bool($result)) {
                    $response->setContent((string)$result);
                }
            }
        } catch (\Throwable $ex) {
            if (!$ex instanceof ErrorException || !in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                $this->logger->error('Error when executing initialization function: '.$ex);
            } else {
                $this->logger->warning('Error when executing initialization function: '.$ex);
            }

            goto finishImport;
        }

        $totalOfAllDataportResources = $rawItemTable->countRows($itemCondition, count($dataportResources) > 1 ? 'hash' : null);
        if (!empty($targetconfig['itemClass']) && empty($rawItemIds)) {
            $currentlyRunningPimImport = $statusModel->findOne(
                [
                    'dataport_resource_id IN (?)' => $dataportResourceIds,
                    '`key` != ?' => $statusKey,
                    'status = ?' => ImportStatus::STATUS_RUNNING,
                    'importType & ?' => ImportStatus::TYPE_PIM
                ]
            );

            if ($currentlyRunningPimImport) {
                $total -= $totalOfAllDataportResources;
                $this->logger->info('Cannot import now because other import (started at '.$currentlyRunningPimImport['startDate'].') for this dataport resource is running -> the other process will process recently updated raw data.');

                goto finishImport;
            }
        }

        $resultCallbackVariables = [];
        if($resultCallback) {
            preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $resultCallback['calculation'], $resultCallbackVariables);
            if($resultCallbackVariables[1]) {
                $resultCallbackVariables = array_unique($resultCallbackVariables[1]);

                $virtualFieldNamesForResultCallbackVariables = array_map(static function ($resultCallbackVariable) {
                    return '__virtual_'.$resultCallbackVariable;
                }, $resultCallbackVariables);
                $mappedResultCallbackVariables = array_map(static function ($virtualFieldNamesForResultCallbackVariable) {
                    return substr($virtualFieldNamesForResultCallbackVariable['fieldName'], strlen('__virtual_'));
                }, Fieldmapping::getInstance()->find(['dataportId = ?' => $dataport['id'], 'fieldName IN (?)' => $virtualFieldNamesForResultCallbackVariables]));

                $resultCallbackVariables = array_intersect($resultCallbackVariables, $mappedResultCallbackVariables);
            } else {
                $resultCallbackVariables = [];
            }
        }


        startProcessing:
        $greatestProcessedRawItemUpdated = gmdate('Y-m-d H:i:s');
        $realTotalOfAllDataportResources = $totalOfAllDataportResources;
        if ($totalOfAllDataportResources > $maxResults) {
            $totalOfAllDataportResources = $maxResults;
        }

        if ($totalOfAllDataportResources === 0) {
            try {
                if (!empty($resultCallback['calculation']) && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                    if ($resultCallbackVariables) {
                        /** @var \Sylphen\DataBridgeBundle\lib\Pim\Helper $helper */
                        $helper = Helper::getInstance();
                        $helper->setLogger($this->logger);

                        foreach ($resultCallbackVariables as $variable) {
                            try {
                                $virtualFieldDefinition = Importer::getFieldDefinition(null, '__virtual_'.$variable);
                                $virtualMapping = @$helper->createMapping($virtualFieldDefinition, $dataport, [], false);
                                if (!array_key_exists($variable, $virtualFieldFallbackCache)) {
                                    $virtualMapping = @$helper->createMapping($virtualFieldDefinition, $dataport, [], false);
                                    $virtualFieldFallbackCache[$variable] = reset($virtualMapping)['example_value'] ?? null;
                                }
                                $virtualFieldFallbackCache[$virtualFieldDefinition->getName()] = reset($virtualMapping)['example_value'] ?? null;
                            } catch (\Throwable $e) {
                            }
                        }
                    }

                    $originalLogs = RawItemLogger::getInstance()->getLogs();
                    $logs = [];
                    foreach ($originalLogs as $rawItemId => $rawItemlogs) {
                        if($rawItemId !== 'global') {
                            $rawItemId = Uuid::fromBytes($rawItemId)->getInteger()->toString();
                        }
                        $logs[$rawItemId] = $rawItemlogs;
                    }

                    $parameters = [
                        'response' => $response,
                        'request' => $request ?? \Sylphen\DataBridgeBundle\lib\Pim\Helper::getRequest(),
                        'virtualFields' => $virtualFieldFallbackCache,
                        'value' => [],
                        'rawItemData' => [],
                        'logs' => $logs,
                        'objectIDs' => [],
                        'context' => [
                            'dataportId' => $dataport['id'],
                            'dataport' => [
                                'id' => $dataport['id'],
                                'name' => $dataport['name'],
                            ],
                            'resource' => \json_decode(reset($dataportResources)['resource'], true),
                            'count' => $realTotalOfAllDataportResources,
                            'statusKey' => $statusKey,
                            'user' => [
                                'id' => Helper::getUser()->getId(),
                                'username' => Helper::getUser()->getUsername()
                            ],
                        ],
                        'transfer' => $transfer,
                        'lastCall' => true,
                        'logger' => $this->logger,
                        'field' => 'Result callback function'
                    ];
                    if (!empty($contextParams)) {
                        $parameters['context'] = array_merge($parameters['context'], $contextParams);
                    }

                    $return = CallbackFunction::evaluateScript(
                        $resultCallback['calculation'], $targetconfig['javascriptEngine'],
                        $parameters
                    );

                    if (is_scalar($return)) {
                        $response->setContent((string)$return);
                    } elseif($return instanceof BackingUpResponse) {
                        $response = $return;
                    }
                }

                if (!$response->headers->has('Content-Disposition')) {
                    try {
                        if (($request instanceof Request && $request->get('gridExport')) || $response->getFileExtension()) {
                            $response->headers->set(
                                'Content-Disposition',
                                'attachment; filename='.OpenDxp\File::getValidFilename($dataport['name']).'_'.date('Y-m-d_H-i-s').'.'.$response->getFileExtension()
                            );

                            if (($request instanceof Request && $request->get('gridExport')) && !$response->hasContent()) {
                                $response->addContent('Dataport successfully executed');
                            }
                        }
                    } catch (Exception $e) {
                    }
                }
            } catch (\Throwable $ex) {
                $this->logger->error('Error while executing result callback function: '.$ex);
            }
        } else {
            $chunkSize = 100;

            $rawItemIdsToBeProcessed = RawItem::getInstance()->find(
                $itemCondition,
                (empty($dataport['targetconfig']['itemClass']) || $dataport['sourcetype'] === 'pimcore') ? 'priority' : 'updated,priority',
                null,
                0,
                count($dataportResources) > 1 ? 'hash' : null,
                ['id','hash', 'dataport_resource_id', 'updated']
            );

            $firstChunk = (int)floor($offset / $chunkSize);
            $lastChunk = (int)ceil($totalOfAllDataportResources / $chunkSize) + $firstChunk;

            try {
                $currentDataportResourceId = null;
                for ($i = $firstChunk; $i < $lastChunk; $i++) {
                    $chunkLimit = (int)min($maxResults - $done, $chunkSize);
                    $rawItemChunk = array_slice($rawItemIdsToBeProcessed, $offset, $chunkLimit);
                    $lastCall = $i == $lastChunk - 1 || count($rawItemChunk) < $chunkLimit;

                    $rawItemsGroupedByDataportResource = [];
                    foreach($rawItemChunk as $rawItemChunkItem) {
                        if(!isset($rawItemsGroupedByDataportResource[$rawItemChunkItem['dataport_resource_id']])) {
                            $rawItemsGroupedByDataportResource[$rawItemChunkItem['dataport_resource_id']] = [];
                        }
                        $rawItemsGroupedByDataportResource[$rawItemChunkItem['dataport_resource_id']][] = $rawItemChunkItem;
                    }

                    foreach($rawItemsGroupedByDataportResource as $dataportResourceId => $rawItemChunk) {
                        foreach ($dataportResources as $dataportResource) {
                            if($dataportResource['id'] == $dataportResourceId) {
                                if ($currentDataportResourceId !== $dataportResourceId) {
                                    Helper::clearEnvironmentVariables();
                                    $currentDataportResourceId = $dataportResourceId;
                                }
                                $resourceParameters = \json_decode($dataportResource['resource'], true);
                                foreach ($resourceParameters['parameters'] ?? [] as $dataportResourceParameterName => $dataportResourceParameterValue) {
                                    $_ENV[$dataportResourceParameterName] = $dataportResourceParameterValue;
                                }
                                break;
                            }
                        }

                        try {
                            $this->processChunk(
                                $rawItemChunk,
                                $done,
                                $offset,
                                $dataport,
                                $transfer,
                                $statusKey,
                                $changedObjectIds,
                                $resultCallback,
                                $resultCallbackVariables,
                                $logFileObject,
                                $virtualFieldFallbackCache,
                                $dataportResources,
                                $lastCall,
                                $response,
                                $realTotalOfAllDataportResources,
                                $importType & ImportStatus::TYPE_DRY_RUN,
                                $contextParams
                            );
                        } catch (ElementLockedException $importResult) {
                            $lastRawItemId = end($rawItemIdsToBeProcessed)['id'] ?? '';
                            if ($lastRawItemId) {
                                $lastRawItemId = Uuid::fromBytes($lastRawItemId)->getInteger();
                            }
                            $cmd = 'data-bridge:process '.$dataport['id'].' '.Uuid::fromBytes($rawItemIdsToBeProcessed[0]['id'])->getInteger().'-'.$lastRawItemId.($this->force ? ' -f' : '');
                            register_shutdown_function(
                                static function () use ($cmd, $importResult, $dataport) {
                                    Queue::getInstance()->create([
                                        'command' => $cmd,
                                        'triggered_by' => 'Element '.$importResult->getElement()->getFullPath().' was locked during update -> import got queued and will be continued later',
                                        'worker_id' => $dataport['id']
                                    ]);
                                }
                            );

                            ImportStatus::getInstance()->update(
                                [
                                    'status' => ImportStatus::STATUS_ABORTED,
                                    'endDate' => new \DateTime(),
                                    'doneItems' => $done,
                                ],
                                ['key' => $statusKey]
                            );

                            return $response;
                        }
                    }

                    $this->monitoringItem->setCurrentStep($done);
                    $this->monitoringItem->setModificationDate(time());
                    $this->monitoringItem->save();

                    $importAborted = $statusModel->findOne(
                        ['`key` = ?' => $statusKey, 'status = ?' => ImportStatus::STATUS_ABORTED]
                    );
                    if ($importAborted) {
                        $statusModel->update(
                            [
                                'status' => ImportStatus::STATUS_ABORTED,
                                'endDate' => new \DateTime(),
                                'doneItems' => $done,
                            ], ['key' => $statusKey]
                        );
                        return $response;
                    }

                    /**
                     * Ebenfalls Status und Endzeitpunkt zurücksetzen, falls dies zwischenzeitlich durch den Maintenance-Job als
                     * abgebrochener Prozess markiert wurde
                     */
                    $statusModel->update(
                        [
                            'status' => ImportStatus::STATUS_RUNNING,
                            'endDate' => null,
                            'lastUpdate' => new \DateTime(),
                            'doneItems' => $done,
                        ],
                        ['key' => $statusKey]
                    );

                    // Fire status event
                    $event = new GenericEvent(
                        $this, [
                            'dataportId' => $dataportId,
                            'total' => $totalOfAllDataportResources,
                            'done' => $done,
                            'startTime' => $startTime,
                            'changedObjects' => \count($changedObjectIds),
                        ]
                    );

                    \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($event, 'pim.importstatus');


                    if (method_exists(OpenDxp::class, 'deleteTemporaryFiles')) {
                        OpenDxp::deleteTemporaryFiles();
                    }

                    PimcoreDbRepository::clearPreparedStatements();
                    OpenDxp::getContainer()->get(LongRunningHelper::class)->cleanUp();
                    Serializer::clearCache();

                    if(!$response->headers->has('Content-Disposition')) {
                        try {
                            if (($request instanceof Request && $request->get('gridExport')) || $response->getFileExtension()) {
                                $response->headers->set(
                                    'Content-Disposition',
                                    'attachment; filename='.OpenDxp\File::getValidFilename($dataport['name']).'_'.date('Y-m-d_H-i-s').'.'.$response->getFileExtension()
                                );

                                if (($request instanceof Request && $request->get('gridExport')) && !$response->hasContent()) {
                                    $response->addContent('Dataport successfully executed');
                                }
                            }
                        } catch (Exception $e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Unable to import data: '.$e);

                if ($e instanceof RetryableException || ($e instanceof PDOException && $e->getCode() == 1205)) {
                    throw $e;
                }
            }

            if ($maxResults !== INF) {
                $maxResults = max($maxResults - $done, 0);
            }
            $offset = 0;
        }

        $totalNew = $rawItemTable->countRows(array_merge($itemCondition, ['updated > ?' => $greatestProcessedRawItemUpdated]));

        if ($totalNew > 0 && ($maxResults === INF || $maxResults > 0)) {
            $totalOfAllDataportResources = $totalNew;
            $offset = 0;

            $itemCondition['updated > ?'] = $greatestProcessedRawItemUpdated;

            PimcoreDbRepository::getInstance()->execute('UPDATE '.Installer::TABLE_IMPORTSTATUS.' SET totalItems=totalItems+'.$totalNew.' WHERE `key` = ?', [$statusKey]);

            $this->logger->info('Restarting import as new raw data was found');

            goto startProcessing;
        }

        $event = new GenericEvent(
            $this,
            [
                'dataportId' => $dataportId,
                'changedObjectIds' => array_unique($changedObjectIds),
            ]
        );

        try {
            \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($event, 'pim.importFinished');
        } catch(\Throwable $e) {
            $this->logger->error('Error while dispatching pim.importFinished event: '.$e->getMessage());
        }

        $result = true;
        try {
            if (!empty($resultAction['calculation']) && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                if (preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $resultAction['calculation'], $variables)) {
                    /** @var \Sylphen\DataBridgeBundle\lib\Pim\Helper $helper */
                    $helper = Helper::getInstance();
                    $helper->setLogger($this->logger);

                    foreach (array_unique($variables[1]) as $variable) {
                        if (!array_key_exists('__virtual_'.$variable, $virtualFieldFallbackCache)) {
                            $virtualFieldDefinition = Importer::getFieldDefinition(null, '__virtual_'.$variable);
                            $virtualMapping = $helper->createMapping($virtualFieldDefinition, $dataport, [], false);
                            $virtualFieldFallbackCache[$virtualFieldDefinition->getName()] = reset($virtualMapping)['example_value'] ?? null;
                        }
                    }
                }

                $originalLogs = RawItemLogger::getInstance()->getLogs();
                $logs = [];
                foreach($originalLogs as $rawItemId => $rawItemlogs) {
                    if ($rawItemId !== 'global') {
                        $rawItemId = Uuid::fromBytes($rawItemId)->getInteger()->toString();
                    }
                    $logs[$rawItemId] = $rawItemlogs;
                }

                $user = Helper::getUser();
                $parameters = [
                    'response' => $response,
                    'request' => $request ?? \Sylphen\DataBridgeBundle\lib\Pim\Helper::getRequest(),
                    'context' => [
                        'dataportId' => $dataport['id'],
                        'dataport' => [
                            'id' => $dataport['id'],
                            'name' => $dataport['name'],
                        ],
                        'user' => [
                            'id' => $user->getId(),
                            'username' => $user->getUsername()
                        ],
                        'resources' => array_map(static function ($dataportResource) {
                            return \json_decode($dataportResource['resource'], true);
                        }, $dataportResources),
                        'statusKey' => $statusKey
                    ],
                    'logs' => $logs,
                    'virtualFields' => $virtualFieldFallbackCache,
                    'transfer' => $transfer,
                    'logger' => $this->logger,
                    'field' => 'Result document action'
                ];
                if (!empty($contextParams)) {
                    $parameters['context'] = array_merge($parameters['context'], $contextParams);
                }
                $result = CallbackFunction::evaluateScript(
                    $resultAction['calculation'], $targetconfig['javascriptEngine'],
                    $parameters
                );

                if (is_scalar($result)) {
                    $response->setContent((string)$result);
                }
            }
        } catch (\Throwable $ex) {
            if (!$ex instanceof ErrorException || !in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                $this->logger->error('Error when executing result document function: '.$ex);
            } else {
                $this->logger->warning('Error when executing result document function: '.$ex);
            }
        }

        if (empty($targetconfig['itemClass']) && $result !== false && !empty($sourceConfig['incrementalExport'])) {
            foreach ($dataportResourceIds as $dataportResourceId) {
                $currentlyRunningPimImport = $statusModel->findOne(
                    [
                        'dataport_resource_id = ?' => $dataportResourceId,
                        '`key` != ?' => $statusKey,
                        'status = ?' => ImportStatus::STATUS_RUNNING,
                        'importType & ?' => ImportStatus::TYPE_PIM
                    ]
                );

                if (empty($currentlyRunningPimImport)) {
                    $rawItemTable->deleteWhere(['dataport_resource_id' => $dataportResourceId]);
                }
            }
        }

        $linkTarget = Installer::getResultDocumentPath().'/result_'.$dataportId.'_'.$statusKey;
;
        if (isset($outputCacheFile) && empty($targetconfig['itemClass']) && empty($rawItemIds)) {
            try {
                $response->cache($outputCacheFile);

                if(!file_exists($linkTarget)) {
                    $response->cache($linkTarget);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Could not save result to cache: '.$e->getMessage());
            }
        } elseif ($resultCallback && (strpos($resultCallback['calculation'], 'return ') !== false || strpos($resultCallback['calculation'], 'addContent') !== false || strpos($resultCallback['calculation'], 'setContent') !== false) && !file_exists($linkTarget)) {
            $response->cache($linkTarget);
        }

        if ($response->hasContent()) {
            if($response->getLength() > 10000) {
                $responseContent = '(too long be be shown here)';
            } else {
                $responseContent = $response->getContent();
                if(!preg_match('//u', $responseContent)) {
                    $responseContent = '(binary content, cannot be shown here)';
                }
            }
            $this->logger->info('Response document: '.$responseContent);
        }

        if (!empty($targetconfig['itemClass']) && empty($rawItemIds)) {
            $totalNew = $rawItemTable->countRows(array_merge($itemCondition, ['updated > ?' => $greatestProcessedRawItemUpdated]));

            if ($totalNew > 0 && ($maxResults === INF || $maxResults > 0)) {
                $totalOfAllDataportResources = $totalNew;
                $offset = 0;

                $itemCondition['updated > ?'] = $greatestProcessedRawItemUpdated;

                PimcoreDbRepository::getInstance()->execute('UPDATE '.Installer::TABLE_IMPORTSTATUS.' SET totalItems=totalItems+'.$totalNew.' WHERE `key` = ?', [$statusKey]);

                $this->logger->info('Restarting import as new raw data was found');

                goto startProcessing;
            }
        }

        finishImport:

        $dbNow = new \DateTime();
        $statusModel->update(
            [
                'status' => ImportStatus::STATUS_FINISHED,
                'lastUpdate' => $dbNow,
                'endDate' => $dbNow,
                'doneItems' => $done,
            ], ['key' => $statusKey]
        );

        return $response;
    }

    /**
     * @param MonitoringItem $monitoringItem
     */
    public function setMonitoringItem(MonitoringItem $monitoringItem)
    {
        $this->monitoringItem = $monitoringItem;
    }

    public function processChunk(
        $rawItems,
        &$done,
        &$offset,
        $dataport,
        $transfer,
        $statusKey,
        &$changedObjectIds,
        $resultCallback,
        $resultCallbackVariables,
        $logFileObject,
        &$virtualFieldFallbackCache,
        $dataportResources,
        $lastCall,
        &$response,
        $realTotalOfAllDataportResources,
        $dryRun = false,
        $contextParams = []
    ) {
        if ($dryRun) {
            PimcoreDbRepository::getInstance()->beginTransaction();
        }

        $importResult = $this->importer->import($dataport, $rawItems, $transfer, $dryRun);

        if ($dryRun) {
            PimcoreDbRepository::getInstance()->rollback();
        }

        if ($importResult instanceof ElementLockedException) {
            throw $importResult;
        }

        $changedObjectIds = [$changedObjectIds];
        foreach ($importResult as $importedRawDataItem) {
            $changedObjectIds[] = $importedRawDataItem['objectIDs'];
        }
        $changedObjectIds = array_merge(...$changedObjectIds);

        $virtualFieldFallbackCache = reset($importResult)['virtualFields'];

        if (!empty($resultCallback['calculation']) && CallbackFunction::isEngineAvailable($dataport['targetconfig']['javascriptEngine'])) {
            $processed = 0;
            $countImportedRawDataItems = count($importResult);

            /** @var \Sylphen\DataBridgeBundle\lib\Pim\Helper $helper */
            $helper = \OpenDxp::getContainer()->get(\Sylphen\DataBridgeBundle\lib\Pim\Helper::class);
            foreach ($importResult as $importedRawDataItem) {
                foreach ($resultCallbackVariables as $variable) {
                    try {
                        if (array_key_exists($variable, $importedRawDataItem['virtualFields']) || array_key_exists($variable, $importedRawDataItem['data'])) {
                            continue;
                        }
                        $virtualFieldName = '__virtual_'.$variable;
                        if (array_key_exists($virtualFieldName, $importedRawDataItem['virtualFields'])) {
                            continue;
                        }
                        $virtualFieldDefinition = Importer::getFieldDefinition(null, $virtualFieldName);
                        if (!array_key_exists($variable, $virtualFieldFallbackCache)) {
                            $virtualMapping = @$helper->createMapping($virtualFieldDefinition, $dataport, [], false);
                            $virtualFieldFallbackCache[$variable] = reset($virtualMapping)['example_value'] ?? null;
                            $importedRawDataItem['virtualFields'][$virtualFieldDefinition->getName()] = $virtualFieldFallbackCache[$variable];
                        }
                    } catch (\Throwable $e) {
                    }
                }

                $logs = RawItemLogger::getInstance()->getLogs($importedRawDataItem['id']);

                try {
                    $user = Helper::getUser();
                    $jsParams = [
                        'response' => $response,
                        'request' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getRequest(),
                        'rawItemData' => $importedRawDataItem['data'],
                        'virtualFields' => $importedRawDataItem['virtualFields'],
                        'logs' => $logs,
                        'objectIDs' => $importedRawDataItem['objectIDs'] ?? [],
                        'context' => [
                            'dataportId' => $dataport['id'],
                            'dataport' => [
                                'id' => $dataport['id'],
                                'name' => $dataport['name'],
                            ],
                            'user' => [
                                'id' => $user->getId(),
                                'username' => $user->getUsername()
                            ],
                            'resource' => \json_decode(reset($dataportResources)['resource'], true),
                            'count' => $realTotalOfAllDataportResources,
                            'statusKey' => $statusKey
                        ],
                        'transfer' => $transfer,
                        'lastCall' => $lastCall && $processed === $countImportedRawDataItems - 1,
                        'logger' => $this->logger,
                        'field' => 'Result callback function',
                        'translator' => $this->translationHelper,
                    ];
                    if (!empty($contextParams)) {
                        $jsParams['context'] = array_merge($jsParams['context'], $contextParams);
                    }

                    if ($dataport['targetconfig']['javascriptEngine'] === CallbackFunction::ENGINE_PHP) {
                        $jsParams['rawItemData'] = new \Sylphen\DataBridgeBundle\lib\Pim\Item\RawItem($jsParams['rawItemData']);
                    }

                    $return = CallbackFunction::evaluateScript(
                        $resultCallback['calculation'],
                        $dataport['targetconfig']['javascriptEngine'],
                        $jsParams
                    );

                    if (is_scalar($return)) {
                        $response->setContent((string)$return);
                    } elseif ($return instanceof BackingUpResponse) {
                        $response = $return;
                    }
                    $processed++;
                } catch (\Throwable $ex) {
                    if (!$ex instanceof ErrorException || !in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                        $this->logger->alert('Error while executing result callback function: '.$ex);
                        continue;
                    }

                    $this->logger->warning('Error while executing result callback function: '.$ex);
                }
            }
        }

        $countRawItems = count($rawItems);
        $done += $countRawItems;

        $offset += $countRawItems;

        RawItemLogger::getInstance()->clearLogs();
    }
}
