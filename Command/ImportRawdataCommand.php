<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\ConsoleLoggerFactory;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LogFormatter;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use JsonException;
use Monolog\Handler\StreamHandler;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\User;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Sylphen\DataBridgeBundle\lib\Pim\RawData;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ImportRawdataCommand extends AbstractCommand
{
    use \InSquare\OpendxpProcessManagerBundle\ExecutionTrait;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        parent::__construct();
        $this->tokenStorage = $tokenStorage;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:extract';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Extract raw data from import resource (e.g. file / URL) to database')
            ->setHelp('After execution the data of the defined raw data fields appears in the "Preview" panel of the given dataport')
            ->addArgument('dataport', InputArgument::REQUIRED, 'Dataport ID or name to process')
            ->addArgument('filename', InputArgument::OPTIONAL, 'Name of the file / source to import. Default: Source of the dataport config')
            ->addOption('parameters', 'p', InputOption::VALUE_REQUIRED, 'Provide parameter values (e.g. for placeholder variables in import resource), JSON (\'{"param1":1,"param2":["abc","def"]}\') and URL notation (\'param1=1&param2[]=abc&param2[]=def\') are supported')
            ->addOption('clear-file-after-import', null, InputOption::VALUE_NONE, 'Alias for --rm')
            ->addOption('rm', null, InputOption::VALUE_NONE, 'Remove used import file(s) after extracting data')
            ->addOption('status-key', null, InputOption::VALUE_REQUIRED, 'Job Id to retrieve response document for async processes')
            ->addOption('monitoring-item-id', null, InputOption::VALUE_REQUIRED, 'Contains the monitoring item id of elements/process-manager-bundle')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language to be used for dataports with import source type "Pimcore" (can be overridden by explicitly setting locale in raw data fields\' data query selectors - e.g. name#en)', null)
            ->addOption('dataport-resource-id', null, InputOption::VALUE_REQUIRED, 'Import resource id which the fetched values shall be added to (mainly to update raw data for automatic imports)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force execution even if nothing changed since last execution')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User Id to use for permission checking')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of raw data items to be extracted. Can also be "<offset>,<limit>" or "<offset>,INF" to retrieve all items after the nth')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch(new GenericEvent(), 'pim.initRawdataImport');

        $dataportId = $input->getArgument('dataport');
        $dataports = Dataport::getInstance();
        $dataport = $dataports->get($dataportId);

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

        if(!$dataport) {
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

                if(count($distances) > 0) {
                    $output->writeln('Did you mean one of the following?');
                    foreach(array_keys($distances) as $similarDataportName) {
                        $output->writeln('* '.$similarDataportName);
                    }
                }
            }

            return 1;
        }

        $dataportId = $dataport['id'];

        $filename = $input->getArgument('filename');
        $removeFileAfterImport = $input->getOption('clear-file-after-import') || $input->getOption('rm');

        $output->writeln('Starting rawdata import for dataport '. $dataportId);
        if (empty($filename)) {
            $output->writeln('Using default source');
        } else {
            $output->writeln('Using ' . $filename);
        }

        $start = microtime(true);

        /** @var RawData\Importmanager $importManager */
        $importManager = \OpenDxp::getContainer()->get(RawData\Importmanager::class);

        $importManager->setOverrideFile($filename);
        $importManager->setRemoveFileAfterImport($removeFileAfterImport);
        $importManager->setLocale($input->getOption('locale'));
        $importManager->setForce($input->getOption('force'));
        $importManager->setLimit($input->getOption('limit'));

        $logger = $importManager->getLogger();
        $monitoringItemId = $input->getOption('monitoring-item-id');
        if($monitoringItemId) {
            self::initProcessManager($monitoringItemId);
            $monitoringItem = self::getMonitoringItem();
            $importManager->setMonitoringItem($monitoringItem);

            if (count($monitoringItem->getLoggers())) {
                $logger = $monitoringItem->getLogger();
            }
        }

        if ($logger instanceof Logger) {
            if(!$importManager->getForce() && !Helper::getUser()->isAdmin()) {
                $logger->enableIntelligentLogging();
                $logger->setIntelligentLogLevel(LogLevel::NOTICE);
            }

            $logger->setMinLogLevel(LogLevel::INFO);
            if ($output->isDebug()) {
                $logger->setMinLogLevel(LogLevel::DEBUG);
            } elseif ($output->isQuiet()) {
                $logger->setMinLogLevel(LogLevel::ERROR);
            }
        }

        if ($output instanceof OutputInterface && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $logger->addLogger(ConsoleLoggerFactory::getConsoleLogger());
        }

        if ($logger instanceof LoggerInterface && $importManager instanceof LoggerAwareInterface) {
            $importManager->setLogger($logger);
        }

        $request = Helper::getRequest();

        $commandParameters = [
            '--rm' => $removeFileAfterImport,
        ];

        $commandParameters['file'] = $input->getArgument('filename');

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
                $commandParameters['--parameters'] = $parameters;
            }
        }

        $dbNow = new \DateTime();
        $statusKey = $input->getOption('status-key');
        if (!$statusKey) {
            $statusKey = uniqid('', true);
        }
        ImportStatus::getInstance()->create(
            [
                'key' => $statusKey,
                'dataport_id' => $dataportId,
                'command_parameters' => json_encode($commandParameters),
                'startDate' => $dbNow,
                'lastUpdate' => $dbNow,
                'importType' => ImportStatus::TYPE_RAWDATA
            ]
        );

        $importManager->importDataport($dataportId, ImportStatus::TYPE_RAWDATA, $input->getOption('dataport-resource-id'), $statusKey);

        $duration = round(microtime(true) - $start, 2);

        $output->writeln('Duration: ' . $duration . ' seconds, Peak memory: ' . formatBytes(memory_get_peak_usage()));

        return 0;
    }
}