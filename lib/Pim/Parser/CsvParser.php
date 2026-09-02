<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ForceUTF8\Encoding;
use OpenDxp\File;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\AbstractElement;
use Psr\Log\LoggerInterface;

/**
 * Parses CSV-Files according to given configuration
 */
class CsvParser implements Parser, CachableParser {
    use IteratableParser;
    use ResourceBasedParser;

    /** @var array */
	private $config;

    /** @var resource */
    private $handle;

    /** @var array */
    private $fieldsToIndexes = [];

    /** @var int */
    private $countOfPreviouslyProcessedFiles = 0;

    /** @var int */
    private $countOfCurrentlyProcessedFile;

    /** @var string[] */
    private $columnNames = [];

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->config['separator'] = str_replace(["\\t", "\\n", "\\r"], ["\t", "\n", "\r"], $this->config['separator']);

        if (strlen($this->config['quote']) > 1) {
            $this->config['quote'] = '"';
        }

        $this->logger = $logger;

        $this->setSourceFile($this->config['file']);
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        // we have to use goto here and cannot call $this->current() because otherwise there could bee "Too much recursion" error
        start:
        $handle = null;
        try {
            $handle = $this->getHandle();
            if($handle === null) {
                $this->current = null;
                return $this->current;
            }
        } catch(\Exception $e) {
            $this->logger->warning($e->getMessage());
        }

        if(!is_resource($handle)) {
            if (empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }

            $this->current = null;
            return null;
        }


        $data = fgetcsv($handle, 0, $this->config['separator'], $this->config['quote']);

        if($this->position < $this->offset) {
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        if($data === false) {
            if ($this->valid() && $this->gotoNextImportResource()) {
                goto start;
            }

            $this->current = null;
            return null;
        }

        $data = array_map(static function($data) {
            return Encoding::toUTF8($data);
        }, $data);

        if($this->position === 0) {
            $this->fieldsToIndexes = [];
            $this->columnNames = [];
            if ($this->config['hasHeader']) {
                $data[-1] = '__source';
                $data[-2] = '__updated';
                $data[-3] = '__index';
                $data[-4] = '__all';
                $data[-5] = '__count';

                foreach ($this->config['fields'] as $field => $values) {
                    $values['column'] = str_replace(["\\t", "\\n", "\\r"], ["\t", "\n", "\r"], $values['column']);
                    $columnName = trim($values['column']);
                    foreach ($data as $index => $name) {
                        if($columnName === '__all' && substr($name, 0, 2) !== '__') {
                            $this->columnNames[$index] = $name;
                        }

                        if (trim($name) === $columnName || (string)$index === $values['column']) {
                            $this->fieldsToIndexes[$index] = $field;
                        }
                    }

                    // case-insensitive column name matching
                    if(count($this->fieldsToIndexes) !== count($data)) {
                        foreach ($data as $index => $name) {
                            if (mb_strtolower(trim($name)) === mb_strtolower($columnName)) {
                                $this->fieldsToIndexes[$index] = $field;
                            }
                        }
                    }
                }

                // fetch actual first raw data item
                $data = fgetcsv($handle, 0, $this->config['separator'], $this->config['quote']);
                $data = array_map(static function($data) {
                    return Encoding::toUTF8($data);
                }, (array)$data);

                foreach($this->fieldsToIndexes as $columnIndex => $fieldNo) {
                    $duplicates = [$columnIndex];
                    foreach($this->fieldsToIndexes as $columnIndexDuplicate => $fieldNoDuplicate) {
                        if($columnIndex <= $columnIndexDuplicate) {
                            continue;
                        }
                        if($fieldNo === $fieldNoDuplicate) {
                            $duplicates[] = $columnIndexDuplicate;
                        }
                    }

                    if(count($duplicates) > 1) {
                        sort($duplicates);
                        $this->logger->warning('Import resource contains column "'.$this->config['fields'][$fieldNo]['column'].'" multiple times (columns '.implode(',', $duplicates).') -> only using last one');
                    }
                }
            } else {
                foreach ($this->config['fields'] as $field => $values) {
                    if($values['column'] === '__source') {
                        $this->fieldsToIndexes[-1] = $field;
                    } elseif ($values['column'] === '__updated') {
                        $this->fieldsToIndexes[-2] = $field;
                    } elseif ($values['column'] === '__index') {
                        $this->fieldsToIndexes[-3] = $field;
                    } elseif ($values['column'] === '__all') {
                        $this->fieldsToIndexes[-4] = $field;

                        $this->columnNames = array_keys($data);
                    } elseif ($values['column'] === '__count') {
                        $this->fieldsToIndexes[-5] = $this->countOfCurrentlyProcessedFile;

                        $this->columnNames = array_keys($data);
                    } else {
                        $colIndex = (int)$values['column'];
                        if (!isset($this->fieldsToIndexes[$colIndex])) {
                            $this->fieldsToIndexes[$colIndex] = $field;
                        }
                    }
                }
            }
        }

        $data[-1] = $this->getFileOrUrl();
        $data[-2] = $this->getLastModified();
        $data[-3] = $this->position;
        $data[-4] = function() use (&$allItem, $data) {
            if(!isset($allItem)) {
                $values = [];
                foreach ($this->columnNames as $index => $columnName) {
                    $values[$columnName] = $data[$index];
                }
                $allItem = json_encode($values, \JSON_UNESCAPED_SLASHES);
            }
            return $allItem;
        };

        $item = [];
        foreach ($this->fieldsToIndexes as $index => $field) {
            $item[$field] = $data[$index] ?? '';
        }

        $item['__updated'] = $data[-2];

        $this->current = $item;
        return $this->current;
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

        $handle = null;
        try {
            $handle = $this->getHandle();

            if($handle === null) {
                return $this->countOfPreviouslyProcessedFiles;
            }
        } catch(\Throwable $e) {
            $this->logger->error($e->getMessage());
        }

        if(!is_resource($handle)) {
            return $this->countOfPreviouslyProcessedFiles;
        }

        $currentPosition = ftell($handle);
        $streamData = \stream_get_meta_data($handle);

        $file = new \SplFileObject($streamData['uri'], 'r');
        $file->seek(PHP_INT_MAX);

        $lineCount = $file->key();

        fseek($handle, $currentPosition);
        $this->countOfCurrentlyProcessedFile = $lineCount + 1 - ($this->config['hasHeader'] ? 1 : 0);

        return $this->countOfPreviouslyProcessedFiles + $this->countOfCurrentlyProcessedFile;
    }

    private function getHandle() {
	    if(!\is_resource($this->handle)) {
            if (empty($this->config['fields'])) {
                throw new \Exception('Please configure raw data fields');
            }

            $this->handle = $this->getStream();

            $this->position = 0;
        }

        return $this->handle;
    }

    public function gotoNextImportResource() {
        $this->archive();

        $this->countOfPreviouslyProcessedFiles += $this->position;
        $this->countOfCurrentlyProcessedFile = null;

        $this->columnNames = [];

        $this->handle = null;
        $this->setSourceFile($this->config['file']);

        try {
            return is_resource($this->getHandle());
        } catch(\Exception $e) {
            return false;
        }
    }

    protected function getArchiveFileExtension() {
        return 'csv';
    }
}
