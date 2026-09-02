<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use ArrayAccess;
use avadim\FastExcelReader\Excel;
use Aws\S3\S3Client;
use Sylphen\DataBridgeBundle\Controller\ImportconfigController;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Event\CallbackFunctionTemplateEvent;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\SelectMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\GenericObjectRelation;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Sylphen\DataBridgeBundle\lib\Pim\Item\TranslationHelper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\InMemoryLogger;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Export;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportIgnoreData;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DeepCopy\DeepCopy;
use DeepCopy\Filter\Doctrine\DoctrineCollectionFilter;
use DeepCopy\Filter\KeepFilter;
use DeepCopy\Filter\SetNullFilter;
use DeepCopy\Matcher\PropertyNameMatcher;
use DeepCopy\Matcher\PropertyTypeMatcher;
use DeepCopy\Reflection\ReflectionHelper;
use Doctrine\Common\Collections\Collection;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Exception;
use GlobIterator;
use IntlDateFormatter;
use InvalidArgumentException;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Renderer\RendererConstant;
use JsonException;
use Laminas\Barcode\Barcode;
use Laminas\Barcode\ObjectPluginManager;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\WhitespacePathNormalizer;
use Monolog\ErrorHandler;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Config;
use OpenDxp\Db;
use OpenDxp\Event\AdminEvents;
use OpenDxp\Event\Model\ElementEvent;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Localizedfields;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\GeoCoordinates;
use OpenDxp\Model\DataObject\Data\Link;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\Fieldcollection\Definition;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\QuantityValue\Unit;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\DeepCopy\PimcoreClassDefinitionMatcher;
use OpenDxp\Model\Element\DeepCopy\PimcoreClassDefinitionReplaceFilter;
use OpenDxp\Model\Element\ElementDumpStateInterface;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Model\FactoryInterface;
use OpenDxp\Model\Property;
use OpenDxp\Model\User;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use OpenDxp\Model\Version\SetDumpStateFilter;
use OpenDxp\Tool;
use OpenDxp\Tool\Serialize;
use OpenDxp\Translation\Translator;
use OpenDxp\Model\Version;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Date;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Nonstandard\UuidV6;
use Ramsey\Uuid\Uuid;
use ReflectionObject;
use SplFileInfo;
use stdClass;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use voku\helper\ASCII;

class Helper
{
    use LoggerAwareTrait;
    use TemporaryFileHelperTrait {
        TemporaryFileHelperTrait::getTemporaryFileFromStream as getTemporaryFileFromStreamTrait;
    }

    /** @var self */
    private static $instance;

    /** @var ImporterInterface */
    private $importer;

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    /** @var Translator */
    private $translator;

    private $previewItem;

    /** @var FilesystemOperator */
    private static $assetStorage;

    /** @var FilesystemOperator */
    private static $thumbnailStorage;

    /** @var FilesystemOperator */
    private static $tempStorage;

    /** @var FilesystemOperator */
    private static $applicationloggerStorage;

    /** @var Filesystem[] */
    private static $flysystemCache = [];

    /** @var Request */
    private static $request;

    private static $user;

    /** @var TranslationHelper */
    private $translationHelper;

    private static $environmentVariables;

    private static $memoryLimit;

    private static $totalMemory = 'undefined';

    private static $classDefinitions = [];

    private static $mappingDefaults = [
        'input' => [
            'translateFromLanguage' => '',
            'infer' => false
        ],
        'wysiwyg' => [
            'translateFromLanguage' => '',
            'generateText' => false,
            'textLength' => 1
        ],
        'textarea' => [
            'translateFromLanguage' => '',
            'generateText' => false,
            'textLength' => 1
        ],
        'numeric' => array(
            'decimalSeparator' => '.',
            'groupingSeparator' => ',',
            'optimize' => '',
            'infer' => false
        ),
        'quantityValue' => [
            'autoCreateUnits' => false,
            'infer' => false
        ],
        'inputQuantityValue' => [
            'autoCreateUnits' => false,
            'infer' => false
        ],
        'date' => array(
            'dateFormat' => 'd.m.Y'
        ),
        'datetime' => array(
            'dateFormat' => 'd.m.Y H:i:s'
        ),
        'select' => array(
            'autoCreate' => false,
            'infer' => false
        ),
        'multiselect' => array(
            'separator' => ',',
            'purgeitems' => true,
            'autoCreate' => false,
            'infer' => false
        ),
        'countrymultiselect' => array(
            'separator' => ',',
            'purgeitems' => true,
            'infer' => false
        ),
        'multihref' => array(
            'purgeitems' => false,
            'overwrite' => true,
            'preventDuplicates' => false
        ),
        'manyToManyRelation' => array(
            'purgeitems' => false,
            'overwrite' => true,
            'preventDuplicates' => false
        ),
        'image' => array(
            'overwrite' => true,
            'preventDuplicates' => false
        ),
        'imageGallery' => [
            'overwrite' => true,
            'purgeitems' => false,
            'preventDuplicates' => false
        ],
        'hotspotimage' => array(
            'overwrite' => true,
            'preventDuplicates' => false
        ),
        'fieldcollections' => array(
            'purgeitems' => false
        ),
        'block' => array(
            'purgeitems' => false,
            'autoCreate' => false
        ),
        'objects' => array(
            'purgeitems' => false
        ),
        'manyToManyObjectRelation' => array(
            'purgeitems' => false,
            'auto_classification' => false,
            'auto_create_references' => false
        ),
        'manyToOneRelation' => array(
            'auto_create_references' => false
        ),
        'multihrefMetadata' => array(
            'purgeitems' => false
        ),
        'advancedManyToManyRelation' => array(
            'purgeitems' => false,
            'overwrite' => true,
            'preventDuplicates' => false
        ),
        'objectsMetadata' => array(
            'purgeitems' => false
        ),
        'advancedManyToManyObjectRelation' => array(
            'purgeitems' => false,
            'infer' => ''
        ),
        'genericObjectRelation' => array(
            'purgeitems' => false
        ),
        'calculatedValue' => array(
            'optimize' => ''
        ),
        'classificationstore' => array(
            'auto_generate_fields' => ''
        ),
        'objectbricks' => array(
            'auto_generate_fields' => '',
            'purgeitems' => false,
        ),
    ];

    private static $hostUrl;
    private static $frontendUrl;

    private $initFunctionResult = [];

    private static $openDxpVersion;
    private static $dataBridgeVersion;

    public function __construct(ImporterInterface $importer, ItemMoldBuilder $itemMoldBuilder, Translator $translator, TranslationHelper $translationHelper)
    {
        $this->importer = $importer;
        $this->itemMoldBuilder = $itemMoldBuilder;
        $this->translator = $translator;
        $this->translationHelper = $translationHelper;
    }

    public function runInitFunction($dataport, $statusKey = null) {
        if(!isset($this->initFunctionResult[$dataport['id']])) {
            $this->initFunctionResult[$dataport['id']] = true;

            $this->importer->setDataport($dataport);

            if ($this->logger === null) {
                $this->logger = new InMemoryLogger();
            }

            $fieldMappings = Fieldmapping::getInstance();
            $initAction = $fieldMappings->findOne(
                [
                    'dataportId = ?' => $dataport['id'],
                    'fieldName = ?' => '__init_action'
                ]
            );

            $targetConfig = $this->importer->getTargetConfig();
            $virtualFieldFallbackCache = [];
            if (!empty($initAction['calculation']) && CallbackFunction::isEngineAvailable($targetConfig['javascriptEngine'])) {
                if (preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $initAction['calculation'], $variables)) {
                    foreach (array_unique($variables[1]) as $variable) {
                        $virtualFieldDefinition = Importer::getFieldDefinition(null, '__virtual_'.$variable);
                        $virtualMapping = $this->createMapping($virtualFieldDefinition, $dataport, [], false);
                        $virtualFieldFallbackCache[$virtualFieldDefinition->getName()] = reset($virtualMapping)['example_value'] ?? null;
                    }
                }

                try {
                    $dataportResourceId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT dataport_resource_id FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE `key` = ?', [$statusKey]);

                    $dataportResource = \json_decode(DataportResource::getInstance()->get($dataportResourceId)['resource'] ?? 'null', true);
                    $this->initFunctionResult[$dataport['id']] = CallbackFunction::evaluateScript(
                        $initAction['calculation'], $targetConfig['javascriptEngine'],
                        [
                            'response' => new Response(),
                            'request' => self::getRequest(),
                            'context' => [
                                'dataportId' => $dataport['id'],
                                'dataport' => [
                                    'id' => $dataport['id'],
                                    'name' => $dataport['name'],
                                ],
                                'user' => [
                                    'id' => self::getUser()->getId(),
                                    'username' => self::getUser()->getUsername()
                                ],
                                'resource' => $dataportResource,
                                'resources' => [$dataportResource], // BC
                                'statusKey' => $statusKey ?? uniqid('', true)
                            ],
                            'virtualFields' => $virtualFieldFallbackCache,
                            'transfer' => self::getRequest()->attributes->get('transfer'),
                            'logger' => $this->logger,
                            'field' => 'Initialization function'
                        ]
                    ) ?? true;
                } catch (\Throwable $ex) {
                    $this->logger->error($ex->getMessage());
                }

                $this->importer->setDataport($dataport);
            }
        }

