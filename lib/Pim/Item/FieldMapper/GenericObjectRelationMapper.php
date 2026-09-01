<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Item\GenericObjectRelation;
use Exception;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Tag;
use OpenDxp\Model\Property;
use OpenDxp\Tool;
use Traversable;

class GenericObjectRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof GenericObjectRelation || $fieldDefinition->getFieldtype() === 'genericObjectRelation';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        /** @var GenericObjectRelation $fieldDefinition */
        if ((!is_array($value) && !$value instanceof Traversable) || (count($value) > 0 && !isset($value[0]) && $fieldDefinition->getClasses() != [['classes' => 'array']] && $fieldDefinition->getClasses() != [['classes' => Property::class]])) {
            $value = [$value];
        }

        $objects = [];
        if (empty($mapping['format']['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $objects = array_map(
                static function ($item) use ($fieldDefinition) {
                    if (!is_object($item) && $fieldDefinition->getClasses() != [['classes' => 'array']]) {
                        return (object)$item;
                    }

                    return $item;
                },
                (array)(is_callable($currentValue) ? $currentValue() : $currentValue)
            );
        } else {
            $this->setPurged($mapping, $dataObject);
        }

        if (strtolower($fieldDefinition->getName()) === 'tags') {
            if (!empty($mapping['format']['purgeitems'])) {
                $this->importer->setCachedItem($dataObject, ['save' => true]);
            }

            foreach (array_filter($value) as $tagPath) {
                $parentTagId = 0;

                $tag = null;
                foreach (array_filter(explode('/', $tagPath)) as $tagItem) {
                    $runtimeCacheKey = 'tag-'.$tagItem.'-'.$parentTagId;
                    try {
                        $tag = RuntimeCache::get($runtimeCacheKey);
                    } catch (\Exception $e) {
                        $tags = new Tag\Listing();
                        $tags->addConditionParam('name = ?', $tagItem);
                        $tags->setLimit(1);

                        if (empty($parentTagId)) {
                            $tags->addConditionParam('(parentId = 0 OR parentId IS NULL)');
                        } else {
                            $tags->addConditionParam('parentId = ?', $parentTagId);
                        }

                        $tags = $tags->load();

                        if (count($tags) === 0) {
                            $tag = new Tag();
                            $tag->setName($tagItem);
                            $tag->setParentId($parentTagId);
                            $tag->save();
                        } else {
                            $tag = $tags[0];
                        }

                        RuntimeCache::save($tag, $runtimeCacheKey);
                    }

                    $parentTagId = $tag->getId();
                }

                if ($tag instanceof Tag) {
                    $isNew = true;
                    foreach ($objects as $object) {
                        if ($object->getId() === $tag->getId()) {
                            $isNew = false;
                            break;
                        }
                    }

                    if ($isNew) {
                        $objects[] = $tag;
                        $this->importer->setCachedItem($dataObject, ['save' => true]);
                    }
                }
            }
        } elseif (strtolower($fieldDefinition->getName()) === 'properties') {
            foreach ($value as $propertyKey => $propertyData) {
                $property = new Property();
                if (is_scalar($propertyData)) {
                    $propertyData = ['name' => $propertyKey, 'data' => $this->importer->getObjectByIdentifier($propertyData)];
                }

                if (!isset($propertyData['cid']) && $dataObject->getId()) {
                    $propertyData['cid'] = $dataObject->getId();
                }

                $propertyData['ctype'] = Service::getElementType($dataObject);

                $propertyData['type'] = 'text';
                if ($propertyData['data'] instanceof PageSnippet) {
                    $propertyData['type'] = 'document';
                } elseif ($propertyData['data'] instanceof Asset) {
                    $propertyData['type'] = 'asset';
                } elseif ($propertyData['data'] instanceof Concrete) {
                    $propertyData['type'] = 'object';
                } elseif (is_bool($propertyData['data'])) {
                    $propertyData['type'] = 'checkbox';
                }

                $property->setValues($propertyData);

                $existingIndex = null;
                foreach ($objects as $index => $existingProperty) {
                    if ($existingProperty->getName() === $property->getName()) {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $objects[$existingIndex] = $property;
                } else {
                    $objects[$property->getName()] = $property;
                }
            }
        } elseif (strtolower($fieldDefinition->getName()) === 'metadata') {
            foreach ($value as $key => $metaValue) {
                if (is_array($metaValue)) {
                    $isLanguageDependentKeyValue = true;
                    foreach (array_keys($metaValue) as $language) {
                        if (!Tool::isValidLanguage($language)) {
                            $isLanguageDependentKeyValue = false;
                            break;
                        }
                    }

                    if (!$isLanguageDependentKeyValue) {
                        $updateIndex = null;
                        foreach ($objects as $existingMetaIndex => $existingMeta) {
                            if (strtolower($existingMeta['name']) === strtolower($metaValue['name']) && (empty($metaValue['language']) || strtolower($metaValue['language']) === strtolower($existingMeta['language'] ?? ''))) {
                                $updateIndex = $existingMetaIndex;
                                break;
                            }
                        }

                        if ($updateIndex === null) {
                            $objects[] = $metaValue;
                        } else {
                            $objects[$updateIndex] = $metaValue;
                        }
                    } else {
                        foreach ($metaValue as $language => $localizedValue) {
                            if (!Tool::isValidLanguage($language)) {
                                $this->log($dataObject, 'It is not possible to set asset metadata field "'.$key.'" for language "'.$language.'" as the language does not exist. Please check System settings > Localization & Internationalization', 'error');
                            }

                            try {
                                $metaObject = $this->importer->getObjectByIdentifier($localizedValue);
                                if ($metaObject !== $localizedValue) {
                                    $localizedValue = $metaObject;
                                }
                            } catch (Exception $e) {
                            }

                            $newMeta = [
                                'name' => $key,
                                'type' => $localizedValue instanceof ElementInterface ? \OpenDxp\Model\Element\Service::getElementType($localizedValue) : 'input',
                                'data' => $localizedValue,
                                'language' => $language
                            ];

                            $updateIndex = null;
                            foreach ($objects as $existingMetaIndex => $existingMeta) {
                                if ($existingMeta['name'] === $key && $existingMeta['language'] === $language) {
                                    $updateIndex = $existingMetaIndex;
                                    break;
                                }
                            }

                            if ($updateIndex === null) {
                                $objects[] = $newMeta;
                            } else {
                                $objects[$updateIndex] = $newMeta;
                            }
                        }
                    }
                } else {
                    try {
                        $metaObject = $this->importer->getObjectByIdentifier($metaValue);
                        if ($metaObject !== $metaValue) {
                            $metaValue = $metaObject;
                        }
                    } catch (Exception $e) {
                    }

                    $nameParts = explode('#', $key);

                    $newMeta = [
                        'name' => $nameParts[0],
                        'type' => $metaValue instanceof ElementInterface ? \OpenDxp\Model\Element\Service::getElementType($metaValue) : 'input',
                        'data' => $metaValue,
                        'language' => $nameParts[1] ?? null
                    ];

                    $updateIndex = null;
                    foreach ($objects as $existingMetaIndex => $existingMeta) {
                        if (strtolower($existingMeta['name']) === strtolower($newMeta['name']) && (empty($existingMeta['language']) || strtolower($existingMeta['language']) === strtolower($newMeta['language'] ?? ''))) {
                            $updateIndex = $existingMetaIndex;
                            break;
                        }
                    }

                    if ($updateIndex === null) {
                        $objects[] = $newMeta;
                    } else {
                        $objects[$updateIndex] = $newMeta;
                    }
                }
            }
        } else {
            $value = array_map(static function ($item) use ($fieldDefinition) {
                if (!is_object($item) && $fieldDefinition->getClasses() != [['classes' => 'array']]) {
                    return (object)$item;
                }

                return $item;
            }, $value);

            $objects = array_merge($objects, $value);
        }

        $value = [];
        foreach ($objects as $key => $object) {
            // this array_filter is an in_array() for objects
            if ((is_numeric($key) && count(
                        array_filter($value, static function ($existing) use ($object) {
                            if (is_object($existing) && is_object($object) && method_exists($existing, '__toString') && method_exists($object, '__toString')) {
                                return (string)$existing === (string)$object;
                            }
                            return $existing == $object;
                        })
                    ) === 0) || (!is_numeric($key) && !array_key_exists($key, $value))) {
                $value[$key] = $object;
            }
        }

        return $value;
    }
}