<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\Normalizer;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Sylphen\DataBridgeBundle\lib\Pim\TemporaryFileHelperTrait;
use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\OpenAiTextGenerator;
use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\TextGenerator;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use DateInterval;
use Exception;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use League\Flysystem\UnableToProvideChecksum;
use OutOfBoundsException;
use OpenDxp;
use OpenDxp\File;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Translation\Translator;
use Psr\Log\LoggerAwareTrait;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\mimeType;
use function Sylphen\DataBridgeBundle\lib\Pim\Import\toString;

abstract class AbstractFieldMapper implements FieldMapperInterface
{
    use TemporaryFileHelperTrait;

    /** @var Importer */
    protected $importer;

    /** @var ItemMoldBuilder */
    protected $itemMoldBuilder;

    /** @var Translator */
    protected $translator;

    private static $purgeCache = [];

    /** @var array */
    private static $httpHeaderCheckUseless = [];

    public function __construct(Importer $importer, ItemMoldBuilder $itemMoldBuilder = null, Translator $translator = null)
    {
        $this->importer = $importer;
        $this->itemMoldBuilder = $itemMoldBuilder ?? \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
        $this->translator = $translator ?? \OpenDxp::getContainer()->get('translator');
    }

    public function log(?AbstractModel $object, $message, $logType)
    {
        $context = [];
        if ($object instanceof ElementInterface && $object->getId() > 0) {
            $context['relatedObject'] = $object;
            if($this->importer->getDataport()) {
                $context['dataportId'] = $this->importer->getDataport()['id'];
            }
        }
        $this->importer->getLogger()->log($logType, $message, $context);
    }

