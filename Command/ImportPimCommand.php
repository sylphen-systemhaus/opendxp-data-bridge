<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\lib\Pim\BackingUpResponse;
use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importmanager;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\ConsoleLoggerFactory;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LogFormatter;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\Tools\Installer;
use JsonException;
use Monolog\Handler\StreamHandler;
use OpenDxp;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use OpenDxp\Model\User;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use OpenDxp\Model\WebsiteSetting;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ImportPimCommand extends AbstractCommand
{
    use \InSquare\OpendxpProcessManagerBundle\ExecutionTrait;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage, Profiler $profiler = null)
    {
        parent::__construct();
        $this->tokenStorage = $tokenStorage;

        if ($profiler) {
            $profiler->disable();
        }
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:process';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import rawdata into pimcore objects')
            ->addArgument('dataport', InputArgument::REQUIRED, 'Dataport ID or name to process')
            ->addArgument('rawitem', InputArgument::OPTIONAL, 'IDs of raw items to import (comma-separated or <from id>-<to id>)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force execution regardless of other processes importing data with this dataport')
            ->addOption('parameters', 'p', InputOption::VALUE_REQUIRED, 'Provide parameter values (e.g. to be used in callback functions), JSON (\'{"param1":1,"param2":["abc","def"]}\') and URL notation (\'param1=1&param2[]=abc&param2[]=def\') are supported')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only output what would happen, no data will be changed')
            ->addOption('ignore-hash-check', null, InputOption::VALUE_NONE, 'Alias for --force')
            ->addOption('status-key', null, InputOption::VALUE_REQUIRED, 'Job Id to retrieve response document for async processes')
            ->addOption('monitoring-item-id', null, InputOption::VALUE_REQUIRED, 'Contains the monitoring item id of elements/process-manager-bundle')
            ->addOption('dataport-resource-id', null, InputOption::VALUE_REQUIRED, 'Dataport resource id, omit to import all raw data of given dataport')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of processed raw data items')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User Id to use for permission checking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force') || $input->getOption('ignore-hash-check');

        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch(new GenericEvent(), 'pim.initPimImport');

        $dataportId = $input->getArgument('dataport');
        $dataports = Dataport::getInstance();
        $dataport = $dataports->get($dataportId);

        if (!$dataport) {
            $output->writeln('Dataport '.$dataportId.' not found');

            if (!is_numeric($dataportId)) {
                $allDataports = $dataports->find();
                $distances = [];
                foreach ($allDataports as $dataport) {
                    $distances[$dataport['name']] = levenshtein($dataportId, $dataport['name']);
                }
                asort($distances);

                $distances = array_filter($distances, static function ($distance) {
                    return $distance < 5;
                });

                if (count($distances) > 0) {
                    $output->writeln('Did you mean one of the following?');
                    foreach (array_keys($distances) as $similarDataportName) {
                        $output->writeln('* '.$similarDataportName);
                    }
                }
            }

            return 1;
        }

        $dataportId = $dataport['id'];

        $rawItemIds = array_filter(explode(',', (string)$input->getArgument('rawitem')));
        $rawItemRepository = RawItem::getInstance();
        foreach($rawItemIds as $index => $rawItemId) {
            if(preg_match('/^(\w+)-(\w*)/', $rawItemId, $matches)) {
                if(!empty($matches[2])) {
                    $rawItemIdRange = PimcoreDbRepository::getInstance()->findInSql('SELECT rawitem.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ? AND rawitem.id BETWEEN ? AND ?', [$dataportId, Uuid::fromInteger($matches[1])->getBytes(),
                        Uuid::fromInteger($matches[2])->getBytes()]);
                } else {
                    $startRawItem = $rawItemRepository->get(Uuid::fromInteger($matches[1])->getBytes());
                    if($startRawItem) {
                        $rawItemIdRange = PimcoreDbRepository::getInstance()->findInSql(
                            'SELECT rawitem.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ? AND dataport_resource.id = ? AND priority >= ?',
                            [$dataportId, $startRawItem['dataport_resource_id'], $startRawItem['priority']]
                        );
                    } else {
                        $rawItemIdRange = [];
                    }
                }

                foreach ($rawItemIdRange as $rawItemRangeRow) {
                    $rawItemIds[] = $rawItemRangeRow['id'];
                }
                unset($rawItemIds[$index]);
            } else {
                $rawItemIds[$index] = Uuid::fromInteger($rawItemId)->getBytes();
            }
        }
        $rawItemIds = array_values(array_unique($rawItemIds));

        if (count($rawItemIds) === 0 && $input->getArgument('rawitem')) {
            $output->writeln('No raw data found');
            return 0;
        }

        if ($input->getOption('user')) {
            $user = User::getById($input->getOption('user'));
            if ($user instanceof User) {
                $userProxy = new \OpenDxp\Security\User\User($user);
                if (Kernel::MAJOR_VERSION > 10) {
                    $token = new UsernamePasswordToken($userProxy, 'opendxp_admin', $userProxy->getRoles());
                } elseif (Kernel::MAJOR_VERSION > 5 || (Kernel::MAJOR_VERSION == 5 && Kernel::MINOR_VERSION >= 4)) {
                    $token = new UsernamePasswordToken($userProxy, 'admin', $userProxy->getRoles());
                } else {
                    $token = new UsernamePasswordToken($userProxy, $user->getPassword(), 'admin', $userProxy->getRoles());
                }

                $this->tokenStorage->setToken($token);
            }
        }

        $monitoringItemId = $input->getOption('monitoring-item-id');

        $predefinedStatusKey = $input->getOption('status-key');
        if ($predefinedStatusKey === null) {
            $predefinedStatusKey = uniqid('', true);
        }

        $isParallelSubProcess = false;
        if(preg_match('/-\d+$/', $predefinedStatusKey, $parallelProcessIndex) && $parallelProcessIndex[0] >= 3) {
            $isParallelSubProcess = true;
        }

        $importType = ImportStatus::TYPE_PIM;
        if ($input->getOption('dry-run')) {
            $importType |= ImportStatus::TYPE_DRY_RUN;
        }

        $dataportResourceIds = array_filter(explode(',', (string)$input->getOption('dataport-resource-id')));
        if (empty($rawItemIds) && $dataport['targetconfig']['itemClass'] && !empty($dataport['targetconfig']['parallelProcesses']) && $dataport['targetconfig']['parallelProcesses'] > 1 && !$isParallelSubProcess) {
            $parallelProcessCount = 4;
            $maxParallelProcessesSetting = WebsiteSetting::getByName('queue_processor.max_processing');
            if ($maxParallelProcessesSetting instanceof WebsiteSetting) {
                $parallelProcessCount = (int)$maxParallelProcessesSetting->getData();
            }

            if ($force) {
                $options[] = '-f';
            }
            if ($monitoringItemId) {
                $options[] = '--monitoring-item-id='.$monitoringItemId;
            }

            if (Helper::getUser()->getId()) {
                $options[] = '--user='.Helper::getUser()->getId();
            }

            if (!$dataportResourceIds) {
                $query = 'SELECT dataport_resource.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ? GROUP BY dataport_resource.id ORDER BY dataport_resource.resource';
                $parameters = [$dataportId];

                $dataportResourceIds = PimcoreDbRepository::getInstance()->findInSql($query, $parameters);
                $dataportResourceIds = array_map(
                    static function ($dataportResource) {
                        return $dataportResource['id'];
                    },
                    $dataportResourceIds
                );
            }

            $rawItemCount = $rawItemRepository->countRows(['dataport_resource_id IN (?)' => $dataportResourceIds], 'priority');

            $dbNow = new \DateTime();
            $logFileObject = ImportStatus::getInstance()->create([
                'key' => $predefinedStatusKey,
                'dataport_id' => $dataportId,
                'dataport_resource_id' => reset($dataportResourceIds),
                'startDate' => $dbNow,
                'endDate' => $dbNow,
                'lastUpdate' => $dbNow,
                'importType' => $importType,
                'status' => ImportStatus::STATUS_FINISHED,
                'totalItems' => $rawItemCount,
                'command_parameters' => json_encode(
                    [
                        '-f' => $force,
                        '--dry-run' => ($input->getOption('dry-run') ? 1 : 0)
                    ]
                )
            ]);

            $importer = \OpenDxp::getContainer()->get(ImporterInterface::class);
            $logger = $importer->getLogger();

            if ($logFileObject instanceof \Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject && method_exists($logger, 'setLogFileObject')) {
                $logger->setLogFileObject($logFileObject);
            }

            $parallelProcessCount = min($parallelProcessCount, $rawItemCount);

            $rawItemsPerProcess = ceil($rawItemCount / $parallelProcessCount);

            for ($offset = 0; $offset < $rawItemCount; $offset += $rawItemsPerProcess) {
                $rawItemIdMin = Uuid::fromBytes($rawItemRepository->findOne(['dataport_resource_id IN (?)' => $dataportResourceIds], 'id', $offset, 'priority')['id']);
                $rawItemIdMax = Uuid::fromBytes($rawItemRepository->findOne(['dataport_resource_id IN (?)' => $dataportResourceIds], 'id', min($rawItemCount - 1, $offset + $rawItemsPerProcess - 1))['id'] ?? $rawItemIdMin, 'priority');

                $commandOptions = $options;
                $commandOptions[] = '--status-key='.$predefinedStatusKey.'-'.(3 + ceil($offset / $rawItemsPerProcess));

                if ($rawItemIdMin != $rawItemIdMax) {
                    $rawItems = $rawItemIdMin->getInteger().'-'.$rawItemIdMax->getInteger();
                } else {
                    $rawItems = $rawItemIdMin->getInteger();
                }

                $command = 'data-bridge:process '.$dataportId.' '.$rawItems.' '.implode(' ', $commandOptions);
                $logger->info('Queueing "'.$command.'" to be executed in parallel');

                Queue::getInstance()->create(['command' => $command, 'triggered_by' => 'Parallel child process for parallel execution']);
            }

            return 0;
        }

        $output->writeln('Starting raw data processing for dataport "'.$dataport['name'].'" (#'.$dataportId.')');
        if (!empty($rawItemIds)) {
            if(count($rawItemIds) <= 10) {
                $output->writeln(
                    'Will only import raw items with ids '.implode(',', array_map(static function ($rawItemId) {
                        return Uuid::fromBytes($rawItemId)->getInteger();
                    }, $rawItemIds))
                );
            } else {
                $output->writeln(
                    'Will only import raw items '.Uuid::fromBytes($rawItemIds[0])->getInteger().' - '.Uuid::fromBytes(end($rawItemIds))->getInteger()
                );
            }
        }

        $request = Helper::getRequest();

        $parameterString = $input->getOption('parameters');
        if ($parameterString) {
            if (strlen($parameterString) < 1000 && file_exists($parameterString)) {
                $parameterString = file_get_contents($parameterString);
            }

            try {
                $parameters = Helper::json_decode($parameterString);
            } catch (JsonException $e) {
                parse_str($parameterString, $parameters);
            }

            if (is_array($parameters)) {
                $request->attributes->add($parameters);
            }
        }

        $limit = $input->getOption('limit');
        if ((int)$limit <= 0) {
            $limit = null; // Ignore invalid limit values
        }


        $start = microtime(true);

        $result = self::import($dataportId, $dataportResourceIds, $rawItemIds, $force, $monitoringItemId, $limit, $input->getOption('status-key'), $output, $importType);

        if($result instanceof BackingUpResponse && $result->hasContent()) {
            $output->writeln(sprintf('HTTP/%s %s %s', $result->getProtocolVersion(), $result->getStatusCode(), Response::$statusTexts[$result->getStatusCode()] ?? 'unknown status'));
            $output->writeln((string)$result->headers);
            $responseStream = $result->getOutputStream();
            while (!feof($responseStream)) {
                $output->write(fgets($responseStream, 4096));
            }
            $output->writeln('');
        } elseif($result->getContent()) {
            $output->writeln($result);
        }

        $duration = round(microtime(true) - $start, 2);

        $output->writeln('Duration: '.$duration.' seconds, Peak memory: '.formatBytes(memory_get_peak_usage()));

        return 0;
    }

    public static function import($dataportId, array $dataportResourceIds = [], array $rawItemIds = [], bool $force = false, $monitoringItemId = null, $limit = null, $statusKey = null, OutputInterface $output = null, $importType = ImportStatus::TYPE_PIM): Response
    {
        /** @var ImporterInterface $importer */
        $importer = \OpenDxp::getContainer()->get(ImporterInterface::class);
        $logger = $importer->getLogger();

        $monitoringItem = null;
        if ($monitoringItemId) {
            self::initProcessManager($monitoringItemId);
            $monitoringItem = self::getMonitoringItem();

            if (count($monitoringItem->getLoggers())) {
                $logger = $monitoringItem->getLogger();
            }
        }

        if ($logger instanceof Logger) {
            if (!$force && !Helper::getUser()->isAdmin()) {
                $logger->enableIntelligentLogging();
                $logger->setIntelligentLogLevel(LogLevel::NOTICE);
            }

            $logger->setMinLogLevel(LogLevel::INFO);
            if ($output instanceof OutputInterface) {
                if ($output->isDebug()) {
                    $logger->setMinLogLevel(LogLevel::DEBUG);
                } elseif ($output->isQuiet()) {
                    $logger->setMinLogLevel(LogLevel::ERROR);
                }
            }
        }

        if($output instanceof OutputInterface && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $logger->addLogger(ConsoleLoggerFactory::getConsoleLogger());
        }

        if ($logger instanceof LoggerInterface && $importer instanceof LoggerAwareInterface) {
            $importer->setLogger($logger);
        }

        $importManager = new Importmanager($importer, $logger, $force);
        if ($monitoringItem !== null) {
            $importManager->setMonitoringItem($monitoringItem);
        }

        if (!$dataportResourceIds) {
            $query = 'SELECT dataport_resource.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ?';
            $parameters = [$dataportId];
            if (!empty($rawItemIds)) {
                $query .= ' AND rawitem.id IN ('.rtrim(str_repeat('?,', count($rawItemIds)), ',').')';
                $parameters = array_merge($parameters, $rawItemIds);
            }
            $query .= ' GROUP BY dataport_resource.id ORDER BY dataport_resource.resource';

            $dataportResourceIds = PimcoreDbRepository::getInstance()->findInSql($query, $parameters);
            $dataportResourceIds = array_map(
                static function ($dataportResource) {
                    return $dataportResource['id'];
                },
                $dataportResourceIds
            );
        }

        foreach($dataportResourceIds as $dataportResourceId) {
            $dataportResource = DataportResource::getInstance()->get($dataportResourceId);
            if($dataportResource) {
                $resource = \json_decode($dataportResource['resource'], true);
                if (!empty($resource['locale'])) {
                    \OpenDxp::getContainer()->get(\OpenDxp\Localization\LocaleServiceInterface::class)->setLocale($resource['locale']);
                }
            }
        }

        if($statusKey !== null) {
            if(strpos($statusKey, '-') === false) {
                $statusKey = $statusKey.'-2';
            }
        } else {
            $statusKey = uniqid('', true);
        }
        $dbNow = new \DateTime();
        ImportStatus::getInstance()->create(
            [
                'key' => $statusKey,
                'dataport_id' => $dataportId,
                'startDate' => $dbNow,
                'lastUpdate' => $dbNow,
                'importType' => ImportStatus::TYPE_PIM,
                'command_parameters' => json_encode(
                    [
                        '-f' => $force,
                        '--dataport-resource-id' => implode(',', $dataportResourceIds),
                        'rawitem' => implode(',',
                            array_map(static function ($rawItemId) {
                                return Uuid::fromBytes($rawItemId)->getInteger();
                            }, $rawItemIds)
                        )
                    ]
                )
            ]
        );

        $params = [
            'force' => $force,
            'importType' => ImportStatus::getImportTypeDescription($importType),
        ];

        return $importManager->importDataport($dataportResourceIds, $importType, $rawItemIds, $statusKey, $limit, $params);
    }
}
