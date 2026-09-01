<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Bundle\SimpleBackendSearchBundle\Model\Search\Backend\Data\Id;
use OpenDxp\Db;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Listing;

use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Translation\Translator;
use Psr\Log\LoggerInterface;

class QuickSearchListener
{
    public function loadByKeyFields($event) {
        $db = \OpenDxp\Db::get();

        $query = $event->getArgument('query');
        $searcherList = $event->getArgument('list');

        $conditionParts = [];

        $conditionParts[] = $this->getPermittedPaths();

        $queryParts = preg_split('/(\s+|,|\.)/', $query);
        foreach ($queryParts as &$queryPart) {
            if (mb_substr($queryPart, 0, 1) !== '-' && strlen($queryPart) > 2) {
                $queryPart = '+'.$queryPart;
            }
        }
        unset($queryPart);

        $matchCondition = 'MATCH (`data`,`properties`) AGAINST ('.$db->quote(implode(' ', $queryParts)).' IN BOOLEAN MODE)';
        $conditionParts[] = $matchCondition." AND `type` != 'folder'";

        $archiveFolders = ['_default_upload_bucket'];
        foreach(Dataport::getInstance()->find() as $dataport) {
            if(!empty($dataport['sourceconfig']['archiveFolder'])) {
                $archiveFolders[] = rtrim($dataport['sourceconfig']['archiveFolder'], '/').'/';
            }
        }

        // filter for Pimcore asset folders (no filesystem folders)
        $assetArchiveFolders = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT path FROM assets WHERE path IN (?)', [$archiveFolders]);
        foreach($assetArchiveFolders as $assetArchiveFolder) {
            $conditionParts[] = 'fullpath NOT LIKE '.$db->quote($assetArchiveFolder.'%');
        }

        $queryCondition = '('.implode(') AND (', array_unique($conditionParts)).')';

        $searcherList->setCondition($queryCondition);
        $searcherList->setOrderKey($matchCondition, false);

        $event->setArgument('list', $searcherList);
    }

    protected function getPermittedPaths($types = ['asset', 'document', 'object'])
    {
        $user = Helper::getUser();
        if($user->isAdmin()) {
            return '1';
        }

        $db = \OpenDxp\Db::get();

        $allowedTypes = [];

        foreach ($types as $type) {
            if ($user->isAllowed($type.'s')) { //the permissions are just plural
                $elementPaths = Service::findForbiddenPaths($type, $user);

                $forbiddenPathSql = [];
                $allowedPathSql = [];
                foreach ($elementPaths['forbidden'] as $forbiddenPath => $allowedPaths) {
                    $exceptions = '';
                    $folderSuffix = '';
                    if ($allowedPaths) {
                        $exceptionsConcat = implode("%' OR fullpath LIKE '", $allowedPaths);
                        $exceptions = " OR (fullpath LIKE '".$exceptionsConcat."%')";
                        $folderSuffix = '/'; //if allowed children are found, the current folder is listable but its content is still blocked, can easily done by adding a trailing slash
                    }
                    $forbiddenPathSql[] = ' (fullpath NOT LIKE '.$db->quote($forbiddenPath.$folderSuffix.'%').$exceptions.') ';
                }
                foreach ($elementPaths['allowed'] as $allowedPaths) {
                    $allowedPathSql[] = ' fullpath LIKE '.$db->quote($allowedPaths.'%');
                }

                // this is to avoid query error when implode is empty.
                // the result would be like `(maintype = type AND ((path1 OR path2) AND (not_path3 AND not_path4)))`
                $forbiddenAndAllowedSql = '(maintype = \''.$type.'\'';

                if ($allowedPathSql || $forbiddenPathSql) {
                    $forbiddenAndAllowedSql .= ' AND (';
                    $forbiddenAndAllowedSql .= $allowedPathSql ? '( '.implode(' OR ', $allowedPathSql).' )' : '';

                    if ($forbiddenPathSql) {
                        //if $allowedPathSql "implosion" is present, we need `AND` in between
                        $forbiddenAndAllowedSql .= $allowedPathSql ? ' AND ' : '';
                        $forbiddenAndAllowedSql .= implode(' AND ', $forbiddenPathSql);
                    }
                    $forbiddenAndAllowedSql .= ' )';
                }

                $forbiddenAndAllowedSql .= ' )';

                $allowedTypes[] = $forbiddenAndAllowedSql;
            }
        }

        //if allowedTypes is still empty after getting the workspaces, it means that there are no any main permissions set
        // by setting a `false` condition in the query makes sure that nothing would be displayed.
        if (!$allowedTypes) {
            $allowedTypes = ['false'];
        }

        return '('.implode(' OR ', $allowedTypes).')';
    }
}