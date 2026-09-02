<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;

class InputWithPlaceholdersPhp8 extends Input
{
    use FieldWithPlaceholdersTrait;

    public function getFieldType(): string
    {
        return 'dataBridgeInputWithPlaceholders';
    }

    public function getDataForResource(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): ?string
    {
        return $this->doGetDataForResource($data, $object, $params);
    }

    public function getDataForQueryResource(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): ?string
    {
        return $this->getDataForResource($data, $object, $params);
    }
}