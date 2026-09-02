<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use avadim\FastExcelReader\Excel;
use avadim\FastExcelReader\Sheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\RowIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use OpenDxp\Logger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Parses Excel-Files according to given configuration
 */
class ExcelParserFast implements Parser, \SeekableIterator, CachableParser
{
    use IteratableParser;
    use ResourceBasedParser;

    /** @var array */
    private $config;

    /** @var Excel */
    private $excelFile;

    /** @var Sheet */
    private $worksheet;

    private $bounds;

    /** @var array */
    private $fieldsToIndexes;

    /** @var RowIterator */
    private $rowIterator;

    /** @var int */
    private $countOfPreviouslyProcessedFiles = 0;

    /** @var string[] */
    private $columnNames = [];

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
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
        if($this->position + $this->countOfPreviouslyProcessedFiles > $this->count()-1) {
            if(empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }
            $this->current = null;
            return $this->current;
        }

        $bounds = $this->getBounds();

        if ($this->fieldsToIndexes === null) {
            $this->fieldsToIndexes = [];

            while($this->getWorksheet()->getReadRowNum() < $bounds['startRow']-1) {
                $this->getWorksheet()->readNextRow();
            }

            $row = $this->getWorksheet()->readNextRow();
            if ($this->config['hasHeader']) {
                foreach ($this->config['fields'] as $field => $values) {
                    $values['column'] = str_replace(["\\t", "\\n", "\\r"], ["\t", "\n", "\r"], $values['column']);
                    $columnName = trim($values['column']);

                    if($values['column'] === '__source') {
                        $this->fieldsToIndexes[-1] = $field;
                        continue;
                    }
                    if ($values['column'] === '__updated') {
                        $this->fieldsToIndexes[-2] = $field;
                        continue;
                    }
                    if ($values['column'] === '__index') {
                        $this->fieldsToIndexes[-3] = $field;
                        continue;
                    }
                    if ($values['column'] === '__all') {
                        $this->fieldsToIndexes[-4] = $field;

                        foreach ($row as $column => $cellValue) {
                            $this->columnNames[$column] = $cellValue;
                        }
                        continue;
                    }
                    if ($values['column'] === '__count') {
                        try {
                            $bounds = $this->getBounds();
                            $this->fieldsToIndexes[-5] = $bounds['endRow'] - $bounds['startRow'] + 1 - ($this->config['hasHeader'] ? 1 : 0);
                        } catch (\Exception $e) {
                            $this->fieldsToIndexes[-5] = 0;
                        }
                        continue;
                    }

                    $countColumns = 0;
                    foreach ($row as $column => $cellValue) {
                        // Get field index if value of cell equals configured column. e.g.: Value1,Value2
                        if (trim((string)$cellValue) === $columnName || $column === $columnName) {
                            $this->fieldsToIndexes[$column] = $field;
                        }
                        $countColumns++;
                    }

                    // case-insensitive column name matching
                    if (count($this->fieldsToIndexes) !== $countColumns) {
                        foreach($row as $column => $cellValue) {
                            if (mb_strtolower(trim((string)$cellValue)) === mb_strtolower($columnName)) {
                                $this->fieldsToIndexes[$column] = $field;
                            }
                        }
                    }
                }

                foreach ($this->fieldsToIndexes as $columnIndex => $fieldNo) {
                    $duplicates = [$columnIndex];
                    foreach ($this->fieldsToIndexes as $columnIndexDuplicate => $fieldNoDuplicate) {
                        if ($columnIndex <= $columnIndexDuplicate) {
                            continue;
                        }
                        if ($fieldNo === $fieldNoDuplicate) {
                            $duplicates[] = $columnIndexDuplicate;
                        }
                    }

                    if (count($duplicates) > 1) {
                        sort($duplicates);
                        $this->logger->warning('Import resource contains column "'.$this->config['fields'][$fieldNo]['column'].'" multiple times (columns '.implode(',', $duplicates).') -> only using last one');
                    }
                }
            } else {
                foreach ($this->config['fields'] as $field => $values) {
                    if ($values['column'] === '__source') {
                        $this->fieldsToIndexes[-1] = $field;
                        continue;
                    }
                    if ($values['column'] === '__updated') {
                        $this->fieldsToIndexes[-2] = $field;
                        continue;
                    }
                    if ($values['column'] === '__index') {
                        $this->fieldsToIndexes[-3] = $field;
                        continue;
                    }
                    if ($values['column'] === '__all') {
                        $this->fieldsToIndexes[-4] = $field;
                        continue;
                    }

                    $colIndex = $values['column'];
                    $this->fieldsToIndexes[$colIndex] = $field;
                }

                foreach ($row as $column => $cellValue) {
                    $this->columnNames[$column] = $cellValue;
                }
            }
        }

