<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueCalculator;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Classificationstore\KeyConfig;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\CalculatedValue;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;

class CalculatedValueMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\CalculatedValue || $fieldDefinition->getFieldtype() === 'calculatedValue';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        // virtual field
        if (empty($fieldDefinition->getName()) || $fieldDefinition->getLocked()) {
            return $value;
        }

        $classDefinition = null;

        if ($dataObject !== null) {
            $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
        } else {
            $updatableObject = $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']);;
        }

        if ($updatableObject instanceof Concrete) {
            $classDefinition = $updatableObject->getClass();
        } elseif ($updatableObject instanceof AbstractData || $updatableObject instanceof \OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData) {
            $classDefinition = $updatableObject->getDefinition();
        } elseif ($updatableObject instanceof Classificationstore) {
            $keyConfig = KeyConfig::getByName($fieldDefinition->getName(), $dataObject->getClass()->getFieldDefinition($mapping['targetBrickField'])->getStoreId());
            $classDefinition = new class($keyConfig) {
                /** @var KeyConfig */
                private $keyConfig;

                public function __construct(KeyConfig $keyConfig)
                {
                    $this->keyConfig = $keyConfig;
                }

                public function getFieldDefinition($name)
                {
                    return \OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromKeyConfig($this->keyConfig);
                }

                public function addFieldDefinition($name, Data $fieldDefinition)
                {
                    $this->keyConfig->setDefinition(json_encode($fieldDefinition));
                }

                public function save()
                {
                    $this->keyConfig->save();
                }
            };
        }
        if (!$classDefinition || !$classDefinition->getFieldDefinition($fieldDefinition->getName()) instanceof Data\CalculatedValue) {
            return $value;
        }

        $saveClassDefinition = false;
        if (method_exists($fieldDefinition, 'setCalculatorType')) {
            if (!empty($fieldDefinition->getCalculatorType()) && $fieldDefinition->getCalculatorType() !== 'class' && $fieldDefinition->getCalculatorExpression()) {
                $this->log($dataObject, 'Cannot map data to calculated value field "'.$fieldDefinition->getName().'" because a custom calculator is configured', 'alert');
                return $value;
            }

            if ($fieldDefinition->getCalculatorType() !== 'class') {
                $fieldDefinition->setCalculatorType('class');
                $saveClassDefinition = true;
            }
        }

        if (!empty($fieldDefinition->getCalculatorClass()) && $fieldDefinition->getCalculatorClass() !== '\\'.CalculatedValueCalculator::class) {
            $this->log($dataObject, 'Cannot map data to calculated value field "'.$fieldDefinition->getName().'" because a custom calculator is configured', 'alert');
            return $value;
        }
        if ($fieldDefinition->getCalculatorClass() !== '\\'.CalculatedValueCalculator::class) {
            $fieldDefinition->setCalculatorClass('\\'.CalculatedValueCalculator::class);
            $saveClassDefinition = true;
        }

        if ($fieldDefinition->getColumnLength() < mb_strlen($value)) {
            $fieldDefinition->setColumnLength(65535);
            $saveClassDefinition = true;
        }

        if (!$fieldDefinition->getElementType()) {
            $fieldDefinition->setElementType('html');
            $saveClassDefinition = true;
        }

        if ($fieldDefinition->getElementType() === 'input' && strip_tags($value) !== $value) {
            $fieldDefinition->setElementType('html');
            $saveClassDefinition = true;
        }

        if ($fieldDefinition->getElementType() === 'html' && empty($fieldDefinition->getWidth())) {
            $fieldDefinition->setWidth(500);
            $saveClassDefinition = true;
        }

        if ($saveClassDefinition) {
            $classDefinition->addFieldDefinition($fieldDefinition->getName(), $fieldDefinition);
            $classDefinition->save();
        }

        if ($value !== $currentValue) {
            $calculatedValue = new CalculatedValue($fieldDefinition->getName());
            $ownerType = 'object';
            $ownerName = null;
            $index = null;
            $position = null;
            $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
            if ($updatableObject instanceof AbstractData) {
                $ownerType = 'objectbrick';
                $ownerName = $updatableObject->getFieldname();
                $index = $updatableObject->getType();
            } elseif ($updatableObject instanceof Classificationstore) {
                $ownerType = 'classificationstore';
                $ownerName = $updatableObject->getFieldname();
                $position = $mapping['locale'] ?? null;
            }

            $calculatedValue->setContextualData($ownerType, $ownerName, $index, $position);
            RuntimeCache::save($value, CalculatedValueCalculator::getRuntimeCacheKey($dataObject, $calculatedValue));
        }

        return $value;
    }
}