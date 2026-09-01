<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;
use ArrayIterator;
use Sylphen\DataBridgeBundle\Controller\ImportconfigController;
use Sylphen\DataBridgeBundle\EventListener\AddDataportIdListener;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueCalculator;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\Normalizer;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\AdvancedManyToManyObjectRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\AdvancedManyToManyRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\BlockMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\CalculatedValueMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\CheckboxMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ClassificationStoreMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\CompleteObjectMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ColorMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\DateMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ElementKeyMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ElementPathMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ExternalImageMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\FieldcollectionMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\GenericObjectRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\GeopointMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\GetObjectByIdentifierMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ImageAdvancedMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ImageGalleryMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ImageMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\InputFieldMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\LinkMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ManyToManyObjectRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ManyToManyRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ManyToOneRelationMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\MultiselectMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\NumericMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\ObjectbrickMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\QuantityValueMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\SelectMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\StructuredTableMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\TableMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\UrlSlugMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\UserMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper\VideoMapper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Geo\GeoCoordinate;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\InMemoryLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LazyLog;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\RawItemLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Optimizer\SimulatedAnnealing;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ArrayMapIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\TypedArrayMapIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\lib\Pim\TemporaryFileHelperTrait;
use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\OpenAiTextGenerator;
use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\TextGenerator;
use Sylphen\DataBridgeBundle\lib\Pim\Translate\AbstractTranslationProvider;
use Sylphen\DataBridgeBundle\lib\Pim\Translate\TranslationProvider;
use Sylphen\DataBridgeBundle\model\Export;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportIgnoreData;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Carbon\Carbon;
use Composer\InstalledVersions;
use Countable;
use DateTimeInterface;
use DeepCopy\DeepCopy;
use DeepCopy\Exception\CloneException;
use DeepCopy\Filter\Doctrine\DoctrineCollectionFilter;
use DeepCopy\Filter\SetNullFilter;
use DeepCopy\Matcher\PropertyNameMatcher;
use DeepCopy\Matcher\PropertyTypeMatcher;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use ErrorException;
use Exception;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Geocoder\Provider\Nominatim\Nominatim;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Psr7\Uri;
use Http\Discovery\HttpClientDiscovery;
use Iterator;
use InvalidArgumentException;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Locale;
use MJS\TopSort\Implementations\StringSort;
use OutOfBoundsException;
use PDOException;
use PhpUnitConversion\UnitType;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Cache\Runtime;
use OpenDxp\Config;
use OpenDxp\Db;
use OpenDxp\Event\AssetEvents;
use OpenDxp\Event\DataObjectEvents;
use OpenDxp\Event\DocumentEvents;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Event\Model\DocumentEvent;
use OpenDxp\File;
use OpenDxp\Helper\LongRunningHelper;
use OpenDxp\Loader\ImplementationLoader\Exception\UnsupportedException;
use OpenDxp\Logger;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CustomResourcePersistingInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition\Data\LazyLoadingSupportInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\QueryResourcePersistenceAwareInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ResourcePersistenceAwareInterface;
use OpenDxp\Model\DataObject\Classificationstore\GroupConfig;
use OpenDxp\Model\DataObject\Classificationstore\KeyConfig;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\Hotspotimage;
use OpenDxp\Model\DataObject\Data\ImageGallery;
use OpenDxp\Model\DataObject\Data\Link;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\Data\StructuredTable;
use OpenDxp\Model\DataObject\Fieldcollection;
use OpenDxp\Model\DataObject\Folder;
use OpenDxp\Model\DataObject\LazyLoadedFieldsInterface;
use OpenDxp\Model\DataObject\Listing;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Objectbrick;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\QuantityValue\Unit;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\AbstractElement;
use OpenDxp\Model\Element\DeepCopy\UnmarshalMatcher;
use OpenDxp\Model\Element\DirtyIndicatorInterface;
use OpenDxp\Model\Element\Editlock;
use OpenDxp\Model\Element\ElementDescriptor;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Tag;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Model\Property;
use OpenDxp\Model\User;
use OpenDxp\Model\Version;
use OpenDxp\Tool;
use OpenDxp\Tool\Storage;
use OpenDxp\Translation\Translator;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Date;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Datetime;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use RtfHtmlPhp\Html\HtmlFormatter;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Pipeline;
use Rubix\ML\Tokenizers\Word;
use Rubix\ML\Transformers\TfIdfTransformer;
use Rubix\ML\Transformers\WordCountVectorizer;
use SplObjectStorage;
use stdClass;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Workflow;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Traversable;
use Twig\Environment;
use Twig\Lexer;
use Twig\Source;
use Twig\Token;
use TypeError;
use UnderflowException;
use voku\helper\StopWords;
use Wamania\Snowball\StemmerFactory;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\htmlToText;
use function Sylphen\DataBridgeBundle\lib\Pim\Import\mimeType;
use function Sylphen\DataBridgeBundle\lib\Pim\Import\toString;

class Importer implements ImporterInterface, LoggerAwareInterface {
    use LoggerAwareTrait;
    use TemporaryFileHelperTrait;

    public const HASH_PROP_PREFIX = 'importhash_';

    private static $allowDummyDeepCopy = false;

    protected $dataport;
    protected $dataportFields;
    private $targetConfig;

    protected $rawItems;
    protected $fieldMappings;
    private static $mappingDependencies = [];

    protected $assetFolder;

    protected $itemFolder;

    private $idPrefix = '';
    private $force = false;

    /** @var \SplObjectStorage|AbstractElement[] */
    private $itemCache;
    private $listCache = [];
    private $relationCache = [];
    private static $fieldDefinitionCache = [];

    /** @var Serializer */
    private static $serializer;

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    /** @var DataQuerySelectorResolver */
    private $dataQuerySelectorResolver;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var Translator */
    private $translator;

    /** @var TranslationHelper */
    private $translationHelper;

    /** @var Environment */
    private static $twigEnvironment;

    private $writeBuffer;

    private $pruneCacheIds = [];

    /** @var self */
    private static $selfReference;

    /** @var FieldMappingManager */
    private $fieldMappingManager;

    private static $dataBridgeTagId;

    /** @var RawItemLogger|null */
    private $rawItemLogger;

    /** @var bool */
    private $dryRun = false;

    /** @var HtmlSanitizer|null */
    private static $htmlSanitizer;

    /** @var array */
    private static $assetsBeforeSaveChecksum = [];

    /** @var bool */
    private static $useBeforeSaveChecksum = false;

    private $hasIgnoredImportData = false;

    /** @var ImportIgnoreData */
    private $importIgnoreData;

    public function __construct(LoggerInterface $logger, ItemMoldBuilder $itemMoldBuilder, DataQuerySelectorResolver $dataQuerySelectorResolver, TokenStorageInterface $tokenStorage, Translator $translator, TranslationHelper $translationHelper, ImportIgnoreData $importIgnoreData) {
        $this->logger = $logger;
        $this->itemMoldBuilder = $itemMoldBuilder;
        $this->dataQuerySelectorResolver = $dataQuerySelectorResolver;
        $this->tokenStorage = $tokenStorage;
        $this->translator = $translator;
        $this->translationHelper = $translationHelper;
        $this->importIgnoreData = $importIgnoreData;
        $this->itemCache = new \SplObjectStorage();
        self::$selfReference = &$this;

        $this->initializeFieldMappingManager();
    }

    /**
     * @return bool
     */
    public function getForce(): bool
    {
        return $this->force;
    }

    public function setDryRun(bool $value): void {
        $this->dryRun = $value;
        Helper::getRequest()->attributes->set('dry-run', $value ? 1 : 0);
    }

    public function getDryRun(): bool {
        return $this->dryRun;
    }

    public function __clone()
    {
        $this->writeBuffer = [];
        $this->pruneCacheIds = [];
    }

    /**
     * @param bool $force
     */
    public function setForce($force)
    {
        $this->force = (bool)$force;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param int $assetId
     * @param string $checksum
     * @return void
     */
    public static function setBeforeSaveChecksum(int $assetId, string $checksum)
    {
        self::$assetsBeforeSaveChecksum[$assetId] = $checksum;
    }

    /**
     * @param int $assetId
     * @return string|null
     */
    public static function getBeforeSaveChecksum(int $assetId): ?string
    {
        return self::$assetsBeforeSaveChecksum[$assetId] ?? null;
    }

    /**
     * @return void
     */
    public static function useBeforeSaveChecksum()
    {
        self::$useBeforeSaveChecksum = true;
    }

    /**
     * @return void
     */
    public static function useCurrentChecksum()
    {
        self::$useBeforeSaveChecksum = false;
    }

    /**
     * @param array $dataport
     */
    public function setDataport($dataport)
    {
        if($this->dataport === null || $this->dataport['id'] != $dataport['id']) {
            $this->writeBuffer();

            $this->dataport = $dataport;

            $this->fieldMappings = null;

            $this->itemFolder = null;
            $this->assetFolder = null;
            $this->targetConfig = null;
            $this->itemCache = new \SplObjectStorage();
            $this->listCache = [];
            $this->writeBuffer = [];
            $this->pruneCacheIds = [];
            $this->relationCache = [];
            self::$mappingDependencies = [];

            $this->dataportFields = RawItemField::getInstance()->find(['dataportId = ?' => $this->dataport['id']], 'priority');

            $targetconfig = $this->getTargetConfig();
            if (array_key_exists('idPrefix', $targetconfig) && !empty($targetconfig['idPrefix'])) {
                $this->idPrefix = $targetconfig['idPrefix'];
            }

            try {
                if ($this->itemMoldBuilder->getItemMold($dataport['id']) instanceof Export) {
                    Serializer::enableCache();
                } else {
                    Serializer::disableCache();
                }
            } catch (\Throwable $e) {
                Serializer::disableCache();
            }

            if (!empty($targetconfig['skipVersioning'])) {
                Version::disable();
            }

            self::$allowDummyDeepCopy = !($targetconfig['compatibilityMode'] ?? true);

            AddDataportIdListener::setDataportId($dataport['id']);

            $this->initializeFieldMappingManager();
        }
    }

    private function initializeFieldMappingManager() {
        $this->fieldMappingManager = new FieldMappingManager([
            new GetObjectByIdentifierMapper($this, $this->itemMoldBuilder, $this->translator),
            new InputFieldMapper($this, $this->itemMoldBuilder, $this->translator),
            new CheckboxMapper($this, $this->itemMoldBuilder, $this->translator),
            new NumericMapper($this, $this->itemMoldBuilder, $this->translator),
            new UserMapper($this, $this->itemMoldBuilder, $this->translator),
            new SelectMapper($this, $this->itemMoldBuilder, $this->translator),
            new MultiselectMapper($this, $this->itemMoldBuilder, $this->translator),
            new ImageAdvancedMapper($this, $this->itemMoldBuilder, $this->translator),
            new DateMapper($this, $this->itemMoldBuilder, $this->translator),
            new VideoMapper($this, $this->itemMoldBuilder, $this->translator),
            new UrlSlugMapper($this, $this->itemMoldBuilder, $this->translator),
            new StructuredTableMapper($this, $this->itemMoldBuilder, $this->translator),
            new TableMapper($this, $this->itemMoldBuilder, $this->translator),
            new QuantityValueMapper($this, $this->itemMoldBuilder, $this->translator),
            new ObjectbrickMapper($this, $this->itemMoldBuilder, $this->translator),
            new ManyToOneRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new ManyToManyRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new ManyToManyObjectRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new ImageMapper($this, $this->itemMoldBuilder, $this->translator),
            new ImageGalleryMapper($this, $this->itemMoldBuilder, $this->translator),
            new LinkMapper($this, $this->itemMoldBuilder, $this->translator),
            new GeopointMapper($this, $this->itemMoldBuilder, $this->translator),
            new GenericObjectRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new FieldcollectionMapper($this, $this->itemMoldBuilder, $this->translator),
            new ExternalImageMapper($this, $this->itemMoldBuilder, $this->translator),
            new ClassificationStoreMapper($this, $this->itemMoldBuilder, $this->translator),
            new CalculatedValueMapper($this, $this->itemMoldBuilder, $this->translator),
            new BlockMapper($this, $this->itemMoldBuilder, $this->translator),
            new AdvancedManyToManyRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new AdvancedManyToManyObjectRelationMapper($this, $this->itemMoldBuilder, $this->translator),
            new ColorMapper($this, $this->itemMoldBuilder, $this->translator),
            new ElementKeyMapper($this, $this->itemMoldBuilder, $this->translator),
            new ElementPathMapper($this, $this->itemMoldBuilder, $this->translator),
            new CompleteObjectMapper($this, $this->itemMoldBuilder, $this->translator),
        ]);
    }

    /**
     * Importiert alle vorhandenen Rohdatensätze in die dazugehörigen Artikel und Produkte. Nicht vorhandene Artikel
     * und Produkte werden angelegt.
     *
     * @return array|ElementLockedException imported raw data rows
     */
    public function import($dataport, $rawItems, ?stdClass $transfer = null, bool $dryRun = false) {
        if(count($rawItems) === 0) {
            return [];
        }

        $this->setDryRun($dryRun);
        $this->setDataport($dataport);
        $this->rawItems = array_combine(array_column($rawItems, 'id'), $rawItems);

        if(count($this->listCache) > 0) {
            $lastListCacheItems = end($this->listCache);
            $listListCacheItemKey = key($this->listCache);
            $this->listCache = [$listListCacheItemKey => $lastListCacheItems];

            $itemCache = new SplObjectStorage();
            foreach ($this->itemCache as $item) {
                $isInListCache = false;
                foreach($this->listCache as $lastListCacheItem) {
                    if($lastListCacheItem === $item) {
                        $isInListCache = true;
                        break;
                    }
                }

                if(!$isInListCache) {
                    continue;
                }

                $defaultData = [
                    'save' => false,
                    'latestVersionData' => $this->itemCache[$item]['latestVersionData'],
                    'currentObjectData' => null,
                    'rawData' => [],
                    'rawItemData' => [],
                    'virtualFields' => $this->itemCache[$item]['virtualFields'],
                    'tags' => [],
                    'properties' => []
                ];
                if (($this->getTargetConfig()['compatibilityMode'] ?? true) || !$item instanceof Concrete) {
                    $defaultData['latestVersion'] = $this->itemCache[$item]['latestVersion'];
                }

                $itemCache[$item] = $defaultData;
            }

            $this->itemCache = $itemCache;
        } else {
            $this->itemCache = new SplObjectStorage();
        }

        $this->writeBuffer = [];
        $this->relationCache = [];

        $sourceconfig = $this->dataport['sourceconfig'];
        $targetconfig = $this->getTargetConfig();

        $this->hasIgnoredImportData = false;
        try {
            $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);

            if ($itemMold instanceof Concrete) {
                $ignoreClassId = $itemMold->getClassId();
            } else {
                $ignoreClassId = OpenDxp\Model\Element\Service::getElementType($itemMold);
            }

            if($ignoreClassId) {
                $this->hasIgnoredImportData = (bool)$this->importIgnoreData->findOne(['classId = ?' => $ignoreClassId]);
            }
        } catch(Exception $e) {
            $itemMold = null;
        }

        $mappings = $this->getMappings();
        $keyMappings = array_filter($mappings, static function($mapping) {
            return !empty($mapping['keyMapping']);
        });

        if ($itemMold instanceof Concrete) {
            $classDefinition = $itemMold->getClass();
        } else {
            $classDefinition = $itemMold;
        }

        AbstractObject::setHideUnpublished(false);
        Helper::useInheritance(true);

        $rawItemDataRepository = RawItemData::getInstance();

        $allRawItemData = $rawItemDataRepository->find([
            'rawItemId IN ('.rtrim(str_repeat('?,', count($this->rawItems)), ',').')' => array_column($this->rawItems, 'id'),
        ]);

        $rawItemDataRows = [];
        foreach($allRawItemData as $singleRawItemData) {
            $rawItemDataRows[$singleRawItemData['rawItemId']][] = $singleRawItemData;
        }

        if (!$keyMappings && $itemMold !== null && !$itemMold instanceof Export) {
            if($targetconfig['mode'] & ImportconfigController::MODE_EDIT) {
                $this->logger->warning('No key field(s) specified in attribute mapping. Falling back to using all mapped fields as key fields. Please go to attribute mapping and set one or more fields as key fields which can be used to find already existing elements.');
            }

            $keyMappings = array_filter($mappings, static function ($mapping) {
                return strpos($mapping['fieldName'], '__virtual_') !== 0;
            });
        }

        $eventDispatcher = \OpenDxp::getContainer()->get(EventDispatcher::class);

        foreach ($this->rawItems as &$rawItem) {
            RawItemLogger::getInstance()->setRawItemId($rawItem['id']);

            try {
                $this->logger->info('--- Processing next raw data item');

                $event = new GenericEvent($rawItem, [
                    'dataportId' => $this->dataport['id'],
                    'shouldSkipItem' => false,
                ]);
                $eventDispatcher->dispatch($event, 'pim.beforeImport');
                if ($event->getArgument('shouldSkipItem')) {
                    $this->logger->info('Skipping item "' .Uuid::fromBytes($rawItem['id'])->getInteger() . '" after evaluating results from event "pim.beforeImport"');
                    continue;
                }

                $rawItem['data'] = [];
                foreach ($this->dataportFields as $field) {
                    foreach ($rawItemDataRows[$rawItem['id']] ?? [] as $row) {
                        if ($field['fieldNo'] == $row['fieldNo']) {
                            if ($row['value'] === null) {
                                $row['value'] = '';
                            }

                            if (isset($sourceconfig['fields']['field_'.$row['fieldNo']]['multiValues']) && $sourceconfig['fields']['field_'.$row['fieldNo']]['multiValues'] === true) {
                                $row['value'] = unserialize($row['value'], ['allowed_classes' => true]);
                            } elseif (!is_numeric($row['value']) && !preg_match('/^"[^"]+"$/', $row['value'])) {
                                $decodedValue = json_decode($row['value'], true);
                                if (json_last_error() === \JSON_ERROR_NONE) {
                                    $row['value'] = $decodedValue;
                                }
                            }

                            $rawItem['data']['field_'.$row['fieldNo']] = $row;
                            $rawItem['data'][$field['name']] = $row;
                            continue 2;
                        }
                    }

                    // add dummy data if parser cannot find data field
                    $rawItem['data']['field_'.$field['fieldNo']] = [
                        'rawItemId' => $rawItem['id'],
                        'fieldNo' => $field['fieldNo'],
                        'value' => ''
                    ];
                    $rawItem['data'][$field['name']] = $rawItem['data']['field_'.$field['fieldNo']];
                }

                if(empty($rawItem['data']) && $dataport['sourcetype'] === 'object-wizard') {
                    $rawItem['data']['dummy'] = [
                        'rawItemId' => $rawItem['id']
                    ];
                }

                $rawItem['virtualFields'] = [];
                $rawItem['objectIDs'] = [];
                $rawItem['tags'] = [];

                // Load virtual fields which are used in key fields' callback functions
                if($itemMold !== null && !$itemMold instanceof Export && $keyMappings) {
                    $lastKeyMapping = end($keyMappings)['fieldName'];
                    foreach ($mappings as $mapping) {
                        if (strpos($mapping['fieldName'], '__virtual_') === 0) {
                            $value = $rawItem['data']['field_'.$mapping['fieldNo']]['value'] ?? null;

                            $hasStaticCachedValue = false;
                            if ($mapping['calculation'] && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                                $hasStaticCachedValue = CallbackFunction::hasStaticCachedValue($mapping['calculation']);

                                $jsParams = [];
                                if (!$hasStaticCachedValue) {
                                    $user = Helper::getUser();
                                    $jsParams = [
                                        'rawItemData' => $rawItem['data'],
                                        'value' => $value,
                                        'virtualFields' => $rawItem['virtualFields'],
                                        'field' => $mapping['fieldName'],
                                        'logger' => $this->logger,
                                        'request' => Helper::getRequest(),
                                        'transfer' => $transfer ?? new stdClass(),
                                        'translator' => $this->translationHelper,
                                        'context' => [
                                            'dataportId' => $this->dataport['id'],
                                            'dataport' => [
                                                'id' => $this->dataport['id'],
                                                'name' => $this->dataport['name'],
                                            ],
                                            'user' => [
                                                'id' => $user->getId(),
                                                'username' => $user->getUsername()
                                            ]
                                        ],
                                    ];

                                    if (isset($mapping['locale'])) {
                                        $jsParams['locale'] = $mapping['locale'];
                                    }
                                }

                                $value = CallbackFunction::evaluateScript($mapping['calculation'], $targetconfig['javascriptEngine'], $jsParams);

                                $value = $this->map($mapping, $value, null, new Data\CalculatedValue());
                            }

                            $rawItem['virtualFields'][Helper::getFieldKey($mapping)] = $value;

                            if (!$hasStaticCachedValue) {
                                $this->log(null, 'Value for field '.Helper::getFieldKey($mapping).': '.self::getLogOutput($value), 'info');
                            }
                        }

                        if ($mapping['fieldName'] === $lastKeyMapping) {
                            break;
                        }
                    }
                }

                $keyValueDatasets = $this->getKeyDatasets($keyMappings, $rawItem['data'], $transfer, $rawItem['virtualFields']);

                $countKeyValueDataSets = count($keyValueDatasets);
                for($keyValueDatasetIndex = 0; $keyValueDatasetIndex<ceil($countKeyValueDataSets / 100); $keyValueDatasetIndex++) {
                    $keyValueDataset = array_slice($keyValueDatasets, $keyValueDatasetIndex * 100, 100);

                    $memoryLimit = Helper::getMemoryLimit();
                    if ($keyValueDatasetIndex > 0 || ($memoryLimit > 0 && memory_get_usage() > $memoryLimit * 0.8)) {
                        $this->saveItems();

                        $this->itemCache = new \SplObjectStorage();
                        $lastListCacheItems = end($this->listCache);
                        $listListCacheItemKey = key($this->listCache);
                        $this->listCache = [$listListCacheItemKey => $lastListCacheItems];

                        $this->writeBuffer = [];
                        $this->relationCache = [];

                        if (method_exists(OpenDxp::class, 'deleteTemporaryFiles')) {
                            OpenDxp::deleteTemporaryFiles();
                        }
                        PimcoreDbRepository::clearPreparedStatements();
                        OpenDxp::getContainer()->get(LongRunningHelper::class)->cleanUp();
                        Serializer::clearCache();
                    }

                    foreach ($keyValueDataset as $keyValues) {
                        /** @var ElementInterface[] $items */
                        $items = [];

                        $conditions = $this->getKeyConditions($keyValues);

                        try {
                            if ($conditions === null) {
                                $this->handleSkippedItem($itemMold, $rawItem, ($itemMold !== null && !$itemMold instanceof Export) ? 'Skipping item because raw data in at least one key column is null' : null, $transfer);

                                continue;
                            }

                            $cachedItems = $this->getListCache($keyValues);
                            if ($cachedItems !== null) {
                                $items = $cachedItems;
                            } else {
                                /** @var Listing|Asset\Listing|Document\Listing $list */
                                $list = $itemMold::getList([
                                    'unpublished' => true,
                                    'objectTypes' => [
                                        AbstractObject::OBJECT_TYPE_OBJECT,
                                        AbstractObject::OBJECT_TYPE_VARIANT,
                                    ],
                                    'locale' => Tool::getDefaultLanguage(),
                                    'orderKey' => \OpenDxp\Model\Element\Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id' // first edit existing elements so that they can use data from items in source folder which get deleted when path collision happens
                                ]);

                                $listLocale = null;
                                foreach ($keyValues as $keyColumn => $keyValue) {
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

                                foreach ($conditions as $column => $keyValue) {
                                    if (!is_array($keyValue) || !isset($keyValue[0])) {
                                        $keyValue = [$keyValue];
                                    }

                                    $elementType = \OpenDxp\Model\Element\Service::getElementType($itemMold);
                                    foreach ($keyValue as $keyValueItem) {
                                        if(strpos($column, '/') !== false) {
                                            $columnParts = explode('/', $column);
                                            /** @var AbstractData $brick */
                                            $brick = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($columnParts[0]), [$itemMold]);
                                            $fieldDefinition = self::getFieldDefinition($brick, ['fieldName' => $columnParts[1]]);
                                            $list->addObjectBrick($brick->getType());
                                            $column = $columnParts[1];
                                            $tableName = $brick->getType();
                                        } else {
                                            $fieldDefinition = self::getFieldDefinition($classDefinition, $column);
                                            if ($list instanceof Listing) {
                                                $tableName = $list->getDao()->getTableName();
                                            } elseif ($list instanceof Asset\Listing) {
                                                $tableName = 'assets';
                                            } elseif ($classDefinition instanceof Document\Listing) {
                                                $tableName = 'documents';
                                            }
                                        }

                                        if ($keyValueItem instanceof ElementMetadata) {
                                            $keyValueItem = $keyValueItem->getElement();
                                        }

                                        addListCondition:
                                        if ($fieldDefinition instanceof Data\ManyToOneRelation && $keyValueItem instanceof ElementInterface) {
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'__id`=?', $keyValueItem->getId());
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'__type`=?', \OpenDxp\Model\Element\Service::getElementType($keyValueItem));
                                        } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations && $keyValueItem instanceof ElementInterface) {
                                            try {
                                                $list->addConditionParam(
                                                    '`'.$tableName.'`.`'.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').'` IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id = '.$keyValueItem->getId().' AND type = '.Db::get()->quote(
                                                        \OpenDxp\Model\Element\Service::getElementType($keyValueItem)
                                                    ).' AND ownertype = '.Db::get()->quote($elementType).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')'
                                                );
                                            } catch (Throwable $e) {
                                                if ($fieldDefinition instanceof Data\ManyToManyRelation) {
                                                    $list->addConditionParam('`'.$tableName.'`.`'.$column."` LIKE ?", '%,'.\OpenDxp\Model\Element\Service::getElementType($keyValueItem).'|'.$keyValueItem->getId().',%');
                                                } else {
                                                    $list->addConditionParam('`'.$tableName.'`.`'.$column."` LIKE ?", '%,'.$keyValueItem->getId().',%');
                                                }
                                            }
                                        } elseif ($fieldDefinition instanceof Data\Multiselect) {
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column."` LIKE ?", '%,'.$keyValueItem.',%');
                                        } elseif ($fieldDefinition instanceof Data\BooleanSelect) {
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'` = ?', $keyValueItem ? 1 : -1);
                                        } elseif ($fieldDefinition instanceof Data\QuantityValue) {
                                            /** @var QuantityValue $keyValueItem */
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'__value` = ?', $keyValueItem->getValue());
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'__unit` = ?', $keyValueItem->getUnitId());
                                        } elseif($fieldDefinition instanceof Data\Date) {
                                            /** @var DateTimeInterface $keyValueItem */
                                            if ($fieldDefinition->getColumnType() === 'date') {
                                                $list->addConditionParam('`'.$tableName.'`.`'.$column.'` = ?', $keyValueItem->format('Y-m-d'));
                                            } else {
                                                $dateRangeStart = clone $keyValueItem;
                                                $dateRangeEnd = clone $keyValueItem;
                                                $dateRangeStart->setTime(0,0);
                                                $dateRangeEnd->setTime(23,59,59);

                                                $list->addConditionParam('`'.$tableName.'`.`'.$column.'` BETWEEN ? AND ?', [$dateRangeStart->getTimestamp(), $dateRangeEnd->getTimestamp()]);
                                            }
                                        } elseif ($fieldDefinition instanceof Data\Image && $keyValueItem instanceof Asset) {
                                            $list->addConditionParam('`'.$tableName.'`.`'.$column.'` = ?', $keyValueItem->getId());
                                        } elseif (is_scalar($keyValueItem)) {
                                            if (@preg_match($keyValueItem, '') === false) {
                                                if($keyValueItem === '') {
                                                    $list->addConditionParam('IFNULL(`'.$tableName.'`.`'.$column.'`,\'\') = ?', $keyValueItem);
                                                } else {
                                                    $list->addConditionParam('`'.$tableName.'`.`'.$column.'` = ?', $keyValueItem);
                                                }
                                            } elseif(preg_match('/^([\p{L}\p{Nd}\s\/]+)\.[\*\+]\??$/u', substr($keyValueItem, 1, -1), $prefix)) {
                                                $list->addConditionParam('`'.$tableName.'`.`'.$column.'` LIKE ?', $prefix[1].'%');
                                            } else {
                                                $list->addConditionParam('`'.$tableName.'`.`'.$column.'` REGEXP ?', str_replace('/', '\\/', substr($keyValueItem, 1, -1)));
                                            }
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
                                        } elseif($fieldDefinition->getName() === 'Complete Object') {
                                            $objectExistsQuery = 'SELECT 1 
                                            FROM '.Service::getElementType($itemMold).'s 
                                            WHERE '.(Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?';

                                            $objectExistsParams = [$keyValueItem['id']];

                                            if (Service::getElementType($itemMold) === 'object') {
                                                $objectExistsQuery .= ' AND '.Helper::prefixObjectSystemColumn('className').' = ?';
                                                $objectExistsParams[] = $keyValueItem['className'];
                                            }

                                            $objectExists = PimcoreDbRepository::getInstance()->findOneInSql($objectExistsQuery, $objectExistsParams);
                                            if ($objectExists) {
                                                $list->addConditionParam((Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?', $keyValueItem['id']);
                                            } else {
                                                $list->addConditionParam((Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' = ?', $keyValueItem['path']);
                                                $list->addConditionParam('`'.(Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('key') : 'key').'` = ?', $keyValueItem['key']);
                                            }
                                        } else {
                                            $this->logger->alert('Field "'.$column.' (type "'.$fieldDefinition->getName().'") can currently not be used as key field');
                                            $list->addConditionParam("1=0");
                                        }
                                    }
                                }

                                $objectIDs = $list->loadIdList();

                                if (\count($objectIDs) === 0) {
                                    $keyValueParts = [];
                                    foreach ($conditions as $key => $value) {
                                        $keyValueParts[] = $key.'='.self::getLogOutput($value);
                                    }

                                    $this->logger->info('No item found by key fields '.implode(', ', $keyValueParts));
                                } else {
                                    foreach ($objectIDs as $objectID) {
                                        if (!($targetconfig['mode'] & ImportconfigController::MODE_EDIT)) {
                                            $item = $itemMold::getById($objectID);
                                            $this->handleSkippedItem($itemMold, $rawItem, 'Skipped '.OpenDxp\Model\Element\Service::getElementType($item).' #'.$objectID.' '.$item->getRealFullPath().' because this import is not allowed to edit objects');
                                            continue;
                                        }

                                        // Skip item if rawdata has not changed since last import
                                        if (!$this->force) {
                                            $rawDataUnchanged = PimcoreDbRepository::getInstance()->findOneInSql(
                                                'SELECT 1 FROM properties WHERE cid = ? AND ctype = ? AND name = ? AND data = ?',
                                                [$objectID, $this->getItemType(), self::HASH_PROP_PREFIX.$this->dataport['id'], $rawItem['hash']]
                                            );
                                            if ($rawDataUnchanged) {
                                                $keyValueParts = [];
                                                foreach ($conditions as $key => $value) {
                                                    $keyValueParts[] = $key.'='.self::getLogOutput($value);
                                                }

                                                $this->handleSkippedItem($itemMold, $rawItem, 'Skipping '.implode(', ', $keyValueParts).' because object property "'.self::HASH_PROP_PREFIX.$this->dataport['id'].'" of object #'.$objectID.' matches hash of raw data values');

                                                continue;
                                            }
                                        }

                                        if ($this->getTargetConfig()['compatibilityMode'] ?? true) {
                                            $targetClass = get_class($itemMold);
                                            $item = new $targetClass();
                                            $item->getDao()->getById($objectID);
                                        } else {
                                            $item = $itemMold::getById($objectID);

                                            if ($item === null || !$item->getId()) {
                                                try {
                                                    $itemMold->getDao()->getById($objectID);
                                                    if ($itemMold instanceof Concrete) {
                                                        $this->logger->warning('Object #'.$objectID.' is not loadable. It has a different class id in object_query_'.$itemMold->getClassId().' and object table. Please remove the corrupt rows from object_query_'.$itemMold->getClassId().' table.');
                                                    } else {
                                                        $this->logger->warning('Object #'.$objectID.' is not loadable.');
                                                    }
                                                } catch (\Throwable $e) {
                                                    $this->logger->warning('Object #'.$objectID.' is not loadable. '.$e->getMessage());
                                                }

                                                continue;
                                            }
                                        }

                                        $cachedItem = $this->getCachedItem($item);
                                        if (!isset($cachedItem['latestVersionData']['modificationDate'])) {
                                            $cachedItem['latestVersionData']['modificationDate'] = PimcoreDbRepository::getInstance()->findOneInSql(
                                                'SELECT `date` FROM versions WHERE ctype = ? AND cid = ? AND (`date` > ? OR versionCount > ?) ORDER BY `versionCount` DESC LIMIT 1',
                                                [
                                                    \OpenDxp\Model\Element\Service::getElementType($item),
                                                    $item->getId(),
                                                    $item->getModificationDate(),
                                                    $item->getVersionCount()
                                                ]
                                            );

                                            if (!$cachedItem['latestVersionData']['modificationDate']) {
                                                // there is no version newer than currently published one
                                                $cachedItem['latestVersionData']['modificationDate'] = $item->getModificationDate();
                                            } elseif ($cachedItem['latestVersionData']['modificationDate'] == $item->getModificationDate()) {
                                                // later version has same modificationDate but greater version count -> this happens when there was a newer unpublished version and we import to the same import later again -> import for latest version + import for published version have same modificationDate but may have different data
                                                $cachedItem['latestVersionData']['modificationDate']++;
                                            }

                                            if (($this->getTargetConfig()['compatibilityMode'] ?? true) || !$item instanceof Concrete) {
                                                $this->setCachedItem($item, ['latestVersion' => Helper::cloneElement($item)]);
                                            }

                                            $this->setCachedItem($item, ['latestVersionData' => $cachedItem['latestVersionData']]);
                                        }

                                        $this->addToListCache($keyValues, $item);
                                        $items[] = $item;

                                        if ($cachedItem['latestVersionData']['modificationDate'] > $item->getModificationDate()) {
                                            $latestVersion = $this->getLatestVersion($item);

                                            if ($latestVersion !== null) {
                                                /** @var ElementInterface $latestVersionObject */
                                                $latestVersionObject = Helper::cloneElement($latestVersion);
                                                $items[] = $latestVersionObject;
                                                $latestVersionCachedItem = $this->getCachedItem($latestVersionObject);
                                                $latestVersionCachedItem['latestVersionData']['modificationDate'] = time();

                                                if (($this->getTargetConfig()['compatibilityMode'] ?? true) || !$item instanceof Concrete) {
                                                    $this->setCachedItem($latestVersionObject, ['latestVersion' => Helper::cloneElement($latestVersionObject)]);
                                                }
                                                $this->setCachedItem($latestVersionObject, ['latestVersionData' => $latestVersionCachedItem['latestVersionData']]);
                                                $this->addToListCache($keyValues, $latestVersionObject);

                                                $cachedItem = $this->getCachedItem($item);
                                                $cachedItem['latestVersionData']['modificationDate'] = $item->getModificationDate();
                                                if (($this->getTargetConfig()['compatibilityMode'] ?? true) || !$item instanceof Concrete) {
                                                    $cachedItem['latestVersion'] = Helper::cloneElement($item);
                                                    $this->setCachedItem($item, ['latestVersion' => Helper::cloneElement($item)]);
                                                }
                                                $this->setCachedItem($item, ['latestVersionData' => $cachedItem['latestVersionData']]);
                                            }
                                        }
                                    }

                                    // prevent object creation if all found items get skipped
                                    if (count($items) === 0) {
                                        continue;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            if ($conditions !== null) {
                                $keyValueParts = [];
                                foreach ((array)$conditions as $key => $value) {
                                    $keyValueParts[] = $key.'='.$value;
                                }

                                $this->logger->alert('Unable to get item by key attribute '.implode(', ', $keyValueParts).', '.$e);
                            } else {
                                $this->logger->alert('An error happened: '.$e);
                            }

                            continue;
                        }

                        // Neuen Artikel anlegen, wenn kein bestehender gefunden wurde.
                        if (count($items) === 0) {
                            if (($targetconfig['mode'] & ImportconfigController::MODE_CREATE) && !empty($keyValues)) {
                                $initialKeyParts = [];
                                foreach ($keyValues as $keyValue) {
                                    if (is_scalar($keyValue) || (is_object($keyValue) && method_exists($keyValue, '__toString'))) {
                                        $initialKeyParts[] = (string)$keyValue;
                                    }
                                }

                                $item = $this->createObject($itemMold, $this->getIdPrefix().implode('_', $initialKeyParts));

                                $this->addToListCache($keyValues, $item);
                                $this->setCachedItem($item, ['latestVersionData' => ['modificationDate' => 0]]);
                                $items[] = $item;
                            } else {
                                $this->handleSkippedItem($itemMold, $rawItem, 'Skipped raw item #'.Uuid::fromBytes($rawItem['id'])->getInteger().' because this import is not allowed to create objects');
                                continue;
                            }
                        }

                        foreach ($items as $item) {
                            $elementType = Service::getElementType($item);
                            $this->log($item, 'Importing '.(in_array($elementType, ['asset','document'], true) ? $elementType : $item->getClassName()).' #'.($item->getId() ?? '0').' '.$item->getRealFullPath(), 'info');
                            if (method_exists($this->logger, 'setContext')) {
                                $this->logger->setContext(['relatedObject' => $item, 'dataportId' => $this->dataport['id']]);
                            }
                            $event = new GenericEvent(
                                $item, [
                                    'shouldSkipItem' => false,
                                    'dataportId' => $this->dataport['id'],
                                    'rawItem' => $rawItem,
                                    'rawItemData' => $rawItem['data'],
                                    'mappings' => $mappings,
                                    'sourceconfig' => $sourceconfig,
                                    'assetFolder' => function () {
                                        return $this->getAssetFolder();
                                    },
                                    'keyValues' => $keyValues,
                                ]
                            );
                            $eventDispatcher->dispatch($event, 'pim.afterFindItem');

                            if ($event->getArgument('shouldSkipItem')) {
                                $this->handleSkippedItem($item, $rawItem, 'Skipping raw item #'.Uuid::fromBytes($rawItem['id'])->getInteger().' after evaluating results from event "pim.afterFindItem"');
                                continue;
                            }

                            // Abbrechen, falls kein Artikel angelegt werden konnte oder kein befülltes Schlüsselattribut gefunden wurde
                            if (!$item instanceof AbstractModel) {
                                $this->handleSkippedItem($itemMold, $rawItem, 'No item could be created for raw item '.Uuid::fromBytes($rawItem['id'])->getInteger());
                                continue;
                            }

                            if (!$item->getId() || ($targetconfig['mode'] & ImportconfigController::MODE_EDIT)) {
                                if ($item->getId() > 0) {
                                    $editLockId = $this->addEditLock($item->getId(), \OpenDxp\Model\Element\Service::getElementType($item));
                                    $this->setCachedItem($item, ['editLockId' => $editLockId]);
                                }
                                $event = new GenericEvent(
                                    $item, [
                                        'dataportId' => $this->dataport['id'],
                                        'rawItem' => $rawItem,
                                        'rawItemData' => $rawItem['data'],
                                        'mappings' => $mappings,
                                        'sourceconfigFields' => $sourceconfig['fields'],
                                        'assetFolder' => function () {
                                            return $this->getAssetFolder();
                                        },
                                        'keyValues' => $keyValues,
                                    ]
                                );

                                $eventDispatcher->dispatch($event, 'pim.beforeMapData');
                                $rawItem['data'] = $event->getArgument('rawItemData');

                                $changedObjects = $this->mapData($item, $rawItem, $mappings, $keyValues, $transfer);

                                // array_unique compares by using __toString() method of objects
                                $changedObjects = array_unique($changedObjects);

                                foreach ($changedObjects as $changedObject) {
                                    $event = new GenericEvent(
                                        $changedObject, [
                                            'dataportId' => $this->dataport['id'],
                                            'rawItem' => $rawItem,
                                            'rawItemData' => $rawItem['data'],
                                            'mappings' => $mappings,
                                            'sourceconfigFields' => $sourceconfig['fields'],
                                            'assetFolder' => function () {
                                                return $this->getAssetFolder();
                                            },
                                        ]
                                    );

                                    $eventDispatcher->dispatch($event, 'pim.afterMapData');
                                    $rawItem['data'] = $event->getArgument('rawItemData');

                                    // Speichern
                                    $event = new GenericEvent(
                                        $changedObject, [
                                            'dataportId' => $this->dataport['id'],
                                            'rawItem' => $rawItem,
                                            'rawItemData' => $rawItem['data'],
                                            'mappings' => $mappings,
                                            'sourceconfigFields' => $sourceconfig['fields'],
                                            'assetFolder' => function () {
                                                return $this->getAssetFolder();
                                            },
                                            'saveAnyway' => false,
                                        ]
                                    );
                                    $eventDispatcher->dispatch($event, 'pim.beforeSave');

                                    $isModified = null;
                                    $cachedItem = $this->getCachedItem($changedObject);

                                    $fieldsToCheck = null;

                                    if($this->isCompleteObjectImport()) {
                                        $fieldsToCheck = array_keys($rawItem['virtualFields']['Complete Object']);
                                    }

                                    if ((empty($cachedItem['save']) || $targetconfig['optimizeInheritance']) && ($event->getArgument('saveAnyway') || (!($this->getTargetConfig()['compatibilityMode'] ?? true) && $changedObject instanceof Concrete && $this->hasModifiedData($changedObject)) || ((($this->getTargetConfig()['compatibilityMode'] ?? true) || !$changedObject instanceof Concrete) && ($isModified = $this->isModified($changedObject, $cachedItem['latestVersion'], $fieldsToCheck, $fieldsToCheck === null))))) {
                                        if($isModified !== null) {
                                            $this->log($changedObject, 'Reason for saving: '.$isModified, 'info');
                                        }

                                        if ($changedObject->getId() > 0) {
                                            $elementType = \OpenDxp\Model\Element\Service::getElementType($changedObject);

                                            $editLockQuery = 'SELECT 1 FROM edit_lock
                                                WHERE cid = ? AND ctype = ? AND date >= ? AND userId = 0 AND sessionId != ?
                                                LIMIT 1';
                                            $editLockParams = [$changedObject->getId(), $elementType, time() - 3600, getmypid().'-'.$this->dataport['id']];
                                            $lockCheckRetries = 30;

                                            while ($lockCheckRetries && PimcoreDbRepository::getInstance()->findOneInSql($editLockQuery, $editLockParams) !== false) {
                                                $currentlyRunningPimImports = ImportStatus::getInstance()->find(
                                                    [
                                                        'dataport_id = ?' => $dataport['id'],
                                                        'status = ?' => ImportStatus::STATUS_RUNNING,
                                                        'importType & ?' => ImportStatus::TYPE_PIM
                                                    ],
                                                    null,
                                                    2
                                                );

                                                if(count($currentlyRunningPimImports) < 2) {
                                                    break;
                                                }

                                                $this->logger->info(
                                                    'Element #'.$changedObject->getId().' '.$changedObject->getRealFullPath().' is currently being updated by another parallely running import. Will wait up to '.$lockCheckRetries.' seconds'
                                                );
                                                sleep(1);
                                                $lockCheckRetries--;
                                            }

                                            if ($lockCheckRetries === 0) {
                                                $this->logger->notice(
                                                    'Element #'.$changedObject->getId().' '.$changedObject->getRealFullPath().' could not be edited to prevent data loss because a parallely running import is just updating the same object. To prevent data loss, we will try to continue import later ...'
                                                );

                                                $this->writeBuffer();

                                                return new ElementLockedException($changedObject, 'Element #'.$changedObject->getId().' is locked');
                                            }
                                        }

                                        $this->log($changedObject, (in_array($elementType, ['asset', 'document'], true) ? $elementType : $changedObject->getClassName()).' '.$changedObject->getRealFullPath().' queued for saving', 'info');

                                        $this->setCachedItem($changedObject, ['save' => true]);
                                    } elseif (empty($cachedItem['save'])) {
                                        $this->log($changedObject, 'Not saving '.(in_array($elementType, ['asset', 'document'], true) ? $elementType : $changedObject->getClassName()).' #'.$changedObject->getId().' '.$changedObject->getRealFullPath().' because it is unchanged', 'info');
                                    }
                                }
                            } else {
                                $this->handleSkippedItem($item, $rawItem, 'Skipped '.(in_array($elementType, ['asset', 'document'], true) ? $elementType : $item->getClassName()).' #'.$item->getId().' '.$item->getRealFullPath().' because this import is not allowed to edit objects');
                            }
                        }
                    }

                    if($keyValueDatasetIndex > 0) {
                        $this->saveItems();
                    }
                }
            } catch (\Throwable $ex) {
                if($ex instanceof ElementLockedException) {
                    return $ex;
                }
                $this->log($itemMold, 'Unable to process item: '.$ex, 'alert');
            }
        }
        unset($rawItem);

