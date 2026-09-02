<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Exception\RetryableException;
use Exception;
use League\Flysystem\UnableToSetVisibility;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Db;
use OpenDxp\Model\User;
use OpenDxp\Tool\Admin;
use Ramsey\Uuid\Uuid;

class ImportStatus extends PimcoreDbRepository
{
    const TYPE_RAWDATA = 2**2;
    const TYPE_PIM = 2**3;
    const TYPE_COMPLETE = 2**5;
    const TYPE_DRY_RUN = 2**4;

    const STATUS_RUNNING = 0;
    const STATUS_FINISHED = 1;
    const STATUS_ABORTED = 2;

    public function getTableName(): string
    {
        return Installer::TABLE_IMPORTSTATUS;
    }

    /**
     * @param array $data
     * @return FileObject|null
     */
    public function create(array $data)
    {
        if(!isset($data['pid'])) {
            $data['pid'] = getmypid();
        }

        if (isset($data['startDate']) && $data['startDate'] instanceof DateTimeInterface) {
            $data['startDate'] = $data['startDate']->setTimezone(new DateTimeZone('UTC'));
        }

        if (isset($data['endDate']) && $data['endDate'] instanceof DateTimeInterface) {
            $data['endDate'] = $data['endDate']->setTimezone(new DateTimeZone('UTC'));
        }

        $parametersHash = md5($this->getTableName().'-'.implode('-', array_keys($data)));
        if (!isset(self::$preparedStatements[$parametersHash])) {
            $columnList = \array_keys($data);

            $countColumnList = count($columnList);
            if ($countColumnList === 0) {
                return;
            }

            $query = 'INSERT INTO '.$this->getTableName().' ('.implode(',', array_map([$this->connection, 'quoteIdentifier'], $columnList)).') VALUES ';

            $updateList = array_map(function ($column) {
                return $this->connection->quoteIdentifier($column).'=VALUES('.$this->connection->quoteIdentifier($column).')';
            }, $columnList);

            $query .= '('.rtrim(\str_repeat('?,', $countColumnList), ',').') ON DUPLICATE KEY UPDATE '.implode(',', $updateList);
            self::$preparedStatements[$parametersHash] = self::prepare($query);
            self::$preparedStatements[$parametersHash.'-types'] = $this->getDataTypes($data);
        } else {
            $this->getDataTypes($data);
        }

        foreach (array_values($data) as $key => $dataItem) {
            self::$preparedStatements[$parametersHash]->bindValue($key + 1, $dataItem, self::$preparedStatements[$parametersHash.'-types'][$key]);
        }

        self::$preparedStatements[$parametersHash]->executeQuery();

        if (empty($data['dataport_resource_id'])) {
            return null;
        }

        $type = self::getImportTypeDescription($data['importType']);

        $dataportResource = DataportResource::getInstance();
        $dataportResource = $dataportResource->get($data['dataport_resource_id']);

        $dataportResourceResource = json_decode($dataportResource['resource'], true);

        $user = Helper::getUser();
        $resourceString = [];
        foreach($dataportResourceResource as $dataportResourceResourceField => $dataportResourceResourceValue) {
            if(!is_scalar($dataportResourceResourceValue)) {
                $dataportResourceResourceValue = json_encode($dataportResourceResourceValue, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            $resourceString[] = "\n".$dataportResourceResourceField.': '.$dataportResourceResourceValue;
        }

        $fileObject = new FileObject('Logs for '.$type.' (Dataport Id: '.$data['dataport_id'].', Resource: '.implode("\n", $resourceString).(($user instanceof User) ? ' by '.$user->getName() : '').')', $data['dataport_id'].'/'.$data['key']);

        return $fileObject;
    }

    /**
     * @param $importType
     * @return string
     */
    public static function getImportTypeDescription($importType): string {
        switch(true) {
            case $importType & self::TYPE_RAWDATA:
                $type = 'Rawdata Extraction';
                break;
            case $importType & self::TYPE_PIM:
                $type = 'Rawdata Processing';
                break;
            case $importType & self::TYPE_COMPLETE:
                $type = 'Complete Processing';
                break;
            case $importType & self::TYPE_DRY_RUN:
                $type = 'Dry run';
                break;
            default:
                $type = 'Unknown import';
                break;
        }

        return $type;
    }

    public function queueRestart($statusKey) {
        $status = $this->findOne(['`key` = ?' => $statusKey]);
        if ($status) {
            $commandParameters = json_decode($status['command_parameters'], true);

            $redoable = true;
            if($status['importType'] & self::TYPE_PIM) {
                if (!isset($commandParameters['--dataport-resource-id'])) {
                    $redoable = false;
                } else {
                    $dataportResourceIds = explode(',', $commandParameters['--dataport-resource-id']);
                    $dataportResourceHasRawItem = $this->findOneInSql('SELECT 1 FROM '.Installer::TABLE_RAWITEM.' rawitem WHERE dataport_resource_id IN (?) LIMIT 1', [$dataportResourceIds]);
                    if (!$dataportResourceHasRawItem) {
                        $redoable = false;
                    }
                }

                $rawItems = RawItem::getInstance();
                if ($redoable && !empty($commandParameters['rawitem'])) {
                    $redoable = false;
                }
            }

            if ($redoable) {
                $cmd = null;
                if ($status['importType'] & self::TYPE_PIM) {
                    $rawItemConditions = ['dataport_resource_id = ?' => $commandParameters['--dataport-resource-id']];
                    if (!empty($commandParameters['rawitem'])) {
                        $rawItemConditions['id IN (?)'] = explode(',', $commandParameters['rawitem']);
                    }
                    $firstUnprocessedRawItem = $rawItems->findOne($rawItemConditions, 'priority', $status['doneItems']);

                    if ($firstUnprocessedRawItem) {
                        $dataportResource = DataportResource::getInstance()->get($commandParameters['--dataport-resource-id']);

                        $dataport = (new Dataport())->get($dataportResource['dataportId']);
                        $targetConfig = $dataport['targetconfig'];
                        $continuable = true;
                        if (empty($targetConfig['itemClass'])) {
                            $sourceConfig = $dataport['sourceconfig'];
                            if (empty($sourceConfig['incrementalExport'])) {
                                $continuable = false;
                            }
                        }

                        $cmd = 'data-bridge:process '.$dataportResource['dataportId'].($continuable ? ' '.Uuid::fromBytes($firstUnprocessedRawItem['id'])->getInteger().'-' : '').' --dataport-resource-id='.$commandParameters['--dataport-resource-id'].(!empty($commandParameters['-f']) ? ' -f' : '');
                    }
                } elseif(isset($commandParameters['filename'])) {
                    $dataport = Dataport::getInstance()->get($status['dataport_id']);
                    $cmd = 'data-bridge:'.(($status['importType'] & self::TYPE_COMPLETE) === self::TYPE_COMPLETE ? 'complete':'extract').' '.$dataport['id'].' "'.$commandParameters['filename'].'"'.(!empty($commandParameters['--dataport-resource-id']) ? ' --dataport-resource-id='.$commandParameters['--dataport-resource-id']:'').(!empty($commandParameters['-f']) ? ' -f' : '');
                }

                if($cmd !== null) {
                    register_shutdown_function(
                        static function () use ($cmd, $dataport) {
                            $websiteSettingName = 'next-execution-dataport-'.$dataport['id'];
                            $websiteSetting = \OpenDxp\Model\WebsiteSetting::getByName($websiteSettingName);
                            if ($websiteSetting) {
                                $websiteSetting->delete();
                            }

                            Queue::getInstance()->create([
                                'command' => $cmd,
                                'triggered_by' => 'Restarted because original process was terminated unintentionally',
                                'worker_id' => $dataport['id']
                            ]);
                        }
                    );
                }

                return true;
            }
        }
        return false;
    }

    public function update($data, $where)
    {
        foreach ($data as &$dataItem) {
            if ($dataItem instanceof DateTimeInterface) {
                $dataItem = $dataItem->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return parent::update($data, $where);
    }

    public static function getLogs($statusKey)
    {
        $statusKey = trim($statusKey);
        $status = self::getInstance()->findOne(['`key` = ?' => $statusKey]);
        if (!$status) {
            return [];
        }

        $logFileObjectPath = $status['dataport_id'].'/'.$statusKey;

        $logStorage = \Sylphen\DataBridgeBundle\lib\Pim\Helper::getApplicationLogStorage();
        if ($logStorage->fileExists($logFileObjectPath)) {
            $dependentDataportLogs = $logStorage->read($logFileObjectPath);
            $dependentDataportLogLines = explode("\n", $dependentDataportLogs);
            return $dependentDataportLogLines;
        }

        return [];
    }
}