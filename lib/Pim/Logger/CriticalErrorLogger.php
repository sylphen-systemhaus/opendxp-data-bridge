<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Monolog\ErrorHandler;
use Psr\Log\AbstractLogger;

if (version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    class CriticalErrorLogger extends CriticalErrorLoggerPsrLog3
    {
    }
} elseif (version_compare(Helper::getPackageVersion('psr/log'), '2', '>=')) {
    class CriticalErrorLogger extends CriticalErrorLoggerPsrLog2
    {
    }
} else {
    class CriticalErrorLogger extends AbstractLogger
    {
        use CriticalErrorLoggerTrait;

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