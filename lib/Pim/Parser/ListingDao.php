<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\EventListener\ClassChangedListener;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use OpenDxp;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\DataObject\Listing\Concrete\Dao;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Tool;

class ListingDao extends Dao
{
    private $tableName;

    /**
     * @return string
     *
     * @throws \Exception
     */
    public function getTableName(): string
    {
        if (empty($this->tableName)) {
            if(!property_exists('\\OpenDxp\\Model\\DataObject\\'.ucfirst($this->model->getClassName()), 'localizedfields')) {
                $this->model->setIgnoreLocalizedFields(true);
                $this->tableName = parent::getTableName();
            } elseif($this->model->getLocale()) {
                $this->tableName = 'object_localized_store_'.$this->model->getClassId().'_'.$this->model->getLocale();
            } else {
                $this->tableName = 'object_localized_store_'.$this->model->getClassId().'_'.Tool::getDefaultLanguage();
            }

            if(!PimcoreDbRepository::getInstance()->findOneInSql('SHOW TABLES LIKE \''.$this->tableName.'\'')) {
                try {
                    $classChangedListener = OpenDxp::getContainer()->get(ClassChangedListener::class);
                    $classChangedListener->createStoreView(new OpenDxp\Event\Model\DataObject\ClassDefinitionEvent(Helper::getClassDefinitionById($this->model->getClassId())));
                } catch (\Throwable $e) {
                }

                if (!PimcoreDbRepository::getInstance()->findOneInSql('SHOW TABLES LIKE \''.$this->tableName.'\'')) {
                    Logger::warning(
                        'Dataport is configured to not use inheritance. Usually for classes which support inheritance, the database view "'.$this->tableName.'" should get created when the class gets saved or rebuilt. But this view does not exist. For this reason the table "'.$this->tableName.'", which includes inherited information, gets used.'
                    );
                    $this->tableName = parent::getTableName();
                }
            }
        }

        return $this->tableName;
    }
}