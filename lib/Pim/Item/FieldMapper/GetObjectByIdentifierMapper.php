<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Exception;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;

class GetObjectByIdentifierMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return !$fieldDefinition instanceof Data\Relations\AbstractRelations &&
            !$fieldDefinition instanceof Data\ImageGallery &&
            !$fieldDefinition instanceof Data\Image &&
            !$fieldDefinition instanceof Data\Hotspotimage &&
            strtolower($mapping['fieldName'] ?? '') !== 'path' &&
            !in_array($fieldDefinition->getName(), ['__result_callback', '__result_action', '__init_action'], true);
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
         try {
            $targetObject = $this->importer->getObjectByIdentifier($value, $dataObject);
            if ($targetObject !== $value) {
                return $targetObject;
            }
        } catch (Exception $e) {
        }

        return $value;
    }
}