<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Tools\Installer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

class RawItem extends PimcoreDbRepository
{
    public function getTableName(): string
    {
        return Installer::TABLE_RAWITEM;
    }

    public function findByHash($dataportResourceId, $hash) {
        $queryBuilder = new QueryBuilder($this->connection);
        $query = $queryBuilder->select('id')->from($this->getTableName())->where('dataport_resource_id = ? AND hash=?');

        return $this->connection->fetchColumn($query->getSQL(), [$dataportResourceId, $hash]);
    }

    public function create(array $data)
    {
        parent::createOrUpdate($data);

        if(isset($data['id'])) {
            return $data['id'];
        }

        $lastInsertId = $this->connection->lastInsertId();
        if($lastInsertId) {
            return $lastInsertId;
        }

        return $this->findByHash($data['dataport_resource_id'], $data['hash']);
    }

    public function get($id): array
    {
        return $this->findOne(['id = ?' => $id]);
    }
}
