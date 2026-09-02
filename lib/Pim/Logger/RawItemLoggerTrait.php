<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Monolog\ErrorHandler;
use OpenDxp\Model\Element\ElementInterface;
use Psr\Log\LogLevel;
use SplObjectStorage;

trait RawItemLoggerTrait
{
    /** @var array */
    private $logs = [];

    /** @var callable */
    private $matchFunction;

    /** @var string */
    private $rawItemId;

    private static $instance;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param callable|null $matchFunction function to determine if message should be logged or not
     */
    public function __construct(callable $matchFunction = null)
    {
        $ignoreLogLevels = \OpenDxp::getContainer()->getParameter('sylphen_data_bridge.raw_item_logger.ignore_log_levels') ?? [LogLevel::DEBUG, LogLevel::INFO];
        $this->matchFunction = $matchFunction ?? static function ($level, $message, $context) use ($ignoreLogLevels) {
            return !in_array($level, $ignoreLogLevels, true);
        };
    }

    public function doLog($level, $message, array $context = array())
    {
        if (call_user_func($this->matchFunction, $level, $message, $context)) {
            $rawItemId = $this->rawItemId;
            if(empty($rawItemId)) {
                $rawItemId = 'global';
            }

            if(!isset($this->logs[$rawItemId])) {
                $this->logs[$rawItemId] = [];
            }

            $level = strtoupper($level);
            if (!isset($this->logs[$rawItemId][$level])) {
                $this->logs[$rawItemId][$level] = [];
            }
            $this->logs[$rawItemId][$level][] = $message;
        }
    }

    /**
     * @return array
     */
    public function getLogs($rawItemId = null): array
    {
        if($rawItemId === null) {
            return $this->logs;
        }
        return $this->logs[$rawItemId] ?? [];
    }

    public function clearLogs(): void
    {
        $this->logs = [];
    }

    public function setRawItemId($rawItemId)
    {
        $this->rawItemId = $rawItemId;
    }
}