        return $this->initFunctionResult[$dataport['id']];
    }

    /**
     * @param array $targetConfig (keys: itemClass, [masterDocument])
     *
     * @return Data[]
     */
    public function getFieldDefinitions(array $targetConfig)
    {
        $itemClassId = $targetConfig['itemClass'];

        $class = null;
        if ($itemClassId) {
            try {
                $class = self::getClassDefinitionById($itemClassId);
            } catch (\Exception $e) {
            }
        }

        $fieldDefinitions = [];

        if ($class instanceof ClassDefinition || is_a($itemClassId, PageSnippet::class, true)) {
            try {
                $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($targetConfig['itemClass']);

                $systemFields = ['id', 'key', 'path'];
                if ($itemMold instanceof Concrete && $itemMold->getClass()->getAllowVariants()) {
                    $systemFields[] = 'type';
                }
                $systemFields = array_merge($systemFields, ['published', 'tags', 'properties']);

                foreach ($systemFields as $field) {
                    $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, $field);
                }
            } catch(\Exception $e) {
            }
        }

        if ($class instanceof ClassDefinition) {
            $fieldDefinitions = array_merge($fieldDefinitions, $class->getFieldDefinitions());

            $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, 'delete element');
            $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, 'Complete Object');
        } elseif (is_a($itemClassId, PageSnippet::class, true)) {
            $masterDocument = null;
            if($targetConfig['masterDocument']) {
                $masterDocument = PageSnippet::getByPath($targetConfig['masterDocument']);
            }
            if ($masterDocument instanceof PageSnippet) {
                $elements = method_exists($masterDocument, 'getEditables') ? $masterDocument->getEditables() : $masterDocument->getElements();
                foreach ($elements as $element) {
                    $fieldDefinition = Importer::getFieldDefinition($masterDocument, $element->getName());
                    $fieldDefinition->setTitle($element->getRealName());
                    $fieldDefinitions[] = $fieldDefinition;
                }
            }

            $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, 'delete element');
            $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, 'Complete Document');
        } elseif (is_a($itemClassId, Asset::class, true)) {
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($targetConfig['itemClass']);
            $class = new \ReflectionClass($itemClassId);
            $setterMethodPrefix = 'set';
            foreach (['id', 'filename', 'path', 'Stream', 'metadata', 'tags', 'properties', 'creationDate', 'modificationDate', 'customSettings'] as $field) {
                foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (\strtolower($method->getName()) === strtolower($setterMethodPrefix.$field)) {
                        $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, $field);
                        continue 2;
                    }
                }

                $fieldDefinitions[] = Importer::getFieldDefinition($itemClassId, $field);
            }

            $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, 'delete element');
        } elseif (class_exists((string)$itemClassId)) {
            $class = new \ReflectionClass($itemClassId);
            $setterMethodPrefix = 'set';
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (strpos($method->getName(), $setterMethodPrefix) !== 0) {
                    continue;
                }

                $name = substr($method->getName(), strlen($setterMethodPrefix));
                $fieldDefinition = Importer::getFieldDefinition($itemClassId, $name);
                $fieldDefinition->setTitle($name);
                $fieldDefinitions[] = $fieldDefinition;
            }
        }

        if ($class instanceof ClassDefinition || is_a($itemClassId, PageSnippet::class, true)) {
            try {
                if (self::isBundleInstalled('SeoBundle')) {
                    $inputDefTitle = Importer::getFieldDefinition(\SeoBundle\SeoBundle::class, 'seoBundle.title');
                    $inputDefDescription = Importer::getFieldDefinition(\SeoBundle\SeoBundle::class, 'seoBundle.description');

                    if (is_a($itemClassId, OpenDxp\Model\Document\Page::class, true)) {
                        $fieldDefinitions[] = $inputDefTitle;
                        $fieldDefinitions[] = $inputDefDescription;
                    } else {
                        if (!array_key_exists('localizedfields', $fieldDefinitions)) {
                            $fieldDefinitions['localizedfields'] = new Localizedfields();
                        }
                        $fieldDefinitions['localizedfields']->addChild($inputDefTitle);
                        $fieldDefinitions['localizedfields']->addChild($inputDefDescription);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        try {
            $workflowManager = \OpenDxp::getContainer()->get(OpenDxp\Workflow\Manager::class);
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($targetConfig['itemClass']);
            $workflows = $workflowManager ? $workflowManager->getAllWorkflowsForSubject($itemMold) : [];
            if (count($workflows) > 0) {
                $fieldDefinitions[] = Importer::getFieldDefinition($itemMold, SelectMapper::VIRTUAL_WORKFLOW_FIELD);
            }
        } catch (\Throwable $e) {
        }

        return $fieldDefinitions;
    }

    /**
     * @param array $dataport
     * @param array|null $fieldDefinitions
     *
     * @return array
     * @throws \Exception
     */
    public function createFieldMappings(array $dataport, ?array $fieldDefinitions = null)
    {
        if (method_exists(Db::getConnection()->getConfiguration(), 'setSQLLogger')) {
            Db::getConnection()->getConfiguration()->setSQLLogger(null);
        }
        if (class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }
        if (method_exists(\Doctrine\Deprecations\Deprecation::class, 'disable')) {
            \Doctrine\Deprecations\Deprecation::disable();
        }

        try {
            PimcoreDbRepository::getInstance()->beginTransaction();

            $fieldMappings = Fieldmapping::getInstance();

            $this->runInitFunction($dataport);

            $onlyForGivenFieldDefinitions = $fieldDefinitions !== null;

            if (!$onlyForGivenFieldDefinitions) {
                $fieldDefinitions = [];

                $targetConfig = $dataport['targetconfig'];
                if (is_array($targetConfig)) {
                    $fieldDefinitions = $this->getFieldDefinitions($targetConfig);
                }
            }

            $mappings = [[]];
            /**
             * @var $fieldDefinitions Data[]
             */
            foreach ($fieldDefinitions as $def) {
                $mappings[] = $this->handleDefinitionElement($def, $dataport);
            }

            $mappings = [array_merge(...$mappings)];

            if (!$onlyForGivenFieldDefinitions) {
                // add mappings of fields which do not exist anymore
                $existingMappings = $fieldMappings->find(
                    [
                        'dataportId = ?' => $dataport['id'],
                        'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action']
                    ]
                );

                foreach ($existingMappings as $existingMapping) {
                    $existingMappingAttributeKey = self::getFieldKey($existingMapping);
                    foreach ($mappings[0] as $mappingIndex => $mapping) {
                        if (strtolower($mapping['attributeKey']) === strtolower($existingMappingAttributeKey)) {
                            if($mapping['attributeKey'] !== $existingMappingAttributeKey) {
                                $mapping['attributeKey'] = $existingMappingAttributeKey;
                                $mappings[0][$existingMappingAttributeKey] = $mapping;
                                unset($mappings[0][$mappingIndex]);
                            }

                            continue 2;
                        }
                    }

                    $fieldDefinition = Importer::getFieldDefinition($this->itemMoldBuilder->getItemMold($dataport['id']), $existingMapping);

                    $isInUse = false;
                    if (strpos($existingMapping['fieldName'], '__virtual_') === 0) {
                        $existingMappingsResultFunctions = $fieldMappings->find(
                            [
                                'dataportId = ?' => $dataport['id'],
                                'fieldName IN (?)' => ['__result_callback', '__result_action', '__init_action']
                            ]
                        );
                        foreach (array_merge($existingMappings, $existingMappingsResultFunctions) as $existingMappingTmp) {
                            if (preg_match('/[\'"]?\{\{\s*'.preg_quote(str_replace('__virtual_', '', $existingMapping['fieldName']), '/').'\s*\}\}[\'"]?/', $existingMappingTmp['calculation'])) {
                                $isInUse = true;
                                break;
                            }
                        }
                    }

                    $title = str_replace('__virtual_', '', $existingMapping['fieldName']);
                    if (strpos($existingMapping['fieldName'], '__virtual_') !== 0) {
                        $title = $this->translator->trans(str_replace('__virtual_', '', $existingMapping['fieldName']), [], 'admin');
                    }

                    if (!$isInUse) {
                        $title .= ' ('.((strpos($existingMapping['fieldName'], '__virtual_') === 0) ? $this->translator->trans('pim.mapping.variables.variable.not_in_use', [], 'admin') : $this->translator->trans('deleted', [], 'admin')).')';
                    }
                    $fieldDefinition->setTitle($title);

                    $fieldDefinition->setName($existingMapping['fieldName']);

                    $mappingOptions = [];
                    if ($existingMapping['brickName']) {
                        $mappingOptions['brickName'] = $existingMapping['brickName'];
                    }
                    if ($existingMapping['locale']) {
                        $mappingOptions['locale'] = $existingMapping['locale'];
                    }

                    $mappings[] = $this->createMapping($fieldDefinition, $dataport, $mappingOptions);
                }

                $fieldDefinition = Importer::getFieldDefinition(null, '__init_action');
                $fieldDefinition->setTitle($this->translator->trans('pim.mapping.init_action', [], 'admin'));
                $mappings[] = $this->createMapping($fieldDefinition, $dataport);

                $fieldDefinition = Importer::getFieldDefinition(null, '__result_callback');
                $fieldDefinition->setTitle($this->translator->trans('pim.mapping.result_callback', [], 'admin'));
                $mappings[] = $this->createMapping($fieldDefinition, $dataport);

                $fieldDefinition = Importer::getFieldDefinition(null, '__result_action');
                $fieldDefinition->setTitle($this->translator->trans('pim.mapping.result_action', [], 'admin'));
                $mappings[] = $this->createMapping($fieldDefinition, $dataport);
            }

            $mappings = array_merge(...$mappings);
            $mappings = array_map(
                static function ($mapping) {
                    $mapping['example_value'] = (!isset($mapping['example_value']) || $mapping['example_value'] === null) ? null : json_encode($mapping['example_value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    return $mapping;
                },
                $mappings
            );

            foreach ($mappings as &$mapping) {
                $mapping['calculation'] = $mapping['settings']['calculation'];
                $mapping['fieldName'] = $mapping['attribute']['key'];
            }
            unset($mapping);

            $mappings = Importer::sortMappingsByDependencies($mappings);

            foreach ($mappings as $index => $mapping) {
                unset($mappings[$index]['calculation'], $mappings[$index]['fieldName']);
            }

            $mappings = array_values($mappings);

            return $mappings;
        } finally {
            try {
                PimcoreDbRepository::getInstance()->rollback();
            } catch(\Throwable $e) {
                // happens when implicit commit happened in between
            }
        }
    }

    /**
     * Container mit Kindelementen rekursiv auflösen
     *
     * @param Data $def
     * @param array $dataport
     * @param array $mappingOptions
     *
     * @return array
     */
    public function handleDefinitionElement(Data $def, array $dataport, array $mappingOptions = [])
    {
        $mappings = [[]];
        if ($def instanceof ClassDefinition\Data\Localizedfields) {
            $languages = Tool::getValidLanguages();
            foreach ($def->getFieldDefinitions() as $child) {
                foreach ($languages as $language) {
                    $childMappings = $this->handleDefinitionElement($child, $dataport, \array_replace_recursive($mappingOptions, ['locale' => $language]));
                    $mappings[] = $childMappings;
                }
            }
        } elseif ($def instanceof ClassDefinition\Data\Objectbricks) {
            $mappings[] = $this->createMapping($def, $dataport, $mappingOptions);

            $allowedBricks = $def->getAllowedTypes();
            $countBrickFields = 0;
            foreach ($allowedBricks as $allowedBrick) {
                $brickDefinition = \OpenDxp\Model\DataObject\Objectbrick\Definition::getByKey($allowedBrick);
                if($brickDefinition instanceof \OpenDxp\Model\DataObject\Objectbrick\Definition) {
                    $countBrickFields += count($brickDefinition->getFieldDefinitions());

                    if ($countBrickFields > 1000) {
                        break;
                    }
                }
            }

            if($countBrickFields <= 1000) {
                $brickMappings = [];
                foreach ($allowedBricks as $allowedBrick) {
                    $brickDefinition = \OpenDxp\Model\DataObject\Objectbrick\Definition::getByKey($allowedBrick);
                    if ($brickDefinition instanceof \OpenDxp\Model\DataObject\Objectbrick\Definition) {
                        foreach ($brickDefinition->getFieldDefinitions() as $brickField) {
                            $childMappings = $this->handleDefinitionElement($brickField, $dataport, \array_replace_recursive($mappingOptions, ['targetBrickField' => $def->getName(), 'brickName' => $allowedBrick]));
                            $brickMappings[] = $childMappings;
                        }
                        }
                }

                $mappings = array_merge($mappings, $brickMappings);
            }
        } elseif($def instanceof Data\Classificationstore) {
            $mappings[] = $this->createMapping($def, $dataport, $mappingOptions);

            $classificationStoreKeyGroupConfigs = new OpenDxp\Model\DataObject\Classificationstore\KeyGroupRelation\Listing();
            $allowedGroups = $def->getAllowedGroupIds();
            if(empty($allowedGroups)) {
                $classificationStoreKeyGroupConfigs->addConditionParam('groupId IN (select id from classificationstore_groups where storeId = ?)', [$def->getStoreId()]);
            } else {
                $classificationStoreKeyGroupConfigs->addConditionParam('groupId IN ('.rtrim(str_repeat('?,', count($allowedGroups)), ',').')', $allowedGroups);
            }

            if(count($classificationStoreKeyGroupConfigs) <= 200) {
                $classificationStoreMappings = [];
                $countClassificationStoreMappings = 0;
                /** @var OpenDxp\Model\DataObject\Classificationstore\KeyGroupRelation $classificationStoreKeyGroupConfig */
                foreach ($classificationStoreKeyGroupConfigs as $classificationStoreKeyGroupConfig) {
                    $classificationStoreFieldDefinition = OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromKeyConfig($classificationStoreKeyGroupConfig);
                    $groupConfig = OpenDxp\Model\DataObject\Classificationstore\GroupConfig::getById($classificationStoreKeyGroupConfig->getGroupId());
                    if (!$groupConfig instanceof OpenDxp\Model\DataObject\Classificationstore\GroupConfig) {
                        continue;
                    }

                    if ($def->isLocalized()) {
                        $classificationStoreMappings[] = $this->handleDefinitionElement($classificationStoreFieldDefinition, $dataport, \array_replace_recursive($mappingOptions, ['targetBrickField' => $def->getName(), 'brickName' => $groupConfig->getName(), 'locale' => 'default']));
                        foreach (Tool::getValidLanguages() as $language) {
                            $childMappings = $this->handleDefinitionElement($classificationStoreFieldDefinition, $dataport, \array_replace_recursive($mappingOptions, ['targetBrickField' => $def->getName(), 'brickName' => $groupConfig->getName(), 'locale' => $language]));
                            $classificationStoreMappings[] = $childMappings;

                            $countClassificationStoreMappings += count($childMappings);
                        }
                    } else {
                        $childMappings = $this->handleDefinitionElement($classificationStoreFieldDefinition, $dataport, \array_replace_recursive($mappingOptions, ['targetBrickField' => $def->getName(), 'brickName' => $groupConfig->getName()]));
                        $classificationStoreMappings[] = $childMappings;

                        $countClassificationStoreMappings += count($childMappings);
                    }

                    if($countClassificationStoreMappings > 200) {
                        break;
                    }
                }

                if($countClassificationStoreMappings <= 200) {
                    $mappings = array_merge($mappings, $classificationStoreMappings);
                }
            }
        } else {
            $mappings[] = $this->createMapping($def, $dataport, $mappingOptions);
        }

        return array_merge(...$mappings);
    }

    /**
     * @param Data $def
     * @param array $dataport
     * @param array $mappingOptions
     * @return array[]
     */
    public function createMapping(Data $def, array $dataport, array $mappingOptions = [], $withPreview = true)
    {
        if (method_exists(Db::getConnection()->getConfiguration(), 'setSQLLogger')) {
            Db::getConnection()->getConfiguration()->setSQLLogger(null);
        }
        if (class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }
        if (method_exists(\Doctrine\Deprecations\Deprecation::class, 'disable')) {
            \Doctrine\Deprecations\Deprecation::disable();
        }

        $this->importer->setDataport($dataport);

        $fieldName = $def->getName();

        $key = $fieldName;
        if (!empty($mappingOptions['locale'])) {
            $key .= '#'.$mappingOptions['locale'];
        }

        $name = $def->getTitle() ?? '';
        if(strpos($fieldName, '__virtual_') !== 0 && strpos($name, $this->translator->trans('pim.mapping.variables.variable.not_in_use', [], 'admin')) === false && strpos($name, $this->translator->trans('deleted', [], 'admin')) === false) {
            $name = $this->translator->trans($name, [], 'admin');
            if (!$name) {
                $name = $this->translator->trans($fieldName, [], 'admin');
            }
        }

        if (!empty($mappingOptions['locale'])) {
            $name .= '#'.$mappingOptions['locale'];
        }

        $field = $key;
        if (!empty($mappingOptions['brickName'])) {
            $key = $mappingOptions['brickName'].'/'.$key;
            $name = $this->translator->trans($mappingOptions['brickName'], [], 'admin').'/'.$name;
        }

        $mapping = [
            $key => array(
                'attribute' => array(
                    'name' => $name,
                    'key' => $field,
                ),
                'attributeKey' => $key,
                'attributeName' => $name,
                'locale' => $mappingOptions['locale'] ?? null,
                'type' => $def->getFieldtype(),
                'templates' => $this->getTemplates($def, $dataport['id'], $mappingOptions),
                'unique' => $def->getUnique() || $def->getIndex() || $def->getName() === 'id' || $def->getName() === 'Complete Object',
                'description' => $def->getTooltip(),

                // Mapping
                'field' => null,
                'settings' => array(),
                'rawValue' => null,
                'example' => null,
                'example_parsed' => [
                    'result' => null,
                    'logs' => [],
                    'valid' => true
                ],
                'history' => []
            )
        ];

        if ($def instanceof Data\Select || $def instanceof Data\Multiselect) {
            $options = [];
            foreach ($def->getOptions() as $option) {
                $optionText = $option['value'];
                if ($option['value'] !== $option['key']) {
                    $optionText .= ' (' . $option['key'] . ')';
                }

                $options[] = $optionText;

                if(count($options) > 10) {
                    $options[] = '...';
                    break;
                }
            }
            $mapping[$key]['description'] .= 'Possible options: '.implode(', ', $options);
        }

        $mapping[$key] = array_replace_recursive($mapping[$key], $mappingOptions);

        if (strpos($mapping[$key]['attributeKey'], '__virtual_') === 0) {
            $parameterName = substr($mapping[$key]['attributeKey'], strlen('__virtual_'));

            if (isset(Helper::getEnvironmentVariables()[$parameterName])) {
                $mapping[$key]['example'] = Helper::getEnvironmentVariables()[$parameterName];
                $mapping[$key]['example_parsed']['result'] = $mapping[$key]['example'];
                $mapping[$key]['rawValue'] = $mapping[$key]['example'];
            }
        }

        $conditions = array(
            'dataportId = ?' => $dataport['id'],
            'fieldName = ?' => $fieldName
        );

        if (!empty($mappingOptions['locale'])) {
            $conditions['locale = ?'] = $mappingOptions['locale'];
        }

        if (!empty($mappingOptions['brickName'])) {
            $conditions['brickName = ?'] = $mappingOptions['brickName'];
        } else {
            $conditions['brickName = ?'] = '';
        }

        $mappingTable = Fieldmapping::getInstance();
        $mappingData = $mappingTable->findOne($conditions);

        if(!empty($mappingData['format'])) {
            $mappingData['format'] = unserialize($mappingData['format'], ['allowed_classes' => false]);
        } elseif($mappingData) {
            $mappingData['format'] = [];
        }

        foreach (Dataport::getInstance()->getVersions($dataport['id']) as $version) {
            $username = $version['user'];

            foreach ($version['config'][Installer::TABLE_FIELDMAPPING] as $fieldMapping) {
                if ($fieldMapping['calculation'] && self::getFieldKey($fieldMapping) === self::getFieldKey(array_merge($mappingOptions, ['fieldName' => $fieldName]))) {
                    if(is_array($fieldMapping['calculation'])) {
                        $fieldMapping['calculation'] = implode("\n", $fieldMapping['calculation']);
                    }
                    $compare = DiffHelper::calculate($fieldMapping['calculation'], $mappingData['calculation'] ?? '', 'SideBySide', [
                        // show how many neighbor lines
                        // Differ::CONTEXT_ALL can be used to show the whole file
                        'context' => Differ::CONTEXT_ALL,
                        // ignore case difference
                        'ignoreCase' => false,
                        // ignore whitespace difference
                        'ignoreWhitespace' => true,
                    ], [
                        // how detailed the rendered HTML in-line diff is? (none, line, word, char)
                        'detailLevel' => 'word',
                        // renderer language: eng, cht, chs, jpn, ...
                        // or an array which has the same keys with a language file
                        'language' => 'eng',
                        // show line numbers in HTML renderers
                        'lineNumbers' => true,
                        // show a separator between different diff hunks in HTML renderers
                        'separateBlock' => true,
                        // show the (table) header
                        'showHeader' => false,
                        // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
                        // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
                        'spacesToNbsp' => false,
                        // HTML renderer tab width (negative = do not convert into spaces)
                        'tabSize' => 4,
                        // this option is currently only for the Combined renderer.
                        // it determines whether a replace-type block should be merged or not
                        // depending on the content changed ratio, which values between 0 and 1.
                        'mergeThreshold' => 0.8,
                        // this option is currently only for the Unified and the Context renderers.
                        // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
                        // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
                        // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
                        'cliColorization' => RendererConstant::CLI_COLOR_ENABLE,
                        // this option is currently only for the Json renderer.
                        // internally, ops (tags) are all int type but this is not good for human reading.
                        // set this to "true" to convert them into string form before outputting.
                        'outputTagAsString' => true,
                        // this option is currently only for the Json renderer.
                        // it controls how the output JSON is formatted.
                        // see available options on https://www.php.net/manual/en/function.json-encode.php
                        'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                        // this option is currently effective when the "detailLevel" is "word"
                        // characters listed in this array can be used to make diff segments into a whole
                        // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
                        // this should bring better readability but set this to empty array if you do not want it
                        'wordGlues' => [' ', '-'],
                        // change this value to a string as the returned diff if the two input strings are identical
                        'resultForIdenticals' => '',
                        // extra HTML classes added to the DOM of the diff container
                        'wrapperClasses' => ['diff-wrapper'],
                    ]);

                    $mapping[$key]['history'][] = ['date' => $version['date'], 'calculation' => $fieldMapping['calculation'], 'user' => $username, 'compare' => $compare, 'timestamp' => $version['timestamp']];

                    break;
                }
            }
        }

        $format = [];

        if (!empty($mappingData)) {
            if (!empty($mappingData['format'])) {
                $format = $mappingData['format'];
            }

            $mapping[$key]['field'] = $mappingData['fieldNo'] ?? null;
        }

        if (isset(self::$mappingDefaults[$def->getFieldtype()]['generateText']) || isset(self::$mappingDefaults[$def->getFieldtype()]['infer'])) {
            $websiteSetting = OpenDxp\Model\WebsiteSetting::getByName('OpenAi.com API Key');
            if ($websiteSetting instanceof OpenDxp\Model\WebsiteSetting) {
                $format['openAiKey'] = $websiteSetting->getData();
            }
        }

        if (isset(self::$mappingDefaults[$def->getFieldtype()]['translateFromLanguage'])) {
            $websiteSetting = OpenDxp\Model\WebsiteSetting::getByName('DeepL API Key');
            if ($websiteSetting instanceof OpenDxp\Model\WebsiteSetting) {
                $format['deeplApiKey'] = $websiteSetting->getData();
            }
        }

        $mapping[$key]['settings'] = self::createSettings($def->getFieldtype(), !empty($mappingData['keyMapping']), $format, $mappingData['calculation'] ?? '');

        $itemMold = null;
        try {
            $itemMold = $this->itemMoldBuilder->getItemMold($dataport['id']);
        } catch (\Exception $e) {
        }

        if ($mapping[$key]['settings']['keyMapping'] && $itemMold instanceof Concrete && !$def->getIndex() && !in_array(Helper::prefixObjectSystemColumn($def->getName()), self::getSystemFields(), true) && $key !== 'Complete Object') {
            $mapping[$key]['settings']['keyMappingIndexed'] = false;
        }

        $targetConfig = $this->importer->getTargetConfig();

        if($this->logger === null) {
            $this->logger = new InMemoryLogger();
        } elseif(method_exists($this->logger, 'clearLogs')) {
            $this->logger->clearLogs();
        }

        if ($this->importer instanceof LoggerAwareInterface) {
            $this->importer->setLogger($this->logger);
        }

        if (strtolower($fieldName) === 'gtin' && $def instanceof Data\Numeric) {
            $this->logger->notice('Field '.$fieldName.' should not be of type "Number" because a GTIN can contain leading zeros which cannot be handled by a numeric field. Please change it to an input field');
        }

        $fieldKey = self::getFieldKey(array_merge($mappingOptions, ['fieldName' => $fieldName]));
        if($itemMold instanceof Concrete) {
            $classId = $itemMold->getClassId();
        } else {
            $classId = Service::getElementType($itemMold);
        }

        if($classId) {
            $mapping[$key]['hasIgnoredData'] = (bool)ImportIgnoreData::getInstance()->findOne(['`field` = ?' => $fieldKey, 'classId = ?' => $classId]);
        }

        if($withPreview && ($this->previewItem === null || empty($this->previewItem['rawItemData']))) {
            $rawItemId = null;
            $request = self::getRequest();
            try {
                $rawItemId = Uuid::fromInteger($request->cookies->get('rawItem-'.$dataport['id']))->getBytes();
            } catch(\Throwable $e) {
            }

            fetchRawItem:
            $dataportResourceQuery = 'SELECT dataport_resource.* 
                FROM '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource 
                INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON dataport_resource.id=rawitem.dataport_resource_id 
                WHERE dataport_resource.dataportId = ?
                AND resource = ?';

            $user = Helper::getUser();
            $dataportResourceParams = [$dataport['id']];
            if($user instanceof OpenDxp\Model\User) {
                $dataportResourceParams[] = json_encode(['locale' => $user->getLanguage()]);
            } else {
                $dataportResourceParams[] = json_encode([]);
            }

            if($rawItemId) {
                $dataportResourceQuery .= ' AND rawitem.id=?';
                $dataportResourceParams[] = $rawItemId;
            }
            $dataportResourceQuery .= ' LIMIT 1';
            $dataportResource = PimcoreDbRepository::getInstance()->findRowInSql($dataportResourceQuery, $dataportResourceParams);

            if(!$dataportResource) {
                $dataportResourceQuery = 'SELECT dataport_resource.* 
                FROM '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource 
                INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON dataport_resource.id=rawitem.dataport_resource_id 
                WHERE dataport_resource.dataportId = ?';
                $dataportResourceParams = [$dataport['id']];
                if ($rawItemId) {
                    $dataportResourceQuery .= ' AND rawitem.id=?';
                    $dataportResourceParams[] = $rawItemId;
                }
                $dataportResourceQuery .= ' LIMIT 1';
                $dataportResource = PimcoreDbRepository::getInstance()->findRowInSql($dataportResourceQuery, $dataportResourceParams);
            }

            $rawItem = null;
            if ($dataportResource) {
                $rawItems = RawItem::getInstance();
                $rawItemConditions = ['dataport_resource_id = ?' => $dataportResource['id']];
                if ($rawItemId) {
                    $rawItemConditions['id = ?'] = $rawItemId;
                }
                $rawItem = $rawItems->findOne($rawItemConditions, (empty($targetConfig['itemClass']) || $dataport['sourcetype'] === 'pimcore') ? 'priority' : 'updated,priority');
            }

            if(!$rawItem && $rawItemId) {
                $rawItemId = null;
                unset($_COOKIE['rawItem-'.$dataport['id']]);
                setcookie('rawItem-'.$dataport['id'], '', time() - 3600, '/');
                goto fetchRawItem;
            }

            $this->previewItem['rawItemData'] = null;

            $isMultivalue = [];
            $sourceconfig = $dataport['sourceconfig'];
            if (is_array($sourceconfig['fields'])) {
                $isMultivalue = array_filter(
                    $sourceconfig['fields'],
                    static function ($values) {
                        return isset($values['multiValues']) && $values['multiValues'] === true;
                    }
                );
            }

            if ($rawItem) {
                $rawItemDataRepository = RawItemData::getInstance();
                $this->previewItem['rawItemDataRows'] = $rawItemDataRepository->find(
                    [
                        'rawItemId = ?' => $rawItem['id']
                    ]
                );

                $this->previewItem['rawItemData'] = [];
                foreach ($this->previewItem['rawItemDataRows'] as &$rawItemDataRow) {
                    if (array_key_exists('field_'.$rawItemDataRow['fieldNo'], $isMultivalue)) {
                        $rawItemDataRow['rawValue'] = $rawItemDataRow['value'];
                        $unserialized = unserialize($rawItemDataRow['value'], ['allowed_classes' => false]);
                        if (\is_array($unserialized)) {
                            $rawItemDataRow['value'] = $unserialized;
                        } elseif (\is_object($unserialized)) {
                            $rawItemDataRow['value'] = $unserialized;
                        }
                    } elseif (!is_numeric($rawItemDataRow['value']) && !preg_match('/^"[^"]+"$/', $rawItemDataRow['value'])) {
                        $decodedValue = json_decode($rawItemDataRow['value'], true);
                        if (json_last_error() === \JSON_ERROR_NONE) {
                            $rawItemDataRow['rawValue'] = $rawItemDataRow['value'];
                            $rawItemDataRow['value'] = $decodedValue;
                        }
                    }

                    unset($rawItemDataRow['rawItemId']);

                    $this->previewItem['rawItemData']['field_'.$rawItemDataRow['fieldNo']] = $rawItemDataRow;
                }
                unset($rawItemDataRow);
            } else {
                try {
                    $this->previewItem['rawItemDataRows'] = [];

                    $oldSetting = ini_get('display_errors');
                    ini_set('display_errors', 0);
                    $parser = Dataport::getInstance()->getParser($dataport['id']);
                    if (method_exists($parser, 'setForce')) {
                        $parser->setForce(true);
                    }
                    $parser->setLimit(1);

                    $this->previewItem['rawItemData'] = $parser->current();
                    unset($this->previewItem['rawItemData']['__updated']);
                    foreach ((array)$this->previewItem['rawItemData'] as $fieldNumberKey => $value) {
                        if (strpos($fieldNumberKey, 'field_') === 0) {
                            $rawItemDataRow = [
                                'rawItemId' => null,
                                'fieldNo' => substr($fieldNumberKey, strlen('field_')),
                            ];

                            $value = (string)$value;

                            if (array_key_exists($fieldNumberKey, $isMultivalue)) {
                                $rawItemDataRow['rawValue'] = $value;
                                $unserialized = unserialize($value, ['allowed_classes' => false]);
                                if (\is_array($unserialized)) {
                                    $value = $unserialized;
                                } elseif (\is_object($unserialized)) {
                                    $value = $unserialized;
                                }
                            } elseif (!is_numeric($value) && !preg_match('/^"[^"]+"$/', $value)) {
                                $decodedValue = json_decode($value, true);
                                if (json_last_error() === \JSON_ERROR_NONE) {
                                    $rawItemDataRow['rawValue'] = $value;
                                    $value = $decodedValue;
                                }
                            }

                            $rawItemDataRow['value'] = $value;
                            $this->previewItem['rawItemDataRows'][] = $rawItemDataRow;
                            $this->previewItem['rawItemData'][$fieldNumberKey] = $rawItemDataRow;
                        }
                    }

                    ini_set('display_errors', $oldSetting);
                } catch (\Throwable $e) {
                    $this->previewItem['rawItemData'] = null;
                }
            }

            foreach($sourceconfig['fields'] as $fieldNumberKey => $sourceConfigField) {
                if(!isset($this->previewItem['rawItemData'][$fieldNumberKey])) {
                    $this->previewItem['rawItemData'][$fieldNumberKey] = ['fieldNo' => substr($fieldNumberKey, strlen('field_')), 'value' => null, 'rawValue' => null];
                    $this->previewItem['rawItemDataRows'][] = $this->previewItem['rawItemData'][$fieldNumberKey];
                }
            }

            $fieldTable = RawItemField::getInstance();
            $existingFields = $fieldTable->find(array('dataportId = ?' => $dataport['id']), 'priority');

            foreach ($existingFields as $field) {
                // add dummy data if parser cannot find data field
                if ($this->previewItem['rawItemData'] !== null && !array_key_exists('field_'.$field['fieldNo'], $this->previewItem['rawItemData'])) {
                    $this->previewItem['rawItemData']['field_'.$field['fieldNo']] = array(
                        'value' => null,
                    );
                }

                foreach ($this->previewItem['rawItemDataRows'] as $rawItemDataRow) {
                    if ($field['fieldNo'] == $rawItemDataRow['fieldNo']) {
                        $this->previewItem['rawItemData'][$field['name']] = $rawItemDataRow;
                        continue 2;
                    }
                }
            }

            try {
                $list = $itemMold::getList(
                    [
                        'unpublished' => true,
                        'objectTypes' => [
                            AbstractObject::OBJECT_TYPE_OBJECT,
                            AbstractObject::OBJECT_TYPE_VARIANT,
                        ],
                        'locale' => Tool::getDefaultLanguage(),
                        'limit' => 1
                    ]
                );

                $keyMappings = $mappingTable->find(
                    [
                        'dataportId = ?' => $dataport['id'],
                        'keyMapping = ?' => 1,
                        'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action']
                    ]
                );

                if ($keyMappings) {
                    $this->previewItem['keyDatasets'] = $this->importer->getKeyDatasets($keyMappings, $this->previewItem['rawItemData']);

                    $listLocale = null;
                    foreach ($this->previewItem['keyDatasets'][0] as $keyColumn => $keyValue) {
                        if ($keyValue === null) {
                            continue;
                        }

                        $localeHashPosition = strpos($keyColumn, '#');
                        if ($localeHashPosition !== false) {
                            $locale = substr($keyColumn, $localeHashPosition + 1);

                            if ($listLocale !== null) {
                                throw new Exception('Currently it is not possible to use key fields in different locales');
                            }
                            $listLocale = $locale;
                        }
                    }

                    if ($listLocale !== null && \method_exists($list, 'setLocale')) {
                        $list->setLocale($listLocale);
                    }

                    $conditions = $this->importer->getKeyConditions($this->previewItem['keyDatasets'][0]);
                    if ($conditions === null) {
                        $nullKeyColumn = null;
                        foreach($this->previewItem['keyDatasets'][0] as $keyColumn => $keyValue) {
                            if ($keyValue === null) {
                                $nullKeyColumn = $keyColumn;
                                break;
                            }
                        }
                        $mapping[$key]['example_parsed']['result'] = sprintf($this->translator->trans('pim.mapping.key_attribute.skipping.key_field_null', [], 'admin'), $nullKeyColumn);
                        $this->previewItem = null;
                        return $mapping;
                    }

                    foreach ($conditions as $column => $keyValue) {
                        if (!is_array($keyValue)) {
                            $keyValue = [$keyValue];
                        }

                        foreach ($keyValue as $keyValueItem) {
                            if (strpos($column, '/') !== false) {
                                $columnParts = explode('/', $column);
                                /** @var AbstractData $brick */
                                $brick = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($columnParts[0]), [$itemMold]);
                                $fieldDefinition = $this->importer::getFieldDefinition($brick, ['fieldName' => $columnParts[1]]);
                                $list->addObjectBrick($brick->getType());
                                $column = $columnParts[1];
                            } else {
                                $fieldDefinition = $this->importer::getFieldDefinition($itemMold, $column);
                            }

                            addListCondition:
                            if ($fieldDefinition instanceof Data\ManyToOneRelation && $keyValueItem instanceof ElementInterface) {
                                $list->addConditionParam('`'.$column.'__id`=?', $keyValueItem->getId());
                                $list->addConditionParam('`'.$column.'__type`=?', \OpenDxp\Model\Element\Service::getElementType($keyValueItem));
                            } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations && $keyValueItem instanceof ElementInterface) {
                                $list->addConditionParam("`$column` LIKE '%,".$keyValueItem->getId().",%'");
                            } elseif($fieldDefinition instanceof Date) {
                                /** @var DateTimeInterface $keyValueItem */
                                if ($fieldDefinition->getColumnType() === 'date') {
                                    $list->addConditionParam('`'.$column.'` = ?', $keyValueItem->format('Y-m-d'));
                                } else {
                                    $dateRangeStart = clone $keyValueItem;
                                    $dateRangeEnd = clone $keyValueItem;
                                    $dateRangeStart->setTime(0,0);
                                    $dateRangeEnd->setTime(23,59,59);

                                    $list->addConditionParam('`'.$column.'` BETWEEN ? AND ?', [$dateRangeStart->getTimestamp(), $dateRangeEnd->getTimestamp()]);
                                }
                            } elseif ($fieldDefinition instanceof Data\Multiselect) {
                                $list->addConditionParam("`$column` LIKE '%,".$keyValueItem.",%'");
                            } elseif ($fieldDefinition instanceof Data\BooleanSelect) {
                                $list->addConditionParam("`$column` = ?", $keyValueItem ? 1 : -1);
                            } elseif ($fieldDefinition instanceof Data\QuantityValue) {
                                /** @var QuantityValue $keyValueItem */
                                $list->addConditionParam('`'.$column.'__value` = ?', $keyValueItem->getValue());
                                $list->addConditionParam('`'.$column.'__unit` = ?', $keyValueItem->getUnitId());
                            } elseif (is_scalar($keyValueItem)) {
                                $list->addConditionParam("`$column` = ?", $keyValueItem);
                            } elseif (is_array($keyValueItem) && (isset($keyValue['id']) || isset($keyValue['type']) || isset($keyValue['fullpath']))) {
                                $keyValueProposal = null;
                                if (isset($keyValue['id'], $keyValue['type'])) {
                                    $keyValueProposal = OpenDxp\Model\Element\Service::getElementById($keyValue['type'], $keyValue['id']);
                                } elseif (isset($keyValue['fullpath'], $keyValue['type'])) {
                                    $keyValueProposal = OpenDxp\Model\Element\Service::getElementByPath($keyValue['type'], $keyValue['fullpath']);
                                }

                                if ($keyValueProposal) {
                                    $keyValueItem = $keyValueProposal;
                                    goto addListCondition;
                                }
                            }
                        }
                    }
                }

                $item = $list->load()[0] ?? null;
                if ($item !== null && !($targetConfig['mode'] & ImportconfigController::MODE_EDIT)) {
                    $mapping[$key]['example_parsed']['result'] = $this->translator->trans('pim.mapping.key_attribute.skipping.edit_not_allowed', [], 'admin');
                    $this->previewItem = null;
                    return $mapping;
                }

                if ($item === null && !($targetConfig['mode'] & ImportconfigController::MODE_CREATE)) {
                    $mapping[$key]['example_parsed']['result'] = $this->translator->trans('pim.mapping.key_attribute.skipping.create_not_allowed', [], 'admin');
                    $this->previewItem = null;
                    return $mapping;
                }

                $this->previewItem['itemMold'] = $list->load()[0] ?? $itemMold;
            } catch (\Throwable $e) {
            }
        }

        if (!in_array($fieldName, ['__result_callback', '__result_action', '__init_action'])) {
            $itemMold = $this->previewItem['itemMold'] ?? $itemMold;
        }

        $virtualFields = [];
        if (!empty($mappingData['calculation']) && \preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $mappingData['calculation'], $variables)) {
            $mapping = [$mapping];
            $fieldTable = RawItemField::getInstance();
            foreach (array_unique($variables[1]) as $variable) {
                $variableParts = explode('#', $variable);
                if (empty($mappingTable->findOne(['dataportId = ?' => $dataport['id'], 'fieldName IN (?)' => ['__virtual_'.$variable, $variableParts[0]], 'locale = ?' => $variableParts[1] ?? '', 'brickName = ?' => '']))) {
                    $virtualFieldDefinition = Importer::getFieldDefinition(null, '__virtual_'.$variable);
                    $virtualFieldDefinition->setTitle($variable);

                    $rawItemField = $fieldTable->findOne(['dataportId = ?' => $dataport['id'], 'name = ?' => $variable]);
                    if(!$rawItemField) {
                        $rawItemFields = $fieldTable->find(['dataportId = ?' => $dataport['id']]);
                        foreach($rawItemFields as $rawItemFieldCandidate) {
                            if(trim(str_replace([':', '{', '}'], '_', $rawItemFieldCandidate['name']), '_') === $variable) {
                                $rawItemField = $rawItemFieldCandidate;
                                break;
                            }

                            if (str_replace('_', ':', $rawItemFieldCandidate['name']) === $variable) {
                                $rawItemField = $rawItemFieldCandidate;
                                break;
                            }
                        }
                    } elseif ($rawItemField && $rawItemField['name'] !== $variable) {
                        // if raw data field's name has different case (raw data field "fieldname" vs. virtual field "Fieldname")
                        $rawItemField = null;
                    }

                    $virtualFieldMapping = $this->createMapping($virtualFieldDefinition, $dataport, ['field' => $rawItemField['fieldNo'] ?? null], $withPreview);
                    $mapping[] = $virtualFieldMapping;

                    $virtualFields['__virtual_'.$variable] = $virtualFieldMapping['__virtual_'.$variable]['example_parsed']['result'];
                }
            }
            $mapping = array_merge(...$mapping);
        }

        if ($itemMold !== null) {
            $value = $this->previewItem['rawItemData']['field_'.$mapping[$key]['field']]['value'] ?? null;

            if (!empty($mappingData['fieldName']) && strpos($mappingData['fieldName'], '__virtual_') === 0) {
                $parameterName = substr($mappingData['fieldName'], strlen('__virtual_'));

                if (\OpenDxp::getContainer()->hasParameter($parameterName)) {
                    $value = \OpenDxp::getContainer()->getParameter($parameterName);
                }
            }

            $currentValue = null;

            $db = Db::get();

            $transactionUsed = false;
            if(!$db->isTransactionActive()) {
                $db->beginTransaction();
                $transactionUsed = true;
            }

            $serializer = new Serializer();
            if (!isset($this->previewItem['currentObjectData']) && $withPreview) {
                $this->previewItem['currentObjectData'] = OpenDxp\Cache::load('mapping-preview-'.$dataport['id'].'-'.$itemMold->getId());
                if (!$this->previewItem['currentObjectData']) {
                    $serializer::trimOutputForBetterPerformance();
                    $this->previewItem['currentObjectData'] = $serializer->getAttributesArrayForObject($itemMold);
                    OpenDxp\Cache::save($this->previewItem['currentObjectData'], 'mapping-preview-'.$dataport['id'].'-'.$itemMold->getId(),['mapping-preview-'.$dataport['id'], 'mapping-preview-element-'.$itemMold->getId()]);
                }
            }

            $this->previewItem['currentObjectData'] = $this->previewItem['currentObjectData'] ?? null;

            $allMappings = $this->importer->getMappings();

            if($mappingData) {
                $currentMappingIndex = self::getFieldKey($mappingData);
            } else {
                $currentMappingIndex = null;
            }

            if ($currentMappingIndex !== null) {
                $mappingDependencies = Importer::getMappingDependencies($allMappings);

                foreach ($mappingDependencies[$currentMappingIndex] ?? [] as $dependencyIndex) {
                    if(!isset($allMappings[$dependencyIndex])) {
                        // implicitly mapped virtual fields (raw data field name == virtual field name
                        $allMappings[$dependencyIndex] = ['fieldName' => $dependencyIndex];
                    }

                    $dependentMapping = $allMappings[$dependencyIndex];

                    if (!empty($dependentMapping['targetBrickField'])) {
                        $fieldDefinition = Importer::getFieldDefinition($itemMold, $dependentMapping['targetBrickField']);
                    } else {
                        $fieldDefinition = Importer::getFieldDefinition($itemMold, $dependentMapping['fieldName']);
                    }

                    $dependentMappingOptions = [];
                    if (!empty($dependentMapping['brickName'])) {
                        $dependentMappingOptions['brickName'] = $dependentMapping['brickName'];
                    }
                    if (!empty($dependentMapping['locale'])) {
                        $dependentMappingOptions['locale'] = $dependentMapping['locale'];
                    }

                    $dependentMappingData = $this->createMapping($fieldDefinition, $dataport, $dependentMappingOptions, $withPreview);
                    $dependentMappingData = reset($dependentMappingData);

                    if (isset($dependentMappingData['example_value'])) {
                        if (strpos($dependentMapping['fieldName'], '__virtual_') !== 0) {
                            try {
                                $updatableObject = Importer::getUpdatableObject($itemMold, $dependentMapping);
                                Importer::setValue(
                                    $updatableObject,
                                    $dependentMapping,
                                    $dependentMappingData['example_value'],
                                    !empty($dependentMapping['locale']) ? [$dependentMapping['locale']] : []
                                );
                                $serializer::trimOutputForBetterPerformance();
                                $fieldSerialization = $serializer->serializeField(
                                    $updatableObject,
                                    $fieldDefinition,
                                    !empty($dependentMapping['locale']) ? [$dependentMapping['locale']] : []
                                );

                                $this->previewItem['currentObjectData'] = array_merge(
                                    $this->previewItem['currentObjectData'],
                                    [
                                        $fieldDefinition->getName().($dependentMapping['locale'] && !$fieldDefinition instanceof Data\Objectbricks ? '#'.$dependentMapping['locale'] : '') => $fieldSerialization
                                    ]
                                );
                            } catch (\Exception $e) {
                            }
                        }

                        $virtualFields[self::getFieldKey($dependentMapping)] = $dependentMappingData['example_value'];
                    }
                }
            }

            $request = Helper::getRequest();
            $user = Helper::getUser();
            $jsParams = [
                'rawItemData' => $this->previewItem['rawItemData'] ?? [],
                'value' => $value,
                'currentValue' => null,
                'currentObjectData' => $this->previewItem['currentObjectData'],
                'virtualFields' => $virtualFields,
                'keyValues' => $this->previewItem['keyDatasets'][0] ?? [],
                'field' => $fieldName,
                'request' => $request,
                'logger' => $this->logger,
                'transfer' => $request->attributes->get('transfer'),
                'translator' => $this->translationHelper,
                'context' => [
                    'dataportId' => $dataport['id'],
                    'dataport' => [
                        'id' => $dataport['id'],
                        'name' => $dataport['name'],
                    ],
                    'user' => [
                        'id' => $user->getId(),
                        'username' => $user->getUsername()
                    ]
                ],
            ];

            if (!empty($mappingOptions['locale'])) {
                $jsParams['locale'] = $mappingOptions['locale'];
            }

            if ($jsParams['currentObjectData'] !== null && array_key_exists($def->getName().(!empty($mappingOptions['locale']) ? '#'.$mappingOptions['locale'] : ''), $jsParams['currentObjectData'])) {
                $jsParams['currentValue'] = $jsParams['currentObjectData'][$def->getName().(!empty($mappingOptions['locale']) ? '#'.$mappingOptions['locale'] : '')];
            } elseif (!empty($mappingOptions['targetBrickField']) && !empty($mappingOptions['brickName']) && isset($jsParams['currentObjectData'][$mappingOptions['targetBrickField']][$mappingOptions['brickName']][$def->getName()])) {
                $jsParams['currentValue'] = $jsParams['currentObjectData'][$mappingOptions['targetBrickField']][$mappingOptions['brickName']][$def->getName()];
            }

            if (!empty($mappingData['keyMapping']) && is_array($value)) {
                $jsParams['value'] = $jsParams['keyValues'][$mappingData['fieldName']] ?? null;
            }

            if(!is_scalar($jsParams['value'])) {
                $mapping[$key]['rawValue'] = ($jsParams['value'] === null ? null : json_encode($jsParams['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            } else {
                $mapping[$key]['rawValue'] = $jsParams['value'];
            }

            if (!isset($this->previewItem['rawItemData'])) {
                $this->previewItem['rawItemData'] = array_map(
                    static function ($rawItem) {
                        if (isset($rawItem['rawValue'])) {
                            unset($rawItem['rawValue']);
                        }

                        return $rawItem;
                    },
                    array_filter(
                        $jsParams['rawItemData'],
                        static function ($key) {
                            return strpos($key, 'field_') !== 0;
                        },
                        ARRAY_FILTER_USE_KEY
                    )
                );
            }

            $mapping[$key]['rawItemData'] = $this->previewItem['rawItemData'];

            $mapping[$key]['currentValue'] = json_encode($jsParams['currentValue'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $mapping[$key]['currentObjectData'] = &$this->previewItem['currentObjectData'];
            $mapping[$key]['keyValues'] = implode(
                "\n\n",
                array_map(
                    static function ($virtualField, $index) {
                        return 'Iteration '.((int)$index + 1).":\n".json_encode($virtualField, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    },
                    $this->previewItem['keyDatasets'] ?? [],
                    array_keys($this->previewItem['keyDatasets'] ?? [])
                )
            );


            if ($def->getName() === '__init_action') {
                $mapping[$key]['example'] = $this->initFunctionResult[$dataport['id']] ?? null;
                $mapping[$key]['example_parsed']['result'] = $mapping[$key]['example'];
            } elseif (!empty($mappingData['calculation']) && !in_array($def->getName(), ['__result_callback', '__result_action'], true) && CallbackFunction::isEngineAvailable($targetConfig['javascriptEngine'])) {
                try {
                    $mapping[$key]['example'] = CallbackFunction::evaluateScript($mappingData['calculation'], $targetConfig['javascriptEngine'], $jsParams);
                } catch (\Throwable $ex) {
                    $mapping[$key]['example'] = null;
                    $mapping[$key]['example_parsed'] = [
                        'result' => $ex->getMessage(),
                        'logs' => [],
                        'valid' => false,
                    ];
                }
            } elseif (!in_array($def->getName(), ['__result_callback', '__result_action'], true)) {
                // unmapped virtual fields
                $mapping[$key]['example'] = $value ?? $mapping[$key]['example'];
                $mapping[$key]['example_parsed']['result'] = $mapping[$key]['example'];
            } elseif(!empty($mappingData['calculation'])) {
                $mapping[$key]['example'] = '~~no-preview~~';
                $mapping[$key]['example_parsed']['result'] = $mapping[$key]['example'];
            } else {
                $existingMappings = Fieldmapping::getInstance()->find(
                    [
                        'dataportId = ?' => $dataport['id'],
                        'fieldName IN (?)' => ['__result_callback', '__result_action'],
                    ]
                );
                if(count($existingMappings) === 0) {
                    $mapping[$key]['example'] = '~~export-hint~~';
                    $mapping[$key]['example_parsed']['result'] = $mapping[$key]['example'];
                }
            }

            if (!empty($mappingData['keyMapping']) && is_array($mapping[$key]['example'])) {
                $mapping[$key]['example'] = $this->previewItem['keyDatasets'][0][Helper::getFieldKey($mappingData)];

                if(count($this->previewItem['keyDatasets'] ?? []) > 1) {
                    $iterationResults = [];
                    foreach (array_keys($this->previewItem['keyDatasets']) as $keyDatasetIndex) {
                        $iterationResults[] = 'Iteration '.($keyDatasetIndex + 1).': '.$this->previewItem['keyDatasets'][$keyDatasetIndex][Helper::getFieldKey($mappingData)];
                    }
                    $this->logger->notice('This is the example result for first iteration. The raw data item will be processed multiple times: <ul><li>'.implode('</li><li>', $iterationResults).'</li>');
                }
            }

            if ($mapping[$key]['example_parsed']['valid'] && ($mapping[$key]['example'] || (!empty($mappingData['calculation']) && !in_array($def->getName(), ['__result_callback', '__result_action'], true) && CallbackFunction::isEngineAvailable($targetConfig['javascriptEngine'])))) {
                try {
                    $dataQuerySelectorForIndexCheck = $mapping[$key]['example'];
                    if (is_array($dataQuerySelectorForIndexCheck)) {
                        $dataQuerySelectorForIndexCheck = reset($mapping[$key]['example']);
                    }

                    if($key === 'path') {
                        if (empty($mapping[$key]['example'])) {
                            $parsedValue = $this->importer->getItemFolder();
                        } elseif ($mapping[$key]['example'] instanceof AbstractModel) {
                            $parsedValue = $mapping[$key]['example'];
                        } elseif (is_array($mapping[$key]['example']) && isset($mapping[$key]['example']['fullpath'])) {
                            $parsedValue = Service::getElementByPath($mapping[$key]['example']['type'], $mapping[$key]['example']['fullpath']);
                        } elseif (is_array($mapping[$key]['example']) && isset($mapping[$key]['example']['path'])) {
                            $parsedValue = Service::getElementByPath($mapping[$key]['example']['type'], $mapping[$key]['example']['path']);
                        } elseif (is_string($mapping[$key]['example']) && \preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*$/u', $mapping[$key]['example'])) {
                            $parsedValue = $this->importer->getOneObjectByIdentifier($mapping[$key]['example'], $itemMold);
                            if ($parsedValue === null) {
                                $objectIdentifierParts = $this->importer->getObjectIdentifierParts($mapping[$key]['example']);

                                $parsedValue = $this->importer->getItemFolder().'/'.$objectIdentifierParts[2];
                            }
                        } else {
                            if (strpos($mapping[$key]['example'], '/') !== 0) {
                                // relative path
                                $parsedValue = rtrim($this->importer->getItemFolder()->getRealFullPath(), '/').'/'.$mapping[$key]['example'];
                            } else {
                                $parsedValue = $mapping[$key]['example'];
                            }

                            $objectPathArray = explode('/', $parsedValue);
                            $elementType = $this->importer->getItemType();
                            $objectPathArray = array_map(static function ($pathPart) use ($elementType) {
                                return Service::getValidKey($pathPart, $elementType);
                            }, $objectPathArray);
                            $parsedValue = implode('/', $objectPathArray);
                        }
                    } else {
                        $mappingData['fieldName'] = $mappingData['fieldName'] ?? $fieldName;
                        $parsedValue = $this->importer->map(
                            $mappingData,
                            $mapping[$key]['example'],
                            $currentValue,
                            $def,
                            $itemMold
                        );
                    }

                    if ((is_string($dataQuerySelectorForIndexCheck) || $dataQuerySelectorForIndexCheck instanceof Stringable) && method_exists($this->importer, 'getObjectIdentifierParts') && \preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*$/u', $dataQuerySelectorForIndexCheck)) {
                        if ($parsedValue !== null && $parsedValue !== $mapping[$key]['example']) { // value is a data query selector
                            try {
                                $dataQuerySelectorParts = $this->importer->getObjectIdentifierParts((string)$dataQuerySelectorForIndexCheck);

                                $relationItemMold = $this->itemMoldBuilder->getItemMoldByClassname($dataQuerySelectorParts[0]);
                                if ($relationItemMold instanceof Concrete) {
                                    $fieldDefinition = Importer::getFieldDefinition($relationItemMold, explode(':', explode('#', $dataQuerySelectorParts[1])[0])[0]);

                                    if (!$fieldDefinition instanceof Data\Relations\AbstractRelations && !$fieldDefinition instanceof Data\Wysiwyg && !$fieldDefinition instanceof Data\Textarea && !$fieldDefinition->getIndex() && !in_array(Helper::prefixObjectSystemColumn($fieldDefinition->getName()), self::getSystemFields(), true)) {
                                        $this->logger->notice(sprintf($this->translator->trans('pim.mapping.data_query_selector_missing_index', [], 'admin'), $fieldDefinition->getName(), $relationItemMold->getClass()->getName()));
                                    }
                                }
                            } catch (\Throwable $e) {
                            }
                        }
                    }

                    $mapping[$key]['example_value'] = $parsedValue;

                    $def->checkValidity($parsedValue, true);

                    $parsedValue = Importer::getLogOutput($parsedValue);

                    $mapping[$key]['example_parsed'] = [
                        'result' => $parsedValue,
                        'logs' => $this->logger->getLogs(),
                        'valid' => true
                    ];
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    if ($e instanceof ValidationException && is_array($e->getSubItems()) && count($e->getSubItems()) > 0) {
                        $error .= implode(
                            ",\n",
                            array_map(
                                static function (\Exception $e) {
                                    return $e->getMessage();
                                },
                                $e->getSubItems()
                            )
                        );
                    }

                    $mapping[$key]['example_parsed'] = [
                        'result' => $error,
                        'logs' => [],
                        'valid' => false,
                    ];
                }

                if (!is_scalar($mapping[$key]['example']) && $mapping[$key]['example'] !== null) {
                    $mapping[$key]['example'] = Importer::getLogOutput($mapping[$key]['example']);
                }
            } elseif (strtolower($def->getName()) === 'path' && ($mapping[$key]['example'] === '' || $mapping[$key]['example'] === null)) {
                $mapping[$key]['example_parsed'] = [
                    'result' => $this->previewItem['currentObjectData']['path'] ?? $this->importer->getItemFolder()->getFullPath(),
                    'logs' => [],
                    'valid' => true,
                ];
            }

            if($transactionUsed) {
                try {
                    $db->rollBack();
                } catch (Exception $e) {
                }
            }

            if (is_string($mapping[$key]['example']) && mb_strlen($mapping[$key]['example']) > 10000 && strpos($mapping[$key]['example'], '[DEBUG] ') === false) {
                $mapping[$key]['example'] = mb_substr($mapping[$key]['example'], 0, 10000).'...';
            }

            if (is_string($mapping[$key]['example_parsed']['result']) && mb_strlen($mapping[$key]['example_parsed']['result']) > 10000 && strpos($mapping[$key]['example_parsed']['result'], '[DEBUG] ') === false) {
                $mapping[$key]['example_parsed']['result'] = mb_substr($mapping[$key]['example_parsed']['result'], 0, 10000).'...';
            }
        }

        //$unexpectedOutput = ob_get_clean();
        $mapping[$key]['output'] = '';
        return $mapping;
    }

    /**
     * @param $type
     * @param bool $keyMapping
     * @param array $format
     * @param string $calculcation
     * @return array
     */
    private static function createSettings($type, $keyMapping = false, $format = array(), $calculcation = '')
    {
        if (!is_array($format)) {
            $format = array();
        }

        if (!is_bool($keyMapping)) {
            $keyMapping = false;
        }

        $currentFormat = $format;
        $defaults = self::getMappingDefaults();
        $format = [];
        if (array_key_exists($type, $defaults)) {
            $format = $defaults[$type];
            foreach ($currentFormat as $attr => $value) {
                $format[$attr] = $value;
            }
        }
        $format['writeProtected'] = $currentFormat['writeProtected'] ?? false;

        return array(
            'keyMapping' => $keyMapping,
            'keyMappingIndexed' => true,
            'format' => $format,
            'calculation' => $calculcation,
        );
    }

    /**
     * @return array
     */
    public static function getMappingDefaults()
    {
        return self::$mappingDefaults;
    }

    public static function getCommonPrefixLength($values)
    {
        sort($values);
        $s1 = $values[0];               // First string
        $s2 = $values[\count($values) - 1]; // Last string
        $len = min(mb_strlen($s1), mb_strlen($s2));

        $lastWhitespacePosition = -1;
        for ($commonPrefixLength = 0; $commonPrefixLength < $len; $commonPrefixLength++) {
            if (mb_strtolower($s1[$commonPrefixLength]) !== mb_strtolower($s2[$commonPrefixLength])) {
                $commonPrefixLength = $lastWhitespacePosition + 1;
                break;
            }

            $currentCharIsAlphanumeric = preg_match('/[\pN\pL]/u', $s1[$commonPrefixLength]);
            if (!$currentCharIsAlphanumeric) {
                $lastWhitespacePosition = $commonPrefixLength;
            }
        }

        return $commonPrefixLength;
    }

    public function getTemplates(Data $def, $dataportId, array $mapping = [])
    {
        $templates = [];

        $dataports = Dataport::getInstance();
        $dataport = $dataports->get($dataportId);

        $sourceConfig = $dataport['sourceconfig'];
        $targetConfig = $dataport['targetconfig'];

        $translator = \OpenDxp::getContainer()->get('translator');
        $user = Tool\Admin::getCurrentUser();
        if ($user instanceof OpenDxp\Model\User) {
            $locale = $user->getLanguage();
            \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($locale);
        }

        if ($def instanceof Data\CalculatedValue && $def->getName() === '__result_callback') {
            $fieldTable = RawItemField::getInstance();
            $existingFields = $fieldTable->find(array('dataportId = ?' => $dataportId), 'priority');

            $templates[] = [
                'value' => 'if(!$params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/xml\');
    $result = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>";
} else {
    $result = \'\';
}

$addCDATAIfNecessary = function($value) {
    $value = (string)$value;
    if(htmlspecialchars($value) !== $value) {
        return \'<![CDATA[\'.$value.\']]>\';
    }
    return $value;
};

$writeTag = function($data) use (&$writeTag, $addCDATAIfNecessary) {
    $result = \'\';
    if(is_array($data)) {
        foreach($data as $tagName => $valueItem) {
            if(is_numeric($tagName)) {
                $tagName = \'item\';
            }
            $result .= \'<\'.trim(preg_replace(\'/[^\p{L}\p{Nd}]+/u\', \'_\', $tagName), \'_\').\'>\';
            if($valueItem instanceof \stdClass || is_array($valueItem)) {
                $result .= $writeTag((array)$valueItem);
            } else {
                $result .= $addCDATAIfNecessary($valueItem);
            }
            $result .= \'</\'.trim(preg_replace(\'/[^\p{L}\p{Nd}]+/u\', \'_\', $tagName), \'_\').\'>\';
        }
    } else {
        $result .= $addCDATAIfNecessary($data);
    }

    return preg_replace(\'/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200E}\x{FEFF}]/u\', \'\', $result);
};

$result .= \'<item>\';
'.implode(
                        "\n",
                        array_map(
                            static function ($field) {
                                return '$result .= \'<'.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'>\';
$result .= $writeTag({{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }});
$result .= \'</'.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'>\';

';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'
$result .= \'</item>\';

if($params[\'lastCall\']) {
    $result .= \'</result>\';
}

$params[\'response\']->addContent($result);',
                'key' => $translator->trans('pim.mapping.template.rawdata_as_xml', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.xml_exports', [], 'admin')
            ];


            $templates[] = [
                'value' => '// The name of the Zip file
$zipName = \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\').\'.zip\';

if(!isset($params[\'transfer\']->zip)) {
    $params[\'transfer\']->zipName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
    $params[\'transfer\']->zip = new \ZipArchive();
    $params[\'transfer\']->zip->open($params[\'transfer\']->zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $params[\'response\']->headers->set(\'Content-Type\', \'application/zip\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'attachment;filename="\' . $zipName . \'"\');

    $params[\'transfer\']->result = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>";
}

$files = [];
$addCDATAIfNecessary = function($value) {
    $value = (string)$value;
    if(htmlspecialchars($value) !== $value) {
        return \'<![CDATA[\'.$value.\']]>\';
    }
    return $value;
};

$writeTag = function($data) use (&$writeTag, &$files, $params, $addCDATAIfNecessary) {
    $looksLikeFilePath = function($value) {
        $basename = basename($value);
        return strtolower(\OpenDxp\Model\Element\Service::getValidKey($basename, \'asset\')) === strtolower($basename);
    };

    $result = \'\';
    if(is_array($data)) {
        foreach($data as $tagName => $valueItem) {
            if(is_numeric($tagName)) {
                $tagName = \'item\';
            }
            $result .= \'<\'.trim(preg_replace(\'/[^\p{L}\p{Nd}]+/u\', \'_\', $tagName), \'_\').\'>\';
            if(!is_scalar($valueItem) && $valueItem !== null) {
                $valueItem = (array)$valueItem;
            }
            $result .= $writeTag($valueItem);
            $result .= \'</\'.trim(preg_replace(\'/[^\p{L}\p{Nd}]+/u\', \'_\', $tagName), \'_\').\'>\';
        }
    } else {
        if(is_string($data) && $looksLikeFilePath(urldecode($data))) {
            try {
                if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($data))) {
                    $files[] = [\'zipPath\' => $data, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($data))];
        
                    $data = ltrim(str_replace(\'/\', \'_\', urldecode($data)), \'_\');
                } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($data))) {
                    $files[] = [\'zipPath\' => $data, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($data))];
        
                    $data = ltrim(str_replace(\'/\', \'_\', urldecode($data)), \'_\');
                }
            } catch(\Exception $e) {
                $params[\'logger\']->debug(\'Could not check if "\'.urldecode($data).\'" is a file to be exported: \'. $e->getMessage());
            }
        }
    
        $result .= $addCDATAIfNecessary($data);
    }

    return preg_replace(\'/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200E}\x{FEFF}]/u\', \'\', $result);
};

$params[\'transfer\']->result .= \'<item>\';
'.implode(
                        "\n",
                        array_map(
                            static function ($field) {
                                return '$params[\'transfer\']->result .= \'<'.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'>\';
$params[\'transfer\']->result .= $writeTag({{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }});
$params[\'transfer\']->result .= \'</'.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'>\';

';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'
$params[\'transfer\']->result .= \'</item>\';

foreach ($files as $file) {
    $params[\'logger\']->debug(\'Adding file "\'.$file[\'path\'].\'" to zip\');
    $params[\'transfer\']->zip->addFile($file[\'path\'], \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($file[\'zipPath\'])), \'_\'));
    
    // prevent error with max number of open files -> see https://www.php.net/manual/de/ziparchive.addfile.php#100755 and https://stackoverflow.com/a/22638907
    if($params[\'transfer\']->zip->count() % 1000 == 999) {
         $params[\'transfer\']->zip->close();
         $params[\'transfer\']->zip->open($params[\'transfer\']->zipName);
    }
}

if($params[\'lastCall\']) {
    $params[\'transfer\']->result .= \'</result>\';
    $params[\'transfer\']->zip->addFromString(\'export.xml\', $params[\'transfer\']->result);
}

if($params[\'lastCall\']) {
    $params[\'transfer\']->zip->close();
    $params[\'response\']->addContent(file_get_contents($params[\'transfer\']->zipName));
}',
                'key' => $translator->trans('pim.mapping.template.rawdata_as_zipped_xml', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.xml_exports', [], 'admin')
            ];

            $templates[] = [
                'value' => 'if(!$params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'application/json\');
    $params[\'response\']->addContent(\'{"items":[\');
}

if($params[\'rawItemData\']) {
    $item = [];
'.implode(
                        "\n",
                        array_map(
                            static function ($field) {
                                return '    $item[\''.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'\'] = {{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }};';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'

    try {
        $params[\'response\']->addContent(json_encode($item, \JSON_UNESCAPED_UNICODE));
        if(!$params[\'lastCall\']) {
            $params[\'response\']->addContent(\',\');
        }
    } catch(\Throwable $e) {
        $params[\'logger\']->error(\'Could not encode item to JSON: \'. $e->getMessage());
    }
}

if($params[\'lastCall\']) {
    $params[\'response\']->addContent(\']}\');
}',
                'key' => $translator->trans('pim.mapping.template.rawdata_as_json', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.json_exports', [], 'admin')
            ];

            $templates[] = [
                'value' => '// The name of the Zip file
$zipName = \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\').\'.zip\';

if(!isset($params[\'transfer\']->zip)) {
    $params[\'transfer\']->zipName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
    $params[\'transfer\']->zip = new \ZipArchive();
    $params[\'transfer\']->zip->open($params[\'transfer\']->zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $params[\'response\']->headers->set(\'Content-Type\', \'application/zip\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'attachment;filename="\' . $zipName . \'"\');

    $params[\'transfer\']->resultData = [];
}

$files = [];
$detectFiles = function($value) use (&$files, $params) {
    $looksLikeFilePath = function($value) {
        $basename = basename($value);
        return strtolower(\OpenDxp\Model\Element\Service::getValidKey($basename, \'asset\')) === strtolower($basename);
    };
    
    if(is_string($value) && $looksLikeFilePath(urldecode($value))) {
        try {
            if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            }
        } catch(\Exception $e) {
            $params[\'logger\']->debug(\'Could not check if "\'.urldecode($value).\'" is a file to be exported: \'. $e->getMessage());
        }
    }
    
    if(!is_scalar($value) && $value !== null) {
        if(is_array($value)) {
            foreach($value as &$item) {
                if(is_string($item) && $looksLikeFilePath(urldecode($item))) {
                    try {
                        if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        }
                    } catch(\Exception $e) {
                        $params[\'logger\']->debug(\'Could not check if "\'.urldecode($item).\'" is a file to be exported: \'. $e->getMessage());
                    }
                }
            }
            unset($item);
        }
    }
    return $value;
};

if($params[\'rawItemData\']) {
    $item = [];
'.implode(
                        "\n",
                        array_map(
                            static function ($field) {
                                return '    $item[\''.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $field), '_').'\'] = $detectFiles({{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }});';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'
    
    $params[\'transfer\']->resultData[] = $item;
}

foreach ($files as $file) {
    $params[\'logger\']->debug(\'Adding file "\'.$file[\'path\'].\'" to zip\');
    $params[\'transfer\']->zip->addFile($file[\'path\'], \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($file[\'zipPath\'])), \'_\'));
    
    // prevent error with max number of open files -> see https://www.php.net/manual/de/ziparchive.addfile.php#100755 and https://stackoverflow.com/a/22638907
    if($params[\'transfer\']->zip->count() % 1000 == 999) {
         $params[\'transfer\']->zip->close();
         $params[\'transfer\']->zip->open($params[\'transfer\']->zipName);
    }
}

if($params[\'lastCall\']) {
    $result = json_encode($params[\'transfer\']->resultData, \JSON_UNESCAPED_UNICODE);
    $params[\'transfer\']->zip->addFromString(\'export.json\', $result);
    
    $params[\'transfer\']->zip->close();
    $params[\'response\']->addContent(file_get_contents($params[\'transfer\']->zipName));
}',
                'key' => $translator->trans('pim.mapping.template.rawdata_as_zipped_json', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.json_exports', [], 'admin')
            ];



            $templates[] = [
                'value' => '$separator = {{ CSV separator }} ?: \';\';
                    
if({{ CSV ENCODING }}) {
    $separator = iconv(\'UTF-8\', {{ CSV ENCODING }}, $separator);
}
                    
if(!$params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/csv\');
    
    $result = \'\';
    if(!{{ CSV ENCODING }} || strtolower({{ CSV ENCODING }}) === \'utf-8\') {
        $result .= "\xEF\xBB\xBF";
    }
    
    $result .= \'"'.implode('"\'.$separator.\'"', array_map(
                            static function ($field) {
                                return trim(preg_replace('/[^\p{L}\p{Nd}\s]+/u', '_', $field), '_');
                            },
                            array_column($existingFields, 'name')
                        )).'"\'."\n";
} else {
    $result = \'\';
}

$decodeValue = function($value) use ($params) {
    if(!is_scalar($value) && $value !== null) {
        if(is_array($value) && is_scalar(reset($value))) {
            $value = implode(\', \', $value);
        } else {
            $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
    }
    
    if({{ CSV ENCODING }}) {
        $value = iconv(\'UTF-8\', {{ CSV ENCODING }}, $value);
    }
    
    return \'"\'.str_replace(\'"\', \'""\', $value).\'"\';
};

if($params[\'rawItemData\']) {
    $line = [];
'.implode("\n",
                        array_map(
                            static function ($field) {
                                return '    $line[] = $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }});';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'

    $result .= implode($separator, $line)."\n";
}

$params[\'response\']->addContent($result);',
                    'key' => $translator->trans('pim.mapping.template.rawdata_as_csv', [], 'admin'),
                    'group' => $translator->trans('pim.mapping.template.group.csv_exports', [], 'admin')
                ];

            $templates[] = [
                'value' => '// The name of the Zip file
$zipName = \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\').\'.zip\';

if(!isset($params[\'transfer\']->zip)) {
    $params[\'transfer\']->zipName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
    
    $params[\'transfer\']->zip = new \ZipArchive();
    $params[\'transfer\']->zip->open($params[\'transfer\']->zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $params[\'response\']->headers->set(\'Content-Type\', \'application/zip\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'attachment;filename="\' . $zipName . \'"\');

    $params[\'transfer\']->csv = "\xEF\xBB\xBF\"'.implode('\";\"', array_column($existingFields, 'name')).'\"\n";
}

$files = [];
$decodeValue = function($value) use (&$files, $params) {
    $looksLikeFilePath = function($value) {
        $basename = basename($value);
        return strtolower(\OpenDxp\Model\Element\Service::getValidKey($basename, \'asset\')) === strtolower($basename);
    };
    
    if(is_string($value) && $looksLikeFilePath(urldecode($value))) {
        try {
            if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            }
        } catch(\Exception $e) {
            $params[\'logger\']->debug(\'Could not check if "\'.urldecode($value).\'" is a file to be exported: \'. $e->getMessage());
        }
    }
    
    if(!is_scalar($value) && $value !== null) {
        if(is_array($value)) {
            foreach($value as &$item) {
                if(is_string($item) && $looksLikeFilePath(urldecode($item))) {
                    try {
                        if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        }
                    } catch(\Exception $e) {
                        $params[\'logger\']->debug(\'Could not check if "\'.urldecode($item).\'" is a file to be exported: \'. $e->getMessage());
                    }
                }
            }
            unset($item);
        }
        
        if(is_array($value) && is_scalar(reset($value))) {
            $value = implode(\', \', $value);
        } else {
            $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
    }
    return \'"\'.str_replace(\'"\', \'""\', $value).\'"\';
};

$line = [];
'.implode(
                        "\n",
                        array_map(
                            static function ($field) {
                                return '$line[] = $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $field), '_').' }});';
                            },
                            array_column($existingFields, 'name')
                        )
                    ).'
$params[\'transfer\']->csv .= implode(\';\', $line)."\n";

foreach ($files as $file) {
    $params[\'logger\']->debug(\'Adding file "\'.$file[\'path\'].\'" to zip\');
    $params[\'transfer\']->zip->addFile($file[\'path\'], \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($file[\'zipPath\'])), \'_\'));
    
    // prevent error with max number of open files -> see https://www.php.net/manual/de/ziparchive.addfile.php#100755 and https://stackoverflow.com/a/22638907
    if($params[\'transfer\']->zip->count() % 1000 == 999) {
         $params[\'transfer\']->zip->close();
         $params[\'transfer\']->zip->open($params[\'transfer\']->zipName);
    }
}

if($params[\'lastCall\']) {
    $params[\'transfer\']->zip->addFromString(\'export.csv\', $params[\'transfer\']->csv);
    $params[\'transfer\']->zip->close();
    $params[\'reponse\']->addContent(file_get_contents($params[\'transfer\']->zipName));
}',
                'key' => $translator->trans('pim.mapping.template.rawdata_as_zipped_csv', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.csv_exports', [], 'admin')
            ];



            $code = '
if(!isset($params[\'transfer\']->spreadsheet)) {
    \PhpOffice\PhpSpreadsheet\Settings::setCache(new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\FilesystemAdapter("phpspreadsheet")));

    $params[\'response\']->headers->set(\'Content-Type\', \'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\');
    
    $params[\'transfer\']->spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $defaultStyle = $params[\'transfer\']->spreadsheet->getDefaultStyle();
    $defaultStyle->getAlignment()
        ->setWrapText(true)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $defaultStyle->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        
    $worksheet = $params[\'transfer\']->spreadsheet->getActiveSheet();
    $columnIndex = 1;';
            foreach (array_column($existingFields, 'name') as $existingField) {
                $code .= '
    $worksheet->setCellValue([$columnIndex++, 1], \''.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $existingField), '_').'\');';
                }

            $code .= '
    $worksheet->freezePane(\'B1\');
                
    foreach ($worksheet->getColumnIterator() as $column) {
       $worksheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
       $worksheet->getStyle($column->getColumnIndex().\'1\')->getFont()->setBold(true);
    }
} else {
    $worksheet = $params[\'transfer\']->spreadsheet->getActiveSheet();
}

$decodeValue = function($value) use ($params) {
    if(!is_scalar($value) && $value !== null) {
        if(is_array($value) && is_scalar(reset($value))) {
            $value = implode(\', \', $value);
        } else {
            $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
    }
    
    return $value;
};

if($params[\'rawItemData\']) {
    $rowIndex = $worksheet->getHighestDataRow();
    $columnIndex = 1;';
            foreach (array_column($existingFields, 'name') as $existingField) {
                $code .= '
    $worksheet->setCellValue([$columnIndex++, $rowIndex+1], $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $existingField), '_').' }}));';
            }
            $code .= '
}

if($params[\'lastCall\']) {
    $worksheet->setAutoFilter($worksheet->calculateWorksheetDimension());
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($params[\'transfer\']->spreadsheet, "Xlsx");
    $stream = fopen(\'php://memory\', \'rb+\');
    $writer->save($stream);
    
    rewind($stream);
    $params[\'response\']->addContent(stream_get_contents($stream));
}';
            $templates[] = [
                'value' => $code,
                'key' => $translator->trans('pim.mapping.template.rawdata_as_excel', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.excel_exports', [], 'admin')
            ];


            $code = '$zipName = \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\').\'.zip\';

if(!isset($params[\'transfer\']->zip)) {
    $params[\'transfer\']->zipName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
    
    $params[\'transfer\']->zip = new \ZipArchive();
    $params[\'transfer\']->zip->open($params[\'transfer\']->zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $params[\'response\']->headers->set(\'Content-Type\', \'application/zip\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'attachment;filename="\' . $zipName . \'"\');
    
    \PhpOffice\PhpSpreadsheet\Settings::setCache(new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\FilesystemAdapter("phpspreadsheet")));
    
    $params[\'transfer\']->spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $defaultStyle = $params[\'transfer\']->spreadsheet->getDefaultStyle();
    $defaultStyle->getAlignment()
        ->setWrapText(true)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP); 
    $defaultStyle->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
    
    $worksheet = $params[\'transfer\']->spreadsheet->getActiveSheet();
    $columnIndex = 1;';
            foreach (array_column($existingFields, 'name') as $existingField) {
                $code .= '
    $worksheet->setCellValue([$columnIndex++, 1], \''.trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $existingField), '_').'\');';
                }

            $code .= '
    $worksheet->freezePane(\'B1\');
                
    foreach ($worksheet->getColumnIterator() as $column) {
       $worksheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
       $worksheet->getStyle($column->getColumnIndex().\'1\')->getFont()->setBold(true);
    }
} else {
    $worksheet = $params[\'transfer\']->spreadsheet->getActiveSheet();
}
     
$files = [];
$decodeValue = function($value) use (&$files, $params) {
    $looksLikeFilePath = function($value) {
        $basename = basename($value);
        return strtolower(\OpenDxp\Model\Element\Service::getValidKey($basename, \'asset\')) === strtolower($basename);
    };
    
    if(is_string($value) && $looksLikeFilePath(urldecode($value))) {
        try {
            if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($value))) {
                $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($value))];
    
                $value = ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
            }
        } catch(\Exception $e) {
            $params[\'logger\']->debug(\'Could not check if "\'.urldecode($value).\'" is a file to be exported: \'. $e->getMessage());
        }
    }
    
    if(!is_scalar($value) && $value !== null) {
        if(is_array($value)) {
            foreach($value as &$item) {
                if(is_string($item) && $looksLikeFilePath(urldecode($item))) {
                    try {
                        if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($item))) {
                            $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($item))];
                
                            $item = ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                        }
                    } catch(\Exception $e) {
                        $params[\'logger\']->debug(\'Could not check if "\'.urldecode($item).\'" is a file to be exported: \'. $e->getMessage());
                    }
                }
            }
            unset($item);
        }
        
        if(is_array($value) && is_scalar(reset($value))) {
            $value = implode(\', \', $value);
        } else {
            $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
    }
    return $value;
};

if($params[\'rawItemData\']) {
    $rowIndex = $worksheet->getHighestDataRow();
    $columnIndex = 1;';
            foreach (array_column($existingFields, 'name') as $existingField) {
                $code .= '
    $worksheet->setCellValue([$columnIndex++, $rowIndex+1], $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $existingField), '_').' }}));';
            }
            $code .= '
}
            
foreach ($files as $file) {
    $params[\'logger\']->debug(\'Adding file "\'.$file[\'path\'].\'" to zip\');
    $params[\'transfer\']->zip->addFile($file[\'path\'], \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($file[\'zipPath\'])), \'_\'));
    
    // prevent error with max number of open files -> see https://www.php.net/manual/de/ziparchive.addfile.php#100755 and https://stackoverflow.com/a/22638907
    if($params[\'transfer\']->zip->count() % 1000 == 999) {
         $params[\'transfer\']->zip->close();
         $params[\'transfer\']->zip->open($params[\'transfer\']->zipName);
    }
}

if($params[\'lastCall\']) {
    $worksheet->setAutoFilter($worksheet->calculateWorksheetDimension());
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($params[\'transfer\']->spreadsheet, "Xlsx");
    $stream = fopen(\'php://memory\', \'rb+\');
    $writer->save($stream);
    
    rewind($stream);
    $params[\'transfer\']->zip->addFromString(\'Export.xlsx\', stream_get_contents($stream));

    $params[\'transfer\']->zip->close();
    $params[\'response\']->addContent(file_get_contents($params[\'transfer\']->zipName));
}';
            $templates[] = [
                'value' => $code,
                'key' => $translator->trans('pim.mapping.template.rawdata_as_zipped_excel', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.excel_exports', [], 'admin')
            ];



            if(empty($targetConfig['itemClass'])) {
                $excelTemplateListing = new Asset\Listing();
                $excelTemplateListing->addConditionParam('type=\'document\' AND mimetype IN (\'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\',\'application/vnd.ms-excel\') AND path NOT LIKE \'/_default_upload_bucket/%\' AND path NOT REGEXP \'/[-_ 0-9]+/\' AND modificationDate>?', [time()-86400*30]);
                $archiveFolders = [];
                foreach (Dataport::getInstance()->find() as $otherDataport) {
                    if (!empty($otherDataport['sourceconfig']['archiveFolder'])) {
                        $archiveFolders[] = rtrim($otherDataport['sourceconfig']['archiveFolder'], '/').'/';
                    }
                }

                $assetArchiveFolders = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT path FROM assets WHERE path IN (?)', [$archiveFolders]);
                foreach ($assetArchiveFolders as $assetArchiveFolder) {
                    $excelTemplateListing->addConditionParam('path NOT LIKE '.Db::get()->quote($assetArchiveFolder.'%'));
                }

                foreach ($excelTemplateListing->load() as $excelTemplate) {
                    if($excelTemplate->getFileSize() > 5 * 1024 * 1024) {
                        // skip files > 5MB
                        continue;
                    }

                    $excelTemplateFilePath = Helper::getLocalAssetFile($excelTemplate);
                    $columns = [];
                    try {
                        $spreadsheet = Excel::open($excelTemplateFilePath);

                        foreach ($spreadsheet->getSheetNames() as $worksheetName) {
                            if(!method_exists($spreadsheet, 'getSheet')) {
                                // avadim/fast-excel-reader v1 does not have getSheet() method, this "continue" simply disabled the Excel filling template for PHP < 7.4
                                continue;
                            }
                            $worksheet = $spreadsheet->getSheet($worksheetName);
                            if(method_exists($worksheet, 'isHidden') && $worksheet->isHidden()) {
                                continue;
                            }

                            foreach ($worksheet->nextRow(false, Excel::KEYS_ORIGINAL) as $rowIndex => $row) {
                                if ($rowIndex > 10) {
                                    unset($columns[$worksheetName]);
                                    continue 2;
                                }
                                foreach ($row as $columnKey => $cellValue) {
                                    $cellValue = trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $cellValue), '_');

                                    if ($cellValue) {
                                        $columns[$worksheetName][$columnKey][] = $cellValue;
                                    }
                                }
                            }

                            if (!empty($columns[$worksheetName])) {
                                foreach ($columns[$worksheetName] as &$columnNameParts) {
                                    $columnNameParts = implode(' - ', array_unique($columnNameParts));
                                }
                                unset($columnNameParts);
                            }
                        }
                    } catch (\Throwable $e) {
                        if($e->getMessage() === 'Not a zip archive') {
                            try {
                                $spreadsheet = IOFactory::load($excelTemplateFilePath);

                                foreach ($spreadsheet->getAllSheets() as $worksheet) {
                                    if($worksheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                                        continue;
                                    }

                                    $lastRowIndex = $worksheet->getHighestDataRow();
                                    if ($lastRowIndex >= 10) {
                                        continue;
                                    }
                                    $lastColumnIndex = $worksheet->getHighestDataColumn();
                                    foreach ($worksheet->getRowIterator(1, $lastRowIndex) as $row) {
                                        foreach ($row->getCellIterator('A', $lastColumnIndex) as $cell) {
                                            try {
                                                $cellValue = $cell->getFormattedValue();
                                            } catch (\Throwable $e) {
                                                $cellValue = (string)$cell->getValue();
                                            }

                                            $cellValue = trim(preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $cellValue), '_');

                                            if ($cellValue) {
                                                $columns[$worksheet->getTitle()][$cell->getColumn()][] = $cellValue;
                                            }
                                        }
                                    }

                                    if ($columns[$worksheet->getTitle()]) {
                                        foreach ($columns[$worksheet->getTitle()] as &$columnNameParts) {
                                            $columnNameParts = implode(' - ', array_unique($columnNameParts));
                                        }
                                        unset($columnNameParts);
                                    }
                                }
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                    unset($spreadsheet);

                    if (count($columns) === 0) {
                        continue;
                    }


                    $code = '
    if(!isset($params[\'transfer\']->spreadsheet)) {
        $params[\'transfer\']->spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(\''.$excelTemplate.'\'));
        $params[\'response\']->headers->set(\'Content-Type\', \'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\');
    }
    
    $decodeValue = function($value) use ($params) {
        if(!is_scalar($value) && $value !== null) {
            if(is_array($value) && is_scalar(reset($value))) {
                $value = implode(\', \', $value);
            } else {
                $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }
    
        return $value;
    };
    
    if($params[\'rawItemData\']) {';
                    foreach ($columns as $worksheetName => $worksheetColumns) {
                        $code .= '
        // Worksheet "'.$worksheetName.'"
        $worksheet = $params[\'transfer\']->spreadsheet->getSheetByName(\''.$worksheetName.'\');
        $rowIndex = $worksheet->getHighestDataRow();';

                        foreach ($worksheetColumns as $columnIndex => $worksheetColumnName) {
                            $code .= '
        $worksheet->setCellValue(\''.$columnIndex.'\' . ($rowIndex + 1), $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $worksheetColumnName), '_').' }}));';
                        }
                        $code .= '
        ';
                    }

                    $code .= '
    }
    
    if($params[\'lastCall\']) {
        $worksheet->setAutoFilter($worksheet->calculateWorksheetDimension());
    
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($params[\'transfer\']->spreadsheet, "Xlsx");
        $stream = fopen(\'php://memory\', \'rb+\');
        $writer->save($stream);
    
        rewind($stream);
        $params[\'response\']->addContent(stream_get_contents($stream));
    }';
                    $templates[] = [
                        'value' => $code,
                        'key' => sprintf($translator->trans('pim.mapping.template.fill_excel', [], 'admin'), $excelTemplate->getFilename()),
                        'group' => $translator->trans('pim.mapping.template.group.excel_exports', [], 'admin')
                    ];


                    // as Zip
                    $code = '
    if(!isset($params[\'transfer\']->spreadsheet)) {
        $params[\'transfer\']->spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(\''.$excelTemplate.'\'));
        
        $params[\'transfer\']->zipName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
        
        $params[\'transfer\']->zip = new \ZipArchive();
        $params[\'transfer\']->zip->open($params[\'transfer\']->zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    
        $params[\'response\']->headers->set(\'Content-Type\', \'application/zip\');
        $params[\'response\']->headers->set(\'Content-Disposition\', \'attachment;filename="'.$excelTemplate->getFilename().'.zip"\');
    }
    
    $decodeValue = function($value) use ($params) {
        if(!is_scalar($value) && $value !== null) {
            if(is_array($value) && is_scalar(reset($value))) {
                $value = implode(\', \', $value);
            } else {
                $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }
    
        return $value;
    };
    
    $files = [];
    $decodeValue = function($value) use (&$files, $params) {
        $looksLikeFilePath = function($value) {
            $basename = basename($value);
            return strtolower(\OpenDxp\Model\Element\Service::getValidKey($basename, \'asset\')) === strtolower($basename);
        };
        
        if(is_string($value) && $looksLikeFilePath(urldecode($value))) {
            try {
                if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($value))) {
                    $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($value))];
        
                    $value = \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
                } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($value))) {
                    $files[] = [\'zipPath\' => $value, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($value))];
        
                    $value = \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($value)), \'_\');
                }
            } catch(\Exception $e) {
                $params[\'logger\']->debug(\'Could not check if "\'.urldecode($value).\'" is a file to be exported: \'. $e->getMessage());
            }
        }
        
        if(!is_scalar($value) && $value !== null) {
            if(is_array($value)) {
                foreach($value as &$item) {
                    if(is_string($item) && $looksLikeFilePath(urldecode($item))) {
                        try {
                            if(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->fileExists(urldecode($item))) {
                                $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile(urldecode($item))];
                    
                                $item = \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                            } elseif(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getThumbnailStorage()->fileExists(urldecode($item))) {
                                $files[] = [\'zipPath\' => $item, \'path\' => \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalThumbnailFile(urldecode($item))];
                    
                                $item = \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($item)), \'_\');
                            }
                        } catch(\Exception $e) {
                            $params[\'logger\']->debug(\'Could not check if "\'.urldecode($item).\'" is a file to be exported: \'. $e->getMessage());
                        }
                    }
                }
                unset($item);
            }
            
            if(is_array($value) && is_scalar(reset($value))) {
                $value = implode(\', \', $value);
            } else {
                $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }
        return $value;
    };
    
    if($params[\'rawItemData\']) {';
                    foreach ($columns as $worksheetName => $worksheetColumns) {
                        $code .= '
        // Worksheet "'.$worksheetName.'"
        $worksheet = $params[\'transfer\']->spreadsheet->getSheetByName(\''.$worksheetName.'\');
        $rowIndex = $worksheet->getHighestDataRow();';

                        foreach ($worksheetColumns as $columnIndex => $worksheetColumnName) {
                            $code .= '
        $worksheet->setCellValue(\''.$columnIndex.'\' . ($rowIndex + 1), $decodeValue({{ '.trim(str_replace([':', '{', '}'], '_', $worksheetColumnName), '_').' }}));';
                        }
                        $code .= '
        ';
                    }

                    $code .= '
    }
    
    if($params[\'lastCall\']) {
        $worksheet->setAutoFilter($worksheet->calculateWorksheetDimension());
    
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($params[\'transfer\']->spreadsheet, "Xlsx");
        $stream = fopen(\'php://memory\', \'rb+\');
        $writer->save($stream);
    
        rewind($stream);
        
        $params[\'transfer\']->zip->addFromString(\''.$excelTemplate->getFilename().'\', stream_get_contents($stream));
    }
    
    foreach ($files as $file) {
        $params[\'logger\']->debug(\'Adding file "\'.$file[\'path\'].\'" to zip\');
        $params[\'transfer\']->zip->addFile($file[\'path\'], \'assets/\'.ltrim(str_replace(\'/\', \'_\', urldecode($file[\'zipPath\'])), \'_\'));
        
        // prevent error with max number of open files -> see https://www.php.net/manual/de/ziparchive.addfile.php#100755 and https://stackoverflow.com/a/22638907
        if($params[\'transfer\']->zip->count() % 1000 == 999) {
             $params[\'transfer\']->zip->close();
             $params[\'transfer\']->zip->open($params[\'transfer\']->zipName);
        }
    }
    
    if($params[\'lastCall\']) {
        $params[\'transfer\']->zip->close();
        return file_get_contents($params[\'transfer\']->zipName);
    }';
                    $templates[] = [
                        'value' => $code,
                        'key' => sprintf($translator->trans('pim.mapping.template.fill_excel_zipped', [], 'admin'), $excelTemplate->getFilename()),
                        'group' => $translator->trans('pim.mapping.template.group.excel_exports', [], 'admin')
                    ];
                }
            }

            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportIds = {{ FOLLOW-UP DATAPORT IDS / NAMES }};

// provide parameters to use in dynamic import resource or in callback functions of dependent dataport
// parameters should get returned as key-value array, e.g. `return [\'parameter\' => \'value\', \'otherParameter\' => 123]`
// leave empty to run dependent dataport with default import source
$parameters = {{ FOLLOW-UP DATAPORT PARAMETERS }};

$errorsCount = count(array_intersect(array_keys($params[\'logs\']), [\'warning\',\'error\',\'critical\',\'alert\',\'emergency\']));
if($params[\'lastCall\'] && $dataportIds && $errorsCount === 0) {
    if(!is_array($dataportIds)) {
        $dataportIds = preg_split(\'/[,|;\n]\s*/\', $dataportIds);
    }
    
    foreach($dataportIds as $dataportId) {
        $command = \'data-bridge:complete "\'.$dataportId.\'" --locale=\'.$params[\'request\']->getLocale().\' --user=\'.$params[\'context\'][\'user\'][\'id\'].($parameters?\' --force --parameters=\'.escapeshellarg(json_encode($parameters)):\'\');
        
        if({{ FOLLOW-UP DATAPORT EXECUTE ASYNCHRONICALLY }}) {
            $params[\'logger\']->info(\'Queueing command "\'.$command.\'"\');
            $queue = new \Sylphen\DataBridgeBundle\model\Queue();
            $queue->create([\'command\' => $command, \'triggered_by\' => \'Result callback function of dataport \'.$params[\'context\'][\'dataportId\']]);
        } else {
            $output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec($command);
            
            if(preg_match(\'/X-Data-Bridge-Run:\s*(.+)(\n|$)/\', $output, $dependentRunId)) {
                $responseDocumentPath = \Sylphen\DataBridgeBundle\Tools\Installer::getResultDocumentPath().\'/result_\'.$dataportId.\'_\'.trim($dependentRunId[1]);
                
                if(file_exists($responseDocumentPath)) {
                    $dependentDataportResponseDocument = \unserialize(\file_get_contents($responseDocumentPath));
                    if($dependentDataportResponseDocument) {
                        return $dependentDataportResponseDocument;
                    }
                }
            }
        }
    }
} elseif($errorsCount > 0) {
    $params[\'logger\']->info(\'Skipping dependent dataport execution due to errors\');
}',
                'key' => $translator->trans('pim.mapping.template.dependent_import', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.start_follow-up', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportId = {{ DEPENDENT DATAPORT ID }};

// provide parameters to use in dynamic import resource or in callback functions of dependent dataport
// leave empty to run dependent dataport with default import source
$parameters = {{ DEPENDENT DATAPORT PARAMETERS }};

if(($parameters || $params[\'lastCall\']) && $dataportId) {
    $params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');
    
    $routeParams = [\'dataportId\' => $dataportId, \'apikey\' => \Sylphen\DataBridgeBundle\Controller\RestController::getApiKeyForCurrentUser()];
    foreach($parameters as $parameterName => $parameterValue) {
        $routeParams[$parameterName] = $parameterValue;
    }
    $params[\'response\']->addContent(
        \'Dataport URL: \'. // you can change this text to whatever you want
        \OpenDxp::getContainer()->get(\'router\')->generate(
            \''.(empty($targetConfig['itemClass']) ? 'dataport_export' : 'dataport_import').'\', 
            $routeParams, 
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        )
    );
}',
                'key' => $translator->trans('pim.mapping.template.dependent_import_url', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.start_follow-up', [], 'admin'),
            ];


            $templates[] = [
                'value' => '// Enter id of dataport to be executed
$dataportId = {{ FOLLOW-UP DATAPORT IDS / NAMES }};

// provide parameters to use in dynamic import resource, raw data fields or in callback functions of dependent dataport
// parameters should get returned as key-value array, e.g. `return [\'parameter\' => \'value\', \'otherParameter\' => 123]`
// leave empty to run dependent dataport with default import source
$parameters = {{ FOLLOW-UP DATAPORT PARAMETERS }};

$params[\'response\']->headers->set(\'Content-Type\', \'application/json\');

return json_encode([
    \'dependentDataportId\' => $dataportId,
    \'dependentDataportParameters\' => $parameters
], \JSON_UNESCAPED_UNICODE);',
                'key' => $translator->trans('pim.mapping.template.parametrize_dependent_import', [], 'admin'),
                'group' => $translator->trans('pim.mapping.template.group.start_follow-up', [], 'admin')
            ];


            $templates[] = [
                'value' => '$item = array_filter(
    $params[\'rawItemData\']->asArray(),
    function($key) {
        return strpos($key, \'field_\') === false;
    }, ARRAY_FILTER_USE_KEY
);

if($item) {
    if(!$params[\'response\']->hasContent()) {
        $html = \'<table>\';
        
        // header row
        $html .= \'<tr>\';
        foreach(array_keys($item) as $columnHeading){
                $html .= \'<th>\' . htmlspecialchars($columnHeading) . \'</th>\';
            }
        $html .= \'</tr>\';
        
        $params[\'response\']->addContent($html);
    }
    
    // data rows
    $html = \'<tr>\';
    foreach($item as $rawItem){
        $html .= \'<td>\' . $rawItem[\'value\'] . \'</td>\';
    }
    $html .= \'</tr>\';
    
    if($params[\'lastCall\']) {
        $html .= \'</table>\';
    }

    $params[\'response\']->addContent($html);
}',
                'key' => $translator->trans('pim.mapping.template.raw_data_table', [], 'admin')
            ];

            $templates[] = [
                'value' => '$result = $params[\'response\']->getContent();

foreach($params[\'logs\'] as $logType => $logs) {
    $result .= \'  [\'.$logType.\']\'."\n\n    ".implode("\n\n    ", $logs);
}
$result .= "\n";

return $result;',
                'key' => $translator->trans('pim.mapping.template.error_output', [], 'admin')
            ];

            // Visualization template
            $code = '$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

$html = \'<html><body style="background:#fff;">\';
            ';
            if (!empty($sourceConfig['sourceClass'])) {
                $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass']);

                if ($itemMold instanceof Concrete) {
                    $classDefinition = $itemMold->getClass();
                    $color = self::stringToColorCode($classDefinition->getName());
                    $code .= '
$nodes = [[
    [
        \'id\'    => {{ Current Node Object Id }} ?: \'current\',
        \'label\' => strip_tags({{ Current Node Label }} ?: \'Current object\'),
        \'image\' => {{ Current Node Image }} ?: \''.($classDefinition->getIcon() ?: '/bundles/opendxpadmin/img/flat-color-icons/class.svg').'\',
        \'color\' => \''.$color.'\',
        \'shape\' => \'circularImage\',
    ]
]];

// you may remove any of the following lines for relations which you do not want to show
';

                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof AbstractRelations) {
                            $code .= '
$nodes[] = {{ '.ucfirst($fieldDefinition->getName()).' }} ?? [];';
                        }
                    }

                    $code .= '
                
$nodes = array_merge(...$nodes);

$edges = [];
foreach($nodes as $index => $node) {
    if (isset($node[\'from\'])) {
        $edges[] = [
            \'from\' => $node[\'from\'], 
            \'to\' => $node[\'id\'],
        ];
    }
}

$nodes = array_values(array_intersect_key($nodes, array_unique(array_column($nodes, \'id\'))));

$html .= \'
<div class="wrapper">
    <img class="search-icon" src="/bundles/opendxpadmin/img/flat-color-icons/search.svg" />
    <input class="search" id="nodeFilter" type="search" >
    <img class="clear-icon" src="/bundles/opendxpadmin/img/flat-color-icons/delete.svg" />
</div>
<div id="dependency-graph"></div>

<script type="text/javascript" src="/bundles/sylphendatabridge/vendor/vis-network/vis-network.min.js"></script>

<link rel="stylesheet" href="/bundles/sylphendatabridge/vendor/vis-network/vis-network.min.css" />
<link rel="stylesheet" href="/bundles/sylphendatabridge/css/vis-network.css" />
<script type="text/javascript">
    var nodes = new vis.DataSet(\'.json_encode($nodes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).\');
    
    var edges = new vis.DataSet(\'.json_encode($edges).\');
    
    var container = document.getElementById("dependency-graph");
    var options = {
        nodes: {
            margin: 12,
            shadow: true
        },
        edges: {
            shadow: true
        },
        interaction: {
            hover: true
        }
    };\';
  
    if ({{ Show as Hierarchical Tree }}) {
        $html .= \'
    options["layout"] = {
        hierarchical: {
            direction: "UD",
        }
    };\';
    }
    
    $html .= \'
    var network = new vis.Network(container, {
        nodes: nodes,
        edges: edges
    }, options);
    
    network.on("click", function(properties) {
        var ids = properties.nodes;
        var clickedNodes = nodes.get(ids);
        if (clickedNodes.length > 0 && clickedNodes[0]["id"] != 1 && parseInt(clickedNodes[0]["id"]) == clickedNodes[0]["id"]) {
            this.parent.opendxp.helpers.openObject(clickedNodes[0]["id"]);
        }
    }.bind(this));
    
    document.getElementById("nodeFilter").addEventListener("change", (e) => {
        if(e.target.value === "") {
            network.unselectAll();
            return true;
        }
        var matchingNodeIds = [];
        nodes.forEach(function(node) {
            if(node.label.toLowerCase().indexOf(e.target.value.toLowerCase()) > -1) {
                matchingNodeIds.push(node.id);
            }
        });
        
        if(matchingNodeIds.length > 0) {
            network.selectNodes(matchingNodeIds);
            network.focus(matchingNodeIds[0]);
        } else {
            network.unselectAll();
        }
    });

    const clearIcon = document.querySelector(".clear-icon");
    const searchBar = document.querySelector(".search");
    
    document.querySelector(".search").addEventListener("keyup", function() {
      if(searchBar.value && clearIcon.style.visibility !== "visible"){
        clearIcon.style.visibility = "visible";
      } else if(!searchBar.value) {
        clearIcon.style.visibility = "hidden";
      }
    });
    
    clearIcon.addEventListener("click", function() {
      searchBar.value = "";
      clearIcon.style.visibility = "hidden";
    });
</script>\';';
                } else {
                    $code .= 'Please select dataport\'s source class and afterwards map the relation field nodes';
                }

                $code .= '
$html .= \'</body></html>\';

return $html;';

                $templates[] = [
                    'value' => $code,
                    'key' => $translator->trans('pim.mapping.template.visualization', [], 'admin')
                ];
            }

            if ($dataport['sourcetype'] === 'object-wizard') {
                $application = new OpenDxp\Console\Application(OpenDxp::getKernel());
                $commands = [];
                foreach($application->all() as $command) {
                    $commands[$command->getName()] = $command;
                }

                $commands = array_filter($commands, static function($command) {
                    if (strpos($command->getName(), ':') === false) {
                        return false;
                    }

                    return
                        // Blacklist
                        !in_array(substr($command->getName(), 0, strpos($command->getName(), ':')), ['doctrine', 'secrets', 'security', 'translation', 'mailer', 'fos', 'lint', 'data-bridge', 'cache', 'assets','config', 'internal','messenger','pimcore','opendxp','debug','router']) ||
                        // Whitelist
                        in_array($command->getName(), ['cache:clear', 'data-bridge:process-queue', 'messenger:consume','opendxp:cache:clear', 'opendxp:email:cleanup','opendxp:recyclebin:cleanup']);
                });

                usort($commands, static function($command1, $command2) {
                    return $command1->getName() <=> $command2->getName();
                });
                foreach ($commands as $command) {
                    $templates[] = [
                        'value' => '$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');
$output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec(\'bin/console '.$command->getName().'\');
$params[\'response\']->addContent(nl2br($output));',
                        'key' => sprintf($translator->trans('pim.mapping.template.action.commands.run', [], 'admin'), $command->getName()).': '.$command->getDescription(),
                        'group' => $translator->trans('pim.mapping.template.action.commands', [], 'admin'),
                    ];
                }
            }
        } elseif($def instanceof Data\CalculatedValue && strpos((Fieldmapping::getInstance()->findOne(['dataportId = ?' => $dataportId, 'fieldName = ?' => '__result_callback']) ?: ['calculation' => ''])['calculation'], 'vis-network') !== false) {
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);

            $classDefinition = $itemMold->getClass();
            foreach($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                if($fieldDefinition instanceof AbstractRelations && strtolower($fieldDefinition->getName()) === strtolower(str_replace('__virtual_', '', $def->getName()))) {
                    $color = self::stringToColorCode($def->getName());
                    $contrastColor = self::getContrastColor($color);
                    $templates[] = [
                        'value' => '
$nodes = [];

$getNodes = function($elements, $sourceElementId) use (&$getNodes, $params) {
    $nodes = [];
    
    if(is_array($elements) && isset($elements[\'id\'])) {
        // many-to-one relation
        $elements = [$elements];
    }
    
    if(is_array($elements) && isset($elements[0][\'id\'])) {
        foreach($elements as $element) {
            $nodes[] = [
                \'id\' => $element[\'id\'],
                \'label\' => strip_tags($element[{{ '.$fieldDefinition->getTitle().' Label Field }}] ?? $element[\'key\'] ?? $element[\'id\']),
                \'image\' => $element[{{ '.$fieldDefinition->getTitle().' Image Field }}][\'url\'] ?? $element[{{ '.$fieldDefinition->getTitle().' Image Field }}] ?? \''.($classDefinition->getIcon() ?: '/bundles/opendxpadmin/img/flat-color-icons/class.svg').'\',
                \'color\' => \''.$color.'\',
                \'shape\' => \'circularImage\',
                \'from\' => $sourceElementId,
            ];
            if(isset($element[\''.$fieldDefinition->getName().'\'])) {
                $nodes = array_merge($nodes, $getNodes($element[\''.$fieldDefinition->getName().'\'], $element[\'id\']));
            }
        }
    }

    return $nodes;
};

$groupName = \''.$fieldDefinition->getTitle().'\';
$showGroupNodes = {{ Show Group Nodes }} ?? true;

if($showGroupNodes) {
    $nodes[] = [
        \'id\' => $groupName,
        \'label\' => $groupName,
        \'color\' => \''.$color.'\',
        \'font\' => [\'color\' => \''.$contrastColor.'\'],
        \'shape\' => \'box\',
        \'from\' => {{ Current Node Object Id }} ?: \'current\'
    ];
}

return array_merge($nodes, $getNodes($params[\'value\'], $showGroupNodes ? $groupName : ({{ Current Node Object Id }} ?: \'current\')));
                ',
                    'key' => $translator->trans('pim.mapping.calculatedValue.nodes_visualization', [], 'admin')
                ];
                    break;
                }
            }
        } elseif ($def instanceof Data\CalculatedValue && !in_array($def->getName(), ['__result_action', '__result_callback', '__init_action'], true) && empty($targetConfig['itemClass'])) {
            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportId = {{ '.str_replace('__virtual_', '', $def->getName()).' DEPENDENT DATAPORT ID }};

// provide parameters to use in dynamic import resource or in callback functions of dependent dataport
// parameters should get returned as key-value array, e.g. `return [\'parameter\' => \'value\', \'otherParameter\' => 123]`
$parameters = {{ '.str_replace('__virtual_', '', $def->getName()).' DEPENDENT DATAPORT PARAMETERS }} ?? [];

if($dataportId && array_filter($parameters)) {
    $command = \'data-bridge:complete "\'.$dataportId.\'" --locale=\'.$params[\'request\']->getLocale().\' --user=\'.$params[\'context\'][\'user\'][\'id\'].\' --rm\'.($parameters?\' --force --parameters=\'.escapeshellarg(json_encode($parameters)):\'\');
    
    $output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec($command);
    
    if(preg_match(\'/X-Data-Bridge-Run:\s*(.+)(\n|$)/\', $output, $dependentRunId)) {
        $responseDocumentPath = \Sylphen\DataBridgeBundle\Tools\Installer::getResultDocumentPath().\'/result_\'.$dataportId.\'_\'.trim($dependentRunId[1]);
        
        if(file_exists($responseDocumentPath)) {
            $dependentDataportResponseDocument = \unserialize(\file_get_contents($responseDocumentPath));
            if($dependentDataportResponseDocument) {
                $responseContent = $dependentDataportResponseDocument->getContent();
                $decodedValue = json_decode($responseContent, true);
                if (json_last_error() === \JSON_ERROR_NONE) {
                    $responseContent = $decodedValue;
                }
                
                if(strpos($responseContent, \'<?xml\') === 0) {
                    $xmlDocument = new \DOMDocument();
                    if(!@$xmlDocument->loadXML($responseContent)) {
                        $params[\'logger\']->error(\'Could not load XML result fragment from dataport #\'.$dataportId);
                        return;
                    }
                    
                    $responseContent = \Sylphen\DataBridgeBundle\lib\Pim\Parser\XmlParser::xml_to_array($xmlDocument->documentElement);
                }
                
                return $responseContent;
            }
        }
    }
}',
                'key' => $translator->trans('pim.mapping.template.fetch_data_from_dependent_import', [], 'admin')
            ];

            $templates[] = [
                'value' => 'return \Sylphen\DataBridgeBundle\lib\Pim\Helper::getHostUrl() . $params[\'value\'];',
                'key' => $translator->trans('pim.mapping.calculatedValue.asset_absolute_url', [], 'admin')
            ];

            $templates[] = [
                'value' => 'return \OpenDxp\Helper\Mail::setAbsolutePaths($params[\'value\'], null, \Sylphen\DataBridgeBundle\lib\Pim\Helper::getHostUrl());',
                'key' => $translator->trans('pim.mapping.calculatedValue.html_convert_to_absolute_urls', [], 'admin')
            ];

            $templates[] = [
                'value' => 'return \Sylphen\DataBridgeBundle\lib\Pim\Import\htmlToText($params[\'value\']);',
                'key' => $translator->trans('pim.mapping.calculatedValue.html_to_text', [], 'admin')
            ];

            $templates[] = [
                'value' => '// see full documentations at https://github.com/thephpleague/html-to-markdown
if (!is_string($params[\'value\'])) return \'\';
$converterOptions = [];
$converter = new \League\HTMLToMarkdown\HtmlConverter($converterOptions);
return $converter->convert($params[\'value\'] ?? \'\');',
                'key' => $translator->trans('pim.mapping.calculatedValue.html_to_markdown', [], 'admin')
            ];

            $templates[] = [
                'value' => 'if($params[\'value\'] && is_numeric($params[\'value\'])) {
    $locale = localeconv();
    $countDecimals = strlen($params[\'value\']) - strrpos($params[\'value\'], \'.\') - 1;
    return number_format($params[\'value\'], $countDecimals, $locale[\'decimal_point\'], $locale[\'thousands_sep\']);
}
return $params[\'value\'];',
                'key' => $translator->trans('pim.mapping.calculatedValue.number_format', [], 'admin')
            ];
        } elseif ($def instanceof Data\CalculatedValue && !in_array($def->getName(), ['__result_action', '__result_callback', '__init_action'], true) && !empty($targetConfig['itemClass'])) {
            $checks = '';
            $existingFields = RawItemField::getInstance()->find(array('dataportId = ?' => $dataportId), 'priority');
            $existingMappings = Fieldmapping::getInstance()->find(
                [
                    'dataportId = ?' => $dataportId,
                    'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action'],
                    'fieldname NOT LIKE ?' => '__virtual_%'
                ]
            );

            foreach($existingFields as $rawItemField) {
                foreach ($existingMappings as $existingMapping) {
                    if ($existingMapping['fieldNo'] == $rawItemField['fieldNo']) {
                        continue 2;
                    }
                }
                $checks .= '  {{ CHECK '.$rawItemField['name'].' Text }} ?: \''.$translator->trans('pim.mapping.calculatedValue.data_quality.invalid_data', [], 'admin').': '.$rawItemField['name'].'\' => {{ CHECK '.$rawItemField['name'].' }},'.PHP_EOL;
            }

            $templates[] = [
                'value' => '$checks = [
'.$checks.'
];
$countChecks = 0;
$errors = [];
foreach ($checks as $error => $check) {
    if ($check !== null) {
        $countChecks++;
        if(empty($check)) {
            $errors[] = $error;
        }
    }
}

$return = \'\';
if({{ DATA QUALITY SHOW PROGRESS BAR }} ?? true) {
    $percentageValid = ($countChecks === 0 ? 100 : round((1 - count($errors) / $countChecks) * 100));
    $progressBarColor = \'#659cef\';
    if($percentageValid == 100) {
        $progressBarColor = \'#65ef6e\';
    }

    $return .= \'<div style="width:100%;background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);">
        <span class="progress-bar-fill" style="width:\'.$percentageValid.\'%;display:block;height:22px;background-color:\'.$progressBarColor.\';border-radius:3px;transition:width 500ms ease-in-out;"></span>
    </div>\';
}

if ($errors) {
    $return .= \'<ul><li>\'.implode(\'</li><li>\', $errors).\'</li></ul>\';
}

if($return) {
    $return = \'<div style="width:100%">
    \'.$return.\'
</div>\';
}

return $return;',
                'key' => $translator->trans('pim.mapping.calculatedValue.data_quality', [], 'admin')
            ];
        } elseif ($def instanceof Data\CalculatedValue && $def->getName() === '__result_action') {
            $templates[] = [
                'value' => '// by default response document gets provided as download. If you prefer to directly show the response document in the browser, uncomment the following line
// $params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

return $params[\'response\'];',
                'key' => $translator->trans('pim.mapping.template.action.output', [], 'admin')
            ];

            $templates[] = [
                'value' => '$fileExtension = $params[\'response\']->getFileExtension();
$params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

$filename = ({{ Response Document Asset Filename }} ?: \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\')).($fileExtension ? \'.\'.$fileExtension : \'\');
                
$folder = \OpenDxp\Model\Asset\Service::createFolderByPath({{ Response Document Asset Target Path }} ?: \'/\' );
$asset = \OpenDxp\Model\Asset::getByPath($folder->getFullPath().\'/\'.$filename);
if($asset === null) {
    $asset = \OpenDxp\Model\Asset::create($folder->getId(), array(
        \'filename\' => $filename,
        \'stream\' => $params[\'response\']->getOutputStream()
    ));
} else {
    $asset->setStream($params[\'response\']->getOutputStream());
    $asset->save();
}

return \'Successfully saved export document to \'.$asset->getFullPath();',
                'key' => $translator->trans('pim.mapping.template.action.save_as_asset', [], 'admin'),
            ];

            $templates[] = [
                'value' => '$fileExtension = $params[\'response\']->getFileExtension();
$params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

$filename = ({{ Response Document Asset Filename }} ?: \'export_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\')).\'.zip\';
                
$folder = \OpenDxp\Model\Asset\Service::createFolderByPath({{ Response Document Asset Target Path }} ?: \'/\' );
$asset = \OpenDxp\Model\Asset::getByPath($folder->getFullPath().\'/\'.$filename);

$zip = new \ZipArchive();
$sourcePath = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.zip\';
$zip->open($sourcePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$zip->addFile($params[\'response\']->getBackupFile(), {{ Response Document Asset Filename in Zip }} ?: (\'export\'.($fileExtension ? \'.\'.$fileExtension : \'\')));
$zip->close();

$stream = fopen($sourcePath, \'rb\');
if($asset === null) {
    $asset = \OpenDxp\Model\Asset::create($folder->getId(), array(
        \'filename\' => $filename,
        \'stream\' => $stream
    ));
} else {
    $asset->setStream($stream);
    $asset->save();
}

return \'Successfully saved export document to \'.$asset->getFullPath();',
                'key' => $translator->trans('pim.mapping.template.action.save_as_zipped_asset', [], 'admin'),
            ];

            $templates[] = [
                'value' => '$params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

$host = {{ FTP HOST }}; // Domain or IP address
$user = {{ FTP USER }};
$password = {{FTP PASSWORD }};
$filePath = {{ FTP TARGET FILE PATH }} ?: \'/pimcore-upload-\'.date(\'Y-m-d\'); // file path (including file name) on FTP server where result document shall be uploaded to

$filesystem = new \League\Flysystem\Filesystem(
    new \League\Flysystem\Ftp\FtpAdapter(
        \League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
            \'host\' => $host,
            \'username\' => $user,
            \'password\' => $password,
            \'port\' => 21,
            \'root\' => dirname($filePath),
            \'ssl\' => (bool){{ FTP over SSL }} ?: false
        ])
    )
);

$stream = $params[\'response\']->getOutputStream();

$tmpName = basename($filePath) . \'.tmp\';
$filesystem->writeStream($tmpName, $stream);
$filesystem->move($tmpName, basename($filePath));

return \'Export successfully uploaded to "\'.$filePath.\'"\';',
                'key' => $translator->trans('pim.mapping.template.action.ftp', [], 'admin'),
            ];

            $templates[] = [
                'value' => 'if($params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');
    
    $host = {{ SFTP HOST }}; // Domain or IP address
    $user = {{ SFTP USER }};
    $password = {{ SFTP PASSWORD }};
    $filePath = {{ SFTP TARGET FILE PATH }} ?: \'pimcore-upload\'; // file path (including file name) on server where result document shall be uploaded to
    
    $filesystem = new \League\Flysystem\Filesystem(
        new \League\Flysystem\PhpseclibV3\SftpAdapter(new \League\Flysystem\PhpseclibV3\SftpConnectionProvider(
            $host,
            $user,
            $password,
            null, // path to private key
            null, // // passphrase for private key (set to null if privateKey has no passphrase)
            22 // port
        ), \'/\')
    );
    
    $stream = $params[\'response\']->getOutputStream();
    
    $tmpFilePath = $filePath . \'.tmp\';
    $filesystem->writeStream($tmpFilePath, $stream);
    $filesystem->delete($filePath);
    $filesystem->move($tmpFilePath, $filePath);
    
    return \'File "\'.$filePath.\'" successfully uploaded\';
} else {
    return \'Response document is empty -> no upload\';
}',
                'key' => $translator->trans('pim.mapping.template.action.sftp', [], 'admin'),
            ];

            $templates[] = [
                'value' => 'if($params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
    $params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');
    
    // for further options see https://flysystem.thephpleague.com/v1/docs/adapter/aws-s3-v3/
    $filePath = {{ AWS S3 TARGET FILE PATH }} ?: \'pimcore-upload\'; // file path (including file name) on S3 bucket where result document shall be uploaded to
    
    $client = new \Aws\S3\S3Client([
        \'credentials\' => [
            \'key\'    => {{ AWS KEY }},
            \'secret\' => {{ AWS SECRET }},
        ],
        \'region\' => {{ AWS REGION }},
        \'version\' => \'latest\',
    ]);
    
    $filesystem = new \League\Flysystem\Filesystem(
        new \League\Flysystem\AwsS3v3\AwsS3Adapter($client, {{ AWS BUCKET }})
    );
    
    $stream = $params[\'response\']->getOutputStream();
    
    $tmpName = $filePath . \'.tmp\';
    $filesystem->writeStream($tmpName, $stream);
    $filesystem->move($tmpName, $filePath);
    
    return \'Export successfully uploaded to "\'.$filePath.\'"\';
} else {
    return \'Response document is empty -> no upload\';
}',
                'key' => $translator->trans('pim.mapping.template.action.s3', [], 'admin'),
            ];

            $templates[] = [
                'value' => '$params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

/**
 * The credentials will be auto-loaded by the Google Cloud Client.
 *
 * 1. The client will first look at the GOOGLE_APPLICATION_CREDENTIALS env var.
 *    You can use `putenv(\'GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json\');` to set the location of your credentials file.
 *
 * 2. The client will look for the credentials file at the following paths:
 * - windows: %APPDATA%/gcloud/application_default_credentials.json
 * - others: $HOME/.config/gcloud/application_default_credentials.json
 *
 * If running in Google App Engine, the built-in service account associated with the application will be used.
 * If running in Google Compute Engine, the built-in service account associated with the virtual machine instance will be used.
 */

$storageClient = new \Google\Cloud\Storage\StorageClient([
    \'projectId\' => {{ Google Cloud Storage PROJECT ID }},
]);
$bucket = $storageClient->bucket( {{ Google Cloud Storage BUCKET }});
$filePath = {{ Google Cloud Storage TARGET FILE PATH }} ?: \'pimcore-upload\'; // file path (including file name) on Google Cloud Storage bucket where result document shall be uploaded to

$filesystem = new \League\Flysystem\Filesystem(
    new \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter($bucket)
);

$stream = $params[\'response\']->getOutputStream();

$filesystem->writeStream($filePath, $stream);

return \'Export successfully uploaded to "\'.$filePath.\'"\';',
                'key' => $translator->trans('pim.mapping.template.action.gcp', [], 'admin'),
            ];

            $templates[] = [
                'value' => '$params[\'response\']->headers->set(\'Content-Type\', \'text/html\');
$params[\'response\']->headers->set(\'Content-Disposition\', \'inline\');

if(empty($params[\'response\']->getContent()) && empty({{ EMAIL PARAMETERS }})) {
    return \'Result callback function did not create a response document -> not sending mail\';
}
                
$mail = new \Sylphen\DataBridgeBundle\lib\Pim\Mail();
$document = {{ EMAIL DOCUMENT PATH }};
if($document) {
    $mail->setDocument($document);
    if(!$mail->getDocument() instanceof \OpenDxp\Model\Document\Email) {
        $params[\'logger\']->warning(\'The configured document \'.$document.\' is no Email document.\');
    }
}

if(empty($mail->getFrom())) {
    $mailFrom = \OpenDxp\Config::getSystemConfiguration(\'email\')[\'debug\'][\'email_addresses\'];
    $domain = \OpenDxp\Config::getSystemConfiguration(\'general\')[\'domain\'];
    if (empty($domain)) {
        $domain = \OpenDxp\Tool::getHostname();
    }
    if(empty($mailFrom) && $domain) {
        $mailFrom = \'no-reply@\'.$domain;
    }
    if (empty($mailFrom)) {
        $mailFrom = \'no-reply@OpenDxp\';
    }

    if(method_exists($mail, \'from\')) {
        $mail->from($mailFrom);
    } else {
        $mail->setFrom($mailFrom);
    }
}

$recipients = \Sylphen\DataBridgeBundle\lib\Pim\Helper::parseEmailRecipients({{ EMAIL RECIPIENTS }}); // email addresses / role names / user objects etc.

foreach($recipients as $recipient) {
    if(\OpenDxp::inDebugMode() && $recipient[\'email\'] !== \OpenDxp\Config::getSystemConfiguration(\'email\')[\'debug\'][\'email_addresses\']) {
        $params[\'logger\']->warning(\'Debug mode is active, emails will only be sent to the configured debug email address\');
    }
    $mail->addTo($recipient[\'email\'] ?? $recipient, $recipient[\'name\']);
}

$mail->setParams({{ EMAIL PARAMETERS }});

if(empty($mail->getSubject())) {
    $mail->setSubject({{ EMAIL SUBJECT }} ?: \''.$dataport['name'].'\');
}

if($params[\'response\']->hasContent()) {
    if({{ SEND RESPONSE DOCUMENT AS ATTACHMENT }}) {
        $mail->createAttachment($params[\'response\']->getContent(), $params[\'response\']->headers->get(\'Content-Type\') ?: \'text/txt\', {{ ATTACHMENT FILENAME }} ?: (\'dataport_\'.$params[\'context\'][\'dataportId\'].\'_\'.(!empty($params[\'context\'][\'resource\'][\'file\'])?$params[\'context\'][\'resource\'][\'file\']:\'Default\').\'_\'.date(\'Y-m-d-H-i-s\').\'.\'.$params[\'response\']->getFileExtensions() ?: \'txt\'));
    } else {
        $mail->setBodyHtml($params[\'response\']->getContent());
    }
}

$mail->send();

return \'Mail successfully sent\';',
                'key' => $translator->trans('pim.mapping.template.action.email', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportIds = {{ FOLLOW-UP DATAPORT IDS / NAMES }};

// provide parameters to use in dynamic import resource or in callback functions of dependent dataport
// leave empty to run dependent dataport with default import source
$parameters = {{ FOLLOW-UP DATAPORT PARAMETERS }};

if($dataportIds) {
    if(!is_array($dataportIds)) {
        $dataportIds = preg_split(\'/[,|;\n]\s*/\', $dataportIds);
    }
    
    $errorsCount = count(array_intersect(array_keys($params[\'logs\']), [\'warning\',\'error\',\'critical\',\'alert\',\'emergency\']));
    if($errorsCount > 0) {
        $dataportIds = []; // do not execute follow-up dataport if errors occured
    }
    
    foreach($dataportIds as $dataportId) {
        $command = \'data-bridge:complete "\'.$dataportId.\'" --locale=\'.$params[\'request\']->getLocale().\' --user=\'.$params[\'context\'][\'user\'][\'id\'].\' --rm\'.($parameters?\' --force --parameters=\'.escapeshellarg(json_encode($parameters)):\'\');
        
        if({{ FOLLOW-UP DATAPORT EXECUTE ASYNCHRONICALLY }}) {
            $params[\'logger\']->info(\'Queuing command "\'.$command.\'"\');
            $queue = new \Sylphen\DataBridgeBundle\model\Queue();
            $queue->create([\'command\' => $command, \'triggered_by\' => \'Result callback function of dataport \'.$params[\'context\'][\'dataportId\']]);
        } else {
            $output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec($command);
            
            if(preg_match(\'/X-Data-Bridge-Run:\s*(.+)(\n|$)/\', $output, $dependentRunId)) {
                $responseDocumentPath = \Sylphen\DataBridgeBundle\Tools\Installer::getResultDocumentPath().\'/result_\'.$dataportId.\'_\'.trim($dependentRunId[1]);
                
                if(file_exists($responseDocumentPath)) {
                    $responseDocument = \unserialize(\file_get_contents($responseDocumentPath));
                    if($responseDocument) {
                        return $responseDocument;
                    }
                }
            }
        }
    }
}',
                'key' => $translator->trans('pim.mapping.template.dependent_import', [], 'admin')
            ];

            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportIds = {{ FOLLOW-UP DATAPORT IDS / NAMES }};

// provide parameters to use in dynamic import resource or in callback functions of dependent dataport
// leave empty to run dependent dataport with default import source
$parameters = {{ DEPENDENT DATAPORT PARAMETERS }};

$errorsCount = count(array_intersect(array_keys($params[\'logs\']), [\'warning\',\'error\',\'critical\',\'alert\',\'emergency\']));
if($errorsCount > 0) {
    $dataportIds = []; // do not execute follow-up dataport if errors occured
}

foreach($dataportIds as $dataportId) {
    $resultDocumentPath = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_\'.$params[\'context\'][\'dataportId\'].\'_\'.uniqid().\'.\'.$params[\'response\']->getFileExtension();
    $stream = fopen($resultDocumentPath, \'wb\');
    stream_copy_to_stream($params[\'response\']->getOutputStream(), $stream);
    fclose($stream);

    $command = \'data-bridge:complete "\'.$dataportId.\'" "\'.$resultDocumentPath.\'" --locale=\'.$params[\'request\']->getLocale().\' --user=\'.$params[\'context\'][\'user\'][\'id\'].\' --rm\'.($parameters?\' --force --parameters=\'.escapeshellarg(json_encode($parameters)):\'\');
    
    if({{ DEPENDENT DATAPORT EXECUTE ASYNCHRONICALLY }}) {
        $params[\'logger\']->info(\'Queuing command "\'.$command.\'"\');
        $queue = new \Sylphen\DataBridgeBundle\model\Queue();
        $queue->create([\'command\' => $command, \'triggered_by\' => \'Result callback function of dataport \'.$params[\'context\'][\'dataportId\']]);
    } else {
        $output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec($command);
        
        if(preg_match(\'/X-Data-Bridge-Run:\s*(.+)(\n|$)/\', $output, $dependentRunId)) {
            $responseDocumentPath = \Sylphen\DataBridgeBundle\Tools\Installer::getResultDocumentPath().\'/result_\'.{{ DEPENDENT DATAPORT ID }}.\'_\'.trim($dependentRunId[1]);
            
            if(file_exists($responseDocumentPath)) {
                $responseDocument = \unserialize(\file_get_contents($responseDocumentPath));
                if($responseDocument) {
                    return $responseDocument;
                }
            }
        }
    }
}',
                'key' => $translator->trans('pim.mapping.template.dependent_import_with_result_document', [], 'admin')
            ];


            $templates[] = [
                'value' => '// Enter id of dataport to be executed
$dataportId = {{ FOLLOW-UP DATAPORT IDS / NAMES }};

// provide parameters to use in dynamic import resource, raw data fields or in callback functions of dependent dataport
// parameters should get returned as key-value array, e.g. `return [\'parameter\' => \'value\', \'otherParameter\' => 123]`
// leave empty to run dependent dataport with default import source
$parameters = {{ FOLLOW-UP DATAPORT PARAMETERS }};

$params[\'response\']->headers->set(\'Content-Type\', \'application/json\');

return json_encode([
    \'dependentDataportId\' => $dataportId,
    \'dependentDataportParameters\' => $parameters
], \JSON_UNESCAPED_UNICODE);',
                'key' => $translator->trans('pim.mapping.template.parametrize_dependent_import', [], 'admin')
            ];

            $templates[] = [
                'value' => '$websiteSetting = \OpenDxp\Model\WebsiteSetting::getByName({{ WEBSITE SETTING NAME }});
if (!$websiteSetting instanceof \OpenDxp\Model\WebsiteSetting) {
  $websiteSetting = new \OpenDxp\Model\WebsiteSetting();
  $websiteSetting->setName({{ WEBSITE SETTING NAME }});
  $websiteSetting->setType(\'text\');
}
 
$websiteSetting->setData({{ WEBSITE SETTING DATA }});
$websiteSetting->save();',
                'key' => $translator->trans('pim.mapping.template.save_to_website_settings', [], 'admin')
            ];

        } elseif ($def instanceof Data\CalculatedValue && $def->getName() === '__init_action') {
            $templates[] = [
                'value' => '$dataports = \Sylphen\DataBridgeBundle\model\Dataport::getInstance();
return !$dataports->isRunning($params[\'context\'][\'dataportId\'], $params[\'context\'][\'statusKey\']) && !$dataports->isQueued($params[\'context\'][\'dataportId\']);',
                'key' => $translator->trans('pim.mapping.template.init.abort_if_other_processes_running', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// Enter the max numbers of process for incremental export in virtual field "MAX JOBS FOR INCREMENTAL EXPORT"
$dataports = \Sylphen\DataBridgeBundle\model\Dataport::getInstance();
if($dataports->countQueuedJobs($params[\'context\'][\'dataportId\']) + $dataports->countRunningJobs($params[\'context\'][\'dataportId\']) > ({{ MAX JOBS FOR INCREMENTAL EXPORT }} ?? INF)) {
    $queue = new \Sylphen\DataBridgeBundle\model\Queue();
    $queue->convertIncrementalToBulkExports($params[\'context\'][\'dataportId\']);
    return false;
}',
                'key' => $translator->trans('pim.mapping.template.init.queue_bulk_export_if_too_many_incremental_exports_queued', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// Enter id or name of dataport to be executed
$dataportIds = {{ PREDECESSOR DATAPORT IDS / NAMES }};

$parameters = [
    \'file\' => $params[\'context\'][\'resources\'][0][\'file\'] // run predecessor dataport with same import resource as current dataport run
];

if($dataportIds) {
    if(!is_array($dataportIds)) {
        $dataportIds = preg_split(\'/[,|;\n]\s*/\', $dataportIds);
    }
    
    foreach($dataportIds as $dataportId) {
        $command = \'data-bridge:complete "\'.$dataportId.\'" --locale=\'.$params[\'request\']->getLocale().\' --user=\'.$params[\'context\'][\'user\'][\'id\'].($parameters?\' --force --parameters=\'.escapeshellarg(json_encode($parameters)):\'\');
        
        $output = \Sylphen\DataBridgeBundle\lib\Pim\Cli::exec($command);
        
        if(preg_match(\'/X-Data-Bridge-Run:\s*(.+)(\n|$)/\', $output, $dependentRunId)) {
            $responseDocumentPath = \Sylphen\DataBridgeBundle\Tools\Installer::getResultDocumentPath().\'/result_\'.$dataportId.\'_\'.trim($dependentRunId[1]);
            
            if(file_exists($responseDocumentPath)) {
                $dependentDataportResponseDocument = \unserialize(\file_get_contents($responseDocumentPath));
                if($dependentDataportResponseDocument) {
                    return $dependentDataportResponseDocument;
                }
            }
        }
    }
}',
                'key' => $translator->trans('pim.mapping.template.predecessor_import', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// To automatically execute the dataport, please check "Run automatically on new data" checkbox in dataport settings

if(!isset($params[\'context\'][\'statusKey\']) || substr($params[\'context\'][\'statusKey\'], strrpos($params[\'context\'][\'statusKey\'], \'-\')+1) < 3) {
    $websiteSettingName = \'next-execution-dataport-\'.$params[\'context\'][\'dataportId\'];
    $websiteSetting = \OpenDxp\Model\WebsiteSetting::getByName($websiteSettingName);    
    if (!$websiteSetting instanceof \OpenDxp\Model\WebsiteSetting) {
        $websiteSetting = new \OpenDxp\Model\WebsiteSetting();
        $websiteSetting->setName($websiteSettingName);
        $websiteSetting->setType(\'text\');
        $websiteSetting->setData(\'@0\');
    }
    
    $now = new \DateTime();
    $nextExecutionDate = new \DateTime($websiteSetting->getData());
    if($nextExecutionDate > $now) {
        $params[\'logger\']->info(\'Dataport is configured to be executed earliest at \'.$nextExecutionDate->format(\'Y-m-d H:i:s e\').\'. Please remove / change website setting "\'.$websiteSettingName.\'" to execute it earlier.\');
        return false;
    }
    
    $websiteSetting->setData($now->add(new \DateInterval(\'PT\'.(({{ Hours between executions }} ?: 0) * 3600).\'S\'))->format(\'Y-m-d H:i:s e\'));
    $websiteSetting->save();
}',
                'key' => $translator->trans('pim.mapping.template.init.only_execute_every_x_hours', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// To automatically execute the dataport, please check "Run automatically on new data" checkbox in dataport settings
                
if(!isset($params[\'context\'][\'statusKey\']) || substr($params[\'context\'][\'statusKey\'], strrpos($params[\'context\'][\'statusKey\'], \'-\')+1) < 3) {
    $websiteSettingName = \'next-execution-dataport-\'.$params[\'context\'][\'dataportId\'];
    $websiteSetting = \OpenDxp\Model\WebsiteSetting::getByName($websiteSettingName);    
    if (!$websiteSetting instanceof \OpenDxp\Model\WebsiteSetting) {
        $websiteSetting = new \OpenDxp\Model\WebsiteSetting();
        $websiteSetting->setName($websiteSettingName);
        $websiteSetting->setType(\'text\');
        $websiteSetting->setData(\'@0\');
    }
    
    $now = new \DateTime();
    $nextExecutionDate = new \DateTime($websiteSetting->getData());
    if($nextExecutionDate > $now) {
        $params[\'logger\']->info(\'Dataport is configured to be executed earliest at \'.$nextExecutionDate->format(\'Y-m-d H:i:s e\').\'. Please remove / change website setting "\'.$websiteSettingName.\'" to execute it earlier.\');
        return false;
    }
    
    $cron = new \Cron\CronExpression({{ Cronjob expression }} ?: \'0 0 * * *\'); // see http://www.cronmaker.com to create this expression, copy the generated "Cron format" from there
    $websiteSetting->setData($cron->getNextRunDate()->format(\'Y-m-d H:i:s e\'));
    $websiteSetting->save();
}',
                'key' => $translator->trans('pim.mapping.template.init.cronjob', [], 'admin'),
            ];

            $templates[] = [
                'value' => '// Please enable checkboxes "Run automatically on new data" and "incremental export" to collect data and later get the response document via bin/console data-bridge:process '.$dataportId.' --parameters="run=1"
if(empty({{ run }})) {
    return false;
}',
                'key' => $translator->trans('pim.mapping.template.init.parameter-dependent-run', [], 'admin'),
            ];
        } elseif ($def instanceof Data\Wysiwyg || $def instanceof Data\Textarea) {
            $fieldTable = RawItemField::getInstance();
            $existingFields = $fieldTable->find(array('dataportId = ?' => $dataportId, 'name NOT IN (?)' => ['id', 'o_id', 'modificationDate', 'creationDate']), 'priority');

            if (count($existingFields) > 0) {
                $templates[] = [
                    'value' => '$toString = function($value) use ($params, &$toString) {
    switch (gettype($value)) {
        case \'boolean\': return \OpenDxp::getContainer()->get(\'translator\')->trans($data ? \'yes\' : \'no\', [], \'admin\');
        case \'NULL\'   : return null;
        case \'object\' :
        case \'array\'  :
            if(isset($value[\'value\'], $value[\'unit\'])) {
                return implode(\' \', $value);
            }
            
            if(isset($value[\'key\'])) {
                return $value[\'key\'];
            }
            
            $expressions = [];
            foreach ($value as $key => $item) {
                $serialized = $toString($item);
                if($serialized) {
                    $expressions[] = (!is_numeric($key) ? $key.\': \' : \'\').$serialized;
                }
            }
            return implode(\', \', $expressions);
    default: return (string)$value;
  }
};

$data = [];
'.implode("\n",array_map(static function ($field) {
    $fieldParts = explode('#', $field['name']);
    return '$data[\''.$fieldParts[0].'\'] = $toString($params[\'rawItemData\'][\''.$field['name'].'\'][\'value\']);';
}, $existingFields)).'

$data = array_filter($data);

return implode("\n", array_map(
    function ($value, $field) { return $field.\': \'.$value; },
    $data,
    array_keys($data)
));',
                    'key' => $translator->trans('pim.mapping.template.textarea.concatenate_raw_data_fields', [], 'admin'),
                ];

                $code = $this->getDeeplTranslationTemplate($dataportId);
                $templates[] = [
                    'value' => $code,
                    'key' => $translator->trans('pim.mapping.deepl_translation', [], 'admin')
                ];
            }
        } elseif ($def instanceof GenericObjectRelation) {
            if (strtolower($def->getName()) === 'metadata') {
                $itemMold = $this->itemMoldBuilder->getItemMold($dataportId);
                if($itemMold instanceof Asset) {
                    $predefinedMetadataListing = new OpenDxp\Model\Metadata\Predefined\Listing();
                    $predefinedGroups = [];
                    foreach($predefinedMetadataListing->load() as $predefined) {
                        $group = null;
                        if(method_exists($predefined, 'getGroup')) {
                            $group = $predefined->getGroup();
                        }

                        if(!$group) {
                            $group = $translator->trans('pim.mapping.assign_metadata_predefined_default', [], 'admin');
                        }
                        $predefinedGroups[$group][] = $predefined;
                    }

                    foreach($predefinedGroups as $group => $predefinedMetaItems) {
                        $templates[] = [
                            'value' => 'return [
'.implode(','.PHP_EOL, array_map(static function(OpenDxp\Model\Metadata\Predefined $predefinedMetaItem) {
                                return '  \''.$predefinedMetaItem->getName().($predefinedMetaItem->getLanguage() ? '#'.$predefinedMetaItem->getLanguage() : '').'\' => {{ META '.$predefinedMetaItem->getName().' }}';
                            }, $predefinedMetaItems)).'
];',
                            'key' => sprintf($translator->trans('pim.mapping.assign_metadata_predefined', [], 'admin'), $group)
                        ];
                    }
                }

                $templates[] = [
                    'value' => '$metaData = [];

foreach((array)$params[\'value\'] as $metaName => $metaItem) {
    $metaValue = $metaItem[\'data\'] ?? $metaItem;
    $metaData[] = [
        \'name\' => $metaItem[\'name\'] ?? $metaKey, 
        \'data\' => $metaValue, 
        \'type\' => $metaValue instanceof ElementInterface ? \OpenDxp\Model\Element\Service::getElementType($metaValue) : \'input\', 
        \'language\' => $metaItem[\'language\'] ?? null
    ];
}

return $metaData;',
                    'key' => $translator->trans('pim.mapping.assign_metadata', [], 'admin')
                ];
            }
        } elseif ($def instanceof Data\AdvancedManyToManyObjectRelation) {
            $allowedClass = self::getClassDefinitionByName($def->getAllowedClassId());

            if ($allowedClass instanceof ClassDefinition) {
                $fields = $allowedClass->getFieldDefinitions();
                $hasTemplate = false;
                foreach ($fields as $field) {
                    $fieldCandidates = [];
                    if ($field instanceof Data\Localizedfields) {
                        foreach ($field->getFieldDefinitions() as $fieldCandidate) {
                            foreach (Tool::getValidLanguages() as $languageCode) {
                                $fieldCandidateClone = clone $fieldCandidate;
                                $fieldCandidateClone->setName($fieldCandidate->getName().'#'.$languageCode);
                                $fieldCandidates[] = $fieldCandidateClone;
                            }
                        }
                    } else {
                        $fieldCandidates = [$field];
                    }

                    foreach ($fieldCandidates as $fieldCandidate) {
                        if ($fieldCandidate->getUnique() || $fieldCandidate->getIndex()) {
                            $template = '$return = [];

$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // either access '.$fieldCandidate->getName().' and meta field content via array keys of $item or via $params[\'rawItemData\'] - depends on raw data field definition
  $return[] = [
    \'query\' => \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$item[\''.$fieldCandidate->getName().'\'] ?? $item,';
                            foreach ($def->getColumnKeys() as $metaColumn) {
                                $template .= '
    \''.$metaColumn.'\' => $item[\''.$metaColumn.'\'] ?? \'\',';
                            }

                            $template .= '
  ];
}
            
return $return;';

                            $templates[] = [
                                'value' => $template,
                                'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $fieldCandidate->getName())
                            ];
                            $hasTemplate = true;
                        }
                    }
                }

                $exampleFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? LIMIT 1', [$allowedClass->getId()]);
                $pathPrefix = $exampleFolder ?: '';
                if ($pathPrefix) {
                    $otherFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? AND '.Helper::prefixObjectSystemColumn('path').' != ? LIMIT 1', [$allowedClass->getId(), $pathPrefix]);
                    if ($otherFolder) {
                        $pathPrefix = '';
                    }
                }

                $template = '$return = [];

$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // either access meta field content by array keys of $item or by $params[\'rawItemData\'] - depends on raw data field definition
  $return[] = [
    \'query\' => \''.$allowedClass->getName().':path:\'.$item[\'path\'] ?? $item,';
                foreach ($def->getColumnKeys() as $metaColumn) {
                    $template .= '
    \''.$metaColumn.'\' => $item[\''.$metaColumn.'\'] ?? \'\',';
                }

                $template .= '
  ];
}
            
return $return;';

                $templates[] = [
                    'value' => $template,
                    'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $translator->trans('path', [], 'admin')).(($hasTemplate === false) ? ' ('.\sprintf(
                                $translator->trans('pim.mapping.add_index', [], 'admin'),
                                $allowedClass->getName()
                            ).')' : '')
                ];
            }
        } elseif ($def instanceof Data\ManyToManyObjectRelation) {
            foreach ((array)$def->getClasses() as $allowedClass) {
                $allowedClass = self::getClassDefinitionByName($allowedClass['classes']);

                if ($allowedClass instanceof ClassDefinition) {
                    $fields = $allowedClass->getFieldDefinitions();

                    $hasTemplate = false;
                    foreach ($fields as $field) {
                        $fieldCandidates = [];
                        if ($field instanceof Data\Localizedfields) {
                            foreach ($field->getFieldDefinitions() as $fieldCandidate) {
                                foreach (Tool::getValidLanguages() as $languageCode) {
                                    $fieldCandidateClone = clone $fieldCandidate;
                                    $fieldCandidateClone->setName($fieldCandidate->getName().'#'.$languageCode);
                                    $fieldCandidates[] = $fieldCandidateClone;
                                }
                            }
                        } else {
                            $fieldCandidates = [$field];
                        }

                        foreach ($fieldCandidates as $fieldCandidate) {
                            if ($fieldCandidate->getUnique() || $fieldCandidate->getIndex()) {
                                $template = '$return = [];
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  $return[] = \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$item;
}
        
return $return;';

                                $templates[] = [
                                    'value' => $template,
                                    'key' => \sprintf(
                                        $translator->trans('pim.mapping.assign_field_by', [], 'admin'),
                                        $allowedClass->getName(),
                                        $fieldCandidate->getName()
                                    )
                                ];
                                $hasTemplate = true;
                            }
                        }
                    }

                    $exampleFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? LIMIT 1', [$allowedClass->getId()]);
                    $pathPrefix = $exampleFolder ?: '';
                    if ($pathPrefix) {
                        $otherFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? AND '.Helper::prefixObjectSystemColumn('path').' != ? LIMIT 1', [$allowedClass->getId(), $pathPrefix]);
                        if ($otherFolder) {
                            $pathPrefix = '';
                        }
                    }

                    $template = '$return = [];

$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  $return[] = \''.$allowedClass->getName().':path:'.$pathPrefix.'\'.$item;
}
        
return $return;';

                    $templates[] = [
                        'value' => $template,
                        'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $translator->trans('path', [], 'admin')).(($hasTemplate === false) ? ' ('.\sprintf(
                                    $translator->trans('pim.mapping.add_index', [], 'admin'),
                                    $allowedClass->getName()
                                ).')' : '')
                    ];
                }
            }
        } elseif ($def instanceof Data\ManyToManyRelation) {
            if ($def->getObjectsAllowed()) {
                foreach ((array)$def->getClasses() as $allowedClass) {
                    $allowedClass = self::getClassDefinitionByName($allowedClass['classes']);

                    if ($allowedClass instanceof ClassDefinition) {
                        $hasTemplate = false;
                        $fields = $allowedClass->getFieldDefinitions();
                        foreach ($fields as $field) {
                            $fieldCandidates = [];
                            if ($field instanceof Data\Localizedfields) {
                                foreach ($field->getFieldDefinitions() as $fieldCandidate) {
                                    foreach (Tool::getValidLanguages() as $languageCode) {
                                        $fieldCandidateClone = clone $fieldCandidate;
                                        $fieldCandidateClone->setName($fieldCandidate->getName().'#'.$languageCode);
                                        $fieldCandidates[] = $fieldCandidateClone;
                                    }
                                }
                            } else {
                                $fieldCandidates = [$field];
                            }

                            foreach ($fieldCandidates as $fieldCandidate) {
                                if ($fieldCandidate->getUnique() || $fieldCandidate->getIndex()) {
                                    $template = '$return = [];
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  $return[] = \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$item;
}
        
return $return;';

                                    $templates[] = [
                                        'value' => $template,
                                        'key' => \sprintf(
                                            $translator->trans('pim.mapping.assign_field_by', [], 'admin'),
                                            $allowedClass->getName(),
                                            $fieldCandidate->getName()
                                        )
                                    ];
                                    $hasTemplate = true;
                                }
                            }
                        }

                        $exampleFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM object_'.$allowedClass->getId().' LIMIT 1');
                        $pathPrefix = $exampleFolder ?: '';
                        if ($pathPrefix) {
                            $otherFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM object_'.$allowedClass->getId().' WHERE '.Helper::prefixObjectSystemColumn('path').' != ? LIMIT 1', [$pathPrefix]);
                            if ($otherFolder) {
                                $pathPrefix = '';
                            }
                        }

                        $template = '$return = [];
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  $return[] = \''.$allowedClass->getName().':path:'.$pathPrefix.'\'.$item;
}
        
return $return;';

                        $templates[] = [
                            'value' => $template,
                            'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $translator->trans('path', [], 'admin')).(($hasTemplate === false) ? ' ('.\sprintf(
                                        $translator->trans('pim.mapping.add_index', [], 'admin'),
                                        $allowedClass->getName()
                                    ).')' : '')
                        ];
                    }
                }
            }

            if ($def->getAssetsAllowed()) {
                $assetTypes = (array)$def->getAssetTypes();
                if(count($assetTypes) === 0) {
                    $assetTypes = [['assetTypes' => 'asset']];
                }

                foreach ($assetTypes as $allowedAssetType) {
                    $allowedAssetType = $allowedAssetType['assetTypes'];

                    $templates[] = [
                        'value' => '$return = [];
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // $item can be a path to a file or a URL
  $return[] = [
    \'url\' => $item,'.((!empty($sourceConfig['assetSource'])) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
    //\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'] // optional, if omitted the file name of the loaded file gets used
  ];
}

return $return;',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                            $translator->trans($allowedAssetType, [], 'admin')
                        )
                    ];

                    $templates[] = [
                        'value' => '$return = [];
                        
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // path means the full path (path + key)
  // you can also use other fields than path (e.g. ID, key etc.)
  $return[] = [\'query\' => \''.ucfirst($allowedAssetType).':path:\'.$item];
}

return $return;',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                            $translator->trans($allowedAssetType, [], 'admin')
                        )
                    ];

                    foreach (
                        PimcoreDbRepository::getInstance()->findColumnInSql(
                            'SELECT assets_metadata.name FROM assets_metadata INNER JOIN assets ON assets_metadata.cid=assets.id WHERE assets.type=\'image\' AND assets_metadata.type IN (\'input\', \'textarea\', \'date\', \'select\', \'checkbox\') GROUP BY assets_metadata.name ORDER BY assets_metadata.name'
                        ) as $metaDataFieldName
                    ) {
                        $templates[] = [
                            'value' => '$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  $return[] = [\'query\' => \''.ucfirst($allowedAssetType).':'.$metaDataFieldName.':\'.$item];
}

return $return;',
                            'key' => \sprintf(
                                $translator->trans('pim.mapping.assign_asset_by_meta_field', [], 'admin'),
                                $translator->trans($allowedAssetType, [], 'admin'),
                                $metaDataFieldName
                            )
                        ];
                    }
                }
            }

            if ($def->getDocumentsAllowed()) {
                foreach ((array)$def->getDocumentTypes() as $allowedDocumentType) {
                    $allowedDocumentType = $allowedDocumentType['documentTypes'];

                    $templates[] = [
                        'value' => '$return = [];
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // path means the full path (path + key)
  // you can also use other fields than path (e.g. ID, key etc.)
  $return[] = [\'query\' => \''.ucfirst($allowedDocumentType).':path:\'.$item];
}

return $return;',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                            $translator->trans(ucfirst($allowedDocumentType), [], 'admin')
                        )
                    ];
                }
            }
        } elseif ($def instanceof Data\ManyToOneRelation) {
            if ($def->getObjectsAllowed()) {
                foreach ((array)$def->getClasses() as $allowedClass) {
                    $allowedClass = self::getClassDefinitionByName($allowedClass['classes']);

                    if ($allowedClass instanceof ClassDefinition) {
                        $hasTemplate = false;
                        $fields = $allowedClass->getFieldDefinitions();
                        foreach ($fields as $field) {
                            if ($field instanceof Data\Localizedfields) {
                                foreach ($field->getFieldDefinitions() as $fieldCandidate) {
                                    foreach (Tool::getValidLanguages() as $languageCode) {
                                        $fieldCandidateClone = clone $fieldCandidate;
                                        $fieldCandidateClone->setName($fieldCandidate->getName().'#'.$languageCode);
                                        $fieldCandidates[] = $fieldCandidateClone;
                                    }
                                }
                            } else {
                                $fieldCandidates = [$field];
                            }

                            foreach ($fieldCandidates as $fieldCandidate) {
                                if ($fieldCandidate->getUnique() || $fieldCandidate->getIndex()) {
                                    $template = 'if($params[\'value\']) {
  return \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$params[\'value\'];
}';

                                    $templates[] = [
                                        'value' => $template,
                                        'key' => \sprintf(
                                            $translator->trans('pim.mapping.assign_field_by', [], 'admin'),
                                            $allowedClass->getName(),
                                            $fieldCandidate->getName()
                                        )
                                    ];
                                    $hasTemplate = true;
                                }
                            }
                        }

                        $exampleFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? LIMIT 1', [$allowedClass->getId()]);
                        $pathPrefix = $exampleFolder ?: '';
                        if ($pathPrefix) {
                            $otherFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? AND '.Helper::prefixObjectSystemColumn('path').' != ? LIMIT 1', [$allowedClass->getId(), $pathPrefix]);
                            if ($otherFolder) {
                                $pathPrefix = '';
                            }
                        }

                        $template = 'if($params[\'value\']) {
  return \''.$allowedClass->getName().':path:'.$pathPrefix.'\'.$params[\'value\'];
}';

                        $templates[] = [
                            'value' => $template,
                            'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $translator->trans('path', [], 'admin')).(($hasTemplate === false) ? ' ('.\sprintf(
                                        $translator->trans('pim.mapping.add_index', [], 'admin'),
                                        $allowedClass->getName()
                                    ).')' : '')
                        ];
                    }
                }
            }

            if ($def->getAssetsAllowed()) {
                $assetTypes = (array)$def->getAssetTypes();
                if (count($assetTypes) === 0) {
                    $assetTypes = [['assetTypes' => 'asset']];
                }

                foreach ($assetTypes as $allowedAssetType) {
                    $allowedAssetType = $allowedAssetType['assetTypes'];

                    $templates[] = [
                        'value' => '// path means the full path (path + key)
// you can also use other fields than path (e.g. ID, key etc.)
return [\'query\' => \''.ucfirst($allowedAssetType).':path:\'.$item];',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                            $translator->trans(ucfirst($allowedAssetType), [], 'admin')
                        )
                    ];

                    $templates[] = [
                        'value' => '// $params[\'value\'] can be a path to a file or a URL
return [
\'url\' => $params[\'value\'],'.(($sourceConfig['assetSource'] ?? null) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
//\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'] // optional, if omitted the file name of the loaded file gets used
];',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                            $translator->trans(ucfirst($allowedAssetType), [], 'admin')
                        )
                    ];
                }
            }

            if ($def->getDocumentsAllowed()) {
                foreach ((array)$def->getDocumentTypes() as $allowedDocumentType) {
                    $allowedDocumentType = $allowedDocumentType['documentTypes'];

                    $templates[] = [
                        'value' => '// path means the full path (path + key)
// you can also use other fields than path (e.g. ID, key etc.)
return [\'query\' => \''.ucfirst($allowedDocumentType).':path:\'.$item];',
                        'key' => \sprintf(
                            $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                            $translator->trans(ucfirst($allowedDocumentType), [], 'admin')
                        )
                    ];
                }
            }
        } elseif ($def instanceof Data\Hotspotimage) {
            $templates[] = [
                'value' => '// $params[\'value\'] can be a path to a file or a URL
return [
  \'url\' => $params[\'value\'],'.(!empty($sourceConfig['assetSource']) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
  //\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'], // optional, if omitted the file name of the loaded file gets used
  \'hotspots\' => [
    [
      \'x\' => rand(0,50), 
      \'y\' => rand(0,50), 
      \'width\' => 50, 
      \'height\' => 20,
      \'data\' => \'\' // Text, data query selector or object
    ]
  ]
];',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                    $translator->trans('Image', [], 'admin')
                )
            ];

            $templates[] = [
                'value' => 'if($params[\'value\']) {
  // path means the full path (path + key)
  // you can also use other fields than path (e.g. ID, key etc.)
  return [
    \'query\' => \'Image:path:\'.$params[\'value\'],
    \'hotspots\' => [
      [
        \'x\' => rand(0,50), 
        \'y\' => rand(0,50), 
        \'width\' => 50, 
        \'height\' => 20,
        \'data\' => \'\' // Text, data query selector or object
      ]
    ]
  ];
}',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                    $translator->trans('Image', [], 'admin')
                )
            ];
        } elseif ($def instanceof Data\Image) {
            $templates[] = [
                'value' => '// $params[\'value\'] can be a path to a file or a URL
return [
  \'url\' => $params[\'value\'],'.(!empty($sourceConfig['assetSource']) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
  //\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'] // optional, if omitted the file name of the loaded file gets used
];',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                    $translator->trans('Image', [], 'admin')
                )
            ];

            $templates[] = [
                'value' => 'if($params[\'value\']) {
    // path means the full path (path + key)
    // you can also use other fields than path (e.g. ID, key etc.)
    return [\'query\' => \'Image:path:\'.$params[\'value\']];
}',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                    $translator->trans('Image', [], 'admin')
                )
            ];

            foreach(PimcoreDbRepository::getInstance()->findColumnInSql('SELECT assets_metadata.name FROM assets_metadata INNER JOIN assets ON assets_metadata.cid=assets.id WHERE assets.type=\'image\' AND assets_metadata.type IN (\'input\', \'textarea\', \'date\', \'select\', \'checkbox\') GROUP BY assets_metadata.name ORDER BY assets_metadata.name') as $metaDataFieldName) {
                $templates[] = [
                    'value' => 'if($params[\'value\']) {
    return [\'query\' => \'Image:'.$metaDataFieldName.':\'.$params[\'value\']];
}',
                    'key' => \sprintf(
                        $translator->trans('pim.mapping.assign_asset_by_meta_field', [], 'admin'),
                        $translator->trans('Image', [], 'admin'),
                        $metaDataFieldName
                    )
                ];
            }

            $types = ['codabar','code128','code25','code25interleaved','code39','ean13','ean2','ean5','ean8','error','identcode','itf14','leitcode','planet','postnet','royalmail','upca' ,'upce'];

            $templates[] = [
                'value' => '// please enable "Overwrite images" option above, otherwise every import run will create a new image
// available barcode types: '.implode(', ', $types).'
$barcodeType = \'ean13\';

if(!$params[\'value\']) {
  return $params[\'currentValue\'];
}

$renderer = \Laminas\Barcode\Barcode::factory(
    $barcodeType,
    \'svg\',
    [
        \'text\' => $params[\'value\'],
        \'providedChecksum\' => $barcodeType === \'ean13\' && strlen($params[\'value\']) === 13,
    ]
);
$resource = $renderer->draw();

$filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_'.$dataportId.'_'.$def->getName().'_\'.\uniqid($params[\'value\'], true).\'.svg\';
file_put_contents($filename, $resource->saveXML());
return [
  \'url\' => $filename,
  \'filename\' => \OpenDxp\File::getValidFilename($params[\'value\'].\'.svg\')
];',
                'key' => $translator->trans('pim.mapping.asset_generate_barcode', [], 'admin')
            ];

            $templates[] = [
                'value' => '// please enable "Overwrite images" option above, otherwise every import run will create a new image 
$url = {{ '.$def->getTitle().' QR-Code Text }};

$qrCode = new \Endroid\QrCode\QrCode($url ?? \'\');
$filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.\'/import_'.$dataportId.'_'.$def->getName().'_\'.\uniqid(\OpenDxp\File::getValidFilename($url), true).\'.svg\';

$writer = new \Endroid\QrCode\Writer\SvgWriter();
if(method_exists($writer, \'write\')) {
    // endroid/qr-code version >= 4
    $writer->write($qrCode)->saveToFile($filename);
} else {
    // endroid/qr-code version 3
    $qrCode->setWriter($writer);
    $qrCode->writeFile($filename);
}

$qrCodeFilename = {{ '.$def->getTitle().' QR-Code Filename }} ?? $params[\'value\'];
if(substr($qrCodeFilename, -4) !== \'.svg\') {
    $qrCodeFilename .= \'.svg\';
}
return [
  \'url\' => $filename,
  \'filename\' => \''.$def->getName().'/\'.\OpenDxp\File::getValidFilename($qrCodeFilename),
];',
                'key' => $translator->trans('pim.mapping.asset_generate_qrcode', [], 'admin')
            ];
        } elseif ($def instanceof Data\ImageGallery) {
            $templates[] = [
                'value' => 'return array_map(function($sourcePath) use ($params) {
    return [
        \'url\' => $sourcePath,'.(!empty($sourceConfig['assetSource']) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
        //\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'] // optional, if omitted the file name of the loaded file gets used
    ];
}, (array)$params[\'value\']);',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                    $translator->trans('image', [], 'admin')
                )
            ];

            $templates[] = [
                'value' => 'return array_map(function($sourcePath) use ($params) {
    // path means the full path (path + key)
    // you can also use other fields than path (e.g. ID, filename etc.)
    return [\'query\' => \'Image:path:\'.$sourcePath];
}, (array)$params[\'value\']);',
                'key' => \sprintf(
                    $translator->trans('pim.mapping.assign_asset_by_query', [], 'admin'),
                    $translator->trans('image', [], 'admin')
                )
            ];

            foreach (
                PimcoreDbRepository::getInstance()->findColumnInSql(
                    'SELECT assets_metadata.name FROM assets_metadata INNER JOIN assets ON assets_metadata.cid=assets.id WHERE assets.type=\'image\' AND assets_metadata.type IN (\'input\', \'textarea\', \'date\', \'select\', \'checkbox\') GROUP BY assets_metadata.name ORDER BY assets_metadata.name'
                ) as $metaDataFieldName
            ) {
                $templates[] = [
                    'value' => 'return array_map(function($'.$metaDataFieldName.') use ($params) {
    return [\'query\' => \'Image:'.$metaDataFieldName.':\'.$'.$metaDataFieldName.'];
}, (array)$params[\'value\']);',
                    'key' => \sprintf(
                        $translator->trans('pim.mapping.assign_asset_by_meta_field', [], 'admin'),
                        $translator->trans('image', [], 'admin'),
                        $metaDataFieldName
                    )
                ];
            }
        } elseif ($def instanceof Data\Checkbox && $def->getName() === 'published') {
            $templates[] = [
                'value' => 'return true;',
                'key' => $translator->trans('pim.mapping.always_publish', [], 'admin')
            ];
            $templates[] = [
                'value' => 'return false;',
                'key' => $translator->trans('pim.mapping.always_unpublish', [], 'admin')
            ];


            $checks = '';
            $fieldTable = RawItemField::getInstance();
            $existingFields = $fieldTable->find(array('dataportId = ?' => $dataportId), 'priority');

            $existingMappings = Fieldmapping::getInstance()->find(
                [
                    'dataportId = ?' => $dataportId,
                    'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action'],
                    'fieldname NOT LIKE ?' => '__virtual_%'
                ]
            );
            foreach ($existingFields as $rawItemField) {
                foreach($existingMappings as $existingMapping) {
                    if($existingMapping['fieldNo'] == $rawItemField['fieldNo']) {
                        continue 2;
                    }
                }
                $checks .= '  {{ CHECK '.$rawItemField['name'].' }},'.PHP_EOL;
            }

            $templates[] = [
                'value' => '$checks = [
'.$checks.'];

foreach ($checks as $check) {
    if (empty($check)) {
        return false;
    }
}

return $params[\'currentValue\'];',
                'key' => $translator->trans('pim.mapping.checkbox.data_quality', [], 'admin')
            ];
        } elseif ($def instanceof Data\Date || $def instanceof Data\Datetime) {
            $templates[] = [
                'value' => 'return new \DateTime();',
                'key' => $translator->trans('pim.mapping.date.now', [], 'admin')
            ];
        } elseif ($def instanceof Data\User) {
            $templates[] = [
                'value' => 'return $params[\'context\'][\'user\'][\'username\'];',
                'key' => $translator->trans('pim.mapping.user.current_user', [], 'admin')
            ];
        } elseif ($def instanceof Data\Checkbox) {
            $templates[] = [
                'value' => 'return true;',
                'key' => $translator->trans('pim.mapping.always_enable', [], 'admin')
            ];
            $templates[] = [
                'value' => 'return false;',
                'key' => $translator->trans('pim.mapping.always_disable', [], 'admin')
            ];
        } elseif ($def instanceof Data\Select && $def->getName() === 'type') {
            $templates[] = [
                'value' => '// see https://docs.opendxp.io/docs/core-framework/Objects/Object_Classes/Class_Settings/Variants
return \'variant\';',
                'key' => $translator->trans('pim.mapping.variant', [], 'admin')
            ];
        } elseif ($def instanceof Data\Link) {
            $templates[] = [
                'value' => 'return [
  \'path\' => $params[\'value\'],
  \'text\' => \'\', 
  \'target\' => \'_blank\',
];',
                'key' => $translator->trans('pim.mapping.assign_link_path', [], 'admin')
            ];
        } elseif ($def instanceof Data\Fieldcollections) {
            foreach ((array)$def->getAllowedTypes() as $allowedType) {
                $fieldCollectionDefinition = Definition::getByKey($allowedType);
                if ($fieldCollectionDefinition instanceof Definition) {
                    $template = 'return [
    \''.$allowedType.'\' => [
        [';
                    foreach ($fieldCollectionDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if($fieldDefinition instanceof Localizedfields) {
                            foreach($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                                foreach (Tool::getValidLanguages() as $language) {
                                    $template .= '
            \''.$localizedFieldDefinition->getName().'#'.$language.'\' => \'\',';
                                }
                            }
                        } else {
                            $template .= '
            \''.$fieldDefinition->getName().'\' => \'\',';
                        }
                    }
                    $template .= '
        ]
    ]
];';

                    $templates[] = [
                        'value' => $template,
                        'key' => sprintf($translator->trans('pim.mapping.assign_fieldcollection', [], 'admin'), $this->translator->trans($fieldCollectionDefinition->getTitle() ?: $allowedType, [], 'admin'))
                    ];
                }
            }

            if (isset($sourceConfig['sourceClass'], $targetConfig['itemClass']) && $sourceConfig['sourceClass'] == $targetConfig['itemClass']) {
                $templates[] = [
                    'value' => '// please enable "Truncate before import" if you are translating your current field collection data, otherwise you will end up with duplicates
$sourceLanguage = \''.Tool::getDefaultLanguage().'\';
$targetLanguages = [\''.implode('\',\'', Tool::getValidLanguages()).'\'];

$fieldCollectionValues = $params[\'value\'];
foreach($fieldCollectionValues as &$fieldCollectionItems) {
    foreach($fieldCollectionItems as &$fieldCollectionItem) {
        foreach($fieldCollectionItem as $fieldName => &$fieldValue) {
            $fieldNameParts = explode(\'#\', $fieldName);
            $sourceLanguageValue = $fieldCollectionItem[$fieldNameParts[0].\'#\'.$sourceLanguage] ?? null;
            if(!empty($fieldNameParts[1]) && in_array($fieldNameParts[1], $targetLanguages) && is_scalar($sourceLanguageValue)) {
                $fieldValue = \Sylphen\DataBridgeBundle\lib\Pim\Item\Importer::translate($sourceLanguageValue, $fieldNameParts[1], $sourceLanguage);
            }
        }
    }
}

return $fieldCollectionValues;',
                    'key' => $translator->trans('pim.mapping.translate_fieldcollection', [], 'admin')
                ];
            }
        } elseif ($def instanceof Data\QuantityValue) {
            foreach ((array)$def->getValidUnits() as $unitId) {
                $unit = Unit::getById($unitId);

                if ($unit instanceof Unit) {
                    $templates[] = [
                        'value' => 'return [$params[\'value\'], \''.$unit->getAbbreviation().'\'];',
                        'key' => sprintf($translator->trans('pim.mapping.assign_quantity_value', [], 'admin'), $translator->trans($unit->getLongname() ?? $unit->getId(), [], 'admin').' ('.$unit->getAbbreviation().')')
                    ];
                }
            }
        } elseif($def->getName() === 'path') {
            $itemMold = $this->itemMoldBuilder->getItemMold($dataportId);

            if($itemMold instanceof Concrete) {
                $listing = new ClassDefinition\Listing();
                foreach ($listing->getClasses() as $allowedClass) {
                    if ($allowedClass instanceof ClassDefinition) {
                        $hasTemplate = false;
                        $fields = $allowedClass->getFieldDefinitions();
                        foreach ($fields as $field) {
                            $fieldCandidates = [];
                            if ($field instanceof Data\Localizedfields) {
                                foreach ($field->getFieldDefinitions() as $fieldCandidate) {
                                    foreach (Tool::getValidLanguages() as $languageCode) {
                                        $fieldCandidateClone = clone $fieldCandidate;
                                        $fieldCandidateClone->setName($fieldCandidate->getName().'#'.$languageCode);
                                        $fieldCandidates[] = $fieldCandidateClone;
                                    }
                                }
                            } else {
                                $fieldCandidates = [$field];
                            }

                            foreach ($fieldCandidates as $fieldCandidate) {
                                if ($fieldCandidate->getUnique() || $fieldCandidate->getIndex()) {
                                    $hasTemplate = true;
                                    if($allowedClass->getId() != $itemMold->getClassId()) {
                                        $template = 'if($params[\'value\']) {
    return \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$params[\'value\'];
} else {
    return \'- '.sprintf($this->translator->trans('pim.mapping.assign_field_by_empty', [], 'admin'), $allowedClass->getName()).' -\';
}';
                                    } else {
                                        $template = 'if($params[\'value\']) {
    return \''.$allowedClass->getName().':'.$fieldCandidate->getName().':\'.$params[\'value\'];
}';
                                    }
                                    $templates[] = [
                                        'value' => $template,
                                        'key' => \sprintf(
                                            $translator->trans('pim.mapping.assign_field_by', [], 'admin'),
                                            $allowedClass->getName(),
                                            $fieldCandidate->getName()
                                        )
                                    ];
                                }
                            }
                        }

                        $exampleFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? LIMIT 1', [$allowedClass->getId()]);
                        $pathPrefix = $exampleFolder ?: '';
                        if ($pathPrefix) {
                            $otherFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? AND '.Helper::prefixObjectSystemColumn('path').' != ? LIMIT 1', [$allowedClass->getId(), $pathPrefix]);
                            if ($otherFolder) {
                                $pathPrefix = '';
                            }
                        }

                        $template = 'return \''.$allowedClass->getName().':path:'.$pathPrefix.'\'.$params[\'value\'];';

                        $templates[] = [
                            'value' => $template,
                            'key' => \sprintf($translator->trans('pim.mapping.assign_field_by', [], 'admin'), $allowedClass->getName(), $translator->trans('path', [], 'admin')).(($hasTemplate === false) ? ' ('.\sprintf(
                                        $translator->trans('pim.mapping.add_index', [], 'admin'),
                                        $allowedClass->getName()
                                    ).')' : '')
                        ];
                    }
                }
            } else {
                $templates[] = [
                    'value' => '$return = [];
                    
$items = is_string($params[\'value\']) ? array_filter(preg_split(\'/[,|;\n]\s*/\', $params[\'value\'])) : (array)$params[\'value\'];
foreach($items as $item) {
  // $item can be a path to a file or a URL
  $return[] = [
    \'url\' => $item,'.(($sourceConfig['assetSource'] ?? null) ? ' // if this is a relative file path the file is searched in '.$sourceConfig['assetSource'] : '').'
    //\'filename\' => $params[\'rawItemData\'][\'name\'][\'value\'] // optional, if omitted the file name of the loaded file gets used
  ];
}

return $return;',
                    'key' => \sprintf(
                        $translator->trans('pim.mapping.assign_asset_by_url', [], 'admin'),
                        $translator->trans('Asset', [], 'admin')
                    )
                ];
            }
        } elseif ($def instanceof Input) {
            if(strtolower($def->getName()) === 'stream') {
                $templates[] = [
                    'value' => '$document = \OpenDxp\Model\Document::getById({{ DOCUMENT ID }});

$html = \OpenDxp\Model\Document\Service::render($document, {{ CONTROLLER PARAMETERS }});
$html = \OpenDxp\Helper\Mail::setAbsolutePaths($html, $document, \Sylphen\DataBridgeBundle\lib\Pim\Helper::getHostUrl());
$web2PrintProcessor = \OpenDxp\Bundle\WebToPrintBundle\Processor::getInstance();

$web2PrintSettings = [
    \'landscape\' => false,
    \'printBackground\' => true
];
return $web2PrintProcessor->getPdfFromString($html, $web2PrintSettings);',
                    'key' => $translator->trans('pim.mapping.generate_pdf_from_document', [], 'admin')
                ];

                $templates[] = [
                    'value' => '$html = \OpenDxp\Helper\Mail::setAbsolutePaths({{ DOCUMENT HTML }}, null, \Sylphen\DataBridgeBundle\lib\Pim\Helper::getHostUrl());
$web2PrintProcessor = \OpenDxp\Bundle\WebToPrintBundle\Processor::getInstance();
$web2PrintSettings = [
    \'landscape\' => false,
    \'printBackground\' => true,
];
return $web2PrintProcessor->getPdfFromString($html, $web2PrintSettings);',
                    'key' => $translator->trans('pim.mapping.generate_pdf_from_html', [], 'admin')
                ];

                $templates[] = [
                    'value' => '$url = {{ QR Code Text }};

$qrCode = new \Endroid\QrCode\QrCode($url ?? \'\');

$writer = new \Endroid\QrCode\Writer\SvgWriter();
if(method_exists($writer, \'write\')) {
    // endroid/qr-code version 4
    return $writer->write($qrCode)->getString();
} else {
    // endroid/qr-code version 3
    $qrCode->setWriter($writer);
    return $qrCode->writeString();
}',
                    'key' => $translator->trans('pim.mapping.asset_generate_qrcode', [], 'admin')
                ];
            } else {
                $templates[] = [
                    'value' => 'if(empty($params[\'currentObjectData\'][\'id\'])) {
    return $params[\'value\'];
}

return $params[\'currentValue\'];',
                    'key' => $translator->trans('pim.mapping.import_only_for_new_objects', [], 'admin')
                ];

                $templates[] = [
                    'value' => '$rawDataField = \'otherField\';

return $params[\'rawItemData\'][$rawDataField][\'value\'].\' \'.$params[\'value\'];',
                    'key' => $translator->trans('pim.mapping.prepend_input', [], 'admin')
                ];

                $templates[] = [
                    'value' => '$rawDataField = \'otherField\';

return $params[\'value\'].\' \'.$params[\'rawItemData\'][$rawDataField][\'value\'];',
                    'key' => $translator->trans('pim.mapping.append_input', [], 'admin')
                ];

                $templates[] = [
                    'value' => '$urlBase = {{ URL PARTS'.(!empty($mapping['locale']) ? ' '.$mapping['locale'] : '').' }} ?: $params[\'value\'];
if(is_array($urlBase)) {
    $urlBase = implode(\' \', $urlBase);
}

$url = trim($urlBase);
$url = preg_replace(\'/\//\', \'-\', $url);

$words = preg_split(\'/[\s,.]+/\', $url);

$stopWordLibrary = new \voku\helper\StopWords();
try {
    $stopWords = $stopWordLibrary->getStopWordsFromLanguage(substr($params[\'locale\'] ?? \OpenDxp\Tool::getDefaultLanguage(), 0, 2));
} catch(\Exception $e) {
    $stopWords = [];
}

foreach ($words as $index => $word) {
    if (in_array($word, $stopWords)) {
        unset($words[$index]);
    }
}

$url = implode(\'-\', $words);
$url = \Sylphen\DataBridgeBundle\lib\Pim\Helper::toASCII($url, substr($params[\'locale\'] ?? \OpenDxp\Tool::getDefaultLanguage(), 0, 2));

// remove everything which is not a number, letter or - / . _
$url = preg_replace(\'/[^a-zA-Z0-9\-\/\.\_]/u\', \'\', $url);
// remove double --
$url = preg_replace(\'/(-){2,}/\', \'-\', $url);
// remove - at the end
$url = preg_replace(\'/-$/\', \'\', $url);

$url = strtolower($url);

if (empty($url)) {
    return \'\';
}

if ({{ PREFIX LOCALE FOLDER }} && !empty($params[\'locale\'])) {
    $url = $params[\'locale\'].\'/\'.$url;
}

if(!isset($params[\'transfer\']->usedURLs)) {
    $params[\'transfer\']->usedURLs = [];
}

$originalUrl = $url;
$counter = 0;
do {
    if(isset($params[\'currentObjectData\'][\'id\']) && isset($params[\'transfer\']->usedURLs[$params[\'locale\'] ?? \'\'][$params[\'currentObjectData\'][\'id\']])) {
        return $params[\'transfer\']->usedURLs[$params[\'locale\'] ?? \'\'][$params[\'currentObjectData\'][\'id\']];
    }
    
    $url = $originalUrl.(($counter === 0) ? \'\' : $counter);
    $counter++;

    $urlAlreadyExists = in_array($url, $params[\'transfer\']->usedURLs, true);

    if(!$urlAlreadyExists) {
        $objectBuilder = OpenDxp::getContainer()->get(Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder::class);
        /** @var Listing $list */
        $list = $objectBuilder->getItemMoldByClassname($params[\'currentObjectData\'][\'className\'])->getList([
            \'unpublished\' => true,
            \'objectTypes\' => [
                \OpenDxp\Model\DataObject\AbstractObject::OBJECT_TYPE_OBJECT,
                \OpenDxp\Model\DataObject\AbstractObject::OBJECT_TYPE_VARIANT,
            ],]);
        if (!empty($params[\'locale\'])) {
            $list->setLocale($params[\'locale\']);
        }
        $list->addConditionParam(\''.Helper::prefixObjectSystemColumn('id').' != ?\', $params[\'currentObjectData\'][\'id\']);
        $list->addConditionParam(\'`\'.$params[\'field\'].\'` = ?\', $url);
        $list->setLimit(1);
        
        if(count($list->loadIdList()) > 0) {
           $urlAlreadyExists = true;
        }
    }
} while ($urlAlreadyExists);

$params[\'transfer\']->usedURLs[$params[\'locale\'] ?? \'\'][$params[\'currentObjectData\'][\'id\'] ?? count($params[\'transfer\']->usedURLs[$params[\'locale\'] ?? \'\'] ?? [])] = $url;

return $url;',
                    'key' => $translator->trans('pim.mapping.generate_url', [], 'admin')
                ];

                $code = $this->getDeeplTranslationTemplate($dataportId);
                $templates[] = [
                    'value' => $code,
                    'key' => $translator->trans('pim.mapping.deepl_translation', [], 'admin')
                ];
            }
        } elseif ($def instanceof Data\Table && $def->getColumnConfig()) {
            $code = '$data = [];
$data = (array)$params[\'currentValue\']; // remove this line if you do not want to keep current data

$data[] = [';
            foreach($def->getColumnConfig() as $columnConfig) {
                $code .= '
  \''.$columnConfig['key'].'\' => \'\',';
            }
            $code .= '
];

return $data;';
            $templates[] = [
                'value' => $code,
                'key' => $translator->trans('pim.mapping.table', [], 'admin')
            ];
        }

        $event = new CallbackFunctionTemplateEvent($dataport, $def);
        \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($event, $event::EVENT_NAME);

        return array_merge($templates, $event->getTemplates());
    }

    private function getDeeplTranslationTemplate($dataportId): string {
        $code = '// see https://developers.deepl.com/docs/api-reference/translate#request-body-descriptions to get a list of all supported translation parameters
return [
  \'text\' => $params[\'value\'],
  \'context\' => ';
        $contextFields = [];
        $itemMold = $this->itemMoldBuilder->getItemMold($dataportId);
        if($itemMold instanceof Concrete) {
            $contextFieldCandidateFieldDefinitions = $itemMold->getClass()->getFieldDefinitions();
            $contextFieldCandidateLocalizedFieldDefinitions = $itemMold->getClass()->getFieldDefinition('localizedfields');
            if($contextFieldCandidateLocalizedFieldDefinitions instanceof Localizedfields) {
                $contextFieldCandidateFieldDefinitions = array_merge($contextFieldCandidateFieldDefinitions, $contextFieldCandidateLocalizedFieldDefinitions->getFieldDefinitions());
            }
            foreach($contextFieldCandidateFieldDefinitions as $contextFieldCandidateFieldDefinition) {
                if ($contextFieldCandidateFieldDefinition instanceof Input || $contextFieldCandidateFieldDefinition instanceof Data\Textarea || $contextFieldCandidateFieldDefinition instanceof Data\Wysiwyg) {
                    foreach(['description', 'name', 'beschreibung', 'bezeichnung'] as $candidateFieldName) {
                        if(strpos($contextFieldCandidateFieldDefinition->getName(), $candidateFieldName) !== false) {
                            $contextFields[] = $contextFieldCandidateFieldDefinition->getName();
                        }
                    }
                }
            }
        }

        if(count($contextFields) === 0) {
            $contextFields[] = 'key';
        }

        $code .= implode('.\' \'.', array_map(function($fieldName) {
            return '$params[\'currentObjectData\'][\''.$fieldName.'\']';
        }, $contextFields));
        $code .= ', // provide some information about the object which the data to be translated belongs to\'
  \'formality\' => \'default\' // alternative: \'prefer_more\' (more formal), \'prefer_less\' (more informal)
];';

        return $code;
    }

    public static function getFieldKey($mapping)
    {
        return
            (!empty($mapping['brickName']) ? $mapping['brickName'].'/' : '').
            (!empty($mapping['blockName']) ? $mapping['blockName'].'/' : '').
            $mapping['fieldName'].
            (!empty($mapping['locale']) ? '#'.$mapping['locale'] : '');
    }

    /**
     * Clone element without loading all lazy-loaded fields (which Pimcore's Element\Service::cloneMe() does)
     * @param AbstractModel $element
     */
    public static function cloneElement($element)
    {
        if ($element instanceof QuantityValue) {
            return new QuantityValue($element->getValue(), $element->getUnit());
        }

        $deepCopy = new DeepCopy();
        $deepCopy->skipUncloneable(true);

        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('dao'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('resource'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('writeResource'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('dependencies'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('_owner'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('class'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_class'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('layoutDefinitions'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('definition'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('context'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('siblings'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_siblings'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('children'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_children'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('parent'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_parent'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('versions'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_versions'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('properties'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyNameMatcher('o_properties'));

        // PropertyTypeMatcher last as they are harder to compute (needs Reflection)
        $deepCopy->addFilter(new KeepFilter(), new PropertyTypeMatcher('Doctrine\Common\Collections\Collection'));
        $deepCopy->addFilter(new KeepFilter(), new PropertyTypeMatcher(ClassDefinition::class));
        $deepCopy->addFilter(new KeepFilter(), new PropertyTypeMatcher(QuantityValue::class));
        $deepCopy->addFilter(new KeepFilter(), new PropertyTypeMatcher(Data::class));

        return $deepCopy->copy($element);
    }

    /**
     * @return FilesystemOperator
     */
    public static function getAssetStorage()
    {
        if (self::$assetStorage === null) {
            if (defined('OPENDXP_ASSET_DIRECTORY')) {
                $adapter = new LocalFilesystemAdapter(OPENDXP_ASSET_DIRECTORY);
                self::$assetStorage = new Filesystem($adapter);
            } else {
                $storage = OpenDxp::getContainer()->get(Tool\Storage::class);
                self::$assetStorage = $storage->getStorage('asset');
            }
        }

        return self::$assetStorage;
    }

    /**
     * @return FilesystemOperator
     */
    public static function getThumbnailStorage()
    {
        if (self::$thumbnailStorage === null) {
            if (defined('OPENDXP_TEMPORARY_DIRECTORY')) {
                $adapter = new LocalFilesystemAdapter(OPENDXP_TEMPORARY_DIRECTORY.'/image-thumbnails');
                self::$thumbnailStorage = new Filesystem($adapter);
            } else {
                $storage = OpenDxp::getContainer()->get(Tool\Storage::class);
                self::$thumbnailStorage = $storage->getStorage('thumbnail');
            }
        }

        return self::$thumbnailStorage;
    }

    /**
     * @return FilesystemOperator
     */
    public static function getApplicationLogStorage()
    {
        if (self::$applicationloggerStorage === null) {
            try {
                $storage = OpenDxp::getContainer()->get(Tool\Storage::class);
                self::$applicationloggerStorage = $storage->getStorage('application_log');
            } catch(\Exception $e) {
                if (defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY')) {
                    $adapter = new LocalFilesystemAdapter(OPENDXP_LOG_FILEOBJECT_DIRECTORY);
                    self::$applicationloggerStorage = new Filesystem($adapter);
                } else {
                    $adapter = new LocalFilesystemAdapter(OPENDXP_PRIVATE_VAR.'/application-logger');
                    self::$applicationloggerStorage = new Filesystem($adapter);
                }
            }
        }

        return self::$applicationloggerStorage;
    }

    /**
     * @return bool
     */
    public static function isRemoteStorageEnabled(): bool {
        if (!isset(self::$applicationloggerStorage)) {
            return false;
        }

        $reflection = new \ReflectionClass(self::$applicationloggerStorage);

        if (!$reflection->hasProperty('adapter')) {
            return false;
        }

        $property = $reflection->getProperty('adapter');
        $property->setAccessible(true);
        $adapter = $property->getValue(self::$applicationloggerStorage);

        return !$adapter instanceof LocalFilesystemAdapter;
    }

    /**
     * @return FilesystemOperator
     */
    public static function getTempStorage()
    {
        if (self::$tempStorage === null) {
            if (defined('OPENDXP_SYSTEM_TEMP_DIRECTORY')) {
                $adapter = new LocalFilesystemAdapter(OPENDXP_SYSTEM_TEMP_DIRECTORY);
                self::$tempStorage = new Filesystem($adapter);
            } else {
                $storage = OpenDxp::getContainer()->get(Tool\Storage::class);
                self::$tempStorage = $storage->getStorage('temp');
            }
        }

        return self::$tempStorage;
    }

    public static function getTemporaryAssetFile($path)
    {
        if ($path instanceof Asset) {
            try {
                $stream = $path->getStream();
            } catch(\Throwable $e) {
                // in old Pimcore versions the stream can be already closed and thus is unusable
                $path->setStream(null);
                $stream = $path->getStream();
            }

            $tempFilePath = self::getTemporaryFileFromStream($stream);
            if ($path->getId() && is_resource($stream)) {
                @fclose($stream);
            }
            return $tempFilePath;
        }

        $stream = Helper::getAssetStorage()->readStream($path);
        $tempFilePath = self::getTemporaryFileFromStream($stream);
        @fclose($stream);
        return $tempFilePath;
    }

    public static function getLocalAssetFile($path)
    {
        if ($path instanceof Asset) {
            try {
                $stream = $path->getStream();
            } catch (\Throwable $e) {
                // in old Pimcore versions the stream can be already closed and thus is unusable
                $path->setStream(null);
                $stream = $path->getStream();
            }
            $localFilePath = self::getLocalFileFromStream($stream);
            return $localFilePath;
        }

        return self::getLocalFileFromStream(Helper::getAssetStorage()->readStream($path));
    }

    public static function getLocalThumbnailFile($path)
    {
        if ($path instanceof Asset) {
            $path = $path->getRealFullPath();
        }
        return self::getLocalFileFromStream(Helper::getThumbnailStorage()->readStream($path));
    }

    public static function getTemporaryFileFromStream($stream, bool $keep = false): string
    {
        return self::getTemporaryFileFromStreamTrait($stream, $keep);
    }

    public static function json_decode($json) {
        $data = json_decode($json, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            return $data;
        }

        $regex = <<<'REGEX'
~
"[^"\\]*(?:\\.|[^"\\]*)*"
(*SKIP)(*F)
| '([^'\\]*(?:\\.|[^'\\]*)*)'
~x
REGEX;

        $json = preg_replace_callback($regex, function ($matches) {
            return '"'.preg_replace('~\\\\.(*SKIP)(*F)|"~', '\\"', $matches[1]).'"';
        }, $json);

        $data = json_decode($json, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            return $data;
        }

        $json = preg_replace('/"(\w+)\":(,|\})/', '"\1":""\2', $json);
        $data = json_decode($json, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            return $data;
        }

        $json = preg_replace('/(\w+):/i', '"\1":', $json);
        $data = json_decode($json, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            return $data;
        }

        throw new JsonException('Could not parse JSON');
    }

    /**
     * @return Request
     */
    public static function getRequest() {
        if(self::$request === null) {
            $requestStack = \OpenDxp::getContainer()->get('request_stack');
            self::$request = method_exists($requestStack, 'getMainRequest') ? $requestStack->getMainRequest() : $requestStack->getMasterRequest();
            if(!self::$request instanceof Request) {
                self::$request = new Request([], [], [], [], [], ['HTTPS' => 'on', 'SERVER_PORT' => 443, 'REQUEST_TIME_FLOAT' => time()]);
                self::$request->setLocale(\OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale() ?? Tool::getDefaultLanguage());
                $requestStack->push(self::$request);
            } else {
                self::$request->setLocale(\OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale() ?? Tool::getDefaultLanguage());
            }

            self::$request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, 'webservice');
            self::$request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);
            self::$request->attributes->set('transfer', new \stdClass());

            $hostUrl = self::getHostUrl();
            if($hostUrl) {
                $context = \OpenDxp::getContainer()->get('router')->getContext();
                $context->setHost(parse_url($hostUrl, PHP_URL_HOST));
                $context->setScheme(parse_url($hostUrl, PHP_URL_SCHEME));
            }
        }

        return self::$request;
    }

    /**
     * @return User
     */
    public static function getUser() {
        if(self::$user === null) {
            self::$user = Tool\Admin::getCurrentUser();
            if(!self::$user instanceof User) {
                self::$user = User::getById(0);
            }

            if (!self::$user instanceof User) {
                self::$user = new User();
                self::$user->setId(0);
                self::$user->setUsername('system');
            }
        }

        return self::$user;
    }

    public static function stringToColorCode($str) {
        return '#'.substr(md5($str), 0, 6);
    }

    public static function getContrastColor($hexColor)
    {
        // hexColor RGB
        $R1 = hexdec(substr($hexColor, 1, 2));
        $G1 = hexdec(substr($hexColor, 3, 2));
        $B1 = hexdec(substr($hexColor, 5, 2));

        // Black RGB
        $blackColor = "#000000";
        $R2BlackColor = hexdec(substr($blackColor, 1, 2));
        $G2BlackColor = hexdec(substr($blackColor, 3, 2));
        $B2BlackColor = hexdec(substr($blackColor, 5, 2));

        // Calc contrast ratio
        $L1 = 0.2126 * pow($R1 / 255, 2.2) +
            0.7152 * pow($G1 / 255, 2.2) +
            0.0722 * pow($B1 / 255, 2.2);

        $L2 = 0.2126 * pow($R2BlackColor / 255, 2.2) +
            0.7152 * pow($G2BlackColor / 255, 2.2) +
            0.0722 * pow($B2BlackColor / 255, 2.2);

        $contrastRatio = 0;
        if ($L1 > $L2) {
            $contrastRatio = (int)(($L1 + 0.05) / ($L2 + 0.05));
        } else {
            $contrastRatio = (int)(($L2 + 0.05) / ($L1 + 0.05));
        }

        // If contrast is more than 5, return black color
        if ($contrastRatio > 5) {
            return '#000000';
        } else {
            // if not, return white color.
            return '#FFFFFF';
        }
    }

    public static function getPackageVersion($packageName) {
        if (self::$dataBridgeVersion === null) {
            if (class_exists(InstalledVersions::class)) {
                self::$dataBridgeVersion = InstalledVersions::getVersion($packageName);
            } else {
                self::$dataBridgeVersion = '0';
            }
        }

        return self::$dataBridgeVersion;
    }

    public static function getOpenDxpVersion(): string
    {
        if (self::$openDxpVersion === null) {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('open-dxp/opendxp')) {
                self::$openDxpVersion = InstalledVersions::getPrettyVersion('open-dxp/opendxp')
                    ?? InstalledVersions::getVersion('open-dxp/opendxp')
                    ?? OpenDxp\Version::getVersion();
            } else {
                self::$openDxpVersion = OpenDxp\Version::getVersion();
            }
        }

        return self::$openDxpVersion;
    }

    /**
     * @deprecated Use getOpenDxpVersion()
     */
    public static function getPimcoreVersion(): string
    {
        return self::getOpenDxpVersion();
    }

    public static function getSystemFields() {
        $fields = Service::getSystemFields();
        $fields[] = Helper::prefixObjectSystemColumn('type');
        $fields[] = Helper::prefixObjectSystemColumn('parentId');
        $fields[] = Helper::prefixObjectSystemColumn('userModification');
        return $fields;
    }

    public static function toASCII($value, $language = null)
    {
        return ASCII::to_ascii($value, $language ?? self::getRequest()->getLocale());
    }

    public static function clearEnvironmentVariables()
    {
        self::$environmentVariables = null;
    }

    public static function getEnvironmentVariables() {
        if(self::$environmentVariables === null) {
            self::$environmentVariables = array_merge($_ENV, getenv());

            $websiteSettings = new OpenDxp\Model\WebsiteSetting\Listing();
            foreach($websiteSettings->load() as $websiteSetting) {
                $key = $websiteSetting->getName();
                if($websiteSetting->getLanguage()) {
                    $key .= '#'.$websiteSetting->getLanguage();
                }
                self::$environmentVariables[$key] = $websiteSetting->getData();
            }

            $secrets = self::getFromCache('symfony-secrets');
            if(empty($secrets)) {
                $secrets = Cli::exec('"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" secrets:list --reveal');
                preg_match_all('/  (\w+) +"(.+)"/', $secrets, $matches, PREG_SET_ORDER);
                $secrets = [];
                if($matches) {
                    foreach ($matches as $secret) {
                        $secrets[$secret[1]] = $secret[2];
                    }
                    self::saveInCache('symfony-secrets', $secrets);
                } else {
                    self::saveInCache('symfony-secrets', 'none');
                }
            } elseif($secrets === 'none') {
                $secrets = [];
            }

            if (self::isBundleInstalled('AwsSecretsBundle')) {
                $container = \OpenDxp::getContainer();
                foreach (self::$environmentVariables as $envKey => $envValue) {
                    try {
                        $awsValue = $container->getParameter(strtolower($envKey));
                        if ($awsValue) {
                            self::$environmentVariables[$envKey] = $awsValue;
                        }
                    } catch (\Exception $e) {}
                }
            }

            self::$environmentVariables = array_merge(self::$environmentVariables, $secrets);
        }

        return self::$environmentVariables;
    }

    public static function parseEmailRecipients($recipients) {
        if(is_string($recipients)) {
            $recipients = preg_split('/,|;/', $recipients);
        } elseif(!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $return = [];
        foreach($recipients as $recipient) {
            if(is_string($recipient) && strpos($recipient, '@') === false) {
                $recipientUser = User::getByName($recipient);
                if($recipientUser instanceof User) {
                    $return[] = ($recipientUser->getName() ?? $recipientUser->getUsername()).' <'.$recipientUser->getEmail().'>';
                } else {
                    $recipientRole = User\Role::getByName($recipient);
                    if ($recipientRole instanceof User\Role) {
                        foreach(self::getUsersWithRole($recipientRole) as $user) {
                            $return[] = ($user->getName() ?? $user->getUsername()).' <'.$user->getEmail().'>';
                        }
                    }
                }

                continue;
            }

            if(!is_array($recipient)) {
                $recipient = [$recipient];
            }

            foreach($recipient as $recipientItem) {
                if ($recipientItem instanceof User) {
                    $return[] = ($recipientItem->getName() ?? $recipientItem->getUsername()).' <'.$recipientItem->getEmail().'>';
                } elseif ($recipientItem instanceof User\Role) {
                    foreach (self::getUsersWithRole($recipientItem) as $user) {
                        $return[] = ($user->getName() ?? $user->getUsername()).' <'.$user->getEmail().'>';
                    }
                } elseif(is_string($recipientItem)) {
                    $return[] = $recipientItem;
                }
            }
        }

        return OpenDxp\Helper\Mail::parseEmailAddressField(implode(';', $return));
    }

    private static function getUsersWithRole(User\Role $role) {
        $userListing = new User\Listing();
        $userListing->addConditionParam('type=\'user\'');
        $users = [];
        foreach ($userListing as $user) {
            if (in_array($role->getId(), $user->getRoles())) {
                $users[] = $user;
            }
        }
        return $users;
    }

    public static function useInheritance($enable) {
        AbstractObject::setGetInheritedValues($enable);
        Localizedfield::setGetFallbackValues($enable);
        if (method_exists(PageSnippet::class, 'setGetInheritedValues')) {
            PageSnippet::setGetInheritedValues($enable);
        }
    }

    public static function getFromCache($key) {
        $cacheEnabled = OpenDxp\Cache::isEnabled();
        if (!$cacheEnabled) {
            OpenDxp\Cache::enable();
        }

        $cacheValue = Cache::load($key);

        if (!$cacheEnabled) {
            OpenDxp\Cache::disable();
        }

        return $cacheValue;
    }

    public static function saveInCache($key, $value, array $tags = []) {
        $cacheEnabled = OpenDxp\Cache::isEnabled();
        if(!$cacheEnabled) {
            OpenDxp\Cache::enable();
        }

        Cache::save($value, $key, $tags, null, 0, true);

        if(!$cacheEnabled) {
            OpenDxp\Cache::disable();
        }
    }

    public static function getFrontendUrl() {
        if (self::$frontendUrl === null) {
            $protocol = self::getRequest()->getScheme() === 'http' ? 'http' : 'https';
            if ($protocol === 'http') {
                foreach (['x-forwarded-proto', 'x-forwarded-scheme'] as $httpHeader) {
                    if (strtolower(self::getRequest()->headers->get($httpHeader, '')) === 'https') {
                        $protocol = 'https';
                    }
                }
            }

            $sites = new OpenDxp\Model\Site\Listing();
            foreach ($sites->load() as $site) {
                if ($site->getMainDomain()) {
                    self::$frontendUrl = $protocol.'://'.$site->getMainDomain();
                    break;
                }
            }

            if (self::$frontendUrl === null) {
                self::$frontendUrl = self::getHostUrl();
            }
        }

        return self::$frontendUrl;
    }

    public static function getHostUrl() {
        if(self::$hostUrl === null) {
            $protocol = self::getRequest()->getScheme() === 'http' ? 'http' : 'https';
            if($protocol === 'http') {
                foreach(['x-forwarded-proto', 'x-forwarded-scheme'] as $httpHeader) {
                    if (strtolower(self::getRequest()->headers->get($httpHeader, '')) === 'https') {
                        $protocol = 'https';
                    }
                }
            }
            if($protocol === 'http') {
                $refererProtocol = parse_url(self::getRequest()->headers->get('referer', ''), PHP_URL_SCHEME);
                if($refererProtocol === 'https') {
                    $protocol = 'https';
                }
            }
            
            $port = '';
            if (!in_array(self::getRequest()->getPort(), [443, 80])) {
                $port = ':'.self::getRequest()->getPort();
            }

            $hostname = self::getRequest()->getHost();
            if ($hostname && $hostname !== 'localhost') {
                self::$hostUrl = $protocol.'://'.$hostname.$port;
                self::saveInCache('OPENDXP_HOSTURL', self::$hostUrl);
            } else {
                self::$hostUrl = self::getFromCache('OPENDXP_HOSTURL');

                if(!self::$hostUrl) {
                    $systemConfig = Helper::getPimcoreSystemConfiguration('general');
                    if (!empty($systemConfig['domain'])) {
                        $hostname = $systemConfig['domain'];
                        self::$hostUrl = $protocol.'://'.$hostname.$port;
                    }
                }
            }
        }

        return self::$hostUrl;
    }

    public static function generateAbsoluteUrl($routeName, array $routeParams = []) {
        $router = \OpenDxp::getContainer()->get('router');
        $context = $router->getContext();

        $hostUrl = self::getHostUrl();
        if ($hostUrl) {
            $context->setHost(parse_url($hostUrl, PHP_URL_HOST));
            $context->setScheme(parse_url($hostUrl, PHP_URL_SCHEME));
        }
        return $router->generate($routeName, $routeParams, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public static function loadVersion(Version $version) {
        if ($version->getSerialized()) {
            // in Version::loadData the runtime cache gets cleared -> restore it afterwards
            $runtimeCacheData = RuntimeCache::getInstance()->getArrayCopy();
            @$version->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
            RuntimeCache::getInstance()->exchangeArray($runtimeCacheData);
        } else {
            @$version->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
        }

        $data = $version->getData();

        $version->setData($data);
    }

    public function clearPreviewItem() {
        $this->previewItem = null;
    }

    public static function getFileExtension(string $fileName): string
    {
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if(!$fileExtension || strlen($fileExtension) > 10) {
            $fileExtension = 'tmp';
        }

        return $fileExtension;
    }

    public static function prefixObjectSystemColumn($column) {
        return $column;
    }

    public static function getDateFormatter() {
        try {
            return new IntlDateFormatter(
                Tool\Admin::getCurrentUser()->getLanguage(),
                IntlDateFormatter::SHORT,
                IntlDateFormatter::MEDIUM,
                new DateTimeZone(self::getRequest()->get('timezone', date_default_timezone_get()))
            );
        } catch (\Throwable $e) {
            try {
                return new IntlDateFormatter(
                    setlocale(LC_TIME, 0) === 'C' ? locale_get_default() : setlocale(LC_TIME, 0),
                    IntlDateFormatter::SHORT,
                    IntlDateFormatter::MEDIUM,
                    new DateTimeZone(self::getRequest()->get('timezone', date_default_timezone_get()))
                );
            } catch (\Throwable $e) {
                try {
                    return new IntlDateFormatter(
                        'en.utf8',
                        IntlDateFormatter::SHORT,
                        IntlDateFormatter::MEDIUM,
                        new DateTimeZone(self::getRequest()->get('timezone', date_default_timezone_get()))
                    );
                } catch(\Throwable $e) {
                    return new IntlDateFormatter(
                        'en.utf8',
                        IntlDateFormatter::SHORT,
                        IntlDateFormatter::MEDIUM,
                        new DateTimeZone(date_default_timezone_get())
                    );
                }
            }
        }
    }

    public static function getPimcoreSystemConfiguration($offset = null) {
        if (method_exists(Config::class, 'getSystemConfiguration')) {
            $config = Config::getSystemConfiguration();
        } else {
            $config = Config::getSystemConfig();
        }

        if($offset) {
            return $config[$offset];
        }

        return $config;
    }

    public static function getInstance() {
        if(self::$instance === null) {
            self::$instance = \OpenDxp::getContainer()->get(self::class);
        }
        return self::$instance;
    }

    private function findLayoutPath($def, $fieldName, &$path = []) {
        if ($def instanceof Data && $def->getName() === $fieldName) {
            array_unshift($path, $def->getName());
            return true;
        }

        if ($def instanceof ClassDefinition\Layout) {
            if ($def->hasChildren()) {
                foreach($def->getChildren() as $child) {
                    if($this->findLayoutPath($child, $fieldName, $path)) {
                        array_unshift($path, $def->getName());
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function isBundleInstalled($bundleName) {
        try {
            $bundle = \OpenDxp::getKernel()->getBundle($bundleName);

            return $bundle instanceof BundleInterface;
        } catch (\Throwable $e) {
        }

        return false;
    }

    public static function getMemoryLimit()
    {
        if(self::$memoryLimit === null) {
            self::$memoryLimit = ini_get('memory_limit');
            if (preg_match('/^(\d+)(.)$/', self::$memoryLimit, $matches)) {
                $factor = $matches[2] ?? '';
                $factor = strtolower(substr($factor, 0, 1));
                if ($factor === 'g') {
                    self::$memoryLimit = $matches[1] * 1024 * 1024 * 1024;
                } elseif ($factor === 'm') {
                    self::$memoryLimit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
                } elseif ($factor === 'k') {
                    self::$memoryLimit = $matches[1] * 1024; // nnnK -> nnn KB
                } else {
                    self::$memoryLimit = $matches[1];
                }
            }
        }

        return self::$memoryLimit;
    }

    public static function getTotalMemory() {
        if(self::$totalMemory === 'undefined') {
            self::$totalMemory = null;
            if (@is_readable('/proc/meminfo')) {
                $data = file_get_contents('/proc/meminfo');

                if (preg_match('/^MemTotal:\s+(\d+)\skB$/m', $data, $matches)) {
                    self::$totalMemory = $matches[1] * 1024; // Convert from kB to bytes
                }
            }

            if (self::$totalMemory === null) {
                self::$totalMemory = (int)Cli::exec("free -b | grep Mem | awk '{print $2}'");
            }
        }

        return self::$totalMemory;
    }

    public static function setMemoryLimit(): void
    {
        $totalMemory = self::getTotalMemory();

        if ($totalMemory === null) {
            ini_set('memory_limit', -1);
        } else {
            $currentMemoryLimit = self::getMemoryLimit();

            $memoryLimit = (int)($totalMemory * 0.9);
            if($memoryLimit > $currentMemoryLimit) {
                ini_set('memory_limit', $memoryLimit);
                self::$memoryLimit = $memoryLimit;
            }
        }
    }

    public static function getFileSystem($fileOrUrl) {
        if (strpos($fileOrUrl, 'ftp://') === 0) {
            $urlParts = parse_url($fileOrUrl);
            if ($urlParts === false && preg_match('/^ftp:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                $source = str_replace($match[0], 'ftp://', $fileOrUrl);
                $urlParts = parse_url($source);
                $urlParts['user'] = $match[1];
                $urlParts['pass'] = $match[2];
            }

            $connectionParams = [
                'host' => $urlParts['host'],
                'username' => $urlParts['user'],
                'password' => $urlParts['pass'],
                'port' => $urlParts['port'] ?? 21,
                'ssl' => false,
                'ignorePassiveAddress' => true
            ];
            $fileSystemHash = json_encode($connectionParams);

            if(isset(self::$flysystemCache[$fileSystemHash])) {
                return self::$flysystemCache[$fileSystemHash];
            }

            self::$flysystemCache[$fileSystemHash] = new \League\Flysystem\Filesystem(
                new \League\Flysystem\Ftp\FtpAdapter(
                    \League\Flysystem\Ftp\FtpConnectionOptions::fromArray($connectionParams)
                )
            );

            return self::$flysystemCache[$fileSystemHash];
        }

        if (strpos($fileOrUrl, 'ftps://') === 0) {
            $urlParts = parse_url($fileOrUrl);
            if ($urlParts === false && preg_match('/^ftps:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                $source = str_replace($match[0], 'ftps://', $fileOrUrl);
                $urlParts = parse_url($source);
                $urlParts['user'] = $match[1];
                $urlParts['pass'] = $match[2];
            }

            $connectionParams = [
                'host' => $urlParts['host'],
                'username' => $urlParts['user'],
                'password' => $urlParts['pass'],
                'port' => $urlParts['port'] ?? 21,
                'ssl' => true,
                'ignorePassiveAddress' => true
            ];

            $fileSystemHash = json_encode($connectionParams);

            if (isset(self::$flysystemCache[$fileSystemHash])) {
                return self::$flysystemCache[$fileSystemHash];
            }

            self::$flysystemCache[$fileSystemHash] = new \League\Flysystem\Filesystem(
                new \League\Flysystem\Ftp\FtpAdapter(
                    \League\Flysystem\Ftp\FtpConnectionOptions::fromArray($connectionParams)
                )
            );

            return self::$flysystemCache[$fileSystemHash];
        }

        if (strpos($fileOrUrl, 'sftp://') === 0) {
            $urlParts = parse_url($fileOrUrl);
            if ($urlParts === false && preg_match('/^sftp:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                $source = str_replace($match[0], 'sftp://', $fileOrUrl);
                $urlParts = parse_url($source);
                $urlParts['user'] = $match[1];
                $urlParts['pass'] = $match[2];
            }

            $connectionParams = [
                $urlParts['host'],
                $urlParts['user'],
                $urlParts['pass'],
                null,
                null,
                $urlParts['port'] ?? 22
            ];

            $fileSystemHash = json_encode($connectionParams);

            if (isset(self::$flysystemCache[$fileSystemHash])) {
                return self::$flysystemCache[$fileSystemHash];
            }

            self::$flysystemCache[$fileSystemHash] = new \League\Flysystem\Filesystem(
                new SftpAdapter(
                    new SftpConnectionProvider(...$connectionParams),
                    '/'
                )
            );

            return self::$flysystemCache[$fileSystemHash];
        }

        if (strpos($fileOrUrl, 's3://') === 0) {
            $urlParts = parse_url($fileOrUrl);
            if ($urlParts === false && preg_match('/^sftp:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                $source = str_replace($match[0], 'sftp://', $fileOrUrl);
                $urlParts = parse_url($source);
                $urlParts['user'] = $match[1];
                $urlParts['pass'] = $match[2];
            }
            $pathParts = array_filter(explode('/', $urlParts['path']));
            $bucket = array_shift($pathParts);

            $connectionParams = [
                'region' => $urlParts['host'],
                'credentials' => [
                    'key' => $urlParts['user'],
                    'secret' => $urlParts['pass'],
                ],
                'bucket' => $bucket // not a param for setting up S3Client object but used for hash in case of access to multiple buckets
            ];

            $fileSystemHash = json_encode($connectionParams);

            if (isset(self::$flysystemCache[$fileSystemHash])) {
                return self::$flysystemCache[$fileSystemHash];
            }

            self::$flysystemCache[$fileSystemHash] = new \League\Flysystem\Filesystem(
                new AwsS3V3Adapter(new S3Client($connectionParams), $bucket)
            );

            return self::$flysystemCache[$fileSystemHash];
        }

        throw new Exception('Cannot access '.$fileOrUrl);
    }

    public static function getClassDefinitionById($classId)
    {
        if (!isset(self::$classDefinitions[$classId])) {
            self::$classDefinitions[$classId] = ClassDefinition::getById($classId);
        }

        return self::$classDefinitions[$classId] ?? null;
    }

    public static function getClassDefinitionByName($className)
    {
        if (!array_key_exists('name-'.$className, self::$classDefinitions)) {
            $classDefinition = new ClassDefinition();
            try {
                $classId = $classDefinition->getDao()->getIdByName($className);
                self::$classDefinitions['name-'.$className] = self::getClassDefinitionById($classId);
            } catch(OpenDxp\Model\Exception\NotFoundException $e) {
                self::$classDefinitions['name-'.$className] = null;
            }
        }

        return self::$classDefinitions['name-'.$className] ?? null;
    }

    public static function array_diff_assoc_recursive($array1, $array2)
    {
        $difference = [];

        // 1️⃣ Keys in array1 (added or changed)
        foreach ($array1 as $key => $value) {
            if (!array_key_exists($key, $array2)) {
                // Key missing in array2 → full element added
                $difference[$key] = $value;
                continue;
            }

            // Both exist → compare
            if (is_array($value) && is_array($array2[$key])) {
                if (self::has_recursive_diff($value, $array2[$key])) {
                    // Rule A: parent contains "object"
                    if (isset($value['object'])) {
                        $difference[$key] = $value;
                        continue;
                    }

                    // Rule B: parent has both "value" and "unit"
                    if (array_key_exists('value', $value) && array_key_exists('unit', $value)) {
                        $difference[$key] = $value;
                        continue;
                    }

                    // Otherwise, dive deeper
                    $diff = self::array_diff_assoc_recursive($value, $array2[$key]);
                    if (!empty($diff)) {
                        $difference[$key] = $diff;
                    }
                }
            } else {
                if ($value !== $array2[$key]) {
                    $difference[$key] = $value;
                }
            }
        }

        // 2️⃣ Keys that exist only in array2 → deep-nullify (removed)
        foreach ($array2 as $key => $value) {
            if (!array_key_exists($key, $array1)) {
                $difference[$key] = self::nullify_structure($value);
            }
        }

        return $difference;
    }

    /**
     * Checks recursively if any difference exists between arrays.
     */
    private static function has_recursive_diff($a, $b)
    {
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b)) {
                return true;
            }
            if (is_array($value) && is_array($b[$key])) {
                if (self::has_recursive_diff($value, $b[$key])) {
                    return true;
                }
            } else {
                if ($value !== $b[$key]) {
                    return true;
                }
            }
        }
        // extra keys in $b
        foreach ($b as $key => $value) {
            if (!array_key_exists($key, $a)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively nullifies the structure of an array.
     */
    private static function nullify_structure($value)
    {
        // ✅ Special rule: array with "object" key
        if (is_array($value) && isset($value['object'])) {
            $nullified = $value; // keep all "object" data
            if (isset($nullified['meta']) && is_array($nullified['meta'])) {
                foreach ($nullified['meta'] as $metaKey => $metaVal) {
                    // if scalar → null, if array → recurse
                    if (is_array($metaVal)) {
                        $nullified['meta'][$metaKey] = self::nullify_structure($metaVal);
                    } else {
                        $nullified['meta'][$metaKey] = null;
                    }
                }
            }
            return $nullified;
        }

        // Default behavior: deep nullify everything else
        if (is_array($value)) {
            $nullified = [];
            foreach ($value as $k => $v) {
                $nullified[$k] = self::nullify_structure($v);
            }
            return $nullified;
        }

        return null;
    }

    public static function array_intersect_key_recursive(array $array1, array $array2)
    {
        $array1 = array_intersect_key($array1, $array2);
        foreach ($array1 as $key => &$value) {
            if (is_array($value)) {
                $value = self::array_intersect_key_recursive($value, $array2[$key] ?? []);
            }
        }
        return $array1;
    }

    public static function reindex_by_object_id(array $array): array
    {
        // Check if array is a list of items with 'object.id'
        $isObjectList = true;
        foreach ($array as $item) {
            if (!is_array($item) || (!isset($item['object']['id']) && !isset($item['id']))) {
                $isObjectList = false;
                break;
            }
        }

        // Convert if it’s a valid object list
        if ($isObjectList) {
            $newArray = [];
            foreach ($array as $item) {
                $id = $item['id'] ?? $item['object']['id'];
                $newArray[$id] = self::reindex_by_object_id($item); // recurse into children
            }
            return $newArray;
        }

        // Otherwise, recurse deeper through associative arrays
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::reindex_by_object_id($value);
            }
        }

        return $array;
    }
}