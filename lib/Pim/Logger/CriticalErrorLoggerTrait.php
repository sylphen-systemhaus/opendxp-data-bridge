<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Monolog\ErrorHandler;

trait CriticalErrorLoggerTrait
{
    private $logPath;

    /**
     * @param $logPath
     */
    public function __construct($logPath)
    {
        $this->logPath = $logPath;
    }

    public function registerFatalErrorLogger()
    {
        $handler = new ErrorHandler($this);
        $handler->registerErrorHandler([], true);
        $handler->registerExceptionHandler([], true);
        $handler->registerFatalHandler();
    }

    public function doLog($level, $message, array $context = array())
    {
        file_put_contents($this->logPath, date('d-M-Y H:i:s').' '.$level . ": " . $message . "\n", FILE_APPEND);
    }
}