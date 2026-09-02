<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\OptionProvider;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;
use OpenDxp\Model\DataObject\Objectbrick\Definition;

class ObjectBrickFieldsOptionProvider implements SelectOptionsProviderInterface
{
    /**
     * @param array $context
     * @param Data\Select|Data\Multiselect $fieldDefinition
     * @return array
     */
    public function getOptions($context, $fieldDefinition): array
    {
        $objectBrickContainerField = explode(':', $fieldDefinition->getOptionsProviderData());

        if(count($objectBrickContainerField) !== 2) {
            throw new \Exception('Please provide object brick container field with syntax <class>:<field>');
        }

        $classDefinition = Helper::getClassDefinitionByName($objectBrickContainerField[0]);
        if(!$classDefinition instanceof ClassDefinition) {
            $classDefinition = Helper::getClassDefinitionById($objectBrickContainerField[0]);
        }

        if (!$classDefinition instanceof ClassDefinition) {
            throw new \Exception('Class "'.$objectBrickContainerField[0].'" does not exist');
        }

        $objectBrickContainerFieldDefinition = $classDefinition->getFieldDefinition($objectBrickContainerField[1]);
        if(!$objectBrickContainerFieldDefinition instanceof Data\Objectbricks) {
            throw new \Exception('Field "'.$objectBrickContainerField[1].'" is not an object brick field');
        }

        $options = [];
        foreach($objectBrickContainerFieldDefinition->getAllowedTypes() as $brickName) {
            $brickDefinition = Definition::getByKey($brickName);
            if(!$brickDefinition instanceof Definition) {
                continue;
            }

            foreach($brickDefinition->getFieldDefinitions() as $brickFieldDefinition) {
                if($brickFieldDefinition instanceof Data\Localizedfields) {
                    foreach ($brickFieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        $options[] = ['key' => $brickName.': '.($localizedFieldDefinition->getTitle() ?: $localizedFieldDefinition->getName()), 'value' => $localizedFieldDefinition->getName()];
                    }
                } else {
                    $options[] = ['key' => $brickName.': '.($brickFieldDefinition->getTitle() ?: $brickFieldDefinition->getName()), 'value' => $brickName.'.'.$brickFieldDefinition->getName()];
                }
            }
        }

        $options = array_values(array_unique($options, SORT_REGULAR));

        usort($options, static function($option1, $option2) {
            return $option1['key'] <=> $option2['key'];
        });

        return $options;
    }

    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return true;
    }

    public function getDefaultValue($context, $fieldDefinition): ?string
    {
        return null;
    }
}