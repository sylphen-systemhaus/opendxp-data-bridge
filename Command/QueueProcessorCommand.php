<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\SylphenDataBridgeBundle;
use Sylphen\DataBridgeBundle\lib\Pim\LockableTrait;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Carbon\Carbon;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Exception;
use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Config;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use OpenDxp\Db\PhpArrayFileTable;
use OpenDxp\Logger;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Tool\Lock;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use SplQueue;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class QueueProcessorCommand extends AbstractCommand
{
    use LockableTrait;

    const LOCK_KEY = 'SylphenDataBridge_Queue_Processor';

    private $started;

    private $batchStarted;

    /** @var resource */
    private $logFileHandle;

    /** @var ConsoleSectionOutput */
    private $globalSection;

    private $statusMonitorHTML = '';

    /** @var SplQueue[] */
    private $queues = [];

    /** @var array */
    private $processed = [];

    /** @var ProgressBar[] */
    private $progress = [];

    /** @var array */
    private $dataportsSupportingParallelProcesses = [];

    protected function configure(): void
    {
        $this
            ->setName('data-bridge:process-queue')
            ->setDescription('Process queued commands')
        ;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:process-queue';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock(self::LOCK_KEY, false, 300)) {
            $output->writeln('Other queue processor already running');
            return 0;
        }

        $this->queues = [];
        $abortFunction = function () {
            foreach ($this->queues as $remainingQueue) {
                if (!$remainingQueue->isEmpty()) {
                    $queueItem = $remainingQueue->bottom();
                    if ($queueItem['process']->isRunning()) {
                        $queueItem['process']->stop(10, 9); // SIGKILL to not queue continuing dataport
                    }
                }
            }

            die;
        };
        register_shutdown_function($abortFunction);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGINT, $abortFunction); // SIGINT is sent by the TTY driver to the current foreground job when the interactive attention character (typically ^C, which has ASCII code 3) appears in the input stream
            pcntl_signal(SIGTERM, $abortFunction);
            pcntl_signal(SIGHUP, $abortFunction); // SIGHUP is sent by the UART driver to the entire session when a hangup condition has been detected.
        }

        $this->logFileHandle = fopen(OPENDXP_LOG_DIRECTORY.'/data-bridge-queue-processor.html', 'wb');

        $maxParallelProcessesSetting = OpenDxp\Model\WebsiteSetting::getByName('queue_processor.max_processing');
        if (!$maxParallelProcessesSetting instanceof OpenDxp\Model\WebsiteSetting) {
            $maxParallelProcessesSetting = new OpenDxp\Model\WebsiteSetting();
        }

        try {
            $this->globalSection = method_exists($output, 'section') ? $output->section() : $output;
            $this->logProgress('Start queue processing', []);
            $this->started = time();

            $queueRepository = Queue::getInstance();

            $counter = new CpuCoreCounter();

            try {
                $cpuCount = $counter->getCount();
            } catch (NumberOfCpuCoreNotFound $e) {
                $cpuCount = 4;
            }

            $maxParallelProcessesSetting = OpenDxp\Model\WebsiteSetting::getByName('queue_processor.max_processing');
            if (!$maxParallelProcessesSetting instanceof OpenDxp\Model\WebsiteSetting) {
                $maxParallelProcessesSetting = new OpenDxp\Model\WebsiteSetting();
            }
            $maxParallelProcessesSetting->setName('queue_processor.max_processing');
            $maxParallelProcessesSetting->setType('text');

            if(empty($maxParallelProcessesSetting->getData())) {
                $maxParallelProcessesSetting->setData(4 * $cpuCount);
                $maxParallelProcessesSetting->save();
            }

            $maxParallelProcesses = max($maxParallelProcessesSetting->getData(), 1);
            $maxParallelProcesses = min($maxParallelProcesses, 4 * $cpuCount);

            $this->dataportsSupportingParallelProcesses = ['unknown' => 1, 'check_automatic_run' => max($cpuCount, 4)];

            processQueue:

            $this->queues = [];
            $this->progress = [];

            try {
                $maxParallelProcessesSetting->setData(PimcoreDbRepository::getInstance()->findOneInSql('SELECT data FROM website_settings WHERE name=? AND modificationDate > ?', ['queue_processor.max_processing', $this->batchStarted]) ?: $maxParallelProcessesSetting->getData());
            } catch (TableNotFoundException $e) {
                $websiteSettingsConfigFile = Config::locateConfigFile('website-settings.php');
                if (file_exists($websiteSettingsConfigFile) && filemtime($websiteSettingsConfigFile) > $this->batchStarted) {
                    $websiteSettings = new PhpArrayFileTable($websiteSettingsConfigFile);
                    foreach($websiteSettings->fetchAll() as $websiteSetting) {
                        if($websiteSetting['name'] === 'queue_processor.max_processing' && $websiteSetting['modificationDate'] > $this->batchStarted) {
                            $maxParallelProcessesSetting->setData($websiteSetting['data']);
                        }
                    }
                }
            }

            if (Installer::isInMaintenanceMode()) {
                $this->logProgress('Maintenance mode is active, abort queue processing', $this->progress);
                return 0;
            }

            if (!OpenDxp::getContainer()->get(Installer::class)->isInstalled()) {
                $this->logProgress('Please run database migration via bin/console opendxp:bundle:install SylphenDataBridgeBundle (or installation is currently running)', $this->progress);
                return 0;
            }

            $queueRepository->execute('UPDATE '.$queueRepository->getTableName().' SET started_at=NULL');

            $this->batchStarted = time();

            $commandPrefix = '"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console');
            chdir(OPENDXP_PROJECT_ROOT);

            $this->processed = [];

            $this->logProgress('Grouping queue items', []);

            $workers = $queueRepository->findColumnInSql('SELECT worker_id FROM '.Installer::TABLE_QUEUE.' GROUP BY worker_id');
            foreach($workers as $workerId) {
                if ($workerId === 'check_automatic_run' && count($workers) > 1) {
                    // do not start check_automatic_run worker if other workers are active
                    continue;
                }
                $queueItems = $queueRepository->find(['worker_id = ?' => $workerId, 'started_at IS NULL' => null, 'restarts < ?' => 10], 'id', 10000);
                foreach ($queueItems as $queueItem) {
                    $this->addToQueue($queueItem, $output);
                }
            }

            foreach ($this->queues as $batchId => $queue) {
                $this->progress[$batchId]->start($queue->count());
            }

            $parallelProcessesRunning = 0;
            $lockRefresh = 0;
            do {
                if ($lockRefresh < time()) {
                    if ($maxParallelProcessesSetting instanceof OpenDxp\Model\WebsiteSetting) {
                        $maxParallelProcessesSetting->getDao()->getByName('queue_processor.max_processing');
                        $maxParallelProcesses = max($maxParallelProcessesSetting->getData(), 1);
                        $maxParallelProcesses = min($maxParallelProcesses, 4 * $cpuCount);
                    }

                    $this->refreshLock(self::LOCK_KEY);
                    $lockRefresh = time();

                    $queueCount = $queueRepository->countRows();
                    $oldestQueueEntry = Carbon::now()->setTimezone('UTC')->diffForHumans(new DateTimeImmutable($queueRepository->findOneInSql('SELECT MIN(queued_at) FROM '.Installer::TABLE_QUEUE), new DateTimeZone('UTC')), true);
                }

                $this->logProgress('Processing queues, max. parallel processes: '.$maxParallelProcesses.', jobs in queue: '.$queueCount.' (oldest from '.$oldestQueueEntry.' ago)', $this->progress);

                $queueWithItemExists = false;
                uksort($this->queues, static function () {
                    return (mt_rand() > mt_getrandmax() / 2) ? 1 : -1;
                });

                foreach ($this->queues as $batchId => $queue) {
                    if ($queue->isEmpty()) {
                        continue;
                    }

                    $queueWithItemExists = true;

                    $queueItem = $queue->bottom();

                    if ($queueItem['process']->isRunning()) {
                        continue;
                    }

                    if ($queueItem['process']->getStatus() === Process::STATUS_READY) {
                        $remaining = !$this->progress[$batchId]->getProgress() ? 0 : round((time() - $this->progress[$batchId]->getStartTime()) / $this->progress[$batchId]->getProgress() * ($this->progress[$batchId]->getMaxSteps() - $this->progress[$batchId]->getProgress()));
                        if ($remaining == 0) {
                            $estimatedFinish = null;
                        } else {
                            $estimatedFinish = Carbon::now()->addSeconds($remaining)->diffForHumans(null, true);
                        }

                        if ($parallelProcessesRunning < $maxParallelProcesses) {
                            $queueItemData = $queueRepository->get($queueItem['queueItem']);
                            if(!$queueItemData || $queueItemData['started_at']) {
                                $this->progress[$batchId]->advance();
                                $queue->dequeue();

                                if ($queue->isEmpty()) {
                                    $this->progress[$batchId]->setMessage('Finished (last job: '.substr($queueItem['process']->getCommandLine(), 0, 1000).(strlen($queueItem['process']->getCommandLine()) > 1000 ? ' ...' : ''));

                                    unset($this->queues[$batchId]);
                                }

                                continue;
                            }

                            $command = $queueItem['process']->getCommandLine();
                            if (strpos($command, $commandPrefix) !== false) {
                                $command = substr($command, strpos($command, $commandPrefix) + strlen($commandPrefix));
                            }

                            $this->progress[$batchId]->setMessage('Processing '.substr($command, 0, 1000).(strlen($command) > 1000 ? ' ...' : '').($estimatedFinish ? ', will be finished in '.$estimatedFinish : ''));
                            $this->logProgress(null, $this->progress);

                            $queueItem['process']->start();
                            $currentDate = new DateTimeImmutable();
                            $currentDate = $currentDate->setTimezone(new DateTimeZone('UTC'));
                            $queueRepository->update(['started_at' => $currentDate], ['id' => $queueItem['queueItem']]);
                            $parallelProcessesRunning++;
                        } else {
                            $command = $queueItem['process']->getCommandLine();
                            if (strpos($command, $commandPrefix) !== false) {
                                $command = substr($command, strpos($commandPrefix, $commandPrefix) + strlen($commandPrefix));
                            }

                            $this->progress[$batchId]->setMessage('Waiting, next job: '.substr($command, 0, 1000).(strlen($command) > 1000 ? ' ...' : '').($estimatedFinish ? ', will be finished in '.$estimatedFinish : ''));
                            $this->logProgress(null, $this->progress);
                        }
                        continue;
                    }

                    $queue->dequeue();
                    $parallelProcessesRunning--;

                    if ($queueItem['process']->isSuccessful()) {
                        $this->progress[$batchId]->advance();
                        $this->processed[$batchId]++;

                        if ($queue->isEmpty()) {
                            $this->progress[$batchId]->setMessage('Finished (last job: '.substr($queueItem['process']->getCommandLine(), 0, 1000).(strlen($queueItem['process']->getCommandLine()) > 1000 ? ' ...' : ''));

                            unset($this->queues[$batchId]);
                        }

                        $deletedRows = PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_QUEUE.' WHERE id=? AND queued_at <= started_at', [$queueItem['queueItem']]);

                        // restart queue processor every hour
                        if ($deletedRows === 0 || time() - $this->started > 3600) {
                            foreach ($this->queues as $remainingQueue) {
                                if (!$remainingQueue->isEmpty()) {
                                    $queueItem = $remainingQueue->bottom();
                                    if ($queueItem['process']->isRunning()) {
                                        $queueItem['process']->stop(10, 9); // SIGKILL to not queue continuing dataport
                                        $this->progress[$batchId]->setMessage('Stopping '.substr($queueItem['process']->getCommandLine(), 0, 1000).(strlen($queueItem['process']->getCommandLine()) > 1000 ? ' ...' : '').', will restart after regrouping');
                                    } elseif ($queueItem['process']->isSuccessful()) {
                                        $queueRepository->delete($queueItem['queueItem']);
                                    }
                                }
                            }

                            if($maxParallelProcessesSetting->getData() != $maxParallelProcesses) {
                                try {
                                    if (PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM website_settings WHERE name=? AND modificationDate < ?', ['queue_processor.max_processing', $this->batchStarted])) {
                                        $maxParallelProcessesSetting->setData($maxParallelProcesses);
                                    }
                                } catch (TableNotFoundException $e) {
                                    $websiteSettingsConfigFile = Config::locateConfigFile('website-settings.php');
                                    if (file_exists($websiteSettingsConfigFile) && filemtime($websiteSettingsConfigFile) < $this->batchStarted) {
                                        $maxParallelProcessesSetting->setData($maxParallelProcesses);
                                    }
                                }
                            }

                            $this->logProgress('Restarting', $this->progress);
                            $this->release(self::LOCK_KEY);

                            $this->runCleanup();

                            clearstatcache(true, OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console');
                            $cmd = '"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').' '.$this->getName();
                            Cli::execInBackground($cmd);

                            return 0;
                        }
                    } else {
                        PimcoreDbRepository::getInstance()->execute('UPDATE '.Installer::TABLE_QUEUE.' SET restarts=restarts+1 WHERE id=?', [$queueItem['queueItem']]);

                        $dataportId = explode('-', $batchId)[0];
                        $this->progress[$batchId]->setMessage('Process '.substr($queueItem['process']->getCommandLine(), 0, 1000).(strlen($queueItem['process']->getCommandLine()) > 1000 ? ' ...' : '').' failed');

                        if ($this->dataportsSupportingParallelProcesses[$dataportId] <= 1) {
                            // do not process queue any further if error occurred to guarantee processing order
                            unset($this->queues[$batchId]);
                            $this->progress[$batchId]->finish();

                            $maxParallelProcesses = max(1, $maxParallelProcesses - 1);
                        } else {
                            $maxParallelProcesses = max(1, $maxParallelProcesses - 1);

                            $this->dataportsSupportingParallelProcesses[$dataportId] = max(1, $this->dataportsSupportingParallelProcesses[$dataportId] - 1);
                        }

                        if (time() - $this->started > 3600) {
                            foreach ($this->queues as $remainingBatchId => $remainingQueue) {
                                if (!$remainingQueue->isEmpty()) {
                                    $queueItem = $remainingQueue->bottom();
                                    if ($queueItem['process']->isRunning()) {
                                        $queueItem['process']->stop(10, 9); // SIGKILL to not queue continuing dataport
                                        $this->progress[$remainingBatchId]->setMessage('Stopping '.substr($queueItem['process']->getCommandLine(), 0, 1000).(strlen($queueItem['process']->getCommandLine()) > 1000 ? ' ...' : '').', will restart soon');
                                    } elseif ($queueItem['process']->isSuccessful()) {
                                        $queueRepository->delete($queueItem['queueItem']);
                                    }
                                }
                            }

                            if ($maxParallelProcessesSetting->getData() != $maxParallelProcesses) {
                                try {
                                    if (PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM website_settings WHERE name=? AND modificationDate < ?', ['queue_processor.max_processing', $this->batchStarted])) {
                                        $maxParallelProcessesSetting->setData($maxParallelProcesses);
                                    }
                                } catch (TableNotFoundException $e) {
                                    $websiteSettingsConfigFile = Config::locateConfigFile('website-settings.php');
                                    if (file_exists($websiteSettingsConfigFile) && filemtime($websiteSettingsConfigFile) < $this->batchStarted) {
                                        $maxParallelProcessesSetting->setData($maxParallelProcesses);
                                    }
                                }
                            }

                            $this->logProgress('Restarting', $this->progress);
                            $this->release(self::LOCK_KEY);

                            $this->runCleanup();

                            clearstatcache(true, OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console');
                            $cmd = '"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').' '.$this->getName();
                            Cli::execInBackground($cmd);

                            return 0;
                        }
                    }
                }

                if(time() > $this->batchStarted + 5 && time() - $this->started < 3000) {
                    $batchStartedDate = (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->setTimestamp($this->batchStarted);

                    $workers = $queueRepository->findColumnInSql('SELECT worker_id FROM '.Installer::TABLE_QUEUE.' WHERE queued_at > ? GROUP BY worker_id', [$batchStartedDate]);
                    foreach ($workers as $workerId) {
                        if ($workerId === 'check_automatic_run' && count($workers) > 1) {
                            // do not start check_automatic_run worker if other workers are active
                            continue;
                        }
                        $queueItems = $queueRepository->find(['worker_id = ?' => $workerId, 'started_at IS NULL' => null, 'queued_at > ?' => $batchStartedDate->format('Y-m-d H:i:s'), 'restarts < ?' => 10], 'id', 10000);
                        foreach ($queueItems as $queueItem) {
                            $this->addToQueue($queueItem, $output);
                        }
                    }
                    $this->batchStarted = time();
                }
            } while ($queueWithItemExists);

            // if in meantime between first query and now new commands got queued, we have to restart processing, otherwise these new commands would have to wait till next command gets queued
            $remainingQueueItem = $queueRepository->findOne(['queued_at >= ?' => (new DateTimeImmutable('@0'))->setTimestamp($this->started)]);
            if ($remainingQueueItem) {
                $this->logProgress('Restarting queue processing as there are new queued items', $this->progress);
                goto processQueue;
            }

            $this->logProgress('Finished', $this->progress);
        } finally {
            try {
                Queue::getInstance()->execute('UPDATE '.$queueRepository->getTableName().' SET started_at=NULL');

                if ($maxParallelProcessesSetting->getData() != $maxParallelProcesses) {
                    try {
                        if (PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM website_settings WHERE name=? AND modificationDate < ?', ['queue_processor.max_processing', $this->batchStarted])) {
                            $maxParallelProcessesSetting->setData($maxParallelProcesses);
                        }
                    } catch (TableNotFoundException $e) {
                        $websiteSettingsConfigFile = Config::locateConfigFile('website-settings.php');
                        if (file_exists($websiteSettingsConfigFile) && filemtime($websiteSettingsConfigFile) < $this->batchStarted) {
                            $maxParallelProcessesSetting->setData($maxParallelProcesses);
                        }
                    }
                }
            } catch (\Exception $e) {
            }

            $this->release(self::LOCK_KEY);

            fclose($this->logFileHandle);
        }

        $this->runCleanup();

        return 0;
    }

    /**
     * @param $globalSectionMessage
     * @param ProgressBar[] $progressBars
     */
    private function logProgress($globalSectionMessage, array $progressBars)
    {
        $output = '';

        if (empty($globalSectionMessage)) {
            $output .= substr($this->statusMonitorHTML, 0, strpos($this->statusMonitorHTML, PHP_EOL));
        } else {
            if (method_exists($this->globalSection, 'overwrite')) {
                $this->globalSection->overwrite($globalSectionMessage);
            } else {
                $this->globalSection->writeln($globalSectionMessage);
            }

            $globalSectionMessage = preg_replace_callback('/max. parallel processes: (\d+)/', static function ($match) {
                try {
                    $counter = new CpuCoreCounter();
                    $cpuCount = $counter->getCount();
                } catch (NumberOfCpuCoreNotFound $e) {
                    $cpuCount = 4;
                }

                return 'max. parallel processes: <input type="number" min="1" step="1" max="'.($cpuCount * 4).'" name="maxParallelProcesses" value="'.$match[1].'" style="width:50px">';
            }, $globalSectionMessage);
            $output .= '<b>Global status: '.$globalSectionMessage.'</b><br><br>'.PHP_EOL;
        }

        foreach ($progressBars as $progressBar) {
            $backgroundColor = '';
            if (substr($progressBar->getMessage(), -5) === 'failed') {
                $backgroundColor = '#EF6565FF';
            } elseif ($progressBar->getMaxSteps() === $progressBar->getProgress()) {
                $backgroundColor = '#83EF65FF';
            }

            $output .= '<div class="progressWrapper">
    <div class="progressBar">
        <div class="progress" style="width: '.($progressBar->getProgressPercent() * 100).'%;'.($backgroundColor ? 'background-color: '.$backgroundColor.';' : '').'"></div>
        <div class="progressCount" style="right:'.max(92 - $progressBar->getProgressPercent() * 100, 1).'%"> '.$progressBar->getProgress().' / '.$progressBar->getMaxSteps().'</div>
    </div>
    <div class="status">'.$progressBar->getMessage().'</div>
</div>'.PHP_EOL;
        }

        $output .= '<!-- finished -->';

        if($output !== $this->statusMonitorHTML) {
            $this->statusMonitorHTML = $output;

            ftruncate($this->logFileHandle, 0);
            fseek($this->logFileHandle, 0);
            fwrite($this->logFileHandle, $output);
        }
    }

    private function runCleanup() {
        $messengerMessagesTableExists = PimcoreDbRepository::getInstance()->findOneInSql('SHOW TABLES LIKE \'messenger_messages\';');
        if($messengerMessagesTableExists) {
            $maintenanceJobNotWorking = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM messenger_messages WHERE queue_name=\'pimcore_maintenance\' AND created_at < NOW() - INTERVAL 1 DAY LIMIT 1');
            if ($maintenanceJobNotWorking) {
                $commandPrefix = '"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console');
                chdir(OPENDXP_PROJECT_ROOT);
                Cli::exec($commandPrefix.' data-bridge:cleanup');
            }
        }
    }

    /**
     * @param $command
     * @return Process
     * @throws Exception
     */
    private function getProcess($command)
    {
        $niceCommandPrefix = '';
        $nice = (string)Cli::getExecutable('nice');
        if ($nice) {
            if (OpenDxp::getContainer()->getParameter('data_bridge.config')['queue_processing']['automatic_start'] || strpos($command, ':start-automatic-dataports') !== false) {
                $niceCommandPrefix = $nice.' -n 19 ';
            } elseif (strpos($command, ':process') !== false) {
                $niceCommandPrefix = '';
            } else {
                $niceCommandPrefix = $nice.' -n 1 ';
            }
        }

        $commandPrefix = '"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console');
        try {
            $process = Process::fromShellCommandline(($niceCommandPrefix ? $niceCommandPrefix.' ' : '').$commandPrefix.' '.$command.' -n', OPENDXP_PROJECT_ROOT);
        } catch (\Throwable $e) {
            $process = new Process(($niceCommandPrefix ? $niceCommandPrefix.' ' : '').$commandPrefix.' '.$command.' -n', OPENDXP_PROJECT_ROOT);
        }

        $process->setTimeout(null);
        $process->disableOutput();
        return $process;
    }

    private function addToQueue($queueItem, OutputInterface $output)
    {
        $dataportId = $queueItem['worker_id'] ?: 'unknown';

        if ($dataportId === 'unknown') {
            if (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim) "?([\p{L}\p{Nd}]+)"?/u', $queueItem['command'], $matches)) {
                $dataportId = $dataport['id'] ?? $dataportId;
            } elseif (preg_match('/^(?:data-bridge):delete-rawdata --dataport-resource-id="?(\d+)"?/', $queueItem['command'], $matches)) {
                $dataportResourceRepository = DataportResource::getInstance();
                $dataportId = $dataportResourceRepository->get($matches[1])['dataportId'] ?? 'unknown';
            }
        }

        if (empty($this->dataportsSupportingParallelProcesses[$dataportId])) {
            $this->dataportsSupportingParallelProcesses[$dataportId] = 1;
            $dataport = Dataport::getInstance()->get($dataportId);

            if($dataport) {
                $this->dataportsSupportingParallelProcesses[$dataportId] = $dataport['targetconfig']['parallelProcesses'] ?: 1;
            }
        }

        $workerCount = PHP_INT_MAX;

        for ($i = 1; $i <= $this->dataportsSupportingParallelProcesses[$dataportId]; $i++) {
            $workerId = $dataportId.'-'.$i;
            if (!isset($this->queues[$workerId])) {
                $this->queues[$workerId] = new SplQueue();
                $this->queues[$workerId]->setIteratorMode(\SplQueue::IT_MODE_FIFO);
                $this->processed[$workerId] = 0;
                $this->progress[$workerId] = new ProgressBar(method_exists($output, 'section') ? $output->section() : $output, 1);
                $this->progress[$workerId]->setMessage('Initializing '.$queueItem['command']);
                $this->progress[$workerId]->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');

                $queueId = $workerId;
                break;
            }

            if (count($this->queues[$workerId]) < $workerCount) {
                $queueId = $workerId;
                $workerCount = count($this->queues[$workerId]);
                $this->progress[$workerId]->setMaxSteps($workerCount);
            }
        }

        $process = $this->getProcess($queueItem['command']);

        $this->queues[$queueId]->enqueue(['queueItem' => $queueItem['id'], 'process' => $process]);
    }
}
