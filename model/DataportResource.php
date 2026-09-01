<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\CachingParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\FilesystemParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\JsonParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\PimcoreParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\Parser;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\XmlParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\CsvParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ExcelParser;
use DateTime;
use Exception;
use OpenDxp\Db;
use OpenDxp\Translation\Translator;
use Psr\Log\LoggerInterface;

class DataportResource extends PimcoreDbRepository {
    public function getTableName(): string
    {
        return Installer::TABLE_DATAPORT_RESOURCE;
    }

    public function create(array $data)
    {
        if(!isset($data['resourceHash'])) {
            $data['resourceHash'] = md5($data['resource']);
        }

        if (!isset($data['lastAccess'])) {
            $data['lastAccess'] = (new DateTime())->format('Y-m-d H:i:s');
        }

        $this->createOrUpdate($data, $this->getTableName());

        $result = $this->findOne(['dataportId = ?' => $data['dataportId'], 'resourceHash = ?' => $data['resourceHash']]);
        if(!$result) {
            $this->execute('UPDATE '.Installer::TABLE_DATAPORT_RESOURCE.' SET resourceHash = MD5(resource) WHERE resourceHash = \'\'');

            $result = $this->findOne(['dataportId = ?' => $data['dataportId'], 'resourceHash = ?' => md5($data['resource'])]);
        }
        return $result;
    }
}