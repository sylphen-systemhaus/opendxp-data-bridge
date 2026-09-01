<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Monolog\Handler\StreamHandler;

class ConsoleLoggerFactory
{
    private static $consoleLogger;

    public static function getConsoleLogger() {
        if (self::$consoleLogger === null) {
            self::$consoleLogger = new \Monolog\Logger('data_bridge');
            $formatter = new LogFormatter();
            $streamHandler = new StreamHandler('php://stdout', \Monolog\Logger::INFO);
            $streamHandler->setFormatter($formatter);
            self::$consoleLogger->pushHandler($streamHandler);
        }

        return self::$consoleLogger;
    }
}