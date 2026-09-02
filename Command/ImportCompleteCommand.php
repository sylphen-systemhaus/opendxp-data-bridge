<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
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
use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\lib\Pim\RawData;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;
use Google\Service\Transcoder\Output;
use JsonException;
use \OpenDxp\Security\User\User;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Log\FileObject;
use OpenDxp\Model\WebsiteSetting;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ImportCompleteCommand extends AbstractCommand
{
    use \InSquare\OpendxpProcessManagerBundle\ExecutionTrait;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    public static function getDefaultName(): string
    {
        return 'data-bridge:complete';
    }

    public function __construct(TokenStorageInterface $tokenStorage, Profiler $profiler = null)
    {
        parent::__construct();
        $this->tokenStorage = $tokenStorage;

        if ($profiler) {
            $profiler->disable();
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Execute dataport incl. raw data extraction and dataport processing (shortcut for data-bridge:extract followed by data-bridge:process')
            ->addArgument('dataport', InputArgument::REQUIRED, 'Dataport ID or name to process')
            ->addArgument('filename', InputArgument::OPTIONAL, 'Name of the file to import. Default: File from the dataport config')
            ->addOption('parameters', 'p', InputOption::VALUE_REQUIRED, 'Provide parameter values (e.g. for placeholder variables in import resource), JSON (\'{"param1":1,"param2":["abc","def"]}\') and URL notation (\'param1=1&param2[]=abc&param2[]=def\') are supported')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only output what would happen, no data will be changed')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip hash check and force all raw data to be imported')
            ->addOption('ignore-hash-check', null, InputOption::VALUE_NONE, 'Alias for --force')
            ->addOption('clear-file-after-import', null, InputOption::VALUE_NONE, 'Use this param to remove given file after import')
            ->addOption('rm', null, InputOption::VALUE_NONE, 'Alias for --clear-file-after-import')
            ->addOption('status-key', null, InputOption::VALUE_REQUIRED, 'Job Id to retrieve response document for async processes')
            ->addOption('monitoring-item-id', null, InputOption::VALUE_REQUIRED, 'Contains the monitoring item id of elements/process-manager-bundle')
            ->addOption('dataport-resource-id', null, InputOption::VALUE_REQUIRED, 'Dataport resource id, omit to import all raw data of given dataport')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language to be used for export dataports', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of processed raw data items. Can also be "<offset>,<limit>" or "<offset>,INF" to retrieve all items after the nth')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User Id to use for permission checking')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('user')) {
            $user = \OpenDxp\Model\User::getById($input->getOption('user'));
            if ($user instanceof \OpenDxp\Model\User) {
                $userProxy = new User($user);

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

        $output->writeln('Starting complete import for dataport "'.$dataport['name'].'" (#'.$dataportId.')');

        $filename = $input->getArgument('filename');
        if (empty($filename)) {
            $output->writeln('Using default file');
        } else {
            $output->writeln('Using '.$filename);
        }

        $removeFileAfterImport = $input->getOption('clear-file-after-import') || $input->getOption('rm');
        $force = $input->getOption('force') || $input->getOption('ignore-hash-check');
        $monitoringItemId = $input->getOption('monitoring-item-id');
        $locale = $input->getOption('locale');

        $request = Helper::getRequest();
        $parameterString = $input->getOption('parameters');
        if ($parameterString) {
            if(strlen($parameterString) < 1000 && file_exists($parameterString)) {
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

        if(!$force) {
            $runningProcesses = ImportStatus::getInstance()->find([
                'dataport_id = ?' => $dataportId,
                'status = ?' => ImportStatus::STATUS_RUNNING,
            ]);

            $counter = new CpuCoreCounter();

            try {
                $cpuCount = $counter->getCount();
            } catch (NumberOfCpuCoreNotFound $e) {
                $cpuCount = 4;
            }

            if(count($runningProcesses) > 4 * $cpuCount) {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $question = new ChoiceQuestion('There are already '.count($runningProcesses).' processes running for dataport '.$dataportId.'. Continue to start new process, add this process to queue or abort? Defaults to "queue"', ['continue', 'queue', 'abort'], 1);
                $question->setErrorMessage('Please enter either "continue" to execute your command, "queue" to add your command to the queue (will get executed when other processes in queue are finished) or "abort"');

                $answer = $helper->ask($input, $output, $question);
                if ($answer === 'abort') {
                    return 0;
                }

                if($answer === 'queue') {
                    $command = '';
                    foreach($this->getDefinition()->getArguments() as $argument) {
                        if($input->hasArgument($argument->getName())) {
                            $command .= ' "'.$input->getArgument($argument->getName()).'"';
                        }
                    }

                    foreach($this->getDefinition()->getOptions() as $option) {
                        if ($input->getOption($option->getName())) {
                            $command .= ' --'.$option->getName();
                            if($option->acceptValue() && $input->getOption($option->getName())) {
                                $command .= '="'.$input->getOption($option->getName()).'"';
                            }
                        }
                    }

                    Queue::getInstance()->create([
                        'command' => $command,
                        'triggered_by' => 'Parallel processes limit exceeded',
                        'worker_id' => $dataportId
                    ]);

                    return 0;
                }
            }
        }

        $dataportResourceId = $input->getOption('dataport-resource-id');

        $result = self::import($dataportId, $filename, $removeFileAfterImport, $force, $locale, $monitoringItemId, $input->getOption('limit'), $input->getOption('status-key'), $output, $dataportResourceId, (bool)$input->getOption('dry-run'));

        if ($result instanceof BackingUpResponse && $result->hasContent()) {
            $output->writeln(sprintf('HTTP/%s %s %s', $result->getProtocolVersion(), $result->getStatusCode(), Response::$statusTexts[$result->getStatusCode()] ?? 'unknown status'));
            $output->writeln((string)$result->headers);
            $responseStream = $result->getOutputStream();
            while (!feof($responseStream)) {
                $output->write(fgets($responseStream, 4096));
            }
            $output->writeln('');
        } else {
            $output->writeln('X-Data-Bridge-Run: '.$result->headers->get('X-Data-Bridge-Run'));
            if ($result->getContent()) {
                $output->writeln($result);
            }
        }

        return 0;
    }

    public static function import($dataportId, $filename = null, $removeFileAfterImport = false, $force = false, $locale = null, $monitoringItemId = null, $limit = null, $predefinedStatusKey = null, OutputInterface $output = null, $dataportResourceId = null, $dryRun = false) {
        // Rawdata
        /** @var RawData\Importmanager $importer */
        $importer = \OpenDxp::getContainer()->get(RawData\Importmanager::class);

        $importer->setOverrideFile($filename);
        $importer->setRemoveFileAfterImport($removeFileAfterImport);
        $importer->setLocale($locale);
        $importer->setForce($force);
        $dataport = Dataport::getInstance()->get($dataportId);
        if (empty($dataport['sourceconfig']['autoImport'])) {
            $importer->setLimit($limit);
        }

        $logger = $importer->getLogger();
        $monitoringItem = null;
        if($monitoringItemId) {
            self::initProcessManager($monitoringItemId);
            $monitoringItem = self::getMonitoringItem();
            $importer->setMonitoringItem($monitoringItem);

            if(count($monitoringItem->getLoggers())) {
                $logger = $monitoringItem->getLogger();
            }
        }

        if($logger instanceof Logger) {
            if(!$force && !Helper::getUser()->isAdmin()) {
                $logger->enableIntelligentLogging();
                $logger->setIntelligentLogLevel(LogLevel::NOTICE);
            }

            $logger->setMinLogLevel(LogLevel::INFO);
            if($output instanceof OutputInterface) {
                if ($output->isDebug()) {
                    $logger->setMinLogLevel(LogLevel::DEBUG);
                } elseif ($output->isQuiet()) {
                    $logger->setMinLogLevel(LogLevel::ERROR);
                }
            }
        }

        if ($output instanceof OutputInterface && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $logger->addLogger(ConsoleLoggerFactory::getConsoleLogger());
        }

        if ($logger instanceof LoggerInterface && $importer instanceof LoggerAwareInterface) {
            $importer->setLogger($logger);
        }

        if ($predefinedStatusKey === null) {
            $predefinedStatusKey = uniqid('', true);
        }

        $statusKey = $predefinedStatusKey.'-1';

        $dbNow = new \DateTime();
        ImportStatus::getInstance()->create(
            [
                'key' => $statusKey,
                'dataport_id' => $dataportId,
                'startDate' => $dbNow,
                'lastUpdate' => $dbNow,
                'importType' => ImportStatus::TYPE_RAWDATA | ImportStatus::TYPE_COMPLETE,
                'command_parameters' => json_encode(
                    [
                        'filename' => $filename,
                        '--rm' => $removeFileAfterImport,
                        '-f' => $force,
                    ]
                )
            ]
        );

        $dataportResourceId = $importer->importDataport($dataportId, ImportStatus::TYPE_RAWDATA | ImportStatus::TYPE_COMPLETE, $dataportResourceId, $statusKey);

        if($dataportResourceId === null) {
            return new Response();
        }

        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch(new GenericEvent(), 'pim.initPimImport');

        /** @var ImporterInterface $importer */
        $importer = \OpenDxp::getContainer()->get(ImporterInterface::class);
        $logger = $importer->getLogger();
        if ($logger instanceof Logger) {
            if (!$force && !Helper::getUser()->isAdmin()) {
                $logger->enableIntelligentLogging();
                $logger->setIntelligentLogLevel(LogLevel::NOTICE);
            }

            $logger->setMinLogLevel(LogLevel::INFO);
            if ($output instanceof OutputInterface) {
                if ($output->isVeryVerbose()) {
                    $logger->setMinLogLevel(LogLevel::DEBUG);
                } elseif ($output->isQuiet()) {
                    $logger->setMinLogLevel(LogLevel::ERROR);
                }
            }
        }

        if($logger instanceof LoggerInterface && $importer instanceof LoggerAwareInterface) {
            $importer->setLogger($logger);
        }

        $statusKey = $predefinedStatusKey.'-2';

        $startTime = new \DateTimeImmutable();

        $importType = ImportStatus::TYPE_PIM | ImportStatus::TYPE_COMPLETE;
        if ($dryRun) {
            $importType |= ImportStatus::TYPE_DRY_RUN;
        }
        $logFileObject = ImportStatus::getInstance()->create(
            [
                'key' => $statusKey,
                'dataport_id' => $dataportId,
                'dataport_resource_id' => $dataportResourceId,
                'importType' => $importType,
                'startDate' => $startTime,
                'lastUpdate' => $startTime,
                'status' => ImportStatus::STATUS_RUNNING,
                'command_parameters' => json_encode([
                    '--rm' => $removeFileAfterImport,
                    '-f' => $force,
                    '--dataport-resource-id' => $dataportResourceId,
                    '--locale' => $locale,
                    '--dry-run' => ($dryRun ? 1 : 0)
                ])
            ]
        );
        if ($logFileObject instanceof \Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject && method_exists($logger, 'setLogFileObject')) {
            $logger->setLogFileObject($logFileObject);
        }


        // parallelize imports
        $dataport = Dataport::getInstance()->get($dataportId);
        if($dataport['targetconfig']['itemClass'] && !empty($dataport['targetconfig']['parallelProcesses']) && $dataport['targetconfig']['parallelProcesses'] > 1) {
            $parallelProcessCount = $dataport['targetconfig']['parallelProcesses'] * 2;
            $maxParallelProcessesSetting = WebsiteSetting::getByName('queue_processor.max_processing');
            if ($maxParallelProcessesSetting instanceof WebsiteSetting) {
                $parallelProcessCount = min((int)$maxParallelProcessesSetting->getData(), $parallelProcessCount);
            }

            $options = ['--dataport-resource-id='.$dataportResourceId];

            if ($limit) {
                $options[] = '--limit='.$limit;
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

            $rawItemRepository = RawItem::getInstance();
            $rawItemCount = $rawItemRepository->countRows(['dataport_resource_id = ?' => $dataportResourceId], 'priority');

            if($rawItemCount > 1) {
                ImportStatus::getInstance()->update([
                    'totalItems' => $rawItemCount,
                    'endDate' => $startTime,
                    'status' => ImportStatus::STATUS_FINISHED
                ], ['key' => $statusKey]);

                $parallelProcessCount = min($parallelProcessCount, $rawItemCount);

                $rawItemsPerProcess = ceil($rawItemCount / $parallelProcessCount);

                for ($offset = 0; $offset < $rawItemCount; $offset += $rawItemsPerProcess) {
                    $rawItemIdMin = Uuid::fromBytes($rawItemRepository->findOne(['dataport_resource_id = ?' => $dataportResourceId], 'id', $offset, 'priority')['id']);
                    $rawItemIdMax = Uuid::fromBytes($rawItemRepository->findOne(['dataport_resource_id = ?' => $dataportResourceId], 'id', min($rawItemCount - 1, $offset + $rawItemsPerProcess - 1))['id'] ?? $rawItemIdMin, 'priority');

                    $commandOptions = $options;
                    if ($predefinedStatusKey !== null) {
                        $commandOptions[] = '--status-key='.$predefinedStatusKey.'-'.(3 + ceil($offset / $rawItemsPerProcess));
                    }

                    if ($rawItemIdMin != $rawItemIdMax) {
                        $rawItems = $rawItemIdMin->getInteger().'-'.$rawItemIdMax->getInteger();
                    } else {
                        $rawItems = $rawItemIdMin->getInteger();
                    }

                    $command = 'data-bridge:process '.$dataportId.' '.$rawItems.' '.implode(' ', $commandOptions);
                    $logger->info('Queueing "'.$command.'" to be executed in parallel');

                    Queue::getInstance()->create(['command' => $command, 'triggered_by' => 'Parallel child process for parallel execution']);
                }

                return new Response();
            }
        }

        $importManager = new Importmanager($importer, $logger, $force);

        if($monitoringItem !== null) {
            $importManager->setMonitoringItem($monitoringItem);
        }

        $params = [
            'force' => $force,
            'importType' => ImportStatus::getImportTypeDescription($importType),
        ];

        return $importManager->importDataport(
            [$dataportResourceId],
            $importType,
            null,
            $statusKey,
            empty($dataport['sourceconfig']['autoImport']) ? null : $limit,
            $params
        );
    }
}