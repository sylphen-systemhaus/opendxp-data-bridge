<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToWriteFile;
use OpenDxp\Logger;
use Throwable;

class FileObject
{
    /** @var string */
    protected $filename;

    private static $initializedFiles = [];

    /**
     * @param string $data
     * @param string|null $filename
     */
    public function __construct(string $content = '', string $filename = null)
    {
        $this->filename = $filename;

        if (!$this->filename) {
            $this->filename = date('/Y/m/d/').uniqid('fileobject_', true);
        }

        if(!isset(self::$initializedFiles[$this->filename])) {
            $logFileStorage = Helper::getApplicationLogStorage();
            $logFileObjectPath = dirname($this->filename);
            $logFilePathDirectoryExists = method_exists($logFileStorage, 'directoryExists') ? $logFileStorage->directoryExists($logFileObjectPath) : $logFileStorage->fileExists($logFileObjectPath);
            if (!$logFilePathDirectoryExists) {
                try {
                    if (method_exists($logFileStorage, 'createDirectory')) {
                        $logFileStorage->createDirectory($logFileObjectPath);
                    } else {
                        $logFileStorage->createDir($logFileObjectPath);
                    }
                } catch (\Throwable $e) {
                }
            }

            if ($content) {
                try {
                    $logFileStorage->write($this->filename, $content);
                } catch (Throwable $e) {
                    if (defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY')) {
                        $filePath = rtrim(OPENDXP_LOG_FILEOBJECT_DIRECTORY, '/').'/'.ltrim($this->filename, '/');
                    } else {
                        $filePath = rtrim(OPENDXP_PRIVATE_VAR.'/application-logger/'.$this->filename, '/');
                    }

                    file_put_contents($filePath, $content);
                }
            }

            self::$initializedFiles[$filename] = true;
        }
    }

    public function getSystemPath(): ?string
    {
        return $this->filename;
    }

    public function getFilename(): string
    {
        return preg_replace('/^'.preg_quote(\OPENDXP_PROJECT_ROOT, '/').'/', '', $this->filename);
    }

    public function __toString(): string
    {
        return $this->getFilename();
    }
}
