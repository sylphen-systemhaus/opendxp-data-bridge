<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Exception;
use InvalidArgumentException;
use League\Flysystem\UnableToWriteFile;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use Monolog\Utils;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

trait LoggerPhpCompatibilityTrait
{
    use FreeDiskSpaceTrait;

    /** @var LoggerInterface[] */
    private $loggers;

    /** @var FileObject */
    private $logFileObject;

    /** @var resource[] */
    private $logFileResources = [];

    /** @var array */
    private $context = [];

    /** @var string */
    private static $bufferedOutputs = '';

    private static $localFileObjectStorage;

    private $minLogLevel = 1;

    /** @var int */
    private $intelligentLogLevel = 1; // if no logs with this or worse log level gets logged, log will get deleted after run finishes

    private $intelligentLogging = false;
    private $intelligentLoggingLogLevelReached = false;

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

    public function __construct()
    {
        $this->enablePimcoreLogger();

        $handler = new ErrorHandler($this);
        $handler->registerErrorHandler([], false);
        $handler->registerExceptionHandler([], false);
        $handler->registerFatalHandler();
    }

    public function addLogger(LoggerInterface $logger)
    {
        $this->loggers[spl_object_id($logger)] = $logger;
    }

    public function setIntelligentLogLevel($intelligentLogLevel)
    {
        if (isset(self::$LEVELS[$intelligentLogLevel])) {
            $intelligentLogLevel = self::$LEVELS[$intelligentLogLevel];
        }
        if (!in_array($intelligentLogLevel, self::$LEVELS)) {
            throw new InvalidArgumentException('Log level does not exist');
        }

        $this->intelligentLogLevel = $intelligentLogLevel;
    }

    public function enableIntelligentLogging()
    {
        $this->intelligentLogging = true;
    }

    /**
     * @return int
     */
    public function getMinLogLevel(): int
    {
        return $this->minLogLevel;
    }

    /**
     * @param int $minLogLevel
     */
    public function setMinLogLevel($minLogLevel)
    {
        if (isset(self::$LEVELS[$minLogLevel])) {
            $minLogLevel = self::$LEVELS[$minLogLevel];
        }
        if (!in_array($minLogLevel, self::$LEVELS)) {
            throw new InvalidArgumentException('Log level does not exist');
        }

        $this->minLogLevel = $minLogLevel;
    }

    /**
     * @return FileObject
     */
    public function getLogFileObject(): ?FileObject
    {
        return $this->logFileObject;
    }

    /**
     * @param FileObject $logFileObject
     */
    public function setLogFileObject(FileObject $logFileObject): void
    {
        $this->logFileObject = $logFileObject;

        $handler = new ErrorHandler($this);
        $handler->registerErrorHandler([], true);
        $handler->registerExceptionHandler([], true);
        $handler->registerFatalHandler();

        if (ob_get_level() === 0) {
            ob_start();
            register_shutdown_function(function () {
                while (ob_get_level() > 0) {
                    $output = ob_get_clean();

                    if ($output) {
                        self::$bufferedOutputs .= $output;
                    }
                }

                if (self::$bufferedOutputs) {
                    $this->log('warning', 'Unexpected output during run: '.PHP_EOL.self::$bufferedOutputs);
                }
            });
        }
    }

    public function __destruct()
    {
        $this->writeToRemoteLog();

        foreach ($this->logFileResources as $logFileResource) {
            if (is_resource($logFileResource)) {
                @fclose($logFileResource);
            }
        }
    }

    private function writeToRemoteLog(): void {
        $isRemoteStorage = Helper::isRemoteStorageEnabled();

        foreach ($this->logFileResources as $contextFilePath => $logFileResource) {
            if ( defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY') ) {
                $logFilePath = rtrim(OPENDXP_LOG_FILEOBJECT_DIRECTORY, '/') . '/' . $contextFilePath;
            } else {
                $logFilePath = OPENDXP_PRIVATE_VAR . '/application-logger/' . $contextFilePath;
            }

            if ( !self::$localFileObjectStorage && $isRemoteStorage ) {
                try {
                    $content = '';
                    if ( file_exists($logFilePath) ) {
                        $content = file_get_contents($logFilePath);
                    }
                    if ( $content !== '' ) {
                        $content = Helper::getApplicationLogStorage()->read($contextFilePath) . PHP_EOL . $content;
                        Helper::getApplicationLogStorage()->write($contextFilePath, $content);
                    }
                } catch (\Throwable $e) {
                }
            }

            if ( $this->intelligentLogging && !$this->intelligentLoggingLogLevelReached ) {
                @unlink($logFilePath);
            }

            if ( $isRemoteStorage && file_exists($logFilePath) ) {
                @unlink($logFilePath);
            }
        }
    }

