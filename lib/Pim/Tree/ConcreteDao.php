<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Tree;

use OpenDxp\Model\DataObject;

class ConcreteDao extends DataObject\Concrete\Dao
{
    protected $hasChildren = false;


    public function setHasChildren($hasChildren)
    {
        $this->hasChildren = $hasChildren;
    }

    public function hasChildren(
        $objectTypes = [DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER],
        $includingUnpublished = null,
        $user = null): bool
    {
        return $this->hasChildren;
    }
}