<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Export;
use OpenDxp\Loader\ImplementationLoader\Exception\UnsupportedException;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\FactoryInterface;
use Throwable;

class ItemMoldBuilder
{
    /** @var FactoryInterface */
    private $modelFactory;

    private static $itemMolds = [];
    private static $itemMoldsByClassId = [];
    private static $classes = [];

    public function __construct(FactoryInterface $modelFactory)
    {
        $this->modelFactory = $modelFactory;
    }

    /**
     * @param int $dataportId
     *
     * @return Concrete|Asset
     */
    public function getItemMold($dataportId, $force = false) {
        if(!isset(self::$itemMolds[$dataportId]) || $force) {
            $table = Dataport::getInstance();
            $dataport = $table->get($dataportId);

            $targetConfig = $dataport['targetconfig'];

            if (is_a($targetConfig['itemClass'], PageSnippet::class, true)) {
                $masterDocumentPath = $targetConfig['masterDocument'];
                if ($masterDocumentPath) {
                    $masterDocument = PageSnippet::getByPath($masterDocumentPath);
                    if ($masterDocument instanceof PageSnippet) {
                        self::$itemMolds[$dataportId] = $this->getItemMoldByClassId(get_class($masterDocument));
                        return self::$itemMolds[$dataportId];
                    }
                }
            }

            self::$itemMolds[$dataportId] = $this->getItemMoldByClassId($targetConfig['itemClass'], $force);
        }

        self::$itemMolds[$dataportId]->setId(null);

        return self::$itemMolds[$dataportId];
    }

    /**
     * @param int|string $classId
     *
     * @return Concrete|Asset
     */
    private function getItemMoldByClassIdInternal($classId) {
        if (is_string($classId) && \class_exists($classId)) {
            try {
                return $this->modelFactory->build(ltrim($classId, '\\'));
            } catch(\Throwable $e) {
            }
        }

        if(!$classId) {
            $itemMold = new Export();
            $itemMold->setClass(new ClassDefinition());
            return $itemMold;
        }

        $class = Helper::getClassDefinitionById($classId);
        if (!($class instanceof ClassDefinition)) {
            throw new \Exception('No class found for id '.$classId);
        }

        return $this->getItemMoldByClassname($class->getName());
    }

    /**
     * @param int|string $classId
     *
     * @return Concrete|Asset
     */
    public function getItemMoldByClassId($classId, $force = false)
    {
        if (!isset(self::$itemMoldsByClassId[$classId]) || $force) {
            self::$itemMoldsByClassId[$classId] = $this->getItemMoldByClassIdInternal($classId);
        }

        return self::$itemMoldsByClassId[$classId];
    }

    /**
     * @param string $className
     * @return Concrete
     * @throws \Exception
     */
    public function getItemMoldByClassname($className) {
        try {
            return $this->modelFactory->build($className);
        } catch(Throwable $e) {
        }

        try {
            return $this->modelFactory->build('OpenDxp\\Model\\DataObject\\'.ucfirst($className));
        } catch(Throwable $e) {
        }

        try {
            return $this->modelFactory->build('OpenDxp\\Model\\Object\\'.ucfirst($className));
        } catch(Throwable $e) {
            throw new \Exception('Could not instantiate "'.$className.'"');
        }
    }

    private function getClassInternal($sourceClass) {
        $classFqn = $sourceClass;
        if (strpos($classFqn, '\\') !== false && class_exists($classFqn)) {
            return $classFqn;
        }
        $classFqn = '\\OpenDxp\\Model\\DataObject\\'.ucfirst($sourceClass);
        if (is_a($classFqn, Concrete::class, true)) {
            return $classFqn;
        }

        $classFqn = '\\OpenDxp\\Model\\Object\\'.ucfirst($sourceClass);
        if (class_exists($classFqn)) {
            return $classFqn;
        }

        if (strtolower($sourceClass) === 'asset') {
            return Asset::class;
        }
        if (strtolower($sourceClass) === 'image') {
            return Asset\Image::class;
        }
        if (strtolower($sourceClass) === 'video') {
            return Asset\Video::class;
        }
        if (strtolower($sourceClass) === 'document') {
            return Document::class;
        }
        if (strtolower($sourceClass) === 'page') {
            return Document\Page::class;
        }

        return false;
    }

    public function getClass($sourceClass) {
        if(!isset(self::$classes[$sourceClass])) {
            self::$classes[$sourceClass] = $this->getClassInternal($sourceClass);
        }

        if(self::$classes[$sourceClass] === false) {
            return null;
        }
        return self::$classes[$sourceClass];
    }
}