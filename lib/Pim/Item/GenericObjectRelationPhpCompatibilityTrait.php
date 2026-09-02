<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use OpenDxp\Logger;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ManyToManyObjectRelation;
use OpenDxp\Model\DataObject\OwnerAwareFieldInterface;
use OpenDxp\Model\Element\Tag;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Model\Property;

trait GenericObjectRelationPhpCompatibilityTrait
{
    protected function allowObjectRelation($object): bool
    {
        if (!$this->getObjectsAllowed()) {
            return false;
        }

        $allowedClasses = $this->getClasses();
        if (count($allowedClasses) > 0) {
            foreach ($allowedClasses as $c) {
                if ($c['classes'] === 'array' || $object instanceof $c['classes']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function checkValidity($data, $omitMandatoryCheck = false, $params = []): void
    {
        if (is_array($data)) {
            foreach ($data as $o) {
                if (empty($o)) {
                    continue;
                }

                $allowClass = $this->allowObjectRelation($o);
                if (!$allowClass) {
                    throw new ValidationException('Invalid generic object relation to object in field '.$this->getName().' , tried to assign object of class '.\get_class($o));
                }
            }
        }
    }

    public function getVersionPreview($data, $object = null, $params = []): string
    {
        if (is_array($data)) {
            $paths = [];

            $usesAssocKeys = false;
            foreach ($data as $key => $o) {
                if (!$usesAssocKeys && !is_numeric($key)) {
                    $usesAssocKeys = true;
                }

                if (is_object($o) && \method_exists($o, '__toString')) {
                    $paths[$key] = (string)$o;
                } elseif ($o instanceof Tag) {
                    $paths[$key] = rtrim($o->getNamePath(), '/');
                } elseif ($o instanceof Property) {
                    Serializer::trimOutputForBetterPerformance(false);
                    $serializer = Importer::getSerializer();
                    $paths[$key] = \json_encode(['name' => $o->getName(), 'data' => $serializer->serialize($o->getData())], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif(is_array($o)) {
                    if(array_key_exists('name', $o) && array_key_exists('data', $o) && array_key_exists('type', $o)) {
                        // Asset metadata
                        $o['language'] = $o['language'] ?? '';

                        if(empty($o['data'])) {
                            $o['data'] = null;
                            unset($data[$key]);
                            continue;
                        }

                        $o['data'] = Importer::getSerializer()->serialize($o['data']);
                    }

                    ksort($o);
                    $paths[$key] = \json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $paths[$key] = \json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if ($usesAssocKeys) {
                $paths = array_map(static function ($path, $key) {
                    return $key.': '.$path;
                }, $paths, array_keys($paths));
            }

            return implode('<br />', $paths);
        }

        return '';
    }

    public function isEqual($array1, $array2): bool
    {
        if ($this->getClasses() === [['classes' => 'array']]) {
            return $this->getVersionPreview($array1) == $this->getVersionPreview($array2);
        }

        if (!method_exists($this->getClasses()[0]['classes'], 'getId') || !method_exists($this->getClasses()[0]['classes'], 'getType')) {
            if (is_array($array1) && reset($array1) instanceof AbstractModel) {
                foreach ($array1 as $index => $item1) {
                    $item2 = $array2[$index] ?? null;
                    if (!$item2) {
                        return false;
                    }

                    $objectVars1 = $item1->getObjectVars();
                    $objectVars2 = $item2->getObjectVars();

                    if (method_exists($item1, '__sleep')) {
                        $compareFields = $item1->__sleep();
                    } else {
                        $compareFields = array_keys($objectVars1);
                    }

                    if ($item1 instanceof Tag) {
                        $compareFields = ['name', 'idPath'];
                    }

                    $compareFields = array_diff($compareFields, ['dao', '_owner']);

                    foreach ($compareFields as $compareField) {
                        if ($objectVars1[$compareField] != ($objectVars2[$compareField] ?? null)) {
                            return false;
                        }
                    }
                }

                return true;
            }

            return $array1 == $array2;
        }

        return parent::isEqual($array1, $array2);
    }
}