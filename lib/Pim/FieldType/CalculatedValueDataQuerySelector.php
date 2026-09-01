<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp;
use OpenDxp\Db;
use OpenDxp\Logger;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CalculatedValue;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;

class CalculatedValueDataQuerySelector extends CalculatedValueDataQuerySelectorPhp8
{
    use CalculatedValueDataQuerySelectorPhpCompatibilityTrait;
}