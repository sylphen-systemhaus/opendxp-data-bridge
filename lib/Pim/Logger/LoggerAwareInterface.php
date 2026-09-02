<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Psr\Log\LoggerInterface;

// This interface looks the same as the one from psr/log but Symfony auto-wires its `logger` service to all objects which implement the psr/log LoggerAwareInterface -> and we don't want this
if (version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    interface LoggerAwareInterface
    {
        /**
         * Sets a logger instance on the object.
         *
         * @param LoggerInterface $logger
         *
         * @return void
         */
        public function setLogger(LoggerInterface $logger): void;
    }
} else {
    interface LoggerAwareInterface
    {
        /**
         * Sets a logger instance on the object.
         *
         * @param LoggerInterface $logger
         *
         * @return void
         */
        public function setLogger(LoggerInterface $logger);
    }
}