<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Exception;
use Iterator;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset\Image;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Tool;

class ImageMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Image || $fieldDefinition->getFieldtype() === 'image';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $assetSource = $value;
        if ($assetSource instanceof Iterator) {
            $assetSource->rewind();
            $assetSource = $assetSource->current();
        }

        try {
            $value = $this->getAsset($assetSource, ['overwrite' => !empty($mapping['format']['overwrite']), 'preventDuplicates' => !empty($mapping['format']['preventDuplicates'])]);

            $fieldDefinition->checkValidity($value);

            if ($value instanceof Image && is_array($assetSource)) {
                $assetNeedsSaving = false;
                $addedMetaData = [];
                foreach ($assetSource as $key => $metaItem) {
                    if (\in_array($key, ['url', 'query', 'filename', 'fullpath']) || is_numeric($key)) {
                        continue;
                    }
                    if (is_array($metaItem)) {
                        foreach ($metaItem as $language => $localizedValue) {
                            if (!Tool::isValidLanguage($language)) {
                                $this->log($value, 'It is not possible to set asset metadata field "'.$key.'" for language "'.$language.'" as the language does not exist. Please check System settings > Localization & Internationalization', 'error');
                            }

                            try {
                                $metaObject = $this->importer->getObjectByIdentifier($localizedValue);
                                if ($metaObject !== $localizedValue) {
                                    $localizedValue = $metaObject;
                                }
                            } catch (Exception $e) {
                            }

                            if ($value->getMetadata($key, $language) != $localizedValue) {
                                $metadataType = ($localizedValue instanceof ElementInterface ? Service::getElementType($localizedValue) : 'input');
                                $value->addMetadata($key, $metadataType, $localizedValue, $language);
                                $addedMetaData[] = [
                                    'name' => $key,
                                    'type' => $metadataType,
                                    'data' => $localizedValue,
                                    'language' => $language
                                ];
                                $assetNeedsSaving = true;
                            }
                        }
                    } else {
                        try {
                            $metaObject = $this->importer->getObjectByIdentifier($metaItem);
                            if ($metaObject !== $metaItem) {
                                $metaItem = $metaObject;
                            }
                        } catch (Exception $e) {
                        }

                        if ($value->getMetadata($key) != $metaItem) {
                            $metadataType = $metaItem instanceof ElementInterface ? Service::getElementType($metaItem) : 'input';
                            $value->addMetadata($key, $metadataType, $metaItem);
                            $addedMetaData[] = [
                                'name' => $key,
                                'type' => $metadataType,
                                'data' => $metaItem
                            ];
                            $assetNeedsSaving = true;
                        }
                    }
                }

                if ($assetNeedsSaving) {
                    $value->save(['versionNote' => 'Dataport: '.$this->importer->getDataport()['id']]);
                    $this->log($value, 'Metadata of asset #'.$value->getId().' '.$value->getRealFullPath().' updated: '.json_encode($addedMetaData, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'info');
                }
            }
        } catch (ValidationException $e) {
            $this->log($dataObject, 'Skipped '.Importer::getLogOutput($value).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');

            if (is_callable($currentValue)) {
                $currentValue = $currentValue();
            }
            $value = $currentValue;
        }

        return $value;
    }
}