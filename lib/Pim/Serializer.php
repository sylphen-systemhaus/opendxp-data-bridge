<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Sylphen\DataBridgeBundle\lib\Pim\Item\GenericObjectRelation;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBag;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Db;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\BlockElement;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\Hotspotimage;
use OpenDxp\Model\DataObject\Data\ImageGallery;
use OpenDxp\Model\DataObject\Data\InputQuantityValue;
use OpenDxp\Model\DataObject\Data\Link;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\Data\StructuredTable;
use OpenDxp\Model\DataObject\Data\Video;
use OpenDxp\Model\DataObject\Fieldcollection;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Objectbrick;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\OwnerAwareFieldInterface;
use OpenDxp\Model\DataObject\QuantityValue\Unit;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\Data\MarkerHotspotItem;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Element\Tag;
use OpenDxp\Model\Property;
use OpenDxp\Tool;
use ReflectionClass;
use ReflectionException;
use Throwable;

class Serializer
{
    private $maxLevels;

    private static $cache = [];
    private static $useCache = false;
    private static $trimOutputForBetterPerformance = false;

    public function __construct($maxLevels = null)
    {
        $this->maxLevels = $maxLevels;
    }

    public static function enableCache() {
        self::$useCache = true;
    }

    public static function disableCache()
    {
        self::$useCache = false;
    }

    public static function trimOutputForBetterPerformance($trimOutputForBetterPerformance = true) {
        self::$trimOutputForBetterPerformance = $trimOutputForBetterPerformance;
    }

    public static function getTrimOutputForBetterPerformance($trimOutputForBetterPerformance = true)
    {
        return self::$trimOutputForBetterPerformance;
    }

