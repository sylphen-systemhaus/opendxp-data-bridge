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
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Data\Hotspotimage;
use OpenDxp\Model\DataObject\Data\ImageGallery;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Tool;

class ImageGalleryMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\ImageGallery || $fieldDefinition->getFieldtype() === 'imageGallery';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (is_callable($currentValue)) {
            $currentValue = $currentValue();
        }

        if (!$currentValue instanceof ImageGallery || !empty($mapping['format']['purgeitems'])) {
            $currentValue = new ImageGallery([]);
            $this->setPurged($mapping, $dataObject);
        }

        if (empty($value)) {
            $value = [];
        }

        if (!is_array($value) || (count($value) > 0 && !isset($value[0]))) {
            $value = [$value];
        }

        $currentItems = $currentValue->getItems();
        foreach ($currentItems as $index => $currentItem) {
            if (!$currentItem instanceof Hotspotimage || !$currentItem->getImage() instanceof Asset\Image) {
                unset($currentItems[$index]);
            }
        }
        $currentItems = array_values($currentItems);
        $currentValue->setItems($currentItems);

        foreach ($value as $assetSource) {
            if (is_array($assetSource) && isset($assetSource['image'])) {
                $assetSource = $assetSource['image'];
            }

            $newAsset = $this->getAsset($assetSource, ['overwrite' => !empty($mapping['format']['overwrite']), 'preventDuplicates' => !empty($mapping['format']['preventDuplicates'])]);

            if (!$newAsset instanceof Asset\Image) {
                if ($newAsset instanceof Asset) {
                    $this->log($newAsset, 'Skipping '.$newAsset->getFilename().' because it is not an image', 'warning');
                }

                continue;
            }

            $assetToUpdateId = null;
            $assetSourceURI = null;
            $newAssetChecksum = null;

            $assetRecognitionFunctions = [
                static function ($existingAsset) use ($newAsset) {
                    $existingAssetImage = $existingAsset->getImage();
                    if (!$existingAssetImage instanceof Asset\Image) {
                        return false;
                    }
                    return $newAsset->getId() === $existingAssetImage->getId();
                },
                static function ($existingAsset) use (&$assetSourceURI, $newAsset) {
                    $existingAssetImage = $existingAsset->getImage();
                    if (!$existingAssetImage instanceof Asset\Image) {
                        return false;
                    }

                    if (!$assetSourceURI) {
                        $assetSourceURI = $newAsset->getProperty('sourcePath');
                    }

                    return $assetSourceURI && $existingAssetImage->getProperty('sourcePath') === $assetSourceURI;
                },
                static function ($existingAsset) use (&$newAssetChecksum, $newAsset) {
                    try {
                        if (!$newAssetChecksum) {
                            $newAssetChecksum = Importer::getAssetChecksum($newAsset);
                        }
                        return $newAssetChecksum && Importer::getAssetChecksum($existingAsset->getImage()) === $newAssetChecksum;
                    } catch (\Throwable $e) {
                        return false;
                    }
                },
            ];

            foreach ($assetRecognitionFunctions as $index => $assetRecognitionFunction) {
                /** @var Hotspotimage $currentAsset */
                foreach ($currentItems as $key => $currentAsset) {
                    if (!$currentAsset instanceof Hotspotimage) {
                        continue;
                    }
                    if ($assetRecognitionFunction($currentAsset)) {
                        $assetToUpdateId = $key;
                        $this->importer->getLogger()->info('Asset "'.$newAsset->getFullpath().'" seems to be a duplicate of the already assigned "'.$currentAsset->getImage()->getFullpath().'" -> update existing one');
                        break 2;
                    }
                }
            }

            if (is_array($assetSource)) {
                $assetNeedsSaving = false;
                $addedMetaData = [];
                foreach ($assetSource as $key => $value) {
                    if (\in_array($key, ['url', 'query', 'filename', 'fullpath']) || is_numeric($key)) {
                        continue;
                    }
                    if (is_array($value)) {
                        foreach ($value as $language => $localizedValue) {
                            if (!Tool::isValidLanguage($language)) {
                                $this->log($newAsset, 'It is not possible to set asset metadata field "'.$key.'" for language "'.$language.'" as the language does not exist. Please check System settings > Localization & Internationalization', 'error');
                            }

                            try {
                                $metaObject = $this->importer->getObjectByIdentifier($localizedValue);
                                if ($metaObject !== $localizedValue) {
                                    $localizedValue = $metaObject;
                                }
                            } catch (Exception $e) {
                            }


                            if ($newAsset->getMetadata($key, $language) != $localizedValue) {
                                $metadataType = ($localizedValue instanceof ElementInterface ? Service::getElementType($localizedValue) : 'input');
                                $newAsset->addMetadata($key, $metadataType, $localizedValue, $language);
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
                            $metaObject = $this->importer->getObjectByIdentifier($value);
                            if ($metaObject !== $value) {
                                $value = $metaObject;
                            }
                        } catch (Exception $e) {
                        }

                        if ($newAsset->getMetadata($key) != $value) {
                            $metadataType = ($value instanceof ElementInterface ? Service::getElementType($value) : 'input');
                            $newAsset->addMetadata($key, $metadataType, $value);
                            $addedMetaData[] = [
                                'name' => $key,
                                'type' => $metadataType,
                                'data' => $value
                            ];
                            $assetNeedsSaving = true;
                        }
                    }
                }
                if ($assetNeedsSaving) {
                    $newAsset->save(['versionNote' => 'Dataport: '.$this->importer->getDataport()['id']]);
                    $this->log($newAsset, 'Metadata of asset #'.$newAsset->getId().' '.$newAsset->getRealFullPath().' added: '.json_encode($addedMetaData, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'info');
                }
            }

            try {
                $hotspotImage = new Hotspotimage($newAsset);
                if ($assetToUpdateId !== null) {
                    $fieldDefinition->checkValidity(array_replace($currentItems, [$assetToUpdateId => $hotspotImage]));
                    $currentItems[$assetToUpdateId] = $hotspotImage;
                } else {
                    $fieldDefinition->checkValidity(new ImageGallery(array_merge($currentItems, [$hotspotImage])));
                    $currentItems[] = $hotspotImage;
                }

                $currentValue->setItems($currentItems);
            } catch (ValidationException $e) {
                if ($assetToUpdateId !== null) {
                    unset($currentItems[$assetToUpdateId]);
                    $currentValue->setItems(array_values($currentItems));
                }
                $this->log($dataObject, 'Skipped '.Importer::getLogOutput($assetSource).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');

                continue;
            }
        }

        $value = $currentValue;

        return $value;
    }
}