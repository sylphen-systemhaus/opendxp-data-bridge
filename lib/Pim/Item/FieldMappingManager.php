<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\FieldMapperInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class FieldMappingManager
{
    /** @var FieldMapperInterface[] */
    private $fieldMappers = [];

    /** @var array */
    private $cache;

    /**
     * @param FieldMapperInterface[] $fieldMappers
     */
    public function __construct(array $fieldMappers)
    {
        foreach($fieldMappers as $fieldMapper) {
            $this->addFieldMapper($fieldMapper);
        }
    }

    public function addFieldMapper(FieldMapperInterface $fieldMapper) {
        $this->fieldMappers[] = $fieldMapper;
    }

    public function map(array $mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        foreach($this->getFieldMappers($mapping, $fieldDefinition, $dataObject) as $fieldMapper) {
            $value = $fieldMapper->map($mapping, $value, $currentValue, $fieldDefinition, $dataObject);
        }

        return $value;
    }

    /**
     * @param array $mapping
     * @param array $fieldDefinition
     * @return FieldMapperInterface[]
     */
    private function getFieldMappers(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null) {
        $fieldKey = Helper::getFieldKey($mapping);
        if(!isset($this->cache[$fieldKey])) {
            $this->cache[$fieldKey] = [];
            foreach($this->fieldMappers as $fieldMapper) {
                if($fieldMapper->supports($mapping, $fieldDefinition, $dataObject)) {
                    $this->cache[$fieldKey][] = $fieldMapper;
                }
            }
        }

        return $this->cache[$fieldKey];
    }
}