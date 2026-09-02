<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Cache\Runtime;
use OpenDxp\Db;
use OpenDxp\Model\DataObject\ClassDefinition\CalculatorClassInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\CalculatedValue;

class CalculatedValueCalculator implements CalculatorClassInterface
{
    public function compute(Concrete $object, CalculatedValue $context): string
    {
        try {
            return (string)RuntimeCache::get(self::getRuntimeCacheKey($object, $context));
        } catch(\Exception $e) {
            if($context->getOwnerType() === 'classificationstore') {
                return PimcoreDbRepository::getInstance()->findOneInSql('SELECT value FROM object_classificationstore_data_'.$object->getClassId().' WHERE '.Helper::prefixObjectSystemColumn('id').'=? AND fieldname=?', [$object->getId(), $context->getFieldname()]) ?: '';
            }

            if ($context->getOwnerType() === 'objectbrick') {
                return PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Db::get()->quoteIdentifier($context->getFieldname()).' FROM object_brick_query_'.$context->getIndex().'_'.$object->getClassId().' WHERE '.Helper::prefixObjectSystemColumn('id').'=? AND fieldname=?', [$object->getId(), $context->getOwnername()]) ?: '';
            }

            if($context->getPosition()) {
                return PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Db::get()->quoteIdentifier($context->getFieldname()).' FROM object_localized_query_'.$object->getClassId().'_'.$context->getPosition().' WHERE ooo_id=?', [$object->getId()]) ?: '';
            }

            return PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Db::get()->quoteIdentifier($context->getFieldname()).' FROM object_query_'.$object->getClassId().' WHERE oo_id=?', [$object->getId()]) ?: '';
        }
    }

    public function getCalculatedValueForEditMode(Concrete $object, CalculatedValue $context): string
    {
        return $this->compute($object, $context);
    }

    public static function getRuntimeCacheKey(Concrete $object, CalculatedValue $context) {
        return $object->getFullpath().'_'.$context->getFieldname().'_'.$context->getOwnerType().'_'.$context->getOwnerName();
    }
}