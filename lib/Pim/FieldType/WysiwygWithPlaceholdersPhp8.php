<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;


use OpenDxp\Model\DataObject\ClassDefinition\Data\Textarea;

class WysiwygWithPlaceholdersPhp8 extends Textarea
{
    use FieldWithPlaceholdersTrait;

    public function getDataForResource(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): ?string
    {
        return $this->doGetDataForResource($data, $object, $params);
    }

    public function getDataForQueryResource(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): ?string
    {
        return $this->getDataForResource($data, $object, $params);
    }

    public function getFieldType(): string
    {
        return 'dataBridgeWysiwygWithPlaceholders';
    }
}