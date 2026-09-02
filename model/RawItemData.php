<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Tools\Installer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Doctrine\DBAL\Exception\RetryableException;

class RawItemData extends PimcoreDbRepository
{
    public function getTableName(): string
    {
        return Installer::TABLE_RAWITEMDATA;
    }

    public function create(array $data)
    {
        if(!isset($data[0])) {
            $data = [$data];
        }

        $columnList = \array_keys($data[0]);
        $paramValues = [];
        foreach ($data as $dataset) {
            foreach ($dataset as $value) {
                $paramValues[] = $value;
            }
        }

        $countColumnList = count($columnList);
        if($countColumnList === 0) {
            return;
        }

        $query = 'INSERT INTO ' . $this->getTableName() . ' (' . implode(',', array_map([$this->connection, 'quoteIdentifier'], $columnList)) . ') VALUES ';

        $values = array_fill(0, count($data), '('.rtrim(\str_repeat('?,', $countColumnList), ',').')');

        $nonKeyFields = array_filter($columnList, static function($column) {
            return !in_array($column, ['rawItemId','fieldNo']);
        });

        $updateList = array_map(function($column) {
            return $this->connection->quoteIdentifier($column).'=VALUES('.$this->connection->quoteIdentifier($column).')';
        }, $nonKeyFields);

        $query .= implode(',', $values).' ON DUPLICATE KEY UPDATE '.implode(',', $updateList);

        $this->execute($query, $paramValues);
    }
}