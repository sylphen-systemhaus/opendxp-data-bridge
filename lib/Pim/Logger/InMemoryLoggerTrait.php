<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Monolog\ErrorHandler;
use Psr\Log\LogLevel;

trait InMemoryLoggerTrait
{
    /** @var array */
    private $logs = [];

    /** @var callable */
    private $matchFunction;

    /**
     * @param callable|null $matchFunction function to determine if message should be logged or not
     */
    public function __construct(callable $matchFunction = null)
    {
        $this->matchFunction = $matchFunction ?? static function ($level, $message, $context) {
            return !in_array($level, [LogLevel::DEBUG, LogLevel::INFO], true);
        };

        $handler = new ErrorHandler($this);
        $handler->registerErrorHandler([], true);
        $handler->registerExceptionHandler([], true);
        $handler->registerFatalHandler();

        ob_start();
    }

    public function __destruct()
    {
        $this->logOutputs();
    }

    public function doLog($level, $message, array $context = array())
    {
        if (call_user_func($this->matchFunction, $level, $message, $context)) {
            $this->logs[] = '['.strtoupper($level).'] '.$message;
        }
    }

    /**
     * @return array
     */
    public function getLogs(): array
    {
        $this->logOutputs();

        ob_start();

        return $this->logs;
    }

    /**
     * @return void
     */
    private function logOutputs(): void
    {
        $outputs = '';
        while (ob_get_level() > 0) {
            $output = ob_get_clean();

            if ($output) {
                $outputs = $output; // is replacing correct here? or concatenation?
            }
        }

        if ($outputs) {
            $this->log('warning', 'Unexpected output during run: '.PHP_EOL.$outputs);
        }
    }

    public function clearLogs(): void
    {
        $this->logs = [];
    }
}