    protected function infer($value, Data $fieldDefinition, ElementInterface $dataObject = null, array $mapping, array $format)
    {
        $textGenerator = OpenDxp::getContainer()->get(TextGenerator::class);

        if (method_exists($textGenerator, 'setLogger')) {
            $textGenerator->setLogger($this->importer->getLogger());
        }

        if (!$value && $dataObject instanceof OpenDxp\Model\DataObject\Concrete) {
            $contextFields = [];
            foreach ($dataObject->getClass()->getFieldDefinitions() as $classFieldDefinition) {
                if ($classFieldDefinition instanceof Data\Localizedfields) {
                    foreach ($classFieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        if ($localizedFieldDefinition instanceof Data\Input || $localizedFieldDefinition instanceof Data\Textarea || $localizedFieldDefinition instanceof Data\Wysiwyg) {
                            $contextFields[] = $localizedFieldDefinition->getName();
                        }
                    }
                } elseif($classFieldDefinition instanceof Data\Input || $classFieldDefinition instanceof Data\Textarea || $classFieldDefinition instanceof Data\Wysiwyg) {
                    $contextFields[] = $classFieldDefinition->getName();
                }
            }

            $contextData = [];
            require_once __DIR__.'/../../Import/helpers.php';
            foreach ($contextFields as $contextField) {
                $contextFieldValue = toString(Importer::decodeJsonIfPossible($this->importer->getObjectByIdentifier('.:.:.:'.$contextField, $dataObject)));
                if (!empty($contextFieldValue) || (string)$contextFieldValue === '0') {
                    $contextData[$contextField] = $contextFieldValue;
                }
            }

            $value = implode(
                "\n",
                array_map(
                    static function ($value, $field) {
                        return $field.': '.$value;
                    },
                    $contextData,
                    array_keys($contextData)
                )
            );
        }

        $restrictionsStart = mb_strpos($value, 'Restrictions:');
        $restrictions = null;
        if ($restrictionsStart !== false) {
            $restrictions = mb_substr($value, $restrictionsStart);
            $value = str_replace($restrictions, '', $value);

            $lengthLimit = OpenAiTextGenerator::MAX_TOKENS * 2 - mb_strlen($restrictions);
            if ($lengthLimit < 0) {
                $this->log($dataObject, 'Cannot infer '.$fieldDefinition->getTitle().' because of maximum input length of text generation model.', 'warning');
                return '';
            }

            if (mb_strlen($value) > $lengthLimit) {
                $value = mb_substr($value, 0, $lengthLimit);
            }
        }

        $prompt = 'For a '.$this->translator->trans(($dataObject ?? $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']))->getClassname(), [], 'admin', 'en').' "'.($dataObject ?? $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']))->getKey().'" with the following properties:

'.$value.'

Task: Identify the '.((strlen($fieldDefinition->getTitle()) < 190) ? $this->translator->trans($fieldDefinition->getTitle(), [], 'admin', 'en') : $fieldDefinition->getTitle()).' in '.\Locale::getDisplayLanguage($mapping['locale'] ?: OpenDxp\Tool::getDefaultLanguage(), 'en').' ';

        if ($fieldDefinition instanceof Data\Select && !$format['autoCreate']) {
            if ($fieldDefinition->getOptionsProviderClass()) {
                try {
                    $fieldDefinition->enrichFieldDefinition(['object' => $dataObject]);
                } catch (\Throwable $e) {
                    $this->log($dataObject, 'Could not enrich field "'.$fieldDefinition->getName().'". '.$e->getMessage(), 'warning');
                }
            }

            $prompt .= 'among the following possible values: '.implode(
                    ', ',
                    array_map(static function ($option) {
                        return $option['key'];
                    }, $fieldDefinition->getOptions())
                );
        } elseif ($fieldDefinition instanceof Data\Multiselect) {
            if ($fieldDefinition->getOptionsProviderClass()) {
                try {
                    $fieldDefinition->enrichFieldDefinition(['object' => $dataObject]);
                } catch (\Throwable $e) {
                    $this->log($dataObject, 'Could not enrich field "'.$fieldDefinition->getName().'". '.$e->getMessage(), 'warning');
                }
            }

            $prompt .= 'among the following possible values: '.implode(
                    ', ',
                    array_map(static function ($option) {
                        return $option['key'];
                    }, $fieldDefinition->getOptions())
                );
        } else {
            $prompt .= 'as short as possible';
        }

        $prompt .= '. ';

        if ($fieldDefinition instanceof Data\Multiselect) {
            $prompt .= 'Multiple values are allowed. Use character ~ to separate the values. ';
        }

        if ($restrictions) {
            $prompt .= $restrictions;
        }

        $prompt .= 'If you are unsure, return "unknown". Format your response as JSON object with key "'.$fieldDefinition->getName().'".';

        $cacheKey = 'text_generation_'.md5($prompt);
        $inferredValue = Helper::getFromCache($cacheKey);
        if (!$inferredValue) {
            $inferredValue = json_decode($textGenerator->generate($prompt), true)[$fieldDefinition->getName()] ?? '';
            if ($inferredValue === '') {
                $inferredValue = 'unknown';
            }

            Helper::saveInCache($cacheKey, $inferredValue);
        }

        if ($inferredValue === 'unknown') {
            throw new OutOfBoundsException('Infer result for field "'.$fieldDefinition->getName().($mapping['locale'] ? '#'.$mapping['locale'] : '').'": '.$inferredValue.' -> keep current value');
        }

        if ($inferredValue === null) {
            $inferredValue = '';
        }

        if ($fieldDefinition instanceof Data\Select && !$format['autoCreate']) {
            foreach ($fieldDefinition->getOptions() as $option) {
                if (strtolower(trim($option['key'])) === strtolower(trim($inferredValue))) {
                    $inferredValue = $option['value'];
                    break;
                }
            }
        }

        if ($fieldDefinition instanceof Data\Multiselect) {
            if (is_string($inferredValue)) {
                $inferredValue = explode('~', $inferredValue);
            }

            if (!$format['autoCreate']) {
                $optionKeys = [];
                foreach ($inferredValue as $inferredValueItem) {
                    foreach ($fieldDefinition->getOptions() as $option) {
                        if (strtolower(trim($option['key'])) === strtolower(trim($inferredValueItem))) {
                            $optionKeys[] = $option['value'];
                            break;
                        }
                    }
                }

                $inferredValue = $optionKeys;
            }
        }

        return $inferredValue;
    }

    protected function isPurged($mapping, AbstractModel $object)
    {
        if ($object instanceof OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData || $object instanceof OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData || $object instanceof OpenDxp\Model\DataObject\Classificationstore) {
            $object = $object->getObject();
        }

        if (!$object instanceof ElementInterface) {
            $this->importer->getLogger()->warning('Could not find element for '.get_class($object));
            return false;
        }

        return self::$purgeCache[Service::getElementType($object).'-'.$object->getId()][\Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping)] ?? self::$purgeCache[Service::getElementType($object).'-'.spl_object_id($object)][\Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping)] ?? false;
    }

