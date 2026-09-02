<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use OpenDxp;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData;
use OpenDxp\Model\DataObject\Localizedfield;
use Psr\Log\NullLogger;
use UnexpectedValueException;

trait FieldWithPlaceholdersTrait
{
    /** @var ImporterInterface */
    private static $importer;

    private function getCalculation($container, $params) {
        $data = '';
        if ($container instanceof Concrete) {
            $data = $container->getObjectVar($this->getName());
        } elseif ($container instanceof Localizedfield || $container instanceof Classificationstore) {
            $data = $params['data'];
        } elseif ($container instanceof AbstractData || $container instanceof \OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData) {
            $data = $container->getObjectVar($this->getName());
        }

        if (\OpenDxp\Model\DataObject::doGetInheritedValues() && $this->isEmpty($data)) {
            $object = null;
            if ($container instanceof Concrete) {
                $object = $container;
            } elseif ($container instanceof Localizedfield || $container instanceof Classificationstore) {
                $object = $container->getObject();
            } elseif ($container instanceof AbstractData || $container instanceof \OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData) {
                $object = $container->getObject();
            }

            if ($object !== null) {
                $parent = $object->getNextParentForInheritance();
                if ($parent instanceof $object) {
                    return $this->getCalculation($parent, $params);
                }
            }
        }

        return $data;
    }

    public function preGetData($container, $params = [])
    {
        $calculation = $this->getCalculation($container, $params);

        if ($container instanceof Localizedfield) {
            $container = $container->getObject();
        }

        return trim(self::getImporter()->replaceObjectIdentifier($calculation, $container));
    }

    public function doGetDataForResource($data, $object = null, $params = [])
    {
        if (!empty($params['owner'])) {
            $container = $params['owner'];
            if ($container instanceof Concrete) {
                return $container->getObjectVar($this->getName());
            }

            if ($container instanceof Localizedfield || $container instanceof Classificationstore) {
                if (!array_key_exists($params['language'], $container->getInternalData())) {
                    // new objects
                    return null;
                }
                $data = $container->getInternalData()[$params['language']][$this->getName()];
                while (empty($data)) {
                    $parent = $container->getObject()->getParent();
                    while ($parent && $parent->getType() == AbstractObject::OBJECT_TYPE_FOLDER) {
                        $parent = $parent->getParent();
                    }
                    if ($parent && $parent->getClassId() === $container->getObject()->getClassId()) {
                        $container = $parent->getLocalizedfields();
                        $data = $container->getInternalData()[$params['language']][$this->getName()] ?? null;
                    } else {
                        break;
                    }
                }
                return $data;
            }

            if ($container instanceof AbstractData || $container instanceof \OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData) {
                return $container->getObjectVar($this->getName());
            }
        }

        if (method_exists($object, 'getObjectVar')) {
            return $object->getObjectVar($this->getName());
        }

        throw new UnexpectedValueException('Unknown owner');
    }

    public function doGetDataForQueryResource($data, $object = null, $params = [])
    {
        return $this->doGetDataForResource($data, $object, $params);
    }

    /**
     * @return ImporterInterface
     */
    private static function getImporter()
    {
        if (self::$importer === null) {
            /** @var ImporterInterface $importer */
            self::$importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);
            self::$importer->setLogger(new NullLogger());
        }

        return self::$importer;
    }
}