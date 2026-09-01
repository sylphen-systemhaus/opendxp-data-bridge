<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Command\QueueProcessorCommand;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Tool\Lock;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;

class Queue extends PimcoreDbRepository
{
    private static $queueProcessorStarted = false;

    public function getTableName(): string
    {
        return Installer::TABLE_QUEUE;
    }

    public function create(array $data)
    {
        if (!isset($data[0])) {
            $data = [$data];
        }

        foreach($data as &$item) {
            if (!isset($item['command_hash'])) {
                $item['command_hash'] = md5(preg_replace('/ --user=\d+/', '', $item['command']));
            }

            if (!isset($item['queued_at'])) {
                $item['queued_at'] = new DateTimeImmutable();
            }
            $item['queued_at'] = $item['queued_at']->setTimezone(new DateTimeZone('UTC'));

            if (!isset($item['triggered_by'])) {
                try {
                    throw new \Exception(); // pseudo exception, see https://stackoverflow.com/questions/1423157/print-php-call-stack
                } catch (\Exception $e) {
                    $item['triggered_by'] = $e->getTraceAsString();
                }
            }

            if (!isset($item['worker_id'])) {
                if (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim) "?(\d+)"?/', $item['command'], $matches)) {
                    $item['worker_id'] = $matches[1];
                } elseif (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim) "?([\p{L}\p{Nd}]+)"?/u', $item['command'], $matches)) {
                    $dataport = Dataport::getInstance()->get($matches[1]);
                    $item['worker_id'] = $dataport['id'] ?? null;
                } elseif (preg_match('/^(?:data-bridge):delete-rawdata --dataport-resource-id="?(\d+)"?/', $item['command'], $matches)) {
                    $item['worker_id'] = DataportResource::getInstance()->get($matches[1])['dataportId'] ?? null;
                }
            }
        }
        unset($item);

        $columnList = \array_keys($data[0]);
        $paramValues = [];
        foreach ($data as $dataset) {
            foreach ($dataset as $value) {
                $paramValues[] = $value;
            }
        }

        $countColumnList = count($columnList);
        if ($countColumnList === 0) {
            return;
        }

        $query = 'INSERT IGNORE INTO '.$this->getTableName().' ('.implode(',', array_map([$this->connection, 'quoteIdentifier'], $columnList)).') VALUES ';

        $values = array_fill(0, count($data), '('.rtrim(\str_repeat('?,', $countColumnList), ',').')');
        $query .= implode(',', $values);
        $query .= ' ON DUPLICATE KEY UPDATE queued_at = VALUES(queued_at), triggered_by = VALUES(triggered_by)';

        $this->execute($query, $paramValues);

        if (OpenDxp::getContainer()->getParameter('data_bridge.config')['queue_processing']['automatic_start']) {
            self::triggerQueueProcessor();
        }

        return null; // currently not necessary to return new database id
    }

    public static function startQueueProcessor() {
        Cli::execInBackground('"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').' data-bridge:process-queue --quiet');
    }

    public static function triggerQueueProcessor() {
        if (!self::$queueProcessorStarted) {
            $startQueueProcessor = static function () {
                self::startQueueProcessor();
            };

            register_shutdown_function($startQueueProcessor);

            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);

                $cliAbortFunction = static function () use ($startQueueProcessor) {
                    $startQueueProcessor();
                    exit(1);
                };
                pcntl_signal(SIGINT, $cliAbortFunction); // SIGINT is sent by the TTY driver to the current foreground job when the interactive attention character (typically ^C, which has ASCII code 3) appears in the input stream
                pcntl_signal(SIGTERM, $cliAbortFunction);
                pcntl_signal(SIGHUP, $cliAbortFunction); // SIGHUP is sent by the UART driver to the entire session when a hangup condition has been detected.
            }

            self::$queueProcessorStarted = true;
        }
    }

    public function convertIncrementalToBulkExports($dataportId)
    {
        $dataport = Dataport::getInstance()->get($dataportId);
        if ($dataport['sourcetype'] !== 'pimcore') {
            throw new InvalidArgumentException('Not supported for dataports of type "'.$dataport['sourcetype'].'"');
        }

        $elementIdsChunks = [];
        foreach ($this->findColumnInSql('SELECT command FROM '.Installer::TABLE_QUEUE.' WHERE worker_id = ? AND started_at IS NULL', [$dataportId]) as $queuedJob) {
            if (preg_match('/id=(\d+)/', $queuedJob, $match)) {
                $elementIdsChunks[str_replace($match[0], '~~ ids ~~', $queuedJob)][] = $match[1];
            }
        }

        foreach($elementIdsChunks as $command => $elementIds) {
            $command = str_replace('~~ ids ~~', 'id IN ('.implode(',', $elementIds).')', $command);
            $this->create([
                'command' => $command,
                'triggered_by' => 'Queued incremental jobs converted to bulk processing',
                'worker_id' => $dataportId
            ]);
        }

        $this->execute('DELETE FROM '.Installer::TABLE_QUEUE.' WHERE worker_id = ? AND started_at IS NULL AND command LIKE \'%id=%\' AND queued_at < NOW() - INTERVAL 10 SECOND', [$dataportId]);
    }
}