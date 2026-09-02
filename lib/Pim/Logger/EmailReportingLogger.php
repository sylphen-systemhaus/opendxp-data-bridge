<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use OpenDxp\Config;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Mail;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Notification\Service\NotificationService;
use OpenDxp\Model\User\Listing;
use OpenDxp\Model\User\Listing\AbstractListing;
use OpenDxp\Tool;
use OpenDxp\Version;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Templating\EngineInterface;
use Throwable;

if (version_compare(Helper::getPackageVersion('psr/log'), '3', '>=')) {
    class EmailReportingLogger extends EmailReportingLoggerPsrLog3 {}
} elseif (version_compare(Helper::getPackageVersion('psr/log'), '2', '>=')) {
    class EmailReportingLogger extends EmailReportingLoggerPsrLog2 {}
} else {
    class EmailReportingLogger extends AbstractLogger
    {
        use EmailReportingLoggerPhpCompatibilityTrait;

        const LINE_DELIMITER = '~data-bridge~';

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