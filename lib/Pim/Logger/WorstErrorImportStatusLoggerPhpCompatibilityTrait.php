<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use OpenDxp\Config;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Mail;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Notification\Service\NotificationService;
use OpenDxp\Model\User\Listing;
use OpenDxp\Model\User\Listing\AbstractListing;
use OpenDxp\Tool;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Templating\EngineInterface;

trait WorstErrorImportStatusLoggerPhpCompatibilityTrait
{
    private $possibleWorseErrorlevels = ['EMERGENCY' => true, 'ALERT' => true, 'CRITICAL' => true, 'ERROR' => true, 'WARNING' => true, 'NOTICE' => true];

    /** @var string */
    private $statusKey;

    public function setStatusKey($statusKey)
    {
        $this->statusKey = $statusKey;
    }

    /**
     * @param string $level
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function doLog($level, $message, array $context = [])
    {
        $logLevelUppercase = \strtoupper($level);
        if (isset($this->possibleWorseErrorlevels[$logLevelUppercase])) {
            $this->possibleWorseErrorlevels = array_slice($this->possibleWorseErrorlevels, 0, array_search($logLevelUppercase, array_keys($this->possibleWorseErrorlevels), true));

            $stackTracePosition = strpos($message, 'Stack trace');
            if ($stackTracePosition !== false) {
                $message = substr($message, 0, $stackTracePosition);
            }

            if(preg_match('/(.+ for field ".+", line \d+):/', $message, $match)) {
                $message = $match[1];
            }

            ImportStatus::getInstance()->update(
                [
                    'worst_error' => '['.$logLevelUppercase.'] '.mb_substr($message, 0, 1e6),
                ],
                ['key' => $this->statusKey]
            );
        }
    }
}
