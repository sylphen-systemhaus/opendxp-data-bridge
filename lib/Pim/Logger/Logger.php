<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Closure;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use League\Flysystem\UnableToWriteFile;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Throwable;

if(version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    class Logger extends LoggerPsrLog3 {}
} elseif (version_compare(Helper::getPackageVersion('psr/log'), '2', '>=')) {
    class Logger extends LoggerPsrLog2 {}
} else {
    class Logger extends AbstractLogger
    {
        use LoggerPhpCompatibilityTrait;

        /**
         * @param string $level
         * @param mixed $message
         * @param array $context
         * @return void
         */
        public function log($level, $message, array $context = array())
        {
            $this->doLog($level, $message, $context);
        }
    }
}
