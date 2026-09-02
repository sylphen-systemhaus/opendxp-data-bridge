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
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Iterator;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ValidationException;

class ManyToOneRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\ManyToOneRelation ||
            $fieldDefinition->getFieldtype() === 'href' ||
            $fieldDefinition->getFieldtype() === 'manyToOneRelation';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($value instanceof Iterator) {
            $value->rewind();
            $value = $value->current();
        } elseif (is_array($value) && isset($value[0])) {
            $value = $value[0];
        }

        if (is_array($value)) {
            if(isset($value['object'], $value['meta'])) {
                $value = $value['object'];
            }

            if (isset($value['query'])) {
                $value = $value['query'];
            } elseif (isset($value['id'])) {
                $value = $value['id'];
                if ($fieldDefinition->getObjectsAllowed()) {
                    $value = Concrete::getById($value);
                } elseif ($fieldDefinition->getAssetsAllowed()) {
                    $value = Asset::getById($value);
                } elseif ($fieldDefinition->getDocumentsAllowed()) {
                    $value = PageSnippet::getById($value);
                }
            } elseif (isset($value['path']) || isset($value['fullpath'])) {
                $value = $value['path'] ?? $value['fullpath'];
                if ($fieldDefinition->getObjectsAllowed()) {
                    $value = Concrete::getByPath($value);
                } elseif ($fieldDefinition->getAssetsAllowed()) {
                    $value = Asset::getByPath($value);
                } elseif ($fieldDefinition->getDocumentsAllowed()) {
                    $value = PageSnippet::getByPath($value);
                }
            }
        } elseif ($value === null) {
            return $value;
        } elseif(is_numeric($value)) {
            if ($fieldDefinition->getObjectsAllowed()) {
                $value = Concrete::getById($value);
            } elseif ($fieldDefinition->getAssetsAllowed()) {
                $value = Asset::getById($value);
            } elseif ($fieldDefinition->getDocumentsAllowed()) {
                $value = PageSnippet::getById($value);
            }
        }

        if (!\is_scalar($value) && !$value instanceof Stringable) {
            return $value;
        }

        $value = (string)$value;

        if (!\preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]*)*$/', $value)) {
            $relationClass = null;
            if (is_numeric($value)) {
                $relationField = 'id';
            } else {
                $relationField = 'path';
            }

            if ($fieldDefinition->getObjectsAllowed() && count($fieldDefinition->getClasses()) === 1) {
                $relationClass = $fieldDefinition->getClasses()[0]['classes'];

                $relationClassDefinition = Helper::getClassDefinitionByName($relationClass);
                if ($relationClassDefinition instanceof ClassDefinition) {
                    $uniqueFieldFound = false;
                    foreach ($relationClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                        if ($relationFieldDefinition->getUnique()) {
                            $relationField = $relationFieldDefinition->getName();
                            $uniqueFieldFound = true;
                            break;
                        }
                    }

                    if (!$uniqueFieldFound) {
                        foreach ($relationClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                            if ($relationFieldDefinition->getIndex()) {
                                $relationField = $relationFieldDefinition->getName();
                                break;
                            }
                        }
                    }
                }
            } elseif ($fieldDefinition->getAssetsAllowed() && count($fieldDefinition->getAssetTypes()) === 1) {
                $relationClass = $fieldDefinition->getAssetTypes()[0]['assetTypes'];
            } elseif ($fieldDefinition->getDocumentsAllowed() && count($fieldDefinition->getDocumentTypes()) === 1) {
                $relationClass = $fieldDefinition->getDocumentTypes()[0]['documentTypes'];
            }

            if ($relationClass !== null) {
                $value = $relationClass.':'.$relationField.':'.$value;
            }
        }

        if (is_callable($currentValue)) {
            $currentValue = $currentValue();
        }

        if ($this->importer->findInRelation([$currentValue], $value) === null) {
            try {
                $object = $this->importer->getOneObjectByIdentifier($value);

                if ($object === null && ($mapping['format']['auto_create_references'] ?? false)) {
                    $dataQuerySelectorParts = $this->importer->getObjectIdentifierParts($value);

                    $referenceObject = $this->itemMoldBuilder->getItemMoldByClassname($dataQuerySelectorParts[0]);
                    $this->importer->getLogger()->info('Trying to automatically generate a '.$referenceObject->getClassname().' object with '.$dataQuerySelectorParts[1].'='.$dataQuerySelectorParts[2]);
                    $referenceFilterField = explode('#', $dataQuerySelectorParts[1])[0];
                    $referenceObjectSetter = 'set'.ucfirst($referenceFilterField);

                    try {
                        if (method_exists($referenceObject, $referenceObjectSetter)) {
                            $referenceObject->$referenceObjectSetter($dataQuerySelectorParts[2]);
                            if (strtolower($referenceObjectSetter) !== 'setpath') {
                                $referenceObject->setKey(Service::getValidKey($dataQuerySelectorParts[2], 'object'));

                                $firstFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' LIMIT 1', [$referenceObject->getClassId()]
                                ) ?: '/';
                                $lastFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' DESC LIMIT 1', [$referenceObject->getClassId()]
                                ) ?: '/';

                                $len = min(mb_strlen($firstFolder), mb_strlen($lastFolder));

                                for ($i = 0; $i < $len; $i++) {
                                    if (mb_substr($firstFolder, $i, 1) !== mb_substr($lastFolder, $i, 1)) {
                                        break;
                                    }
                                }

                                $commonPrefix = substr($firstFolder, 0, $i);

                                $rootFolder = $commonPrefix ?: '/';
                                if (substr($commonPrefix, -1) !== '/') {
                                    $rootFolder = rtrim(dirname($commonPrefix), '/').'/';
                                }

                                if ($rootFolder !== '/') {
                                    $referenceObjectParent = DataObject::getByPath($rootFolder);
                                } else {
                                    $referenceObjectParent = Service::createFolderByPath('/'.$referenceObject->getClassname());
                                }

                                $referenceObject->setParent($referenceObjectParent);
                                $referenceObject->setPath(rtrim($referenceObjectParent->getRealFullPath(), '/').'/');
                            } else {
                                $referenceObject->setKey(Service::getValidKey(basename($dataQuerySelectorParts[2]), 'object'));

                                $referenceObjectParent = DataObject::getByPath(dirname($referenceObject->getPath()));
                                if (!$referenceObjectParent instanceof DataObject) {
                                    $referenceObjectParent = Service::createFolderByPath(dirname($referenceObject->getPath()));
                                    $referenceObject->setPath(rtrim($referenceObjectParent->getRealFullPath(), '/').'/');
                                }
                                $referenceObject->setParent($referenceObjectParent);
                            }

                            $this->importer->saveObject($referenceObject);
                            $object = $referenceObject;
                        }
                    } catch(\Throwable $e) {
                        $this->log($dataObject, 'Could not create object based on data query selector "'.$value.'" because: '.$e->getMessage(), 'warning');
                        $object = $currentValue;
                    }
                }
                
                if (method_exists($this->importer->getLogger(), 'disablePimcoreLogger')) {
                    $this->importer->getLogger()->disablePimcoreLogger();
                }
                $fieldDefinition->checkValidity($object, true);

                $value = $object;
            } catch (ValidationException $e) {
                if(is_array($object)) {
                    return $this->map($mapping, $object, $currentValue, $fieldDefinition, $dataObject);
                }

                try {
                    $debugOutput = $this->importer->getOneObjectByIdentifier(is_string($value) && \preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*$/', $value) ? $value.':debug':$value);
                } catch(\Throwable $e) {
                    $debugOutput = '';
                }

                $this->log($dataObject, 'Skipped '.Importer::getLogOutput($value).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage().
                ($debugOutput ? '
                Debug output:
                '.$debugOutput : ''), 'warning');
                $value = $currentValue;
            } finally {
                if (method_exists($this->importer->getLogger(), 'enablePimcoreLogger')) {
                    $this->importer->getLogger()->enablePimcoreLogger();
                }
            }
        } else {
            $value = $currentValue;
        }
        
        return $value;
    }
}