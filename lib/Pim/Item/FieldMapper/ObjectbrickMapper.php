<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ElementLockedException;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldDoesNotExistException;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use InvalidArgumentException;
use OpenDxp\Loader\ImplementationLoader\Exception\UnsupportedException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Service;
use OpenDxp\Model\DataObject\Objectbrick;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Tool;
use Throwable;

class ObjectbrickMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Objectbricks || $fieldDefinition->getFieldtype() === 'objectbricks';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (is_callable($currentValue)) {
            $currentValue = $currentValue();
        }

        $brickContainer = $currentValue;
        if (!$brickContainer instanceof Objectbrick) {
            $brickContainerClassName = "\\OpenDxp\\Model\\DataObject\\".ucfirst($dataObject->getClassName())."\\".ucfirst($fieldDefinition->getName());
            if (!class_exists($brickContainerClassName)) {
                $brickContainerClassName = Objectbrick::class;
            }
            $brickContainer = new $brickContainerClassName($dataObject, $fieldDefinition->getName());
        }

        $allowedTypes = $brickContainer->getAllowedBrickTypes();
        foreach ((array)$value as $brickName => $itemData) {
            if(is_int($brickName) && is_string($itemData) && Definition::getByKey($itemData)) {
                $brickName = $itemData;
                $itemData = [];
            }
            $brickTitle = $brickName;
            $brickName = trim(preg_replace('/[^a-z0-9_]+/i', '', Helper::toASCII($brickName, Tool::getDefaultLanguage())));
            if (!preg_match('/^[a-z]/i', $brickName)) {
                $brickName = 'Brick'.$brickName;
            }

            $format = $mapping['format'];

            $brickGetter = 'get'.ucfirst($brickName);

            $brickItem = null;
            if (method_exists($brickContainer, $brickGetter)) {
                $brickItem = $brickContainer->$brickGetter();
            }

            if (!$brickItem instanceof AbstractData) {
                try {
                    $brickItem = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($brickName), [$dataObject]);
                } catch (UnsupportedException $e) {
                    if (!empty($format['auto_generate_fields'])) {
                        $brickDefinition = new Definition();
                        $brickDefinition->setKey($brickName);
                        $brickDefinition->setTitle($brickTitle);
                        $brickDefinition->setGroup($dataObject->getClassname());
                        $brickDefinition->setClassDefinitions([['classname' => $dataObject->getClassname(), 'fieldname' => $fieldDefinition->getName()]]);
                        $brickDefinition->save();

                        require $brickDefinition->getPhpClassFile();
                        $brickItem = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($brickName), [$dataObject]);
                    } else {
                        $this->log($dataObject, 'Skipped brick '.$brickName.' as it is not allowed in field '.$fieldDefinition->getName(), 'warning');
                        continue;
                    }
                }

                $brickItem->setFieldname($fieldDefinition->getName());
                $addMethod = 'set'.ucfirst($brickName);
                if (method_exists($brickContainer, $addMethod)) {
                    $brickContainer->$addMethod($brickItem);
                }
            }

            foreach ($itemData as $field => $fieldValue) {
                $fieldTitle = $field;
                $field = trim(preg_replace('/[^a-z0-9_#]+/i', '_', Helper::toASCII($field, Tool::getDefaultLanguage())));
                $language = null;
                $fieldParts = explode('#', $field);
                if (isset($fieldParts[1])) {
                    $field = $fieldParts[0];
                    $language = $fieldParts[1];
                }

                $fieldMapping = ['brickName' => $brickName, 'fieldName' => $field, 'targetBrickField' => $fieldDefinition->getName(), 'format' => ['autoCreateUnits' => true, 'purgeitems' => $format['purgeitems'] ?? false, 'autoCreate' => !empty($format['auto_generate_fields'])]];
                if ($language !== null) {
                    $fieldMapping['locale'] = $language;
                }

                $currentFieldKey = Helper::getFieldKey($fieldMapping);
                if (isset($this->importer->getMappings()[$currentFieldKey]) && $brickItem->getDefinition()->getFieldDefinition($field) instanceof Data) {
                    $this->log($dataObject, 'Skipping field '.Helper::getFieldKey($fieldMapping).' for object brick container - instead using the mapped single field', 'info');
                    continue;
                }

                try {
                    $brickFieldDefinition = Importer::getFieldDefinition($brickItem, $fieldMapping);
                    try {
                        $getterArgs = [];
                        if (!empty($language)) {
                            $getterArgs[] = $language;
                        }
                        $currentValue = Importer::getValue($brickItem, $field, $getterArgs);
                    } catch (FieldDoesNotExistException $e) {
                        if (!empty($format['auto_generate_fields'])) {
                            $brickFieldDefinition->setLocked(false);
                            $brickFieldDefinition->setTitle($fieldTitle);
                            $brickFieldDefinition->setVisibleGridView(false);
                            $brickFieldDefinition->setVisibleSearch(false);

                            $brickDefinition = $brickItem->getDefinition();
                            if ($brickDefinition->getLayoutDefinitions() === null || !$brickDefinition->getLayoutDefinitions()->hasChildren()) {
                                $brickDefinition->setLayoutDefinitions(Service::generateLayoutTreeFromArray(['datatype' => 'layout', 'fieldtype' => 'panel'], true));
                            }

                            $firstPanel = null;
                            foreach ($brickDefinition->getLayoutDefinitions()->getChildren() as $layoutDefinition) {
                                if ($layoutDefinition->fieldtype === 'panel' && $layoutDefinition->getName() === $fieldDefinition->getName()) {
                                    $firstPanel = $layoutDefinition;
                                    break;
                                }
                            }

                            if ($firstPanel === null) {
                                $loader = \OpenDxp::getContainer()->get('opendxp.implementation_loader.object.layout');
                                $firstPanel = $loader->build('panel');
                                $firstPanel->setName($fieldDefinition->getName());
                                $brickDefinition->getLayoutDefinitions()->addChild($firstPanel);
                            }

                            $firstPanel->addChild($brickFieldDefinition);

                            $brickDefinition->addFieldDefinition($brickFieldDefinition->getName(), $brickFieldDefinition);
                            $brickDefinition->save();


                            $this->log($dataObject, 'Automatically created field "'.$field.'" in object brick "'.$brickName.'" (container "'.$fieldDefinition->getName().'")', 'info');

                            $this->log($dataObject, 'Pimcore currently does not support adding object brick fields during runtime. Waiting for brick class to be generated and afterwards this import gets continued in a separate process.', 'info');

                            while (true) {
                                try {
                                    Definition::getByKey($brickItem->getType());
                                    break;
                                } catch (\Exception $e) {
                                    sleep(1);
                                }
                            }

                            throw new ElementLockedException($dataObject);
                        } else {
                            $this->log($dataObject, 'Could not find brick field "'.$field.'" in object brick "'.$brickName.'" (container "'.$fieldDefinition->getName().'")', 'warning');
                            continue;
                        }
                    }

                    if (!empty($format['writeProtected']) && !$this->importer->isEmpty($currentValue, $brickFieldDefinition)) {
                        $this->log($dataObject, 'Field "'.Helper::getFieldKey($fieldMapping).'" is populated and configured to be write-protected -> won\'t change value', 'info');
                        continue;
                    }

                    $fieldValue = $this->importer->map($fieldMapping, $fieldValue, $currentValue, $brickFieldDefinition, $dataObject);

                    $arguments = [];
                    if (!empty($fieldMapping['locale'])) {
                        $arguments[] = $fieldMapping['locale'];
                    }

                    try {
                        Importer::setValue($brickItem, $field, $fieldValue, $arguments);
                    } catch (Throwable $e) {
                        $this->importer->getLogger()->error($e->getMessage());
                    }
                } catch (Throwable $e) {
                    if ($e instanceof ElementLockedException) {
                        throw $e;
                    }

                    if ($e instanceof FieldDoesNotExistException) {
                        $this->importer->getLogger()->notice('Could not apply mapping helpers to field '.$field.' of brick '.$brickName.': '.$e->getMessage());
                    } else {
                        $this->importer->getLogger()->warning('Could not apply mapping helpers to field '.$field.' of brick '.$brickName.': '.$e->getMessage());
                    }
                }
            }
        }

        $value = $brickContainer;

        return $value;
    }
}