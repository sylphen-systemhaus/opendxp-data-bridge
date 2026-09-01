<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CalculatedValue;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Definition;

class CalculatedValueDataQuerySelectorPhp8 extends CalculatedValue
{
    public function getDataForEditmode(mixed $data, Concrete $object = null, array $params = []): ?string
    {
        return $this->doGetDataForEditmode($data, $object, $params);
    }

    public function getDataForQueryResource(mixed $data, ?Concrete $object = null, array $params = []): ?string
    {
        return $this->doGetDataForQueryResource($data, $object, $params);
    }

    public function getGetterCode(Definition|ClassDefinition|\OpenDxp\Model\DataObject\Fieldcollection\Definition $class): string
    {
        return $this->doGetGetterCode($class);
    }

    public function getGetterCodeLocalizedfields(Definition|ClassDefinition|\OpenDxp\Model\DataObject\Fieldcollection\Definition $class): string
    {
        return $this->doGetGetterCodeLocalizedfields($class);
    }

    public function getGetterCodeFieldcollection(\OpenDxp\Model\DataObject\Fieldcollection\Definition $fieldcollectionDefinition): string
    {
        return $this->doGetGetterCodeFieldcollection($fieldcollectionDefinition);
    }

    public function getGetterCodeObjectbrick(Definition $brickClass): string
    {
        return $this->doGetGetterCodeObjectbrick($brickClass);
    }

    public function getFieldType(): string
    {
        return 'dataBridgeCalculatedValueDataQuerySelector';
    }

    public function getVersionPreview(mixed $data, Concrete $object = null, array $params = []): string
    {
        if($object === null) {
            return (string)$data;
        }
        return parent::getVersionPreview($data, $object, $params);
    }
}