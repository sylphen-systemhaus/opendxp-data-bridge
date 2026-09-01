<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Psr\Log\LogLevel;

/**
 * This whole class only exists because Pimcore's application logger executed debug_backtrace for every log - even if the set log level is higher than current log's level
 */
class ApplicationLogger extends \OpenDxp\Bundle\ApplicationLoggerBundle\ApplicationLogger
{
    /**
     * @var int[]
     */
    private static $LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var int */
    private $logLevel;

    /**
     * @param string $component
     * @param bool $initDbHandler
     * @param string $logLevel
     * @return \OpenDxp\Bundle\ApplicationLoggerBundle\ApplicationLogger
     */
    public static function getInstance($component = 'default', $initDbHandler = false, $logLevel = LogLevel::DEBUG): \OpenDxp\Bundle\ApplicationLoggerBundle\ApplicationLogger
    {
        $container = \OpenDxp::getContainer();
        $containerId = 'opendxp.app_logger.'.$component;

        if ($container->has($containerId)) {
            $logger = $container->get($containerId);
        } else {
            $logger = new static;
            if ($initDbHandler) {
                $logger->addWriter($container->get(ApplicationLoggerDb::class));
            }

            $container->set($containerId, $logger);
            $logger->logLevel = self::$LEVELS[$logLevel];
        }

        $logger->setComponent($component);

        return $logger;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        if($this->logLevel <= (self::$LEVELS[$level] ?? 0)) {
            $context['source'] = '';

            if(isset($context['fileObject']) && $context['fileObject'] instanceof FileObject) {
                $context['fileObject'] = $context['fileObject']->getFilename();
            }
            parent::log($level, $message, $context);
        }
    }
}