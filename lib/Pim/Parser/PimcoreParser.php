<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ArrayIterator;
use Sylphen\DataBridgeBundle\Controller\ImportconfigController;
use Sylphen\DataBridgeBundle\EventListener\ObjectDidNotChangeException;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBagComposite;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBagObject;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParenthesisParser;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Export;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use EmptyIterator;
use Exception;
use InvalidArgumentException;
use Iterator;
use IteratorIterator;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Cache\Runtime;
use OpenDxp\Db;
use OpenDxp\File;
use OpenDxp\Helper\LongRunningHelper;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CustomResourcePersistingInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\LazyLoadingSupportInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ResourcePersistenceAwareInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Folder;
use OpenDxp\Model\DataObject\Listing;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\Dependency;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\AbstractElement;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Model\Tool\Lock;
use OpenDxp\Model\Version;
use OpenDxp\Tool;
use Psr\Log\LoggerInterface;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use ReflectionUnionType;
use Throwable;

/**
 * Parses CSV-Files according to given configuration
 */
class PimcoreParser implements Parser {
    use IteratableParser;

	private $config;
	private $targetConfig;
	private $logger;

	/** @var AbstractListing */
	private $listing;

	/** @var int */
	private $count;

	/** @var ItemMoldBuilder */
	private $helper;

	private $sqlCondition;

	private $importer;

	/** @var bool */
	private $force = false;

	private static $processedElementIds;

    private static $dataQuerySelectorPointsToCache = [];

    private $keyFields;
    private $fieldsWithoutKeyFields;

    private static $modificationDateTranslations;

	public function __construct(array $config, LoggerInterface $logger, ItemMoldBuilder $helper, array $targetConfig = [])
    {
        $this->config = $config;
        if (empty($this->config['fields'])) {
            throw new \Exception('Please configure raw data fields');
        }

        $this->targetConfig = $targetConfig;

        $this->logger = $logger;
        $this->helper = $helper;

        AbstractObject::setHideUnpublished(false);

        if (!empty($this->config['inheritanceEnabled'])) {
            Helper::useInheritance(true);
        } else {
            Helper::useInheritance(false);
        }

        foreach ($this->config['fields'] as &$dataportField) {
            if ($dataportField['parameters'] && substr($dataportField['parameters'], 0, 6) !== '.:.:.:' && !preg_match('/^\{\{\s*(\S+?( +\S+?)*?)\s*\}\}$/', $dataportField['parameters']) && !in_array($dataportField['parameters'], ['__updated', '__index', '__request', '__count'], true)) {
                $parts = \str_getcsv($dataportField['parameters'], ':', '"');
                if (count($parts) >= 3) {
                    if ($this->helper->getClass($parts[0]) !== null) {
                        if (strtolower(substr($parts[1], 0, 5)) !== 'getby') {
                            // if we get here, first data query selector part also exists as data object class -> check if field for $object with same name exists -> field has higher priority
                            $fieldDefinition = Importer::getFieldDefinition($this->getItemMold(), $parts[0]);
                            if (!$fieldDefinition->getLocked()) {
                                $dataportField['parameters'] = '.:.:.:'.$dataportField['parameters'];
                            }
                        }
                    } else {
                        $dataportField['parameters'] = '.:.:.:'.$dataportField['parameters'];
                    }
                } else {
                    $dataportField['parameters'] = '.:.:.:'.$dataportField['parameters'];
                }
            }
        }

        unset($dataportField);
	}

    private function getKeyFields() {
        if($this->keyFields === null) {
            $this->keyFields = [];
            if (!$this->getForce()) {
                foreach ($this->config['fields'] as $fieldIndex => $field) {
                    if (!empty($field['exportKey'])) {
                        $this->keyFields[$fieldIndex] = $field;
                    }
                }
            }
        }

        return $this->keyFields;
    }

	private function getValue(AbstractModel $object, $dataportField) {
        if(empty($dataportField['parameters'])) {
            return null;
        }

        foreach($this->config['parameters'] as $parameterName => $parameterValue) {
            if(!property_exists($object, $parameterName)) {
                @$object->$parameterName = $parameterValue;
            }
        }

        $dataportField['parameters'] = preg_replace_callback('/(.?)\{\{\s*(\S+?( +\S+?)*?)\s*\}\}(.?)/', function($matches) use ($object) {
            if($matches[1] === '' && $matches[4] === '') {
                return '.:.:.:'.$matches[2];
            }

            return $this->getImporter()->replaceObjectIdentifier($matches[0], $object);
        }, $dataportField['parameters']);

        if($dataportField['parameters'] === '__index') {
            return $this->listing->key();
        }

        if ($dataportField['parameters'] === '__request') {
            return json_encode(array_diff_key(Helper::getRequest()->attributes->all(), array_flip(['_opendxp_context', '_opendxp_frontend_request'])), \JSON_UNESCAPED_SLASHES);
        }

        try {
            if($dataportField['parameters'] === '__count') {
                return $this->count();
            }

            $value = $this->getImporter()->getObjectByIdentifier($dataportField['parameters'], $object);

            if ($value === '' && strpos($dataportField['parameters'], '.:.:.:') === 0) {
                $getter = 'get'.substr($dataportField['parameters'], strlen('.:.:.:'));
                try {
                    $envValue = $this->config['parameters']->$getter();
                    if ($envValue) {
                        $value = $envValue;
                    }
                } catch (\Exception $e) {
                }
            }

            if (is_bool($value)) {
                $value = (int)$value;
            } elseif (is_scalar($value)) {
                $value = (string)$value;
            } else {
                if (\is_object($value)) {
                    $value = (array)$value;
                }
                $value = json_encode($value, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }

            $this->logger->info('Result of data query selector "'.$dataportField['parameters'].'" is '.$this->getImporter()::getLogOutput($value));
            return $value;
        } catch(\Throwable $e) {
            $this->logger->alert('Parsing error for field "'.$dataportField['parameters'].'": '.$e);
            return null;
        }
    }

    /**
     * @return Importer
     */
    private function getImporter() {
	    if($this->importer === null) {
            /** @var ImporterInterface $importer */
            $this->importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);

            if (\method_exists($this->importer, 'setDataport') && \method_exists($this->importer, 'getObjectByIdentifier')) {
                $table = Dataport::getInstance();
                $dataport = $table->get($this->config['dataportId']);
                $this->importer->setDataport($dataport);
            }
        }

        return $this->importer;
    }

