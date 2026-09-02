<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Classificationstore\GroupConfig;
use OpenDxp\Model\DataObject\Classificationstore\KeyConfig;
use OpenDxp\Model\DataObject\Classificationstore\KeyGroupRelation;
use Throwable;

class ClassificationStoreMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Classificationstore || $fieldDefinition->getFieldtype() === 'classificationstore';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (is_callable($currentValue)) {
            $currentValue = $currentValue();
        }

        $brickContainer = $currentValue;
        if (!$brickContainer instanceof Classificationstore) {
            $brickContainer = new Classificationstore();
            $brickContainer->setObject($dataObject);
            $brickContainer->setFieldname($fieldDefinition->getName());
        }

        $storeId = $fieldDefinition->getStoreId();
        $allowedTypes = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT name FROM '.GroupConfig\Dao::TABLE_NAME_GROUPS.' WHERE storeId = ?', [$storeId]);
        foreach ((array)$value as $brickName => $itemData) {
            if (in_arrayi($brickName, $allowedTypes)) {
                foreach ($itemData as $field => $fieldValue) {
                    $language = null;
                    $fieldParts = explode('#', $field);
                    if (isset($fieldParts[1])) {
                        $field = $fieldParts[0];
                        $language = $fieldParts[1];
                    }

                    $keyConfig = KeyConfig::getByName($field, $storeId, true);
                    if (!$keyConfig instanceof KeyConfig) {
                        if (!empty($mapping['format']['auto_generate_fields'])) {
                            $definition = [
                                'fieldtype' => 'input',
                                'name' => $field,
                                'title' => $field,
                                'datatype' => 'data',
                            ];
                            $keyConfig = new KeyConfig();
                            $keyConfig->setName($field);
                            $keyConfig->setType('input');
                            $keyConfig->setStoreId($storeId);
                            $keyConfig->setEnabled(true);
                            $keyConfig->setDefinition(json_encode($definition));
                            $keyConfig->save();

                            $group = GroupConfig::getByName($brickName, $storeId);
                            if (!$group) {
                                $group = new GroupConfig();
                                $group->setName($brickName);
                                $group->setStoreId($storeId);
                                $group->save();
                            }

                            $keyGroupRelation = new KeyGroupRelation();
                            $keyGroupRelation->setEnabled(true);
                            $keyGroupRelation->setGroupId($group->getId());
                            $keyGroupRelation->setKeyId($keyConfig->getId());
                            $keyGroupRelation->save();

                            $this->log($dataObject, 'Automatically created field "'.$field.'" in store #'.$storeId.' (field "'.$fieldDefinition->getName().'")', 'info');
                        } else {
                            $this->log($dataObject, 'Could not find classification store key "'.$field.'" in store #'.$storeId.' (field "'.$fieldDefinition->getName().'")', 'warning');
                            continue;
                        }
                    }

                    $fieldMapping = ['brickName' => $brickName, 'fieldName' => $field, 'targetBrickField' => $fieldDefinition->getName()];
                    if ($language !== null) {
                        $fieldMapping['locale'] = $language;
                    }

                    try {
                        $classificationStoreFieldDefinition = Importer::getFieldDefinition($brickContainer, $fieldMapping);
                        $currentValue = Importer::getValue($brickContainer, $fieldMapping);

                        if (!empty($mapping['format']['writeProtected']) && !$this->importer->isEmpty($currentValue, $classificationStoreFieldDefinition)) {
                            $this->log($dataObject, 'Field "'.Helper::getFieldKey($fieldMapping).'" is populated and configured to be write-protected -> won\'t change value', 'info');
                            continue;
                        }

                        $fieldValue = $this->importer->map($fieldMapping, $fieldValue, $currentValue, $classificationStoreFieldDefinition, $dataObject);

                        $arguments = [];
                        if (!empty($fieldMapping['locale'])) {
                            $arguments[] = $fieldMapping['locale'];
                        }
                        Importer::setValue($brickContainer, $fieldMapping, $fieldValue, $arguments);
                    } catch (Throwable $e) {
                        $this->importer->getLogger()->info('Could not apply mapping helpers to field '.$field.' of group '.$brickName.', '.$e->getMessage());
                    }
                }
            } else {
                $this->log($dataObject, 'Skipped classification store group "'.$brickName.'" as it is not allowed in field "'.$fieldDefinition->getName().'" (store: '.$storeId.')', 'warning');
            }
        }

        $value = $brickContainer;

        return $value;
    }
}