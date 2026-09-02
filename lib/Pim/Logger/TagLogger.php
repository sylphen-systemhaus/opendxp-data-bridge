<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Psr\Log\AbstractLogger;

if (version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    class TagLogger extends TagLoggerPsrLog3 {}
} elseif (version_compare(Helper::getPackageVersion('psr/log'), '2', '>=')) {
    class TagLogger extends TagLoggerPsrLog2 {}
} else {
    class TagLogger extends AbstractLogger
    {
        use TagLoggerPhpCompatibilityTrait;

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