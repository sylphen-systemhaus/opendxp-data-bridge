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
use OpenDxp\Model\User;

class UserMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\User;
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (is_string($value)) {
            $user = User::getByName($value);
            if (!$user instanceof User) {
                $user = User::getById($value);
            }
            if (!$user instanceof User) {
                $this->log($dataObject, 'Could not find user "'.$value.'"', 'warning');
            }
            $value = $user;
        } elseif (is_array($value) && !empty($value['id'])) {
            $value = User::getById($value['id']);
        } elseif (is_array($value) && !empty($value['username'])) {
            $value = User::getByName($value['username']);
        }

        if ($value instanceof User) {
            $value = $value->getId();
        }

        return $value;
    }
}