    public function serialize($object) {
        if (is_scalar($object) || $object === null) {
            return $object;
        }

        if (($object instanceof AbstractModel && !$object instanceof ParameterBag && !$object instanceof BlockElement) || $object instanceof ImageGallery || $object instanceof Video || $object instanceof MarkerHotspotItem || $object instanceof Classificationstore\Key) {
            return $this->getAttributesArrayForObject($object);
        }

        if (is_array($object)) {
            $return = [];

            foreach ($object as $key => $item) {
                $return[$key] = $this->serialize($item);
                if(isset($return[$key]['localizedfields'])) {
                    $return[$key] = array_merge($return[$key]['localizedfields'], array_diff_key($return[$key], array_flip(['localizedfields'])));
                } elseif(self::$trimOutputForBetterPerformance && is_int($key) && $key > 4 && strlen(json_encode($return[$key])) > 100) {
                    if(isset($return[$key - 1]) && is_array($return[$key - 1])) {
                        $return[$key - 1]['comment'] = 'There are '.count($object).' referenced objects in total - all of them will get returned when you execute this dataport';
                    } elseif(is_array($return[$key])) {
                        $return[$key]['comment'] = 'There are '.count($object).' referenced objects in total - all of them will get returned when you execute this dataport';
                    }

                    break;
                }

                $return[$key] = Importer::decodeJsonIfPossible($return[$key]);
            }

            return $return;
        }

        if($object instanceof BlockElement) {
            return $this->serialize($object->getData());
        }

        if($object instanceof OwnerAwareFieldInterface) {
            if (method_exists($object, '_getOwner') && method_exists($object, '_getOwnerFieldname') && $object->_getOwner() && $object->_getOwnerFieldname()) {
                try {
                    $owner = $object->_getOwner();

                    $getterArgs = [];
                    if(method_exists($object, '_getOwnerLanguage') && $object->_getOwnerLanguage()) {
                        $getterArgs[] = $object->_getOwnerLanguage();
                    }

                    if($owner instanceof Objectbrick) {
                        return $this->getAttributesArrayForObject($object);
                    }

                    return $this->serializeField($owner, Importer::getFieldDefinition($owner, $object->_getOwnerFieldname()), $getterArgs);
                } catch (\Throwable $e) {
                }
            } else {
                $reflectionClass = new ReflectionClass($object);
                try {
                    $ownerProperty = $reflectionClass->getProperty('_owner');
                    $ownerProperty->setAccessible(true);

                    $ownerFieldnameProperty = $reflectionClass->getProperty('_fieldname');
                    $ownerFieldnameProperty->setAccessible(true);

                    $owner = $ownerProperty->getValue($object);
                    $ownerFieldname = $ownerFieldnameProperty->getValue($object);
                    if($owner && $ownerFieldname) {
                        if ($owner instanceof Objectbrick) {
                            return $this->getAttributesArrayForObject($object);
                        }

                        return $this->serializeField($owner, Importer::getFieldDefinition($ownerProperty->getValue($object), $ownerFieldname), []);
                    }
                } catch(ReflectionException $e) {
                }
            }
        }

        if(is_bool($object)) {
            return (int)$object;
        }
        if (is_scalar($object)) {
            return (string)$object;
        }

        if ($object instanceof ElementInterface) {
            return '#'.$object->getId().' '.$object->getRealFullPath();
        }

        if (is_array($object) && reset($object) instanceof ElementInterface) {
            return json_encode(array_map(function ($item) {
                    return '#'.$item->getId().' '.$item->getRealFullPath();
                }, $object),
                JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
            );
        }

        if(is_object($object) && method_exists($object, '__toString')) {
            return (string)$object;
        }

        return json_encode($object, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param AbstractModel|ElementInterface|MarkerHotspotItem $object
     * @param bool $trimOutputForBetterPerformance
     * @return array
     * @throws \ReflectionException
     */
    public function getAttributesArrayForObject($object): array
    {
        $attributes = [];

        if($object instanceof ElementInterface) {
            $attributes = [
                [
                    'path' => $object->getPath(),
                    'key' => $object->getKey(),
                    'id' => $object->getId(),
                    'type' => $object->getType(),
                    'query' => get_class($object).':path:"'.$object->getRealFullPath().'"',
                    'tags' => array_map(
                        static function ($tag) {
                            if (is_string($tag)) {
                                return $tag;
                            }
                            return $tag->getNamePath();
                        },
                        (array)Importer::getValue($object, 'tags')
                    ),
                ]
            ];

            $attributes[] = $this->getSerializedObjectValue($object, Importer::getFieldDefinition($object, 'properties'), []);
        }

        if (!self::$useCache) {
            self::clearCache();
        }
        if ($object instanceof Concrete) {
            $attributes[] = ['published' => $object->getPublished(), 'className' => $object->getClassName()];
            foreach ($object->getClass()->getFieldDefinitions() as $fieldDefinition) {
                if($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                    continue;
                }
                $attributes[] = $this->getSerializedObjectValue($object, $fieldDefinition, []);
            }
        } elseif ($object instanceof PageSnippet) {
            $attributes[] = ['published' => $object->getPublished()];

            $editables = method_exists($object, 'getEditables')?$object->getEditables():$object->getElements();
            foreach ($editables as $element) {
                $fieldDefinition = Importer::getFieldDefinition($object, $element->getName());
                $attributes[] = $this->getSerializedObjectValue($object, $fieldDefinition, []);
            }
        } elseif ($object instanceof Asset) {
            $serializedMetaData = [];
            foreach($object->getMetadata() as $metaData) {
                if($metaData['data'] instanceof ElementInterface) {
                    $metaData['data'] = $metaData['data']->getRealFullPath();
                }
                $serializedMetaData[$metaData['name'].($metaData['language'] ? '#'.$metaData['language'] : '')] = $this->serialize($metaData['data']);
            }
            $attributes[] = ['metadata' => $serializedMetaData];
        } elseif ($object instanceof ObjectMetadata) {
            $fieldDefinition = new Data\AdvancedManyToManyObjectRelation();
            $fieldDefinition->setName($object->getFieldname());

            $serialized = $this->getSerializedObjectValue([$object], $fieldDefinition, []);
            $attributes = array_merge($attributes, reset($serialized));
        } elseif ($object instanceof ElementMetadata) {
            $fieldDefinition = new Data\AdvancedManyToManyRelation();
            $fieldDefinition->setName($object->getFieldname());

            $serialized = $this->getSerializedObjectValue([$object], $fieldDefinition, []);
            $attributes = array_merge($attributes, reset($serialized));
        } elseif ($object instanceof Fieldcollection) {
            $fieldDefinition = new Data\Fieldcollections();
            $fieldDefinition->setName($object->getFieldname());

            $serialized = $this->getSerializedObjectValue($object, $fieldDefinition, []);
            $attributes[] = (array)reset($serialized);
        } elseif ($object instanceof Objectbrick) {
            $fieldDefinition = new Data\Objectbricks();
            $fieldDefinition->setName($object->getFieldname());

            $serialized = $this->getSerializedObjectValue($object->getObject(), $fieldDefinition, []);
            $attributes[] = (array)reset($serialized);
        } elseif($object instanceof Fieldcollection\Data\AbstractData) {
            $fieldDefinition = new Data\Fieldcollections();
            $fieldDefinition->setName($object->getFieldname());

            $value = new Fieldcollection();
            $value->setItems([$object]);

            $serialized = $this->getSerializedObjectValue($value, $fieldDefinition, []);
            $attributes[] = [reset($serialized)];
        } elseif ($object instanceof QuantityValue || $object instanceof InputQuantityValue) {
            $unit = $object->getUnit();
            if (!$unit instanceof Unit) {
                $unit = new Unit();
            }

            $attributes[] = [
                'value' => $object->getValue(),
                'unit' => $unit->getAbbreviation()
            ];
        } elseif ($object instanceof ImageGallery) {
            $fieldDefinition = new Data\ImageGallery();
            $serialized = null;
            if(method_exists($object, '_getOwnerFieldname') && $object->_getOwnerFieldname()) {
                $fieldDefinition->setName($object->_getOwnerFieldname());
                $serialized = $this->getSerializedObjectValue($object->_getOwner(), $fieldDefinition, []);
            } else {
                $reflectionClass = new ReflectionClass($object);
                try {
                    $ownerProperty = $reflectionClass->getProperty('_owner');
                    $ownerProperty->setAccessible(true);

                    $ownerFieldnameProperty = $reflectionClass->getProperty('_fieldname');
                    $ownerFieldnameProperty->setAccessible(true);

                    $owner = $ownerProperty->getValue($object);
                    $ownerFieldname = $ownerFieldnameProperty->getValue($object);
                    $fieldDefinition->setName($ownerFieldname);
                    if ($owner && $ownerFieldname) {
                        $serialized = $this->getSerializedObjectValue($owner, $fieldDefinition, []);
                    }
                } catch (ReflectionException $e) {
                }
            }

            if(is_array($serialized)) {
                $attributes[] = (array)reset($serialized);
            } else {
                $attributes[] = [];
            }
        } elseif ($object instanceof Classificationstore) {
            try {
                $fieldDefinition = $object->getObject()->getClass()->getFieldDefinition($object->getFieldname());
            } catch (\Throwable $e) {
                $fieldDefinition = new Data\Classificationstore();
                $fieldDefinition->setName($object->getFieldname());
            }

            $serialized = $this->getSerializedObjectValue($object->getObject(), $fieldDefinition, []);
            $attributes[] = (array)reset($serialized);
        } elseif ($object instanceof Classificationstore\Key) {
            $fieldDefinition = $object->getFieldDefinition();
            $serialized = $this->getSerializedObjectValue($object, $fieldDefinition, []);
            $attributes[] = (array)reset($serialized);
        } elseif ($object instanceof BlockElement) {
            if($object->getData() instanceof Localizedfield) {
                $attributes[] = $this->getAttributesArrayForObject($object->getData());
            } else {
                $attributes[] = [$object->getData()];
            }
        } else {
            $class = new \ReflectionClass($object);
            $setterMethodPrefix = 'set';
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (strpos($method->getName(), $setterMethodPrefix) !== 0) {
                    continue;
                }

                if ($object instanceof Asset && $method->getName() === 'setData') {
                    continue;
                }

                $name = substr($method->getName(), strlen($setterMethodPrefix));
                $fieldDefinition = Importer::getFieldDefinition($object, $name);
                $attributes[] = $this->getSerializedObjectValue($object, $fieldDefinition, []);
            }
        }

        if (!self::$useCache) {
            self::clearCache();
        }

        $attributes = \array_merge(...$attributes);

        return $attributes;
    }

    /**
     * @param Concrete|AbstractData|Fieldcollection\Data\AbstractData $object
     * @param Data                                                    $field
     * @param array                                                   $getterArguments
     */
    public function serializeField($object, Data $field, array $getterArguments = [])
    {
        if (!self::$useCache) {
            self::clearCache();
        }

        try {
            $fieldValue = $this->getSerializedObjectValue($object, $field, $getterArguments);
            return reset($fieldValue);
        } finally {
            if (!self::$useCache) {
                self::clearCache();
            }
        }
    }

    /**
     * @param AbstractModel|Hotspotimage|ElementInterface[]|ObjectMetadata[] $object
     *
     * @return array
     */
    private function getSerializedObjectValue($object, Data $field, array $getterArguments = [], $level = 1)
    {
        $doSerializationEvent = new DoSerializationEvent($object, $field, $getterArguments);
        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($doSerializationEvent, 'pim.serializeField');
        if(!$doSerializationEvent->shallBeSerialized()) {
            return [];
        }

        if($level >= 3 && self::$trimOutputForBetterPerformance) {
            return [
                'comment' => 'In preview $params[\'currentObjectData\'] supports max. nesting of 3 levels. If you need more, please use a virtual field with a data query selector.'
            ];
        }

        if($this->maxLevels !== null && $level > $this->maxLevels) {
            return [
                'comment' => 'Max nesting level reached.'
            ];
        }

        if(is_object($object)) {
            if($object instanceof ElementInterface) {
                $parameterIdentifier = Service::getElementType($object).'#'.$object->getId();
            } elseif($object instanceof Fieldcollection && count($object->getItems()) > 0) {
                $items = $object->getItems();
                if(reset($items)->getObject() instanceof Concrete) {
                    $parameterIdentifier = get_class($object).'-'.$object->getFieldname().'-'.reset($items)->getObject()->getId();
                } else {
                    $parameterIdentifier = md5(serialize($object));
                }
            } elseif ($object instanceof Fieldcollection\Data\AbstractData && $object->getObject() instanceof ElementInterface) {
                $parameterIdentifier = get_class($object).'-'.$object->getFieldname().'-'.$object->getObject()->getId().'-'.$object->getIndex();
            } elseif ($object instanceof AbstractData && $object->getObject() instanceof ElementInterface) {
                $parameterIdentifier = get_class($object).'-'.$object->getFieldname().'-'.$object->getObject()->getId();
            } elseif ($object instanceof Localizedfield && $object->_getOwner() instanceof Fieldcollection\Data\AbstractData && $object->_getOwner()->getObject()) {
                $parameterIdentifier = get_class($object->getObject()).'-'.$object->_getOwner()->getFieldname().'-'.$object->getObject()->getId().'-'.$object->_getOwner()->getIndex().'-localizedfields';
            } elseif($object instanceof Localizedfield) {
                $parameterIdentifier = get_class($object->getObject()).'-localizedfields-'.$object->getObjectId();
            } else {
                try {
                    $parameterIdentifier = md5(serialize($object));
                } catch(\Throwable $e) {
                    $parameterIdentifier = md5(json_encode($object));
                }
            }

            $parameterIdentifier .= '-'.$field->getName().'-'.json_encode($getterArguments);
        } elseif(is_array($object)) {
            $parameterIdentifier = '';
            foreach($object as $item) {
                if($item instanceof ElementInterface) {
                    $parameterIdentifier .= Service::getElementType($item).'#'.$item->getId().';';
                } else {
                    try {
                        $parameterIdentifier .= md5(serialize($item)).';';
                    } catch(\Throwable $e) {
                        $parameterIdentifier .= md5(json_encode($item)).';';
                    }
                }
            }
        } else {
            try {
                $parameterIdentifier = md5(serialize($object));
            } catch(\Throwable $e) {
                $parameterIdentifier = md5(json_encode($object));
            }
        }

        if (\array_key_exists($parameterIdentifier, self::$cache)) {
            return self::$cache[$parameterIdentifier];
        }
        self::$cache[$parameterIdentifier] = [];

        $value = $object;
        try {
            if(self::$trimOutputForBetterPerformance && $field instanceof Data\ManyToManyObjectRelation && $object instanceof Concrete && !$field instanceof GenericObjectRelation) {
                $relations = PimcoreDbRepository::getInstance()->findInSql('SELECT dest_id FROM object_relations_' . $object->getClassId() . ' WHERE src_id = ? AND fieldname = ? ORDER BY `index` LIMIT 4', [$object->getId(), $field->getName()]);
                $value = [];
                foreach ($relations as $relationIndex => $relation) {
                    if($relationIndex > 3) {
                        break;
                    }
                    $o = Concrete::getById($relation['dest_id']);
                    if ($o instanceof Concrete) {
                        $value[] = $o;
                    }
                }
            } elseif (self::$trimOutputForBetterPerformance && $field instanceof Data\AdvancedManyToManyObjectRelation && $object instanceof Concrete) {
                $relations = PimcoreDbRepository::getInstance()->findInSql('SELECT dest_id, ownertype, ownername, position FROM object_relations_'.$object->getClassId().' WHERE src_id = ? AND fieldname = ? ORDER BY `index` LIMIT 4', [$object->getId(), $field->getName()]);
                $value = [];
                foreach ($relations as $relationIndex => $relation) {
                    if ($relationIndex > 3) {
                        break;
                    }
                    $metaData = \OpenDxp::getContainer()->get('opendxp.model.factory')
                        ->build(ObjectMetadata::class, [
                            'fieldname' => $field->getName(),
                            'columns' => $field->getColumnKeys(),
                            'object' => null,
                        ]);

                    $metaData->_setOwner($object);
                    $metaData->_setOwnerFieldname($field->getName());
                    $metaData->setObjectId($object->getId());

                    $ownertype = $relation['ownertype'] ? $relation['ownertype'] : '';
                    $ownername = $relation['ownername'] ? $relation['ownername'] : '';
                    $position = $relation['position'] ? $relation['position'] : '0';
                    $index = $relationIndex + 1;

                    $metaData->load(
                        $object,
                        $relation['dest_id'],
                        $field->getName(),
                        $ownertype,
                        $ownername,
                        $position,
                        $index
                    );
                    $value[] = $metaData;
                }
            } elseif($object instanceof Classificationstore\Key) {
                $value = $object->getValue(...$getterArguments);

                if ($field->getReturnTypeDeclaration() === '?bool') {
                    $value = (bool)$value;
                }
            } elseif ($object instanceof BlockElement) {
                $value = $object->getData();
            } elseif ($object instanceof Localizedfield) {
                $value = call_user_func_array([$object, 'getLocalizedValue'], array_merge([$field->getName()], $getterArguments));
            } else {
                $value = Importer::getValue($object, $field->getName(), $getterArguments);
            }
        } catch(\Throwable $e) {
        }

        $indexName = $field->getName();
        $attributes = [];

        // if anything else needs to be evaluated, please add the definition and how to serialize it
        if ($field instanceof ClassDefinition\Data\Multiselect) {
            $attributes[$indexName] = $value;
        } elseif ($field instanceof Data\Hotspotimage) {
            $fieldDefinition = new Data\Image();
            $fieldDefinition->setName('image');
            $attributes[$indexName] = $this->getSerializedObjectValue($value, $fieldDefinition, $getterArguments, $level);

            if($value instanceof Hotspotimage) {
                $attributes[$indexName]['hotspots'] = $this->serialize($value->getHotspots());
                $attributes[$indexName]['marker'] = $this->serialize($value->getMarker());
            }
        } elseif ($field instanceof ClassDefinition\Data\Image) {
            $attributes[$indexName] = null;
            if ($value instanceof Asset) {
                $attributes[$indexName] = [
                    'filename' => $value->getFilename(),
                    'fullpath' => $value->getRealFullPath(),
                    'query' => 'Asset:path:"'.$value->getRealFullPath().'"',
                    'url' => Helper::getHostUrl().(method_exists($value, 'getFrontendFullPath') ? $value->getFrontendFullPath() : $value->getFullPath())
                ];
            }
        } elseif ($field instanceof ClassDefinition\Data\Datetime || $field instanceof ClassDefinition\Data\Date) {
            $attributes[$indexName] = null;
            if ($value instanceof \DateTimeInterface) {
                $attributes[$indexName] = $value->format('U');
            }
        } elseif ($field instanceof ClassDefinition\Data\Localizedfields) {
            foreach ($field->getFieldDefinitions() as $localizedFieldDefinition) {
                if ($localizedFieldDefinition instanceof Data\ReverseObjectRelation || $localizedFieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                    continue;
                }

                $attributes[] = $this->getSerializedObjectValue($value, $localizedFieldDefinition, [Helper::getRequest()->getLocale()], $level);

                foreach(Tool::getValidLanguages() as $language) {
                    $localizedAttributes = $this->getSerializedObjectValue($value, $localizedFieldDefinition, [$language], $level);

                    foreach($localizedAttributes as $localizedAttributeName => $localizedAttribute) {
                        $attributes[] = [$localizedAttributeName.'#'.$language => $localizedAttribute];
                    }
                }
            }

            $attributes = \array_merge(...$attributes);
        } elseif ($value instanceof Localizedfield) {
            $context = $value->getContext();
            if (isset($context['containerType']) && $context['containerType'] === 'fieldcollection') {
                $containerKey = $context['containerKey'];
                $fcDef = Fieldcollection\Definition::getByKey($containerKey);
                /** @var Data\Localizedfields $container */
                $container = $fcDef->getFieldDefinition('localizedfields');
            } elseif (isset($context['containerType']) && $context['containerType'] === 'objectbrick') {
                $containerKey = $context['containerKey'];
                $brickDef = Definition::getByKey($containerKey);
                /** @var Data\Localizedfields $container */
                $container = $brickDef->getFieldDefinition('localizedfields');
            } elseif (isset($context['containerType']) && $context['containerType'] === 'block') {
                $containerKey = $context['containerKey'];
                $object = $value->getObject();
                /** @var Data\Block $block */
                $block = $object->getClass()->getFieldDefinition($containerKey);
                /** @var Data\Localizedfields $container */
                $container = $block->getFieldDefinition('localizedfields');
            } else {
                $class = $value->getClass();
                /** @var Data\Localizedfields $container */
                $container = $class->getFieldDefinition('localizedfields');
            }

            foreach ($container->getFieldDefinitions() as $localizedFieldDefinition) {
                if ($localizedFieldDefinition instanceof Data\ReverseObjectRelation || $localizedFieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                    continue;
                }
                foreach(Tool::getValidLanguages() as $language) {
                    $localizedValue = $this->getSerializedObjectValue($value, $localizedFieldDefinition, [$language], $level);
                    $attributes[] = [$localizedFieldDefinition->getName().'#'.$language => reset($localizedValue)];
                }
            }
            $attributes = \array_merge(...$attributes);
        } elseif ($field instanceof Data\AdvancedManyToManyObjectRelation) {
            /** @var ObjectMetadata $objectMeta */
            $attributes[$indexName] = [];

            foreach ((array)$value as $index => $objectMeta) {
                if (self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName][$index-1]['comment'] = 'There are ' . count((array)$value) . ' referenced objects in total - all of those will get returned when you execute this dataport';
                    break;
                }

                $referencedObject = null;
                if($objectMeta instanceof ObjectMetadata) {
                    $referencedObject = $objectMeta->getElement();
                }

                if(!$referencedObject instanceof Concrete) {
                    continue;
                }

                $attributes[$indexName][$index]['object'] = [
                    [
                        'path' => $referencedObject->getPath(),
                        'key' => $referencedObject->getKey(),
                        'id'        => $referencedObject->getId(),
                        'className' => $referencedObject->getClassName(),
                        'published' => method_exists($referencedObject, 'getPublished')?$referencedObject->getPublished() : true,
                        'query' => $referencedObject->getClassName().':path:"'.$referencedObject->getRealFullPath().'"',
                        'tags' => array_map(
                            static function ($tag) {
                                if (is_string($tag)) {
                                    return $tag;
                                }
                                return $tag->getNamePath();
                            },
                            (array)Importer::getValue($referencedObject, 'tags')
                        )
                    ],
                ];

                $attributes[$indexName][$index]['object'][] = $this->getSerializedObjectValue($referencedObject, Importer::getFieldDefinition($referencedObject, 'properties'), []);

                try {
                    $classDefinition = $referencedObject->getClass();
                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                            continue;
                        }
                        $attributes[$indexName][$index]['object'][] =
                            $this->getSerializedObjectValue($referencedObject, $fieldDefinition, $getterArguments, $level + 1);
                    }
                } catch(Throwable $e) {
                }

                $attributes[$indexName][$index]['object'] = \array_merge(...$attributes[$indexName][$index]['object']);
                $attributes[$indexName][$index]['meta'] = $objectMeta->getData();
            }
        } elseif ($field instanceof GenericObjectRelation) {
            $attributes[$indexName] = [];
            foreach ((array)$value as $index => $referencedObject) {
                if($field->getClasses() === [['classes' => 'array']]) {
                    if(strtolower($field->getName()) === 'metadata') {
                        $attributes[$indexName][$referencedObject['name'].($referencedObject['language'] ? '#'.$referencedObject['language'] : '')] = $this->serialize($referencedObject['data']);
                    }
                }

                if (!$referencedObject instanceof Property || strpos($referencedObject->getName(), Importer::HASH_PROP_PREFIX) === 0) {
                    continue;
                }

                $attributes[$indexName][$index] = $this->getSerializedObjectValue($referencedObject, Importer::getFieldDefinition($referencedObject, 'data'), []);
            }
        } elseif ($field instanceof Data\ManyToManyObjectRelation) {
            $attributes[$indexName] = [];

            $lastValidIndex = 0;
            foreach ((array)$value as $index => $referencedObject) {
                if(!$referencedObject instanceof Concrete) {
                    // e.g. for array setters of Assets
                    continue;
                }

                if(self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName][$lastValidIndex]['comment'] = 'There are more referenced objects - all of them will get returned when you execute this dataport';
                    break;
                }

                $attributes[$indexName][$index] = [
                    [
                        'path' => $referencedObject->getPath(),
                        'key' => $referencedObject->getKey(),
                        'id' => $referencedObject->getId(),
                        'className' => $referencedObject->getClassName(),
                        'published' => $referencedObject->getPublished(),
                        'query' => $referencedObject->getClassName().':path:"'.$referencedObject->getRealFullPath().'"',
                        'tags' => array_map(
                            static function ($tag) {
                                if (is_string($tag)) {
                                    return $tag;
                                }
                                return $tag->getNamePath();
                            },
                            (array)Importer::getValue($referencedObject, 'tags')
                        )
                    ],
                ];

                $attributes[$indexName][$index][] = $this->getSerializedObjectValue($referencedObject, Importer::getFieldDefinition($referencedObject, 'properties'), []);

                /** @var ClassDefinition $classDefinition */
                $classDefinition = $referencedObject->getClass();
                foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                        continue;
                    }
                    $attributes[$indexName][$index][] =
                        $this->getSerializedObjectValue($referencedObject, $fieldDefinition, $getterArguments, $level + 1);
                }
                $attributes[$indexName][$index] = \array_merge(...$attributes[$indexName][$index]);
                $lastValidIndex = $index;
            }
        } elseif($field instanceof Data\ImageGallery) {
            $attributes[$indexName] = [];

            if(!$value instanceof ImageGallery) {
                $value = new ImageGallery([]);
            }

            foreach ($value->getItems() as $index => $hotspotImage) {
                if (self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName]['comment'] = 'There are more referenced objects - all of them will get returned when you execute this dataport';
                    break;
                }
                $fieldDefinition = new Data\Hotspotimage();
                $fieldDefinition->setName('hotspotimage');
                $attributes[$indexName][$index] = $this->getSerializedObjectValue($hotspotImage, $fieldDefinition, $getterArguments, $level + 1)['hotspotimage'] ?? null;
            }
        } elseif ($field instanceof Data\AdvancedManyToManyRelation) {
            /** @var ElementMetadata $elementMeta */
            $attributes[$indexName] = [];

            if(!is_array($value)) {
                $value = [$value];
            }

            foreach ($value as $index => $elementMeta) {
                if (self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName][$index - 1]['comment'] = 'There are ' . count((array)$value) . ' referenced elements in total - all of them will get returned when you execute this dataport';
                    break;
                }

                $element = null;
                if ($elementMeta instanceof ElementMetadata) {
                    $element = $elementMeta->getElement();
                }

                if (!$element instanceof ElementInterface) {
                    continue;
                }

                $attributes[$indexName][$index]['element'] = [
                    'path' => $element->getPath(),
                    'key' => $element->getKey(),
                    'id' => $element->getId(),
                    'type' => $element->getType(),
                    'query' => get_class($element).':path:"'.$element->getRealFullPath().'"',
                    'tags' => array_map(
                        static function ($tag) {
                            if (is_string($tag)) {
                                return $tag;
                            }
                            return $tag->getNamePath();
                        },
                        (array)Importer::getValue($element, 'tags')
                    )
                ];

                $attributes[$indexName][$index]['element']['properties'] = $this->getSerializedObjectValue($element, Importer::getFieldDefinition($element, 'properties'), [])['properties'];

                if($element instanceof Concrete) {
                    $attributes[$indexName][$index]['element']['published'] = $element->getPublished();

                    $attributes[$indexName][$index]['element'] = [$attributes[$indexName][$index]['element']];
                    $classDefinition = $element->getClass();
                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                            continue;
                        }
                        $attributes[$indexName][$index]['element'][] = $this->getSerializedObjectValue($element, $fieldDefinition, $getterArguments, $level + 1);
                    }
                    $attributes[$indexName][$index]['element'] = array_merge(...$attributes[$indexName][$index]['element']);
                } elseif ($element instanceof PageSnippet) {
                    $attributes[$indexName][$index]['element']['published'] = $element->getPublished();

                    $attributes[$indexName][$index]['element'] = [$attributes[$indexName][$index]['element']];
                    $editables = method_exists($element, 'getEditables') ? $element->getEditables() : $element->getElements();
                    foreach ($editables as $element) {
                        $fieldDefinition = Importer::getFieldDefinition($object, $element->getName());
                        $attributes[$indexName][$index]['element'][] = $this->getSerializedObjectValue($element, $fieldDefinition, [], $level + 1);
                    }
                    $attributes[$indexName][$index]['element'] = array_merge(...$attributes[$indexName][$index]['element']);
                } elseif ($element instanceof Asset) {
                    $attributes[$indexName][$index]['element']['filename'] = $element->getFilename();
                    $attributes[$indexName][$index]['element']['type'] = $element->getType();
                }

                $attributes[$indexName][$index]['meta'] = $elementMeta->getData();
            }
        } elseif ($field instanceof Data\ManyToManyRelation) {
            $attributes[$indexName] = [];
            foreach ((array)$value as $index => $element) {
                if(!$element instanceof ElementInterface) {
                    continue;
                }
                if (self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName][$index - 1]['comment'] = 'There are ' . count((array)$value) . ' referenced elements in total - all of them will get returned when you execute this dataport';
                    break;
                }

                $attributes[$indexName][$index] = [
                    'path' => $element->getPath(),
                    'key' => $element->getKey(),
                    'id' => $element->getId(),
                    'type' => $element->getType(),
                    'query' => get_class($element).':path:"'.$element->getRealFullPath().'"',
                    'tags' => array_map(
                        static function ($tag) {
                            if (is_string($tag)) {
                                return $tag;
                            }
                            return $tag->getNamePath();
                        },
                        (array)Importer::getValue($element, 'tags')
                    )
                ];

                $attributes[$indexName][$index]['properties'] = $this->getSerializedObjectValue($element, Importer::getFieldDefinition($element, 'properties'), [])['properties'];

                if ($element instanceof Concrete) {
                    $attributes[$indexName][$index]['published'] = $element->getPublished();

                    $attributes[$indexName][$index] = [$attributes[$indexName][$index]];
                    $classDefinition = $element->getClass();
                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                            continue;
                        }
                        $attributes[$indexName][$index][] = $this->getSerializedObjectValue($element, $fieldDefinition, $getterArguments, $level + 1);
                    }
                    $attributes[$indexName][$index] = array_merge(...$attributes[$indexName][$index]);
                } elseif ($element instanceof PageSnippet) {
                    $attributes[$indexName][$index]['published'] = ['published' => $element->getPublished()];

                    $attributes[$indexName][$index] = [$attributes[$indexName][$index]];
                    $editables = method_exists($element, 'getEditables') ? $element->getEditables() : $element->getElements();
                    foreach ($editables as $editable) {
                        try {
                            $fieldDefinition = Importer::getFieldDefinition($object, $editable->getName());
                            $attributes[$indexName][$index][] = $this->getSerializedObjectValue($editable, $fieldDefinition, [], $level + 1);
                        } catch(Throwable $e) {
                        }
                    }
                    $attributes[$indexName][$index] = array_merge(...$attributes[$indexName][$index]);
                } elseif ($element instanceof Asset) {
                    $attributes[$indexName][$index]['element']['filename'] = $element->getFilename();
                    $attributes[$indexName][$index]['element']['type'] = $element->getType();
                }
            }
        } elseif ($field instanceof Data\ManyToOneRelation) {
            $attributes[$indexName] = null;
            $element = $value;

            if($value instanceof ElementInterface) {
                $attributes[$indexName] = [
                    'id' => $element->getId(),
                    'type' => $element->getType(),
                    'key' => $element->getKey(),
                    'path' => $element->getPath(),
                    'query' => get_class($element).':path:"'.$element->getRealFullPath().'"',
                    'tags' => array_map(
                        static function ($tag) {
                            if (is_string($tag)) {
                                return $tag;
                            }
                            return $tag->getNamePath();
                        },
                        (array)Importer::getValue($element, 'tags')
                    )
                ];

                $attributes[$indexName]['properties'] = $this->getSerializedObjectValue($element, Importer::getFieldDefinition($element, 'properties'), [])['properties'];

                if ($element instanceof Concrete) {
                    $attributes[$indexName]['published'] = $element->getPublished();

                    $attributes[$indexName] = [$attributes[$indexName]];
                    $classDefinition = $element->getClass();
                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                            continue;
                        }
                        $attributes[$indexName][] = $this->getSerializedObjectValue($element, $fieldDefinition, $getterArguments, $level + 1);
                    }
                    $attributes[$indexName] = array_merge(...$attributes[$indexName]);
                } elseif ($element instanceof PageSnippet) {
                    $attributes[$indexName]['published'] = ['published' => $element->getPublished()];

                    $attributes[$indexName] = [$attributes[$indexName]];
                    $editables = method_exists($element, 'getEditables') ? $element->getEditables() : $element->getElements();
                    foreach ($editables as $editable) {
                        try {
                            $fieldDefinition = Importer::getFieldDefinition($object, $editable->getName());
                            $attributes[$indexName][] = $this->getSerializedObjectValue($editable, $fieldDefinition, [], $level + 1);
                        } catch (Throwable $e) {
                        }
                    }
                    $attributes[$indexName] = array_merge(...$attributes[$indexName]);
                } elseif ($element instanceof Asset) {
                    $attributes[$indexName]['element']['filename'] = $element->getFilename();
                    $attributes[$indexName]['element']['type'] = $element->getType();
                }
            }
        } elseif ($field instanceof Data\Fieldcollections) {
            $attributes[$indexName] = [];

            if (!$value instanceof Fieldcollection) {
                $value = new Fieldcollection();
            }

            /** @var Fieldcollection\Data\AbstractData $fieldCollectionItem */
            foreach ($value->getItems() as $index => $fieldCollectionItem) {
                $fieldCollectionDefinition = $fieldCollectionItem->getDefinition();
                $attributes[$indexName][$fieldCollectionDefinition->getKey()][$index] = [[]];
                foreach ($fieldCollectionDefinition->getFieldDefinitions() as $fieldDefinition) {
                    if (self::$trimOutputForBetterPerformance && $index > 3) {
                        $attributes[$indexName][$fieldCollectionDefinition->getKey()][$index - 1]['comment'] = 'There are '.count($value->getItems()).' items in total - all of them will get returned when you execute this dataport';

                        unset($attributes[$indexName][$fieldCollectionDefinition->getKey()][$index]);
                        break 2;
                    }
                    $attributes[$indexName][$fieldCollectionDefinition->getKey()][$index][] = $this->getSerializedObjectValue($fieldCollectionItem, $fieldDefinition, $getterArguments, $level + 1);
                }
                $attributes[$indexName][$fieldCollectionDefinition->getKey()][$index] = \array_merge(...$attributes[$indexName][$fieldCollectionDefinition->getKey()][$index]);
            }
        } elseif ($field instanceof Data\Block) {
            $attributes[$indexName] = [];

            if (!is_array($value)) {
                $value = [];
            }

            foreach ($value as $index => $blockItem) {
                if (self::$trimOutputForBetterPerformance && $index > 3) {
                    $attributes[$indexName][$index - 1]['comment'] = 'There are '.count((array)$value).' items in total - all of them will get returned when you execute this dataport';
                    break;
                }

                $attributes[$indexName][$index] = [[]];
                foreach ($field->getFieldDefinitions() as $fieldDefinition) {
                    $attributes[$indexName][$index][] = $this->getSerializedObjectValue($blockItem[$fieldDefinition->getName()] ?? null, $fieldDefinition, $getterArguments, $level + 1);
                }
                $attributes[$indexName][$index] = \array_merge(...$attributes[$indexName][$index]);
            }
        } elseif ($field instanceof Data\Objectbricks && $value instanceof Objectbrick) {
            $attributes[$indexName] = [];
            /** @var Objectbrick $value */
            foreach ($value->getBrickGetters() as $brickGetter) {
                $brickItem = $value->$brickGetter();

                if ($brickItem instanceof AbstractData) {
                    /** @var Definition $brickDefinition */
                    $brickDefinition = $brickItem->getDefinition();
                    $attributes[$indexName][$brickItem->getType()] = [[]];
                    foreach ($brickDefinition->getFieldDefinitions() as $fieldDefinition) {
                        $attributes[$indexName][$brickItem->getType()][] = $this->getSerializedObjectValue($brickItem, $fieldDefinition, $getterArguments, $level);
                    }
                    $attributes[$indexName][$brickItem->getType()] = \array_merge(...$attributes[$indexName][$brickItem->getType()]);
                }
            }
        } elseif ($field instanceof Data\Classificationstore && $value instanceof Classificationstore) {
            try {
                $activeGroups = $value->getActiveGroups();
                $groups = [];
                foreach (array_keys($activeGroups) as $groupId) {
                    $groupConfig = Classificationstore\GroupConfig::getById($groupId);
                    if($groupConfig instanceof Classificationstore\GroupConfig) {
                        $groups[] = new Classificationstore\Group($value, $groupConfig);
                    }
                }
            } catch(\Throwable $e) {
                $groups = [];
            }

            $attributes[$indexName] = [];

            /** @var Classificationstore\Group $group */
            foreach ($groups as $group) {
                $groupName = $group->getConfiguration()->getName();
                $attributes[$indexName][$groupName] = [[]];
                foreach ($group->getKeys() as $key) {
                    $fieldDefinition = $key->getFieldDefinition();
                    if($field->isLocalized()) {
                        if(property_exists($value, 'locale')) {
                            $getterArguments = [$value->locale];
                        } else {
                            $getterArguments = [Helper::getRequest()->getLocale()];
                        }
                    }
                    $attributes[$indexName][$groupName][] = $this->getSerializedObjectValue($key, $fieldDefinition, $getterArguments, $level);

                    if ($field->isLocalized()) {
                        $languages = Tool::getValidLanguages();
                        array_unshift($languages, 'default');
                        foreach ($languages as $language) {
                            $localizedValue = $this->getSerializedObjectValue($key, $fieldDefinition, [$language], $level);
                            $attributes[$indexName][$groupName][] = [$fieldDefinition->getName().'#'.$language => reset($localizedValue)];
                        }
                    }
                }
                $attributes[$indexName][$groupName] = \array_merge(...$attributes[$indexName][$groupName]);
            }
        } elseif ($field instanceof Data\QuantityValue) {
            if(!$value instanceof QuantityValue && !$value instanceof InputQuantityValue) {
                $value = new QuantityValue();
            }

            $unit = $value->getUnit();
            if(!$unit instanceof Unit) {
                $unit = new Unit();
            }

            /** @var QuantityValue $value */
            $attributes[$indexName] = [
                'value' => $value->getValue(),
                'unit' => $unit->getAbbreviation()
            ];
        } elseif ($field instanceof Data\Link) {
            if (!$value instanceof Link) {
                $value = new Link();
            }

            /** @var Link $value */
            $attributes[$indexName] = ['text' => $value->getText()];
            try {
                $attributes[$indexName]['href'] = $value->getHref();
            } catch(\Throwable $e) {
                $attributes[$indexName]['href'] = null;
            }
        } elseif ($field instanceof Data\Video) {
            if (!$value instanceof Video) {
                $value = new Video();
                $value->setType('asset');
                $value->setData(0);
            }

            /** @var Video $value */
            $attributes[$indexName] = [
                'type' => $value->getType(),
                'data' => $value->getData()
            ];
        } elseif ($field instanceof Data\StructuredTable) {
            if (!$value instanceof StructuredTable) {
                $value = new StructuredTable();
            }

            /** @var StructuredTable $value */
            $attributes[$indexName] = $value->getData();
        } elseif ($field instanceof Data\Table) {
            if (!is_array($value)) {
                $value = [];
            } else {
                $value = array_filter($value);
            }

            /** @var StructuredTable $value */
            $attributes[$indexName] = $value;
        } elseif (\is_bool($value)) {
            $attributes[$indexName] = (int)$value;
        } elseif(\is_scalar($value) || $value === null) {
            $attributes[$indexName] = $value;
        } elseif(\is_array($value)) {
            foreach($value as $key => $item) {
                $attributes[$indexName][$key] = $item;
            }
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $attributes[$indexName] = (string)$value;
        }

        self::$cache[$parameterIdentifier] = $attributes;

        return $attributes;
    }

    public static function clearCache() {
        self::$cache = [];
    }
}
