<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

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
class ExcelParser implements Parser, \SeekableIterator, CachableParser
{
    use IteratableParser;
    use ResourceBasedParser;

    /** @var array */
    private $config;

    /** @var Spreadsheet */
    private $excelFile;

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

        if(defined('LIBXML_DTDLOAD')) {
            Settings::setLibXmlLoaderOptions(LIBXML_DTDLOAD | LIBXML_DTDATTR | LIBXML_PARSEHUGE);
        }

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
            $this->getRowIterator()->seek($bounds['startRow']);
            $row = $this->getRowIterator()->current();
            $cellIterator = $row->getCellIterator($bounds['startColumn'], $bounds['endColumn']);
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

                        foreach ($cellIterator as $cell) {
                            try {
                                $this->columnNames[$cell->getColumn()] = $cell->getFormattedValue();
                            } catch (Throwable $e) {
                                $this->columnNames[$cell->getColumn()] = (string)$cell->getValue();
                            }
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
                    foreach ($cellIterator as $cell) {
                        // Get field index if value of cell equals configured column. e.g.: Value1,Value2
                        if (trim((string)$cell->getValue()) === $columnName || $cell->getColumn() === $columnName) {
                            $this->fieldsToIndexes[$cell->getColumn()] = $field;
                        }
                        $countColumns++;
                    }

                    // case-insensitive column name matching
                    if (count($this->fieldsToIndexes) !== $countColumns) {
                        foreach ($cellIterator as $cell) {
                            if (mb_strtolower(trim((string)$cell->getValue())) === mb_strtolower($columnName)) {
                                $this->fieldsToIndexes[$cell->getColumn()] = $field;
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

                foreach($cellIterator as $cell) {
                    try {
                        $this->columnNames[$cell->getColumn()] = $cell->getFormattedValue();
                    } catch (Throwable $e) {
                        $this->columnNames[$cell->getColumn()] = (string)$cell->getValue();
                    }
                }
            }
        }

        if ($this->position < $this->offset) {
            $this->next();
            if($this->valid() || $this->gotoNextImportResource()){
                goto start;
            } else {
                return null;
            }
        }

        $this->getRowIterator()->seek($this->position+$bounds['startRow']+($this->config['hasHeader']?1:0));
        $row = $this->getRowIterator()->current();
        $cellIterator = $row->getCellIterator($bounds['startColumn'], $bounds['endColumn']);

        $staticData = [
            -1 => $this->getFileOrUrl(),
            -2 => $this->getLastModified(),
            -3 => $this->position,
            -4 => function () use (&$allItem, $row) {
                if (!isset($allItem)) {
                    $values = [];
                    foreach ($this->columnNames as $index => $columnName) {
                        $cell = $this->getWorksheet()->getCell($index.$row->getRowIndex());
                        try {
                            $values[$columnName] = $cell->getFormattedValue();
                        } catch (Throwable $e) {
                            $values[$columnName] = (string)$cell->getValue();
                        }
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

            foreach ($cellIterator as $cell) {
                if ($cell->getColumn() === $index) {
                    $mergeRange = $cell->getMergeRange();
                    if ($mergeRange) {
                        $mergeRange = Coordinate::splitRange($mergeRange);
                        $cell = $cell->getWorksheet()->getCell($mergeRange[0][0]);
                    }

                    try {
                        $value = $cell->getFormattedValue();
                        if($value === '' && preg_match('/=TEXT\([;,](.+)\)/', (string)$cell->getValue(), $match)) {
                            $value = $match[1];
                        }
                    } catch(Throwable $e) {
                        $value = (string)$cell->getValue();
                    }

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
            $bounds = $this->getBounds();
            return $this->countOfPreviouslyProcessedFiles + $bounds['endRow'] - $bounds['startRow'] + 1 - ($this->config['hasHeader']?1:0);
        } catch(\Exception $e) {
            return $this->countOfPreviouslyProcessedFiles;
        }
    }

    private function getSpreadsheet() {
        if($this->excelFile === null) {
            $this->setSourceFile($this->config['file']);
            $filePathOrUrl = self::getLocalFileFromStream($this->getStream());
            $excelReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePathOrUrl);
            $excelReader->setReadDataOnly(true);
            $this->excelFile = $excelReader->load($filePathOrUrl);
            $this->position = 0;
            $this->rowIterator = null;
            $this->fieldsToIndexes = null;
        }

        return $this->excelFile;
    }

    private function getWorksheet() {
        try {
            $worksheet = $this->getSpreadsheet()->getSheetByName($this->config['sheet']);
            if (!$worksheet instanceof Worksheet) {
                foreach($this->getSpreadsheet()->getWorksheetIterator() as $availableWorksheet) {
                    if(mb_strtolower(trim($availableWorksheet->getTitle(), "'")) === mb_strtolower(trim($this->config['sheet'], "'"))) {
                        $worksheet = $availableWorksheet;
                        break;
                    }
                }
            }
            if (!$worksheet instanceof Worksheet) {
                $worksheet = $this->getSpreadsheet()->getSheet(0);
            }
        } catch(\Throwable $e) {
            $worksheet = null;
        }

        if (!$worksheet instanceof Worksheet) {
            throw new \Exception('Cannot find worksheet "'.$this->config['sheet'].'"');
        }

        return $worksheet;
    }

    private function getRowIterator() {
        if($this->rowIterator === null) {
            $worksheet = $this->getWorksheet();
            if(!$worksheet instanceof Worksheet) {
                throw new \Exception('No worksheet found');
            }

            $bounds = $this->getBounds();

            $this->rowIterator = $worksheet->getRowIterator($bounds['startRow'], $bounds['endRow']);
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
                $data['endRow'] = $this->getWorksheet()->getHighestDataRow();
            }

            if (!$data['endColumn']) {
                $data['endColumn'] = $this->getWorksheet()->getHighestDataColumn();
            }

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