    protected function setPurged($mapping, AbstractModel $object)
    {
        if ($object instanceof OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData || $object instanceof OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData || $object instanceof OpenDxp\Model\DataObject\Classificationstore) {
            $object = $object->getObject();
        }

        if (!$object instanceof ElementInterface) {
            $this->importer->getLogger()->warning('Could not find element for '.get_class($object));
            return false;
        }

        if ($object->getId() > 0) {
            self::$purgeCache[Service::getElementType($object).'-'.$object->getId()][\Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping)] = true;
        } else {
            self::$purgeCache[Service::getElementType($object).'-'.spl_object_id($object)][\Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping)] = true;
        }
    }

    protected function parseNumber($value)
    {
        if($value === null) {
            return null;
        }

        // If already numeric, return as-is to preserve precision
        if (is_numeric($value) && !is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $value = str_replace(',', '.', (string)$value);
        $value = preg_replace('/\.(?=.*\.)/', '', $value);

        $originalValue = $value;
        if($originalValue == 0) {
            return 0;
        }

        if (strpos(ltrim($originalValue, '0'), ltrim((string)$value, '0')) === 0) {
            if (false !== strpos($value, '.')) {
                return (float)$value;
            }
            return (int)$value;
        }

        return null;
    }

    protected function getAsset($value, $config = null)
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof Asset) {
            return $value;
        }

        if ($value instanceof OpenDxp\Model\DataObject\Data\ElementMetadata) {
            return $value->getElement();
        }

        if ($value instanceof OpenDxp\Model\Element\ElementDescriptor) {
            $asset = OpenDxp\Model\Element\Service::getElementById($value->getType(), $value->getId());
            if ($asset instanceof Asset) {
                return $asset;
            }
        }

        $originalValue = $value;
        if (is_scalar($value) || $value instanceof Stringable) {
            $value = (string)$value;

            if (\preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]+)+$/', $value)) {
                try {
                    $objectIdentifierParts = $this->importer->getObjectIdentifierParts($value);
                } catch(\Throwable $e) {
                    $objectIdentifierParts = null;
                }

                if ($objectIdentifierParts !== null) {
                    $value = ['query' => $value];
                } else {
                    $value = ['url' => $value];
                }
            } elseif(is_numeric($value)) {
                $value = ['query' => 'Asset:id:'.$value];
            } else {
                $value = ['url' => $value];
            }
        } elseif (is_array($value) && isset($value['fullpath'], $value['type']) && !isset($value['query']) && $value['type'] === 'asset') {
            $value = ['query' => 'Asset:path:'.$value['fullpath']];
        } elseif (is_array($value) && (isset($value[0]['query']) || isset($value[0]['url']))) {
            $value = $value[0];
        }

        if (!isset($value['query']) && isset($value['url']) && strpos($value['url'], Helper::getHostUrl()) === 0) {
            $assetPath = urldecode(substr($value['url'], strlen(Helper::getHostUrl())));
            if (Asset::getByPath($assetPath) !== null) {
                $value = ['query' => 'Asset:path:'.$assetPath];
            }
        }

        $asset = null;
        $assetNeedsSaving = false;
        if (isset($value['query'])) {
            $parts = \str_getcsv($value['query'], ':', '"');
            if (count($parts) === 2) {
                \array_unshift($parts, Asset::class);
            }

            if (!class_exists($parts[0])) {
                if (strtolower($parts[0]) === 'asset') {
                    $parts[0] = Asset::class;
                } else {
                    $parts[0] = Asset::class.'\\'.ucfirst($parts[0]);
                }
            }

            $asset = $this->importer->getOneObjectByIdentifier(implode(':', $parts));
            if (!$asset instanceof Asset && strtolower($parts[1]) === 'path' && isset($this->importer->getDataport()['sourceconfig']['assetSource'])) {
                $parts[2] = $this->importer->getDataport()['sourceconfig']['assetSource'].'/'.$parts[2];
                $this->importer->getLogger()->info('Trying to find "'.implode(':', $parts).'"');
                $asset = $this->importer->getOneObjectByIdentifier(implode(':', $parts));
            }

            if (!$asset instanceof Asset && isset($originalValue['url'])) {
                $value = $originalValue;
                goto importByUrl;
            }

            if (!$asset instanceof Asset) {
                $this->importer->getLogger()->warning("Unable to find asset by query '".$value['query']."'");
                return null;
            }

            if (!empty($config['preventDuplicates'])) {
                try {
                    $normalizedHash = $asset->getproperty(Normalizer::HASH_PROPERTY);
                    if (!$normalizedHash) {
                        $normalizedHash = Normalizer::getHash($asset);
                        PimcoreDbRepository::getInstance()->createOrUpdate([
                            'cid' => $asset->getId(),
                            'ctype' => 'asset',
                            'type' => 'text',
                            'name' => Normalizer::HASH_PROPERTY,
                            'data' => $normalizedHash,
                            'inheritable' => 0,
                        ], 'properties');
                    }

                    $originalId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT cid FROM properties WHERE ctype=\'asset\' AND name=? AND data=? ORDER BY cid LIMIT 1', [Normalizer::HASH_PROPERTY, $normalizedHash]);
                    if ($originalId != $asset->getId()) {
                        $originalAsset = Asset::getById($originalId);
                        $this->importer->getLogger()->info('Asset "'.$asset->getFullpath().'" (#'.$asset->getId().') is a duplicate of '.$originalAsset->getFullpath().' (#'.$originalId.'). Using the latter one to prevent duplicates.');
                        return $originalAsset;
                    }
                } catch (\Throwable $e) {
                    $this->importer->getLogger()->warning('Could not create normalized thumbnail: '.$e->getMessage());
                }
            }
        } elseif (isset($value['url'])) {
            importByUrl:
            $url = trim($value['url']);

            if (empty($url)) {
                return null;
            }

            $asset = $this->getAssetOrCreate($value['filename'] ?? null, $url, $config);
            if (!$asset instanceof Asset) {
                return null;
            }

            if ($asset->getDataChanged()) {
                $assetNeedsSaving = true;
            }

            if ($asset->getProperty('sourcePath') !== $url) {
                $asset->setProperty('sourcePath', 'text', $url);
                $assetNeedsSaving = true;
            }
        }

        renameAsset:
        if ($asset instanceof Asset) {
            if (!empty($value['filename'])) {
                if (strpos($value['filename'], '/') !== false) {
                    $lastPart = strrpos($value['filename'], '/') + 1;
                    $filename = substr($value['filename'], $lastPart);
                    $path = substr($value['filename'], 0, $lastPart);

                    $pathArray = explode('/', $path);
                    array_walk($pathArray, static function (&$pathPart) {
                        $pathPart = Service::getValidKey($pathPart, 'asset');
                    });
                    $path = rtrim(implode('/', $pathArray), '/');

                    if (strpos($path, '/') !== 0) {
                        $path = rtrim($this->importer->getAssetFolder()->getRealFullPath(), '/').'/'.$path;
                    }

                    if ($path && rtrim($asset->getPath(), '/') !== $path) {
                        $asset->setParent(Asset\Service::createFolderByPath($path));
                        $asset->setPath($asset->getParent()->getRealFullPath().'/');
                        $assetNeedsSaving = true;
                    }

                    if($filename === '') {
                        $filename = $asset->getFilename();
                    }
                } else {
                    $filename = $value['filename'];
                }

                $filename = Service::getValidKey($filename, 'asset');

                if ($asset->getFilename() !== $filename) {
                    $asset->setFilename($filename);
                    $assetNeedsSaving = true;
                }
            }

            if ($assetNeedsSaving) {
                $user = Helper::getUser();
                $asset->setUserModification($user->getId());

                try {
                    try {
                        Importer::setBeforeSaveChecksum((int)$asset->getId(), Importer::getAssetChecksum($asset));
                    } catch(UnableToProvideChecksum $e) {
                    }

                    $assetRootDirectory = rtrim(defined('OPENDXP_ASSET_DIRECTORY') ? OPENDXP_ASSET_DIRECTORY : OPENDXP_WEB_ROOT.'/var/assets', '/');
                    if(is_link($assetRootDirectory.$asset->getRealFullPath())) {
                        @unlink($assetRootDirectory.$asset->getRealFullPath());
                    }

                    $asset->save(['versionNote' => 'Dataport: '.$this->importer->getDataport()['id']]);
                    $this->importer->getLogger()->info('Asset "' . $asset->getRealFullPath() . '" (ID: ' . $asset->getId() . ') has been updated: ' . Importer::getBeforeSaveChecksum((int)$asset->getId()) . ' -> ' .Importer::getAssetChecksum($asset));
                    $asset->setDataChanged(false);
                    $assetClass = get_class($asset);
                    if($asset->getType() !== (new $assetClass)->getType()) {
                        $asset = Asset::getById($asset->getId(), ['force' => true]);
                    }
                } catch (\Throwable $e) {
                    if (strpos($e->getMessage(), 'Duplicate full path') === 0 || strpos($e->getMessage(), 'Integrity constraint violation: 1062 Duplicate entry') !== false) {
                        $stream = $asset->getStream();
                        $duplicatePath = $asset->getRealFullPath();
                        $asset = Asset::getByPath($duplicatePath);
                        $asset->setStream($stream);
                        goto renameAsset;
                    }

                    $this->log($asset, 'Could not save asset "'.$asset->getRealFullPath().'": '.$e->getMessage(), 'error');
                }
            }
        }

        return $asset;
    }

    private function getAssetOrCreate($assetFilename, $sourceUrl, $config)
    {
        if (empty($assetFilename)) {
            $assetFilename = '';
        }

        $assetFilenameArray = explode('/', $assetFilename);
        array_walk($assetFilenameArray, static function (&$value) {
            $value = Service::getValidKey($value, 'asset');
        });

        $filenameAutomatic = false;
        if (end($assetFilenameArray) === '') {
            $urlPath = parse_url($sourceUrl, PHP_URL_PATH);
            if ($urlPath) {
                $assetFilenameArray[count($assetFilenameArray) - 1] = Service::getValidKey(basename($urlPath), 'asset');
            } else {
                $assetFilenameArray[count($assetFilenameArray) - 1] = Service::getValidKey(basename($sourceUrl), 'asset');
            }
            $filenameAutomatic = true;
        }

        $assetFilename = rtrim(implode('/', $assetFilenameArray), '/');
        if (strpos($assetFilename, '/') !== 0) {
            $assetFilename = rtrim($this->importer->getAssetFolder()->getRealFullPath(), '/').'/'.$assetFilename;
        }

        try {
            $asset = Asset::getByPath($assetFilename);
            if ($asset instanceof Asset\Folder) {
                $asset = null;
            }
        } catch (\Throwable $e) {
            $this->importer->getLogger()->error('Could not load existing asset '.$assetFilename.': '.$e->getMessage());
            $asset = null;
        }

        if($asset === null) {
            $assetId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT cid FROM properties WHERE ctype=\'asset\' AND name=\'sourcePath\' AND data=? LIMIT 1', [$sourceUrl]);
            $asset = null;
            if ($assetId) {
                try {
                    $asset = Asset::getById($assetId);
                } catch (\Throwable $e) {
                    $this->importer->getLogger()->error('Could not load existing asset #'.$assetId.': '.$e->getMessage());
                }
            }
        }


        $originalUrl = $sourceUrl;
        try {
            $uri = new Uri($sourceUrl);

            if (!in_arrayi($uri->getScheme(), ['http', 'https']) || preg_match('~https?://~i', $uri->getPath())) {
                throw new \InvalidArgumentException('URI is not HTTP(S), falling back to local file / stream wrapper');
            }

            $sourceFileHeaders = null;
            if ($asset instanceof Asset && Helper::getAssetStorage()->fileExists($asset->getRealFullPath()) && Helper::getAssetStorage()->fileSize($asset->getRealFullPath()) > 0) {
                $request = Helper::getRequest();
                if ($request->server->get('REQUEST_TIME_FLOAT') && $asset->getModificationDate() >= $request->server->get('REQUEST_TIME_FLOAT')) {
                    $this->importer->getLogger()->info('Asset '.$asset->getFullpath().' is still up-to-date, will not import it again');
                    return $asset;
                }

                if(!isset(self::$httpHeaderCheckUseless[$uri->getHost()])) {
                    $sourceFileHeaders = @get_headers(
                        $sourceUrl,
                        true,
                        stream_context_create(
                            [
                                'http' => array(
                                    'method' => 'HEAD',
                                    'timeout' => 2
                                ),
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                ]
                            ]
                        )
                    );

                    if ($sourceFileHeaders === false) {
                        $this->importer->getLogger()->warning("Asset source url '".$sourceUrl."' is unreachable at the moment, skip updating the asset.");
                        return $asset;
                    }

                    $lastModifiedHeaderFound = false;
                    if (is_array($sourceFileHeaders)) {
                        foreach ($sourceFileHeaders as $headerName => $headerValue) {
                            if (strtolower(trim($headerName)) === 'last-modified') {
                                $sourceModificationDate = (new \DateTime($headerValue))->getTimestamp();

                                if($sourceModificationDate < time() - 10) {
                                    $lastModifiedHeaderFound = true;
                                }

                                if ($asset->getModificationDate() >= $sourceModificationDate) {
                                    $this->importer->getLogger()->info('Asset '.$asset->getFullpath().' is still up-to-date, will not import it again');
                                    $asset->setModificationDate($request->server->get('REQUEST_TIME_FLOAT', time()));
                                    return $asset;
                                }
                                break;
                            }

                            if (strtolower(trim($headerName)) === 'content-length') {
                                if (Helper::getAssetStorage()->fileSize($asset->getRealFullPath()) == $headerValue) {
                                    $this->importer->getLogger()->info('Asset '.$asset->getFullpath().' is still up-to-date, will not import it again');
                                    $asset->setModificationDate($request->server->get('REQUEST_TIME_FLOAT', time()));
                                    return $asset;
                                }

                                $lastModifiedHeaderFound = true;

                                break;
                            }
                        }
                    }

                    if (!$lastModifiedHeaderFound) {
                        self::$httpHeaderCheckUseless[$uri->getHost()][] = $uri->getHost();
                    }
                }
            }

            if($filenameAutomatic) {
                if (!$sourceFileHeaders) {
                    $sourceFileHeaders = @get_headers(
                        $sourceUrl,
                        true,
                        stream_context_create(
                            [
                                'http' => array(
                                    'method' => 'HEAD',
                                    'timeout' => 2
                                ),
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                ]
                            ]
                        )
                    );

                    if ($sourceFileHeaders === false) {
                        $this->importer->getLogger()->warning("Asset source url '".$sourceUrl."' is unreachable at the moment, skip creating the asset.");
                        return null;
                    }
                }

                if (is_array($sourceFileHeaders)) {
                    foreach ($sourceFileHeaders as $headerName => $headerValue) {
                        if (strtolower(trim($headerName)) === 'content-disposition') {
                            if (!is_array($headerValue)) {
                                $headerValue = [$headerValue];
                            }

                            foreach ($headerValue as $disposition) {
                                // Check if the disposition is an attachment and contains a filename
                                if (strpos($disposition, 'attachment') !== false) {
                                    $filenameFromHeader = $this->getFilenameFromContentDisposition($disposition);
                                    if ($filenameFromHeader) {
                                        $assetFilename = dirname($assetFilename).'/'.Service::getValidKey($filenameFromHeader, 'asset');
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $sourceUrl = (string)$uri;
        } catch (\InvalidArgumentException $e) {
            $sourceUrl = $this->importer->getAssetSourceFile($sourceUrl);
        }

        $fileStream = null;
        if ($asset instanceof Asset) {
            $fileStream = self::getStreamFromFileOrUrl($sourceUrl, $this->importer->getLogger());

            if (!\is_resource($fileStream)) {
                $this->log($asset, 'Unable to load "'.$originalUrl.'", using existing asset '.$asset->getRealFullPath(), 'warning');
                return $asset;
            }

            require_once __DIR__.'/../../Import/helpers.php';
            $useExistingAsset = false;
            if (stream_is_local($fileStream) && $asset->getType() !== 'unknown') {
                try {
                    $tmpFile = self::getLocalFileFromStream($fileStream);
                    if (hash_file('md5', $tmpFile) === Importer::getAssetChecksum($asset)) {
                        $this->log($asset, 'Asset file '.$asset->getRealFullPath().' is unchanged -> will not reimport', 'info');

                        $asset->setFilename(basename($assetFilename));
                        return $asset;
                    }
                } catch (InvalidArgumentException $e) {
                    $this->log($asset, 'Asset file '.$asset->getRealFullPath().' not readable', 'info');
                }
            }

            if ($config['overwrite'] || !$asset->getFileSize()) {
                if (feof($fileStream)) {
                    // open new file stream because might have been closed meanwhile
                    $fileStream = self::getStreamFromFileOrUrl($sourceUrl, $this->importer->getLogger());
                }

                // see https://github.com/pimcore/pimcore/pull/9700
                $isRewindable = @rewind($fileStream);
                if (!$isRewindable) {
                    $fileStream = fopen(Helper::getTemporaryFileFromStream($fileStream), 'rb', false, File::getContext());
                }

                $asset->setStream($fileStream);

                $asset->setFilename(basename($assetFilename));
                return $asset;
            }
        }

        if (!$fileStream || feof($fileStream)) {
            $fileStream = self::getStreamFromFileOrUrl($sourceUrl, $this->importer->getLogger());
        }

        if (!\is_resource($fileStream)) {
            $this->importer->getLogger()->error("Unable to load asset file from '".$originalUrl."'");
            return null;
        }

        if (!empty($config['preventDuplicates'])) {
            try {
                $tempAsset = new Asset();
                $tempAsset->setStream($fileStream);
                require_once __DIR__.'/../../Import/helpers.php';
                $tempAsset->setType(Asset::getTypeFromMimeMapping(mimeType($fileStream), $sourceUrl));

                $normalizedHash = Normalizer::getHash($tempAsset);
                $duplicateId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT cid FROM properties WHERE ctype="asset" AND name=? AND data=? ORDER BY cid LIMIT 1', [Normalizer::HASH_PROPERTY, $normalizedHash]);
                if ($duplicateId) {
                    $originalAsset = Asset::getById($duplicateId);
                    $this->importer->getLogger()->info('Asset "'.$originalUrl.'" is a duplicate of '.$originalAsset->getFullpath().' (#'.$duplicateId.'). Using the latter one to prevent duplicates.');
                    return $originalAsset;
                }

                // open new file stream because might have been closed meanwhile
                // see https://github.com/pimcore/pimcore/pull/9700
                $isRewindable = false;
                if (is_resource($fileStream)) {
                    $isRewindable = @rewind($fileStream);
                }

                if (!$isRewindable && is_resource($fileStream)) {
                    $fileStream = fopen(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getTemporaryFileFromStream($fileStream), 'rb', false, File::getContext());
                }
            } catch (\Throwable $e) {
                $this->importer->getLogger()->warning('Could not create normalized thumbnail: '.$e->getMessage());
            }
        }

        $assetFilename = basename($assetFilename);
        $lastDot = strrpos($assetFilename, '.');
        $fileEnding = substr($assetFilename, $lastDot);
        $originalFilename = substr($assetFilename, 0, $lastDot);

        $newFileName = $assetFilename;
        $i = 1;
        preventCollision:
        do {
            $existingAsset = Asset::getByPath(rtrim($this->importer->getAssetFolder()->getRealFullPath(), '/').'/'.$newFileName);
            if ($existingAsset === null) {
                break;
            }

            if ((!$existingAsset->getProperty('sourcePath') || $existingAsset->getProperty('sourcePath') === $originalUrl) && Helper::getAssetStorage()->fileExists($existingAsset->getRealFullPath()) && Helper::getAssetStorage()->fileSize($existingAsset->getRealFullPath()) > 0) {
                $request = Helper::getRequest();
                if ($request->server->get('REQUEST_TIME_FLOAT') && $existingAsset->getModificationDate() >= $request->server->get('REQUEST_TIME_FLOAT')) {
                    $this->importer->getLogger()->info('Asset '.$existingAsset->getFullpath().' is still up-to-date, will not import it again');
                    return $existingAsset;
                }

                if (empty($sourceFileHeaders) && $uri instanceof Uri && !isset(self::$httpHeaderCheckUseless[$uri->getHost()])) {
                    $sourceFileHeaders = @get_headers(
                        $sourceUrl,
                        true,
                        stream_context_create(
                            [
                                'http' => array(
                                    'method' => 'HEAD',
                                    'timeout' => 2
                                ),
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                ]
                            ]
                        )
                    );
                }

                if (is_array($sourceFileHeaders)) {
                    foreach ($sourceFileHeaders as $headerName => $headerValue) {
                        if (strtolower(trim($headerName)) === 'last-modified') {
                            $sourceModificationDate = (new \DateTime($headerValue))->getTimestamp();
                            if ($existingAsset->getModificationDate() >= $sourceModificationDate) {
                                $this->importer->getLogger()->info('Asset '.$existingAsset->getFullpath().' is still up-to-date, will not import it again');
                                $existingAsset->setModificationDate($request->server->get('REQUEST_TIME_FLOAT', time()));
                                return $existingAsset;
                            }
                            break;
                        }

                        if (strtolower(trim($headerName)) === 'content-length') {
                            if (Helper::getAssetStorage()->fileSize($existingAsset->getRealFullPath()) == $headerValue) {
                                $this->importer->getLogger()->info('Asset '.$existingAsset->getFullpath().' is still up-to-date, will not import it again');
                                $existingAsset->setModificationDate($request->server->get('REQUEST_TIME_FLOAT', time()));
                                return $existingAsset;
                            }
                            break;
                        }
                    }
                }
            }

            $newFileName = $originalFilename.'-'.$i.$fileEnding;
        } while ($i++ < 100);
        if ($i === 100 && Asset\Service::pathExists(rtrim($this->importer->getAssetFolder()->getRealFullPath(), '/').'/'.$newFileName)) {
            throw new Exception('Could not find unused filename for '.$assetFilename.', tried suffixes "-1" till "-'.$i.'"');
        }

        // see https://github.com/pimcore/pimcore/pull/9700
        $isRewindable = false;
        if (is_resource($fileStream)) {
            $isRewindable = @rewind($fileStream);
        }

        if (!$isRewindable && is_resource($fileStream)) {
            $fileStream = fopen(\Sylphen\DataBridgeBundle\lib\Pim\Helper::getTemporaryFileFromStream($fileStream), 'rb', false, File::getContext());
        }

        $user = Helper::getUser();
        $userId = $user->getId();

        $asset = Asset::create($this->importer->getAssetFolder()->getId(), array(
            'filename' => $newFileName,
            'stream' => $fileStream,
            'userOwner' => $userId,
            'userModification' => $userId,
        ), false);

        if (!empty($config['preventDuplicates']) && isset($normalizedHash)) {
            $asset->setProperty(Normalizer::HASH_PROPERTY, 'text', $normalizedHash);
        }

        return $asset;
    }

    private function getFilenameFromContentDisposition($header)
    {
        // 1. Try RFC 5987 / filename*=
        if (preg_match('/filename\*\s*=\s*([^\'"]+)\'\'([^\s]+)/i', $header, $matches)) {
            $charset = $matches[1];
            $encoded = $matches[2];

            // Decode the RFC5987 encoded filename (percent-encoding)
            $decoded = rawurldecode($encoded);

            // Convert charset if needed
            if (strtoupper($charset) !== 'UTF-8') {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
            }
            return $decoded;
        }

        // 2. Fallback: Classic filename="..."
        if (preg_match('/filename\s*=\s*"([^"]*)"/i', $header, $matches)) {
            return $matches[1];
        }

        // 3. Fallback: filename= without quotes
        if (preg_match('/filename\s*=\s*([^;]+)/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}