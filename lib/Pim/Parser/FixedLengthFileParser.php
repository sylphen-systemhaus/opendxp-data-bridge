<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ForceUTF8\Encoding;
use Psr\Log\LoggerInterface;

class FixedLengthFileParser implements Parser {
    use IteratableParser;
    use ResourceBasedParser;

    /** @var array */
    private $config;

    /** @var resource */
    private $handle;

    /** @var int */
    private $countOfPreviouslyProcessedFiles = 0;

    /** @var int */
    private $countOfCurrentlyProcessedFile;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        if (empty($this->config['fields'])) {
            throw new \Exception('Please configure raw data fields');
        }

        $this->logger = $logger;

        $this->setSourceFile($this->config['file']);
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        if($this->countOfCurrentlyProcessedFile !== null) {
            return $this->countOfPreviouslyProcessedFiles + $this->countOfCurrentlyProcessedFile;
        }

        try {
            $handle = $this->getHandle();
        } catch(\Exception $e) {
            return $this->countOfPreviouslyProcessedFiles;
        }

        if (!is_resource($handle)) {
            return $this->countOfPreviouslyProcessedFiles;
        }

        $currentPosition = ftell($handle);
        $streamData = \stream_get_meta_data($handle);

        $file = new \SplFileObject($streamData['uri'], 'r');
        $file->seek(PHP_INT_MAX);

        $lineCount = $file->key();

        fseek($handle, $currentPosition);
        $this->countOfCurrentlyProcessedFile = $lineCount + 1;

        return $this->countOfPreviouslyProcessedFiles + $this->countOfCurrentlyProcessedFile;
    }

    private function getHandle() {
        if(!\is_resource($this->handle)) {
            $this->handle = $this->getStream();

            $this->position = 0;
        }

        return $this->handle;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        start:
        try {
            $handle = $this->getHandle();
        } catch(\Exception $e) {
            $this->current = null;
            return $this->current;
        }

        if (!is_resource($handle)) {
            if (empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }

            $this->current = null;
            return null;
        }

        $row = fgets($handle);

        if ($this->position < $this->offset) {
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        $row = Encoding::toUTF8($row);

        if($row === false) {
            if(empty($this->limit)) {
                if($this->gotoNextImportResource()) {
                    goto start;
                }
                return null;
            }

            $this->current = null;
            return $this->current;
        }


        $item = [];
        $length = 0;
        foreach ($this->config['fields'] as $field => $values) {
            if($values['length'] === '__source') {
                $value = $this->getFileOrUrl();
            } elseif ($values['length'] === '__updated') {
                $value = $this->getLastModified();
            } elseif ($values['length'] === '__index') {
                $value = $this->position;
            } elseif ($values['length'] === '__count') {
                $value = $this->countOfPreviouslyProcessedFiles;
            } else {
                $value = mb_substr($row, $length, $values['length']);
                $length += $values['length'];
            }

            $item[$field] = Encoding::toUTF8($value);
        }

        $item['__updated'] = $this->getLastModified();

        $this->current = $item;

        return $this->current;
    }

    public function gotoNextImportResource() {
        $this->archive();

        $this->countOfPreviouslyProcessedFiles += $this->position;
        $this->countOfCurrentlyProcessedFile = null;

        $this->handle = null;
        $this->setSourceFile($this->config['file']);

        try {
            return is_resource($this->getHandle());
        } catch (\Exception $e) {
            return false;
        }
    }
}