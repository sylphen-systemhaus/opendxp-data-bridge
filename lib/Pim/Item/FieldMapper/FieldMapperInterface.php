<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use Psr\Log\LoggerInterface;

interface FieldMapperInterface
{
    /**
     * @return bool
     */
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null);

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null);
}