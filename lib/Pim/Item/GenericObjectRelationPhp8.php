<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use OpenDxp\Model\DataObject\ClassDefinition\Data\ManyToManyObjectRelation;

class GenericObjectRelationPhp8 extends ManyToManyObjectRelation
{
    public string $fieldtype = 'genericObjectRelation';
}