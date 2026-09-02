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
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;

use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Exception\NotFoundException;

class ElementKeyMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if($dataObject instanceof ElementInterface) {
            $itemMold = $dataObject;
        } else {
            $itemMold = $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']);
        }

        return ((($itemMold instanceof Concrete || $itemMold instanceof PageSnippet) && strtolower($mapping['fieldName']) === 'key') || ($itemMold instanceof Asset && strtolower($mapping['fieldName']) === 'filename'));
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($dataObject instanceof ElementInterface) {
            $elementType = \OpenDxp\Model\Element\Service::getElementType($dataObject);
        } else {
            $itemMold = $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']);
            $elementType = \OpenDxp\Model\Element\Service::getElementType($itemMold);
        }

        if(is_array($value)) {
            $value = array_map(static function($valueItem) use ($elementType) {
                return Service::getValidKey($valueItem, $elementType);
            }, $value);
        } elseif($value !== null) {
            $value = Service::getValidKey($value, $elementType);
        }

        // not necessary in querying phase
        if($dataObject !== null && $this->importer->getDataport()) {
            $currentValue = (is_callable($currentValue) ? $currentValue() : $currentValue);
            if ($value !== $currentValue) {
                $suffix = 0;
                $parentPath = rtrim($dataObject->getPath() ?? '/', '/');
                do {
                    $pathExists = false;
                    $targetKey = Service::getValidKey(mb_substr($value, 0, 255 - strlen($suffix ? '_'.$suffix : '')), $elementType).($suffix ? '_'.$suffix : '');
                    $intendedPath = $parentPath.'/'.$targetKey;
                    $suffix++;

                    try {
                        if ($dataObject->getId() != Importer::getElementIdForPath($intendedPath, $elementType)) {
                            $pathExists = true;
                        }
                    } catch (\Exception $e) {
                        $elementWithSamePath = $this->importer->pathExistsInCache($intendedPath);
                        if ($elementWithSamePath !== null && $elementWithSamePath !== $dataObject) {
                            $pathExists = true;
                        }
                    }
                } while ($pathExists);

                $value = $targetKey;
            }
        }

        return $value;
    }
}