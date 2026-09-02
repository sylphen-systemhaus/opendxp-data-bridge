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
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBagComposite;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBagObject;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\InMemoryLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItemField;
use OpenDxp;
use OpenDxp\Db;
use OpenDxp\Event\Model\ElementEventInterface;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CalculatedValue;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Element\Service;
use Psr\Log\NullLogger;

trait CalculatedValueDataQuerySelectorPhpCompatibilityTrait
{
    /**
     * @internal
     *
     * @var string|null
     */
    public $dataQuerySelector = null;

    /** @var ImporterInterface */
    private static $importer;

    /** @var ItemMoldBuilder */
    private static $itemMoldBuilder;

    private static $fetchFromDatabase = true;

    private static $storeInDatabase = true;

    private static $clearDatabaseBuffer = null;

    public static function disableDatabaseFetch()
    {
        self::$fetchFromDatabase = false;
    }

    public static function disableDatabaseStore()
    {
        self::$storeInDatabase = false;
    }

    public function clearDatabaseCache(ElementEventInterface $e)
    {
        try {
            if ($e->getArgument('saveVersionOnly')) {
                return;
            }
        } catch (\InvalidArgumentException $exception) {
        }

        $object = $e->getElement();
        if($object instanceof OpenDxp\Model\DataObject\Concrete) {
            if(self::$clearDatabaseBuffer === null) {
                self::$clearDatabaseBuffer = [];
                register_shutdown_function(static function() {
                    foreach(array_chunk(self::$clearDatabaseBuffer ?? [], 1000) as $objectData) {
                        if (!\OpenDxp::hasContainer()) {
                            continue;
                        }
                        $objectIds = array_column($objectData, 'id');
                        $dependentObjects = PimcoreDbRepository::getInstance()->findInSql(
                            '(
                                SELECT dependencies.targetid AS id, objects.'.Helper::prefixObjectSystemColumn('classId').' as classId
                                FROM dependencies
                                LEFT JOIN objects ON dependencies.targettype="object" AND dependencies.targetid=objects.'.Helper::prefixObjectSystemColumn('id').'
                                WHERE dependencies.sourceid IN (?) AND dependencies.sourcetype = ? AND objects.'.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL
                            ) UNION (
                                SELECT dependencies.sourceid AS id, objects.'.Helper::prefixObjectSystemColumn('classId').' as classId
                                FROM dependencies
                                LEFT JOIN objects ON dependencies.sourcetype="object" AND dependencies.sourceid=objects.'.Helper::prefixObjectSystemColumn('id').'
                                WHERE dependencies.targetid IN (?) AND dependencies.targettype = ? AND objects.'.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL
                            )',
                            [$objectIds, 'object', $objectIds, 'object']
                        );

                        $objectPaths = array_column($objectData, 'fullpath');
                        $descendants = [[]];
                        foreach($objectPaths as $objectPath) {
                            $descendants[] = PimcoreDbRepository::getInstance()->findInSql(
                                'SELECT '.Helper::prefixObjectSystemColumn('id').' AS id, '.Helper::prefixObjectSystemColumn('classId').' AS classId FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').' LIKE ?',
                                [$objectPath.'%']
                            );
                        }

                        $dependentObjects = array_merge($dependentObjects, ...$descendants);

                        $groupedDependentObjects = array();
                        foreach ($dependentObjects as $dependentObject) {
                            $groupedDependentObjects[$dependentObject['classId']][] = $dependentObject['id'];
                        }

                        if ($dependentObjects) {
                            foreach ((new OpenDxp\Model\DataObject\ClassDefinition\Listing())->load() as $classDefinition) {
                                $fields = [];
                                $localizedFields = [];
                                foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                                    if ($fieldDefinition instanceof CalculatedValueDataQuerySelector) {
                                        $fields[] = $fieldDefinition->getName();
                                    } elseif ($fieldDefinition instanceof OpenDxp\Model\DataObject\ClassDefinition\Data\Localizedfields) {
                                        foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                                            if ($localizedFieldDefinition instanceof CalculatedValueDataQuerySelector) {
                                                $localizedFields[] = $localizedFieldDefinition->getName();
                                            }
                                        }
                                    }
                                }

                                if (count($fields) > 0) {
                                    $assignments = array_map(static function ($field) {
                                        return Db::get()->quoteIdentifier($field).'=NULL';
                                    }, $fields);

                                    foreach (array_chunk($groupedDependentObjects[$classDefinition->getId()] ?? [], 1000) as $dependentObjectChunk) {
                                        try {
                                            PimcoreDbRepository::retry(static function () use ($classDefinition, $dependentObjectChunk, $assignments) {
                                                PimcoreDbRepository::getInstance()->execute('UPDATE object_query_'.$classDefinition->getId().' SET '.implode(',', $assignments).' WHERE oo_id IN (?)', [$dependentObjectChunk]);
                                            });
                                        } catch (\Throwable $e) {
                                        }
                                    }
                                }

                                if (count($localizedFields) > 0) {
                                    $assignments = array_map(static function ($field) {
                                        return Db::get()->quoteIdentifier($field).'=NULL';
                                    }, $localizedFields);

                                    foreach (OpenDxp\Tool::getValidLanguages() as $language) {
                                        foreach (array_chunk($groupedDependentObjects[$classDefinition->getId()] ?? [], 1000) as $dependentObjectChunk) {
                                            try {
                                                PimcoreDbRepository::retry(static function () use ($classDefinition, $language, $dependentObjectChunk, $assignments) {
                                                    PimcoreDbRepository::getInstance()->execute('UPDATE object_localized_query_'.$classDefinition->getId().'_'.$language.' SET '.implode(',', $assignments).' WHERE ooo_id IN (?)', [$dependentObjectChunk]);
                                                });
                                            } catch (\Throwable $e) {
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                });
            }

            self::$clearDatabaseBuffer[] = ['id' => $object->getId(), 'fullpath' => $object->getRealFullPath()];
        }
    }

    /**
     * @return string|null
     */
    public function getDataQuerySelector(): ?string
    {
        return $this->dataQuerySelector;
    }

    /**
     * @param string|null $dataQuerySelector
     */
    public function setDataQuerySelector(?string $dataQuerySelector): void
    {
        $this->dataQuerySelector = $dataQuerySelector;
    }

    /**
     * @param OpenDxp\Model\DataObject\Data\CalculatedValue|null $data
     * @param OpenDxp\Model\DataObject\Concrete $object
     * @param array $params
     *
     * @return string|null
     * @see Data::getDataForEditmode
     *
     */
    public function doGetDataForEditmode($data, $object = null, $params = [])
    {
        $getResultParams = [
            'fieldName' => $this->getName(),
            'locale' => $data instanceof OpenDxp\Model\DataObject\Data\CalculatedValue && $data->getOwnerType() !== 'objectbrick' ? $data->getPosition() : null,
        ];

        if ($data instanceof OpenDxp\Model\DataObject\Data\CalculatedValue && $data->getOwnerType() === 'fieldcollection') {
            $containerGetter = 'get'.ucfirst($data->getOwnerName());
            $object = $object->$containerGetter()->getItems()[$data->getIndex()];
        }

        if ($data instanceof OpenDxp\Model\DataObject\Data\CalculatedValue && $data->getOwnerType() === 'objectbrick') {
            $containerGetter = 'get'.ucfirst($data->getOwnerName());
            $brickGetter = 'get'.ucfirst($data->getIndex());
            $object = $object->$containerGetter()->$brickGetter();
        }

        return self::getResult($this->getDataQuerySelector(), $object, $getResultParams);
    }

    public function doGetDataForQueryResource($data, $object = null, $params = [])
    {
        self::disableDatabaseFetch();
        return self::getResult($this->getDataQuerySelector(), $object, $params);
    }

    public static function getResult($dataQuerySelector, $object = null, $params = [])
    {
        if(!isset($params['locale']) && isset($params['language'])) {
            $params['locale'] = $params['language'];
        }

        if (!$dataQuerySelector) {
            try {
                $calculator = OpenDxp\Model\DataObject\ClassDefinition\Helper\CalculatorClassResolver::resolveCalculatorClass(CalculatedValueCalculator::class);
                $context = new OpenDxp\Model\DataObject\Data\CalculatedValue($params['fieldName']);
                $context->setContextualData('object', null, null, $params['locale'] ?? null);

                $ownerType = 'object';
                $ownerName = null;
                $index = null;
                $position = null;
                if ($object instanceof AbstractData) {
                    $ownerType = 'objectbrick';
                    $ownerName = $object->getFieldname();
                    $index = $object->getType();
                }

                $context->setContextualData($ownerType, $ownerName, $index, $position);
                return trim($calculator->compute($object, $context));
            } catch (\Throwable $e) {
                return null;
            }
        }

        if(self::$fetchFromDatabase && !empty($params['fieldName'])) {
            if($object instanceof OpenDxp\Model\DataObject\Concrete) {
                $dbValue = PimcoreDbRepository::getInstance()->findOneInSql(
                    'SELECT '.Db::get()->quoteIdentifier($params['fieldName']).' FROM '.(!empty($params['locale']) ? 'object_localized_'.$object->getClassId().'_'.$params['locale'] : 'object_'.$object->getClassId()).' WHERE oo_id=?',
                    [$object->getId()]
                ) ?: '';
                if ($dbValue && strpos($dbValue, 'Exception') === false) {
                    return $dbValue;
                }
            } elseif ($object instanceof AbstractData && $object->getObject()) {
                $dbValue = PimcoreDbRepository::getInstance()->findOneInSql(
                    'SELECT '.Db::get()->quoteIdentifier($params['fieldName']).' FROM '.(!empty($params['locale']) ? 'object_brick_localized_query_'.$object->getType().'_'.$object->getObject()->getClassId().'_'.$params['locale'] : 'object_brick_query_'.$object->getType().'_'.$object->getObject()->getClassId()).' WHERE '.Helper::prefixObjectSystemColumn('id').'=?',
                    [$object->getObject()->getId()]
                ) ?: '';
                if ($dbValue && strpos($dbValue, 'Exception') === false) {
                    return $dbValue;
                }
            }
        }

        $inheritanceEnabled = AbstractObject::getGetInheritedValues();
        try {
            if (!empty($params['locale'])) {
                $originalLanguage = \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale();
                \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($params['locale']);
            }

            $phpPrefix = '<?php';
            Helper::useInheritance(true);
            $trimOutputForbetterPerformance = Serializer::getTrimOutputForBetterPerformance();
            Serializer::trimOutputForBetterPerformance(true);
            if (substr($dataQuerySelector, 0, strlen($phpPrefix)) === $phpPrefix || strpos($dataQuerySelector, 'return ') !== false) {
                $dataQuerySelector = preg_replace_callback('/\{\{\s*(\S+?( +\S+?)*?)\s*\}\}/', static function ($matches) use ($object) {
                    if ($matches[1] === '' && $matches[4] === '') {
                        return $matches[2];
                    }

                    if (substr($matches[1], 0, 6) !== '.:.:.:') {
                        $parts = \str_getcsv($matches[1], ':', '"');
                        if (count($parts) >= 3) {
                            if (self::getItemMoldBuilder()->getClass($parts[0]) !== null) {
                                if (strtolower(substr($parts[1], 0, 5)) !== 'getby') {
                                    // if we get here, first data query selector part also exists as data object class -> check if field for $object with same name exists -> field has higher priority
                                    $fieldDefinition = Importer::getFieldDefinition($object, $parts[0]);
                                    if (!$fieldDefinition->getLocked()) {
                                        $matches[1] = '.:.:.:'.$matches[1];
                                    }
                                }
                            } else {
                                $matches[1] = '.:.:.:'.$matches[1];
                            }
                        } else {
                            $matches[1] = '.:.:.:'.$matches[1];
                        }
                    }

                    $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                    try {
                        Helper::useInheritance(true);
                        Serializer::trimOutputForBetterPerformance(true);
                        return var_export(self::getImporter()->getObjectByIdentifier($matches[1], $object), true);
                    } finally {
                        Helper::useInheritance($inheritanceEnabled);
                    }
                }, $dataQuerySelector);

                $currentObjectValues = function () use ($object, $dataQuerySelector) {
                    if (!preg_match('/currentObjectData[\'"]\][);]/', $dataQuerySelector)) {
                        if (preg_match_all('/currentObjectData[\'"]\]\[["\']([A-Za-z0-9_#]+)["\']\]/', $dataQuerySelector, $fieldsToBeSerialized)) {
                            $currentObjectValues = [];
                            foreach ($fieldsToBeSerialized[1] as $fieldToBeSerialized) {
                                if (isset($currentObjectValues[$fieldToBeSerialized])) {
                                    continue;
                                }

                                Serializer::trimOutputForBetterPerformance(false);
                                $fieldSerialization = Importer::getSerializer()->serializeField($object, Importer::getFieldDefinition($object, $fieldToBeSerialized));

                                $currentObjectValues[$fieldToBeSerialized] = $fieldSerialization;
                            }
                        } else {
                            Serializer::trimOutputForBetterPerformance(false);
                            $currentObjectValues = Importer::getSerializer()->getAttributesArrayForObject($object);
                        }
                    } else {
                        Serializer::trimOutputForBetterPerformance(false);
                        $currentObjectValues = Importer::getSerializer()->getAttributesArrayForObject($object);
                    }

                    return $currentObjectValues;
                };
                $logger = new InMemoryLogger(function () {
                    return true;
                });
                $jsParams = [
                    'currentObjectData' => $currentObjectValues,
                    'request' => Helper::getRequest(),
                    'logger' => $logger
                ];

                $returnValue = CallbackFunction::evaluateScript($dataQuerySelector, CallbackFunction::ENGINE_PHP, $jsParams);

                $logs = $logger->getLogs();
                if ($logs) {
                    $returnValue .= PHP_EOL.PHP_EOL.implode(PHP_EOL, $logs);
                }
            } elseif (strpos($dataQuerySelector, '{{') === false && strpos($dataQuerySelector, '{%') === false) {
                if (strpos($dataQuerySelector, '.:.:.:') !== 0) {
                    $parts = \str_getcsv($dataQuerySelector, ':', '"');
                    if (self::getItemMoldBuilder()->getClass($parts[0]) !== null) {
                        // if we get here first data query selector part also exists as data object class -> check if field for $object with same name exists -> field has higher priority
                        $fieldDefinition = Importer::getFieldDefinition($object, $parts[0]);
                        if (!$fieldDefinition->getLocked()) {
                            $dataQuerySelector = '.:.:.:'.$dataQuerySelector;
                        }
                    } else {
                        $dataQuerySelector = '.:.:.:'.$dataQuerySelector;
                    }
                }

                Serializer::trimOutputForBetterPerformance(true);
                $returnValue = self::getImporter()->getObjectByIdentifier($dataQuerySelector, $object);

                if ($returnValue !== null && !is_scalar($returnValue)) {
                    $returnValue = Importer::getLogOutput($returnValue);
                } elseif (is_bool($returnValue)) {
                    $returnValue = (int)$returnValue;
                }
            } else {
                $returnValue = self::getImporter()->replaceObjectIdentifier($dataQuerySelector, $object);

                $returnValue = self::getImporter()->getObjectByIdentifier($returnValue, $object);
            }
        } catch (\Exception $e) {
            $returnValue = (string)$e;
        } finally {
            if (!empty($params['locale'])) {
                \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($originalLanguage);
            }

            Helper::useInheritance($inheritanceEnabled);
            Serializer::trimOutputForBetterPerformance($trimOutputForbetterPerformance);
        }

        if(!empty($params['fieldName']) && self::$storeInDatabase) {
            try {
                if($object instanceof OpenDxp\Model\DataObject\Concrete) {
                    if (!empty($params['locale'])) {
                        PimcoreDbRepository::getInstance()->execute('SELECT 1 FROM object_localized_query_'.$object->getClassId().'_'.$params['locale'].' WHERE ooo_id=? FOR UPDATE NOWAIT', [$object->getId()]);
                        PimcoreDbRepository::getInstance()->execute('UPDATE object_localized_query_'.$object->getClassId().'_'.$params['locale'].' SET '.Db::get()->quoteIdentifier($params['fieldName']).'=? WHERE ooo_id=?', [$returnValue, $object->getId()]);
                    } else {
                        PimcoreDbRepository::getInstance()->execute('SELECT 1 FROM object_query_'.$object->getClassId().' WHERE oo_id=? FOR UPDATE NOWAIT', [$object->getId()]);
                        PimcoreDbRepository::getInstance()->execute('UPDATE object_query_'.$object->getClassId().' SET '.Db::get()->quoteIdentifier($params['fieldName']).'=? WHERE oo_id=?', [$returnValue, $object->getId()]);
                    }
                } elseif($object instanceof AbstractData) {
                    if (!empty($params['locale'])) {
                        PimcoreDbRepository::getInstance()->execute('SELECT 1 FROM object_brick_localized_query_'.$object->getType().'_'.$object->getObject()->getClassId().'_'.$params['locale'].' WHERE ooo_id=? FOR UPDATE NOWAIT', [$object->getObject()->getId()]);
                        PimcoreDbRepository::getInstance()->execute('UPDATE object_brick_localized_query_'.$object->getType().'_'.$object->getObject()->getClassId().'_'.$params['locale'].' SET '.Db::get()->quoteIdentifier($params['fieldName']).'=? WHERE ooo_id=?', [$returnValue, $object->getObject()->getId()]);
                    } else {
                        PimcoreDbRepository::getInstance()->execute('SELECT 1 FROM object_brick_query_'.$object->getType().'_'.$object->getObject()->getClassId().' WHERE '.Helper::prefixObjectSystemColumn('id').'=? FOR UPDATE NOWAIT', [$object->getObject()->getId()]);
                        PimcoreDbRepository::getInstance()->execute('UPDATE object_brick_query_'.$object->getType().'_'.$object->getObject()->getClassId().' SET '.Db::get()->quoteIdentifier($params['fieldName']).'=? WHERE '.Helper::prefixObjectSystemColumn('id').'=?', [$returnValue, $object->getObject()->getId()]);
                    }
                }
            } catch(\Throwable $e) {
            }
        }

        if(!is_string($returnValue)) {
            $returnValue = json_encode($returnValue);
        }

        return trim($returnValue);
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

    /**
     * @return ItemMoldBuilder
     */
    private static function getItemMoldBuilder()
    {
        if (self::$itemMoldBuilder === null) {
            /** @var ImporterInterface $importer */
            self::$itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
        }

        return self::$itemMoldBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function doGetGetterCode($class)
    {
        $key = $this->getName();

        $code = '/**'."\n";
        $code .= '* Get '.str_replace(['/**', '*/', '//'], '', $this->getName()).' - '.str_replace(['/**', '*/', '//'], '', $this->getTitle())."\n";
        $code .= '* @return '.$this->getPhpdocReturnType()."\n";
        $code .= '*/'."\n";
        $code .= 'public function get'.ucfirst($key).'()'."\n";
        $code .= '{'."\n";

        if ($class instanceof OpenDxp\Model\DataObject\Objectbrick\Definition) {
            $code .= "\t".'$object = $this->getObject();'."\n";
        } else {
            $code .= "\t".'$object = $this;'."\n";
        }

        $code .= "\t".'$data = \\Sylphen\\DataBridgeBundle\\lib\\Pim\\FieldType\\CalculatedValueDataQuerySelector::getResult(\''.str_replace('\'','\\\'', $this->getDataQuerySelector()).'\', $object, [\'fieldName\' => \''.$this->getName().'\']);'."\n\n";
        $code .= "\t".'return $data;'."\n";
        $code .= "}\n\n";

        return $code;
    }

    /**
     * {@inheritdoc}
     */
    public function doGetGetterCodeLocalizedfields($class)
    {
        $key = $this->getName();
        $code = '/**'."\n";
        $code .= '* Get '.str_replace(['/**', '*/', '//'], '', $this->getName()).' - '.str_replace(['/**', '*/', '//'], '', $this->getTitle())."\n";
        $code .= '* @return '.$this->getPhpdocReturnType()."\n";
        $code .= '*/'."\n";
        $code .= 'public function get'.ucfirst($key).'($language = null)'."\n";
        $code .= '{'."\n";
        $code .= "\t".'if (!$language) {'."\n";
        $code .= "\t\t".'try {'."\n";
        $code .= "\t\t\t".'$locale = \OpenDxp::getContainer()->get(\''.LocaleServiceInterface::class.'\')->getLocale();'."\n";
        $code .= "\t\t\t".'if (\OpenDxp\Tool::isValidLanguage($locale)) {'."\n";
        $code .= "\t\t\t\t".'$language = (string) $locale;'."\n";
        $code .= "\t\t\t".'} else {'."\n";
        $code .= "\t\t\t\t".'throw new \Exception("Not supported language");'."\n";
        $code .= "\t\t\t".'}'."\n";
        $code .= "\t\t".'} catch (\Exception $e) {'."\n";
        $code .= "\t\t\t".'$language = \OpenDxp\Tool::getDefaultLanguage();'."\n";
        $code .= "\t\t".'}'."\n";
        $code .= "\t".'}'."\n";

        if ($class instanceof OpenDxp\Model\DataObject\Objectbrick\Definition) {
            $code .= "\t".'$object = $this->getObject();'."\n";
        } else {
            $code .= "\t".'$object = $this;'."\n";
        }

        $code .= "\t".'$data'." = new \\OpenDxp\\Model\\DataObject\\Data\\CalculatedValue('".$key."');\n";
        $code .= "\t".'$data = \\Sylphen\\DataBridgeBundle\\lib\\Pim\\FieldType\\CalculatedValueDataQuerySelector::getResult(\''.str_replace('\'', '\\\'', $this->getDataQuerySelector()).'\', $object, [\'fieldName\' => \''.$this->getName().'\', \'locale\' => $language]);'."\n\n";
        $code .= "\treturn ".'$data'.";\n";
        $code .= "}\n\n";

        return $code;
    }

    /**
     * {@inheritdoc}
     */
    public function doGetGetterCodeObjectbrick($brickClass)
    {
        $key = $this->getName();
        $code = '';
        $code .= '/**'."\n";
        $code .= '* Set '.str_replace(['/**', '*/', '//'], '', $this->getName()).' - '.str_replace(['/**', '*/', '//'], '', $this->getTitle())."\n";
        $code .= '* @return '.$this->getPhpdocReturnType()."\n";
        $code .= '*/'."\n";
        $code .= 'public function get'.ucfirst($key).'($language = null)'."\n";
        $code .= '{'."\n";
        $code .= "\t".'$data = \\Sylphen\\DataBridgeBundle\\lib\\Pim\\FieldType\\CalculatedValueDataQuerySelector::getResult(\''.str_replace('\'', '\\\'', $this->getDataQuerySelector()).'\', $this, [\'fieldName\' => \''.$this->getName().'\']);'."\n\n";
        $code .= "\treturn ".'$data'.";\n";
        $code .= "}\n\n";

        return $code;
    }

    /**
     * {@inheritdoc}
     */
    public function doGetGetterCodeFieldcollection($fieldcollectionDefinition)
    {
        $key = $this->getName();

        $code = '';
        $code .= '/**'."\n";
        $code .= '* Get '.str_replace(['/**', '*/', '//'], '', $this->getName()).' - '.str_replace(['/**', '*/', '//'], '', $this->getTitle())."\n";
        $code .= '* @return '.$this->getPhpdocReturnType()."\n";
        $code .= '*/'."\n";
        $code .= 'public function get'.ucfirst($key).'()'."\n";
        $code .= '{'."\n";
        $code .= "\t".'$data = \\Sylphen\\DataBridgeBundle\\lib\\Pim\\FieldType\\CalculatedValueDataQuerySelector::getResult(\''.str_replace('\'', '\\\'', $this->getDataQuerySelector()).'\', $this, [\'fieldName\' => \''.$this->getName().'\']);'."\n\n";
        $code .= "\t".'return $data;'."\n";
        $code .= "}\n\n";

        return $code;
    }

    public function getCalculatorClass(): string
    {
        return CalculatedValueCalculator::class;
    }
}