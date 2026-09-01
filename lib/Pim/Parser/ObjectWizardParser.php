<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\AdvancedManyToManyObjectRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ManyToManyObjectRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ManyToOneRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyObjectRelation;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Folder;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ObjectWizardParser implements Parser
{
    use IteratableParser;

    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var Serializer */
    private static $serializer;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        self::$serializer = new Serializer();
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        if($this->current !== null) {
            $this->current = null;
            return null;
        }
        $item = [
            '__updated' => time()
        ];

        foreach($this->config['fields'] as $fieldNo => $field) {
            $method = 'get'.str_replace(' ', '_', $field['definition']['name']);

            if($field['definition']['name'] === '__request') {
                $item[$fieldNo] = json_encode(array_diff_key(Helper::getRequest()->attributes->all(), array_flip(['_opendxp_context', '_opendxp_frontend_request'])), \JSON_UNESCAPED_SLASHES);
                continue;
            }

            try {
                $item[$fieldNo] = $this->config['parameters']->$method() ?? null;

                if (is_string($item[$fieldNo]) && !is_numeric($item[$fieldNo])) {
                    $decodedValue = json_decode($item[$fieldNo], true);
                    if (json_last_error() === \JSON_ERROR_NONE) {
                        $item[$fieldNo] = $decodedValue;
                    }
                }
                if(isset($item[$fieldNo]['type'])) {
                    $element = null;
                    if (isset($item[$fieldNo]['id'])) {
                        $element = Service::getElementById($item[$fieldNo]['type'], $item[$fieldNo]['id']);
                    } elseif(isset($item[$fieldNo]['fullpath'])) {
                        $element = Service::getElementByPath($item[$fieldNo]['type'], $item[$fieldNo]['fullpath']);
                    } elseif (isset($item[$fieldNo]['path'])) {
                        $element = Service::getElementByPath($item[$fieldNo]['type'], $item[$fieldNo]['path']);
                    }

                    self::$serializer::trimOutputForBetterPerformance(true);
                    $item[$fieldNo] = self::$serializer->serialize($element);
                    self::$serializer::trimOutputForBetterPerformance(false);
                    if ($element instanceof ElementInterface) {
                        $item[$fieldNo]['fullpath'] = $element->getRealFullPath();
                    }
                } elseif(is_array($item[$fieldNo]) && isset($item[$fieldNo][0]['type'])) {
                    foreach($item[$fieldNo] as &$elementData) {
                        $element = null;
                        if (isset($elementData['id'])) {
                            $element = Service::getElementById($elementData['type'], $elementData['id']);
                        } elseif (isset($elementData['fullpath'])) {
                            $element = Service::getElementByPath($elementData['type'], $elementData['fullpath']);
                        } elseif (isset($item[$fieldNo]['path'])) {
                            $element = Service::getElementByPath($elementData['type'], $elementData['path']);
                        }

                        self::$serializer::trimOutputForBetterPerformance(true);
                        unset($elementData['inheritedFields'], $elementData['metadata'], $elementData['localizedfields'], $elementData['rowId'], $elementData['type'], $elementData['subtype'], $elementData['query'], $elementData['creationDate'], $elementData['modificationDate'], $elementData['idPath'], $elementData['permissions'], $elementData['locked'], $elementData['classname']);
                        $elementData = array_merge($elementData, self::$serializer->serialize($element));
                        self::$serializer::trimOutputForBetterPerformance(false);
                        if ($element instanceof ElementInterface) {
                            $elementData['fullpath'] = $element->getRealFullPath();
                        }
                    }
                    unset($elementData);
                }

                if (!\is_scalar($item[$fieldNo]) && $item[$fieldNo] !== null) {
                    $item[$fieldNo] = json_encode($item[$fieldNo], \JSON_UNESCAPED_SLASHES);
                }
            } catch(\Exception $e) {
                $item[$fieldNo] = null;
            }
        }

        $this->current = $item;
        return $this->current;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return 1;
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return '';
    }

    /**
     * @param ElementInterface $object
     * @param bool $addDataportCondition
     * @param bool $tryToSkip
     * @return array
     */
    public function getFileConditionFromObject(ElementInterface $object, $addDataportCondition = true, $tryToSkip = true)
    {
        $parameters = [];
        foreach ($this->config['fields'] as $field) {
            $fieldDefinition = \OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($field['definition'], $field['definition']['fieldtype']);

            $method = 'get'.str_replace(' ', '_', $fieldDefinition->getName());
            if (!method_exists($object, $method)) {
                continue;
            }

            $parameters[$fieldDefinition->getName()] = $object->$method();
        }

        return $this->getDefaultValues($parameters);
    }

    public function getDefaultValues($parameters)
    {
        /** @var ImporterInterface $importer */
        $importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);

        try {
            foreach ($this->config['fields'] as $field) {
                $fieldDefinition = \OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($field['definition'], $field['definition']['fieldtype']);

                switch (true) {
                    case $fieldDefinition instanceof ClassDefinition\Data\ManyToOneRelation:
                    case $fieldDefinition->getFieldtype() === 'manyToOneRelation':
                        if (!isset($parameters[$field['definition']['name']])) {
                            $parameters[$field['definition']['name']] = ['classes' => array_column($fieldDefinition->getClasses(), 'classes')]; // parameters will be filled in the frontend via js
                            break;
                        }

                        $object = null;

                        $fieldMapper = new ManyToOneRelationMapper($importer);
                        $object = $fieldMapper->map(['fieldName' => $fieldDefinition->getName()], $parameters[$field['definition']['name']], null, $fieldDefinition);

                        if ($object instanceof ElementInterface) {
                            $item = [
                                'id' => $object->getId(),
                                'type' => $object->getType(),
                                'key' => $object->getKey(),
                                'path' => $object->getRealFullPath(),
                                'fullpath' => $object->getRealFullPath(),
                            ];

                            $parameters[$field['definition']['name']] = $item;
                        } else {
                            unset($parameters[$field['definition']['name']]);
                        }
                        break;

                    case $fieldDefinition instanceof AdvancedManyToManyObjectRelation:
                    case $fieldDefinition->getFieldtype() === 'advancedManyToManyObjectRelation':
                        if (!isset($parameters[$field['definition']['name']])) {
                            if($fieldDefinition->getAllowedClassId()) {
                                $parameters[$field['definition']['name']] = ['classes' => [$fieldDefinition->getAllowedClassId()]]; // parameters will be filled in the frontend via js
                            }
                            break;
                        }

                        $fieldMapper = new AdvancedManyToManyObjectRelationMapper($importer);
                        $objects = $fieldMapper->map(['fieldName' => $fieldDefinition->getName()], $parameters[$field['definition']['name']], null, $fieldDefinition);

                        $parameters[$field['definition']['name']] = [];
                        foreach ($objects as $objectMeta) {
                            $object = $objectMeta->getObject();
                            $item = [
                                'id' => $object->getId(),
                                'type' => $object->getType(),
                                'key' => $object->getKey(),
                                'path' => $object->getPath(),
                                'fullpath' => $object->getRealFullPath(),
                            ];
                            $item = array_merge($item, $objectMeta->getData());

                            $visibleFields = $fieldDefinition->getVisibleFields();
                            if (is_string($visibleFields)) {
                                $visibleFields = explode(',', $visibleFields);
                            }

                            foreach ($visibleFields as $visibleFieldName) {
                                $method = 'get'.str_replace(' ', '_', $visibleFieldName);
                                if (!method_exists($object, $method)) {
                                    continue;
                                }
                                $item[$visibleFieldName] = $object->$method();
                            }

                            $parameters[$field['definition']['name']][] = $item;
                        }

                        break;

                    case $fieldDefinition instanceof ClassDefinition\Data\ManyToManyObjectRelation:
                    case $fieldDefinition->getFieldtype() === 'manyToManyObjectRelation':
                    case $fieldDefinition->getFieldtype() === 'objects':
                        if (!isset($parameters[$field['definition']['name']])) {
                            $parameters[$field['definition']['name']] = ['classes' => array_column($fieldDefinition->getClasses(), 'classes')]; // parameters will be filled in the frontend via js
                            break;
                        }

                        $fieldMapper = new ManyToManyObjectRelationMapper($importer);
                        $objects = $fieldMapper->map(['fieldName' => $fieldDefinition->getName()], $parameters[$field['definition']['name']], null, $fieldDefinition);

                        $parameters[$field['definition']['name']] = [];
                        foreach ($objects as $object) {
                            $item = [
                                'id' => $object->getId(),
                                'type' => $object->getType(),
                                'key' => $object->getKey(),
                                'path' => $object->getPath(),
                                'fullpath' => $object->getRealFullPath(),
                            ];

                            $visibleFields = $fieldDefinition->getVisibleFields();
                            if(is_string($visibleFields)) {
                                $visibleFields = explode(',', $visibleFields);
                            }

                            foreach($visibleFields as $visibleFieldName) {
                                $method = 'get'.str_replace(' ', '_', $visibleFieldName);
                                if (!method_exists($object, $method)) {
                                    continue;
                                }
                                $item[$visibleFieldName] = $object->$method();
                            }

                            $parameters[$field['definition']['name']][] = $item;
                        }

                        break;
                    case $fieldDefinition instanceof ClassDefinition\Data\Multiselect:
                        if (is_array($parameters[$field['definition']['name']] ?? null)) {
                            $parameters[$field['definition']['name']] = implode(',', $parameters[$field['definition']['name']]);
                        }
                        break;
                }
            }
        } catch(\Throwable $e) {
            die($e);
        }

        return $parameters;
    }

    /**
     * Sets a logger.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setSourceFile($file)
    {
    }
}