    /**
     * @param string|FileObject $contextFilePath
     * @return false|resource
     */
    private function getLogFileResource($contextFilePath)
    {
        if ($contextFilePath instanceof FileObject) {
            $contextFilePath = $contextFilePath->getSystemPath();
        }

        if (!isset($this->logFileResources[$contextFilePath])) {
            try {
                if (defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY')) {
                    $applicationLogDirectory = rtrim(OPENDXP_LOG_FILEOBJECT_DIRECTORY, '/');
                } else {
                    $applicationLogDirectory = OPENDXP_PRIVATE_VAR.'/application-logger';
                }

                $logFileObjectPath = dirname($applicationLogDirectory.'/'.$contextFilePath);
                if (!file_exists($logFileObjectPath) && !is_dir($logFileObjectPath) && !mkdir($logFileObjectPath, 0775, true) && !is_dir($logFileObjectPath)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $logFileObjectPath));
                }

                if (self::$localFileObjectStorage === null) {
                    self::$localFileObjectStorage = file_exists($applicationLogDirectory.'/'.$contextFilePath);
                }

                $this->logFileResources[$contextFilePath] = @fopen($applicationLogDirectory.'/'.$contextFilePath, 'ab');

                if (!isset($this->logFileResources[$contextFilePath]) || !is_resource($this->logFileResources[$contextFilePath])) {
                    // some cloud storage providers do not support append mode
                    $this->logFileResources[$contextFilePath] = @fopen($applicationLogDirectory.'/'.$contextFilePath, 'wb');
                }

                if (!self::$localFileObjectStorage) {
                    stream_copy_to_stream(Helper::getApplicationLogStorage()->readStream($contextFilePath), $this->logFileResources[$contextFilePath]);
                }
            } catch (\Throwable $e) {
            }

            if (!isset($this->logFileResources[$contextFilePath]) || !is_resource($this->logFileResources[$contextFilePath])) {
                $this->logFileResources[$contextFilePath] = fopen('php://temp', 'wb');
                $this->warning('Could not create dataport log file. Please check write permissions for directory '.(defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY') ? OPENDXP_LOG_FILEOBJECT_DIRECTORY : OPENDXP_PRIVATE_VAR.'/application-logger'));
            }
        }

        return $this->logFileResources[$contextFilePath];
    }

    /**
     * @param array $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param string $level
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function doLog($level, $message, array $context = array())
    {
        if (empty($context)) {
            $context = $this->context;
        }

        if (!is_string($level)) {
            // comes from monolog v1: parameters were ErrorHandler::registerExceptionHandler($level, ...)
            $level = 'alert';
        }

        if (empty($context['fileObject']) && !empty($this->logFileObject)) {
            $context['fileObject'] = $this->logFileObject;
        }

        if (ob_get_level() === 1) {
            $outputBuffer = ob_get_contents();
            if ($outputBuffer) {
                ob_clean();
                $this->log('warning', 'Unexpected output during run: '.PHP_EOL.$outputBuffer);
            }
        }

        $this->intelligentLoggingLogLevelReached = $this->intelligentLoggingLogLevelReached || ($this->intelligentLogging && $this->intelligentLogLevel <= (self::$LEVELS[$level] ?? 0));

        if (!is_scalar($message) && !$message instanceof LazyLog) {
            $varDumper = new CliDumper();
            $cloner = new VarCloner();
            $message = $varDumper->dump($cloner->cloneVar($message), true);
        }

        if ($this->minLogLevel > (self::$LEVELS[$level] ?? 0) || (is_string($message) && substr($message, 0, 12) === 'E_DEPRECATED')) {
            return;
        }

        if ($message instanceof LazyLog) {
            $message = (string)$message;
        }

        if (!empty($context['file'])) {
            $message .= ' in '.$context['file'];
            if (!empty($context['line'])) {
                $message .= ', line '.$context['line'];
            }
        }

        if (!empty($context['fileObject'])) {
            $fileHandle = $this->getLogFileResource($context['fileObject']);

            if (is_resource($fileHandle)) {
                if ($this->isFreeSpaceBelowThreshold($fileHandle)) {
                    $freeDiskSpaceWarning = $this->getFreeSpaceWarning($fileHandle);
                    if ($freeDiskSpaceWarning) {
                        fwrite($fileHandle, PHP_EOL.'['.\strtoupper($level).'] '.$message);
                        $this->log('warning', $freeDiskSpaceWarning);
                    }
                } else {
                    fwrite($fileHandle, PHP_EOL.'['.\strtoupper($level).'] '.$message);
                }
            }
        }

        foreach ($this->loggers as $logger) {
            $logger->log($level, $message, $context);
        }
    }

    public function disablePimcoreLogger()
    {
        $pimcoreLogger = \OpenDxp::getContainer()->get('monolog.logger.opendxp');
        if ($pimcoreLogger instanceof \Monolog\Logger) {
            $pimcoreLogger->setHandlers([]);
        }
    }

    public function enablePimcoreLogger()
    {
        $pimcoreLogger = \OpenDxp::getContainer()->get('monolog.logger.opendxp');
        if ($pimcoreLogger instanceof \Monolog\Logger) {
            $pimcoreLogger->setHandlers([new PimcoreLoggerHandler($this)]);
        }
    }
}