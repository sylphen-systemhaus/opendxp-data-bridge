<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\RawData;

use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Doctrine\DBAL\ConnectionException;
use Exception;
use OpenDxp\Db;
use OpenDxp\Tool;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\GenericEvent;

class Importer {
    private $dataportResourceId;

    /** @var LoggerInterface */
    private $logger;

    /** @var RawItem */
    private $rawItem;

    /** @var RawItemData */
    private $rawItemData;

    /** @var int[] */
    private $keyFieldFieldNumbers = [];

    private $writeBuffer = [];

    private $dataport;

    public function __construct($dataportResourceId, LoggerInterface $logger) {
        $this->dataportResourceId = (int)$dataportResourceId;

        $this->logger = $logger;
        $this->rawItem = RawItem::getInstance();
        $this->rawItemData = RawItemData::getInstance();

        $dataportResources = DataportResource::getInstance();
        $dataportResource = $dataportResources->get($dataportResourceId);

        if (empty($dataportResource)) {
            throw new Exception('Dataport resource not found');
        }

        $this->dataport = Dataport::getInstance()->get($dataportResource['dataportId']);
        $sourceConfig = $this->dataport['sourceconfig'];

        foreach($sourceConfig['fields'] as $fieldNo => $field) {
            if(!empty($field['exportKey'])) {
                $this->keyFieldFieldNumbers[] = (int)substr($fieldNo, strrpos($fieldNo, '_') + 1);
            }
        }

        // use __updated for hash for Pimcore-based imports to reimport object when object itself changed but to skip import when object did not get changed
        if(count($this->keyFieldFieldNumbers) === 0 && $this->dataport['sourcetype'] === 'pimcore') {
            $targetConfig = $this->dataport['targetconfig'];
            if(!empty($targetConfig['itemClass'])) {
                foreach ($sourceConfig['fields'] as $fieldNo => $field) {
                    $this->keyFieldFieldNumbers[] = (int)substr($fieldNo, strrpos($fieldNo, '_') + 1);
                }
                $this->keyFieldFieldNumbers[] = '__updated';
            }
        }

        if(!extension_loaded('bcmath') && !extension_loaded('gmp')) {
            $this->logger->notice('Raw item IDs get generated as UUIDs. Please install bcmath PHP extension to accelerate processing.');
        }
    }

    /**
     * @param array $item
     *
     * @return string
     */
    public static function getHash(array $item): string
    {
        $values = array_filter($item, static function($field) {
            return $field !== '__updated';
        }, ARRAY_FILTER_USE_KEY);
        return md5(implode('_', $values));
    }

    /**
     * @param array $item associative array 'field_<rawdata field number>' => value
     *
     * @return int|null raw item id or null on failure
     */
    public function insert(array $item, $allowDelayed = false)
    {
        $priority = [];
        $rawItemDataValues = [];
        $keyValues = [];
        $hasEmptyValue = false;

        foreach ($item as $field => $value) {
            $fieldNo = (int)substr($field, strrpos($field, '_') + 1);
            if ($fieldNo > 0) {
                $value = trim((string)$value);

                if ($value !== '') {
                    $rawItemDataValues[$fieldNo] = [
                        'fieldNo' => $fieldNo,
                        'value' => $value,
                    ];

                    if (is_numeric($value)) {
                        $priorityValue = str_pad($value, 10, '0', STR_PAD_LEFT);
                    } else {
                        $priorityValue = mb_strtolower($value);
                    }

                    if(($this->dataport['sourceconfig']['fields']['field_'.$fieldNo]['sort'] ?? 'ASC') === 'DESC') {
                        $reversedPriorityValue = '';
                        foreach(str_split((string)$priorityValue) as $priorityValueChar) {
                            if ('0' <= $priorityValueChar && $priorityValueChar <= 'z') {
                                $reversedPriorityValue .= chr(122 - ord($priorityValueChar) + 48);
                            } else {
                                $reversedPriorityValue .= $priorityValueChar;
                            }
                        }

                        $priorityValue = $reversedPriorityValue;
                    }

                    $priority[] = \mb_substr($priorityValue, 0, 191); // we can cut here already as more than 191 characters will not be stored in the database anyway
                } elseif (($this->dataport['sourceconfig']['fields']['field_'.$fieldNo]['sort'] ?? 'ASC') === 'ASC') {
                    $priority[] = ' '; // whitespace is first (visible) character in sorting
                    $hasEmptyValue = true;
                } else {
                    $priority[] = 'ÿ'; // last (visible) character in sorting
                    $hasEmptyValue = true;
                }
            }
        }

        if (count($rawItemDataValues) === 0 && $this->dataport['sourcetype'] !== 'object-wizard') {
            return null;
        }

        $priority = implode(' ', $priority); // whitespace is first (visible) character in sorting

        $item['__updated'] = !empty($item['__updated']) ? (new \DateTimeImmutable('@0'))->setTimestamp($item['__updated']) : new \DateTimeImmutable();

        if(count($this->keyFieldFieldNumbers) === 0) {
            $hash = self::getHash($item);
        } else {
            foreach ($this->keyFieldFieldNumbers as $keyFieldFieldNumber) {
                if($keyFieldFieldNumber === '__updated') {
                    $keyValues[] = $item['__updated']->getTimestamp();
                    continue;
                }
                $keyValues[] = $rawItemDataValues[$keyFieldFieldNumber]['value'] ?? '';
            }
            $hash = self::getHash($keyValues);
        }

        $rawItemId = UuidGenerator::generate()->getBytes();
        foreach ($rawItemDataValues as &$rawItemDataValue) {
            $rawItemDataValue['rawItemId'] = $rawItemId;
        }
        unset($rawItemDataValue);

        if($hasEmptyValue && count($this->keyFieldFieldNumbers) > 0) {
            PimcoreDbRepository::retry(function() use ($hash) {
                $this->rawItem->deleteWhere([
                    'dataport_resource_id' => $this->dataportResourceId,
                    'hash' => $hash
                ]);
            });
        }

        if($allowDelayed) {
            $this->writeBuffer[$this->dataportResourceId.'-'.$hash] = [
                Installer::TABLE_RAWITEM => [
                    'id' => $rawItemId,
                    'dataport_resource_id' => $this->dataportResourceId,
                    'hash' => $hash,
                    'updated' => $item['__updated'],
                    'priority' => \mb_substr($priority, 0, 191),
                    'toBeDeleted' => 0
                ],
                Installer::TABLE_RAWITEMDATA => array_values($rawItemDataValues)
            ];
        } else {
            PimcoreDbRepository::getInstance()->beginTransaction();

            $this->rawItem->create([
                'id' => $rawItemId,
                'dataport_resource_id' => $this->dataportResourceId,
                'hash' => $hash,
                'updated' => $item['__updated'],
                'priority' => \mb_substr($priority, 0, 191),
                'toBeDeleted' => 0
            ]);

            $this->rawItemData->create(array_values($rawItemDataValues));

            PimcoreDbRepository::getInstance()->commit();
        }

        return $rawItemId;
    }

    public function writeBuffer() {
        if (empty($this->writeBuffer)) {
            return;
        }

        PimcoreDbRepository::retry(function() {
            PimcoreDbRepository::getInstance()->createOrUpdate(array_column($this->writeBuffer, Installer::TABLE_RAWITEM), Installer::TABLE_RAWITEM);

            $rawItemData = array_merge(...array_column($this->writeBuffer, Installer::TABLE_RAWITEMDATA));

            PimcoreDbRepository::getInstance()->createOrUpdate($rawItemData, Installer::TABLE_RAWITEMDATA);
        });

        $this->writeBuffer = [];
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}