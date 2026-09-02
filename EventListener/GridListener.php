<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Model\DataObject;

class GridListener
{
    public function defaultToClassWithMostObjects($event) {
        $eventData = $event->getArgument('data');

        if(empty($eventData['selectedClass'])) {
            /** @var DataObject $object */
            $object = $event->getArgument('object');
            $classWithMostObjects = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('classId').' 
                FROM objects
                WHERE '.Helper::prefixObjectSystemColumn('path').' LIKE ?
                AND '.Helper::prefixObjectSystemColumn('type').' = "object" 
                GROUP BY '.Helper::prefixObjectSystemColumn('classId').' 
                ORDER BY COUNT(*) DESC',
                [$object->getRealFullPath().'/%']
            );
            if($classWithMostObjects) {
                $eventData['selectedClass'] = $classWithMostObjects;
                $event->setArgument('data', $eventData);
            }
        }
    }
}