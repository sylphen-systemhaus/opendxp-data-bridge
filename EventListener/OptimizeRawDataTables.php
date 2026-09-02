<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Logger;

class OptimizeRawDataTables
{
    public function optimize() {
        $dataportIsRunning = ImportStatus::getInstance()->findOne(['status = ?' => ImportStatus::STATUS_RUNNING]);
        if (!$dataportIsRunning) {
            Logger::info('Truncating raw data tables to regain disk space');

            PimcoreDbRepository::getInstance()->execute('OPTIMIZE TABLE '.Installer::TABLE_RAWITEM);

            $fulltextIndexName = 'fulltext_value';
            $indexExists = PimcoreDbRepository::getInstance()->findOneInSql('SHOW INDEX FROM '.Installer::TABLE_RAWITEMDATA.' WHERE Key_name = ?', [$fulltextIndexName]);
            if($indexExists) {
                PimcoreDbRepository::getInstance()->execute('ALTER TABLE '.Installer::TABLE_RAWITEMDATA.' DROP INDEX '.$fulltextIndexName);
            }
            PimcoreDbRepository::getInstance()->execute('OPTIMIZE TABLE '.Installer::TABLE_RAWITEMDATA);
            PimcoreDbRepository::getInstance()->execute('ALTER TABLE '.Installer::TABLE_RAWITEMDATA.' ADD FULLTEXT INDEX '.$fulltextIndexName.' (`value`)');
        } else {
            $dataport = Dataport::getInstance()->get($dataportIsRunning['dataport_id']);
            Logger::info('Cannot truncate raw data tables, because dataport #'.$dataport['id'].' ('.$dataport['name'].') is currently running');
        }
    }
}