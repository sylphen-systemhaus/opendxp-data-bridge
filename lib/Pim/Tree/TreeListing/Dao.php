<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Sylphen\DataBridgeBundle\lib\Pim\Tree\TreeListing;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Tree\ConcreteDao;
use Sylphen\DataBridgeBundle\lib\Pim\Tree\FolderDao;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Model\DataObject;

class Dao extends \OpenDxp\Model\DataObject\Listing\Dao
{
    public function load(): array
    {
        $ids = $this->loadIdList();

        $hasChildren = PimcoreDbRepository::getInstance()->findInSql(
            'SELECT '.Helper::prefixObjectSystemColumn('id').' AS id,
            EXISTS (SELECT 1 FROM objects WHERE '.Helper::prefixObjectSystemColumn('parentId').'=parents.'.Helper::prefixObjectSystemColumn('id').' LIMIT 1) AS hasChildren
            FROM objects parents
            WHERE '.Helper::prefixObjectSystemColumn('id').' IN (?)',
            [$ids]
        );

        $hasChildren = array_column($hasChildren, 'hasChildren', 'id');

        return array_filter(array_map(static function ($id) use ($hasChildren) {
            $object = DataObject\AbstractObject::getById($id);

            if(!$object instanceof DataObject\AbstractObject) {
                return null;
            }

            if($object instanceof DataObject\Folder) {
                $dao = new FolderDao();
            } else {
                $dao = new ConcreteDao();
            }
            $dao->setModel($object);

            $dao->configure();

            $dao->setHasChildren($hasChildren[$id]);

            $object->setDao($dao);

            return $object;
        }, $ids));
    }
}