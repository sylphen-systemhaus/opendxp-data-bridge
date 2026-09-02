<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\OptionProvider;

use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Google\Auth\Cache\Item;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\DynamicOptionsProvider\MultiSelectOptionsProviderInterface;
use OpenDxp\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;

class DataQuerySelectorOptionProvider implements SelectOptionsProviderInterface
{
    /** @var ImporterInterface */
    private $importer;

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    /**
     * @param ImporterInterface $importer
     * @param ItemMoldBuilder $itemMoldBuilder
     */
    public function __construct(ImporterInterface $importer, ItemMoldBuilder $itemMoldBuilder)
    {
        $this->importer = $importer;
        $this->itemMoldBuilder = $itemMoldBuilder;
    }

    /**
     * @param array $context
     * @param Data\Select|Data\Multiselect $fieldDefinition
     * @return array
     */
    public function getOptions($context, $fieldDefinition): array
    {
        $object = $context['object'] ?? null;

        $dataQuerySelector = $fieldDefinition->getOptionsProviderData();

        $dataQuerySelector = preg_replace_callback('/\{\{\s*(\S+?( +\S+?)*?)\s*\}\}/', function ($matches) use ($object) {
            return $this->importer->replaceObjectIdentifier($matches[0], $object);
        }, $dataQuerySelector);

        if (strpos($dataQuerySelector, '.:.:.:') !== 0) {
            $parts = \str_getcsv($dataQuerySelector, ':', '"');
            if (count($parts) >= 3) {
                if ($this->itemMoldBuilder->getClass($parts[0]) !== null) {
                    // if we get here first data query selector part also exists as data object class -> check if field for $object with same name exists -> field has higher priority
                    $referencedFieldDefinition = Importer::getFieldDefinition($object, $parts[0]);
                    if (!$referencedFieldDefinition->getLocked()) {
                        $dataQuerySelector = '.:.:.:'.$dataQuerySelector;
                    }
                } else {
                    $dataQuerySelector = '.:.:.:'.$dataQuerySelector;
                }
            } else {
                $dataQuerySelector = '.:.:.:'.$dataQuerySelector;
            }
        }

        $result = $this->importer->getObjectByIdentifier($dataQuerySelector, $object);

        if(is_scalar($result) || $result === null) {
            $result = [$result];
        }

        $result = array_filter($result);

        $result = array_map(static function($resultItem) {
            $value = $resultItem;

            if(is_array($resultItem) && isset($resultItem['value'])) {
                $value = $resultItem['value'];
            }

            if (isset($resultItem['label'])) {
                $key = $resultItem['label'];
            } elseif (isset($resultItem['key'])) {
                $key = $resultItem['key'];
            }

            if (!is_scalar($value)) {
                if (isset($value['id']) && !isset($key)) {
                    $key = $value['id'];
                }
                $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }

            if(!isset($key)) {
                $key = $value;
            }

            return [
                'key' => $key,
                'value' => $value
            ];
        }, $result);

        $result = array_values(array_unique($result, SORT_REGULAR));

        usort($result, static function ($option1, $option2) {
            return $option1['key'] <=> $option2['key'];
        });

        return $result;
    }

    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return !preg_match('/\{\{\s*(\S+?( +\S+?)*?)\s*\}\}/', $fieldDefinition->getOptionsProviderData());
    }

    public function getDefaultValue($context, $fieldDefinition): ?string
    {
        return null;
    }
}