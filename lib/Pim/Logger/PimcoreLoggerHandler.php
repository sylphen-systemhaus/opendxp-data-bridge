<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Monolog\Handler\AbstractProcessingHandler;
use OpenDxp\Model\DataObject\Objectbrick;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

if (version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    class PimcoreLoggerHandler extends AbstractProcessingHandler
    {
        /** @var LoggerInterface */
        private $wrappedLogger;

        /**
         * @param LoggerInterface $wrappedLogger
         */
        public function __construct(LoggerInterface $wrappedLogger)
        {
            parent::__construct();
            $this->wrappedLogger = $wrappedLogger;
        }

        protected function write(\Monolog\LogRecord $record): void
        {
            switch ($record['level']) {
                case '600':
                    $level = LogLevel::EMERGENCY;
                    break;
                case '500':
                    $level = LogLevel::CRITICAL;
                    break;
                case '400':
                    $level = LogLevel::ERROR;
                    break;
                case '300':
                    $level = LogLevel::WARNING;
                    break;
                case '250':
                    $level = LogLevel::NOTICE;
                    break;
                case '200':
                    $level = LogLevel::INFO;
                    break;
                case '100':
                    $level = LogLevel::DEBUG;
                    break;
                default:
                    $level = LogLevel::ERROR;
            }

            $callingClasses = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            $errorHappenedDuringCacheLoad = false;
            foreach ($callingClasses as $callingClass) {
                if (isset($callingClass['class']) && $callingClass['class'] === Objectbrick::class && $callingClass['function'] === '__wakeup') {
                    $errorHappenedDuringCacheLoad = true;
                    break;
                }
            }

            if (!$errorHappenedDuringCacheLoad) {
                $this->wrappedLogger->log($level, $record['message'], $record['context']);
            }
        }
    }
} else {
    class PimcoreLoggerHandler extends AbstractProcessingHandler
    {
        /** @var LoggerInterface */
        private $wrappedLogger;

        /**
         * @param LoggerInterface $wrappedLogger
         */
        public function __construct(LoggerInterface $wrappedLogger)
        {
            parent::__construct();
            $this->wrappedLogger = $wrappedLogger;
        }

        protected function write(array $record): void
        {
            switch ($record['level']) {
                case '600':
                    $level = LogLevel::EMERGENCY;
                    break;
                case '500':
                    $level = LogLevel::CRITICAL;
                    break;
                case '400':
                    $level = LogLevel::ERROR;
                    break;
                case '300':
                    $level = LogLevel::WARNING;
                    break;
                case '250':
                    $level = LogLevel::NOTICE;
                    break;
                case '200':
                    $level = LogLevel::INFO;
                    break;
                case '100':
                    $level = LogLevel::DEBUG;
                    break;
                default:
                    $level = LogLevel::ERROR;
            }

            $callingClasses = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            $errorHappenedDuringCacheLoad = false;
            foreach ($callingClasses as $callingClass) {
                if (isset($callingClass['class']) && $callingClass['class'] === Objectbrick::class && $callingClass['function'] === '__wakeup') {
                    $errorHappenedDuringCacheLoad = true;
                    break;
                }
            }

            if(!$errorHappenedDuringCacheLoad) {
                $this->wrappedLogger->log($level, $record['message'], $record['context']);
            }
        }
    }
}