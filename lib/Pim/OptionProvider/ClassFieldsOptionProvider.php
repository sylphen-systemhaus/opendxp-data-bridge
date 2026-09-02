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
use OpenDxp\Tool;

class ClassFieldsOptionProvider implements SelectOptionsProviderInterface
{
    /**
     * @param array $context
     * @param Data\Select|Data\Multiselect $selectFieldDefinition
     * @return array
     */
    public function getOptions($context, $selectFieldDefinition): array
    {
        $classId = $selectFieldDefinition->getOptionsProviderData();

        $classDefinition = Helper::getClassDefinitionByName($classId);
        if(!$classDefinition instanceof ClassDefinition) {
            $classDefinition = Helper::getClassDefinitionById($classId);
        }

        if (!$classDefinition instanceof ClassDefinition) {
            throw new \Exception('Class "'.$classId.'" does not exist. Please set the class name in "Option Provider Data"');
        }

        $options = [];
        foreach($classDefinition->getFieldDefinitions() as $fieldDefinition) {
            if($fieldDefinition instanceof Data\Localizedfields) {
                foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                    foreach(Tool::getValidLanguages() as $language) {
                        $options[] = ['key' => ($localizedFieldDefinition->getTitle() ?: $localizedFieldDefinition->getName()).' '.\Locale::getDisplayLanguage($language), 'value' => $localizedFieldDefinition->getName().'#'.$language];
                    }
                }
            } else {
                $options[] = ['key' => $fieldDefinition->getTitle() ?: $fieldDefinition->getName(), 'value' => $fieldDefinition->getName()];
            }
        }

        $optionsWithSameKey = array_count_values(array_column($options, 'key'));
        foreach ($options as &$option) {
            if ($optionsWithSameKey[$option['key']] > 1) {
                $option['key'] .= ' ('.$option['value'].')';
            }
        }
        unset($option);

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