    public function getListing() {
	    if($this->listing === null) {
	        if(empty($this->config['sourceClass'])) {
	            $this->logger->notice('No source data class selected');
                return new ArrayMapIterator([], static function() {});
            }

            $itemMold = $this->getItemMold();

            $this->listing = $itemMold->getList([
                'unpublished' => true,
                'objectTypes' => [
                    AbstractObject::OBJECT_TYPE_OBJECT,
                    AbstractObject::OBJECT_TYPE_VARIANT
                ],
                'locale' => Tool::getDefaultLanguage()
            ]);

            if (empty($this->config['inheritanceEnabled']) && $itemMold instanceof Concrete && $itemMold->getClass()->getAllowInherit()) {
                $listingClass = 'Sylphen\\DataBridgeBundle\\Listing_'.preg_replace('/\W+/', '_', get_class($itemMold).'_'.$this->config['dataportId']);
                $listingClassFilePath = Installer::getCachePath().'/Listing_'.preg_replace('/\W+/', '_', get_class($itemMold).'_'.$this->config['dataportId']).'.php';

                if (!class_exists($listingClass, false) && \file_exists($listingClassFilePath)) {
                    @include($listingClassFilePath);
                }

                if (!class_exists($listingClass, false)) {
                    $classCode = '<?php
namespace '.str_replace('/', '\\', dirname(str_replace('\\', '/', $listingClass))).';

class '.basename(str_replace('\\', '/', $listingClass)).' extends \\'.get_class($this->listing).' {
    /**
     * @param string|null $key
     * @param bool $forceDetection
     *
     * @throws \Exception
     */
     public function initDao(?string $key = null, bool $forceDetection = false): void
    {
        $this->dao = new \\Sylphen\\DataBridgeBundle\\lib\\Pim\\Parser\\ListingDao();
        $this->dao->setModel($this);

        $this->dao->configure();

        if (method_exists($this->dao, \'init\')) {
            $this->dao->init();
        }
    }
}'.PHP_EOL.PHP_EOL;

                    file_put_contents($listingClassFilePath, $classCode, LOCK_EX);
                    include($listingClassFilePath);
                }

                $this->listing = new $listingClass([
                    'unpublished' => true,
                    'objectTypes' => [
                        AbstractObject::OBJECT_TYPE_OBJECT,
                        AbstractObject::OBJECT_TYPE_VARIANT
                    ],
                    'locale' => Tool::getDefaultLanguage()
                ]);
            }

            $sqlCondition = $this->getResource();

            $sqlCondition = preg_replace_callback('/LIMIT (\d+(,\d+)?)$/i', function($limitMatches) {
                if ($this->limit === null) {
                    $limitOffset = explode(',', $limitMatches[1]);
                    if(count($limitOffset) === 1) {
                        $this->limit = $limitOffset[0];
                    } elseif(count($limitOffset) >= 2) {
                        $this->limit = $limitOffset[1];
                        $this->offset = $limitOffset[0];
                    }
                }
                return '';
            }, $sqlCondition);

            $user = Helper::getUser();
            $sqlCondition = $this->transpileCondition($sqlCondition, $this->listing);

            if ($this->limit !== null) {
                $fields = $this->config['fields'];

                $countIsUsed = false;
                foreach ($fields as $field) {
                    if ($field['parameters'] === '__count') {
                        $countIsUsed = true;
                        break;
                    }
                }

                if(!$countIsUsed) {
                    $this->listing->setLimit($this->limit + $this->offset);
                }
            }

            if (!$user->isAdmin() && empty($this->config['autoImport'])) {
                $elementPaths = Service::findForbiddenPaths(Service::getElementType($itemMold), $user);

                $pathColumn = 'path';
                if ($itemMold instanceof Asset) {
                    $keyColumn = 'filename';
                } elseif ($itemMold instanceof PageSnippet) {
                    $keyColumn = '`key`';
                } else {
                    $keyColumn = '`'.Helper::prefixObjectSystemColumn('key').'`';
                    $pathColumn = Helper::prefixObjectSystemColumn('path');
                }

                $forbiddenPathSql = [];
                $allowedPathSql = [];
                foreach ($elementPaths['forbidden'] as $forbiddenPath => $allowedPaths) {
                    $exceptions = '';
                    $folderSuffix = '';
                    if ($allowedPaths) {
                        $exceptionsConcat = implode("%' OR CONCAT(".$pathColumn.",".$keyColumn.") LIKE '", $allowedPaths);
                        $exceptions = " OR (CONCAT(".$pathColumn.",".$keyColumn.") LIKE '".$exceptionsConcat."%')";
                        $folderSuffix = '/'; //if allowed children are found, the current folder is listable but its content is still blocked, can easily done by adding a trailing slash
                    }
                    $forbiddenPathSql[] = ' (CONCAT('.$pathColumn.','.$keyColumn.') NOT LIKE '.$this->listing->quote($forbiddenPath.$folderSuffix.'%').$exceptions.') ';
                }
                foreach ($elementPaths['allowed'] as $allowedPaths) {
                    $allowedPathSql[] = ' CONCAT('.$pathColumn.','.$keyColumn.') LIKE '.$this->listing->quote($allowedPaths.'%');
                }

                if ($allowedPathSql || $forbiddenPathSql) {
                    $forbiddenAndAllowedSql = ' AND (';
                    $forbiddenAndAllowedSql .= $allowedPathSql ? '( '.implode(' OR ', $allowedPathSql).' )' : '';

                    if ($forbiddenPathSql) {
                        //if $allowedPathSql "implosion" is present, we need `AND` in between
                        $forbiddenAndAllowedSql .= $allowedPathSql ? ' AND ' : '';
                        $forbiddenAndAllowedSql .= implode(' AND ', $forbiddenPathSql);
                    }
                    $forbiddenAndAllowedSql .= ' )';

                    if(strlen($forbiddenAndAllowedSql) > PimcoreDbRepository::getMaxAllowedPacket() * 0.9) {
                        $userIds = $user->getRoles();
                        $userIds[] = $user->getId();

                        if ($itemMold instanceof Concrete) {
                            $forbiddenAndAllowedSql = ' AND (
                        (SELECT `view` FROM users_workspaces_object WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(CONCAT('.Helper::prefixObjectSystemColumn('path').',`'.Helper::prefixObjectSystemColumn('key').'`),cpath)=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                            OR
                        (SELECT `view` FROM users_workspaces_object WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(cpath,CONCAT('.Helper::prefixObjectSystemColumn('path').',`'.Helper::prefixObjectSystemColumn('key').'`))=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                    )';
                        } elseif ($itemMold instanceof Asset) {
                            $forbiddenAndAllowedSql = ' AND (
                        (SELECT `view` FROM users_workspaces_asset WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(CONCAT(path,filename),cpath)=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                            OR
                        (SELECT `view` FROM users_workspaces_asset WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(cpath,CONCAT(path, filename))=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                    )';
                        } elseif ($itemMold instanceof Document) {
                            $forbiddenAndAllowedSql = ' AND (
                        (SELECT `view` FROM users_workspaces_document WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(CONCAT(path,`key`),cpath)=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                            OR
                        (SELECT `view` FROM users_workspaces_document WHERE userId IN ('.implode(',', $userIds).') AND LOCATE(cpath,CONCAT(path, `key`))=1 ORDER BY LENGTH(cpath) DESC, FIELD(userId, '.$user->getId().') DESC, `view` DESC LIMIT 1)=1
                    )';
                        }
                    }

                    $sqlCondition = ($sqlCondition ? '('.$sqlCondition.') ' : '1').$forbiddenAndAllowedSql;
                }
            }

            $this->listing->setCondition($sqlCondition);

            // optimize memory usage -> do not load all elements into memory
            $idList = $this->listing->loadIdList();

            if($user instanceof OpenDxp\Model\User && !$user->isAdmin() && empty($this->config['autoImport']) && count($idList) === 0) {
                $elementListing = $itemMold->getList([
                    'unpublished' => true,
                    'objectTypes' => [
                        AbstractObject::OBJECT_TYPE_OBJECT,
                        AbstractObject::OBJECT_TYPE_VARIANT
                    ],
                    'locale' => Tool::getDefaultLanguage()
                ]);

                $elementSqlCondition = $this->config['file'];
                if ($this->sqlCondition) {
                    $elementSqlCondition = $this->getImporter()->replaceObjectIdentifier($elementSqlCondition, $this->config['parameters'] ?? null);
                    if (trim($elementSqlCondition)) {
                        $elementSqlCondition = '('.$elementSqlCondition.') AND ('.$this->sqlCondition.')';
                    } else {
                        $elementSqlCondition = $this->sqlCondition;
                    }
                }

                $elementSqlCondition = $this->getImporter()->replaceObjectIdentifier($elementSqlCondition, $this->config['parameters'] ?? null);

                if (!empty($elementSqlCondition)) {
                    $elementSqlCondition = $this->transpileCondition($elementSqlCondition, $elementListing);
                    $elementListing->setCondition($elementSqlCondition);
                }
                $elementListing->setLimit(1);

                if(count($elementListing->loadIdList()) > 0) {
                    $this->logger->warning('Requesting user does not have "view" permission for matching elements');
                }
            }

            $this->listing = new ArrayMapIterator($idList, function($id) use ($itemMold, $idList) {
                if (!empty($this->config['draftVersions'])) {
                    $itemMold->setId($id);
                    $latestVersion = $this->getImporter()->getLatestVersion($itemMold);
                    if($latestVersion !== null) {
                        return $latestVersion;
                    }
                }

                if ($itemMold instanceof Concrete && !$this->targetConfig['compatibilityMode']) {
                    if (method_exists($this->logger, 'disablePimcoreLogger')) {
                        $this->logger->disablePimcoreLogger();
                    }
                    try {
                        $object = Helper::getFromCache(Service::getElementType($itemMold).'_'.$id);
                        if ($object) {
                            return $object;
                        }
                    } finally {
                        if (method_exists($this->logger, 'enablePimcoreLogger')) {
                            $this->logger->enablePimcoreLogger();
                        }
                    }

                    $itemClass = 'Sylphen\\DataBridgeBundle\\Dao_'.preg_replace('/\W+/', '_', get_class($itemMold).'_'.$this->config['dataportId']);
                    $itemClassFilePath = Installer::getCachePath().'/Dao_'.preg_replace('/\W+/', '_', get_class($itemMold).'_'.$this->config['dataportId']).'.php';

                    if (!class_exists($itemClass, false) && \file_exists($itemClassFilePath)) {
                        if(filemtime($itemClassFilePath) >= $itemMold->getClass()->getModificationDate()) {
                            @include($itemClassFilePath);
                        }
                    }

                    if (!class_exists($itemClass, false)) {
                        $classCode = '<?php
namespace '.str_replace('/', '\\', dirname(str_replace('\\', '/', $itemClass))).';

#[AllowDynamicProperties]
class '.basename(str_replace('\\', '/', $itemClass)).' extends \\'.get_class($itemMold).' {
    private $elementId;
    private static $cachedData = [];
    private static $idList;
    
    public function __construct($elementId = null, $idList = []) {
        $this->elementId = $elementId;
        self::$idList = $idList;
        
        if($elementId) {
            if(!isset(self::$cachedData[$elementId])) {
                self::$cachedData = [];
                $idListIndex = array_search($elementId, self::$idList);
                $idList = array_slice(self::$idList, $idListIndex, 1000);
                
                $systemData = \\'.PimcoreDbRepository::class.'::getInstance()->findInSql("SELECT * FROM objects WHERE '.Helper::prefixObjectSystemColumn('id').' IN (".rtrim(str_repeat(\'?,\', count($idList)), \',\').")", $idList);
                
                foreach($systemData as $elementSystemData) {
                    self::$cachedData[$elementSystemData[\''.Helper::prefixObjectSystemColumn('id').'\']] = $elementSystemData;
                }
            }
    
            if (!empty(self::$cachedData[$elementId][\''.Helper::prefixObjectSystemColumn('id').'\'])) {
                $this->setValues(array_intersect_key(self::$cachedData[$elementId], array_flip(\\'.Helper::class.'::getSystemFields())));
            }
        }
    }'.PHP_EOL.PHP_EOL;
                        $reflectionClass = new ReflectionClass($itemMold);
                        foreach ($itemMold->getClass()->getFieldDefinitions() as $fieldDefinition) {
                            if ((!$fieldDefinition instanceof CustomResourcePersistingInterface && !$fieldDefinition instanceof ResourcePersistenceAwareInterface) || ($fieldDefinition instanceof LazyLoadingSupportInterface && $fieldDefinition->getLazyLoading())) {
                                continue;
                            }

                            try {
                                $reflectionMethod = $reflectionClass->getMethod('get'.ucfirst($fieldDefinition->getName()));
                            } catch(\ReflectionException $e) {
                                continue;
                            }

                            $typeDeclaration = '';
                            $returnType = $reflectionMethod->getReturnType();
                            if ($returnType) {
                                $typeDeclaration = ':'.($returnType->allowsNull() ? '?' : '').(!in_array($returnType->getName(), ['string', 'array', 'int', 'float', 'bool', 'self', 'parent', 'iterable', 'object', 'static']) ? '\\' : '').$returnType->getName();
                            }

                            $getterArgs = [];
                            foreach ($reflectionMethod->getParameters() as $parameter) {
                                $getterArg = '$'.$parameter->getName();
                                try {
                                    $getterArg .= '='.var_export($parameter->getDefaultValue(), true);
                                } catch (ReflectionException $e) {
                                }
                                $getterArgs[] = $getterArg;
                            }

                            $classCode .= '
    private $initialized_'.$fieldDefinition->getName().' = false;
    
    public function get'.ucfirst($fieldDefinition->getName()).'('.implode(',', $getterArgs).')'.$typeDeclaration.' {
        $result = null;
        if(!$this->initialized_'.$fieldDefinition->getName().') {
            $this->initialized_'.$fieldDefinition->getName().' = true;
            
            $fieldDefinition = $this->getClass()->getFieldDefinition(\''.$fieldDefinition->getName().'\');';
                            if ($fieldDefinition instanceof CustomResourcePersistingInterface) {
                                $classCode .= '
            $params = [
                \'context\' => [
                    \'object\' => $this,
                ],
                \'owner\' => $this,
                \'fieldname\' => \''.$fieldDefinition->getName().'\',
            ];
            $value = $fieldDefinition->load($this, $params);
            if ($value === 0 || !empty($value)) {
                $this->setValue(\''.$fieldDefinition->getName().'\', $value);
            }';
                            } elseif ($fieldDefinition instanceof ResourcePersistenceAwareInterface) {
                                $columns = null;
                                if (is_array($fieldDefinition->getColumnType())) {
                                    $columns = array_map(static function ($column) use ($fieldDefinition) {
                                        return $fieldDefinition->getName().'__'.$column;
                                    }, array_keys($fieldDefinition->getColumnType()));
                                }

                                $classCode .= '
                                if(!array_key_exists(\''.($columns[0] ?? $fieldDefinition->getName()).'\', self::$cachedData[$this->elementId])) {
                                    $idListIndex = array_search($this->elementId, self::$idList);
                                    $idList = array_slice(self::$idList, $idListIndex, 1000);
                                    $data = \\'.PimcoreDbRepository::class.'::getInstance()->findInSql(\'SELECT oo_id,`'.implode('`,`', $columns ?? [$fieldDefinition->getName()]).'` FROM object_'.($fieldDefinition instanceof Data\Wysiwyg ? 'store':'query').'_'.$itemMold->getClassId().' WHERE oo_id IN (\'.rtrim(str_repeat(\'?,\', count($idList)), \',\').\')\', $idList);
                
                                    foreach($data as $elementData) {';
                                    foreach($columns ?? [$fieldDefinition->getName()] as $column) {
                                            $classCode .= '
                                        self::$cachedData[$elementData[\'oo_id\']][\''.$column.'\'] = $elementData[\''.$column.'\'];';
                                        }
                                      $classCode .= '  
                                    }
                                }';

                                if (is_array($columns)) {
                                    $classCode .= '
            $multidata = [';
                                    foreach ($columns as $column) {
                                        $classCode .= '
                \''.$column.'\' => self::$cachedData[$this->elementId][\''.$column.'\'] ?? null,'.PHP_EOL;
                                    }
                                    $classCode .= '
            ];
            
            $this->setValue(\''.$fieldDefinition->getName().'\', $fieldDefinition->getDataFromResource($multidata));'.PHP_EOL;
                                } elseif($fieldDefinition instanceof Data\Multiselect) {
                                    $classCode .= '
            $this->setValue(\''.$fieldDefinition->getName().'\', $fieldDefinition->getDataFromResource(!empty(self::$cachedData[$this->elementId][\''.$fieldDefinition->getName().'\']) ? trim(self::$cachedData[$this->elementId][\''.$fieldDefinition->getName().'\'], \',\') : null, $this, [
                \'owner\' => $this,
                \'fieldname\' => \''.$fieldDefinition->getName().'\',
            ]));'.PHP_EOL;
                                } elseif ($fieldDefinition instanceof Data\Table) {
                                    $classCode .= '
            $this->setValue(\''.$fieldDefinition->getName().'\', array_map(function($row) { return str_getcsv($row, \'|\'); }, explode("\n", self::$cachedData[$this->elementId][\''.$fieldDefinition->getName().'\'] ?? \'\')));'.PHP_EOL;
                                } else {
                                    $classCode .= '
            $this->setValue(\''.$fieldDefinition->getName().'\', $fieldDefinition->getDataFromResource(self::$cachedData[$this->elementId][\''.$fieldDefinition->getName().'\'] ?? null, $this, [
                \'owner\' => $this,
                \'fieldname\' => \''.$fieldDefinition->getName().'\',
            ]));'.PHP_EOL;
                                }
                            }
                            $classCode .= '
        }
        return parent::get'.ucfirst($fieldDefinition->getName()).'(...func_get_args());
    }';

                            $reflectionMethod = $reflectionClass->getMethod('set'.ucfirst($fieldDefinition->getName()));

                            $setterArgs = [];
                            foreach ($reflectionMethod->getParameters() as $parameter) {
                                $type = $parameter->getType();
                                $setterArg = '';
                                if ($type) {
                                    $types = $type instanceof ReflectionUnionType
                                        ? $type->getTypes()
                                        : [$type];

                                    $types = array_map(static function($type) {
                                        return ($type->allowsNull() ? '?' : '').(in_array($type->getName(), ['string', 'array', 'int', 'float', 'bool', 'self', 'parent', 'iterable', 'object', 'static'], true) ? '' : '\\').$type->getName();
                                    }, $types);

                                    $setterArg .= implode('|', $types).' ';
                                }

                                $setterArg .= '$'.$parameter->getName();
                                try {
                                    $setterArg .= '='.var_export($parameter->getDefaultValue(), true);
                                } catch (ReflectionException $e) {
                                }
                                $setterArgs[] = $setterArg;
                            }

                            $returnType = $reflectionMethod->getReturnType();
                            $typeDeclaration = '';
                            if ($returnType) {
                                $typeDeclaration = ':'.($returnType->allowsNull() ? '?' : '').(!in_array($returnType->getName(), ['string', 'array', 'int', 'float', 'bool', 'self', 'parent', 'iterable', 'object', 'static']) ? '\\' : '').$returnType->getName();
                            }
                            $classCode .= '
    public function set'.ucfirst($fieldDefinition->getName()).'('.implode(',', $setterArgs).')'.$typeDeclaration.' {
        parent::set'.ucfirst($fieldDefinition->getName()).'(...func_get_args());
        $this->initialized_'.$fieldDefinition->getName().' = true;
        return $this;
    }'.PHP_EOL.PHP_EOL;
                        }
                        $classCode .= '
                        
    public function __getRawRelationData(): array
    {
        if ($this->__rawRelationData === null) {
            if(!array_key_exists(\'__rawRelationData\', self::$cachedData[$this->elementId])) {
                $idListIndex = array_search($this->elementId, self::$idList);
                $idList = array_slice(self::$idList, $idListIndex, 1000);
                $data = \\'.PimcoreDbRepository::class.'::getInstance()->findInSql(\'SELECT * FROM object_relations_' .$itemMold->getClassId().' WHERE src_id IN (\'.rtrim(str_repeat(\'?,\', count($idList)), \',\').\')\', $idList);
                
                foreach($idList as $elementId) {
                    self::$cachedData[$elementId][\'__rawRelationData\'] = [];
                }
                
                foreach($data as $elementData) {
                    self::$cachedData[$elementData[\'src_id\']][\'__rawRelationData\'][] = $elementData;
                }
            }
            
            $this->__rawRelationData = self::$cachedData[$this->elementId][\'__rawRelationData\'] ?? [];
        }

        return $this->__rawRelationData;
    }
}';
                        file_put_contents($itemClassFilePath, $classCode, LOCK_EX);
                        include($itemClassFilePath);
                    }

                    return new $itemClass($id, $idList);
                }

                return $itemMold::getById($id);
            });
            $this->listing->rewind();
        }

	    return $this->listing;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        // we have to use goto here and cannot call $this->current() because otherwise there could be "Too much recursion" error
        start:
        if(!$this->getListing()->valid()) {
            $this->gotoNextImportResource();
            return null;
        }

        try {
            $object = $this->getListing()->current();
        } catch(\Throwable $e) {
            $object = null;
        }

        if (!$object instanceof ElementInterface || $this->position < $this->offset) {
            $this->getListing()->next();
            $this->next();

            $object = $this->getListing()->current();

            if ($object || $this->gotoNextImportResource()) {
                goto start;
            } else {
                $this->gotoNextImportResource();
                return null;
            }
        }

        self::$processedElementIds[$this->getLockKey($object)] = true;

        $objectElementType = Service::getElementType($object);
        $updated = $object->getModificationDate();
        if (empty($this->targetConfig['itemClass']) && $updated < time()) {
            $updated = self::getGreatestModificationDate($object);
        }

        $updated = max($updated, self::getLastModificationDateTranslations());

        $item = [];
        $fields = $this->config['fields'];

        foreach ($fields as $fieldIndex => $field) {
            if($field['parameters'] === '__updated') {
                $item[$fieldIndex] = $updated;
                continue;
            }
            $item[$fieldIndex] = $this->getValue($object, $field);
        }

        if (!empty($this->config['incrementalExport']) && empty($this->targetConfig['itemClass'])) {
            $allFieldsHash = \Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer::getHash($item);
            if(!$this->getForce()) {
                $rawDataDidNotChangeSinceLastExport = PimcoreDbRepository::getInstance()->findOneInSql(
                    'SELECT 1 FROM properties WHERE cid = ? AND ctype = ? AND name = ? AND data = ?',
                    [$object->getId(), $objectElementType, Importer::HASH_PROP_PREFIX.$this->config['dataportId'].'_data', $allFieldsHash]
                );
                if ($rawDataDidNotChangeSinceLastExport) {
                    $this->logger->info('Skipping object '.$object->getFullPath().' (#'.$object->getId().') because raw data has not changed since last successful export');
                    $this->getListing()->next();
                    goto start;
                }
            }


            $property = new OpenDxp\Model\Property();
            $property->setCid($object->getId());
            $property->setCtype($objectElementType);
            $property->setType('text');
            $property->setName(Importer::HASH_PROP_PREFIX.$this->config['dataportId'].'_data');
            $property->setData($allFieldsHash);
            $property->setCpath($object->getRealFullPath());
            $property->setInheritable(false);
            $property->setInherited(false);
            PimcoreDbRepository::retry(static function () use ($property) {
                $property->save();
            });
        }

        if (!empty($item)) {
            $item['__updated'] = $updated;
        }

        $this->current = $item;

        $this->getListing()->next();

        $memoryLimit = Helper::getMemoryLimit();
        if($memoryLimit > 0 && memory_get_usage() > $memoryLimit * 0.8) {
            if (method_exists(OpenDxp::class, 'deleteTemporaryFiles')) {
                OpenDxp::deleteTemporaryFiles();
            }

            PimcoreDbRepository::clearPreparedStatements();
            OpenDxp::getContainer()->get(LongRunningHelper::class)->cleanUp();
            Serializer::clearCache();
        }

        return $this->current;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        if($this->count === null) {
            $this->count = $this->getListing()->count();
        }

        return $this->count;
    }

    public function setSourceFile($file) {
        $this->sqlCondition = $file;
        $this->listing = null;
    }

    public function getFileConditionFromObject(ElementInterface $object, $addDataportCondition = true, $tryToSkip = true) {
	    if(!empty($this->targetConfig['itemClass']) && isset(self::$processedElementIds[$this->getLockKey($object)])) {
	        return null;
        }

        if ($tryToSkip && !empty($this->targetConfig['itemClass'])) {
            $latestManuallySavedVersionId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM versions USE INDEX(ctype_cid) WHERE ctype=? AND cid=? AND note NOT LIKE ? ORDER BY id DESC LIMIT 1', [Service::getElementType($object), $object->getId(), 'Dataport: %']);
            if ($latestManuallySavedVersionId) {
                $dataportAlreadyExecuted = PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM versions WHERE ctype=? AND cid=? AND id > ? AND note LIKE ? LIMIT 1', [Service::getElementType($object), $object->getId(), $latestManuallySavedVersionId, 'Dataport: '.$this->config['dataportId'].'\n%']);
                if($dataportAlreadyExecuted) {
                    return null;
                }
            }
        }

        $conditions = $this->getConditions($object, $tryToSkip);

        if(count($conditions) === 0) {
            throw new ObjectDidNotChangeException('Object did not change');
        }

        $condition = implode(' OR ', $conditions);

        $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
        $sqlCondition = $condition;

        if ($addDataportCondition) {
            if($this->sqlCondition && (substr(trim($this->sqlCondition), 0, 2) !== '/*' || substr(trim($this->sqlCondition), -2) !== '*/')) {
                $this->sqlCondition = trim($importer->replaceObjectIdentifier($this->sqlCondition, $object));
                if($this->sqlCondition) {
                    $sqlCondition = '('.$sqlCondition.') AND ('.$this->sqlCondition.')';
                }
            } elseif($this->config['file'] && (substr(trim($this->config['file']), 0, 2) !== '/*' || substr(trim($this->config['file']), -2) !== '*/')) {
                $this->config['file'] = trim($importer->replaceObjectIdentifier($this->config['file'], $object));
                if($this->config['file']) {
                    $sqlCondition = '('.$sqlCondition.') AND ('.$this->config['file'].')';
                }
            }
        }

        $itemMold = $this->getItemMold();

        $originalSqlCondition = $sqlCondition;
        $sqlConditions = [];

        if (preg_match('/id IN\s*\(([\d,]+)\)/i', $sqlCondition, $matches)) {
            $ids = explode(',', $matches[1]);

            foreach (array_chunk($ids, 10000) as $idChunk) {
                $sqlConditions[] = preg_replace('/id IN\s*\(([\d,]+)\)/i', 'id IN ('.implode(',', $idChunk).')', $sqlCondition);
            }
        } else {
            $sqlConditions = [$sqlCondition];
        }

        $elementIds = [[]];
        foreach($sqlConditions as $sqlCondition) {
            $listing = $itemMold->getList(
                [
                    'unpublished' => true,
                    'objectTypes' => [
                        AbstractObject::OBJECT_TYPE_OBJECT,
                        AbstractObject::OBJECT_TYPE_VARIANT
                    ],
                    'locale' => OpenDxp\Tool::getDefaultLanguage()
                ]
            );

            $sqlCondition = $importer->replaceObjectIdentifier($sqlCondition, $this->config['parameters'] ?? null);

            $sqlCondition = $this->transpileCondition($sqlCondition, $listing);

            $listing->setCondition($sqlCondition);

            $itemIds = $listing->loadIdList();
            if (!empty($this->targetConfig['itemClass'])) {
                foreach ($itemIds as $itemId) {
                    // lock element in case "optimize inheritance" feature is used -> to not import the same children again and again
                    self::$processedElementIds[$itemId] = 1;
                }
            }

            if (count($itemIds) > 0) {
                if ($object instanceof Folder || $object instanceof Asset\Folder || $object instanceof Document\Folder) {
                    return $originalSqlCondition;
                }

                if ($tryToSkip && !empty($this->config['incrementalExport']) && empty($this->targetConfig['itemClass'])) {
                    $rawDataFieldIsAlsoMapped = false;
                    if(!empty($this->targetConfig['itemClass'])) {
                        $dataQuerySelectorFieldNames = [];
                        foreach ($this->config['fields'] as $field) {
                            $dataQuerySelectorParts = explode(':', preg_replace('/^.:.:.:/', '', $field['parameters']));
                            $dataQuerySelectorFieldNames[] = $dataQuerySelectorParts[0];
                        }

                        if ($dataQuerySelectorFieldNames) {
                            $rawDataFieldIsAlsoMapped = (bool)Fieldmapping::getInstance()->findOne(['dataportId = ?' => $this->config['dataportId'], 'fieldName IN (?)' => $dataQuerySelectorFieldNames]);
                        }
                    }

                    if($rawDataFieldIsAlsoMapped) {
                        $unchangedItemIds = [];
                    } else {
                        $elementType = Service::getElementType($itemMold);
                        $rawDataDidNotChangeSinceLastExport = PimcoreDbRepository::getInstance()->findInSql(
                            'SELECT cid, data FROM properties WHERE cid IN (?) AND ctype = ? AND name = ?',
                            [$itemIds, $elementType, Importer::HASH_PROP_PREFIX.$this->config['dataportId'].'_data']
                        );

                        $unchangedItemIds = [];
                        foreach ($rawDataDidNotChangeSinceLastExport as $potentiallyUnchangedItem) {
                            $potentiallyUnchangedElement = Service::getElementById($elementType, $potentiallyUnchangedItem['cid']);
                            if (!$potentiallyUnchangedElement instanceof $itemMold) {
                                $unchangedItemIds[] = $potentiallyUnchangedElement['cid'];
                                continue;
                            }

                            $updated = self::getGreatestModificationDate($potentiallyUnchangedElement);

                            $rawItemData = [];
                            foreach ($this->config['fields'] as $fieldIndex => $field) {
                                if ($field['parameters'] === '__updated') {
                                    $rawItemData[$fieldIndex] = $updated;
                                    continue;
                                }
                                $rawItemData[$fieldIndex] = $this->getValue($potentiallyUnchangedElement, $field);
                            }

                            $allFieldsHash = \Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer::getHash($rawItemData);

                            if ($allFieldsHash === $potentiallyUnchangedItem['data']) {
                                $unchangedItemIds[] = $potentiallyUnchangedItem['cid'];
                            }
                        }
                    }

                    $elementIds[] = array_diff($itemIds, $unchangedItemIds);
                } else {
                    $elementIds[] = $itemIds;
                }
            }
        }

        $elementIds = array_merge(...$elementIds);
        if ($elementIds) {
            return (Service::getElementType($this->getItemMold()) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN ('.implode(',', $elementIds).')';
        }

        return null;
    }

    private function getConditions(ElementInterface $object, $tryToSkip = true) {
        $conditions = [];

        $itemMold = $this->getItemMold();
        $sourceClassElementType = Service::getElementType($itemMold);

        if ($object instanceof $itemMold) {
            // saved object is of same class as dataport source class
            // e.g. change data of same class item (e.g. accessories) -> update export
            // only fields in source class have to be checked for being changed

            if ($sourceClassElementType === 'asset') {
                $keyColumn = 'filename';
            } elseif ($sourceClassElementType === 'document') {
                $keyColumn = '`key`';
            } else {
                $keyColumn = '`'.Helper::prefixObjectSystemColumn('key').'`';
            }

            $parentId = $object->getParentId();

            $skipDependencies = false;
            if (empty($this->config['inheritanceEnabled'])) {
                $skipDependencies = strpos($this->getResource(), 'parentId') === false && strpos($this->getResource(), 'path') === false;
                foreach ($this->config['fields'] as $dataQuerySelector) {
                    $dataQuerySelector = preg_replace('/^.:.:.:/', '', $dataQuerySelector['parameters']);

                    $dataQuerySelectorParts = \str_getcsv($dataQuerySelector, ':');
                    if ($this->dataQuerySelectorPointsTo($dataQuerySelectorParts, get_class($itemMold), get_class($object))) {
                        $skipDependencies = false;
                        break;
                    }
                }
            }

            $dependentElementIDs = [$object->getId()];
            $dependentElementPaths = [];

            if(!$skipDependencies) {
                $dependentElementPaths = [$object->getFullPath().'/'];
                do {
                    $dependentElementIDs[] = $parentId;
                    $parentId = PimcoreDbRepository::getInstance()->findOneInSql(
                        'SELECT '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId 
                        FROM '.$sourceClassElementType.'s 
                        WHERE '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?',
                        [$parentId]
                    );
                } while ($parentId);

                $dependencies = PimcoreDbRepository::getInstance()->findInSql(
                    'SELECT sourceid, CONCAT('.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').', '.$keyColumn.') AS path
                    FROM dependencies 
                    '.($sourceClassElementType === 'object' ? 'INNER JOIN objects ON dependencies.sourceid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.targettype="object" AND objects.'.Helper::prefixObjectSystemColumn('classId').'=\''.$itemMold->getClassId().'\'' : '').'
                    '.($sourceClassElementType === 'asset' ? 'INNER JOIN assets ON dependencies.sourceid=assets.id AND dependencies.targettype="asset"' : '').'
                    '.($sourceClassElementType === 'document' ? 'INNER JOIN documents ON dependencies.sourceid=documents.id AND dependencies.targettype="document"' : '').'
                    WHERE targetid = ? AND targettype=? AND sourcetype = ?',
                    [$object->getId(), $sourceClassElementType, $sourceClassElementType]
                );
                foreach ($dependencies as $dependency) {
                    // check if element already covered by path
                    if (strpos($dependency['path'], $object->getFullPath()) !== 0) {
                        $dependentElementIDs[] = $dependency['sourceid'];
                    }

                    $dependentElementPaths[] = $dependency['path'].'/';
                }

                usort(
                    $dependentElementPaths,
                    static function ($a, $b) {
                        return mb_strlen($b) - mb_strlen($a);
                    }
                );

                $cntElementPaths = count($dependentElementPaths);
                for ($i = 0; $i < $cntElementPaths; $i++) {
                    for ($j = $i + 1; $j < $cntElementPaths; $j++) {
                        if (strpos($dependentElementPaths[$i], $dependentElementPaths[$j]) === 0) {
                            unset($dependentElementPaths[$i]);
                            continue 2;
                        }
                    }
                }
            }

            if (count($dependentElementPaths) > 0) {
                $dependentElementQueryParts = [];
                $dependentElementQueryParameters = [];
                foreach ($dependentElementPaths as $dependentElementPath) {
                    $dependentElementQueryParts[] = 'SELECT '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' FROM '.$sourceClassElementType.'s WHERE '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' LIKE ?';
                    $dependentElementQueryParameters[] = $dependentElementPath.'%';
                }

                $dependentElementIDs = array_merge($dependentElementIDs, PimcoreDbRepository::getInstance()->findColumnInSql('('.implode(') UNION (', $dependentElementQueryParts).')', $dependentElementQueryParameters));
            }

            if (count($dependentElementIDs) > 0) {
                $conditions[] = ($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN ('.implode(',', array_map('intval', array_unique($dependentElementIDs))).')';
            }
        } elseif ($object->getType() !== 'folder') {
            // saved object of another class than source data class
            // e.g. save category -> update product export

            $skipAutoImport = false;

            // otherwise removing raw data would not work
            if ($tryToSkip) {
                $skipAutoImport = true;
                foreach ($this->config['fields'] as $dataQuerySelector) {
                    $dataQuerySelector = preg_replace('/^.:.:.:/', '', $dataQuerySelector['parameters']);

                    $dataQuerySelectorParts = \str_getcsv($dataQuerySelector, ':');
                    if ($this->dataQuerySelectorPointsTo($dataQuerySelectorParts, get_class($itemMold), get_class($object))) {
                        $skipAutoImport = false;
                        break;
                    }
                }
            }

            if (!$skipAutoImport) {
                $dependenciesQuery = '
                    SELECT sourceid FROM (
                        SELECT sourceid FROM dependencies
                        '.($sourceClassElementType === 'object' ? 'INNER JOIN objects ON dependencies.sourceid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.sourcetype=\'object\' AND objects.'.Helper::prefixObjectSystemColumn('classId').'=\''.$itemMold->getClassId().'\'' : '').'
                        '.($sourceClassElementType === 'asset' ? 'INNER JOIN assets ON dependencies.sourceid=assets.id AND dependencies.sourcetype=\'asset\'' : '').'
                        '.($sourceClassElementType === 'document' ? 'INNER JOIN documents ON dependencies.sourceid=documents.id AND dependencies.sourcetype=\'document\'' : '').'
                        WHERE targettype='.Db::get()->quote(Service::getElementType($object)).' AND targetid='.Db::get()->quote($object->getId()).'
                    UNION
                        SELECT targetid AS sourceid FROM dependencies
                        '.($sourceClassElementType === 'object' ? 'INNER JOIN objects ON dependencies.targetid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.targettype=\'object\' AND objects.'.Helper::prefixObjectSystemColumn('classId').'=\''.$itemMold->getClassId().'\'' : '').'
                        '.($sourceClassElementType === 'asset' ? 'INNER JOIN assets ON dependencies.targetid=assets.id AND dependencies.targettype=\'asset\'' : '').'
                        '.($sourceClassElementType === 'document' ? 'INNER JOIN documents ON dependencies.targetid=documents.id AND dependencies.targettype=\'document\'' : '').'
                        WHERE sourcetype='.Db::get()->quote(Service::getElementType($object)).' AND sourceid='.Db::get()->quote($object->getId()).'
                    UNION 
                        SELECT '.$sourceClassElementType.'s.'.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS sourceid
                        FROM '.$sourceClassElementType.'s 
                        WHERE '.$sourceClassElementType.'s.'.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' LIKE \''.str_replace('\'', '\\\'', rtrim($object->getRealFullPath(), '/')).'/%\'
                    ) t
                ';

                $dependentElementIDs = PimcoreDbRepository::getInstance()->findColumnInSql($dependenciesQuery);
                $parentId = $object->getParentId();
                do {
                    $dependentElementIDs[] = $parentId;
                    $parentId = PimcoreDbRepository::getInstance()->findOneInSql(
                        'SELECT '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId 
                        FROM '.$sourceClassElementType.'s 
                        WHERE '.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?',
                        [$parentId]
                    );
                } while ($parentId > 1);

                $conditions[] = '('.($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN ('.implode(',', array_map('intval', array_unique($dependentElementIDs))).'))';
            }
        } elseif (empty($this->targetConfig['itemClass']) || ($this->targetConfig['mode'] & ImportconfigController::MODE_EDIT)) {
            $skipAutoImport = false;

            // otherwise removing raw data would not work
            if ($tryToSkip) {
                $skipAutoImport = true;
                foreach ($this->config['fields'] as $dataQuerySelector) {
                    $dataQuerySelector = preg_replace('/^.:.:.:/', '', $dataQuerySelector['parameters']);

                    $dataQuerySelectorParts = \str_getcsv($dataQuerySelector, ':');
                    if ($this->dataQuerySelectorPointsTo($dataQuerySelectorParts, get_class($itemMold), get_class($object))) {
                        $skipAutoImport = false;
                        break;
                    }
                }
            }

            if (!$skipAutoImport) {
                $conditions[] = ($sourceClassElementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' LIKE \''.str_replace('\'', '\\\'', rtrim($object->getFullPath(), '/')).'/%\'';
                }
        }

        return $conditions;
    }

    public function gotoNextImportResource()
    {
        $this->current = null;
        return false;
    }

    /**
     * @param ElementInterface|int $objectId
     * @return string
     */
    public function getLockKey($objectId) {
        if($objectId instanceof ElementInterface) {
            $objectId = $objectId->getId();
        }
        return 'import-'.$this->config['dataportId'].'-'.$objectId;
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return bool
     */
    public function getForce(): bool
    {
        return $this->force;
    }

    /**
     * @param bool $force
     */
    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    public function transpileCondition($sqlCondition, AbstractListing $listing) {
        $sqlCondition = preg_replace('/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $sqlCondition);
        if(substr(trim($this->sqlCondition), 0, 2) === '/*' && substr(trim($this->sqlCondition), -2) === '*/') {
            return '';
        }

        $itemMold = $this->getItemMold();

        if (reset($this->config['fields'])['parameters'] === '__index' && preg_match('/id\s+IN\s+\((\d+(\s*,\s*\d+)*)\)/', $sqlCondition, $inIdMatches)) {
            $listing->setOrderKey('FIND_IN_SET('.($itemMold instanceof Concrete ? Helper::prefixObjectSystemColumn('id') : 'id').', '.$listing->quote($inIdMatches[1]).')', false);
        }

        if(strpos($sqlCondition, '__updated') !== false) {
            $__updatedSql = 'GREATEST(
                                '.$listing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('modificationDate').',
                                IFNULL(
                                    (SELECT 
                                    GREATEST(
                                        MAX(IFNULL(objects.'.Helper::prefixObjectSystemColumn('modificationDate').',0)),
                                        MAX(IFNULL(assets.modificationDate,0)),
                                        MAX(IFNULL(documents.modificationDate,0))
                                    )
                                    FROM dependencies
                                    LEFT JOIN objects ON dependencies.targetid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.targettype="object"
                                    LEFT JOIN assets ON dependencies.targetid=assets.id AND dependencies.targettype="asset"
                                    LEFT JOIN documents ON dependencies.targetid=documents.id AND dependencies.targettype="document"
                                    WHERE sourcetype="'.Service::getElementType($itemMold).'" AND sourceid='.$listing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('id').'),
                                    0
                                ),
                                IFNULL(
                                    (SELECT 
                                    GREATEST(
                                        MAX(IFNULL(objects.'.Helper::prefixObjectSystemColumn('modificationDate').',0)),
                                        MAX(IFNULL(assets.modificationDate,0)),
                                        MAX(IFNULL(documents.modificationDate,0))
                                    )
                                    FROM dependencies
                                    LEFT JOIN objects ON dependencies.sourceid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.sourcetype="object"
                                    LEFT JOIN assets ON dependencies.sourceid=assets.id AND dependencies.sourcetype="asset"
                                    LEFT JOIN documents ON dependencies.sourceid=documents.id AND dependencies.sourcetype="document"
                                    WHERE targettype="'.Service::getElementType($itemMold).'" AND targetid='.$listing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('id').'),
                                    0
                                )
                            )';
            $sqlCondition = str_replace('__updated', '(FROM_UNIXTIME('.$__updatedSql.'))', $sqlCondition);
        }

        if ($itemMold instanceof Concrete) {
            if (empty($this->config['inheritanceEnabled']) || !$itemMold->getClass()->getAllowInherit()) {
                preg_match_all('/([\w#]+)\s*LIKE\s*[\'"]%,?([^,]+),?%[\'"]/i', $sqlCondition, $likeMatches, PREG_SET_ORDER);
                foreach ($likeMatches as $likeMatch) {
                    $columnParts = explode('#', $likeMatch[1]);
                    $column = $columnParts[0];

                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                    if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        $valueParts = explode('|', $likeMatch[2]);
                        $relatedElementType = 'object';
                        if (count($valueParts) === 2) {
                            $relatedElementId = $valueParts[1];
                            $relatedElementType = $valueParts[0];
                        } else {
                            $relatedElementId = $valueParts[0];
                        }
                        try {
                            $replacementCondition = Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id = '.$relatedElementId.' AND type = '.Db::get()->quote($relatedElementType).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).(isset($columnParts[1]) ? ' AND position='.Db::get()->quote($columnParts[1]).'' : '').')';

                            $sqlCondition = str_replace($likeMatch[0], $replacementCondition, $sqlCondition);
                        } catch (Throwable $e) {
                        }
                    }
                }

                preg_match_all('/([\w#]+)\s*(IS NULL|IS NOT NULL)\s*/i', $sqlCondition, $likeMatches, PREG_SET_ORDER);
                foreach ($likeMatches as $likeMatch) {
                    $columnParts = explode('#', $likeMatch[1]);
                    $column = $columnParts[0];

                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                    if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        if(strtoupper($likeMatch[2]) === 'IS NULL') {
                            try {
                                $replacementCondition = Helper::prefixObjectSystemColumn('id').' NOT IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE src_id='.$listing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('id').' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).(isset($columnParts[1]) ? ' AND position='.Db::get()->quote($columnParts[1]).'' : '').')';

                                $sqlCondition = str_replace($likeMatch[0], $replacementCondition, $sqlCondition);
                            } catch (Throwable $e) {
                            }
                        } elseif (strtoupper($likeMatch[2]) === 'IS NOT NULL') {
                            try {
                                $replacementCondition = Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE src_id='.$listing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('id').' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).(isset($columnParts[1]) ? ' AND position='.Db::get()->quote($columnParts[1]).'' : '').')';

                                $sqlCondition = str_replace($likeMatch[0], $replacementCondition, $sqlCondition);
                            } catch (Throwable $e) {
                            }
                        }
                    }
                }
            }

            $localizedFields = $itemMold->getClass()->getFieldDefinition('localizedfields');
            $listLocale = null;
            if ($localizedFields instanceof Data\Localizedfields) {
                $languages = Tool::getValidLanguages();
                usort($languages, static function ($language1, $language2) {
                    return strlen($language2) <=> strlen($language1);
                });

                foreach ($languages as $language) {
                    if (stripos($sqlCondition, '#'.$language) !== false) {
                        if ($listLocale === null) {
                            $listing->setLocale($language);
                            $listLocale = $language;
                            $sqlCondition = str_ireplace('#'.$language, '', $sqlCondition);
                        } else {
                            throw new Exception('Currently it is not possible to use localized fields in different locales');
                        }
                    }
                }
            }

            $fieldDefinitions = $itemMold->getClass()->getFieldDefinitions();
            if ($localizedFields instanceof Data\Localizedfields) {
                $fieldDefinitions = array_merge($fieldDefinitions, $localizedFields->getFieldDefinitions());
            }

            foreach ($fieldDefinitions as $fieldDefinition) {
                if ($fieldDefinition instanceof Data\Objectbricks) {
                    $allowedBricks = $fieldDefinition->getAllowedTypes();
                    foreach ($allowedBricks as $allowedBrick) {
                        if (stripos($sqlCondition, $allowedBrick.'.') !== false) {
                            $listing->addObjectBrick($allowedBrick);
                        }
                    }
                } elseif($fieldDefinition instanceof Data\Fieldcollections) {
                    $allowedFieldCollections = $fieldDefinition->getAllowedTypes();
                    foreach ($allowedFieldCollections as $allowedFieldCollection) {
                        if (stripos($sqlCondition, $allowedFieldCollection.'.') !== false) {
                            $listing->addFieldCollection($allowedFieldCollection);
                        }
                    }
                } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations && (stripos($sqlCondition, $fieldDefinition->getName().'.') !== false || stripos($sqlCondition, $fieldDefinition->getName().':') !== false)) {

                    if(stripos($sqlCondition, ' or ') === false) {
                        preg_match_all('/(^|\s|\()'.$fieldDefinition->getName().'[\.:](\S+?)\s*(=|>=|<=|<|>|LIKE|!=|<>)\s*(((?!(AND|OR|(?<!\()\))).)+)/i', $sqlCondition, $relationalConditions, PREG_SET_ORDER);
                        if (!$relationalConditions) {
                            preg_match_all('/(^|\s|\()'.$fieldDefinition->getName().'[\.:](\S+?)\s*(IN\s*\s*)([^\)]+\))/i', $sqlCondition, $relationalConditions, PREG_SET_ORDER);
                        }

                        $refersTo = [];
                        foreach ($relationalConditions as $relationalCondition) {
                            if (method_exists($fieldDefinition, 'getObjectsAllowed') && $fieldDefinition->getObjectsAllowed()) {
                                $allowedClasses = $fieldDefinition->getClasses();
                                if (!$allowedClasses) {
                                    if(method_exists($fieldDefinition, 'getAllowedClassId')) {
                                        $allowedClasses = [['classes' => $fieldDefinition->getAllowedClassId()]];
                                    } elseif (method_exists($fieldDefinition, 'getOwnerClassId')) {
                                        $allowedClasses = [['classes' => $fieldDefinition->getOwnerClassId()]];
                                    }
                                }


                                foreach ($allowedClasses as $allowedClass) {
                                    $allowedClassDefinition = Helper::getClassDefinitionByName($allowedClass['classes']);
                                    if (!$allowedClassDefinition instanceof ClassDefinition) {
                                        $allowedClassDefinition = Helper::getClassDefinitionById($allowedClass['classes']);
                                    }

                                    if (!$allowedClassDefinition instanceof ClassDefinition) {
                                        continue;
                                    }

                                    if (in_array('o_'.$relationalCondition[2], Helper::getSystemFields())) {
                                        $relationalCondition[2] = 'o_'.$relationalCondition[2];
                                    }

                                    $replace = in_array($relationalCondition[2], Helper::getSystemFields(), true);
                                    $relationTable = 'object_'.$allowedClassDefinition->getId();
                                    if (!$replace) {
                                        $relationFieldDefinition = $allowedClassDefinition->getFieldDefinition($relationalCondition[2]);
                                        $replace = $relationFieldDefinition instanceof Data;

                                        if(!$replace) {
                                            $localizedFieldsDefinition = $allowedClassDefinition->getFieldDefinition('localizedfields');
                                            if($localizedFieldsDefinition instanceof Data\Localizedfields) {
                                                $relationFieldDefinition = $localizedFieldsDefinition->getFieldDefinition($relationalCondition[2]);
                                                $replace = $relationFieldDefinition instanceof Data;

                                                if($replace) {
                                                    $relationTable = 'object_localized_'.$allowedClassDefinition->getId().'_'.$listing->getLocale();
                                                }
                                            }
                                        }
                                    }

                                    if (!$replace) {
                                        foreach ($allowedClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                                            if (strtolower($relationFieldDefinition->getName()) === strtolower($relationalCondition[2])) {
                                                $replace = true;
                                                $relationalCondition[2] = $relationFieldDefinition->getName();
                                                break;
                                            }
                                        }
                                    }

                                    if ($replace) {
                                        $relationalCondition[0] .= str_repeat(')', substr_count($relationalCondition[4], '(') - substr_count($relationalCondition[4], ')'));
                                        $relationalCondition[4] .= str_repeat(')', substr_count($relationalCondition[4], '(') - substr_count($relationalCondition[4], ')'));

                                        $refersTo[$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation || $fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation ? $fieldDefinition->getOwnerFieldName():$fieldDefinition->getName()]['classFields'][$relationTable][] = '`'.$relationalCondition[2].'` '.$relationalCondition[3].' '.$relationalCondition[4];

                                        $sqlCondition = str_replace($relationalCondition[0], $relationalCondition[1].'1=1 ', $sqlCondition);
                                    } elseif ($fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation && in_arrayi($relationalCondition[2], $fieldDefinition->getColumnKeys())) {
                                        $refersTo[$fieldDefinition->getName()]['metaFields'][] = '`column` = '.Db::get()->quote($relationalCondition[2]).' and `data` '.$relationalCondition[3].' '.$relationalCondition[4];
                                        $sqlCondition = str_replace($relationalCondition[0], $relationalCondition[1].'1=1 ', $sqlCondition);
                                    }
                                }
                            }

                            if (method_exists($fieldDefinition, 'getAssetsAllowed') && $fieldDefinition->getAssetsAllowed()) {
                                if ($fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation && in_arrayi($relationalCondition[2], $fieldDefinition->getColumnKeys())) {
                                    $refersTo[$fieldDefinition->getName()]['metaFields'][] = '`column` = '.Db::get()->quote($relationalCondition[2]).' and `data` '.$relationalCondition[3].' '.$relationalCondition[4];
                                    $sqlCondition = str_replace($relationalCondition[0], $relationalCondition[1].'1=1 ', $sqlCondition);
                                } elseif (in_array($relationalCondition[2], ['id', 'filename'], true)) {
                                    $refersTo[$fieldDefinition->getName()]['classFields']['assets'][] = '`'.$relationalCondition[2].'` '.$relationalCondition[3].' '.$relationalCondition[4];
                                }

                                $sqlCondition = str_replace($relationalCondition[0], $relationalCondition[1].'1=1 ', $sqlCondition);
                            }
                        }

                        foreach ($refersTo as $relationField => $relationConditions) {
                            $sqlCondition .= ' AND '.Helper::prefixObjectSystemColumn('id').' IN (SELECT '.($fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation || $fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation ? 'dest_id': 'src_id').' FROM object_relations_'.($fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation || $fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation ? $fieldDefinition->getOwnerClassId() : $itemMold->getClassId()).' object_relations ';
                            foreach ($relationConditions['metaFields'] ?? [] as $metaConditionIndex => &$metaFieldCondition) {
                                $sqlCondition .= ' INNER JOIN object_metadata_'.$itemMold->getClassId().' object_metadata'.$metaConditionIndex.' ON object_relations.src_id=object_metadata'.$metaConditionIndex.'.'.Helper::prefixObjectSystemColumn(
                                        'id'
                                    ).' AND object_relations.dest_id=object_metadata'.$metaConditionIndex.'.dest_id';
                                if ($listLocale !== null) {
                                    $sqlCondition .= ' AND object_relations.position=object_metadata'.$metaConditionIndex.'.position';
                                }

                                $metaFieldCondition = str_replace([
                                    '`column`',
                                    '`data`'
                                ], [
                                    'object_metadata'.$metaConditionIndex.'.column',
                                    'object_metadata'.$metaConditionIndex.'.data'
                                ], $metaFieldCondition);
                            }
                            unset($metaFieldCondition);

                            $sqlCondition .= ' WHERE object_relations.type = \''.(!isset($relationConditions['classFields']['assets']) ? 'object' : 'asset').'\' AND object_relations.ownertype '.($listLocale === null ? '=\'object\'' : 'IN(\'object\',\'localizedfield\') AND object_relations.position IN (\'0\',\''.$listLocale.'\')').' AND object_relations.fieldname = '.Db::get()->quote($relationField).' AND object_relations.'.($fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation || $fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation ? 'src_id':'dest_id').' IN (';
                            $orConditions = [];
                            foreach ($relationConditions['classFields'] as $foreignClassId => $classFieldConditions) {
                                $orCondition = 'SELECT '.($foreignClassId === 'assets' ? 'id' : Helper::prefixObjectSystemColumn('id')).' FROM '.$foreignClassId.' WHERE ';

                                $andConditions = [];
                                foreach ($classFieldConditions as $classFieldCondition) {
                                    $andConditions[] = $classFieldCondition;
                                }

                                $orCondition .= implode(' AND ', $andConditions);

                                $orConditions[] = $orCondition;
                            }
                            $sqlCondition .= implode(' OR ', $orConditions);

                            $sqlCondition .= ')';

                            foreach ($relationConditions['metaFields'] ?? [] as $metaField) {
                                $sqlCondition .= ' AND '.$metaField;
                            }

                            $sqlCondition .= ')';
                        }
                    } else {
                        preg_match_all('/(^|\s|\()'.$fieldDefinition->getName().'[\.:](\S+?)\s*(=|>=|<=|<|>|LIKE|!=|<>)\s*(((?!(AND|OR|\))).)+)/i', $sqlCondition, $relationalConditions, PREG_SET_ORDER);
                        if (!$relationalConditions) {
                            preg_match_all('/(^|\s|\()'.$fieldDefinition->getName().'[\.:](\S+?)\s*(IN\s*\s*\()([^\)]+\))/i', $sqlCondition, $relationalConditions, PREG_SET_ORDER);
                        }

                        foreach ($relationalConditions as $relationalCondition) {
                            if (method_exists($fieldDefinition, 'getObjectsAllowed') && $fieldDefinition->getObjectsAllowed()) {
                                foreach ($fieldDefinition->getClasses() as $allowedClass) {
                                    $allowedClassDefinition = Helper::getClassDefinitionByName($allowedClass['classes']);

                                    if (!$allowedClassDefinition instanceof ClassDefinition) {
                                        continue;
                                    }

                                    if (in_array('o_'.$relationalCondition[2], Helper::getSystemFields())) {
                                        $relationalCondition[2] = 'o_'.$relationalCondition[2];
                                    }

                                    $replace = in_array($relationalCondition[2], Helper::getSystemFields(), true);
                                    $relationTable = 'object_'.$allowedClassDefinition->getId();
                                    if (!$replace) {
                                        $relationFieldDefinition = $allowedClassDefinition->getFieldDefinition($relationalCondition[2]);
                                        $replace = $relationFieldDefinition instanceof Data;

                                        if (!$replace) {
                                            $localizedFieldsDefinition = $allowedClassDefinition->getFieldDefinition('localizedfields');
                                            if ($localizedFieldsDefinition instanceof Data\Localizedfields) {
                                                $relationFieldDefinition = $localizedFieldsDefinition->getFieldDefinition($relationalCondition[2]);
                                                $replace = $relationFieldDefinition instanceof Data;

                                                if ($replace) {
                                                    $relationTable = 'object_localized_'.$allowedClassDefinition->getId().'_'.$listing->getLocale();
                                                }
                                            }
                                        }
                                    }

                                    if (!$replace) {
                                        foreach ($allowedClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                                            if (strtolower($relationFieldDefinition->getName()) === strtolower($relationalCondition[2])) {
                                                $replace = true;
                                                $relationalCondition[2] = $relationFieldDefinition->getName();
                                                break;
                                            }
                                        }
                                    }

                                    if ($replace) {
                                        $replacementCondition = $relationalCondition[1].'('.Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN (SELECT '.Helper::prefixObjectSystemColumn('id').' FROM '.$relationTable.' WHERE `'.$relationalCondition[2].'` '.$relationalCondition[3].' '.$relationalCondition[4].') AND type = \'object\' AND ownertype = \'object\' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).'))';

                                        $sqlCondition = str_replace($relationalCondition[0], $replacementCondition, $sqlCondition);
                                    }
                                }
                            }

                            if (method_exists($fieldDefinition, 'getAssetsAllowed') && $fieldDefinition->getAssetsAllowed()) {
                                $replacementCondition = $relationalCondition[1].'('.Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN (SELECT '.Helper::prefixObjectSystemColumn('id').' FROM assets WHERE `'.$relationalCondition[2].'` '.$relationalCondition[3].' '.$relationalCondition[4].') AND type = \'asset\' AND ownertype = \'object\' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).'))';

                                $sqlCondition = str_replace($relationalCondition[0], $replacementCondition, $sqlCondition);
                            }
                        }
                    }
                } elseif ((empty($this->config['inheritanceEnabled']) || !$itemMold->getClass()->getAllowInherit()) && $fieldDefinition instanceof Data\ManyToOneRelation) {
                    $sqlCondition = preg_replace(
                        '/(^|\s)'.$fieldDefinition->getName().'__id\s*(=|LIKE|!=|<>|>|<|<=|>=)\s*(((?!(AND|OR|\))).)+)/i',
                        '$1('.Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id $2 $3 AND ownertype = \'object\' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).'))', $sqlCondition);
                }

                $sqlCondition = preg_replace('/(^|\(|\s)('.$fieldDefinition->getName().')(\s*(NOT LIKE|!=|<>))/i', '$1IFNULL($2, \'\')$3', $sqlCondition);
            }

            if (count($listing->getObjectbricks()) > 0) {
                foreach ($fieldDefinitions as $fieldDefinition) {
                    $sqlCondition = preg_replace('/(^|[^.])('.$fieldDefinition->getName().'($|[^0-9_A-Za-z]))/', '$1'.$listing->getDao()->getTableName().'.$2', $sqlCondition);
                }
                foreach (Helper::getSystemFields() as $systemField) {
                    $sqlCondition = preg_replace('/(^|[^.])('.$systemField.'($|[^0-9_A-Za-z]))/', '$1'.$listing->getDao()->getTableName().'.$2', $sqlCondition);
                }
            }

            if (!empty($this->config['inheritanceEnabled']) && $itemMold->getClass()->getAllowInherit() && stripos($sqlCondition, ' or ') === false) {
                $sqlConditionWithoutRelationLikes = $sqlCondition;
                $relationConditions = [];
                preg_match_all('/(\w+)\s*LIKE\s*[\'"]%,([^,]+),%[\'"]/i', $sqlConditionWithoutRelationLikes, $likeMatches, PREG_SET_ORDER);
                foreach ($likeMatches as $likeMatch) {
                    $column = $likeMatch[1];

                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                    if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        $sqlConditionWithoutRelationLikes = str_replace($likeMatch[0], '1=1', $sqlConditionWithoutRelationLikes);
                        $relationConditions[] = $likeMatch[0];
                    }
                }

                preg_match_all('/(\w+)\s*(IS NULL|IS NOT NULL)\s*/i', $sqlConditionWithoutRelationLikes, $likeMatches, PREG_SET_ORDER);
                foreach ($likeMatches as $likeMatch) {
                    $column = $likeMatch[1];

                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                    if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        $sqlConditionWithoutRelationLikes = str_replace($likeMatch[0], '1=1', $sqlConditionWithoutRelationLikes);
                        $relationConditions[] = $likeMatch[0];
                    }
                }

                $tmpListing = clone $listing;

                $tmpListing->setCondition($sqlConditionWithoutRelationLikes);

                $tmpIdList = $tmpListing->loadIdList();

                if (count($tmpIdList) === 0) {
                    return '1=0';
                }
                if (count($tmpIdList) < 1000) {
                    $sqlCondition = $tmpListing->getDao()->getTableName().'.'.Helper::prefixObjectSystemColumn('id').' IN ('.implode(',', $tmpIdList).')'.($relationConditions ? ' AND '.implode(' AND ', $relationConditions) : '');
                }
            }
        } elseif($itemMold instanceof PageSnippet) {
            if($sqlCondition) {
                $sqlCondition = '`type`=\''.$itemMold->getType().'\' AND ('.$sqlCondition.')';
            } else {
                $sqlCondition = '`type`=\''.$itemMold->getType().'\'';
            }
        } elseif($itemMold instanceof Asset) {
            $metaFields = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT DISTINCT name FROM assets_metadata WHERE name NOT IN (\'id\', \'parentId\', \'type\', \'filename\', \'path\', \'mimetype\', \'creationDate\',\'modificationDate\',\'userOwner\',\'userModification\',\'customSettings\',\'hasMetaData\',\'versionCount\')');

            foreach($metaFields as $metaField) {
                $sqlCondition = preg_replace('/('.preg_quote($metaField, '/').')\s*(=|>=|<=|<|>|LIKE|!=|<>)\s*([^\s)]+)/i', 'id IN (SELECT cid FROM assets_metadata WHERE name=\'$1\' AND data$2$3)', $sqlCondition);

                $sqlCondition = preg_replace('/('.preg_quote($metaField, '/').')\s*IS NULL/i', 'id NOT IN (SELECT cid FROM assets_metadata WHERE name=\'$1\' AND data$2$3)', $sqlCondition);
                $sqlCondition = preg_replace('/('.preg_quote($metaField, '/').')\s*IS NOT NULL/i', 'id IN (SELECT cid FROM assets_metadata WHERE name=\'$1\' AND data$2$3)', $sqlCondition);
            }

            $propertyFields = PimcoreDbRepository::getInstance()->findColumnInSql(
                'SELECT DISTINCT name FROM properties WHERE name NOT IN (\'id\', \'parentId\', \'type\', \'filename\', \'path\', \'mimetype\', \'creationDate\',\'modificationDate\',\'userOwner\',\'userModification\',\'customSettings\',\'hasMetaData\',\'versionCount\')'
            );
            foreach ($propertyFields as $propertyField) {
                if (!empty($this->config['inheritanceEnabled'])) {
                    $sqlCondition = preg_replace('/('.preg_quote($propertyField, '/').')\s*(=|>=|<=|<|>|LIKE|!=|<>)\s*([^\s)]+)/i', 'id IN ((SELECT cid FROM properties WHERE name=\'$1\' AND data$2$3) UNION (SELECT id FROM assets WHERE parentId IN (SELECT cid FROM properties WHERE name=\'$1\' AND data$2$3 AND inheritable=1)) UNION (SELECT id FROM assets WHERE parentId IN (SELECT id FROM assets WHERE parentId IN (SELECT cid FROM properties WHERE name=\'$1\' AND data$2$3 AND inheritable=1))))', $sqlCondition);
                } else {
                    $sqlCondition = preg_replace('/('.preg_quote($propertyField, '/').')\s*(=|>=|<=|<|>|LIKE|!=|<>)\s*([^\s)]+)/i', 'id IN (SELECT cid FROM properties WHERE name=\'$1\' AND data$2$3)', $sqlCondition);
                }
            }
        }

        return $sqlCondition;
    }

    public function getResource() {
        $sqlCondition = $this->config['file'];
        $sqlCondition = trim($this->getImporter()->replaceObjectIdentifier($sqlCondition, $this->config['parameters'] ?? null));

        if ($this->sqlCondition) {
            $this->sqlCondition = trim($this->getImporter()->replaceObjectIdentifier($this->sqlCondition, $this->config['parameters'] ?? null));
            if (trim($sqlCondition) && (substr(trim($sqlCondition), 0, 2) !== '/*' || substr(trim($sqlCondition), -2) !== '*/')) {
                $sqlCondition = '('.$this->sqlCondition.') AND ('.$sqlCondition.')';
            } else {
                $sqlCondition = $this->sqlCondition;
            }
        }

        return $sqlCondition;
    }

    private function getItemMold() {
        $sourceClass = $this->config['sourceClass'];
        if ($sourceClass === Document\Page::class) {
            $masterDocument = null;
            if (!empty($this->targetConfig['masterDocument'])) {
                $masterDocument = Document::getByPath($this->targetConfig['masterDocument']);
            }
            if ($masterDocument instanceof PageSnippet) {
                $sourceClass = get_class($masterDocument);
            } else {
                $sourceClass = Document::class;
            }
        }

        return $this->helper->getItemMoldByClassId($sourceClass);
    }

    /**
     * Sets a logger.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function dataQuerySelectorPointsToInternal($dataQuerySelectorParts, $fromClass, $toClass)
    {
        $importConfigController = OpenDxp::getContainer()->get(ImportconfigController::class);

        $dataQuerySelector = array_shift($dataQuerySelectorParts);
        if(!$dataQuerySelector) {
            return false;
        }
        if ($this->helper->getClass($dataQuerySelector) !== null) {
            if (isset($dataQuerySelectorParts[0]) && stripos($dataQuerySelectorParts[0], 'getby') === false) {
                // if we get here first data query selector part also exists as data object class -> check if field for $object with same name exists -> field has higher priority
                $fieldDefinition = Importer::getFieldDefinition($fromClass, $dataQuerySelector);
                if ($fieldDefinition->getLocked()) {
                    $fromClass = $this->helper->getClass($dataQuerySelector);
                    if (is_a($toClass, $fromClass, true)) {
                        return true;
                    }
                    $dataQuerySelectorParts = array_slice($dataQuerySelectorParts, 2);
                    return $this->dataQuerySelectorPointsTo($dataQuerySelectorParts, $fromClass, $toClass);
                }
            } else {
                $fromClass = $this->helper->getClass($dataQuerySelector);
                if (is_a($toClass, $fromClass, true)) {
                    return true;
                }
                $dataQuerySelectorParts = array_slice($dataQuerySelectorParts, 2);
                return $this->dataQuerySelectorPointsTo($dataQuerySelectorParts, $fromClass, $toClass);
            }
        }

        $targetClasses = $importConfigController->getDataQueryTargetClass($fromClass, [$dataQuerySelector]);
        foreach ($targetClasses as $targetClass) {
            // before and self return the current object itself, so no need to fetch dependencies for those data query selectors
            if (is_a($toClass, $targetClass, true) && !in_array($dataQuerySelector, ['before', 'self'], true)) {
                return true;
            }

            if (count($dataQuerySelectorParts) > 0) {
                return $this->dataQuerySelectorPointsTo($dataQuerySelectorParts, $targetClass, $toClass);
            }
        }

        return false;
    }

    private function dataQuerySelectorPointsTo($dataQuerySelectorParts, $fromClass, $toClass) {
        $cacheKey = $fromClass.'_'.$toClass.'_'.implode(':', $dataQuerySelectorParts);
        if(!isset(self::$dataQuerySelectorPointsToCache[$cacheKey])) {
            self::$dataQuerySelectorPointsToCache[$cacheKey] = $this->dataQuerySelectorPointsToInternal($dataQuerySelectorParts, $fromClass, $toClass);
        }

        return self::$dataQuerySelectorPointsToCache[$cacheKey];
    }

    private static function getGreatestModificationDate(ElementInterface $object)
    {
        $updated = $object->getModificationDate();

        $objectElementType = Service::getElementType($object);

        $updated = max(
            $updated,
            PimcoreDbRepository::getInstance()->findOneInSql('SELECT GREATEST(MAX(IFNULL(objects.'.Helper::prefixObjectSystemColumn('modificationDate').',0)), MAX(IFNULL(assets.modificationDate,0)), MAX(IFNULL(documents.modificationDate,0)))
                FROM dependencies 
                LEFT JOIN objects ON dependencies.targetid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.targettype="object" AND objects.'.Helper::prefixObjectSystemColumn('modificationDate').' > ?
                LEFT JOIN assets ON dependencies.targetid=assets.id AND dependencies.targettype="asset" AND assets.modificationDate > ?
                LEFT JOIN documents ON dependencies.targetid=documents.id AND dependencies.targettype="document" AND documents.modificationDate > ?
                WHERE sourcetype=? AND sourceid = ?',
                [$updated, $updated, $updated, $objectElementType, $object->getId()]
            )
        );

        $updated = max(
            $updated,
            PimcoreDbRepository::getInstance()->findOneInSql(
                'SELECT GREATEST(MAX(IFNULL(objects.'.Helper::prefixObjectSystemColumn('modificationDate').',0)), MAX(IFNULL(assets.modificationDate,0)), MAX(IFNULL(documents.modificationDate,0)))
                FROM dependencies 
                LEFT JOIN objects ON dependencies.sourceid=objects.'.Helper::prefixObjectSystemColumn('id').' AND dependencies.sourcetype="object" AND objects.'.Helper::prefixObjectSystemColumn('modificationDate').' > ?
                LEFT JOIN assets ON dependencies.sourceid=assets.id AND dependencies.sourcetype="asset" AND assets.modificationDate > ?
                LEFT JOIN documents ON dependencies.sourceid=documents.id AND dependencies.sourcetype="document" AND documents.modificationDate > ?
                WHERE targettype=? AND targetid = ?',
                [$updated, $updated, $updated, $objectElementType, $object->getId()]
            )
        );

        try {
            $updated = max(
                $updated,
                PimcoreDbRepository::getInstance()->findOneInSql(
                'WITH RECURSIVE elements AS (
                        SELECT current.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id, current.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').' AS modificationDate, current.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId
                        FROM '.$objectElementType.'s current
                        WHERE '.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').'=?
                        UNION ALL
                        SELECT anchestors.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').', GREATEST(elements.modificationDate, anchestors.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').'), anchestors.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').'
                        FROM '.$objectElementType.'s anchestors
                        INNER JOIN elements ON anchestors.'.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = elements.parentId
                    )
                    SELECT MAX(modificationDate)
                    FROM elements',
                [$object->getId()]
                )
            );
        } catch (\Exception $e) {
            $pathParts = explode('/', $object->getRealFullPath());

            $parentQueries = [];
            $parentQueriesParameter = [];
            for ($pathLength = 1, $pathLengthMax = count($pathParts); $pathLength < $pathLengthMax - 1; $pathLength++) {
                if ($objectElementType === 'object') {
                    $keyColumn = Helper::prefixObjectSystemColumn('key');
                } elseif ($objectElementType === 'asset') {
                    $keyColumn = 'filename';
                } elseif ($objectElementType === 'document') {
                    $keyColumn = 'key';
                }

                $parentQueries[] = 'SELECT '.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').' AS modificationDate, '.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId 
                        FROM '.$objectElementType.'s 
                        WHERE '.($objectElementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' = ? AND `'.$keyColumn.'` = ?';
                $parentQueriesParameter[] = implode('/', array_slice($pathParts, 0, $pathLength)).'/';
                $parentQueriesParameter[] = implode('/', array_slice($pathParts, $pathLength, 1));
            }

            if ($parentQueries) {
                $updated = max($updated, PimcoreDbRepository::getInstance()->findOneInSql('SELECT MAX(modificationDate) FROM (('.implode(') UNION (', $parentQueries).')) t', $parentQueriesParameter));
            }
        }

        return $updated;
    }

    private static function getLastModificationDateTranslations() {
        if(self::$modificationDateTranslations === null) {
            self::$modificationDateTranslations = max(
                PimcoreDbRepository::getInstance()->findOneInSql('SELECT MAX(modificationDate) FROM translations_admin'),
                PimcoreDbRepository::getInstance()->findOneInSql('SELECT MAX(modificationDate) FROM translations_messages')
            );
        }

        return self::$modificationDateTranslations;
    }
}