        RawItemLogger::getInstance()->setRawItemId(null);

        $this->saveItems();

        return $this->rawItems;
    }

    public function mapData(AbstractModel $target, &$rawItem, $mappings, $keyValues = [], ?stdClass $transfer = null) {
        if ($target instanceof PageSnippet) {
            $masterDocumentPath = $this->getTargetConfig()['masterDocument'];
            if ($masterDocumentPath) {
                $masterDocument = PageSnippet::getByPath($masterDocumentPath);
                if ($masterDocument instanceof PageSnippet) {
                    if (method_exists($target, 'setModule')) {
                        $target->setModule($masterDocument->getModule());
                    }
                    $target->setController($masterDocument->getController());

                    if (method_exists($target, 'setAction')) {
                        $target->setAction($masterDocument->getAction());
                    }

                    if(method_exists($target, 'setContentMainDocument')) {
                        $target->setContentMainDocument($masterDocument);
                    } else {
                        $target->setContentMasterDocument($masterDocument);
                    }

                    $target->setProperty('language', 'text', $masterDocument->getProperty('language'));
                }
            }
        }

        $cachedItem = $this->getCachedItem($target);

        $cachedItem['rawItemIds'][] = $rawItem['id'];
        $this->setCachedItem($target, $cachedItem);

        $changedObjects = [$target];

        $targetConfig = $this->getTargetConfig();

        if ($targetConfig['javascriptEngine'] === CallbackFunction::ENGINE_PHP) {
            $jsParamsRawItemData = new RawItem($this->rawItems[$rawItem['id']]['data']);
        } else {
            $jsParamsRawItemData = $this->rawItems[$rawItem['id']]['data'];
        }

        $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);

        restartMapping:
        foreach ($mappings as $mapping) {
            try {
                try {
                    $updatableObject = self::getUpdatableObject($target, $mapping);
                } catch (ObjectBrickNotAvailableException $e) {
                    $this->log($target, $e->getMessage(), 'info');
                    continue;
                } catch (FieldDoesNotExistException $e) {
                    $this->log($target, $e->getMessage(), 'notice');
                    continue;
                } catch(Exception $e) {
                    $this->log($target, $e->getMessage(), 'warning');
                    continue;
                }

                $currentValueFunction = function () use ($target, $updatableObject, $mapping, &$currentValueFunction) {
                    Helper::useInheritance(false);

                    try {
                        $currentValue = self::getValue($updatableObject, $mapping, !empty($mapping['locale']) ? [$mapping['locale']] : []);
                    } catch(InvalidArgumentException $e) {
                        $this->log($target, $e->getMessage(), 'warning');
                        $currentValue = null;
                    }

                    Helper::useInheritance(true);

                    $currentValueFunction = static function() use ($currentValue) {
                        return $currentValue;
                    };

                    return $currentValue;
                };

                if (strpos($mapping['fieldName'], '__virtual_') !== 0 && !array_key_exists(Helper::getFieldKey($mapping), $cachedItem['latestVersionData'])) {
                    $currentValue = $currentValueFunction();

                    if (is_object($currentValue)) {
                        if ($currentValue instanceof ElementInterface && $currentValue->getId() > 0) {
                            $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)] = ['type' => \OpenDxp\Model\Element\Service::getElementType($currentValue), 'id' => $currentValue->getId()];
                        } else {
                            $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)] = Helper::cloneElement($currentValue);
                        }
                    } elseif(is_array($currentValue)) {
                        $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)] = [];
                        foreach($currentValue as $currentValueKey => $currentValueItem) {
                            if (is_object($currentValueItem)) {
                                if ($currentValueItem instanceof ElementInterface && $currentValueItem->getId() > 0) {
                                    $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)][$currentValueKey] = ['type' => \OpenDxp\Model\Element\Service::getElementType($currentValueItem), 'id' => $currentValueItem->getId()];
                                } else {
                                    $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)][$currentValueKey] = Helper::cloneElement($currentValueItem);
                                }
                            } else {
                                $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)][$currentValueKey] = Helper::cloneElement($currentValueItem);
                            }
                        }
                    } else {
                        $cachedItem['latestVersionData'][Helper::getFieldKey($mapping)] = $currentValue;
                    }

                    $this->setCachedItem($target, ['latestVersionData' => $cachedItem['latestVersionData']]);
                }


                if ($mapping['fieldName'] === 'id') {
                    $allowSettingId = $this->isCompleteObjectImport();

                    if(!$allowSettingId) {
                        continue;
                    }
                }

                $def = self::getFieldDefinition($updatableObject, $mapping);

                if(!empty($mapping['format']) && !$def instanceof Data\Classificationstore && !$def instanceof Data\Objectbricks) {
                    if (!empty($mapping['format']['writeProtected'])) {
                        if (!$this->isEmpty($currentValueFunction(), $def)) {
                            $this->log($target, 'Field "'.$mapping['fieldName'].($mapping['locale'] ? '#'.$mapping['locale'] : '').'" is populated and configured to be write-protected -> won\'t change value', 'info');
                            continue;
                        }
                    } elseif (!empty($mapping['optimize'])) {
                        $this->getOptimizer()->setState(\Sylphen\DataBridgeBundle\lib\Pim\Helper::cloneElement($target), $currentValueFunction());
                        if ($this->getOptimizer()->getTemperature() > 0) {
                            goto restartMapping;
                        }
                        $target = $this->getOptimizer()->getBest();
                    }
                }

                $hasStaticCachedValue = false;
                try {
                    $value = null;
                    if ($mapping['fieldNo'] != null) {
                        $value = $this->rawItems[$rawItem['id']]['data']['field_'.$mapping['fieldNo']]['value'] ?? null;
                    }

                    // Berechnungen ausführen
                    if (!empty($mapping['calculation'])) {
                        $hasStaticCachedValue = CallbackFunction::hasStaticCachedValue($mapping['calculation']);

                        $jsParams = [];
                        if (!$hasStaticCachedValue) {
                            $currentObjectValues = function () use ($target, $mapping, $updatableObject) {
                                $cachedItem = $this->getCachedItem($target);
                                if (!preg_match('/currentObjectData[\'"]\][);]/', $mapping['calculation'])) {
                                    if (preg_match_all('/currentObjectData[\'"]\]\[["\']([A-Za-z0-9_#]+)["\']\]/', $mapping['calculation'], $fieldsToBeSerialized)) {
                                        $currentObjectValues = $cachedItem['currentObjectData'] ?? [];
                                        foreach ($fieldsToBeSerialized[1] as $fieldToBeSerialized) {
                                            if (isset($currentObjectValues[$fieldToBeSerialized])) {
                                                continue;
                                            }

                                            Serializer::trimOutputForBetterPerformance(false);
                                            if (array_key_exists($fieldToBeSerialized, $cachedItem['latestVersionData'])) {
                                                $fieldSerialization = self::getSerializer()->serialize($this->getLatestVersionData($target, $fieldToBeSerialized));
                                            } else {
                                                $fieldSerialization = self::getSerializer()->serializeField($target, self::getFieldDefinition($updatableObject, $fieldToBeSerialized));
                                            }

                                            $currentObjectValues[$fieldToBeSerialized] = $fieldSerialization;
                                        }
                                    } else {
                                        Serializer::trimOutputForBetterPerformance(false);
                                        $this->setCachedItem($target, ['currentObjectData' => self::getSerializer()->getAttributesArrayForObject($target)]);
                                        $currentObjectValues = $cachedItem['currentObjectData'];
                                    }
                                } else {
                                    Serializer::trimOutputForBetterPerformance(false);
                                    $cachedItem['currentObjectData'] = self::getSerializer()->getAttributesArrayForObject($target);
                                    $this->setCachedItem($target, ['currentObjectData' => $cachedItem['currentObjectData']]);
                                    $currentObjectValues = $cachedItem['currentObjectData'];
                                }

                                return $currentObjectValues;
                            };

                            $jsParams = [
                                'rawItem' => $rawItem,
                                'rawItemData' => $jsParamsRawItemData,
                                'value' => $value,
                                'currentObjectData' => $currentObjectValues,
                                'keyValues' => $keyValues,
                                'virtualFields' => $rawItem['virtualFields'],
                                'field' => $mapping['fieldName'],
                                'logger' => $this->logger,
                                'request' => Helper::getRequest(),
                                'transfer' => $transfer ?? new stdClass(),
                                'translator' => $this->translationHelper,
                                'context' => [
                                    'dataportId' => $this->dataport['id'],
                                    'dataport' => [
                                        'id' => $this->dataport['id'],
                                        'name' => $this->dataport['name'],
                                    ],
                                    'user' => [
                                        'id' => Helper::getUser()->getId(),
                                        'username' => Helper::getUser()->getUsername()
                                    ]
                                ],
                            ];

                            if (isset($mapping['locale'])) {
                                $jsParams['locale'] = $mapping['locale'];
                            }

                            $jsParams['currentValue'] = static function () use ($def, $mapping, $updatableObject, $cachedItem) {
                                if ($cachedItem['currentObjectData'] !== null && array_key_exists($def->getName().($mapping['locale'] ? '#'.$mapping['locale'] : ''), $cachedItem['currentObjectData'])) {
                                    return $cachedItem['currentObjectData'][$def->getName().($mapping['locale'] ? '#'.$mapping['locale'] : '')];
                                }

                                Helper::useInheritance(false);
                                self::getSerializer()::disableCache();
                                Serializer::trimOutputForBetterPerformance(false);
                                $return = self::getSerializer()->serializeField($updatableObject, $def, !empty($mapping['locale']) ? [$mapping['locale']] : []);
                                Helper::useInheritance(true);
                                return $return;
                            };
                        }

                        $value = CallbackFunction::evaluateScript($mapping['calculation'], $targetConfig['javascriptEngine'], $jsParams);
                    }

                    if (!empty($mapping['keyMapping'])) {
                        if(is_array($value) && isset($value[0])) {
                            $value = $keyValues[Helper::getFieldKey($mapping)];
                        } elseif (is_string($value) && @preg_match($value, '') !== false && (strpos($value, '*') !== false || strpos($value, '+'))) {
                            $regexpValue = $value;
                            $value = $jsParams['currentValue']();
                            $this->log($target, 'Not applying value "'.$regexpValue.'" to "'.$mapping['fieldName'].'" because it looks like a regular expression which gets only used for querying existing elements', 'info');
                        }
                    }
                } catch (\Throwable $ex) {
                    if ($ex instanceof ErrorException) {
                        $errorLevel = 'alert';
                        if(in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                            $errorLevel = 'debug';
                        } elseif($ex->getCode() === E_USER_WARNING || strpos($ex->getMessage(), 'User Warning') === 0) {
                            $errorLevel = 'warning';
                        }

                        $this->log($target, 'Error when executing callback function for field "'.$mapping['fieldName'].'": '.$ex, $errorLevel);
                        continue;
                    }

                    $this->log($target, 'Error when executing callback function for field "'.$mapping['fieldName'].'": '.$ex, 'warning');
                }

                $unmappedValue = $value;
                $value = $this->map($mapping, $value, $currentValueFunction, $def, $target);

                $fieldKey = \Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping);
                if (!$hasStaticCachedValue) {
                    $logOutput = self::getLogOutput($value);
                    if (!is_scalar($value) && $value !== null) {
                        $mappedLogOutput = self::getLogOutput($value, $unmappedValue);
                        if ($logOutput !== $mappedLogOutput) {
                            $logOutput .= ' -> '.$mappedLogOutput;
                        }
                    }

                    if($this->hasIgnoredImportData) {
                        if ($target instanceof Concrete) {
                            $ignoreClassId = $target->getClassId();
                        } else {
                            $ignoreClassId = OpenDxp\Model\Element\Service::getElementType($target);
                        }

                        $ignoreImportHash = $this->importIgnoreData->computeHash([
                            'classId'  => $ignoreClassId,
                            'path'     => $target->getRealFullPath(),
                            'field'    => $fieldKey,
                            'value'    => $logOutput,
                            'objectId' => $target->getId() ?: null,
                        ]);
                        $ignoreImportValue = $this->importIgnoreData->findOne(['hash = ?' => $ignoreImportHash]);
                        if ($ignoreImportValue) {
                            $this->log($target, 'Value for field '. $fieldKey .': '.$logOutput.' '.ImportIgnoreData::IGNORED_VALUE_LOG_SUFFIX, 'info');
                            continue;
                        }
                    }

                    $this->log($target, 'Value for field '. $fieldKey .': '.$logOutput, 'info');
                }

                $callSetValue = true;
                if($itemMold instanceof Asset && strtolower($mapping['fieldName']) === 'stream' && (is_string($value) || $value instanceof Stringable)) {
                    $value = (string)$value;
                    if(($value === '' || substr($value, -1) === '/') && !file_exists($value)) {
                        $mapping['fieldName'] = 'type';
                        $value = 'folder';
                    } else {
                        $originalValue = $value;
                        try {
                            $uri = new Uri($value);

                            if(!in_arrayi($uri->getScheme(), ['http', 'https'])) {
                                throw new \InvalidArgumentException('URI is not HTTP(S), falling back to local file / stream wrapper');
                            }

                            $value = (string)$uri;
                        } catch (\InvalidArgumentException $e) {
                            $value = $this->getAssetSourceFile($value);
                        }

                        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                            $value = self::getStreamFromFileOrUrl($value, $this->logger);
                        } else {
                            $stream = null;
                            $basename = basename($originalValue);

                            if (\OpenDxp\File::getValidFilename($basename) === strtolower($basename) || file_exists($value)) {
                                try {
                                    $stream = @\fopen($this->getAssetSourceFile($value), 'rb');
                                } catch(\Throwable $e) {
                                }
                            }

                            if (!is_resource($stream)) {
                                $stream = fopen(\OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->dataport['id'].'_'.uniqid(), 'w+b');
                                fwrite($stream, $originalValue);
                                rewind($stream);
                            }

                            $value = $stream;
                        }

                        if(!is_resource($value)) {
                            $this->log($target, 'Could not load asset source "'.$originalValue.'"', 'error');
                        }
                    }
                } elseif ($def instanceof GenericObjectRelation && $mapping['fieldName'] === 'tags') {
                    RuntimeCache::save($value, 'tags-'.spl_object_hash($target));
                    $callSetValue = false;
                } elseif (in_array($mapping['fieldName'], ['seoBundle.title', 'seoBundle.description'], true) && Helper::isBundleInstalled('SeoBundle')) {
                    $runtimeCacheKeySeoBundle = 'seoBundle'.spl_object_hash($target);
                    try {
                        $seoData = RuntimeCache::get($runtimeCacheKeySeoBundle);
                    } catch (\Throwable $e) {
                        $seoMetaDataController = \OpenDxp::getContainer()->get(\SeoBundle\Controller\Admin\MetaDataController::class);
                        $request = Helper::getRequest();
                        $request->query->set('elementType', Service::getElementType($target));
                        $request->query->set('elementId', $target->getId());
                        $seoData = $seoMetaDataController->getElementMetaDataConfigurationAction($request);
                        $seoData = json_decode($seoData->getContent(), true)['data'];
                    }

                    $seoFieldName = null;
                    if ($mapping['fieldName'] === 'seoBundle.title') {
                        $seoFieldName = 'title';
                    } elseif ($mapping['fieldName'] === 'seoBundle.description') {
                        $seoFieldName = 'description';
                    }

                    if($seoFieldName) {
                        if ( $target instanceof Concrete ) {
                            $localeFound = false;
                            foreach (($seoData['title_description'][$seoFieldName] ?? []) as &$item) {
                                if ($item['locale'] === $mapping['locale']) {
                                    $localeFound = true;
                                    $item['value'] = $value;
                                    break;
                                }
                            }

                            if (!$localeFound) {
                                $seoData['title_description'][$seoFieldName][] = [
                                    'locale' => $mapping['locale'],
                                    'value' => $value,
                                ];
                            }
                        } elseif ( $target instanceof PageSnippet) {
                            $seoData['title_description'][$seoFieldName] = $value;
                        }
                    }

                    RuntimeCache::save($seoData, $runtimeCacheKeySeoBundle);
                    $callSetValue = false;
                } elseif($mapping['fieldName'] === SelectMapper::VIRTUAL_WORKFLOW_FIELD) {
                    RuntimeCache::save($value, SelectMapper::VIRTUAL_WORKFLOW_FIELD.'-'.spl_object_hash($target));
                    $callSetValue = false;
                } elseif ($mapping['fieldName'] === 'Complete Object') {
                    $callSetValue = false;
                } elseif ($def instanceof Data\Checkbox && $mapping['fieldName'] === 'delete element') {
                    RuntimeCache::save($value, 'delete element-'.spl_object_hash($target));
                    if($value) {
                        $this->setCachedItem($target, ['save' => true]);
                    }

                    continue;
                } elseif($mapping['fieldName'] === 'modificationDate') {
                    $this->setCachedItem($target, ['latestVersionData' => ['modificationDate' => $value->getTimestamp()]]);
                } elseif ($def instanceof Data\CalculatedValue) {
                    $callSetValue = false;
                }

                if($targetConfig['optimizeInheritance'] && empty($mapping['keyMapping']) && $target instanceof Concrete && $target->getClass()->getAllowInherit() && !in_array(Helper::prefixObjectSystemColumn($mapping['fieldName']), Helper::getSystemFields(), true) && !$def instanceof GenericObjectRelation) {
                    $alternativeTarget = $this->getAncestorForInheritanceOptimization($target, $value, $mapping);
                    if($alternativeTarget !== $target) {
                        $changedObjects = array_merge($changedObjects, $this->mapData($alternativeTarget, $rawItem, [$mapping], $keyValues, $transfer));
                        continue;
                    }
                }

                if ($updatableObject instanceof AbstractData) {
                    $anyFieldSet = !$this->isEmpty($value, $def);

                    $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                    $brickField = $target->$brickfieldGetter();

                    $brickGetter = 'get' . ucfirst($mapping['brickName']);
                    if ($anyFieldSet) {
                        $brickSetter = 'set' . ucfirst($mapping['brickName']);
                        $brickField->$brickSetter($updatableObject);
                    } elseif(!$brickField->$brickGetter() instanceof AbstractData) {
                        $this->log($target, 'Not adding brick '.$mapping['brickName'].' because all fields are empty', 'info');
                        continue;
                    }
                }

                $changedObjects[] = $target;

                // do not call setter for virtual fields and for calculated value fields
                if($callSetValue) {
                    try {
                        $fieldDefinition = null;
                        if (is_array($value)) {
                            if (!empty($mapping['targetBrickField'])) {
                                $fieldDefinition = self::getFieldDefinition($target, $mapping['targetBrickField']);
                            } else {
                                $fieldDefinition = self::getFieldDefinition($target, $mapping['fieldName']);
                            }

                            if (method_exists($fieldDefinition, 'getParameterTypeDeclaration')) {
                                $targetParameterType = ltrim($fieldDefinition->getParameterTypeDeclaration(), '?');
                                if ($targetParameterType === 'string') {
                                    $value = json_encode($value);
                                }
                            }
                        }

                        self::setValue($updatableObject, $mapping, $value, !empty($mapping['locale']) ? [$mapping['locale']] : []);

                        if($cachedItem['currentObjectData'] !== null) {
                            if($fieldDefinition === null) {
                                if (!empty($mapping['targetBrickField'])) {
                                    $fieldDefinition = self::getFieldDefinition($target, $mapping['targetBrickField']);
                                } else {
                                    $fieldDefinition = self::getFieldDefinition($target, $mapping['fieldName']);
                                }
                            }

                            $fieldSerialization = self::getSerializer()->serializeField($updatableObject, $fieldDefinition, !empty($mapping['locale']) ? [$mapping['locale']] : []);

                            $cachedItem['currentObjectData'][$fieldDefinition->getName().($mapping['locale'] && !$fieldDefinition instanceof Data\Objectbricks ? '#'.$mapping['locale'] : '')] = $fieldSerialization;

                        }
                    } catch (\Throwable $e) {
                        $this->log($target, 'Unable to set value for field "'.$mapping['fieldName'].'", '.$e->getMessage(), 'warning');
                    }
                }

                $rawItem['virtualFields'][$fieldKey] = $value;
            } catch (\Throwable $e) {
                if($e instanceof ElementLockedException) {
                    throw $e;
                }

                // FieldDoesNotExistException appears not indirectly mapped fields, e.g. object brick fields which are mapped via container
                if(!$e instanceof FieldDoesNotExistException) {
                    $this->log($target, 'Error while mapping value of field "'.$mapping['fieldName'].'"'.($mapping['fieldNo'] !== null ? ', raw value "'.(self::getLogOutput($this->rawItems[$rawItem['id']]['data']['field_'.$mapping['fieldNo']]['value']) ?? '(empty)').'"' : '').': '.$e, 'alert');
                }
            }
        }
        $this->setCachedItem($target, ['currentObjectData' => $cachedItem['currentObjectData']]);

        return $changedObjects;
    }

    /**
     * Applies mapping to given value and returns the result
     * @param array $mapping
     * @param mixed $rawValue
     * @param mixed $currentValue
     * @param ClassDefinition\Data $fieldDefinition
     * @param AbstractModel $dataObject
     * @return mixed the final value, created by transforming $rawValue using $mapping
     */
    public function map($mapping, $rawValue, $currentValue, ClassDefinition\Data $fieldDefinition, ?AbstractModel $dataObject = null) {
        $value = $this->fieldMappingManager->map($mapping, $rawValue, $currentValue, $fieldDefinition, $dataObject);

        if ($value !== $rawValue && !$fieldDefinition instanceof Data\Relations\AbstractRelations && strtolower($mapping['fieldName'] ?? '') !== 'path') {
            try {
                $targetObject = $this->getObjectByIdentifier($value, $dataObject);
            if ($targetObject !== $value) {
                return $targetObject;
            }
            } catch (Exception $e) {
            }
        }

        $value = $this->replaceObjectIdentifier($value, $dataObject);

        return $value;
    }

    public function getObjectIdentifiers(&$value) {
        $replacementRegexes = [
            '/\{\{\s*([A-Za-z0-9\\\.]+:[^:]+(::?[^:]+?)*)\s*\}\}/',
            '/([A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*)/'
        ];

        foreach ($replacementRegexes as $replacementRegex) {
            $objectIdentifiers = [];
            if (preg_match_all($replacementRegex, $value, $objectIdentifiers, PREG_SET_ORDER)) {
                foreach ($objectIdentifiers as $objectIdentifier) {
                    yield $objectIdentifier;
                }
            }
        }
    }

    public function replaceObjectIdentifier($value, ?AbstractModel $context = null)
    {
        if (\is_array($value) || $value instanceof stdclass) {
            foreach ($value as $index => &$valueItem) {
                if($index === 'query') {
                    continue;
                }

                try {
                    $targetObject = $this->getObjectByIdentifier($valueItem, $context);
                    if ($targetObject !== $valueItem) {
                        $valueItem = $targetObject;
                    } else {
                        $valueItem = $this->replaceObjectIdentifier($valueItem, $context);
                    }
                } catch (Exception $e) {
                }
            }
            unset($valueItem);
            return $value;
        }

        if(!is_string($value)) {
            return $value;
        }

        $originalValue = $value;
        $value = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);

        // $value === null means an error occured, this happens for example when applying preg_replace to a binary string
        if ($value === null || (strpos($value, '{{') === false && strpos($value, '{%') === false)) {
            return $originalValue;
        }

        $originalValue = $value;

        $value = \str_replace(['#', ';', '%s'], ['_hashtag_', '_semicolon_', '_current_value_'], $value);

        $variables = [];

        try {
            $variables = self::getTwigVariables($value);
        } catch (Throwable $e) {
        }

        if (count($variables) === 0) {
            return $originalValue;
        }

        usort($variables, static function($variable1, $variable2) {
            return strlen($variable2) <=> strlen($variable1);
        });

        $templateVariables = [];
        foreach ($variables as $variable) {
            $originalVariable = $variable;
            $variable = str_replace(['_hashtag_', '_semicolon_', '_current_value_'], ['#', ';', '%s'], $variable);

            try {
                $parts = $this->getObjectIdentifierParts($variable, $context);
                if($parts === false) {
                    $variable = '.:.:.:'.$variable;
                }
            } catch(Exception $e) {
                $variable = '.:.:.:'.$variable;
            }

            try {
                $replacementObject = $this->getObjectByIdentifier($variable, $context);
                if($replacementObject === null && strpos($variable, '.:.:.:') === false) {
                    // this can happen when the data object class of $context contains a field which has the same name as another data object class, e.g. manufacturer:name, then getObjectIdentifierParts uses special handling for 2-part data query selector and executes manufacturer:path:name
                    $variable = '.:.:.:'.$variable;
                    $replacementObject = $this->getObjectByIdentifier($variable, $context);
                }
            } catch (Exception $e) {
                $this->logger->warning('Cannot replace placeholder "'.$variable.'": '.$e->getMessage());
                $replacementObject = null;
            }

            $variableName = 'dd_'.md5($variable);

            $value = preg_replace_callback('/({[{%][^}]*?)(?:\.:\.:\.:)?'.preg_quote($originalVariable, '/').'(.*?[}%]})/', static function($matches) use ($variableName) {
                $return = str_replace("\xc2\xa0", ' ', $matches[1]). // replace non-breaking space
                    $variableName;
                if(strpos($matches[1], ' for ') === false || strpos($matches[1], ' in ') !== false) {
                    $return .= '|raw';
                }
                $return .= str_replace("\xc2\xa0", ' ', $matches[2]);
                return $return;
            }, $value);

            $templateVariables[$variableName] = $replacementObject;
        }

        try {
            $template = self::getTwigEnvironment()->createTemplate($value);
            $value = $template->render($templateVariables);

            $value = \str_replace(['_hashtag_', '_semicolon_', '_current_value_'], ['#', ';', '%s'], $value);
        } catch(Throwable $e) {
            if(stripos($originalValue, 'debug') !== false) {
                $value = $e->getMessage();
            } else {
                $value = $originalValue;
            }
        }

        return $value;
    }

    public static function getTwigVariables($template) {
        $template = \str_replace(['#', ';', '%s'], ['_hashtag_', '_semicolon_', '_current_value_'], $template);
        $template = preg_replace('/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $template);

        $variables = [];
        $template = preg_replace_callback(
            '/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/',
            static function ($match) use (&$variables) {
                if(preg_match('/[+\-*\/.\[]/', $match[1])) {
                    return $match[0];
                }
                $variables[] = trim(explode('|', $match[1])[0]);
                return 'output';
            },
            $template
        );

        $twigEnvironment = self::getTwigEnvironment();
        $lexer = new Lexer($twigEnvironment);
        $stream = $lexer->tokenize(new Source($template, md5($template)));

        $twigFilters = array_keys($twigEnvironment->getFilters());

        $checkTokenType = function(Token $token, $type) {
            if(method_exists($token, 'test')) {
                return $token->test($type);
            }

            $token->getType() === $type;
        };

        while (!$stream->isEOF()) {
            $token = $stream->next();

            if ($checkTokenType($token, Token::NAME_TYPE) && !in_array($token->getValue(), ['for', 'endfor', 'if', 'else', 'elseif', 'endif', 'iterable', 'default'], true) && !in_array($token->getValue(), $twigFilters, true)) {
                $variable = $token->getValue();

                while (!$stream->isEOF() && !$checkTokenType($token, Token::VAR_END_TYPE) && !$checkTokenType($token, Token::BLOCK_END_TYPE)) {
                    $token = $stream->next();

                    if (in_array($token->getValue(), [':', '.', '(', '/', '-', ',', '<', '>'], true)) {
                        $prevToken = $token->getValue();
                        $token = $stream->next();
                        if ($checkTokenType($token, Token::NAME_TYPE) || $checkTokenType($token, Token::NUMBER_TYPE) || $token->getValue() === '.') {
                            if ($prevToken === '.' && $checkTokenType($token, Token::NAME_TYPE)) {
                                continue 2; // loop variable, e.g. item.itemCode
                            }

                            $variable .= $prevToken.$token->getValue();
                        } elseif (in_array($token->getValue(), ['/', ','], true)) {
                            $currentToken = $token->getValue();
                            $token = $stream->next();
                            if ($checkTokenType($token, Token::NAME_TYPE) || $checkTokenType($token, Token::NUMBER_TYPE)) {
                                $variable .= $prevToken.$currentToken.$token->getValue();
                            }
                        } elseif ($token->getValue() === '(') {
                            $variable .= $prevToken.$token->getValue();
                            while (!$stream->isEOF() && $token->getValue() !== ')') {
                                $token = $stream->next();
                                $variable .= $token->getValue();
                            }
                        }
                    } elseif ($checkTokenType($token, Token::OPERATOR_TYPE)) {
                        if ($token->getValue() === 'in') {
                            continue 2;
                        }

                        $variables[] = $variable;
                        continue 2;
                    } elseif ($checkTokenType($token, Token::NAME_TYPE) && !in_array($token->getValue(), ['for', 'endfor', 'if', 'else', 'elseif', 'endif', 'iterable', 'default'], true) && !in_array($token->getValue(), $twigFilters, true)) {
                        $variables[] = $token->getValue();
                        continue 2;
                    } elseif ($token->getValue() === '|') {
                        $variables[] = $variable;
                        continue 2;
                    } elseif ($checkTokenType($token, Token::STRING_TYPE)) {
                        $variable .= '"'.$token->getValue().'"';
                    }
                }

                $variables[] = $variable;
            }
        }

        $variables = array_filter(array_unique($variables), static function($variable) use ($template) {
            return $variable && strpos($template, 'set '.$variable) === false;
        });
        return $variables;
    }

    private function hasModifiedData(ElementInterface $item) {
        if(!$item->getId()) {
            return true;
        }

        foreach ($this->getMappings() as $mapping) {
            try {
                $updatableObject = self::getUpdatableObject($item, $mapping);
            } catch (\Throwable $e) {
                continue;
            }

            $fieldDefinition = self::getFieldDefinition($updatableObject, $mapping);

            if ($fieldDefinition->getLocked() && !$this->isCompleteObjectImport()) {
                continue;
            }

            $getterArgs = [];
            if (!empty($mapping['locale'])) {
                $getterArgs[] = $mapping['locale'];
            }

            Helper::useInheritance(false);
            $value = self::getValue($updatableObject, $mapping, $getterArgs);
            $value2 = $this->getLatestVersionData($item, Helper::getFieldKey($mapping));
            Helper::useInheritance(true);

            if (!$this->isEqual($fieldDefinition, $value, $value2)) {
                if($updatableObject instanceof DataObject\Classificationstore && $fieldDefinition instanceof Data\CalculatedValue) {
                    $updatableObject->markFieldDirty($fieldDefinition->getName());
                }
                return true;
            }
        }

        return false;
    }

    /**
     * @param ElementInterface $newObject
     * @param ElementInterface|null $oldObject
     * @param array|null $fieldsToTest
     * @return bool|string
     * @throws Exception
     */
    public function isModified($newObject, $oldObject = null, ?array $fieldsToTest = null, $onlyMappedFields = true)
    {
        $request = Helper::getRequest();
        $requestContext = $request->attributes->get(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT);
        $requestIsFrontendRequest = $request->attributes->get(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST);
        $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::CONTEXT_ADMIN);
        $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);

        try {
            if (!$newObject->getId() && ($fieldsToTest === null || in_arrayi('id', $fieldsToTest))) {
                return 'Object is new';
            }

            if ($oldObject === null) {
                return 'No latest version found';
            }

            if ($newObject instanceof Asset) {
                if ($fieldsToTest === null) {
                    if (method_exists($newObject, '__sleep')) {
                        $fieldsToTest = array_diff($newObject->__sleep(), ['dataModificationDate', '__dataVersionTimestamp', '_fulldump', 'dataChanged', 'dependencies', 'siblings', 'hasSiblings', 'hasMetaData', '____pimcore_cache_item__']);
                    } else {
                        return serialize($newObject) !== serialize($oldObject); // serialize calls __sleep() which removes unnecessary properties (like Asset::stream)
                    }
                }

                foreach ($fieldsToTest as $fieldToTest) {
                    $getter = 'get'.ucfirst($fieldToTest);
                    $v1 = null;
                    $v2 = null;
                    if (self::method_exists($newObject, $getter)) {
                        $v1 = $newObject->$getter();
                    }
                    if (self::method_exists($oldObject, $getter)) {
                        $v2 = $oldObject->$getter();
                    }

                    $fieldDefinition = self::getFieldDefinition($newObject, $fieldToTest);
                    if (!$this->isEqual($fieldDefinition, $v1, $v2)) {
                        return 'Field '.$fieldToTest.' changed';
                    }
                }

                if ($newObject instanceof Asset && $oldObject instanceof Asset && !$newObject instanceof Asset\Folder && !$oldObject instanceof Asset\Folder) {
                    try {
                        $assetChecksumChanged = self::getAssetChecksum($newObject) !== self::getAssetChecksum($oldObject);
                        if ($assetChecksumChanged) {
                            return 'Asset checksum changed';
                        }
                    } catch (\Exception $e) {
                        if($newObject->getDataChanged()) {
                            return 'Asset checksum could not be calculated ('.$e->getMessage().') but as asset source got changed, it is assumed that it got modified';
                        }
                    }
                }

                return false;
            }

            if(!$newObject instanceof Concrete) {
                return 'Object is of class '.get_class($newObject);
            }

            Helper::useInheritance(false);

            if ($newObject->getPublished() !== $oldObject->getPublished() && ($fieldsToTest === null || in_arrayi('published', $fieldsToTest))) {
                return 'Publish state changed';
            }

            if ($newObject->getRealFullPath() !== $oldObject->getRealFullPath() && ($fieldsToTest === null || in_arrayi('path', $fieldsToTest) || in_arrayi('key', $fieldsToTest) || in_arrayi('filename', $fieldsToTest))) {
                return 'Full path changed';
            }

            $fields = $newObject->getClass()->getFieldDefinitions();

            if($onlyMappedFields) {
                $fieldMappings = $this->getMappings();
            } else {
                $fieldMappings = [];
                foreach($fields as $fieldDefinition) {
                    if($fieldDefinition instanceof Data\Localizedfields) {
                        foreach($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                            foreach(Tool::getValidLanguages() as $language) {
                                $fieldMappings[] = [
                                    'dataportId' => $this->dataport['id'],
                                    'fieldName' => $localizedFieldDefinition->getName(),
                                    'locale' => $language,
                                    'fieldNo' => '',
                                    'keyMapping' => 0,
                                    'format' => '',
                                    'calculation' => ''
                                ];
                            }
                        }
                    } elseif($fieldDefinition instanceof Data\Objectbricks) {
                        foreach($fieldDefinition->getAllowedTypes() as $brickName) {
                            $brickDefinition = Definition::getByKey($brickName);
                            if($brickDefinition instanceof Definition) {
                                foreach($brickDefinition->getFieldDefinitions() as $brickFieldDefinition) {
                                    if ($brickFieldDefinition instanceof ClassDefinition\Data\Localizedfields) {
                                        foreach($brickFieldDefinition->getFieldDefinitions() as $localizedBrickFieldDefinition) {
                                            foreach (Tool::getValidLanguages() as $language) {
                                                $fieldMappings[] = [
                                                    'dataportId' => $this->dataport['id'],
                                                    'fieldName' => $localizedBrickFieldDefinition->getName(),
                                                    'locale' => $language,
                                                    'fieldNo' => '',
                                                    'keyMapping' => 0,
                                                    'format' => '',
                                                    'calculation' => ''
                                                ];
                                            }
                                        }
                                    } else {
                                        $fieldMappings[] = [
                                            'dataportId' => $this->dataport['id'],
                                            'fieldName' => $brickFieldDefinition->getName(),
                                            'locale' => '',
                                            'fieldNo' => '',
                                            'keyMapping' => 0,
                                            'format' => '',
                                            'calculation' => ''
                                        ];
                                    }
                                }
                            }
                        }
                    } else {
                        $fieldMappings[] = [
                            'dataportId' => $this->dataport['id'],
                            'fieldName' => $fieldDefinition->getName(),
                            'locale' => '',
                            'fieldNo' => '',
                            'keyMapping' => 0,
                            'format' => '',
                            'calculation' => ''
                        ];
                    }
                }
            }
            $fieldMappingNames = array_column($fieldMappings, 'fieldName');

            $canFieldBeDirty = static function (Data $fieldDefinition, AbstractModel $object, $fieldMappingNames) use ($onlyMappedFields) {
                if(!$onlyMappedFields) {
                    return true;
                }

                if (!in_array($fieldDefinition->getName(), $fieldMappingNames, true) && !$fieldDefinition instanceof Data\Localizedfields && !$fieldDefinition instanceof Data\Objectbricks && !$fieldDefinition instanceof Data\Fieldcollections && !$fieldDefinition instanceof Data\Classificationstore) {
                    return false;
                }

                if (!AbstractObject::isDirtyDetectionDisabled() && $fieldDefinition->supportsDirtyDetection() && $object instanceof DirtyIndicatorInterface && !$fieldDefinition instanceof Data\Localizedfields && !$fieldDefinition instanceof Data\Objectbricks && !$fieldDefinition instanceof Data\Fieldcollections && !$fieldDefinition instanceof Data\Classificationstore && !$object->isFieldDirty($fieldDefinition->getName())) {
                    return false;
                }

                return true;
            };

            $fields = array_filter(
                $fields,
                static function (Data $fieldDefinition) use ($newObject, $canFieldBeDirty, $fieldMappingNames) {
                    return $canFieldBeDirty($fieldDefinition, $newObject, $fieldMappingNames);
                }
            );

            foreach ($fields as $fieldName => $definition) {
                if ($definition instanceof ClassDefinition\Data\Localizedfields) {
                    /** @var Localizedfield $localizedFields */
                    $localizedFields = $newObject->getLocalizedFields();

                    $localizedFieldDefinitions = $definition->getFieldDefinitions();
                    $localizedFieldDefinitions = array_filter(
                        $localizedFieldDefinitions,
                        static function (Data $fieldDefinition) use ($newObject, $canFieldBeDirty, $fieldMappingNames) {
                            return $canFieldBeDirty($fieldDefinition, $newObject, $fieldMappingNames);
                        }
                    );
                    foreach ($localizedFieldDefinitions as $lfd) {
                        if($onlyMappedFields && $lfd instanceof Data\CalculatedValue && in_array($lfd->getName(), $fieldMappingNames, true)) {
                            return 'Calculated value field '.$lfd->getName().' is mapped -> always gets saved when using compatibility mode';
                        }

                        $getter = 'get'.ucfirst($lfd->getName());
                        if (\method_exists($newObject, $getter) xor \method_exists($oldObject, $getter)) {
                            return 'Localized field '.$lfd->getName().' does not exist on latest version';
                        }

                        foreach (Tool::getValidLanguages() as $language) {
                            if ($fieldsToTest !== null && !in_arrayi($lfd->getName().'#'.$language, $fieldsToTest)) {
                                continue;
                            }

                            if (!$this->isEqual($lfd, $newObject->$getter($language), $oldObject->$getter($language))) {
                                return 'Localized field '.$lfd->getName().'#'.$language.' changed';
                            }
                        }

                        if ($lfd->supportsDirtyDetection()) {
                            $localizedFields->markFieldDirty($lfd->getName(), false);
                            $newObject->markFieldDirty($lfd->getName(), false);
                        }
                    }

                    $localizedFields->resetDirtyMap();
                } elseif ($definition instanceof ClassDefinition\Data\Objectbricks) {
                    $bricks1 = $newObject->{'get'.ucfirst($fieldName)}();
                    $bricks2 = $oldObject->{'get'.ucfirst($fieldName)}();

                    if (!$bricks1 instanceof Objectbrick && !$bricks2 instanceof Objectbrick) {
                        continue;
                    }

                    foreach ($definition->getAllowedTypes() as $allowedType) {
                        $getter = 'get'.$allowedType;

                        /** @var null|AbstractData $brick1Value */
                        $brick1Value = null;
                        if ($bricks1) {
                            if (!\method_exists($bricks1, $getter)) {
                                return 'Brick '.$allowedType.' does not exist in object';
                            }
                            $brick1Value = $bricks1->$getter();
                        }

                        /** @var null|AbstractData $brick1Value */
                        $brick2Value = null;
                        if ($bricks2) {
                            if (!\method_exists($bricks2, $getter)) {
                                return 'Brick '.$allowedType.' does not exist in latest version';
                            }
                            $brick2Value = $bricks2->$getter();
                        }

                        if ($brick1Value === null && $brick2Value === null) {
                            continue;
                        }

                        if ($brick1Value instanceof AbstractData xor $brick2Value instanceof AbstractData) {
                            return 'Brick '.$allowedType.' does not exist';
                        }

                        $brickDefinition = Definition::getByKey($allowedType);
                        $brickFieldDefinitions = $brickDefinition->getFieldDefinitions();
                        $brickFieldDefinitions = array_filter(
                            $brickFieldDefinitions,
                            static function (Data $fieldDefinition) use ($brick1Value, $canFieldBeDirty) {
                                return $canFieldBeDirty($fieldDefinition, $brick1Value, [$fieldDefinition->getName()]);
                            }
                        );

                        foreach ($brickFieldDefinitions as $brickFieldDefinition) {
                            if ($onlyMappedFields && $brickFieldDefinition instanceof Data\CalculatedValue && in_array($brickFieldDefinition->getName(), $fieldMappingNames, true)) {
                                return 'Calculated value field '.$brickFieldDefinition->getName().' is mapped -> always gets saved when using compatibility mode';
                            }

                            if ($brickFieldDefinition instanceof ClassDefinition\Data\Localizedfields) {
                                /** @var Localizedfield $localizedFields */
                                $localizedFields = $brick1Value->getLocalizedFields();

                                $localizedFieldDefinitions = $brickFieldDefinition->getFieldDefinitions();
                                $localizedFieldDefinitions = array_filter(
                                    $localizedFieldDefinitions,
                                    static function (Data $fieldDefinition) use ($brick1Value, $canFieldBeDirty) {
                                        return $canFieldBeDirty($fieldDefinition, $brick1Value, [$fieldDefinition->getName()]);
                                    }
                                );
                                foreach ($localizedFieldDefinitions as $lfd) {
                                    $getter = 'get'.ucfirst($lfd->getName());
                                    if (\method_exists($brick1Value, $getter) xor \method_exists($brick2Value, $getter)) {
                                        return 'Localized field '.$allowedType.'/'.$lfd->getName().' does not exist on latest version';
                                    }

                                    foreach (Tool::getValidLanguages() as $language) {
                                        if ($fieldsToTest !== null && !in_arrayi($allowedType.'/'.$lfd->getName().'#'.$language, $fieldsToTest)) {
                                            continue;
                                        }

                                        if (!$this->isEqual($lfd, $brick1Value->$getter($language), $brick2Value->$getter($language))) {
                                            return 'Localized field '.$lfd->getName().'#'.$language.' changed';
                                        }
                                    }

                                    if ($lfd->supportsDirtyDetection()) {
                                        $localizedFields->markFieldDirty($lfd->getName(), false);
                                        $newObject->markFieldDirty($lfd->getName(), false);
                                    }
                                }

                                $localizedFields->resetDirtyMap();
                            } else {
                                if ($fieldsToTest !== null && !in_arrayi($allowedType.'/'.$brickFieldDefinition->getName(), $fieldsToTest)) {
                                    continue;
                                }

                                if ($brick1Value instanceof AbstractData && $brick2Value instanceof AbstractData) {
                                    $fieldGetter = 'get'.ucfirst($brickFieldDefinition->getName());
                                    if (!\method_exists($brick1Value, $fieldGetter) || !\method_exists($brick2Value, $fieldGetter)) {
                                        return 'Field '.$brickFieldDefinition->getName().' does not exist in brick '.$allowedType;
                                    }
                                    $brick1FieldValue = $brick1Value->$fieldGetter();
                                    $brick2FieldValue = $brick2Value->$fieldGetter();

                                    if (!$this->isEqual($brickFieldDefinition, $brick1FieldValue, $brick2FieldValue)) {
                                        return 'Field '.$brickFieldDefinition->getName().' in brick '.$allowedType.' changed';
                                    }
                                }
                            }

                            if ($brickFieldDefinition->supportsDirtyDetection()) {
                                $brick1Value->markFieldDirty($brickFieldDefinition->getName(), false);
                            }
                        }
                    }
                } elseif ($definition instanceof Data\Fieldcollections) {
                    $getter = 'get'.ucfirst($fieldName);
                    $fieldCollection1 = $newObject->$getter();
                    $fieldCollection2 = $oldObject->$getter();

                    if (!$fieldCollection1 instanceof Fieldcollection) {
                        $fieldCollection1 = new Fieldcollection();
                    }
                    if (!$fieldCollection2 instanceof Fieldcollection) {
                        $fieldCollection2 = new Fieldcollection();
                    }

                    $fieldCollection1Items = $fieldCollection1->getItems();
                    $fieldCollection2Items = $fieldCollection2->getItems();

                    if (count($fieldCollection1Items) !== count($fieldCollection2Items)) {
                        return true;
                    }

                    foreach ($fieldCollection1Items as $index => $item1) {
                        $item2 = $fieldCollection2Items[$index] ?? null;
                        if (!$item1 instanceof $item2) {
                            return true;
                        }
                        foreach ($item1->getDefinition()->getFieldDefinitions() as $fieldDefinition1) {
                            if ($onlyMappedFields && $fieldDefinition1 instanceof Data\CalculatedValue && in_array($fieldDefinition1->getName(), $fieldMappingNames, true)) {
                                return 'Calculated value field '.$fieldDefinition1->getName().' is mapped -> always gets saved when using compatibility mode';
                            }


                            if ($fieldDefinition1 instanceof ClassDefinition\Data\Localizedfields) {
                                /** @var Localizedfield $localizedFields */
                                $localizedFields = $item1->getLocalizedFields();

                                $localizedFieldDefinitions = $fieldDefinition1->getFieldDefinitions();
                                $localizedFieldDefinitions = array_filter(
                                    $localizedFieldDefinitions,
                                    static function (Data $fieldDefinition) use ($localizedFields, $canFieldBeDirty) {
                                        return $canFieldBeDirty($fieldDefinition, $localizedFields, [$fieldDefinition->getName()]);
                                    }
                                );
                                foreach ($localizedFieldDefinitions as $lfd) {
                                    if ($fieldsToTest !== null && !in_arrayi($item1->getType().'.'.$lfd->getName(), $fieldsToTest)) {
                                        continue;
                                    }
                                    $getter = 'get'.ucfirst($lfd->getName());
                                    if (\method_exists($item1, $getter) xor \method_exists($item2, $getter)) {
                                        return 'Localized field '.$fieldDefinition1->getName().'.'.$lfd->getName().' does not exist on latest version';
                                    }

                                    foreach (Tool::getValidLanguages() as $language) {
                                        if (!$this->isEqual($lfd, $item1->$getter($language), $item2->$getter($language))) {
                                            return 'Localized field '.$fieldDefinition1->getName().'.'.$lfd->getName().'#'.$language.' changed';
                                        }
                                    }

                                    if ($lfd->supportsDirtyDetection()) {
                                        $localizedFields->markFieldDirty($lfd->getName(), false);
                                        $newObject->markFieldDirty($lfd->getName(), false);
                                    }
                                }

                                $localizedFields->resetDirtyMap();
                            } else {
                                if ($fieldsToTest !== null && !in_arrayi($item1->getType().'.'.$fieldDefinition1->getName(), $fieldsToTest)) {
                                    continue;
                                }
                                $getter = 'get'.ucfirst($fieldDefinition1->getName());
                                if (!\method_exists($item1, $getter) || !\method_exists($item2, $getter)) {
                                    return 'Field '.$fieldDefinition1->getName().' does not exist in field collection '.$item1->getType();
                                }

                                $value1 = $item1->$getter();
                                $value2 = $item2->$getter();

                                if (!$this->isEqual($fieldDefinition1, $value1, $value2)) {
                                    return 'Field '.$fieldCollection1->getFieldname().'['.$index.'].'.$fieldDefinition1->getName().' changed';
                                }
                            }

                            if ($fieldDefinition1->supportsDirtyDetection()) {
                                $item1->markFieldDirty($fieldDefinition1->getName(), false);
                            }
                        }
                    }
                } else {
                    if ($fieldsToTest !== null && !in_arrayi($fieldName, $fieldsToTest)) {
                        continue;
                    }

                    $getter = 'get'.ucfirst($fieldName);
                    $value1 = null;
                    $value2 = null;
                    if (\method_exists($newObject, $getter)) {
                        $value1 = $newObject->$getter();
                    }

                    if (\method_exists($oldObject, $getter)) {
                        $value2 = $oldObject->$getter();
                    }

                    if ($onlyMappedFields && $definition instanceof Data\CalculatedValue && in_array($definition->getName(), $fieldMappingNames, true)) {
                        return 'Calculated value field '.$definition->getName().' is mapped -> always gets saved when using compatibility mode';
                    }

                    if (!$this->isEqual($definition, $value1, $value2)) {
                        return 'Field '.$fieldName.' changed';
                    }
                }

                if ($definition->supportsDirtyDetection()) {
                    $newObject->markFieldDirty($fieldName, false);
                }
            }

            if ($fieldsToTest === null || in_arrayi('properties', $fieldsToTest)) {
                foreach ($newObject->getProperties() as $newProperty) {
                    if (strpos($newProperty->getName(), self::HASH_PROP_PREFIX) === 0) {
                        continue;
                    }
                    $oldProperty = $oldObject->getProperty($newProperty->getName());
                    if ($newProperty->getData() != $oldProperty) {
                        return 'Property '.$newProperty->getName().' changed';
                    }
                }
            }
        } catch (\Throwable $e) {
            // In case of error assume the object was changed
            return (string)$e;
        } finally {
            Helper::useInheritance(true);

            $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, $requestContext);
            $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, $requestIsFrontendRequest);
        }

        return false;
    }

    /**
     * @return AbstractModel
     */
    public function createObject(AbstractModel $objectBlueprint, $key) {
        $key = Service::getValidKey($key, $this->getItemType());

        $suffix = 0;
        $parentPath = rtrim($this->getItemFolder()->getRealFullPath(), '/');
        do {
            $targetKey = $key.($suffix?'_'.$suffix:'');
            $intendedPath = $parentPath.'/'.$targetKey;
            $suffix++;

            try {
                self::getElementIdForPath($intendedPath, Service::getElementType($objectBlueprint));
                $pathExists = true;
            } catch (Exception $e) {
                $pathExists = $this->pathExistsInCache($intendedPath);
            }
        } while($pathExists);

        $objectClass = get_class($objectBlueprint);
        $object = new $objectClass();

        if($object instanceof Concrete || $object instanceof PageSnippet) {
            $object->setKey($targetKey);
            $object->setPublished(false);
        } elseif($object instanceof Asset) {
            $object->setType('unknown');
            $object->setFilename($targetKey);
        }

        if($object instanceof AbstractElement) {
            $object->setCreationDate(time());

            $user = Helper::getUser();
            $object->setUserModification($user->getId());
            $object->setUserOwner($user->getId());
            $object->setParent($this->getItemFolder());
            $object->setPath($parentPath.'/');
        }

        return $object;
    }

    private function getIdPrefix() {
        return $this->idPrefix;
    }

    private function getAssetSource() {
        $sourceconfig = $this->dataport['sourceconfig'];
        return $sourceconfig['assetSource'] ?? '/';
    }

    /** @return Asset\Folder */
    public function getAssetFolder() {
        if($this->assetFolder === null) {
            $targetconfig = $this->getTargetConfig();

            $parameters = [Helper::getEnvironmentVariables()];
            $request = Helper::getRequest();
            if ($request instanceof Request) {
                $parameters[] = $request->request->all();
                $parameters[] = $request->query->all();
                $parameters[] = $request->attributes->all();
                $parameters[] = ['domain' => $request->getHost(), 'hostname' => $request->getHost()];
            }
            $parameters = array_replace(...$parameters);
            $parameters = array_filter($parameters, static function ($parameterName) {
                return !in_array($parameterName, ['dataportId', 'importType', 'force', 'csrfToken', 'bundle', 'importfile', 'locale', 'apikey']) && substr($parameterName, 0, 1) !== '_';
            }, ARRAY_FILTER_USE_KEY);
            $parameters = new ParameterBag($parameters);

            $targetFolderPath = $this->replaceObjectIdentifier($targetconfig['assetFolder'], $parameters);
            $assetFolder = Asset::getByPath($targetFolderPath);
            if (!$assetFolder instanceof Asset\Folder) {
                $assetFolder = Asset\Service::createFolderByPath($targetFolderPath);
            }

            if (!$assetFolder instanceof Asset\Folder) {
                $assetFolder = Asset::getById(1);
            }

            $this->assetFolder = $assetFolder;
        }

        return $this->assetFolder;
    }

    /**
     * @return Asset|AbstractObject
     * @throws Exception
     */
    public function getItemFolder() {
        if($this->itemFolder === null) {
            $targetconfig = $this->getTargetConfig();

            $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);

            $parameters = [Helper::getEnvironmentVariables()];
            $request = Helper::getRequest();
            if ($request instanceof Request) {
                $parameters[] = $request->request->all();
                $parameters[] = $request->query->all();
                $parameters[] = $request->attributes->all();
                $parameters[] = ['domain' => $request->getHost(), 'hostname' => $request->getHost()];
            }
            $parameters = array_replace(...$parameters);
            $parameters = array_filter($parameters, static function ($parameterName) {
                return !in_array($parameterName, ['dataportId', 'importType', 'force', 'csrfToken', 'bundle', 'importfile', 'locale', 'apikey']) && substr($parameterName, 0, 1) !== '_';
            }, ARRAY_FILTER_USE_KEY);
            $parameters = new ParameterBag($parameters);

            if($itemMold instanceof Concrete) {
                $itemFolderPath = $this->replaceObjectIdentifier($targetconfig['itemFolder'], $parameters);
                $this->itemFolder = DataObject::getByPath($itemFolderPath);

                if (!$this->itemFolder instanceof AbstractObject) {
                    try {
                        $this->itemFolder = Service::createFolderByPath($itemFolderPath);
                    } catch (Exception $e) {
                        $this->itemFolder = AbstractObject::getById(1);
                    }
                }
            } elseif($itemMold instanceof Asset) {
                $itemFolderPath = $this->replaceObjectIdentifier($targetconfig['assetFolder'], $parameters);
                $this->itemFolder = Asset::getByPath($itemFolderPath);

                if (!$this->itemFolder instanceof Asset) {
                    try {
                        $this->itemFolder = Asset\Service::createFolderByPath($itemFolderPath);
                    } catch (Exception $e) {
                        $this->itemFolder = Asset::getById(1);
                    }
                }
            } elseif($itemMold instanceof Document) {
                $itemFolderPath = $this->replaceObjectIdentifier($targetconfig['itemFolder'], $parameters);
                $this->itemFolder = Document::getByPath($itemFolderPath);

                if (!$this->itemFolder instanceof Document) {
                    try {
                        $this->itemFolder = Document\Service::createFolderByPath($itemFolderPath);
                    } catch (Exception $e) {
                        $this->itemFolder = Document::getById(1);
                    }
                }
            }
        }

        return $this->itemFolder;
    }

    public function getOneObjectByIdentifier($objectIdentifier, ?AbstractModel $context = null)
    {
        $targetObject = $this->getObjectByIdentifier($objectIdentifier, $context, 1);
        if (is_array($targetObject)) {
            $targetObject = reset($targetObject);
        }

        return $targetObject;
    }

    /**
     * @param string $objectIdentifier Class:field[#locale]:value[:function][:argument1[,argument2, ...]] find object of type Class with field=value, execute function(argument1, argument2, ...) on this object and return return value of this method
     * @param mixed $context currently processed element
     *
     * @return mixed
     */
    public function getObjectByIdentifier($objectIdentifier, $context = null, $limit = null) {
        if (!is_string($objectIdentifier) && !$objectIdentifier instanceof Stringable) {
            if ($objectIdentifier instanceof OpenDxp\Model\Element\ElementDescriptor){
                return OpenDxp\Model\Element\Service::getElementById($objectIdentifier->getType(), $objectIdentifier->getId());
            }

            return $objectIdentifier;
        }

        $objectIdentifier = (string)$objectIdentifier;

        // if any problems occur with pseudo data query selectors in text, uncomment following line
        /*if (!\preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*$/', $objectIdentifier)) {
            return $objectIdentifier;
        }*/

        if (isset($this->relationCache[$objectIdentifier])) {
            if (is_array($this->relationCache[$objectIdentifier]) && !empty($this->relationCache[$objectIdentifier]['type']) && !empty($this->relationCache[$objectIdentifier]['id'])) {
                return Service::getElementById($this->relationCache[$objectIdentifier]['type'], $this->relationCache[$objectIdentifier]['id']);
            }

            if ($this->relationCache[$objectIdentifier] instanceof TypedArrayMapIterator && count($this->relationCache[$objectIdentifier]) > 1) {
                return iterator_to_array($this->relationCache[$objectIdentifier]);
            }
            return $this->relationCache[$objectIdentifier];
        }

        $logger = $this->logger;
        $debug = false;
        if (strpos($objectIdentifier, ':debug') !== false) {
            $objectIdentifier = str_replace(':debug', '', $objectIdentifier);
            $logger = new InMemoryLogger(static function () {
                return true;
            });
            $debug = true;
        }

        if(!$context instanceof AbstractModel && substr($objectIdentifier, 0, 6) === '.:.:.:') {
            try {
                return DataQuerySelectorResolver::resolveSingleField(str_replace('.:.:.:', '', $objectIdentifier), $context, $logger);
            } catch(\Throwable $e) {
                return $objectIdentifier;
            }
        }

        try {
            $parts = $this->getObjectIdentifierParts($objectIdentifier, $context);
            if ($parts === false) {
                return $objectIdentifier;
            }
        } catch(InvalidArgumentException $e) {
            return $objectIdentifier;
        }

        $identifierParts = $parts;

        $type = $parts[0];
        $targetObject = null;

        $cacheable = true;

        if($parts[1] === '.' && $parts[2] === '.' && $context instanceof $parts[0]) {
            $targetObject = $context;
            $logger->debug(new LazyLog(static function() use ($targetObject) {
                return 'Using context object '.self::getLogOutput($targetObject).' as target';
            }));

            $cacheable = false;
        }

        if($targetObject === null) {
            $isLikeSearch = substr($parts[2], 0, 1) === '*' || substr($parts[2], -1) === '*' || (in_arrayi(strtolower($parts[1]), ['path', 'o_path']) && strpos($parts[2], '*') !== false);
            $isInSearch = substr($parts[2], 0, 1) === '[' && substr($parts[2], -1) === ']';

            if ($isInSearch) {
                $filterValueItems = explode(',', substr($parts[2], 1, -1));
            } else {
                $filterValueItems = [$parts[2]];
            }

            $localeHashPosition = strpos($parts[1], '#');
            if($localeHashPosition !== false) {
                $locale = substr($parts[1], $localeHashPosition+1);
                $parts[1] = substr($parts[1], 0, $localeHashPosition);
            } else {
                $locale = Tool::getDefaultLanguage();
                \array_splice($parts, 3);
            }

            try {
                $parts[1] = preg_replace('/^getBy/i', '', $parts[1]);

                if (is_a($type, Asset::class, true) && !method_exists($type, 'get'.ucfirst($parts[1]))) {
                    // query asset by metadata
                    $assetMetaDataQuery = 'SELECT cid FROM assets_metadata WHERE name=? AND data IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').')';
                    $assetMetaDataParams = array_merge([$parts[1]], $filterValueItems);
                    if ($localeHashPosition !== false) {
                        $assetMetaDataQuery .= ' AND language=?';
                        $assetMetaDataParams[] = $locale;
                    }

                    $assetIDs = PimcoreDbRepository::getInstance()->findColumnInSql($assetMetaDataQuery, $assetMetaDataParams);
                    $targetObject = new ArrayMapIterator($assetIDs, static function($assetId) {
                        return Asset::getById($assetId);
                    });

                    $countTargetObjects = count($targetObject);
                    if ($countTargetObjects === 0) {
                        $targetObject = null;
                    } elseif ($countTargetObjects === 1) {
                        $targetObject->rewind();
                        $targetObject = $targetObject->current();
                    }
                } else {
                    if(is_a($type, Concrete::class, true) && in_arrayi(Helper::prefixObjectSystemColumn($parts[1]), Helper::getSystemFields(), true)) {
                        $parts[1] = Helper::prefixObjectSystemColumn($parts[1]);
                    }

                    /** @var AbstractListing $listing */
                    $listing = $type::getList([
                        'unpublished' => true,
                        'objectTypes' => [
                            AbstractObject::OBJECT_TYPE_OBJECT,
                            AbstractObject::OBJECT_TYPE_VARIANT,
                        ],
                        'locale' => $locale
                    ]);

                    if($limit !== null) {
                        $listing->setLimit($limit);
                    }

                    $itemMold = $this->itemMoldBuilder->getItemMoldByClassname($type);
                    $relationFieldSeparatorPosition = strpos($parts[1], ':');
                    $relationFilterField = null;
                    if($relationFieldSeparatorPosition !== false) {
                        $relationFilterField = substr($parts[1], $relationFieldSeparatorPosition + 1);
                        $parts[1] = substr($parts[1], 0, $relationFieldSeparatorPosition);
                    }
                    $fieldDefinition = self::getFieldDefinition($itemMold, $parts[1]);
                    $elementType = OpenDxp\Model\Element\Service::getElementType($itemMold);

                    if (in_arrayi(strtolower($parts[1]), ['path', 'o_path'])) {
                        $condition = [];
                        $conditionParams = [];
                        foreach ($filterValueItems as $filterValueItem) {
                            $lastSlashPosition = strrpos($filterValueItem, '/') + 1;
                            $path = substr($filterValueItem, 0, $lastSlashPosition);
                            $key = substr($filterValueItem, $lastSlashPosition);

                            $column = 'key';
                            if (is_a($type, Concrete::class, true)) {
                                $column = Helper::prefixObjectSystemColumn('key');
                            } elseif (is_a($type, Asset::class, true)) {
                                $column = 'filename';
                            }

                            if ($isLikeSearch) {
                                $condition[] = '`'.$parts[1].'` LIKE ? AND `'.$column.'` LIKE ?';
                                $conditionParams[] = str_replace(['/*/','*'], ['/%', '%'], $path);
                                $conditionParams[] = str_replace(['/*/', '*'], ['/%', '%'], $key);
                            } else {
                                $condition[] = '`'.$parts[1].'` = ? AND `'.$column.'` = ?';
                                $conditionParams[] = $path;
                                $conditionParams[] = $key;
                            }
                        }

                        $listing->addConditionParam('('.implode(' OR ', $condition).')', $conditionParams);
                    } elseif ($fieldDefinition instanceof Data\ManyToOneRelation) {
                        $allowedTypes = [];
                        if ($fieldDefinition->getAssetsAllowed()) {
                            $allowedTypes[] = 'asset';
                        }
                        if ($fieldDefinition->getObjectsAllowed()) {
                            $allowedTypes[] = 'object';
                        }
                        if ($fieldDefinition->getDocumentsAllowed()) {
                            $allowedTypes[] = 'document';
                        }

                        $listing->addConditionParam(
                            '`'.$parts[1].'__id` IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').') AND `'.$parts[1].'__type` IN ("'.implode('","', $allowedTypes).'")',
                            $filterValueItems
                        );
                    } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        try {
                            $allowedTypes = [];
                            if ($fieldDefinition->getAssetsAllowed()) {
                                $allowedTypes[] = 'asset';
                            }
                            if ($fieldDefinition->getObjectsAllowed()) {
                                $allowedTypes[] = 'object';
                            }
                            if ($fieldDefinition->getDocumentsAllowed()) {
                                $allowedTypes[] = 'document';
                            }

                            if($relationFilterField !== null) {
                                if ($fieldDefinition->getObjectsAllowed()) {
                                    $allowedClasses = $fieldDefinition->getClasses();
                                    if (!$allowedClasses) {
                                        if (method_exists($fieldDefinition, 'getAllowedClassId')) {
                                            $allowedClasses = [['classes' => $fieldDefinition->getAllowedClassId()]];
                                        } elseif (method_exists($fieldDefinition, 'getOwnerClassId')) {
                                            $allowedClasses = [['classes' => $fieldDefinition->getOwnerClassId()]];
                                        }
                                    }
                                    foreach ($allowedClasses as $allowedClass) {
                                        $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                        if ($allowedClass->getFieldDefinition($relationFilterField) instanceof Data) {
                                            $listing->addConditionParam(
                                                ($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN (SELECT '.Helper::prefixObjectSystemColumn('id').' FROM object_'.$allowedClass->getId().' WHERE '.$relationFilterField.' IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').')) AND type IN ("'.implode('","', $allowedTypes).'") AND ownertype = '.Db::get()->quote($elementType).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')',
                                                $filterValueItems
                                            );
                                            break;
                                        } elseif(($fieldDefinition instanceof Data\AdvancedManyToManyRelation || $fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation) && in_array($relationFilterField, $fieldDefinition->getColumnKeys())) {
                                            $listing->addConditionParam(
                                                ($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN (SELECT dest_id FROM object_metadata_'.$itemMold->getClassId().' WHERE `column`='.Db::get(
                                                )->quote($relationFilterField).' AND data IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').')) AND type IN ("'.implode('","', $allowedTypes).'") AND ownertype = '.Db::get()->quote($elementType).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')',
                                                $filterValueItems
                                            );
                                            break;
                                        }
                                    }
                                }
                            } else {
                                $listing->addConditionParam(
                                    ($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').') AND type IN ("'.implode('","', $allowedTypes).'") AND ownertype = '.Db::get()->quote($elementType).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')',
                                    $filterValueItems
                                );
                            }
                        } catch (Throwable $e) {
                            if ($fieldDefinition instanceof Data\ManyToManyRelation) {
                                $orConditions = [];
                                foreach($filterValueItems as $filterValueItem) {
                                    if ($fieldDefinition->getAssetsAllowed()) {
                                        $orConditions[] = "`$parts[1]` LIKE ".Db::get()->quote('asset|%,'.$filterValueItem.',%');
                                    }
                                    if ($fieldDefinition->getObjectsAllowed()) {
                                        $orConditions[] = "`$parts[1]` LIKE ".Db::get()->quote('object|%,'.$filterValueItem.',%');
                                    }
                                    if ($fieldDefinition->getDocumentsAllowed()) {
                                        $orConditions[] = "`$parts[1]` LIKE ".Db::get()->quote('document|%,'.$filterValueItem.',%');
                                    }
                                }
                                $listing->addConditionParam('('.implode(' OR ', $orConditions).')');
                            } else {
                                $orConditions = [];
                                foreach ($filterValueItems as $filterValueItem) {
                                    $orConditions[] = "`$parts[1]` LIKE ".Db::get()->quote('%,'.$filterValueItem.',%');
                                }
                                $listing->addConditionParam('('.implode(' OR ', $orConditions).')');

                            }
                        }
                    } elseif ($fieldDefinition instanceof Data\Multiselect) {
                        $orConditions = [];
                        foreach ($filterValueItems as $filterValueItem) {
                            $orConditions[] = "`$parts[1]` LIKE '%,".$filterValueItem.",%'";
                        }
                        $listing->addConditionParam('('.implode(' OR ', $orConditions).')');
                    } elseif ($fieldDefinition instanceof Data\QuantityValue) {
                        $valueParts = explode(' ', $parts[2]);
                        /** @var QuantityValue $keyValueItem */
                        $listing->addConditionParam('`'.$parts[1].'__value` = ?', $valueParts[0]);

                        if($valueParts[1]) {
                            $unit = Unit::getByAbbreviation($valueParts[1]);
                            if(!$unit instanceof Unit) {
                                $listing->addConditionParam('1=0');
                            }
                            $listing->addConditionParam('`'.$parts[1].'__unit` = ?', $unit->getId());
                        }
                    } else {
                        $filterValueItems = array_map(static function ($filterValueItem) use ($fieldDefinition) {
                            return $fieldDefinition->getDataForQueryResource($filterValueItem);
                        }, $filterValueItems);
                        if ($isLikeSearch) {
                            $likeSearchCondition = [];
                            $likeSearchParams = [];
                            foreach($filterValueItems as $filterValueItem) {
                                $likeSearchCondition[] = '`'.$parts[1].'` LIKE ?';
                                $likeSearchParams[] = preg_replace('/^\*|\*$/', '%', $filterValueItem);
                            }

                            $listing->addConditionParam('('.implode(' OR ', $likeSearchCondition).')', $likeSearchParams);
                        } else {
                            $listing->addConditionParam('`'.$parts[1].'` IN ('.rtrim(str_repeat('?,', count($filterValueItems)), ',').')', $filterValueItems);
                        }
                    }

                    $logger->debug(new LazyLog(static function() use ($type, $listing) {
                        return 'Trying to find target object(s) of class '.$type.' with condition '.$listing->getCondition().' and variables '.json_encode($listing->getConditionVariables(), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                    }));

                    $targetObject = new TypedArrayMapIterator($listing, $type);

                    $countTargetObjects = count($targetObject);
                    if($countTargetObjects === 0) {
                        $targetObject = null;
                    } elseif($countTargetObjects === 1) {
                        $targetObject->rewind();
                        $targetObject = $targetObject->current();
                    }
                }
            } catch (\Throwable $e) {
                $this->log(null, 'Error when searching a '.$type.' object with '.$parts[1].'="'.$parts[2].'", '.$e->getMessage(), 'alert');
                return null;
            }
        }

        if($targetObject === null) {
            $targetObjects = [];
            /** @var Concrete $item */
            $itemCacheIterator = new ResettableIterator($this->itemCache);
            foreach($itemCacheIterator as $item) {
                if($this->findInRelation([$item], $objectIdentifier) !== null) {
                    $targetObjects[] = $item;
                }
            }
            $itemCacheIterator->reset();

            if(count($targetObjects) > 1) {
                $targetObject = new TypedArrayMapIterator($targetObjects, $type);
            } elseif(count($targetObjects) > 0) {
                $targetObject = reset($targetObjects);
            }
        }

        if ($targetObject === null) {
            $logger->info('No object found for "'.$objectIdentifier.'"');
        } else {
            $logger->debug('Found '.(($targetObject instanceof TypedArrayMapIterator) ? count($targetObject) : '1').' elements');
        }

        if(isset($identifierParts[3])) {
            $resolved = $this->dataQuerySelectorResolver->resolve(implode(':', array_slice($identifierParts, 3)), $targetObject, false, $logger);

            if((is_array($resolved) || $resolved instanceof Countable) && is_array($targetObject) && count($resolved) === count($targetObject)) {
                reset($targetObject);
                foreach($resolved as &$resolvedItem) {
                    $currentContext = current($targetObject);
                    if(!$currentContext instanceof AbstractModel) {
                        $currentContext = $context;
                    }
                    $resolvedItem = $this->replaceObjectIdentifier($resolvedItem, $currentContext);
                    next($targetObject);
                }
                unset($resolvedItem);
                $targetObject = $resolved;
            } elseif ((is_array($resolved) || $resolved instanceof Countable) && $targetObject instanceof Countable && count($resolved) === count($targetObject)) {
                $targetObject->rewind();
                foreach ($resolved as &$resolvedItem) {
                    $currentContext = $targetObject->current();
                    if (!$currentContext instanceof AbstractModel) {
                        $currentContext = $context;
                    }
                    $resolvedItem = $this->replaceObjectIdentifier($resolvedItem, $currentContext);
                    $targetObject->next();
                }
                unset($resolvedItem);
                $targetObject = $resolved;
            } else {
                $targetObject = $this->replaceObjectIdentifier($resolved, $context);
            }
        }

        // do not add objects of the imported class to relation cache as their values could change
        if ($cacheable && isset($this->dataport['id']) && !$this->itemMoldBuilder->getItemMold($this->dataport['id']) instanceof $type) {
            if($targetObject instanceof ElementInterface && $targetObject->getId() > 0) {
                $this->addToRelationCache($objectIdentifier, ['type' => \OpenDxp\Model\Element\Service::getElementType($targetObject), 'id' => $targetObject->getId()]);
            } else {
                $this->addToRelationCache($objectIdentifier, $targetObject);
            }
        }

        if($targetObject instanceof TypedArrayMapIterator) {
            $targetObject = iterator_to_array($targetObject);
        }

        $logger->debug(new LazyLog(static function() use ($targetObject) {
            return 'Final result: '.self::getLogOutput($targetObject);
        }));

        if($debug) {
            return implode("\n", $logger->getLogs());
        }

        return $targetObject;
    }

    public function getObjectIdentifierParts($objectIdentifier, ?AbstractModel $context = null)
    {
        $parts = \str_getcsv($objectIdentifier, ':', '"');

        $countParts = \count($parts);
        if ($countParts < 3) {
            if ($countParts === 2 && substr($parts[1], 0 , 1) === '/') {
                $parts = [
                    $parts[0],
                    'path',
                    $parts[1],
                ];
            } else {
                return false;
            }
        }

        if ($parts[0] === '.') {
            if($context instanceof AbstractModel) {
                $parts[0] = get_class($context);
            } elseif($this->getTargetConfig() !== null) {
                $parts[0] = $this->getTargetConfig()['itemClass'];
            }
        }

        $parts[0] = $this->itemMoldBuilder->getClass($parts[0]);
        if ($parts[0] === null) {
            throw new \InvalidArgumentException('Class not found');
        }

        if (in_array($parts[1], Helper::getSystemFields(), true)) {
            $parts[1] = preg_replace('/^o_/', '', $parts[1]);
        }

        $fieldLowercase = strtolower($parts[1]);
        if ($fieldLowercase === 'path') {
            if (is_a($parts[0], Document::class, true)) {
                $elementType = 'document';
            } elseif (is_a($parts[0], Asset::class, true)) {
                $elementType = 'asset';
            } else {
                $elementType = 'object';
            }

            $objectPathArray = array_filter(explode('/', $parts[2]));
            $objectPathArray = array_map(static function ($value) use ($elementType) {
                if(strpos($value, '*') !== false) {
                    return $value;
                }
                return Service::getValidKey($value, $elementType);
            }, $objectPathArray);

            $parts[2] = (isset($objectPathArray[0]) && $objectPathArray[0] === '*' ? '' : '/').trim(implode('/', $objectPathArray), '/');
        } elseif (in_array($fieldLowercase, ['key', 'filename'], true)) {
            if (is_a($parts[0], Document::class, true)) {
                $elementType = 'document';
                $parts[1] = 'key';
            } elseif (is_a($parts[0], Asset::class, true)) {
                $elementType = 'asset';
                $parts[1] = 'filename';
            } else {
                $elementType = 'object';
                $parts[1] = 'key';
            }

            $parts[2] = Service::getValidKey($parts[2], $elementType);
        } elseif (preg_match('/[^\dA-Za-z_.:#]/', $parts[1]) && !is_a($parts[0], Asset::class, true)) {
            throw new InvalidArgumentException('"'.$objectIdentifier.'" is not a valid object identifier, filter field contains non-allowed character'.$parts[1]);
        } elseif ($parts[1] !== '.') {
            $filterFieldParts = explode('#', $parts[1]);
            $filterFieldParts[0] = preg_replace('/^getBy/i', '', $filterFieldParts[0]);

            $isValidField = method_exists($parts[0], 'get'.$filterFieldParts[0]);
            if (!$isValidField && is_a($parts[0], Asset::class, true)) {
                if(PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM assets_metadata WHERE name=? LIMIT 1', [$filterFieldParts[0]])) {
                    $isValidField = true;
                } elseif(OpenDxp\Model\Metadata\Predefined::getByName($filterFieldParts[0]) !== null) {
                    $isValidField = true;
                }
            } elseif(!$isValidField && is_a($parts[0], Concrete::class, true)) {
                $classDefinition = Helper::getClassDefinitionByName($parts[0]::classId());
                if($classDefinition instanceof ClassDefinition) {
                    $relationFieldParts = explode(':', $filterFieldParts[0]);
                    if(method_exists($parts[0], 'get'.$relationFieldParts[0])) {
                        $relationFieldDefinition = $classDefinition->getFieldDefinition($relationFieldParts[0]);
                        if($relationFieldDefinition instanceof Data\Relations\AbstractRelations && $relationFieldDefinition->getObjectsAllowed()) {
                            $allowedClasses = $relationFieldDefinition->getClasses();
                            if (!$allowedClasses) {
                                if (method_exists($relationFieldDefinition, 'getAllowedClassId')) {
                                    $allowedClasses = [['classes' => $relationFieldDefinition->getAllowedClassId()]];
                                } elseif (method_exists($relationFieldDefinition, 'getOwnerClassId')) {
                                    $allowedClasses = [['classes' => $relationFieldDefinition->getOwnerClassId()]];
                                }
                            }
                            foreach($allowedClasses as $allowedClass) {
                                $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                if ($allowedClass->getFieldDefinition($relationFieldParts[1]) instanceof Data) {
                                    $isValidField = true;
                                    break;
                                }

                                if(($relationFieldDefinition instanceof Data\AdvancedManyToManyRelation || $relationFieldDefinition instanceof Data\AdvancedManyToManyObjectRelation) && in_array($relationFieldParts[1], $relationFieldDefinition->getColumnKeys())) {
                                    $isValidField = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if (!$isValidField) {
                throw new InvalidArgumentException('"'.$objectIdentifier.'" is not a valid object identifier, filter field does not exist in class');
            }
        }

        $parts[2] = trim($parts[2]);

        return $parts;
    }

    public function findInRelation($value, $objectIdentifier) {
        try {
            $parts = $this->getObjectIdentifierParts($objectIdentifier);
            if ($parts === false) {
                $this->logger->error('Failed to parse data query selector "'.$objectIdentifier.'"');
                return null;
            }
        } catch(Exception $e) {
            $this->logger->error('Failed to parse data query selector "'.$objectIdentifier.'"');
            return null;
        }

        $type = $parts[0];
        $getterMethod = 'get'.ucfirst($parts[1]);
        if($getterMethod === 'getPath') {
            $getterMethod = 'getRealFullPath';
        }

        $localeHashPosition = strpos($getterMethod, '#');
        $locale = null;
        if($localeHashPosition !== false) {
            $locale = substr($getterMethod, $localeHashPosition+1);
            $getterMethod = substr($getterMethod, 0, $localeHashPosition);
        }

        foreach((array)$value as $index => $item) {
            if($item instanceof ElementMetadata || $item instanceof ObjectMetadata) {
                $item = $item->getElement();
            }

            if($item instanceof $type) {
                if(method_exists($item, $getterMethod)) {
                    $result = $item->$getterMethod($locale);
                    if (is_scalar($result) && trim(mb_strtolower((string)$result)) === mb_strtolower($parts[2])) {
                        return $index;
                    }

                    if(is_array($result)) {
                        foreach($result as $resultItem) {
                            if(is_scalar($resultItem) && trim(mb_strtolower((string)$resultItem)) === mb_strtolower($parts[2])) {
                                return $index;
                            }

                            if($resultItem instanceof ElementInterface && $resultItem->getId() == $parts[2]) {
                                return $index;
                            }
                        }
                    }
                }

                if(is_a($type, Asset::class, true) && !method_exists($item, $getterMethod)) {
                    $metaData = $item->getMetadata($parts[1]);

                    if(mb_strtolower(trim((string)$metaData)) === mb_strtolower((string)$parts[2])) {
                        return $index;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Generate all the possible combinations among a set of nested arrays.
     * @see https://gist.github.com/fabiocicerchia/4556892
     *
     * @param array $data  The entrypoint array container.
     * @param array $all   The final container (used internally).
     * @param array $group The sub container (used internally).
     * @param mixed $val   The value to append (used internally).
     * @param int   $i     The key index (used internally).
     */
    private static function generateCombinations(array $data, array &$all = array(), array $group = array(), $value = null, $i = 0,$key = null)
    {
        $keys = array_keys($data);
        if (isset($key)) {
            $group[$key] = $value;
        }
        if ($i >= count($data)) {
            $all[] = $group;
        } else {
            $currentKey = $keys[$i];
            $currentElement = $data[$currentKey];
            if(count($currentElement) === 0) {
                self::generateCombinations($data, $all, $group, null, $i + 1,$currentKey);
            } else {
                foreach ((array)$currentElement as $val) {
                    self::generateCombinations($data, $all, $group, $val, $i + 1,$currentKey);
                }
            }
        }
        return $all;
    }

    /**
     * @param Concrete|Asset $item
     *
     * @throws Exception
     */
    public function saveObject(AbstractModel $item, $immediateWrite = false)
    {
        if($item instanceof Concrete) {
            $item->setOmitMandatoryCheck(true);
        }

        $user = Helper::getUser();
        $item->setUserModification($user->getId());

        try {
            $cachedItem = $this->getCachedItem($item);

            $versionNote = $this->getVersionNote($item);

            $request = Helper::getRequest();
            $requestContext = $request->attributes->get(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT);
            $requestIsFrontendRequest = $request->attributes->get(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST);
            $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::CONTEXT_ADMIN);
            $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);
            $request->attributes->set('task', 'publish');

            if(($cachedItem['latestVersionData']['modificationDate'] ?? null) > $item->getModificationDate()) {
                $saveVersion = true;
                $itemCacheIterator = new ResettableIterator($this->itemCache);
                foreach ($itemCacheIterator as $otherItem) {
                    if ($item !== $otherItem && $item->getFullPath() === $otherItem->getFullPath() && !$this->isModified($item, $otherItem, null, false)) {
                        $this->log($item, 'Latest draft version and latest published version of '.\OpenDxp\Model\Element\Service::getElementType($item).' #'.$item->getId().' do not differ -> will not save new draft version', 'info');

                        $newPublishedVersionGotSaved = (bool)PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM versions WHERE ctype=? AND cid=? AND versionCount>?', [\OpenDxp\Model\Element\Service::getElementType($item), $item->getId(), $item->getVersionCount()]);
                        if(!$newPublishedVersionGotSaved) {
                            PimcoreDbRepository::getInstance()->execute('DELETE FROM versions WHERE ctype=? AND cid=? AND versionCount=?', [\OpenDxp\Model\Element\Service::getElementType($item), $item->getId(), $item->getVersionCount()]);
                        }

                        $saveVersion = false;
                    }
                }
                $itemCacheIterator->reset();

                if($saveVersion) {
                    $item->setModificationDate(time() + 2); // otherwise version will not be loaded as draft
                    if ($item instanceof OpenDxp\Model\Element\ElementDumpStateInterface) {
                        $item->setInDumpState(true);

                        if ($item instanceof Concrete) {
                            foreach ($item->getClass()->getFieldDefinitions() as $def) {
                                $getter = 'get'.ucfirst($def->getName());
                                if ($def instanceof Data\Objectbricks) {
                                    $value = $item->$getter();
                                    if (!$value instanceof Objectbrick) {
                                        continue;
                                    }
                                    foreach ($value->getBrickGetters() as $brickGetter) {
                                        $brick = $value->$brickGetter();
                                        if ($brick instanceof AbstractData) {
                                            $brick->setInDumpState(true);
                                        }
                                    }
                                } elseif ($def instanceof Data\Fieldcollections) {
                                    $value = $item->$getter();
                                    if (!$value instanceof Fieldcollection) {
                                        continue;
                                    }
                                    foreach ($value->getItems() as $fieldCollectionItem) {
                                        $fieldCollectionItem->setInDumpState(true);
                                    }
                                } elseif ($def instanceof Data\Localizedfields) {
                                    $value = $item->$getter();
                                    $value->setInDumpState(true);
                                }
                            }
                        }
                    }

                    PimcoreDbRepository::retry(function() use ($item, $versionNote) {
                        try {
                            $versionCount = $item->getDao()->getVersionCountForUpdate() + 1;
                        } catch (\Throwable $e) {
                            $versionCount = (int)PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('versionCount').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('id').' = ?', [$item->getId()]);
                            $versionCount2 = (int)PimcoreDbRepository::getInstance()->findOneInSql('SELECT MAX('.Helper::prefixObjectSystemColumn('versionCount').') FROM versions WHERE cid = ? AND ctype = \'object\'', [$item->getId()]);

                            $versionCount = max($versionCount, $versionCount2) + 1;
                        }

                        // in non-compatibility mode the object gets saved at the end of the script (and also its version) but the version count already gets calculated in saveConcrete() -> to get the draft version's versionCount > object's versionCount we have to add 2 here
                        if ($item instanceof Concrete && !($this->getTargetConfig()['compatibilityMode'] ?? true) && $item->getClassId() == $this->itemMoldBuilder->getItemMold($this->dataport['id'])->getClassId()) {
                            $versionCount++;
                        }

                        if ($versionCount > 4200000000) {
                            $versionCount = 1;
                        }

                        $item->setVersionCount($versionCount);
                        $item->saveVersion(false, false, $versionNote);
                    });

                    if ($item instanceof OpenDxp\Model\Element\ElementDumpStateInterface) {
                        $item->setInDumpState(false);
                    }
                    $this->log($item, 'Successfully saved new draft version for '.\OpenDxp\Model\Element\Service::getElementType($item).' #'.$item->getId().' '.$item->getRealFullPath(), 'info');
                }
            } else {
                $runtimeCacheKeyDeleteElement = 'delete element-'.spl_object_hash($item);
                if(RuntimeCache::isRegistered($runtimeCacheKeyDeleteElement) && RuntimeCache::get($runtimeCacheKeyDeleteElement)) {
                    if($item->getId()) {
                        OpenDxp\Model\Element\Recyclebin\Item::create($item, Helper::getUser());

                        $item->delete();
                        $this->log($item, 'Successfully deleted '.\OpenDxp\Model\Element\Service::getElementType($item).' #'.$item->getId().' '.$item->getRealFullPath(), 'info');
                    } else {
                        $this->log($item, $item->getRealFullPath().' did not exist and thus could not be deleted', 'info');
                    }
                } elseif($item instanceof Concrete && !($this->getTargetConfig()['compatibilityMode'] ?? true) && $item->getClassId() == $this->itemMoldBuilder->getItemMold($this->dataport['id'])->getClassId()) {
                    $this->saveConcrete($item);
                } else {
                    $isUpdate = !empty($item->getId());
                    try {
                        if (method_exists($this->logger, 'disablePimcoreLogger')) {
                            $this->logger->disablePimcoreLogger();
                        }

                        $item->setModificationDate(time()+1);
                        $item->save(['versionNote' => $versionNote]);

                        if (method_exists($this->logger, 'enablePimcoreLogger')) {
                            $this->logger->enablePimcoreLogger();
                        }
                    } catch(ConnectionException $e) {
                        if (method_exists($this->logger, 'disablePimcoreLogger')) {
                            $this->logger->disablePimcoreLogger();
                        }

                        $item->save(['versionNote' => $versionNote]);

                        if (method_exists($this->logger, 'enablePimcoreLogger')) {
                            $this->logger->enablePimcoreLogger();
                        }
                    }

                    $versionId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM versions WHERE ctype=? AND cid=? ORDER BY versionCount DESC LIMIT 1', [\OpenDxp\Model\Element\Service::getElementType($item), $item->getId()]);
                    $this->log($item, 'Successfully '.($isUpdate ? 'saved' : 'created').' '.\OpenDxp\Model\Element\Service::getElementType($item).' #'.$item->getId().' '.$item->getRealFullPath().' (version #'.$versionId.')', 'info');

                    $runtimeCacheKeyTags = 'tags-'.spl_object_hash($item);
                    if(RuntimeCache::isRegistered($runtimeCacheKeyTags)) {
                        $itemTags = RuntimeCache::get($runtimeCacheKeyTags);
                        Db::get()->delete('tags_assignment', [
                            'ctype' => \OpenDxp\Model\Element\Service::getElementType($item),
                            'cid' => $item->getId()
                        ]);

                        foreach ($itemTags as $tag) {
                            Tag::addTagToElement(\OpenDxp\Model\Element\Service::getElementType($item), $item->getId(), $tag);
                        }
                    }

                    $runtimeCacheKeySeoBundle = 'seoBundle'.spl_object_hash($item);
                    if (RuntimeCache::isRegistered($runtimeCacheKeySeoBundle)) {
                        $seoMetaDataController = \OpenDxp::getContainer()->get(\SeoBundle\Controller\Admin\MetaDataController::class);
                        $request->request->set('elementType', Service::getElementType($item));
                        $request->request->set('elementId', $item->getId());
                        $request->request->set('integratorValues', json_encode(RuntimeCache::get($runtimeCacheKeySeoBundle)));
                        $seoMetaDataController->setElementMetaDataConfigurationAction($request);
                    }
                }
            }

            $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, $requestContext);
            $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, $requestIsFrontendRequest);

            $this->setCachedItem($item, ['save' => false]);
        } catch (\Throwable $e) {
            if($e instanceof Exception && (strpos($e->getMessage(), 'Duplicate full path') !== false || strpos($e->getMessage(), 'Integrity constraint violation: 1062 Duplicate entry') !== false)) {
                $duplicate = \OpenDxp\Model\Element\Service::getElementByPath(\OpenDxp\Model\Element\Service::getElementType($item), $item->getRealFullPath());
                if($duplicate instanceof ElementInterface && $duplicate->getId() == $item->getId()) {
                    throw $e;
                }

                // when importing children before parents in hierarchical object imports, children's parents are created as folders. When the real parent object gets imported later the path is already taken by the folder
                if($item instanceof Concrete && ($duplicate instanceof Folder || ($duplicate instanceof ElementInterface && $duplicate->getProperty('auto_generated')))) {
                    $children = $duplicate->getChildren(
                        [
                            AbstractObject::OBJECT_TYPE_OBJECT,
                            AbstractObject::OBJECT_TYPE_FOLDER,
                            AbstractObject::OBJECT_TYPE_VARIANT
                        ],
                        true
                    );
                    /** @var Concrete $child */
                    foreach ($children as $child) {
                        $child->setParent($this->getItemFolder());
                        $child->save(['versionNote' => $versionNote]);
                    }
                    $duplicate->delete();

                    $item->save();
                    foreach ($children as $child) {
                        $child->setParent($item);
                        $child->save(['versionNote' => $versionNote]);
                    }
                } else {
                    $fieldMappingTable = Fieldmapping::getInstance();

                    $keyFields = $fieldMappingTable->find(
                        [
                            'dataportId = ?' => $this->dataport['id'],
                            'keyMapping = ?' => 1
                        ]
                    );

                    if(!$this->isModified($item, $duplicate, array_map([Helper::class, 'getFieldKey'], $keyFields), false)) {
                        if($item->getId() > 0) {
                            $this->log($item, 'Element deleted because of full path collision with other element which has the same key fields and thus got updated with same data.', 'info');

                            if ($item instanceof Asset) {
                                $item->delete(true);
                            } else {
                                $item->delete();
                            }
                        }
                    } else {
                        $additionalDetails = [];

                        if ($e instanceof ValidationException) {
                            /** @var Exception $subItem */
                            foreach ($e->getSubItems() as $subItem) {
                                $additionalDetails[] = $subItem->getMessage();
                            }
                        }

                        $this->log(
                            $item,
                            'Object #' . $item->getId() . ' could not be saved. Reverted changes. Error: ' . $e . ' '
                            . \implode(', ', $additionalDetails),
                            'error'
                        );
                    }
                }
            } elseif($e instanceof RetryableException) {
                throw $e;
            } else {
                $additionalDetails = [];

                if ($e instanceof ValidationException) {
                    /** @var Exception $subItem */
                    foreach ($e->getSubItems() as $subItem) {
                        $additionalDetails[] = $subItem->getMessage();
                    }
                }

                $this->log($item, 'Object #'.$item->getId().' could not be saved. Reverted changes. Error: '.$e.' '
                    .\implode(', ', $additionalDetails), 'error');
            }
        }

        if($immediateWrite) {
            try {
                $this->writeBuffer();
            } catch(\Exception $e) {
                RuntimeCache::clear();
                $element = OpenDxp\Model\Element\Service::getElementByPath($item->getRealFullPath(), OpenDxp\Model\Element\Service::getElementType($item));
                if($element) {
                    $item->setId($element->getId());
                } else {
                    throw $e;
                }
            }
        }
    }

    public function __destruct() {
        $this->writeBuffer();

        if($this->pruneCacheIds) {
            Cache::clearTags($this->pruneCacheIds);
        }
    }

    private function writeBuffer() {
        if(empty($this->writeBuffer)) {
            return;
        }

        $writeBuffer = $this->writeBuffer;
        $this->writeBuffer = [];

        $itemIDs = [];
        $versionIds = [];
        try {
            PimcoreDbRepository::retry(function() use (&$itemIDs, &$versionIds, $writeBuffer) {
                $itemIDs = [];
                $versionIds = [];

                foreach (array_merge_recursive(...array_column($writeBuffer, 'queries')) as $table => $data) {
                    $insertData = [];
                    foreach ($data as $keyData => $objectData) {
                        $row = [];
                        $keyData = explode(' AND ', $keyData);
                        foreach ($keyData as $keyItem) {
                            $firstEqualPosition = strpos($keyItem, '=');
                            if ($firstEqualPosition !== false) {
                                $row[substr($keyItem, 0, $firstEqualPosition)] = substr($keyItem, $firstEqualPosition + 1);
                            } else {
                                $isNullPosition = strpos($keyItem, ' IS NULL');
                                $row[substr($keyItem, 0, $isNullPosition)] = null;
                            }
                        }

                        foreach ($objectData as $field => $value) {
                            $row[$field] = $value;
                        }
                        $insertData[] = $row;
                    }

                    try {
                        $retried = false;
                        executeQuery:
                        PimcoreDbRepository::getInstance()->createOrUpdate($insertData, $table);
                    } catch (\Throwable $e) {
                        if (!$retried && strpos($e->getMessage(), 'Base table or view not found') !== false) {
                            $compatibilityMode = $this->getTargetConfig()['compatibilityMode'];
                            $this->targetConfig['compatibilityMode'] = true;
                            $this->saveObject(reset($writeBuffer)['item']);
                            $this->targetConfig['compatibilityMode'] = $compatibilityMode;
                            $retried = true;
                            goto executeQuery;
                        } elseif (strpos($e->getMessage(), 'Duplicate full path') !== false || strpos($e->getMessage(), 'Integrity constraint violation: 1062 Duplicate entry') !== false) {
                            foreach ($insertData as $row) {
                                try {
                                    PimcoreDbRepository::getInstance()->createOrUpdate([$row], $table);
                                } catch (\Exception $e) {
                                    if ($table === 'objects' && (strpos($e->getMessage(), 'Duplicate full path') !== false || strpos($e->getMessage(), 'Integrity constraint violation: 1062 Duplicate entry') !== false)) {
                                        foreach ($writeBuffer as $writeBufferItem) {
                                            if ($writeBufferItem['item']->getId() === $row[Helper::prefixObjectSystemColumn('id')]) {
                                                $suffix = 1;
                                                $parentPath = rtrim($writeBufferItem['item']->getRealPath(), '/');
                                                do {
                                                    $targetKey = $row[Helper::prefixObjectSystemColumn('key')].'_'.$suffix;
                                                    $intendedPath = $parentPath.'/'.$targetKey;
                                                    $suffix++;

                                                    try {
                                                        self::getElementIdForPath($intendedPath, 'object');
                                                        $pathExists = true;
                                                    } catch (Exception $e) {
                                                        $pathExists = $this->pathExistsInCache($intendedPath);
                                                    }
                                                } while ($pathExists);

                                                $writeBufferItem['item']->setKey($targetKey);
                                                $writeBufferItem['item']->save();
                                                $this->log($writeBufferItem['item'], 'Prevented full path collision for '.$row[Helper::prefixObjectSystemColumn('path')].$row[Helper::prefixObjectSystemColumn('key')], 'info');
                                            }
                                        }
                                    } else {
                                        $columns = PimcoreDbRepository::getInstance()->findInSql('SHOW COLUMNS FROM '.$table);
                                        $primaryKeyColumns = [];
                                        foreach ($columns as $column) {
                                            if ($column['Key'] === 'PRI') {
                                                $primaryKeyColumns[] = $column['Field'];;
                                            }
                                        }

                                        if ($primaryKeyColumns) {
                                            $allPrimaryColumnsProvided = true;
                                            foreach ($primaryKeyColumns as $primaryKeyColumn) {
                                                if (!isset($row[$primaryKeyColumn])) {
                                                    $allPrimaryColumnsProvided = false;
                                                    break;
                                                }
                                            }

                                            if ($allPrimaryColumnsProvided) {
                                                $primaryKeyValues = [];
                                                foreach ($row as $column => $value) {
                                                    if (in_array($column, $primaryKeyColumns, true)) {
                                                        $primaryKeyValues[$column] = $value;
                                                    }
                                                }

                                                foreach ($row as $column => $value) {
                                                    if (in_array($column, $primaryKeyColumns, true)) {
                                                        continue;
                                                    }

                                                    try {
                                                        PimcoreDbRepository::getInstance()->createOrUpdate(array_merge($primaryKeyValues, [$column => $value]), $table);
                                                    } catch (\Throwable $e) {
                                                        $this->log(null, 'Field '.$column.'='.$value.' could not be saved for '.json_encode($primaryKeyValues, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).': '.$e->getMessage(), 'error');
                                                    }
                                                }
                                            } else {
                                                $this->log(null, 'Could not save '.json_encode($row, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).': '.$e->getMessage(), 'error');
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $dependencyInserts = [];
                foreach ($writeBuffer as $writeBufferItem) {
                    foreach ($writeBufferItem['dependencies']['added'] as $dependency) {
                        $dependencyInserts[] = ['sourcetype' => 'object', 'sourceid' => $writeBufferItem['item']->getId(), 'targettype' => $dependency['type'], 'targetid' => $dependency['id']];
                    }

                    foreach ($writeBufferItem['dependencies']['removed'] as $dependency) {
                        PimcoreDbRepository::getInstance()->execute('DELETE FROM dependencies WHERE sourcetype=? AND sourceid=? AND targettype=? AND targetid=?', ['object', $writeBufferItem['item']->getId(), $dependency['type'], $dependency['id']]);
                    }

                    $itemIDs[] = $writeBufferItem['item']->getId();

                    $itemReflection = new ReflectionObject($writeBufferItem['item']);
                    try {
                        $rawRelationDataProperty = $itemReflection->getProperty('__rawRelationData');
                        $rawRelationDataProperty->setAccessible(true);
                        $rawRelationDataProperty->setValue($writeBufferItem['item'], null);
                    } catch(ReflectionException $e) {
                    }
                }

                PimcoreDbRepository::getInstance()->createOrUpdate($dependencyInserts, 'dependencies');
            });

            if (!method_exists(Version::class, 'isEnabled') || Version::isEnabled()) {
                try {
                    $objectsConfig = Helper::getPimcoreSystemConfiguration('objects');
                } catch (\Exception $e) {
                    $objectsConfig = [];
                }

                if ((is_null($objectsConfig['versions']['days'] ?? null) && is_null($objectsConfig['versions']['steps'] ?? null)) || !empty($objectsConfig['versions']['steps']) || !empty($objectsConfig['versions']['days'])) {
                    foreach ($writeBuffer as $item) {
                        try {
                            $versionNote = $this->getVersionNote($item['item']);

                            if ($item['item'] instanceof OpenDxp\Model\Element\ElementDumpStateInterface) {
                                $item['item']->setInDumpState(true);

                                foreach ($item['item']->getClass()->getFieldDefinitions() as $def) {
                                    $getter = 'get'.ucfirst($def->getName());
                                    if ($def instanceof Data\Objectbricks) {
                                        $value = $item['item']->$getter();
                                        if (!$value instanceof Objectbrick) {
                                            continue;
                                        }
                                        foreach ($value->getBrickGetters() as $brickGetter) {
                                            $brick = $value->$brickGetter();
                                            if ($brick instanceof AbstractData) {
                                                $brick->setInDumpState(true);
                                            }
                                        }
                                    } elseif ($def instanceof Data\Fieldcollections) {
                                        $value = $item['item']->$getter();
                                        if (!$value instanceof Fieldcollection) {
                                            continue;
                                        }
                                        foreach ($value->getItems() as $fieldCollectionItem) {
                                            $fieldCollectionItem->setInDumpState(true);
                                        }
                                    } elseif ($def instanceof Data\Localizedfields) {
                                        $value = $item['item']->$getter();
                                        $value->setInDumpState(true);
                                    }
                                }
                            }

                            $version = null;
                            PimcoreDbRepository::retry(static function () use ($item, $versionNote, &$version) {
                                $version = $item['item']->saveVersion(false, false, $versionNote);
                            });

                            if ($item['item'] instanceof OpenDxp\Model\Element\ElementDumpStateInterface) {
                                $item['item']->setInDumpState(false);
                            }

                            $versionIds[$item['item']->getId()] = $version->getId();
                        } catch (\Throwable $e) {
                            $this->log($item['item'], 'Could not create new version: '.$e, 'warning');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $itemIDs = [];
            foreach ($writeBuffer as $item) {
                $itemIDs[] = $item['item']->getId();
            }

            PimcoreDbRepository::getInstance()->execute('DELETE FROM objects WHERE '.Helper::prefixObjectSystemColumn('id').' IN (?) AND '.Helper::prefixObjectSystemColumn('type').' IS NULL', [$itemIDs]);

            throw $e;
        }

        if(count($itemIDs) > 0) {
            $cacheTags = ['object_properties'];
            foreach ($itemIDs as $itemID) {
                $cacheTags[] = 'object_'.$itemID;
            }

            Cache::clearTags($cacheTags);
        }

        foreach ($writeBuffer as $item) {
            $postUpdateEvent = new DataObjectEvent($item['item'], [
                'saveVersionOnly' => false,
                'isAutoSave' => false,
                'dataportId' => $this->dataport['id'],
            ]);

            try {
                if ($item['isUpdate']) {
                    \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($postUpdateEvent, DataObjectEvents::POST_UPDATE);
                } else {
                    \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($postUpdateEvent, DataObjectEvents::POST_ADD);
                }
            } catch(\Throwable $e) {
                $this->log(
                    $item['item'],
                    'Handling '.($item['isUpdate'] ? DataObjectEvents::POST_UPDATE : DataObjectEvents::POST_ADD).' event caused error: '.$e->getMessage(),
                    'warning'
                );
            }

            if ($item['isUpdate']) {
                $logMessage = 'Successfully saved ';
            } else {
                $logMessage = 'Successfully created ';
            }

            $elementType = Service::getElementType($item['item']);
            $this->log($item['item'], $logMessage .(in_array($elementType, ['asset', 'document'], true) ? $elementType : $item['item']->getClassName()) . ' #' . $item['item']->getId() . ' ' . $item['item']->getRealFullPath() . (!empty($versionIds[$item['item']->getId()]) ? ' (version #' . $versionIds[$item['item']->getId()] . ')' : ''), 'info');
        }
    }

    private function saveConcrete(Concrete $item)
    {
        $queries = [];
        $user = Helper::getUser();
        $userId = $user->getId();

        $inheritanceEnabled = Concrete::getGetInheritedValues();
        Helper::useInheritance(false);
        $isNew = !$item->getId() || $this->isCompleteObjectImport();
        if ($isNew) {
            \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch(new DataObjectEvent($item, [
                'saveVersionOnly' => false,
                'isAutoSave' => false,
                'dataportId' => $this->dataport['id'],
            ]), DataObjectEvents::PRE_ADD);

            if (!OpenDxp\Model\Element\Service::isValidKey($item->getKey(), OpenDxp\Model\Element\Service::getElementType($item))) {
                throw new \Exception('invalid key for object "'.$item->getRealFullPath().'": '.$item->getKey().'');
            }

            if (strlen($item->getKey()) < 1) {
                throw new \Exception('DataObject requires key');
            }

            if (PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.OpenDxp\Model\Element\Service::getElementType($item).'s WHERE '.Helper::prefixObjectSystemColumn('path').'=? AND `'.Helper::prefixObjectSystemColumn('key').'`=? AND '.Helper::prefixObjectSystemColumn('id').'!=?', [$item->getPath(), $item->getKey(), $item->getId()])) {
                throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
            }

            foreach($this->writeBuffer as $writeBufferItem) {
                if($writeBufferItem['item']->getFullPath() === $item->getFullpath()) {
                    throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
                }
            }

            // objects fields already have to be set because CustomResourcePersistingInterface may need it
            $objectsData = [
                Helper::prefixObjectSystemColumn('key') => $item->getKey(),
                Helper::prefixObjectSystemColumn('path') => $item->getRealPath(),
                Helper::prefixObjectSystemColumn('type') => 'object',
                Helper::prefixObjectSystemColumn('classId') => $item->getClassId(),
                Helper::prefixObjectSystemColumn('creationDate') => time(),
                Helper::prefixObjectSystemColumn('userOwner') => $user instanceof User ? $user->getId() : 0,
                Helper::prefixObjectSystemColumn('parentId') => $item->getParentId(),
                Helper::prefixObjectSystemColumn('published') => (int)$item->getPublished(),
                Helper::prefixObjectSystemColumn('className') => $item->getClassName(),
                Helper::prefixObjectSystemColumn('childrenSortBy') => $item->getChildrenSortBy(),
            ];
            if (method_exists($item, 'getChildrenSortOrder')) {
                $objectsData[Helper::prefixObjectSystemColumn('childrenSortOrder')] = $item->getChildrenSortOrder();
            }

            if ($item->getId()) {
                $objectsData[Helper::prefixObjectSystemColumn('id')] = $item->getId();
            }

            PimcoreDbRepository::getInstance()->createOrUpdate($objectsData, 'objects');
            $item->setId((int)Db::get()->lastInsertId());

            if(!$item->getId()) {
                throw new \Exception('Could not create id for object '.$item->getFullpath());
            }

            PimcoreDbRepository::getInstance()->createOrUpdate(['oo_id' => $item->getId()], 'object_query_'.$item->getClassId());
            PimcoreDbRepository::getInstance()->createOrUpdate(['oo_id' => $item->getId()], 'object_store_'.$item->getClassId());
        } else {
            \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch(new DataObjectEvent($item, [
                'saveVersionOnly' => false,
                'isAutoSave' => false,
                'dataportId' => $this->dataport['id'],
            ]), DataObjectEvents::PRE_UPDATE);
        }

        $item->setModificationDate(time()+1);

        $systemFields = Helper::getSystemFields();
        $pathUpdated = false;

        $classInheritanceEnabled = $item->getClass()->getAllowInherit();

        $objectChanged = false;
        $dependencies = ['added' => [], 'removed' => []];

        foreach ($this->getMappings() as $mapping) {
            if($mapping['fieldName'] === 'delete element') {
                continue;
            }

            try {
                $updatableObject = self::getUpdatableObject($item, $mapping);
            } catch (\Exception $e) {
                continue;
            }

            $fieldDefinition = self::getFieldDefinition($updatableObject, $mapping);

            if($fieldDefinition->getLocked()) {
                continue;
            }

            $value = null;
            $value2 = null;

            $getterArgs = [];
            if (!empty($mapping['locale'])) {
                $getterArgs[] = $mapping['locale'];
            }

            $value = self::getValue($updatableObject, $mapping, $getterArgs);
            $value2 = $this->getLatestVersionData($item, Helper::getFieldKey($mapping));

            if (!$this->isEqual($fieldDefinition, $value, $value2)) {
                if(!$objectChanged) {
                    $this->log($item, 'Saving #'.$item->getId().' '.$item->getRealFullPath(), 'info');
                }

                if ($mapping['fieldName'] !== SelectMapper::VIRTUAL_WORKFLOW_FIELD) {
                    $objectChanged = true;
                }
                $this->log($item, 'Field "'.Helper::getFieldKey($mapping).'": Value changed', 'info');

                if (in_array(Helper::prefixObjectSystemColumn($mapping['fieldName']), $systemFields, true)) {
                    $queries['objects'][Helper::prefixObjectSystemColumn('id').'='.$item->getId()][Helper::prefixObjectSystemColumn($mapping['fieldName'])] = $value;

                    if ($mapping['fieldName'] === 'key') {
                        if (!OpenDxp\Model\Element\Service::isValidKey($item->getKey(), OpenDxp\Model\Element\Service::getElementType($item))) {
                            throw new \Exception('invalid key for object with id [ '.$item->getId().' ] key is: ['.$item->getKey().']');
                        }

                        if ((string)$item->getKey() === '') {
                            throw new \Exception('DataObject requires key');
                        }

                        if (PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.OpenDxp\Model\Element\Service::getElementType($item).'s WHERE '.Helper::prefixObjectSystemColumn('path').'=? AND `'.Helper::prefixObjectSystemColumn('key').'`=? ANd '.Helper::prefixObjectSystemColumn('id').'!=?', [$item->getPath(), $item->getKey(), $item->getId()])) {
                            throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
                        }

                        foreach ($this->writeBuffer as $writeBufferItem) {
                            if ($writeBufferItem['item']->getFullPath() === $item->getFullpath()) {
                                throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
                            }
                        }

                        $pathUpdated = true;
                    } elseif ($mapping['fieldName'] === 'path') {
                        if ($item->getParentId() == $item->getId()) {
                            throw new \Exception("ParentID and ID is identical, an element can't be the parent of itself.");
                        }

                        if (PimcoreDbRepository::getInstance()->findOneInSql(
                            'SELECT 1 FROM '.OpenDxp\Model\Element\Service::getElementType($item).'s WHERE '.Helper::prefixObjectSystemColumn('path').'=? AND `'.Helper::prefixObjectSystemColumn('key').'`=? AND '.Helper::prefixObjectSystemColumn('id').'!=?',
                            [$item->getPath(), $item->getKey(), $item->getId()]
                        )) {
                            throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
                        }

                        foreach ($this->writeBuffer as $writeBufferItem) {
                            if ($writeBufferItem['item']->getFullPath() === $item->getFullpath()) {
                                throw new \Exception('Duplicate full path [ '.$item->getRealFullPath().' ] - cannot save object');
                            }
                        }

                        $queries['objects'][Helper::prefixObjectSystemColumn('id').'='.$item->getId()][Helper::prefixObjectSystemColumn('parentId')] = $item->getParentId();

                        $pathUpdated = true;
                    }

                    continue;
                }

                if ($mapping['fieldName'] === 'properties') {
                    PimcoreDbRepository::getInstance()->execute('DELETE FROM properties WHERE cid=? AND ctype=?', [$item->getId(), OpenDxp\Model\Element\Service::getElementType($item)]);
                    foreach ((array)$value as $property) {
                        if (!$property->getInherited()) {
                            $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['type'] = $property->getType();
                            $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['cpath'] = $item->getRealFullPath();
                            $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['inheritable'] = (int)$property->getInheritable();

                            $propertyData = $property->getData();
                            if (in_array($property->getType(), ['object', 'asset', 'document'], true)) {
                                if ($propertyData instanceof ElementInterface) {
                                    $propertyData = $propertyData->getId();
                                } else {
                                    $propertyData = null;
                                }
                            }
                            $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['data'] = $propertyData;
                        }
                    }
                    continue;
                }

                if ($mapping['fieldName'] === 'tags') {
                    PimcoreDbRepository::getInstance()->execute('DELETE FROM tags_assignment WHERE cid=? AND ctype=?', [$item->getId(), OpenDxp\Model\Element\Service::getElementType($item)]);
                    /** @var Tag $tag */
                    foreach ((array)$value as $tag) {
                        $queries['tags_assignment']['cid='.$item->getId().' AND ctype=object AND tagid='.$tag->getId()]['tagid'] = $tag->getId();
                    }
                    continue;
                }
                if (in_array($mapping['fieldName'], ['seoBundle.title', 'seoBundle.description'], true)) {
                    $runtimeCacheKeySeoBundle = 'seoBundle' . spl_object_hash($item);
                    $cacheSeoData = RuntimeCache::get($runtimeCacheKeySeoBundle);

                    $seoMetaDataController = \OpenDxp::getContainer()->get(\SeoBundle\Controller\Admin\MetaDataController::class);
                    $request = Helper::getRequest();
                    $request->request->set('elementType', Service::getElementType($item));
                    $request->request->set('elementId', $item->getId());
                    $request->request->set('integratorValues', json_encode($cacheSeoData));
                    $seoMetaDataController->setElementMetaDataConfigurationAction($request);
                    continue;
                }

                if ($mapping['fieldName'] === SelectMapper::VIRTUAL_WORKFLOW_FIELD) {
                    $targetTransition = RuntimeCache::get(SelectMapper::VIRTUAL_WORKFLOW_FIELD.'-'.spl_object_hash($item));
                    if ($targetTransition) {
                        $workflowManager = \OpenDxp::getContainer()->get(OpenDxp\Workflow\Manager::class);

                        if ( strpos($targetTransition, '.') !== false ) {
                            [$workflowName, $targetTransition] = explode('.', $targetTransition);
                            /**
                             * @var Workflow $workflow
                             */
                            $workflow = $workflowManager->getWorkflowByName($workflowName);
                        } else {
                            /**
                             * @var Workflow $workflow
                             */
                            $workflow = $workflowManager->getAllWorkflowsForSubject($item)[0];
                        }

                        if ($workflow && $workflow->can($item, $targetTransition)) {
                            $workflow->apply($item, $targetTransition);
                            $this->log($item, 'Applied workflow transition "'.$targetTransition.'"', 'info');
                        } else {
                            $currentStates = array_keys($workflow->getMarking($item)->getPlaces());
                            $this->log($item, 'Cannot apply workflow transition "' .$targetTransition . '" from current object state "'.implode(', ', $currentStates).'"', 'info');
                        }
                    }

                    continue;
                }

                if ($fieldDefinition instanceof Data\CustomResourcePersistingInterface) {
                    // for fieldtypes which have their own save algorithm eg. fieldcollections, relational data-types, ...
                    $saveParams = [
                        'isUntouchable' => false,
                        'isUpdate' => true,
                        'context' => [
                            'containerType' => 'object',
                        ],
                        'owner' => $updatableObject,
                        'fieldname' => $fieldDefinition->getName(),
                        'forceSave' => true
                    ];

                    $saveObject = $updatableObject;
                    if ($updatableObject instanceof Fieldcollection\Data\AbstractData) {
                        if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                            PimcoreDbRepository::getInstance()->execute('DELETE FROM object_relations_'.$item->getClassId().' WHERE src_id=? AND ownertype=\'fieldcollection\' AND ownername=? AND fieldname=?', [$item->getId(), $updatableObject->getFieldname(), $fieldDefinition->getName()]
                            );
                        }

                        $saveParams['context']['containerType'] = 'fieldcollection';
                        $saveParams['context']['containerKey'] = $updatableObject->getType();
                        $saveParams['context']['fieldname'] = $updatableObject->getFieldname();
                    } elseif($updatableObject instanceof AbstractData) {
                        $queries['object_brick_store_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()][Helper::prefixObjectSystemColumn('id')] = $item->getId();
                        $queries['object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()][Helper::prefixObjectSystemColumn('id')] = $item->getId();

                        if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                            PimcoreDbRepository::getInstance()->execute(
                                'DELETE FROM object_relations_'.$item->getClassId().' WHERE src_id=? AND fieldname=? AND ownertype=\'objectbrick\' AND ownername=? AND position=?',
                                [$item->getId(), $fieldDefinition->getName(), $updatableObject->getFieldname(), $updatableObject->getType()]
                            );
                        }

                        $saveParams['context']['containerType'] = 'objectbrick';
                        $saveParams['context']['containerKey'] = $updatableObject->getType();
                        $saveParams['context']['fieldname'] = $updatableObject->getFieldname();
                    }

                    if (!empty($mapping['locale'])) {
                        $saveObject = $updatableObject->getLocalizedfields();

                        $saveParams['language'] = $mapping['locale'];
                        $saveParams['context']['containerType'] = 'localizedfield';
                        $saveParams['context']['containerKey'] = 'localizedfield';
                        $saveParams['context']['position'] = $mapping['locale'];
                    }

                    if ($item instanceof DirtyIndicatorInterface) {
                        $saveParams['newParent'] = $item->isFieldDirty(Helper::prefixObjectSystemColumn('parentId'));
                    }

                    $fieldDefinition->save($saveObject, $saveParams);
                }

                if ($fieldDefinition instanceof Data\ResourcePersistenceAwareInterface) {
                    $storeData = [];

                    $saveParams = [
                        'isUpdate' => true,
                        'owner' => $updatableObject,
                        'fieldname' => $fieldDefinition->getName(),
                    ];

                    if ($updatableObject instanceof AbstractData) {
                        $saveParams['context']['containerType'] = 'objectbrick';
                        $saveParams['context']['containerKey'] = $updatableObject->getType();
                        $saveParams['context']['fieldname'] = $updatableObject->getFieldname();
                    }

                    if (is_array($fieldDefinition->getColumnType())) {
                        $insertDataArray = $fieldDefinition->getDataForResource(
                            $value,
                            $item,
                            $saveParams
                        );
                        if (is_array($insertDataArray)) {
                            $storeData = array_merge($storeData, $insertDataArray);
                        }
                    } else {
                        $insertData = $fieldDefinition->getDataForResource(
                            $value,
                            $item,
                            $saveParams
                        );
                        $storeData[$fieldDefinition->getName()] = $insertData;
                    }

                    foreach ($storeData as $storeColumn => $storeValue) {
                        if (empty($mapping['locale'])) {
                            if ($updatableObject instanceof AbstractData) {
                                $queries['object_brick_store_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()][$storeColumn] = $storeValue;
                            } elseif ($updatableObject instanceof DataObject\Classificationstore) {
                                $updatableObject->save();
                            } else {
                                $queries['object_store_'.$item->getClassId()]['oo_id='.$item->getId()][$storeColumn] = $storeValue;
                            }
                        } else {
                            if ($updatableObject instanceof AbstractData) {
                                $queries['object_brick_store_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()]['fieldname'] = $updatableObject->getFieldname();

                                $queries['object_brick_localized_'.$updatableObject->getType().'_'.$item->getClassId()]['ooo_id='.$item->getId().' AND language='.$mapping['locale'].' AND fieldName='.$mapping['targetBrickField']][$storeColumn] = $storeValue;
                            } elseif($updatableObject instanceof DataObject\Classificationstore) {
                                $updatableObject->save();
                            } else {
                                $queries['object_localized_data_'.$item->getClassId()]['ooo_id='.$item->getId().' AND language='.$mapping['locale']][$storeColumn] = $storeValue;
                            }
                        }
                    }
                }

                if ($fieldDefinition instanceof QueryResourcePersistenceAwareInterface && (!$updatableObject instanceof DataObject\Classificationstore || $fieldDefinition instanceof Data\CalculatedValue)) {
                    $insertData = $fieldDefinition->getDataForQueryResource(
                        $value,
                        $item,
                        [
                            'isUpdate' => true,
                            'owner' => $item,
                            'fieldname' => $fieldDefinition->getName(),
                        ]
                    );

                    if (is_array($insertData)) {
                        $columnNames = array_keys($insertData);
                    } else {
                        $columnNames = [$fieldDefinition->getName()];
                        $insertData = [$fieldDefinition->getName() => $insertData];
                    }

                    $isEmpty = $fieldDefinition->isEmpty($value);

                    $queryData = [];
                    // if the current value is empty and we have data from the parent, we just use it
                    if ($isEmpty && $classInheritanceEnabled) {
                        $parentData = [];
                        if ($fieldDefinition->supportsInheritance()) {
                            // get the next suitable parent for inheritance
                            $parentForInheritance = $item->getNextParentForInheritance();
                            if ($parentForInheritance) {
                                // we don't use the getter (built in functionality to get inherited values) because we need to avoid race conditions
                                // we cannot DataObject::setGetInheritedValues(true); and then $this->model->$method();
                                // so we select the data from the parent object using FOR UPDATE, which causes a lock on this row
                                // so the data of the parent cannot be changed while this transaction is on progress

                                if (empty($mapping['locale'])) {
                                    if ($updatableObject instanceof AbstractData) {
                                        $parentData = PimcoreDbRepository::getInstance()->findInSql('SELECT `'.implode('`,`', $columnNames).'` FROM object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId().' WHERE '.Helper::prefixObjectSystemColumn('id').' = ? FOR UPDATE', [$parentForInheritance->getId()])[0] ?? $insertData;
                                    } else {
                                        $parentData = PimcoreDbRepository::getInstance()->findInSql('SELECT `'.implode('`,`', $columnNames).'` FROM object_query_'.$item->getClassId().' WHERE oo_id = ? FOR UPDATE', [$parentForInheritance->getId()])[0] ?? $insertData;
                                    }
                                } else {
                                    if ($updatableObject instanceof AbstractData) {
                                        $parentData = PimcoreDbRepository::getInstance()->findInSql(
                                                'SELECT `'.implode('`,`', $columnNames).'` FROM object_brick_localized_query_'.$updatableObject->getType().'_'.$item->getClassId().'_'.$mapping['locale'].' WHERE ooo_id = ? FOR UPDATE',
                                                [$parentForInheritance->getId()]
                                            )[0] ?? $insertData;
                                    } else {
                                        $parentData = PimcoreDbRepository::getInstance()->findInSql('SELECT `'.implode('`,`', $columnNames).'` FROM object_localized_query_'.$item->getClassId().'_'.$mapping['locale'].' WHERE ooo_id = ? FOR UPDATE', [$parentForInheritance->getId()])[0] ?? $insertData;
                                    }
                                }
                            }

                            foreach ($columnNames as $columnName) {
                                $queryData[$columnName] = $parentData[$columnName] ?? $insertData[$columnName];
                            }
                        }
                    }

                    if (count($queryData) === 0) {
                        if (is_array($insertData)) {
                            $queryData = $insertData;
                        } else {
                            $queryData[$fieldDefinition->getName()] = $insertData;
                        }
                    }

                    $descendantIds = [];
                    if ($classInheritanceEnabled) {
                        $descendantIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                            'SELECT '.Helper::prefixObjectSystemColumn('id').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').' = ? AND '.Helper::prefixObjectSystemColumn('path').' LIKE ?',
                            [$item->getClassId(), $item->getRealFullPath().'%']
                        );
                    }

                    foreach ($columnNames as $columnName) {
                        if (empty($mapping['locale'])) {
                            if ($updatableObject instanceof AbstractData) {
                                $queries['object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()][$columnName] = $queryData[$columnName];

                                try {
                                    $descendantIdsWithEmptyData = PimcoreDbRepository::getInstance()->findColumnInSql(
                                        'SELECT '.Helper::prefixObjectSystemColumn('id').' FROM object_brick_store_'.$updatableObject->getType().'_'.$item->getClassId().' WHERE '.Helper::prefixObjectSystemColumn('id').' IN (?) AND ('.$columnName.' IS NULL OR '.$columnName.'=\'\')',
                                        [$descendantIds]
                                    );

                                    foreach ($descendantIdsWithEmptyData as $descendantId) {
                                        $queries['object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$descendantId.' AND fieldname='.$updatableObject->getFieldname()][$columnName] = $queryData[$columnName];
                                    }
                                } catch(\Throwable $e) {}
                            } elseif ($updatableObject instanceof DataObject\Classificationstore) {
                                $updatableObject->save();
                            } else {
                                $queries['object_query_'.$item->getClassId()]['oo_id='.$item->getId()][$columnName] = $queryData[$columnName];

                                try {
                                    $descendantIdsWithEmptyData = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT oo_id FROM object_store_'.$item->getClassId().' WHERE oo_id IN (?) AND ('.$columnName.' IS NULL OR '.$columnName.'=\'\')', [$descendantIds]);

                                    foreach ($descendantIdsWithEmptyData as $descendantId) {
                                        $queries['object_query_'.$item->getClassId()]['oo_id='.$descendantId][$columnName] = $queryData[$columnName];
                                    }
                                } catch(\Throwable $e) {}
                            }
                        } else {
                            if ($updatableObject instanceof AbstractData) {
                                $queries['object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$item->getId().' AND fieldname='.$updatableObject->getFieldname()]['fieldname'] = $updatableObject->getFieldname();

                                $queries['object_brick_localized_query_'.$updatableObject->getType().'_'.$item->getClassId().'_'.$mapping['locale']]['ooo_id='.$item->getId().' AND language='.$mapping['locale']][$columnName] = $queryData[$columnName];

                                try {
                                    $descendantIdsWithEmptyData = PimcoreDbRepository::getInstance()->findColumnInSql(
                                        'SELECT ooo_id FROM object_brick_localized_'.$updatableObject->getType().'_'.$item->getClassId().' WHERE ooo_id IN (?) AND ('.$columnName.' IS NULL OR '.$columnName.'=\'\')',
                                        [$descendantIds]
                                    );

                                    foreach ($descendantIdsWithEmptyData as $descendantId) {
                                        $queries['object_brick_query_'.$updatableObject->getType().'_'.$item->getClassId()][Helper::prefixObjectSystemColumn('id').'='.$descendantId.' AND fieldname='.$updatableObject->getFieldname()]['fieldname'] = $updatableObject->getFieldname();
                                        $queries['object_brick_localized_query_'.$updatableObject->getType().'_'.$item->getClassId().'_'.$mapping['locale']]['ooo_id='.$descendantId.' AND language='.$mapping['locale']][$columnName] = $queryData[$columnName];
                                    }
                                } catch(\Throwable $e) {}
                            } elseif ($updatableObject instanceof DataObject\Classificationstore) {
                                $updatableObject->save();
                            } else {
                                $queries['object_localized_query_'.$item->getClassId().'_'.$mapping['locale']]['ooo_id='.$item->getId().' AND language='.$mapping['locale']][$columnName] = $queryData[$columnName];

                                try {
                                    $descendantIdsWithEmptyData = PimcoreDbRepository::getInstance()->findColumnInSql(
                                        'SELECT ooo_id FROM object_localized_data_'.$item->getClassId().' WHERE ooo_id IN (?) AND language=? AND ('.$columnName.' IS NULL OR '.$columnName.'=\'\')',
                                        [$descendantIds, $mapping['locale']]
                                    );
                                    foreach ($descendantIdsWithEmptyData as $descendantId) {
                                        $queries['object_localized_query_'.$item->getClassId().'_'.$mapping['locale']]['ooo_id='.$descendantId.' AND language='.$mapping['locale']][$columnName] = $queryData[$columnName];
                                    }
                                } catch(\Throwable $e) {
                                }
                            }
                        }
                    }
                }
            }

            $dependencies1 = $fieldDefinition->resolveDependencies($value);
            $dependencies2 = $fieldDefinition->resolveDependencies($value2);

            ksort($dependencies1);
            ksort($dependencies2);

            $addedDependencies = array_udiff(
                $dependencies1,
                $dependencies2,
                static function ($dependency1, $dependency2) {
                    return ($dependency1['id'] != $dependency2['id'] || $dependency1['type'] != $dependency2['type']) ? -1 : 0;
                }
            );
            $dependencies['added'] = array_merge($dependencies['added'], $addedDependencies);

            $addedDependencies = array_udiff(
                $dependencies2,
                $dependencies1,
                static function ($dependency1, $dependency2) {
                    return ($dependency1['id'] != $dependency2['id'] || $dependency1['type'] != $dependency2['type']) ? -1 : 0;
                }
            );
            $dependencies['removed'] = array_merge($dependencies['removed'], $addedDependencies);
        }

        if (!isset($queries['properties']['cid='.$item->getId().' AND ctype=object AND name=auto_generated'])) {
            $reflectionClass = new ReflectionClass($item);
            $itemProperties = $reflectionClass->getProperty(Helper::prefixObjectSystemColumn('properties'));
            $itemProperties->setAccessible(true);
            $itemProperties = $itemProperties->getValue($item);
            $property = $itemProperties['auto_generated'] ?? null;

            if ($property instanceof Property && $property->getData()) {
                $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['type'] = $property->getType();
                $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['cpath'] = $item->getRealFullPath();
                $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['inheritable'] = (int)$property->getInheritable();
                $queries['properties']['cid='.$item->getId().' AND ctype=object AND name='.$property->getName()]['data'] = $property->getData();

                $this->log($item, 'Property "auto_generated" added / changed', 'info');
            }
        }

        if($objectChanged || $isNew) {
            $queries['objects'][Helper::prefixObjectSystemColumn('id').'='.$item->getId()][Helper::prefixObjectSystemColumn('modificationDate')] = $item->getModificationDate();
            $queries['objects'][Helper::prefixObjectSystemColumn('id').'='.$item->getId()][Helper::prefixObjectSystemColumn('userModification')] = $userId;

            if($isNew) {
                $item->setVersionCount(1);
            }
            else {
                try {
                    $versionCount = $item->getDao()->getVersionCountForUpdate() + 1;
                } catch (\Throwable $e) {
                    $versionCount = (int)PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('versionCount').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('id').' = ?', [$item->getId()]);
                    $versionCount2 = (int)PimcoreDbRepository::getInstance()->findOneInSql('SELECT MAX('.Helper::prefixObjectSystemColumn('versionCount').') FROM versions WHERE cid = ? AND ctype = \'object\'', [$item->getId()]);
                    $versionCount = max($versionCount, $versionCount2) + 1;
                }

                if ($versionCount > 4200000000) {
                    $versionCount = 1;
                }

                $item->setVersionCount($versionCount);
            }

            $queries['objects'][Helper::prefixObjectSystemColumn('id').'='.$item->getId()][Helper::prefixObjectSystemColumn('versionCount')] = $item->getVersionCount();

            if ($pathUpdated) {
                if ($classInheritanceEnabled) {
                    // get the next suitable parent for inheritance
                    $parentForInheritance = $item->getNextParentForInheritance();
                    if ($parentForInheritance) {
                        // we don't use the getter (built in functionality to get inherited values) because we need to avoid race conditions
                        // we cannot DataObject::setGetInheritedValues(true); and then $this->model->$method();
                        // so we select the data from the parent object using FOR UPDATE, which causes a lock on this row
                        // so the data of the parent cannot be changed while this transaction is on progress
                        $parentData = PimcoreDbRepository::getInstance()->findRowInSql('SELECT * FROM object_query_'.$item->getClassId().' WHERE oo_id = ? FOR UPDATE', [$parentForInheritance->getId()]) ?? [];

                        foreach ($parentData as $columnName => $data) {
                            if ($columnName === 'oo_id') {
                                continue;
                            }
                            $queries['object_query_'.$item->getClassId()]['oo_id='.$item->getId()][$columnName] = $queries['object_query_'.$item->getClassId()]['oo_id='.$item->getId()][$columnName] ?? $data;
                        }

                        if(method_exists($item, 'getLocalizedfields')) {
                            foreach (Tool::getValidLanguages() as $language) {
                                $parentData = PimcoreDbRepository::getInstance()->findInSql('SELECT * FROM object_localized_query_'.$item->getClassId().'_'.$language.' WHERE ooo_id = ? FOR UPDATE', [$parentForInheritance->getId()])[0] ?? [];
                                foreach ($parentData as $columnName => $data) {
                                    if ($columnName === 'ooo_id') {
                                        continue;
                                    }

                                    $queries['object_localized_query_'.$item->getClassId().'_'.$language]['ooo_id='.$item->getId()][$columnName] = $queries['object_localized_query_'.$item->getClassId().'_'.$language]['ooo_id='.$item->getId()][$columnName] ?? $data;
                                }
                            }
                        }
                    }
                }

                if (!$isNew) {
                    $oldPath = PimcoreDbRepository::getInstance()->findOneInSql('SELECT CONCAT('.Helper::prefixObjectSystemColumn('path').',`'.Helper::prefixObjectSystemColumn('key').'`) FROM objects WHERE '.Helper::prefixObjectSystemColumn('id').' = ?', [$item->getId()]);

                    if($oldPath && $oldPath !== $item->getRealFullPath()) {
                        $childrenIds = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT '.Helper::prefixObjectSystemColumn('id').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').' like ?', [$oldPath.'/%']);
                        $this->pruneCacheIds = array_merge(
                            $this->pruneCacheIds,
                            array_map(static function($childId) {
                                return 'object_'.$childId;
                            }, $childrenIds)
                        );

                        $childrenCount = PimcoreDbRepository::getInstance()->execute('update objects set '.Helper::prefixObjectSystemColumn('path').' = replace('.Helper::prefixObjectSystemColumn('path').',?,?), '.Helper::prefixObjectSystemColumn('userModification').' = ? where '.Helper::prefixObjectSystemColumn('path').' like ?', [$oldPath.'/', $item->getRealFullPath().'/', $userId, $oldPath.'/%']);

                        if($childrenCount > 0) {
                            //update object child permission paths
                            PimcoreDbRepository::getInstance()->execute('update users_workspaces_object set cpath = replace(cpath,?,?) where cpath like ?', [$oldPath.'/', $item->getRealFullPath().'/', $oldPath.'/%']);

                            //update object child properties paths
                            PimcoreDbRepository::getInstance()->execute('update properties set cpath = replace(cpath,?,?) where cpath like ?', [$oldPath.'/', $item->getRealFullPath().'/', $oldPath.'/%']);
                        }
                    }
                }
            }

            $this->writeBuffer[] = ['item' => $item, 'queries' => $queries, 'dependencies' => $dependencies, 'isUpdate' => !$isNew];

            Helper::useInheritance($inheritanceEnabled);
        }
    }

    /**
     * @param ElementInterface $item
     * @return ElementInterface|null
     */
    public function getLatestVersion(ElementInterface $item)
    {
        if (!$item->getId()) {
            return null;
        }

        $oldItem = null;
        try {
            $versionId = PimcoreDbRepository::getInstance()->findOneInSql(
                'SELECT id FROM versions WHERE ctype = ? AND cid = ? AND (`date` >= ? OR versionCount >= ?) ORDER BY `versionCount` DESC LIMIT 1',
                [
                    \OpenDxp\Model\Element\Service::getElementType($item),
                    $item->getId(),
                    $item->getModificationDate(),
                    $item->getVersionCount()
                ]
            );

            $latestVersion = null;
            if ($versionId) {
                $latestVersion = Version::getById($versionId);
            }
            if (!$latestVersion instanceof Version) {
                throw new Exception('Could not find latest version');
            }

            if (method_exists($this->logger, 'disblePimcoreLogger')) {
                $this->logger->disablePimcoreLogger();
            }
            if ($latestVersion->getSerialized()) {
                // in Version::loadData the runtime cache gets cleared -> restore it afterwards
                $runtimeCacheData = RuntimeCache::getInstance()->getArrayCopy();
                @$latestVersion->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
                RuntimeCache::getInstance()->exchangeArray($runtimeCacheData);
            } else {
                @$latestVersion->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
                $this->logger->enablePimcoreLogger();
            }
            if (method_exists($this->logger, 'enablePimcoreLogger')) {
                $this->logger->enablePimcoreLogger();
            }

            $oldItem = $latestVersion->getData();
            if (!$item instanceof $oldItem && !$oldItem instanceof $item) {
                // This could be the case if unserialization goes wrong (and $oldItem is an __PHP_Incomplete_Class_Name)
                $oldItem = null;
            }
        } catch (\Throwable $e) {
            $this->log($item, 'Unable to load latest version for object, '.$e->getMessage(), 'notice');
        }

        return $oldItem;
    }

    public function getKeyConditions($keyValues) {
        $conditions = [];

        $type = $this->getItemType();

        $systemFields = [];
        if($type === 'object') {
            $systemFields = Helper::getSystemFields();
        }

        foreach($keyValues as $column => $keyValue) {
            if($column === 'id' && is_array($keyValue) && isset($keyValue['type'], $keyValue['id']) && $keyValue['type'] === $type) {
                $keyValue = $keyValue['id'];
            } elseif ((strtolower($column) === 'key' && in_array($type, ['object', 'document'], true)) || (strtolower($column) === 'filename' && $type === 'asset')) {
                if($keyValue === null) {
                    return null;
                }

                if($keyValue instanceof ElementInterface) {
                    if(in_array($type, ['object', 'document'], true)) {
                        $keyValue = $keyValue->getKey();
                    } elseif($type === 'asset') {
                        $keyValue = $keyValue->getFilename();
                    }
                }

                $keyValue = Service::getValidKey($keyValue, $type);
            } elseif (strtolower($column) === 'path' && (!is_string($keyValue) || @preg_match($keyValue, '') === false)) {
                if ($keyValue === null) {
                    return null;
                }

                if (is_array($keyValue) && !empty($keyValue['path']) && !empty($keyValue['key'])) {
                    $keyValue = OpenDxp\Model\Element\Service::getElementByPath($type, $keyValue['path'].$keyValue['key']);
                }

                if ($keyValue instanceof ElementInterface) {
                    if (in_array($type, ['object', 'document'], true)) {
                        $keyValue = $keyValue->getRealFullpath();
                    } elseif ($type === 'asset') {
                        $keyValue = $keyValue->getRealFullpath();
                    }
                }

                if (strpos($keyValue, '/') !== 0) {
                    $keyValue = preg_replace('~^/+~', '/', $this->getItemFolder().'/').$keyValue;
                }

                $objectPathArray = explode('/', $keyValue);

                $objectPathArray = array_map(static function ($pathPart) use ($type) {
                    return Service::getValidKey($pathPart, $type);
                }, $objectPathArray);

                $keyValue = implode('/', $objectPathArray);

                if (substr($keyValue, -1) !== '/') {
                    $keyValue .= '/';
                }
            }

            if(($keyValue instanceof QuantityValue || $keyValue instanceof DataObject\Data\InputQuantityValue) && $keyValue->getValue() === null) {
                $keyValue = null;
            }

            if($keyValue === null) {
                return null;
            }

            if($type === 'object' && in_array(Helper::prefixObjectSystemColumn($column), $systemFields, true)) {
                $column = Helper::prefixObjectSystemColumn($column);
            }

            $localeHashPosition = strpos($column, '#');
            if ($localeHashPosition !== false) {
                $column = substr($column, 0, $localeHashPosition);
            }

            $conditions[$column] = $keyValue;
        }

        if(count($conditions) === 0) {
            return null;
        }

        return $conditions;
    }

    public function getItemType() {
        $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);

        if($itemMold instanceof Export) {
            $sourceConfig = $this->dataport['sourceconfig'];
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
        }
        return \OpenDxp\Model\Element\Service::getElementType($itemMold);
    }

    /**
     * @return array
     */
    public function getCachedItem(ElementInterface $object) {
        if($this->itemCache->contains($object)) {
            return $this->itemCache[$object];
        }
        $defaultData = [
            'save' => false,
            'latestVersionData' => [],
            'currentObjectData' => null,
            'rawItemIds' => [],
            'tags' => [],
            'properties' => []
        ];

        if(($this->getTargetConfig()['compatibilityMode'] ?? true) || !$object instanceof Concrete) {
            $defaultData['latestVersion'] = null;
        }
        return $defaultData;
    }

    public function setCachedItem(ElementInterface $object, array $content) {
        if(!isset($this->itemCache[$object])) {
            $this->itemCache[$object] = $this->getCachedItem($object);
        }

        $this->itemCache[$object] = array_replace_recursive($this->itemCache[$object], $content);
    }

    private static function getListCacheKey(array $keyValues) {
        $keyValues = array_map(static function ($keyValue) {
            if ($keyValue instanceof ElementInterface) {
                return $keyValue->getId();
            }
            if (is_string($keyValue)) {
                return mb_strtolower($keyValue);
            }

            if (is_array($keyValue)) {
                return array_map(static function ($keyValueItem) {
                    if ($keyValueItem instanceof ElementInterface) {
                        return $keyValueItem->getId();
                    }
                    if (is_object($keyValueItem) && method_exists($keyValueItem, '__toString')) {
                        return $keyValueItem->__toString();
                    }
                    return $keyValueItem;
                }, $keyValue);
            }

            if (is_object($keyValue) && method_exists($keyValue, '__toString')) {
                return $keyValue->__toString();
            }

            return $keyValue;
        }, $keyValues);

        return json_encode($keyValues);
    }

    private function addToListCache(array $keyValues, ElementInterface $object) {
        $keyValueIndex = self::getListCacheKey($keyValues);

        $this->listCache[$keyValueIndex][] = $object;
    }

    private function getListCache(array $keyValues) {
        $keyValueIndex = self::getListCacheKey($keyValues);
        if(isset($this->listCache[$keyValueIndex])) {
            $elements = [];
            foreach($this->listCache[$keyValueIndex] as $cachedElement) {
                if(!$cachedElement instanceof ElementInterface) {
                    $this->logger->debug('List cache contains item with neither ElementInterface nor ["type", "id"] array');
                    continue;
                }

                $elements[] = $cachedElement;
            }
            return $elements;
        }
        return null;
    }

    /**
     * @return Concrete
     */
    private function getAncestorForInheritanceOptimization(Concrete $target, $value, array $mapping) {
        $this->logger->debug('Looking for ancestor of '.$target->getRealFullPath().' for field "'.$mapping['fieldName'].'" for inheritance optimization');
        $updatableObject = self::getUpdatableObject($target, $mapping);

        if($updatableObject instanceof AbstractData) {
            $classDefinition = $updatableObject->getDefinition();
        } else {
            $classDefinition = $updatableObject->getClass();
        }

        $fieldDefinition = $classDefinition->getFieldDefinition($mapping['fieldName']);
        $localized = $classDefinition->getFieldDefinition('localizedfields');
        if (!$fieldDefinition instanceOf Data && $localized) {
            $fieldDefinition = $localized->getFieldDefinition($mapping['fieldName']);
        }

        // Field collections currently do not support inheritance, see https://docs.opendxp.io/docs/core-framework/Objects/Object_Classes/Data_Types/Fieldcollections#inheritance
        if(!$fieldDefinition instanceof Data || $fieldDefinition instanceof Data\Fieldcollections) {
            return $target;
        }

        $parentElement = $target->getNextParentForInheritance();

        if($parentElement instanceof $target) {
            $getter = 'get'.\ucfirst($mapping['fieldName']);
            $method = new \ReflectionMethod($updatableObject, $getter);
            $params = $method->getParameters();
            $getterCallParams = [];
            if (count($params) >= 1 && $params[0]->getName() === 'language') {
                $getterCallParams[] = $mapping['locale'];
            }
            $siblings = $parentElement->getChildren([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT], true);
            if($siblings instanceof AbstractListing) {
                $siblings = $siblings->load();
            }

            /** @var Concrete $item */
            $itemCacheIterator = new ResettableIterator($this->itemCache);
            foreach($itemCacheIterator as $item) {
                if($item->getParentId() === $parentElement->getId()) {
                    $siblings[] = $item;
                }
            }
            $itemCacheIterator->reset();

            foreach($siblings as $i => $sibling) {
                if($sibling->getRealFullPath() === $target->getRealFullPath()) {
                    unset($siblings[$i]);
                }
                foreach($siblings as $j => $compareSibling) {
                    if($i > $j && ($sibling->getClassId() !== $target->getClassId() || $sibling->getRealFullPath() === $compareSibling->getRealFullPath())) {
                        unset($siblings[$j]);
                        continue 2;
                    }
                }
            }

            $previouslyInheritedValue = null;
            foreach ($siblings as $siblingOfTarget) {
                $updatableSiblingObject = self::getUpdatableObject($siblingOfTarget, $mapping);
                try {
                    $siblingValue = self::getValue($updatableSiblingObject, $mapping, $getterCallParams);

                    if (!$this->isEqual($fieldDefinition, $value, $siblingValue)) {
                        if ($updatableSiblingObject instanceof AbstractData) {
                            $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                            $brickField = $siblingOfTarget->$brickfieldGetter();
                            $brickSetter = 'set' . ucfirst($mapping['brickName']);
                            $brickField->$brickSetter($updatableSiblingObject);
                        }

                        $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                        Helper::useInheritance(false);
                        $realPreviousValue = self::getValue($updatableSiblingObject, $mapping, $getterCallParams);
                        Helper::useInheritance($inheritanceEnabled);

                        $previouslyInheritedValue = ['value' => $siblingValue]; // we cannot set this just to $siblingValue because the object field could be NULL and nevertheless we have to break inheritance
                        self::setValue($updatableSiblingObject, $mapping, null, $getterCallParams); // break inheritance
                        self::setValue($updatableSiblingObject, $mapping, $siblingValue, $getterCallParams);

                        if(!$this->isEqual($fieldDefinition, $realPreviousValue, $siblingValue)) {
                            $this->setCachedItem($siblingOfTarget, ['save' => true]);
                        }
                    }
                } catch(\InvalidArgumentException $e) {
                }
            }

            if($previouslyInheritedValue !== null) {
                Helper::useInheritance(false);

                do {
                    $updatableParentObject = self::getUpdatableObject($parentElement, $mapping);
                    $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                    Helper::useInheritance(false);
                    $parentValue = self::getValue($updatableParentObject, $mapping, $getterCallParams);
                    Helper::useInheritance($inheritanceEnabled);
                    if($fieldDefinition->isEmpty($parentValue) || $this->isEqual($fieldDefinition, $parentValue, $previouslyInheritedValue['value'])) {
                        if ($updatableParentObject instanceof AbstractData){
                            $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                            $brickField = $parentElement->$brickfieldGetter();
                            $brickSetter = 'set' . ucfirst($mapping['brickName']);
                            $brickField->$brickSetter($updatableParentObject);
                        }
                        self::setValue($updatableParentObject, $mapping, null, $getterCallParams); // remove value for parent as we already set the value to all children which inherited the value before
                        if(!$this->isEqual($fieldDefinition, $parentValue, null)) {
                            $this->setCachedItem($parentElement, ['save' => true]);
                        }
                    }

                    $parentParent = $parentElement->getNextParentForInheritance();
                    if($parentParent instanceof $target) {
                        $siblings = $parentParent->getChildren(
                            [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT], true
                        );

                        /** @var Concrete $item */
                        $itemCacheIterator = new ResettableIterator($this->itemCache);
                        foreach ($itemCacheIterator as $item) {
                            if ($item->getParentId() === $parentParent->getId()) {
                                $siblings[] = $item;
                            }
                        }
                        $itemCacheIterator->reset();

                        foreach ($siblings as $i => $sibling) {
                            if ($sibling->getRealFullPath() === $parentElement->getRealFullPath()) {
                                unset($siblings[$i]);
                            }
                            foreach ($siblings as $j => $compareSibling) {
                                if ($i > $j
                                    && ($sibling->getClassId() !== $parentElement->getClassId()
                                        || $sibling->getRealFullPath() === $compareSibling->getRealFullPath())) {
                                    unset($siblings[$j]);
                                    continue 2;
                                }
                            }
                        }

                        foreach ($siblings as $siblingOfParent) {
                            $updatableParentSiblingObject = self::getUpdatableObject($siblingOfParent, $mapping);
                            try {
                                $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                                Helper::useInheritance(false);
                                $parentSiblingValue = self::getValue($updatableParentSiblingObject, $mapping, $getterCallParams);
                                Helper::useInheritance($inheritanceEnabled);

                                if ($fieldDefinition->isEmpty($parentSiblingValue)) {
                                    if ($updatableParentSiblingObject instanceof AbstractData) {
                                        $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                                        $brickField = $siblingOfParent->$brickfieldGetter();
                                        $brickSetter = 'set' . ucfirst($mapping['brickName']);
                                        $brickField->$brickSetter($updatableParentSiblingObject);
                                    }

                                    $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                                    Helper::useInheritance(true);
                                    $previouslyInheritedValueOfParentSibling = self::getValue($updatableParentSiblingObject, $mapping, $getterCallParams);
                                    Helper::useInheritance($inheritanceEnabled);

                                    if(!$fieldDefinition->isEmpty($previouslyInheritedValueOfParentSibling)) {
                                        Helper::useInheritance(false);
                                        $realPreviousValue = self::getValue($updatableParentSiblingObject, $mapping, $getterCallParams);
                                        Helper::useInheritance($inheritanceEnabled);

                                        self::setValue(
                                            $updatableParentSiblingObject, $mapping, null,
                                            $getterCallParams
                                        ); // break inheritance
                                        self::setValue(
                                            $updatableParentSiblingObject, $mapping,
                                            $previouslyInheritedValueOfParentSibling, $getterCallParams
                                        );

                                        if(!$this->isEqual($fieldDefinition, $realPreviousValue, $previouslyInheritedValueOfParentSibling)) {
                                            $this->setCachedItem($siblingOfParent, ['save' => true]);
                                        }
                                    }
                                }
                            } catch(\InvalidArgumentException $e) {
                            }
                        }
                    }

                    $parentElement = $parentElement->getNextParentForInheritance();
                } while($parentElement !== null);

                Helper::useInheritance(true);

                return $target;
            }


            $updatableParentObject = self::getUpdatableObject($parentElement, $mapping);
            $inheritanceEnabled = AbstractObject::getGetInheritedValues();
            Helper::useInheritance(false);
            $parentValue = self::getValue($updatableParentObject, $mapping, $getterCallParams);
            Helper::useInheritance($inheritanceEnabled);
            if($fieldDefinition->isEmpty($parentValue) || $this->isEqual($fieldDefinition, $parentValue, $value)) {
                return $this->getAncestorForInheritanceOptimization($parentElement, $value, $mapping);
            }
        }

        return $target;
    }

    /**
     * @return AbstractData|ElementInterface|OpenDxp\Model\DataObject\Classificationstore
     */
    public static function getUpdatableObject(ElementInterface $target, array $mapping) {
        if (!empty($mapping['targetBrickField']) && !empty($mapping['brickName'])) {
            $inheritanceState = AbstractObject::getGetInheritedValues();
            try {
                AbstractObject::setGetInheritedValues(false);

                $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                $brickGetter = 'get'.ucfirst($mapping['brickName']);
                if (!method_exists($target, $brickfieldGetter)) {
                    throw new Exception('Invalid mapping to non-existent brick field / classification store "'.$mapping['targetBrickField'].'"');
                }

                $brickField = $target->$brickfieldGetter();

                if ($brickField instanceof Objectbrick) {
                    $brick = null;
                    if (method_exists($brickField, $brickGetter)) {
                        $brick = $brickField->$brickGetter();
                    }

                    if (!$brick instanceof AbstractData) {
                        $containerFieldKey = Helper::getFieldKey(['fieldName' => $mapping['targetBrickField'], 'brickName' => '']);
                        if(isset(self::$selfReference->getMappings()[$containerFieldKey])) {
                            throw new ObjectBrickNotAvailableException(
                                'Object brick container "'.$containerFieldKey.'" and single brick field "'.Helper::getFieldKey($mapping).'" are mapped. As callback function for "'.$containerFieldKey.'" does not return brick "'.$mapping['brickName'].'" the single field mapping gets ignored.'
                            );
                        }

                        /** @var AbstractData $brick */
                        try {
                            $brick = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($mapping['brickName']), [$target]);
                            $brick->setFieldname($brickField->getFieldname());
                        } catch(\Exception $e) {
                            throw new Exception('"'.$mapping['brickName'].'" is not a valid brick in '.$mapping['targetBrickField'].'.');
                        }
                    }

                    return $brick;
                }

                if($brickField instanceof OpenDxp\Model\DataObject\Classificationstore) {
                    return $brickField;
                }

                if ($target instanceof Concrete && $target->getClass()->getFieldDefinition($mapping['targetBrickField']) instanceof Data\Classificationstore) {
                    $store = new OpenDxp\Model\DataObject\Classificationstore();
                    $store->setObject($target);

                    return $store;
                }

                throw new Exception('"'.$mapping['targetBrickField'].'" is not a brick / classification store field.');
            } finally {
                AbstractObject::setGetInheritedValues($inheritanceState);
            }
        }
        return $target;
    }

    public function getTargetConfig() {
        return $this->dataport['targetconfig'] ?? [];
    }

    /**
     * @param ClassDefinition|Definition|Asset|Concrete|AbstractData|null $class
     * @param $fieldName
     *
     * @return Data
     */
    public static function getFieldDefinition($class, $field) {
        if (!is_array($field)) {
            $field = ['fieldName' => $field];
        }

        $cacheKey = (is_object($class)?get_class($class):(string)$class).'-'.Helper::getFieldKey($field);
        if (isset(self::$fieldDefinitionCache[$cacheKey])) {
            return self::$fieldDefinitionCache[$cacheKey];
        }

        $context = [];
        try {
            if ($class instanceof Concrete || $class instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $context['object'] = $class;
                $class = $class->getClass();
            } elseif ($class instanceof AbstractData || $class instanceof Fieldcollection\Data\AbstractData) {
                $context['object'] = $class->getObject();

                $class = $class->getDefinition();
            }
        } catch (\Throwable $e) {
            $class = null;
        }

        $def = null;
        if($class instanceof ClassDefinition || $class instanceof Fieldcollection\Definition || $class instanceof Data\Localizedfields) {
            $def = $class->getFieldDefinition($field['fieldName']);

            $localized = null;
            if(!$def instanceof Data) {
                $localized = $class->getFieldDefinition('localizedfields');
                if ($localized) {
                    $def = $localized->getFieldDefinition($field['fieldName']);
                }
            }

            if(!$def instanceof Data) {
                // case-insensitive lookup for field - e.g. for Pimcore-based imports where we have to conclude the field name from the getter method name
                foreach ($class->getFieldDefinitions() as $fieldDefinition) {
                    if (\strtolower($fieldDefinition->getName()) === \strtolower($field['fieldName'])) {
                        $def = $fieldDefinition;
                        break;
                    }
                }
            }

            if(!$def instanceof Data && $localized instanceof Data\Localizedfields) {
                // case-insensitive lookup for field - e.g. for Pimcore-based imports where we have to conclude the field name from the getter method name
                foreach($localized->getFieldDefinitions() as $fieldDefinition) {
                    if(\strtolower($fieldDefinition->getName()) === \strtolower($field['fieldName'])) {
                        $def = $fieldDefinition;
                        break;
                    }
                }
            }

            // classification store
            if (!empty($field['targetBrickField']) && $class->getFieldDefinition($field['targetBrickField']) instanceof Data\Classificationstore && !empty($field['brickName'])) {
                $keyConfig = KeyConfig::getByName($field['fieldName'], $class->getFieldDefinition($field['targetBrickField'])->getStoreId());
                $def = OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromKeyConfig($keyConfig);
            }
        } elseif($class instanceof PageSnippet) {
            $element = self::method_exists($class, 'getEditable') ? $class->getEditable($field['fieldName']) : $class->getElement($field['fieldName']);
            if($element instanceof Document\Editable || $element instanceof Document\Tag) {
                if($element instanceof Document\Editable\Relation) {
                    $def = new Data\ManyToOneRelation();
                    $def->setAssetsAllowed(true);
                    $def->setAssetTypes(['image', 'text', 'audio', 'video', 'document', 'archive', 'unknown']);

                    $def->setObjectsAllowed(true);
                    $classes = array_map(static function(ClassDefinition $classDefinition) {
                        return $classDefinition->getName();
                    }, (new ClassDefinition\Listing())->load());
                    $def->setClasses($classes);

                    $def->setDocumentsAllowed(true);
                    $documentTypes = array_map(static function (Document\DocType $documentType) {
                        return $documentType->getName();
                    }, (new Document\DocType\Listing())->load());
                    $def->setDocumentTypes($documentTypes);
                } elseif ($element instanceof Document\Editable\Relations) {
                    $def = new Data\ManyToManyRelation();
                    $def->setAssetsAllowed(true);
                    $def->setAssetTypes(['image', 'text', 'audio', 'video', 'document', 'archive', 'unknown']);

                    $def->setObjectsAllowed(true);
                    $classes = array_map(static function (ClassDefinition $classDefinition) {
                        return $classDefinition->getName();
                    }, (new ClassDefinition\Listing())->load());

                    $def->setDocumentsAllowed(true);
                    $documentTypes = array_map(static function (Document\DocType $documentType) {
                        return $documentType->getName();
                    }, (new Document\DocType\Listing())->load());
                    $def->setDocumentTypes($documentTypes);
                } else {
                    $type = $element->getType();
                    $defName = Data::class.'\\'.ucfirst($type);
                    if (\class_exists($defName)) {
                        $def = new $defName;
                    } else {
                        $def = new Input();
                    }
                }

                $def->setName($element->getName());
            }
        }

        if(in_array($field['fieldName'], ['__result_callback', '__result_action', '__init_action'], true) || strpos($field['fieldName'], '__virtual_') === 0) {
            $def = new Data\CalculatedValue();
            $def->setName($field['fieldName']);
            $def->setLocked(true);
        }

        if(!$def instanceof Data) {
            if(is_string($class) && class_exists($class)) {
                $reflectionClass = new \ReflectionClass($class);
                try {
                    $method = $reflectionClass->getMethod('set'.$field['fieldName']);
                    if (!$method->isPublic()) {
                        throw new Exception('Cannot set field '.$field['fieldName'].' as its setter method is not public');
                    }

                    preg_match('/@param\s+(\S+)\s/', $method->getDocComment(), $paramTypes);

                    if (isset($paramTypes[1])) {
                        if (\strtolower($paramTypes[1]) === 'array') {
                            $def = new ClassDefinition\Data\Multiselect();
                            $def->setOptions([]);
                        } elseif (preg_match('/^[A-Z]/', $paramTypes[1])) {
                            if (substr($paramTypes[1], -2) === '[]') {
                                $def = new ClassDefinition\Data\ManyToManyRelation();
                            } else {
                                $def = new ClassDefinition\Data\ManyToOneRelation();
                            }
                        }
                    }
                } catch (\ReflectionException $e) {
                }
            }

            if(!$def instanceof Data) {
                if($field['fieldName'] === 'id') {
                    $def = new Data\Numeric();
                    $def->setInteger(true);
                    $def->setLocked(true);
                } elseif ($field['fieldName'] === 'published' || $field['fieldName'] === 'delete element') {
                    $def = new Data\Checkbox();
                } elseif ($field['fieldName'] === 'type') {
                    $def = new Data\Select();
                    $def->setOptions([['key' => 'object', 'value' => 'object'], ['key' => 'variant', 'value' => 'variant'], ['key' => 'folder', 'value' => 'folder']]);
                } elseif(strtolower($field['fieldName']) === 'tags') {
                    $def = new GenericObjectRelation();
                    $def->setClasses([Tag::class]);
                } elseif (strtolower($field['fieldName']) === 'properties') {
                    $def = new GenericObjectRelation();
                    $def->setClasses([Property::class]);
                } elseif (strtolower($field['fieldName']) === 'metadata') {
                    $def = new GenericObjectRelation();
                    $def->setClasses(['array']);
                } elseif (in_array(strtolower($field['fieldName']), ['creationdate', 'modificationdate'])) {
                    $def = new Data\Datetime();
                } elseif ($class instanceof Asset && strtolower($field['fieldName']) === 'customsettings') {
                    $def = new GenericObjectRelation();
                    $def->setClasses(['array']);
                } elseif ($field['fieldName'] === SelectMapper::VIRTUAL_WORKFLOW_FIELD) {
                    $workflowManager = \OpenDxp::getContainer()->get(OpenDxp\Workflow\Manager::class);
                    $options = [];
                    $translator = \OpenDxp::getContainer()->get('translator');
                    $workflows = $workflowManager->getAllWorkflowsForSubject($context['object'] ?? $class);
                    /**
                     * @var Workflow $workflow
                     */
                    foreach ($workflows as $workflow) {
                        $workflowName = $workflow->getName();
                        $config = $workflowManager->getWorkflowConfig($workflowName);
                        $workflowLabel = $config->getWorkflowConfigArray()['label'] ?? '';
                        foreach($workflow->getDefinition()->getTransitions() as $transition) {
                            $key = $workflowLabel.'.'.$transition->getOptions()['label'];
                            $value = $workflowName.'.'.$transition->getName();
                            $options[$key] = [
                                'key' => $translator->trans($key, [], 'admin'),
                                'value' => $value,
                            ];
                        }
                    }
                    $def = new Data\Select();
                    $def->setOptions($options);
                } elseif ($field['fieldName'] === 'seoBundle.title' && Helper::isBundleInstalled('SeoBundle')) {
                    $translator = \OpenDxp::getContainer()->get('translator');
                    $def = new Input();
                    $title = $translator->trans('seo_bundle.panel_title', [], 'admin').': '.$translator->trans('seo_bundle.integrator.title_description.single_title', [], 'admin');
                    $def->setTitle($title);
                } elseif ($field['fieldName'] === 'seoBundle.description' && Helper::isBundleInstalled('SeoBundle')) {
                    $translator = \OpenDxp::getContainer()->get('translator');
                    $def = new Input();
                    $title = $translator->trans('seo_bundle.panel_title', [], 'admin').': '.$translator->trans('seo_bundle.integrator.title_description.single_description', [], 'admin');
                    $def->setTitle($title);
                } else {
                    $def = new Input();
                    $def->setColumnLength(PHP_INT_MAX);

                    if (!in_array(Helper::prefixObjectSystemColumn($field['fieldName']), Helper::getSystemFields(), true)) {
                        $def->setLocked(true);
                    }
                }
            }
            $def->setName($field['fieldName']);
        }

        if(method_exists($def, 'enrichFieldDefinition')) {
            try {
                $def->enrichFieldDefinition($context);
            } catch(\Throwable $e) {
                Logger::warning('Could not enrich field "'.$def->getName().'". '.$e->getMessage());
            }
        }

        if($def instanceof Data\User) {
            $def->configureOptions();
        }

        self::$fieldDefinitionCache[$cacheKey] = $def;

        return $def;
    }

    public static function getValue($object, $field, array $arguments = []) {
        try {
            $request = Helper::getRequest();
            $requestContext = $request->attributes->get(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT);
            $requestIsFrontendRequest = $request->attributes->get(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST);
            $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::CONTEXT_ADMIN);
            $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);

            if (is_string($field)) {
                $fieldParts = explode('#', $field);
                $field = ['fieldName' => $fieldParts[0], 'locale' => $fieldParts[1] ?? null];

                $fieldParts = explode('/', $field['fieldName']);
                if(count($fieldParts) > 1) {
                    $field['brickName'] = $fieldParts[0];
                    $field['fieldName'] = $fieldParts[1];
                }
            }

            if (!empty($field['brickName']) && $object instanceof Concrete) {
                foreach ($object->getClass()->getFieldDefinitions() as $classFieldDef) {
                    // classification store fields
                    if ($classFieldDef instanceof Data\Classificationstore) {
                        $storeId = $classFieldDef->getStoreId();
                        $group = GroupConfig::getByName($field['brickName'], $storeId);
                        if (!$group instanceof GroupConfig) {
                            continue;
                        }
                        $keyConfig = KeyConfig::getByName($field['fieldName'], $storeId);
                        if (!$keyConfig instanceof KeyConfig) {
                            continue;
                        }
                        $storeGetter = 'get'.ucfirst($classFieldDef->getName());
                        if (!method_exists($object, $storeGetter)) {
                            continue;
                        }
                        $store = $object->$storeGetter();
                        if (!$store instanceof OpenDxp\Model\DataObject\Classificationstore) {
                            continue;
                        }
                        return $store->getLocalizedKeyValue($group->getId(), $keyConfig->getId(), $field['locale'] ?? null);
                    }
                    // object brick fields
                    if ($classFieldDef instanceof Data\Objectbricks && in_array($field['brickName'], $classFieldDef->getAllowedTypes() ?: [], true)) {
                        $containerGetter = 'get'.ucfirst($classFieldDef->getName());
                        if (!method_exists($object, $containerGetter)) {
                            continue;
                        }
                        $brickContainer = $object->$containerGetter();
                        $brickGetter = 'get'.ucfirst($field['brickName']);
                        if (!$brickContainer || !method_exists($brickContainer, $brickGetter)) {
                            continue;
                        }
                        $brick = $brickContainer->$brickGetter();
                        if (!$brick) {
                            continue;
                        }
                        return self::getValue($brick, ['fieldName' => $field['fieldName'], 'locale' => $field['locale'] ?? null], $arguments);
                    }
                }
            }

            $getter = 'get'.ucfirst($field['fieldName']);
            if (method_exists($object, $getter)) {
                try {
                    return $object->$getter(...$arguments);
                } catch (\Throwable $e) {
                    if($e instanceof TypeError && property_exists($object, $field['fieldName'])) {
                        $reflection = new ReflectionObject($object);
                        $property = $reflection->getProperty($field['fieldName']);
                        $property->setAccessible(true);
                        $value = $property->getValue($object);
                        if ($value instanceof OpenDxp\Model\Element\ElementDescriptor) {
                            $value = OpenDxp\Model\Element\Service::getElementById($value->getType(), $value->getId());
                        }
                        return $value;
                    }
                    throw new \InvalidArgumentException('Unable to get value for field "'.$field['fieldName'].'". '.$e, 0, $e);
                }
            } elseif($object instanceof Localizedfield) {
                try {
                    return $object->getLocalizedValue($field['fieldName'], $arguments[0] ?? null);
                } catch (\Throwable $e) {
                    throw new \InvalidArgumentException('Unable to get value for field "'.$field['fieldName'].'". '.$e, 0, $e);
                }
            } elseif ($field['fieldName'] === 'tags') {
                $runtimeCacheKeyTags = 'tags-'.spl_object_hash($object);
                if(RuntimeCache::isRegistered($runtimeCacheKeyTags)) {
                    return RuntimeCache::get($runtimeCacheKeyTags);
                }

                if(self::$dataBridgeTagId === null) {
                    self::$dataBridgeTagId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM tags WHERE name=\'Data Bridge\' AND idPath=\'/\'') ?? 0;
                }

                if(self::$dataBridgeTagId) {
                    $tagIds = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT tagid FROM tags_assignment INNER JOIN tags ON tags_assignment.tagid=tags.id WHERE cid = ? AND ctype = ? AND idPath NOT LIKE ?', [$object->getId(), \OpenDxp\Model\Element\Service::getElementType($object), '/'.self::$dataBridgeTagId.'/%']);
                    return array_map(static function($tagId) {
                        return \Sylphen\DataBridgeBundle\model\Tag::getById($tagId);
                    }, $tagIds);
                }

                if($object->getId()) {
                    // Data Bridge tags do not exist yet
                    return Tag::getTagsForElement(\OpenDxp\Model\Element\Service::getElementType($object), $object->getId());
                }
                return [];
            } elseif (in_array($field['fieldName'], ['seoBundle.title', 'seoBundle.description'], true)) {
                $runtimeCacheKeySeoBundle = 'seoBundle' . spl_object_hash($object);
                if(RuntimeCache::isRegistered($runtimeCacheKeySeoBundle)) {
                    $cacheSeoData = RuntimeCache::get($runtimeCacheKeySeoBundle);
                    $seoFieldName = null;
                    if ($field['fieldName'] === 'seoBundle.title') {
                        $seoFieldName = 'title';
                    } elseif ($field['fieldName'] === 'seoBundle.description') {
                        $seoFieldName = 'description';
                    }

                    if($seoFieldName) {
                        if ( $object instanceof Concrete ) {
                            foreach (($cacheSeoData['title_description'][$seoFieldName] ?? []) as $seoData) {
                                if ($seoData['locale'] === $field['locale']) {
                                    return $seoData['value'];
                                }
                            }
                        } elseif ( $object instanceof PageSnippet ) {
                            return $cacheSeoData['title_description'][$seoFieldName];
                        }
                    }
                }

                return null;
            } elseif($field['fieldName'] === SelectMapper::VIRTUAL_WORKFLOW_FIELD) {
                $runtimeCacheKeyDDWorkflowState= SelectMapper::VIRTUAL_WORKFLOW_FIELD.'-'.spl_object_hash($object);
                if(RuntimeCache::isRegistered($runtimeCacheKeyDDWorkflowState)) {
                    return RuntimeCache::get($runtimeCacheKeyDDWorkflowState);
                }

                return null;
            } elseif ($field['fieldName'] === 'Complete Object') {
                return $object;
            } elseif($field['fieldName'] === 'delete element') {
                $runtimeCacheKeyDeleteElement = 'delete element-'.spl_object_hash($object);
                if (RuntimeCache::isRegistered($runtimeCacheKeyDeleteElement)) {
                    return RuntimeCache::get($runtimeCacheKeyDeleteElement);
                }
                return $object->getId() == 0;
            } elseif ($object instanceof PageSnippet) {
                if (method_exists($object, 'hasEditable') && $object->hasEditable($field['fieldName'])) {
                    return $object->getEditable($field['fieldName'])->getDataForResource();
                }

                // legacy methods
                if (method_exists($object, 'hasElement') && $object->hasElement($field['fieldName'])) {
                    return $object->getElement($field['fieldName'])->getDataForResource();
                }

                $contentMasterDocument = null;

                if (method_exists($object, 'getContentMainDocument')) {
                    $contentMasterDocument = $object->getContentMainDocument();
                }

                if (!$contentMasterDocument && method_exists($object, 'getContentMasterDocument')) {
                    $contentMasterDocument = $object->getContentMasterDocument();
                }

                if ($contentMasterDocument instanceof Document) {
                    if (method_exists($contentMasterDocument, 'hasEditable') && $contentMasterDocument->hasEditable($field['fieldName'])) {
                        return $contentMasterDocument->getEditable($field['fieldName'])->getDataForResource();
                    }

                    // legacy methods
                    if (method_exists($contentMasterDocument, 'hasElement') && $contentMasterDocument->hasElement($field['fieldName'])) {
                        return $contentMasterDocument->getElement($field['fieldName'])->getDataForResource();
                    }
                }
            } elseif ($object instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $storeId = $object->getClass()->getFieldDefinition($object->getFieldname())->getStoreId();
                $group = OpenDxp\Model\DataObject\Classificationstore\GroupConfig::getByName($field['brickName'], $storeId);
                if (!$group instanceof OpenDxp\Model\DataObject\Classificationstore\GroupConfig) {
                    throw new \InvalidArgumentException('Could not find classification store group "'.$field['brickName'].'"');
                }

                $keyConfig = OpenDxp\Model\DataObject\Classificationstore\KeyConfig::getByName($field['fieldName'], $storeId);
                if (!$keyConfig instanceof OpenDxp\Model\DataObject\Classificationstore\KeyConfig) {
                    throw new \InvalidArgumentException('Could not find classification store key "'.$field['fieldName'].'" in store #'.$storeId);
                }
                $arguments = array_merge([$group->getId(), $keyConfig->getId()], $arguments);
                return $object->getLocalizedKeyValue(...$arguments);
            } else {
                throw new FieldDoesNotExistException('Invalid mapping for field '.$field['fieldName'].': No valid target found');
            }
        } finally {
            $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, $requestContext);
            $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, $requestIsFrontendRequest);
        }
    }

    public static function setValue(AbstractModel $object, $field, $value, array $arguments = []) {
        if (!is_array($field)) {
            $field = ['fieldName' => $field];
        }

        if($object instanceof PageSnippet) {
            $editable = null;

            if (method_exists($object, 'hasEditable') && !isset($object->getEditables()[$field['fieldName']]) && $object->getContentMainDocument()->hasEditable($field['fieldName'])) {
                $editable = clone ($object->getContentMainDocument()->getEditable($field['fieldName']));
                $object->setEditable($editable);
            } elseif (method_exists($object, 'hasElement') && !isset($object->getElements()[$field['fieldName']]) && $object->getContentMasterDocument()->hasElement($field['fieldName'])) {
                // legacy methods
                $editable = clone $object->getContentMasterDocument()->getElement($field['fieldName']);
                $object->setElement($field['fieldName'], $editable);
            } elseif (method_exists($object, 'hasEditable') && isset($object->getEditables()[$field['fieldName']])) {
                $editable = $object->getEditable($field['fieldName']);
            } elseif(method_exists($object, 'hasElement') && isset($object->getElements()[$field['fieldName']])) {
                // legacy methods
                $editable = $object->getElement($field['fieldName']);
            }

            if($editable !== null) {
                if ($editable instanceof Document\Editable\Relation && $value instanceof ElementInterface) {
                    $value = [
                        'id' => $value->getId(),
                        'type' => OpenDxp\Model\Element\Service::getElementType($value),
                        'subtype' => $value->getType()
                    ];
                } elseif ($editable instanceof Document\Editable\Relations && is_array($value) && reset($value) instanceof ElementInterface) {
                    $value = array_map(function($element) use ($object, $editable) {
                        if(!$element instanceof ElementInterface) {
                            $this->log($object, 'Invalid argument given for editable '.$editable->getName(), 'warning');
                        }
                        return [
                            'id' => $element->getId(),
                            'type' => OpenDxp\Model\Element\Service::getElementType($element),
                            'subtype' => $element->getType()
                        ];
                    }, $value);
                } elseif($editable instanceof Document\Editable\Image && $value instanceof Asset\Image) {
                    $editable->setImage($value);
                } elseif($editable instanceof Document\Editable\Link && $value instanceof Link) {
                    $linkConfig = $editable->getData();
                    foreach(['path', 'text', 'target'] as $configType) {
                        $getter = 'get'.ucfirst($configType);
                        $configValue = $value->$getter();
                        if(!empty($configValue)) {
                            $linkConfig[$configType] = $configValue;
                        }
                    }
                    $value = $linkConfig;
                }

                return $editable->setDataFromEditmode($value);
            }
        } elseif ($object instanceof OpenDxp\Model\DataObject\Classificationstore) {
            /** @var Data\Classificationstore $classificationStoreDefinition */
            $classificationStoreDefinition = $object->getClass()->getFieldDefinition($object->getFieldname());
            $storeId = $classificationStoreDefinition->getStoreId();
            $group = OpenDxp\Model\DataObject\Classificationstore\GroupConfig::getByName($field['brickName'], $storeId);
            if (!$group instanceof OpenDxp\Model\DataObject\Classificationstore\GroupConfig) {
                throw new \InvalidArgumentException('Could not find classification store group "'.$field['brickName'].'"');
            }

            $keyConfig = OpenDxp\Model\DataObject\Classificationstore\KeyConfig::getByName($field['fieldName'], $storeId);
            if (!$keyConfig instanceof OpenDxp\Model\DataObject\Classificationstore\KeyConfig) {
                throw new \InvalidArgumentException('Could not find classification store key "'.$field['fieldName'].'" in store #'.$storeId);
            }

            if ($classificationStoreDefinition->isLocalized() && count($arguments) === 0) {
                $arguments[] = 'default';
            }

            if ($keyConfig->getType() === 'calculatedValue') {
                $object->setActiveGroups($object->getActiveGroups() + [$group->getId() => true]);
                $items = $object->getItems();
                $items[$group->getId()][$keyConfig->getId()][$arguments[0] ?? 'default'] = $value;
                $object->setItems($items);
                return;
            }

            $arguments = array_merge([$group->getId(), $keyConfig->getId(), $value], $arguments);
            $field['fieldName'] = 'localizedKeyValue';
        }

        if (!$object instanceof OpenDxp\Model\DataObject\Classificationstore) {
            array_unshift($arguments, $value);
        }

        $setter = 'set' . ucfirst($field['fieldName']);
        if(in_array(strtolower($setter), ['setcreationdate', 'setmodificationdate']) && $arguments[0] instanceof DateTimeInterface) {
            $arguments[0] = $arguments[0]->getTimestamp();
        }
        if (self::method_exists($object, $setter)) {
            try {
                return $object->$setter(...$arguments);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException('Unable to set value for field "'.$field['fieldName'].'". '.$e, 0, $e);
            }
        } else {
            throw new FieldDoesNotExistException('Invalid mapping for field ' . $field['fieldName'] . ': No valid target found');
        }
    }

    public function pathExistsInCache($path) {
        $itemCacheIterator = new ResettableIterator($this->itemCache);
        try {
            foreach ($itemCacheIterator as $item) {
                if ($item->getRealFullPath() === $path) {
                    return $item;
                }
            }

            return null;
        } finally {
            $itemCacheIterator->reset();
        }
    }

    /**
     * add rawdata to itemCache so result callback function can be executed (e.g. for REST requests) and also import errors can be analysed
     *
     * @param ElementInterface $object
     * @param array $rawData
     * @param string $skipReason
     */
    private function handleSkippedItem(ElementInterface $object, array &$rawItem, $skipReason, ?stdClass $transfer = null) {
        $targetconfig = $this->getTargetConfig();
        foreach ($this->getMappings() as $mapping) {
            if (strpos($mapping['fieldName'], '__virtual_') !== 0) {
                continue;
            }

            try {
                $value = $rawItem['data']['field_'.$mapping['fieldNo']]['value'] ?? null;

                $hasStaticCachedValue = false;
                if (!empty($mapping['calculation']) && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                    $hasStaticCachedValue = CallbackFunction::hasStaticCachedValue($mapping['calculation']);

                    $jsParams = [];
                    if (!$hasStaticCachedValue) {
                        $user = Helper::getUser();
                        $jsParams = [
                            'rawItemData' => $rawItem['data'],
                            'value' => $value,
                            'virtualFields' => $rawItem['virtualFields'],
                            'field' => $mapping['fieldName'],
                            'logger' => $this->logger,
                            'request' => Helper::getRequest(),
                            'transfer' => $transfer ?? new stdClass(),
                            'translator' => $this->translationHelper,
                            'context' => [
                                'dataportId' => $this->dataport['id'],
                                'dataport' => [
                                    'id' => $this->dataport['id'],
                                    'name' => $this->dataport['name'],
                                ],
                                'user' => [
                                    'id' => $user->getId(),
                                    'username' => $user->getUsername()
                                ]
                            ],
                        ];

                        if (isset($mapping['locale'])) {
                            $jsParams['locale'] = $mapping['locale'];
                        }
                    }
                    $value = CallbackFunction::evaluateScript($mapping['calculation'], $targetconfig['javascriptEngine'], $jsParams);

                    $value = $this->map($mapping, $value, null, new Data\CalculatedValue());
                }

                $rawItem['virtualFields'][Helper::getFieldKey($mapping)] = $value;

                if(!$hasStaticCachedValue) {
                    $this->log(null, 'Value for field '.Helper::getFieldKey($mapping).': '.self::getLogOutput($value), 'info');
                }
            } catch (\Throwable $ex) {
                if ($ex instanceof ErrorException) {
                    $errorLevel = 'alert';
                    if (in_array($ex->getCode(), [E_USER_DEPRECATED, E_USER_NOTICE])) {
                        $errorLevel = 'debug';
                    } elseif ($ex->getCode() === E_USER_WARNING || strpos($ex->getMessage(), 'User Warning') === 0) {
                        $errorLevel = 'warning';
                    }

                    $this->log(null, 'Error when executing callback function for field "'.Helper::getFieldKey($mapping).'": '.$ex, $errorLevel);
                    continue;
                }

                $this->log(null, 'Error when executing callback function for field "'.Helper::getFieldKey($mapping).'": '.$ex, 'warning');
            }
        }

        if($skipReason) {
            $this->log($object, $skipReason, 'info');
        }
    }

    private function log(?AbstractModel $object, $message, $logType) {
        $this->logger->log($logType, $message, ($object instanceof ElementInterface && $object->getId() > 0) ? ['relatedObject' => $object, 'dataportId' => $this->dataport['id']]:[]);
    }

    private static function method_exists($object, $methodName) {
        if(!is_object($object)) {
            return false;
        }
        if(\method_exists($object, $methodName)) {
            return true;
        }

        if(!$object instanceof ObjectMetadata && !$object instanceof ElementMetadata) {
            return false;
        }

        if(strpos($methodName, 'get') !== 0) {
            return false;
        }

        return in_array(strtolower(substr($methodName, 3)), array_map('strtolower', $object->getColumns()), true);
    }

    /**
     * @param array $keyMappings
     *
     * @return array first level: datasets to be imported, second level: data per dataset
     */
    public function getKeyDatasets(array $keyMappings, $rawItemData, ?stdClass $transfer = null, array $virtualFields = []) {
        $targetconfig = $this->getTargetConfig();

        try {
            $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);
        } catch(Exception $e) {
            return self::generateCombinations([]);
        }

        $keyValues = [];
        foreach($keyMappings as $keyMapping) {
            $keyValue = $rawItemData['field_' . $keyMapping['fieldNo']]['value'] ?? null;

            if (!empty($keyMapping['calculation'])) {
                try {
                    if(!CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                        throw new Exception('Callback function engine "'.$targetconfig['javascriptEngine'].'" is not available');
                    }

                    $user = Helper::getUser();
                    $jsParams = [
                        'rawItemData' => $rawItemData,
                        'value' => $keyValue,
                        'field' => $keyMapping['fieldName'],
                        'logger' => $this->logger,
                        'request' => Helper::getRequest(),
                        'transfer' => $transfer ?? new stdClass(),
                        'translator' => $this->translationHelper,
                        'virtualFields' => $virtualFields,
                        'context' => [
                            'dataportId' => $this->dataport['id'],
                            'dataport' => [
                                'id' => $this->dataport['id'],
                                'name' => $this->dataport['name'],
                            ],
                            'user' => [
                                'id' => $user->getId(),
                                'username' => $user->getUsername()
                            ]
                        ],
                    ];

                    if(isset($keyMapping['locale'])) {
                        $jsParams['locale'] = $keyMapping['locale'];
                    }

                    $keyValue = CallbackFunction::evaluateScript($keyMapping['calculation'], $targetconfig['javascriptEngine'], $jsParams);
                } catch (\Throwable $ex) {
                    throw new Exception("Error while calculating key value for field '{$keyMapping['fieldName']}': {$ex}");
                }
            }

            try {
                $def = self::getFieldDefinition($itemMold, $keyMapping);
            } catch (Exception $e) {
                $this->logger->info('Skipping key field '.Helper::getFieldKey($keyMapping).' as it does not exist anymore'.$e);

                continue;
            }

            if (!\is_array($keyValue) || (!isset($keyValue[0]) && count($keyValue) > 0)) {
                $keyValue = [$keyValue];
            }

            $keyValue = array_map(function ($keyValue) use ($keyMapping, $def) {
                $parsedValue = $this->map($keyMapping, $keyValue, null, $def);

                $keyValueLog = 'Value for key field "'.Helper::getFieldKey($keyMapping).'": '.(is_scalar($parsedValue) ? $keyValue : json_encode($keyValue, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
                if ((is_string($parsedValue) && \preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)*$/', $parsedValue)) || $parsedValue === null) {
                    try {
                        $targetObject = $this->getObjectByIdentifier($parsedValue);
                        if ($targetObject !== $parsedValue) {
                            $parsedValue = $targetObject;
                        }
                    } catch (Exception $e) {
                    }

                    $keyValueLog .= ' -> '.((is_scalar($parsedValue) || (is_object($parsedValue) && method_exists($parsedValue, '__toString'))) ? $parsedValue : json_encode($parsedValue, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
                }

                $this->logger->info($keyValueLog);

                return $parsedValue;
            }, $keyValue);

            $keyValues[Helper::getFieldKey($keyMapping)] = $keyValue;
        }

        return self::generateCombinations($keyValues);
    }

    private static function getHtmlSanitizer()
    {
        if(self::$htmlSanitizer === null && defined(Tool\Text::class.'::PIMCORE_WYSIWYG_SANITIZER_ID')) {
            self::$htmlSanitizer = \OpenDxp::getContainer()->get(Tool\Text::PIMCORE_WYSIWYG_SANITIZER_ID);
        }
        return self::$htmlSanitizer;
    }

    public function isEqual(Data $fieldDefinition, $value1, $value2) {
        if (($value1 === null xor $value2 === null) && !$fieldDefinition instanceof Data\ManyToManyRelation && !$fieldDefinition instanceof Data\ManyToManyObjectRelation && !$fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation && !$fieldDefinition instanceof Data\AdvancedManyToManyRelation) {
            return false;
        }

        if ($fieldDefinition instanceof Data\ImageGallery) {
            /**
             * @var ImageGallery $value1
             * @var ImageGallery $value2
             */
            foreach ((array)$value1->getItems() as $index => $image1) {
                $image2 = $value2->getItems()[$index] ?? null;
                if (!$image1 instanceof Hotspotimage || !$image2 instanceof Hotspotimage) {
                    return false;
                }

                if ($image1 === null xor $image2 === null) {
                    return false;
                }

                if ($image1 !== null) {
                    $image1 = $image1->getImage();
                }

                if ($image2 !== null) {
                    $image2 = $image2->getImage();
                }

                if ($image1 && $image2 && $image1->getId() !== $image2->getId()) {
                    return false;
                }
            }

            return true;
        } elseif ($fieldDefinition instanceof Data\Numeric) {
            return $value1 == $value2;
        } elseif ($fieldDefinition instanceof Data\Checkbox && $value1 !== $value2) {
            return false;
        } elseif ($fieldDefinition instanceof Data\Wysiwyg) {
            if($value1 === null && $value2 === null) {
                return true;
            }
            $sanitizer = self::getHtmlSanitizer();
            if($sanitizer instanceof HtmlSanitizer) {
                return $sanitizer->sanitize($value1) === $sanitizer->sanitize($value2);
            }
        } elseif ($fieldDefinition instanceof Data\Objectbricks) {
            if (!$value1 instanceof Objectbrick && !$value2 instanceof Objectbrick) {
                return true;
            }

            foreach ($fieldDefinition->getAllowedTypes() as $allowedType) {
                $getter = 'get'.$allowedType;

                /** @var null|AbstractData $brick1Value */
                $brick1Value = null;
                if ($value1) {
                    if (!\method_exists($value1, $getter)) {
                        $this->log(null, 'Brick '.$allowedType.' does not exist in object', 'info');
                        return false;
                    }
                    $brick1Value = $value1->$getter();
                }

                /** @var null|AbstractData $brick1Value */
                $brick2Value = null;
                if ($value2) {
                    if (!\method_exists($value2, $getter)) {
                        $this->log(null, 'Brick '.$allowedType.' does not exist in latest version', 'info');
                        return false;
                    }
                    $brick2Value = $value2->$getter();
                }

                if ($brick1Value === null && $brick2Value === null) {
                    continue;
                }

                if ($brick1Value instanceof AbstractData xor $brick2Value instanceof AbstractData) {
                    $this->log(null, 'Brick '.$allowedType.' does not exist', 'info');
                    return false;
                }

                $brickDefinition = Definition::getByKey($allowedType);
                $brickFieldDefinitions = $brickDefinition->getFieldDefinitions();

                foreach ($brickFieldDefinitions as $brickFieldDefinition) {
                    if ($brickFieldDefinition instanceof ClassDefinition\Data\Localizedfields) {
                        /** @var Localizedfield $localizedFields */
                        $localizedFields = $brick1Value->getLocalizedFields();

                        $localizedFieldDefinitions = $brickFieldDefinition->getFieldDefinitions();
                        foreach ($localizedFieldDefinitions as $lfd) {
                            $getter = 'get'.ucfirst($lfd->getName());
                            if (\method_exists($brick1Value, $getter) xor \method_exists($brick2Value, $getter)) {
                                $this->log(null, 'Localized field '.$allowedType.'/'.$lfd->getName().' does not exist on latest version', 'info');
                                return false;
                            }

                            foreach (Tool::getValidLanguages() as $language) {
                                if (!$this->isEqual($lfd, $brick1Value->$getter($language), $brick2Value->$getter($language))) {
                                    $this->log(null, 'Localized field '.$lfd->getName().'#'.$language.' changed', 'info');
                                    return false;
                                }
                            }
                        }
                    } elseif ($brick1Value instanceof AbstractData && $brick2Value instanceof AbstractData) {
                        $fieldGetter = 'get'.ucfirst($brickFieldDefinition->getName());
                        if (!\method_exists($brick1Value, $fieldGetter) || !\method_exists($brick2Value, $fieldGetter)) {
                            $this->log(null, 'Field '.$brickFieldDefinition->getName().' does not exist in brick '.$allowedType, 'info');
                            return false;
                        }
                        $brick1FieldValue = $brick1Value->$fieldGetter();
                        $brick2FieldValue = $brick2Value->$fieldGetter();

                        if (!$this->isEqual($brickFieldDefinition, $brick1FieldValue, $brick2FieldValue)) {
                            $this->log(null, 'Field '.$brickFieldDefinition->getName().' in brick '.$allowedType.' changed', 'info');
                            return false;
                        }
                    }
                }
            }

            return true;
        } elseif ($fieldDefinition instanceof Data\Fieldcollections) {
            if (!$value1 instanceof Fieldcollection) {
                $value1 = new Fieldcollection();
            }
            if (!$value2 instanceof Fieldcollection) {
                $value2 = new Fieldcollection();
            }

            $fieldCollection1Items = array_reverse($value1->getItems());
            $fieldCollection2Items = array_reverse($value2->getItems());

            if (count($fieldCollection1Items) !== count($fieldCollection2Items)) {
                return false;
            }

            foreach ($fieldCollection1Items as $index => $item1) {
                $item2 = $fieldCollection2Items[$index] ?? null;
                if (!$item1 instanceof $item2) {
                    return false;
                }
                foreach ($item1->getDefinition()->getFieldDefinitions() as $fieldDefinition1) {
                    if ($fieldDefinition1 instanceof ClassDefinition\Data\Localizedfields) {
                        $localizedFieldDefinitions = $fieldDefinition1->getFieldDefinitions();
                        foreach ($localizedFieldDefinitions as $lfd) {
                            $getter = 'get'.ucfirst($lfd->getName());
                            if (\method_exists($item1, $getter) xor \method_exists($item2, $getter)) {
                                return false;
                            }

                            foreach (Tool::getValidLanguages() as $language) {
                                if (!$this->isEqual($lfd, $item1->$getter($language), $item2->$getter($language))) {
                                    return false;
                                }
                            }
                        }
                    } else {
                        $getter = 'get'.ucfirst($fieldDefinition1->getName());
                        if (!\method_exists($item1, $getter) || !\method_exists($item2, $getter)) {
                            return false;
                        }

                        $value1 = $item1->$getter();
                        $value2 = $item2->$getter();

                        if (!$this->isEqual($fieldDefinition1, $value1, $value2)) {
                            return false;
                        }
                    }
                }
            }

            return true;
        } elseif ($fieldDefinition instanceof Data\ClassificationStore) {
            $value1Groups = null;
            if ($value1 instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $value1Groups = $value1->getActiveGroups();
            }
            $value2Groups = null;
            if ($value2 instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $value2Groups = $value2->getActiveGroups();
            }

            if ($value1Groups != $value2Groups) {
                return false;
            }

            if ($value1 instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $value1 = $value1->getItems();
            }
            if ($value2 instanceof OpenDxp\Model\DataObject\Classificationstore) {
                $value2 = $value2->getItems();
            }

            return serialize($value1) === serialize($value2);
        } elseif ($fieldDefinition instanceof Data\Block || $fieldDefinition instanceof Data\Fieldcollections) {
            return serialize($value1) === serialize($value2);
        } elseif ($fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation || $fieldDefinition instanceof Data\AdvancedManyToManyRelation) {
            $count1 = is_array($value1) ? count($value1) : 0;
            $count2 = is_array($value2) ? count($value2) : 0;

            if ($count1 !== $count2) {
                return false;
            }

            $values1 = array_filter(array_values(is_array($value1) ? $value1 : []));
            $values2 = array_filter(array_values(is_array($value2) ? $value2 : []));

            for ($i = 0; $i < $count1; $i++) {
                /** @var ElementMetadata|null $container1 */
                $container1 = $values1[$i];
                /** @var ElementMetadata|null $container2 */
                $container2 = $values2[$i];

                if (!$container1 || !$container2) {
                    return !$container1 && !$container2;
                }

                /** @var ElementInterface $el1 */
                $el1 = $container1->getElement();
                /** @var ElementInterface $el2 */
                $el2 = $container2->getElement();

                if(!$el1 instanceof ElementInterface || !$el2 instanceof ElementInterface) {
                    continue;
                }

                if (!($el1->getType() == $el2->getType() && ($el1->getId() == $el2->getId()))) {
                    return false;
                }

                $data1 = $container1->getData();
                $data2 = $container2->getData();
                if ($data1 != $data2) {
                    return false;
                }
            }

            return true;
        } elseif($fieldDefinition instanceof Data\Localizedfields) {
            if(!$value1 instanceof Localizedfield || !$value2 instanceof Localizedfield) {
                return !$value1 && !$value2;
            }

            $localizedFieldDefinitions = $fieldDefinition->getFieldDefinitions();
            foreach ($localizedFieldDefinitions as $lfd) {
                foreach (Tool::getValidLanguages() as $language) {
                    if (!$this->isEqual($lfd, $value1->getLocalizedValue($lfd->getName(), $language), $value2->getLocalizedValue($lfd->getName(), $language))) {
                        return false;
                    }
                }
            }
            return true;
        } elseif($fieldDefinition instanceof Date && $fieldDefinition->getFieldtype() === 'date') {
            if($value1 instanceof DateTimeInterface && $value2 instanceof DateTimeInterface) {
                $value1->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $value2->setTimezone(new \DateTimeZone(date_default_timezone_get()));

                return $value1->format('Y-m-d') === $value2->format('Y-m-d');
            }
        } elseif (is_object($value1) && is_object($value2) && method_exists($value1, '__toString') && method_exists($value2, '__toString') && !$fieldDefinition instanceof Date && !$fieldDefinition instanceof Data\Hotspotimage) {
            return (string)$value1 === (string)$value2;
        } elseif ($value1 instanceof DataObject\Data\Video && $value2 instanceof DataObject\Data\Video && $fieldDefinition instanceof Data\Video) {
            if($value1->getType() !== $value2->getType()) {
                return false;
            }

            if($value1->getType() === 'asset') {
                return $value1->getData()->getId() === $value2->getData()->getId();
            }

            return $value1->getData() === $value2->getData();
        }

        try {
            $isEqual = true;

            if (is_array($value1) && array_keys($value1) != array_keys(array_values($value1))) {
                ksort($value1);
            }
            if (is_array($value2) && array_keys($value2) != array_keys(array_values($value2))) {
                ksort($value2);
            }

            if (method_exists($fieldDefinition, 'getDiffVersionPreview')) {
                // bypass bug https://github.com/pimcore/pimcore/pull/15333
                if (($fieldDefinition instanceof Data\Select || $fieldDefinition instanceof Data\Multiselect) && $fieldDefinition->getOptions() === null) {
                    $fieldDefinition->setOptions([]);
                }

                $v1 = $fieldDefinition->getDiffVersionPreview($value1);
                $v2 = $fieldDefinition->getDiffVersionPreview($value2);

                $isEqual = $v1 === $v2;
            }

            if($isEqual) {
                if($value1 === null) {
                    $v1 = '';
                } else {
                    $v1 = $fieldDefinition->getVersionPreview($value1);
                }

                if($value2 === null) {
                    $v2 = '';
                } else {
                    $v2 = $fieldDefinition->getVersionPreview($value2);
                }

                if(($v1 === null || is_scalar($v1)) && ($v2 === null || is_scalar($v2))) {
                    $isEqual = (string)$v1 === (string)$v2;
                } else {
                    // this happens when field type was changed, e.g. from quantity value to numeric -> Numeric::getVersionPreview() simply returns the set value (QuantitValue object in this case)
                    $isEqual = json_encode($v1) === json_encode($v2);
                }
            }
        } catch(\Throwable $e) {
            if($fieldDefinition instanceof Data\Classificationstore) {
                return false;
            }

            return $value1 == $value2;
        }

        if($isEqual) {
            if ($fieldDefinition instanceof Data\EqualComparisonInterface && !$fieldDefinition->isEqual($value1, $value2)) {
                return false;
            }

            if ((string)$v1 === 'no preview') {
                return serialize($v1) === serialize($v2);
            }
        }

        return $isEqual;
    }

    public function getMappings($force = false) {
        if(!empty($this->dataport['id']) && ($this->fieldMappings === null || $force)) {
            self::$mappingDependencies = [];
            $fieldMappings = Fieldmapping::getInstance()->find(
                [
                    'dataportId = ?' => $this->dataport['id'],
                    'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action']
                ],
                'fieldName'
            );

            $this->fieldMappings = [];

            $javascriptEngine = $this->getTargetConfig()['javascriptEngine'];

            foreach ($fieldMappings as $mapping) {
                if ($mapping['format']) {
                    $mapping['format'] = unserialize($mapping['format'], ['allowed_classes' => false]);
                } else {
                    $mapping['format'] = [];
                }

                if ($mapping['calculation'] && !CallbackFunction::isEngineAvailable($javascriptEngine)) {
                    throw new Exception('Callback function engine "'.$javascriptEngine.'" is not available.');
                }

                $this->fieldMappings[Helper::getFieldKey($mapping)] = $mapping;
            }

            // import system fields first - especially path is important to be imported before custom fields for inheritance optimization
            uasort($this->fieldMappings, static function ($mapping1, $mapping2) {
                if ($mapping1['keyMapping'] && !$mapping2['keyMapping']) {
                    return -1;
                }

                if (!$mapping1['keyMapping'] && $mapping2['keyMapping']) {
                    return 1;
                }

                $mapping1IsSystemField = in_array(Helper::prefixObjectSystemColumn($mapping1['fieldName']), Helper::getSystemFields(), true) || in_arrayi($mapping1['fieldName'], ['filename', 'tags', 'properties']);
                $mapping2IsSystemField = in_array(Helper::prefixObjectSystemColumn($mapping2['fieldName']), Helper::getSystemFields(), true) || in_arrayi($mapping2['fieldName'], ['filename', 'tags', 'properties']);

                if (!$mapping1IsSystemField && $mapping2IsSystemField) {
                    return 1;
                }
                if ($mapping1IsSystemField && !$mapping2IsSystemField) {
                    return -1;
                }

                // import path before key to be able to add key suffix on path collision
                if (in_arrayi($mapping1['fieldName'], ['key', 'filename']) && strtolower($mapping2['fieldName']) === 'path') {
                    return 1;
                }
                if ($mapping1['fieldName'] === 'path' && in_arrayi($mapping2['fieldName'], ['key', 'filename'])) {
                    return -1;
                }

                if (!empty($mapping1['format']['auto_classification']) && empty($mapping2['format']['auto_classification'])) {
                    return 1;
                }

                if (empty($mapping1['format']['auto_classification']) && !empty($mapping2['format']['auto_classification'])) {
                    return -1;
                }

                // import brick fields last when container and single bricks fields have been mapped to ignore single brick fields if in container's callback function to brick is not contained
                if (!empty($mapping1['targetBrickField']) && empty($mapping2['targetBrickField'])) {
                    return 1;
                }

                if (empty($mapping1['targetBrickField']) && !empty($mapping2['targetBrickField'])) {
                    return -1;
                }

                return 0;
            });

            $this->fieldMappings = self::sortMappingsByDependencies($this->fieldMappings);
            unset($mapping);
        }

        return $this->fieldMappings;
    }

    public static function getMappingDependencies(array $mappings)
    {
        foreach ($mappings as $index => $mapping) {
            if (isset(self::$mappingDependencies[$index])) {
                continue;
            }

            self::$mappingDependencies[$index] = [];
            if (!empty($mapping['calculation'])) {
                if (\preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $mapping['calculation'], $variables)) {
                    foreach ($variables[1] as $variable) {
                        $variableParts = explode('#', $variable);
                        foreach ($mappings as $dependencyIndex => $dependentMapping) {
                            if ($dependencyIndex === $index) {
                                continue;
                            }

                            if ('__virtual_'.$variable === $dependentMapping['fieldName'] || ($variableParts[0] === $dependentMapping['fieldName'] && (!isset($variableParts[1]) || $variableParts[1] === $dependentMapping['locale']))) {
                                self::$mappingDependencies[$index][] = $dependencyIndex;
                                break;
                            }
                        }
                    }
                }

                if (\preg_match_all('/params(\.|\[[\'"])currentObjectData([\'"]\])?(((\.|\[[\'"])([\w #]+)([\'"]\])?)+)/', $mapping['calculation'], $variables)) {
                    foreach ($variables[3] as $variable) {
                        foreach ($mappings as $dependencyIndex => $dependentMapping) {
                            if ($dependencyIndex === $index) {
                                continue;
                            }

                            $possibleReferencingVariableNames = [];
                            if (!empty($dependentMapping['brickName'])) {
                                $possibleReferencingVariableNames[] = '[\''.$dependentMapping['brickName'].'\'][\''.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'\']';
                                $possibleReferencingVariableNames[] = '["'.$dependentMapping['brickName'].'"]["'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'"]';
                                $possibleReferencingVariableNames[] = '["'.$dependentMapping['brickName'].'"][\''.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'\']';
                                $possibleReferencingVariableNames[] = '[\''.$dependentMapping['brickName'].'\']["'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'"]';
                                $possibleReferencingVariableNames[] = '.'.$dependentMapping['brickName'].'.'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '');
                                $possibleReferencingVariableNames[] = '[\''.$dependentMapping['brickName'].'\'].'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '');
                                $possibleReferencingVariableNames[] = '["'.$dependentMapping['brickName'].'"].'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '');
                            } else {
                                $possibleReferencingVariableNames[] = '[\''.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'\']';
                                $possibleReferencingVariableNames[] = '["'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '').'"]';
                                $possibleReferencingVariableNames[] = '.'.$dependentMapping['fieldName'].(!empty($dependentMapping['locale']) ? '#'.$dependentMapping['locale'] : '');
                            }

                            if (in_array($variable, $possibleReferencingVariableNames, true)) {
                                self::$mappingDependencies[$index][] = $dependencyIndex;
                                break;
                            }
                        }
                    }
                }
            }
        }

        $mappingFieldnames = array_keys($mappings);
        uksort(
            self::$mappingDependencies,
            static function ($fieldName1, $fieldName2) use ($mappingFieldnames) {
                return array_search($fieldName1, $mappingFieldnames) - array_search($fieldName2, $mappingFieldnames);
            }
        );

        return self::$mappingDependencies;
    }

    public static function sortMappingsByDependencies(array $mappings)
    {
        if(count($mappings) === 0) {
            return $mappings;
        }

        $sorter = new StringSort();
        foreach(self::getMappingDependencies($mappings) as $index => $dependencies) {
            $sorter->add($index, $dependencies);
        }

        $sortedIndexes = $sorter->sort();

        $sortedMappings = [];
        foreach($sortedIndexes as $sortedIndex) {
            $sortedMappings[$sortedIndex] = $mappings[$sortedIndex];
        }

        return $sortedMappings;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getAssetSourceFile(string $url): string
    {
        if (strpos($url, 'sftp://') === 0 || strpos($url, 'ftp://') === 0 || strpos($url, 'ftps://') === 0 || @file_exists($url)) {
            return $url;
        }

        try {
            $uri = new Uri($url);

            if (!in_arrayi($uri->getScheme(), ['http', 'https']) || preg_match('~https?://~i', $uri->getPath())) {
                throw new \InvalidArgumentException('URI is not HTTP(S), falling back to local file / stream wrapper');
            }

            if (in_arrayi($uri->getScheme(), ['ftp', 'ftps', 'sftp', 's3'])) {
                return $url;
            }

            if ($uri->getHost() === parse_url(Helper::getHostUrl(), PHP_URL_HOST)) {
                if (defined('OPENDXP_ASSET_DIRECTORY') && file_exists(OPENDXP_ASSET_DIRECTORY.$uri->getPath())) {
                    return OPENDXP_ASSET_DIRECTORY.$uri->getPath();
                }

                if(file_exists(\OPENDXP_WEB_ROOT.'/var/assets'.$uri->getPath())) {
                    return \OPENDXP_WEB_ROOT.'/var/assets'.$uri->getPath();
                }
            }
        } catch (\InvalidArgumentException $e) {
        }

        // relative path from configured asset source directory
        $filePath = rtrim($this->getAssetSource(), '/').'/'.ltrim($url, '/');
        if (strpos($filePath, 'sftp://') === 0 || strpos($filePath, 'ftp://') === 0 || strpos($filePath, 'ftps://') === 0 || @file_exists($filePath)) {
            return $filePath;
        }

        // relative path from Pimcore root directory
        $filePath = OPENDXP_PROJECT_ROOT.'/'.trim($this->getAssetSource(), '/') . '/' . ltrim($url, '/');
        if(file_exists($filePath)) {
            return $filePath;
        }

        // absolute Pimcore asset path
        $asset = Asset::getByPath($filePath);
        if ($asset instanceof Asset) {
            try {
                if (defined('OPENDXP_ASSET_DIRECTORY')) {
                    $filePath = \OPENDXP_ASSET_DIRECTORY.$asset->getRealFullPath();
                } else {
                    $filePath = \OPENDXP_WEB_ROOT.'/var/assets'.$asset->getRealFullPath();
                }

                if (!file_exists($filePath)) {
                    $filePath = \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile($asset);
                }

                if (file_exists($filePath)) {
                    return $filePath;
                }
            } catch (Exception $e) {
            }
        }

        // relative Pimcore asset path
        $asset = Asset::getByPath(rtrim($this->getAssetSource(), '/').'/'.ltrim($url, '/'));
        if ($asset instanceof Asset) {
            try {
                return \Sylphen\DataBridgeBundle\lib\Pim\Helper::getLocalAssetFile($asset);
            } catch (Exception $e) {
            }
        }

        // absolute Pimcore Asset path but asset does not exist anymore (only file exists)
        $assetFolder = Asset\Folder::getByPath($this->getAssetSource());
        if ($assetFolder instanceof Asset\Folder) {
            $filePath = $assetFolder->getRealFullPath() . ltrim($url, '/');
            try {
                return self::getLocalFileFromStream(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getAssetStorage()->readStream($filePath));
            } catch(Exception $e) {
            }
        }

        // when importing zip file which includes assets
        $filePath = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $this->dataport['id'] . '_zipAsset/' . $url;
        if (file_exists($filePath)) {
            return $filePath;
        }

        // when importing zip file which includes assets
        $filePath = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $this->dataport['id'] . '_zipAsset/'. rtrim($this->getAssetSource()).'/'. $url;
        if (file_exists($filePath)) {
            return $filePath;
        }

        if (preg_match('/^data:[^\/]+?\/([^;]+);base64(.+)/', $url, $base64)) {
            $tmpFile = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->dataport['id'].'_'.uniqid().'.'.$base64[1];

            file_put_contents($tmpFile, base64_decode($base64[2]));

            register_shutdown_function(
                static function () use ($tmpFile) {
                    @unlink($tmpFile);
                }
            );

            return $tmpFile;
        }

        return $url;
    }

    private function addEditLock($id, $type)
    {
        if(PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM edit_lock WHERE cid=? AND ctype=? AND userId=? AND sessionId=?', [$id, $type, 0, getmypid().'-'.$this->dataport['id']])) {
            return;
        }
        $lock = new Editlock();
        $lock->setCid($id);
        $lock->setCtype($type);
        $lock->setDate(time());
        $lock->setSessionId(getmypid().'-'.$this->dataport['id']);

        if (!self::method_exists($lock, 'setUser')) {
            $lock->setUserId(0);
        } else {
            // BC Pimcore 5
            $lock->setUser(User::getById(0));
            $lock->userId = 0;
        }

        $lock->save();

        return $lock->getId();
    }

    public static function getAssetChecksum(Asset $asset, $type = 'md5') {
        if($asset instanceof Asset\Folder) {
            throw new InvalidArgumentException('Checksum cannot be calculated for folders');
        }

        if (!in_array($type, hash_algos())) {
            throw new \Exception('Hashing algorithm `'.$type.'` is not supported');
        }

        // potential risk for "Too many open files" if asset stream was not opened yet
        $stream = $asset->getStream();
        if (!stream_is_local($stream)) {
            throw new InvalidArgumentException('Checksum can only be calculated for local assets');
        }

        $file = self::getLocalFileFromStream($stream);
        return hash_file($type, $file);
    }


    public static function translate(string $term, ?string $locale = null, ?string $sourceLanguage = null, $translateOptions = []): string
    {
        return AbstractTranslationProvider::translateText($term, $locale, $sourceLanguage, $translateOptions);
    }

    public static function isImportWithoutCompatibilityModeRunning() {
        return self::$allowDummyDeepCopy;
    }

    public static function getLogOutput($value, $unmappedValue = null) {
        try {
            if (is_bool($value)) {
                if ($value) {
                    $logOutput = 'true';
                } else {
                    $logOutput = 'false';
                }
            } elseif ($value === null) {
                $logOutput = 'NULL';
            } elseif (is_scalar($value)) {
                $logOutput = $value;
            } elseif ($value instanceof ElementInterface) {
                $logOutput = $value->getRealFullPath();
            } elseif ($value instanceof QuantityValue) {
                $logOutput = (string)$value;
            } elseif (is_array($value) && reset($value) instanceof ElementInterface) {
                $logOutput = json_encode(
                    array_map(static function ($item) {
                        return $item->getRealFullPath() . self::getImageUpdateInfo($item);
                    }, $value),
                    JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
            } elseif (is_array($value) && (reset($value) instanceof ElementMetadata || reset($value) instanceof ObjectMetadata)) {
                $logOutput = json_encode(
                    array_map(static function ($item) {
                        $element = $item->getElement();
                        if ($element instanceof ElementInterface) {
                            return $element->getRealFullPath().' ['.json_encode($item->getData()).']' . self::getImageUpdateInfo($element);
                        }
                    }, $value),
                    JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
            } elseif ($value instanceof ImageGallery) {
                $logOutput = self::getLogOutput($value->getItems());
            } elseif (is_array($value) && reset($value) instanceof Hotspotimage) {
                $logOutput = json_encode(
                    array_map(static function ($item) {
                        $image = $item->getImage();
                        if ($image instanceof Asset\Image) {
                            return $image->getRealFullPath() . self::getImageUpdateInfo($image);
                        }
                    }, $value),
                    JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
            } elseif ($value instanceof StructuredTable) {
                $logOutput = json_encode($value->getData(), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif ($value instanceof DataObject\Classificationstore) {
                $logOutputArray = [];
                if ($unmappedValue !== null) {
                    foreach ($value->getGroups() as $group) {
                        foreach ((array)$unmappedValue as $brickName => $itemData) {
                            if ($group->getConfiguration()->getName() === $brickName) {
                                if (!isset($logOutputArray[$brickName])) {
                                    $logOutputArray[$brickName] = [];
                                }

                                foreach ($group->getKeys() as $groupKey) {
                                    foreach ($itemData as $field => $fieldValue) {
                                        if ($groupKey->getConfiguration()->getName() === $field) {
                                            $logOutputArray[$brickName][$field] = self::decodeJsonIfPossible(self::getLogOutput($groupKey->getValue()));
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    /** @var DataObject\Classificationstore\Group $group */
                    foreach ($value->getGroups() as $group) {
                        $groupName = $group->getConfiguration()->getName();
                        $logOutputArray[$groupName] = [];
                        foreach ($group->getKeys() as $key) {
                            $logOutputArray[$groupName][$key->getConfiguration()->getName()] = self::decodeJsonIfPossible(self::getLogOutput($key->getValue()));
                        }
                    }
                }

                Serializer::trimOutputForBetterPerformance(false);
                $logOutput = json_encode(self::getSerializer()->serialize($logOutputArray, true), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif ($value instanceof DataObject\Objectbrick) {
                $logOutputArray = [];

                if ($unmappedValue !== null) {
                    /** @var AbstractData $brick */
                    foreach ($value->getItems() as $brick) {
                        foreach ((array)$unmappedValue as $brickName => $itemData) {
                            if ($brick->getType() === $brickName) {
                                if (!isset($logOutputArray[$brickName])) {
                                    $logOutputArray[$brickName] = [];
                                }

                                foreach ($brick->getDefinition()->getFieldDefinitions() as $brickFieldDefinition) {
                                    foreach ($itemData as $field => $fieldValue) {
                                        if ($brickFieldDefinition instanceof Data\Localizedfields) {
                                            $fieldParts = explode('#', $field);
                                            foreach ($brickFieldDefinition->getFieldDefinitions() as $localizedBrickFieldDefinition) {
                                                if (strtolower($localizedBrickFieldDefinition->getName()) === strtolower($fieldParts[0])) {
                                                    $logOutputArray[$brickName][$fieldParts[0].(!empty($fieldParts[1]) ? '#'.$fieldParts[1] : '')] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($brick, $fieldParts[0], (!empty($fieldParts[1]) ? [$fieldParts[1]] : []))));
                                                }
                                            }
                                        } elseif (strtolower($brickFieldDefinition->getName()) === strtolower($field)) {
                                            $logOutputArray[$brickName][$field] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($brick, $field)));
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    foreach ($value->getBrickGetters() as $brickGetter) {
                        $brickItem = $value->$brickGetter();

                        if ($brickItem instanceof AbstractData) {
                            /** @var Definition $brickDefinition */
                            $brickDefinition = $brickItem->getDefinition();
                            $logOutputArray[$brickItem->getType()] = [];
                            foreach ($brickDefinition->getFieldDefinitions() as $brickFieldDefinition) {
                                if ($brickFieldDefinition instanceof Data\Localizedfields) {
                                    foreach ($brickFieldDefinition->getFieldDefinitions() as $localizedBrickFieldDefinition) {
                                        foreach (Tool::getValidLanguages() as $language) {
                                            $logOutputArray[$brickItem->getType()][$localizedBrickFieldDefinition->getName().'#'.$language] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($brickItem, $localizedBrickFieldDefinition->getName(), [$language])));
                                        }
                                    }
                                } else {
                                    $logOutputArray[$brickItem->getType()][$brickFieldDefinition->getName()] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($brickItem, $brickFieldDefinition->getName())));
                                }
                            }
                        }
                    }
                }

                Serializer::trimOutputForBetterPerformance(false);
                $logOutput = json_encode(self::getSerializer()->serialize($logOutputArray, true), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif ($value instanceof Fieldcollection\Data\AbstractData) {
                $logOutputArray = [];

                /** @var Definition $brickDefinition */
                $fieldCollectionDefinition = $value->getDefinition();
                $logOutputArray[$value->getType()] = [];
                foreach ($fieldCollectionDefinition->getFieldDefinitions() as $fieldCollectionFieldDefinition) {
                    if ($fieldCollectionFieldDefinition instanceof Data\Localizedfields) {
                        foreach($fieldCollectionFieldDefinition->getFieldDefinitions() as $localizedFieldCollectionFieldDefinition) {
                            foreach (Tool::getValidLanguages() as $language) {
                                $logOutputArray[$value->getType()][$localizedFieldCollectionFieldDefinition->getName().'#'.$language] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($value, $localizedFieldCollectionFieldDefinition->getName(), [$language])));
                            }
                        }
                    } else {
                        $logOutputArray[$value->getType()][$fieldCollectionFieldDefinition->getName()] = self::decodeJsonIfPossible(self::getLogOutput(self::getValue($value, $fieldCollectionFieldDefinition->getName())));
                    }
                }

                Serializer::trimOutputForBetterPerformance(false);
                $logOutput = json_encode(self::getSerializer()->serialize($logOutputArray, true), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif ($value instanceof \Iterator) {
                $logOutput = '['.PHP_EOL;
                foreach ($value as $item) {
                    $logOutput .= '  '.self::getLogOutput($item).PHP_EOL;
                }
                $logOutput .= ']';
            } elseif (is_array($value) && isset($value['value']) && array_key_exists('unit', $value)) {
                $logOutput = $value['value'].' '.$value['unit'];
            } elseif($value instanceof ParameterBagInterface) {
                $logOutput = (string)$value;
            } else {
                $logOutput = self::getSerializer()->serialize($value);
                if(!is_scalar($logOutput)) {
                    $logOutput = json_encode($logOutput, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                }
            }

            return $logOutput;
        } catch(\Throwable $e) {
            return '';
        }
    }

    private function getVersionNote(ElementInterface $item) {
        try {
            $itemMold = $this->itemMoldBuilder->getItemMold($this->dataport['id']);
        } catch (Exception $e) {
            $itemMold = null;
        }

        $versionNote = 'Dataport: '.$this->dataport['id'];
        if (!$item instanceof $itemMold) {
            return $versionNote;
        }

        foreach ($this->getMappings() as $mapping) {
            if (in_array($mapping['fieldName'], ['id', 'tags'], true) || strpos($mapping['fieldName'], '__virtual_') === 0) {
                continue;
            }

            try {
                $updatableObject = self::getUpdatableObject($item, $mapping);
            } catch(\Exception $e) {
                continue;
            }

            $getterArgs = [];
            if (!empty($mapping['locale'])) {
                $getterArgs[] = $mapping['locale'];
            }

            try {
                Helper::useInheritance(false);
                $value = self::getValue($updatableObject, $mapping, $getterArgs);
                Helper::useInheritance(true);
            } catch (\Throwable $e) {
                $value = 'ERROR: '.$e->getMessage();
            }

            $valueOld = $this->getLatestVersionData($item, Helper::getFieldKey($mapping));

            Importer::useBeforeSaveChecksum();
            $logOutputOld = self::getLogOutput($valueOld);
            Importer::useCurrentChecksum();
            $logOutputNew = self::getLogOutput($value);

            if($logOutputOld === $logOutputNew) {
                $versionNote .= "\n".Helper::getFieldKey($mapping).': unchanged -> unchanged';
            } else {
                if (strlen($logOutputOld) > 1000) {
                    $logOutputOld = '(too long)';
                }
                if (strlen($logOutputNew) > 1000) {
                    $logOutputNew = '(too long)';
                }
                $versionNote .= "\n".Helper::getFieldKey($mapping).': '.$logOutputOld.' -> '.$logOutputNew;
            }
        }

        $logs = [[]];
        $rawItemLogger = RawItemLogger::getInstance();
        try {
            foreach ($this->itemCache[$item]['rawItemIds'] as $rawItemId) {
                $logs[] = $rawItemLogger->getLogs($rawItemId);
            }
        } catch(\Exception $e) {
            if($e->getMessage() !== 'Object not found') {
                throw $e;
            }
        }
        $logs = array_merge(...$logs);

        foreach($logs as $logType => $logItems) {
            $versionNote .= "\n".$logType.':';
            foreach($logItems as $log) {
                $versionNote .= "\n  ".$log;
            }
        }

        return $versionNote;
    }

    public function isEmpty($value, Data $fieldDefinition) {
        if ($value instanceof Fieldcollection) {
            $value = $value->getItems();
        } elseif ($fieldDefinition instanceof Data\Wysiwyg) {
            $value = strip_tags($value);
        } elseif ($value instanceof QuantityValue || $value instanceof DataObject\Data\InputQuantityValue) {
            $value = $value->getValue();
        } elseif ($value instanceof Link) {
            $value = array_filter([$value->getPath(), $value->getText()]);
        } elseif ($value instanceof DataObject\Data\ExternalImage) {
            $value = $value->getUrl();
        } elseif ($value instanceof Asset) {
            $value = Helper::getAssetStorage()->fileExists($value->getRealFullPath()) && Helper::getAssetStorage()->fileSize($value->getRealFullPath()) > 0;
        } elseif ($value instanceof Hotspotimage) {
            $value = $value->getImage();
        } elseif (is_array($value)) {
            foreach($value as &$valueItem) {
                if ($valueItem instanceof ElementMetadata || $valueItem instanceof ObjectMetadata) {
                    $valueItem = $valueItem->getElement();
                    if (!$valueItem instanceof ElementInterface) {
                        $valueItem = null;
                        continue;
                    }
                }

                if ($valueItem instanceof Asset) {
                    $valueItem = Helper::getAssetStorage()->fileExists($valueItem->getRealFullPath()) && Helper::getAssetStorage()->fileSize($valueItem->getRealFullPath()) > 0;
                }
            }
            unset($valueItem);
            $value = array_filter($value);
        } elseif($fieldDefinition instanceof Data\Checkbox && $value === false) {
            return false;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if(is_numeric($value)) {
            return false;
        }

        return empty($value);
    }

    private function getOptimizer() {
        return new SimulatedAnnealing();
    }

    private function saveItems() {
        $editLockIds = [];
        $properties = [];

        $itemCacheIterator = new ResettableIterator($this->itemCache);
        foreach ($itemCacheIterator as $item) {
            if($item instanceof Export) {
                continue;
            }

            // due to error in PHP 7.1 it is possible that $this->itemCache[$item] does not exist although the foreach contains the item
            try {
                if ($this->itemCache[$item]['save']) {
                    $this->saveObject($item);
                }

                if (!empty($this->itemCache[$item]['editLockId'])) {
                    $editLockIds[] = $this->itemCache[$item]['editLockId'];
                }

                foreach ($this->itemCache[$item]['rawItemIds'] as $rawItemId) {
                    $this->rawItems[$rawItemId]['tags'] = array_merge($this->rawItems[$rawItemId]['tags'] ?? [], $this->itemCache[$item]['tags']);

                    if ($item->getId() > 0) {
                        $this->rawItems[$rawItemId]['objectIDs'][] = $item->getId();
                    }
                }
            } catch (\Throwable $e) {
                if ($e instanceof RetryableException) {
                    throw $e;
                }

                $this->saveObject($item);
            }

            if($item->getId()) {
                $rawItemIds = $this->itemCache[$item]['rawItemIds'];
                if(reset($rawItemIds)) {
                    $properties[] = [
                        'cid' => $item->getId(),
                        'ctype' => Service::getElementType($item),
                        'cpath' => $item->getRealFullPath(),
                        'type' => 'text',
                        'name' => self::HASH_PROP_PREFIX.$this->dataport['id'],
                        'data' => $this->rawItems[reset($rawItemIds)]['hash'],
                        'inheritable' => false,
                    ];
                }
            }
        }

        PimcoreDbRepository::getInstance()->createOrUpdate($properties, 'properties');

        $itemCacheIterator->reset();

        $this->writeBuffer();

        PimcoreDbRepository::getInstance()->execute('DELETE FROM edit_lock WHERE id IN (?)', [$editLockIds]);
    }

    public static function getSerializer() {
        if(self::$serializer === null) {
            self::$serializer = new Serializer();
        }
        return self::$serializer;
    }

    private static function getTwigEnvironment() {
        if(self::$twigEnvironment === null) {
            self::$twigEnvironment = new Environment(new \Twig\Loader\ArrayLoader(), array(
                'autoescape' => false
            ));
        }

        return self::$twigEnvironment;
    }

    public static function decodeJsonIfPossible($value) {
        if(!is_string($value) || is_numeric($value)) {
            return $value;
        }

        if(substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
            return $value;
        }

        $decodedValue = json_decode($value, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            $value = $decodedValue;
        }
        return $value;
    }

    public function getTranslator() {
        return $this->translator;
    }

    public function getDataport()
    {
        return $this->dataport;
    }

    public function getCachedItems()
    {
        return $this->itemCache;
    }

    private function getLatestVersionData(ElementInterface $element, $field) {
        $cachedItem = $this->getCachedItem($element);
        $currentValue = $cachedItem['latestVersionData'][$field] ?? null;
        if (is_array($currentValue) && !empty($currentValue['type']) && !empty($currentValue['id'])) {
            $currentValue = Service::getElementById($currentValue['type'], $currentValue['id']);
        } elseif (is_array($currentValue) && is_array(reset($currentValue)) && !empty(reset($currentValue)['type']) && !empty(reset($currentValue)['id'])) {
            $currentValue = array_map(static function ($currentValueItem) {
                return Service::getElementById($currentValueItem['type'], $currentValueItem['id']);
            }, $currentValue);
        }

        return $currentValue;
    }

    public function addToRelationCache($objectIdentifier, $object) {
        $this->relationCache[$objectIdentifier] = $object;
    }

    public static function getElementIdForPath($fullpath, $elementType) {
        $key = '';
        $path = $fullpath;
        if ($fullpath !== '/') {
            $lastPart = strrpos($fullpath, '/') + 1;
            $key = substr($fullpath, $lastPart);
            $path = substr($fullpath, 0, $lastPart);
        }

        $idColumn = 'id';
        if ($elementType === 'asset') {
            $keyColumn = 'filename';
        } elseif ($elementType === 'document') {
            $keyColumn = '`key`';
        } else {
            $idColumn = Helper::prefixObjectSystemColumn('id');
            $keyColumn = '`'.Helper::prefixObjectSystemColumn('key').'`';
        }

        $id = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.$idColumn.' FROM '.$elementType.'s WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' = ? AND '.$keyColumn.' = ?', [$path, $key]);

        if(!$id) {
            throw new OpenDxp\Model\Exception\NotFoundException("object doesn't exist");
        }

        return $id;
    }

    public function addRawItem($data) {
        $rawItemId = 'generated_'.Uuid::uuid4()->getInteger();

        $rawItemData = [];
        $fieldNo = 1;
        foreach($data as $value) {
            $rawItemData['field_'.$fieldNo] = [
                'rawItemId' => $rawItemId,
                'fieldNo' => $fieldNo,
                'value' => $value
            ];
            $fieldNo++;
        }

        $this->rawItems[$rawItemId] = ['id' => $rawItemId, 'data' => $rawItemData];

        return $this->rawItems[$rawItemId];
    }

    public function removeRawItem($id) {
        unset($this->rawItems[$id]);
    }

    public static function getImageUpdateInfo($image): string
    {
        if (!($image instanceof Asset\Image)) {
            return '';
        }

        $checksum = self::$useBeforeSaveChecksum && self::getBeforeSaveChecksum($image->getId())
            ? self::getBeforeSaveChecksum($image->getId())
            : self::getAssetChecksum($image);

        return ' (' . $checksum . ' from ' . $image->getProperty('sourcePath') . ')';
    }

    /**
     * @return AbstractElement[]|SplObjectStorage
     */
    public function getItemCache()
    {
        return $this->itemCache;
    }

    /**
     * @param AbstractElement[]|SplObjectStorage $itemCache
     */
    public function setItemCache($itemCache): void
    {
        $this->itemCache = $itemCache;
    }

    public function getListCacheObject(): array
    {
        return $this->listCache;
    }

    public function setListCacheObject(array $listCache): void
    {
        $this->listCache = $listCache;
    }

    public function getRelationCache(): array
    {
        return $this->relationCache;
    }

    public function setRelationCache(array $relationCache): void
    {
        $this->relationCache = $relationCache;
    }

    /**
     * @return mixed
     */
    public function getWriteBuffer()
    {
        return $this->writeBuffer;
    }

    /**
     * @param mixed $writeBuffer
     */
    public function setWriteBuffer($writeBuffer): void
    {
        $this->writeBuffer = $writeBuffer;
    }

    public function getPruneCacheIds(): array
    {
        return $this->pruneCacheIds;
    }

    public function setPruneCacheIds(array $pruneCacheIds): void
    {
        $this->pruneCacheIds = $pruneCacheIds;
    }

    /**
     * @return mixed
     */
    public function getRawItems()
    {
        return $this->rawItems;
    }

    /**
     * @param mixed $rawItems
     */
    public function setRawItems($rawItems): void
    {
        $this->rawItems = $rawItems;
    }

    private function isCompleteObjectImport() {
        foreach ($this->getMappings() as $mapping) {
            if ($mapping['fieldName'] === 'Complete Object') {
                return true;
            }
        }

        return false;
    }
}