        $row = $this->getWorksheet()->readNextRow();
        if ($this->position < $this->offset) {
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        $staticData = [
            -1 => $this->getFileOrUrl(),
            -2 => $this->getLastModified(),
            -3 => $this->position,
            -4 => function () use (&$allItem, $row) {
                if (!isset($allItem)) {
                    $values = [];
                    foreach ($this->columnNames as $index => $columnName) {
                        $values[$columnName] = $row[$index];
                    }
                    $allItem = json_encode($values, \JSON_UNESCAPED_SLASHES);
                }
                return $allItem;
            }
        ];

        $item = [];
        foreach ($this->fieldsToIndexes as $index => $field) {
            if($index < 0) {
                $item[$field] = $staticData[$index];
                if(is_callable($item[$field])) {
                    $item[$field] = $item[$field]();
                }
                continue;
            }

            foreach ($row ?? [] as $column => $cellValue) {
                if ($column === $index) {
                    $value = $cellValue;

                    // Check value for string like NULL and set value to null
                    if (strtoupper($value) === 'NULL') {
                        $value = null;
                    }

                    $item[$field] = $value;
                }
            }
        }

        $item['__updated'] = $this->getLastModified();

        $this->current = $item;
        return $this->current;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        try {
            if($this->getSpreadsheet() === false) {
                return $this->countOfPreviouslyProcessedFiles;
            }
            $bounds = $this->getBounds();
            return $this->countOfPreviouslyProcessedFiles + $bounds['endRow'] - $bounds['startRow'] + 1 - ($this->config['hasHeader']?1:0);
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->countOfPreviouslyProcessedFiles;
        }
    }

    private function getSpreadsheet() {
        if($this->excelFile === null) {
            $this->setSourceFile($this->config['file']);
            $stream = $this->getStream();
            if(!is_resource($stream)) {
                return false;
            }
            $filePathOrUrl = self::getLocalFileFromStream($stream);
            $this->excelFile = Excel::open($filePathOrUrl);
            $this->position = 0;
            $this->rowIterator = null;
            $this->fieldsToIndexes = null;
        }

        return $this->excelFile;
    }

    private function getWorksheet() {
        if ($this->worksheet === null) {
            try {
                $this->worksheet = $this->getSpreadsheet()->getSheet($this->config['sheet']);
                if (!$this->worksheet instanceof Sheet) {
                    foreach ($this->getSpreadsheet()->getSheetNames() as $availableWorksheet) {
                        if (mb_strtolower(trim($availableWorksheet->getTitle(), "'")) === mb_strtolower(trim($this->config['sheet'], "'"))) {
                            $this->worksheet = $this->getSpreadsheet()->getSheet($availableWorksheet);
                            break;
                        }
                    }
                }
                if (!$this->worksheet instanceof Sheet) {
                    $this->worksheet = $this->getSpreadsheet()->getFirstSheet();
                }
            } catch (\Throwable $e) {
                $this->worksheet = null;
            }

            if (!$this->worksheet instanceof Sheet) {
                throw new \Exception('Cannot find worksheet "'.$this->config['sheet'].'"');
            }
        }

        return $this->worksheet;
    }

    private function getRowIterator() {
        if($this->rowIterator === null) {
            $worksheet = $this->getWorksheet();

            if(!$worksheet instanceof Sheet) {
                throw new \Exception('No worksheet found');
            }

            $this->rowIterator = $worksheet->reset();
        }

        return $this->rowIterator;
    }

    private function getBounds() {
        if($this->bounds === null) {
            $data = [
                'startRow' => 1,
                'endRow' => null,
                'startColumn' => 'A',
                'endColumn' => null
            ];
            if (!empty($this->config['dataArea']) && preg_match('/([A-Z]+)(\d*):(([A-Z]+)(\d*))?/', $this->config['dataArea'], $matches)) {
                $data['startColumn'] = $matches[1];
                $data['startRow'] = $matches[2] ?? null;
                $data['endColumn'] = $matches[4] ?? null;
                $data['endRow'] = $matches[5] ?? null;
            }

            if (!$data['startRow']) {
                $data['startRow'] = 1;
            }

            if (!$data['endRow']) {
                $data['endRow'] = 0;
                foreach ($this->getWorksheet()->nextRow(false, Excel::KEYS_ORIGINAL) as $rowIndex => $row) {
                    $data['endRow'] = $rowIndex;
                }
            }

            if (!$data['endColumn']) {
                $columns = $this->getWorksheet()->getColAttributes();
                end($columns);
                $data['endColumn'] = key($columns);
            }

            $this->getWorksheet()->reset();

            $this->bounds = $data;
        }

        return $this->bounds;
    }

    #[\ReturnTypeWillChange]
    public function seek($position)
    {
        $this->position = $position;
    }

    public function gotoNextImportResource()
    {
        $this->archive();

        $this->countOfPreviouslyProcessedFiles += $this->position + 1;
        $this->excelFile = null;
        $this->worksheet = null;
        $this->bounds = null;
        $this->rowIterator = null;
        $this->columnNames = [];

        try {
            $this->getRowIterator();
            return true;
        } catch(\Exception $e) {
            return false;
        }
    }

    protected function getArchiveFileExtension() {
        return 'xlsx';
    }
}
