<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Report;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importmanager;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Db;
use OpenDxp\Bundle\CustomReportsBundle\Tool\Adapter\AbstractAdapter;
use OpenDxp\Tool;
use OpenDxp\Tool\Admin;

class Adapter extends AbstractAdapter
{
    /** @var array|null mapping of fieldNo => field name */
    private $rawItemFields = null;

    private $data = [];

    public function getData($filters, $sort, $dir, $offset, $limit, $fields = null, $drillDownFilters = null): array
    {
        if(!isset($this->data[md5(json_encode(func_get_args()))])) {
            if (!Helper::getUser()->isAllowed(Dataport::getExecutionPermissionName($this->config->dataport))) {
                return ['data' => [], 'total' => 0];
            }

            $data = [];

            $table = Dataport::getInstance();
            $dataport = $table->get($this->config->dataport);

            if (!$dataport) {
                return ['data' => [], 'total' => 0];
            }

            $dataportResources = DataportResource::getInstance();
            $dataportResource = $dataportResources->create([
                'dataportId' => $this->config->dataport,
                'resource' => json_encode([
                    'locale' => Helper::getUser()->getLanguage()
                ])
            ]);

            $skipDataLoading = false;
            if((new RawItem())->findOne(['dataport_resource_id = ?' => $dataportResource['id']])) {
                $skipDataLoading = true;
            }

            if(!$skipDataLoading) {
                $importManager = \OpenDxp::getContainer()->get(Importmanager::class);
                $importManager->importDataport($this->config->dataport, ImportStatus::TYPE_RAWDATA, $dataportResource['id']);
            }

            $isMultivalue = [];
            $sourceconfig = $dataport['sourceconfig'];
            if (is_array($sourceconfig['fields'])) {
                $isMultivalue = array_filter(
                    $sourceconfig['fields'],
                    static function ($values) {
                        return isset($values['multiValues']) && $values['multiValues'] === true;
                    }
                );
            }

            $start = (int)$offset;
            if (!$start || $start < 0) {
                $start = 0;
            }

            $limit = (int)$limit;
            if (!$limit || $limit < 1) {
                $limit = 25;
            }

            foreach((array)$drillDownFilters as $field => $value) {
                $filters[] = ['operator' => 'eq', 'property' => $field, 'value' => $value];
            }

            $condition = 'FROM '.Installer::TABLE_RAWITEM.' i'.((!empty($filters) || !empty($sort)) ? ' INNER JOIN '.Installer::TABLE_RAWITEMDATA.' d ON i.id = d.rawItemId' : '').' INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON i.dataport_resource_id = dataport_resource.id'.(!empty($filters) ? ' INNER JOIN '.Installer::TABLE_RAWITEMFIELD.' field ON d.fieldNo=field.fieldNo AND dataport_resource.dataportId=field.dataportId' : '').' WHERE dataport_resource.dataportId = ?';

            $variables = [$this->config->dataport];
            if ($filters) {
                $conditions = [1];
                foreach ($filters as $filter) {
                    if ($filter['value'] && $filter['operator'] === 'in') {
                        $conditionsOr = [];
                        foreach ($filter['value'] as $filterValue) {
                            $conditionsOr[] = 'd.value=? AND field.name=?';
                            $variables[] = $filterValue;
                            $variables[] = $filter['property'];
                        }
                        $conditions[] = implode(' OR ', $conditionsOr);
                    } elseif ($filter['value'] && $filter['operator'] === 'eq') {
                        $conditions[] = 'd.value LIKE ? AND field.name=?';
                        $variables[] = '%'.$filter['value'].'%';
                        $variables[] = $filter['property'];
                    } elseif ($filter['value'] && $filter['operator'] === 'lt') {
                        $conditions[] = 'd.value <= ? AND field.name=?';
                        $variables[] = $filterValue;
                        $variables[] = $filter['property'];
                    } elseif ($filter['value'] && $filter['operator'] === 'gt') {
                        $conditions[] = 'd.value >= ? AND field.name=?';
                        $variables[] = $filterValue;
                        $variables[] = $filter['property'];
                    }
                }

                $condition .= ' AND ('.implode(') AND (', $conditions).')';
            }

            if (!empty($sort)) {
                $sortQuery = 'GROUP_CONCAT(IF(d.fieldNo = '.array_search($sort, $this->getRawItemFields()).', d.value, "")) '.$dir;
            } else {
                $targetConfig = $dataport['targetconfig'];
                $sortQuery = 'i.dataport_resource_id, '.(empty($targetConfig['itemClass']) ? 'i.priority' : 'updated,i.priority');
            }

            $items = PimcoreDbRepository::getInstance()->findInSql('SELECT SQL_CALC_FOUND_ROWS i.id, i.updated, dataport_resource.resource '.$condition.' GROUP BY i.priority ORDER BY '.$sortQuery.' LIMIT '.$start.','.$limit, $variables);

            $total = PimcoreDbRepository::getInstance()->findOneInSql('SELECT FOUND_ROWS()');

            foreach ($items as $item) {
                $data[] = $this->getRawItemPreview($item, $item, $isMultivalue, $fields);
            }

            $this->data[md5(json_encode(func_get_args()))] = ['data' => $data, 'total' => $total];
        }

        return $this->data[md5(json_encode(func_get_args()))];
    }

    private function getRawItemFields() {
        if ($this->rawItemFields === null) {
            $this->rawItemFields = array_column((new RawItemField())->find(['dataportId = ?' => $this->config->dataport]), 'name', 'fieldNo');
        }
        return $this->rawItemFields;
    }

    private function getRawItemPreview($rawItem, array $dataportResource = [], array $isMultivalue = [], array $fields = null)
    {
        $itemData = [];

        $rawItemDataRepository = RawItemData::getInstance();

        $condition = ['rawItemId = ?' => $rawItem['id']];
        if(is_array($fields)) {
            $condition['fieldNo IN (?)'] = array_map(function($field) {
                return array_search($field, $this->getRawItemFields(), true);
            }, $fields);
        }
        $rawItemData = $rawItemDataRepository->find($condition);
        foreach ($rawItemData as $row) {
            $value = $row['value'];
            if (array_key_exists('field_'.$row['fieldNo'], $isMultivalue)) {
                $unserialized = $value;
                if (\is_array($unserialized)) {
                    $itemData[$this->getRawItemFields()[$row['fieldNo']]] = json_encode($unserialized, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                } elseif (\is_object($unserialized)) {
                    $itemData[$this->getRawItemFields()[$row['fieldNo']]] = json_encode(\get_object_vars($unserialized), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                } else {
                    $itemData[$this->getRawItemFields()[$row['fieldNo']]] = $value;
                }
            } else {
                if (is_string($value) && !is_numeric($value)) {
                    $decodedValue = json_decode($value, true);
                    if (json_last_error() === \JSON_ERROR_NONE) {
                        $value = json_encode($decodedValue, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);;
                    }
                }
                $itemData[$this->getRawItemFields()[$row['fieldNo']]] = $value;
            }
        }

        return $itemData;
    }

    public function getColumns($configuration): array
    {
        return array_values($this->getRawItemFields());
    }

    public function getAvailableOptions($filters, $field, $drillDownFilters): array
    {
        $data = array_unique(array_column($this->getData($filters, null, null, null, null, [$field], $drillDownFilters)['data'], $field));

        return ['data' => array_map(static function($data) {
            return ['value' => $data];
        }, $data)];
    }
}