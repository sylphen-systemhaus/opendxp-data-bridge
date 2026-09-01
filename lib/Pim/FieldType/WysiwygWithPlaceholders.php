<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData;
use OpenDxp\Model\DataObject\Localizedfield;
use UnexpectedValueException;

class WysiwygWithPlaceholders extends WysiwygWithPlaceholdersPhp8
{
}