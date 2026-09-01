<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Exception;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Bundle\CustomReportsBundle\Tool\Adapter\CustomReportAdapterInterface;
use OpenDxp\Bundle\CustomReportsBundle\Tool\Config;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ReportParser implements Parser
{
    use IteratableParser;

    private const BLOCK_SIZE = 1000; // fetch this many items from report adapter at once

    /** @var array */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var CustomReportAdapterInterface */
    private $adapter;

    private $currentDataBlock;

    /** @var int */
    private $count;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        if (empty($this->config['fields'])) {
            throw new \Exception('Please configure raw data fields');
        }

        $this->logger = $logger;
    }

    private function getAdapter() {
        if($this->adapter === null) {
            $reportConfig = Config::getByName($this->config['file']);

            if (!$reportConfig instanceof Config && !$reportConfig instanceof \OpenDxp\Model\Tool\CustomReport\Config) {
                throw new RuntimeException('Report with name "' . $this->config['file'] . '" does not exist');
            }

            $configuration = $reportConfig->getDataSourceConfig();
            //if many rows returned as an array than use the first row. Fixes: #782
            $configuration = is_array($configuration) ? $configuration[0] : $configuration;

            $this->adapter = Config::getAdapter($configuration, $reportConfig);
        }

        return $this->adapter;
    }

    private function getCurrentDataBlock() {
        if($this->currentDataBlock === null) {
            $this->currentDataBlock = $this->getAdapter()->getData(null, null, null, $this->position, $this->limit ?? self::BLOCK_SIZE);

            if(empty($this->currentDataBlock['data'])) {
                throw new Exception('No more results from adapter');
            }
        }

        return $this->currentDataBlock;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        if ($this->position >= $this->count()) {
            $this->current = null;
            return $this->current;
        }

        try {
            $data = $this->getCurrentDataBlock()['data'];
            $data[$this->position % self::BLOCK_SIZE]['__index'] = $this->position;
        } catch(Exception $e) {
            $this->current = null;
            return $this->current;
        }

        $this->current = [];
        foreach ($this->config['fields'] as $fieldIndex => $values) {
            $this->current[$fieldIndex] = $data[$this->position % self::BLOCK_SIZE][$values['column']] ?? null;
        }

        if ((($this->position + 1) % self::BLOCK_SIZE) === 0) {
            $this->currentDataBlock = null;
        }

        return $this->current;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        if($this->count === null) {
            $this->count = min($this->getAdapter()->getData(null, null, null, 0, 1)['total'], $this->limit ?? INF);
        }

        return $this->count;
    }

    public function getResource()
    {
        return $this->config['file'];
    }

    /**
     * Sets a logger.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}