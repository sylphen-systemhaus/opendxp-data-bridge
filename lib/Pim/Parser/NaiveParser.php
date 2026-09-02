<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ArrayIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use DOMDocument;
use DOMNode;
use ForceUTF8\Encoding;
use InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use OpenDxp\File;
use OpenDxp\Logger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Asset\Image;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyObjectRelation;
use OpenDxp\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyRelation;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ManyToManyObjectRelation;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Objectbricks;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\Objectbrick\Definition\Listing;
use OpenDxp\Model\Document\Page;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Bundle\CustomReportsBundle\Tool\Config;
use Prewk\XmlStringStreamer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;
use Symfony\Component\Translation\TranslatorInterface;

class NaiveParser
{
    use ResourceBasedParser;

    /** @var array */
    private $config;

    /** @var TranslatorInterface */
    private $translator;


    public function __construct($source, array $params)
    {
        chdir(OPENDXP_PROJECT_ROOT);

        $this->setSourceFile($source);
        $this->config = $params;

        $this->translator = \OpenDxp::getContainer()->get('translator');
        $this->logger = new NullLogger();
    }

    public function findXmlNodes($nodePrefix) {
        $streamer = XmlStringStreamer::createStringWalkerParser($this->getStream());
        $items = [];

        $nodePrefix = str_replace('/', '', $nodePrefix);
        while ($node = $streamer->getNode()) {
            // search for closing tags because XML items should have body, otherwise they are no items
            if(preg_match_all('/<('.\preg_quote('/'.$nodePrefix, '/').'\w+)[\s>]/i', $node, $matches) !== false) {
                foreach($matches[1] as $match) {
                    $items[] = '/'.$match; // add leading / to generate XPaths which are independent of parent tags
                }
            }
        }

        $items = \array_unique($items);

        return array_map(static function($item) {
            return ['value' => $item];
        }, $items);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function guessConfig() {
        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);

        $guessedConfig = [];
        if($this->config['sourceType'] === 'csv') {
            $delimiter = $this->config['separator'] ?? null;
            if($delimiter === null || $delimiter === '') {
                $delimiter = ';';
            }

            $delimiter = str_replace(["\\t", "\\n", "\\r"], ["\t", "\n", "\r"], $delimiter);

            $handle = $this->getStream();
            if (!\is_resource($handle)) {
                throw new \Exception('Cannot read "'.$this->getFileOrUrl().'"');
            }

            if (strlen($this->config['quote']) > 1) {
                $this->config['quote'] = '"';
            }

            $data = fgetcsv($handle, 0, $delimiter, $this->config['quote'] ?? '"');
            if(count($data) === 1) {
                $delimiters = [';' => 0, ',' => 0, "\t" => 0, '|' => 0, '^' => 0];

                if (!@rewind($handle)) {
                    $tmpFile = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.uniqid();
                    $originalHandle = $handle;
                    $handle = fopen($tmpFile, 'wb+', false);
                    stream_copy_to_stream($originalHandle, $handle);
                    rewind($handle);
                }
                $firstLine = fgets($handle);
                fclose($handle);
                foreach ($delimiters as $delimiter => &$count) {
                    $count = count(str_getcsv($firstLine, $delimiter));
                }
                unset($count);

                $delimiter = array_search(max($delimiters), $delimiters);

                $guessedConfig['separator'] = $delimiter;

                $data = str_getcsv($firstLine, $delimiter);
            }

            $guessedConfig['fields'] = [];
            $data = array_map(
                static function ($data) {
                    $data = Encoding::toUTF8($data);
                    return $data;
                },
                $data
            );
            foreach($data as $index => $field) {
                if($this->config['hasHeader']) {
                    $field = trim($field);
                    $guessedConfig['fields'][] = [
                        'name' => $field,
                        'column' => $field,
                    ];
                } else {
                    $guessedConfig['fields'][] = [
                        'name' => $this->translator->trans('Field', [], 'admin').' '.$index,
                        'column' => $index,
                    ];
                }
            }
        } elseif($this->config['sourceType'] === 'excel') {
            $filePathOrUrl = self::getLocalFileFromStream($this->getStream());
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePathOrUrl);
            $worksheet = null;
            if($this->config['sheet']) {
                $worksheet = $spreadsheet->getSheetByName($this->config['sheet']);
            }
            if (!$worksheet instanceof Worksheet) {
                $worksheet = $spreadsheet->getSheet(0);
            }

            $bounds = [
                'startRow' => 1,
                'endRow' => null,
                'startColumn' => 'A',
                'endColumn' => null
            ];
            if (!empty($this->config['dataArea']) && preg_match('/([A-Z]+)(\d*):(([A-Z]+)(\d*))?/', $this->config['dataArea'], $matches)) {
                $bounds['startColumn'] = $matches[1];
                $bounds['startRow'] = $matches[2] ?? null;
                $bounds['endColumn'] = $matches[4] ?? null;
                $bounds['endRow'] = $matches[5] ?? null;
            }

            if (!$bounds['endRow']) {
                $bounds['endRow'] = $worksheet->getHighestDataRow();
            }

            if (!$bounds['endColumn']) {
                $bounds['endColumn'] = $worksheet->getHighestDataColumn();
            }

            $rowIterator = $worksheet->getRowIterator($bounds['startRow'], $bounds['endRow']);
            $rowIterator->seek($bounds['startRow']);
            $row = $rowIterator->current();
            $cellIterator = $row->getCellIterator($bounds['startColumn'], $bounds['endColumn']);
            foreach ($cellIterator as $cell) {
                if($this->config['hasHeader']) {
                    $guessedConfig['fields'][] = [
                        'name' => (string)$cell->getValue(),
                        'column' => (string)$cell->getValue(),
                    ];
                } else {
                    $guessedConfig['fields'][] = [
                        'name' => $this->translator->trans('Field', [], 'admin').' '.$cell->getColumn(),
                        'column' => $cell->getColumn(),
                    ];
                }
            }
        } elseif($this->config['sourceType'] === 'xml') {
            if(preg_match('/^[\pL\pN\/_-]+$/u', $this->config['itemxpath'])) {
                $parser = new XmlStringStreamer\Parser\UniqueNode([
                    'uniqueNode' => basename($this->config['itemxpath']),
                    'checkShortClosing' => false // should be set to true if input file has empty item tags
                ]);

                parseXml:
                $stream = new XmlStringStreamer\Stream\File($this->getStream());
                $streamer = new XmlStringStreamer($parser, $stream);

                if($streamer === false) {
                    throw new \Exception('Could not load file');
                }

                $itemXml = $streamer->getNode();
                if($itemXml === false) {
                    throw new \Exception('Could not parse items. Is the item XPath expression correct?');
                }

                $itemXml = Encoding::toUTF8($itemXml);

                $domDocument = new \DOMDocument();
                libxml_clear_errors();
                libxml_use_internal_errors(true);
                $domLoaded = @$domDocument->loadXML('<?xml version="1.0" encoding="utf-8"?>'.$itemXml);
                if (!$domLoaded) {
                    $domDocument->loadHTML(
                        $itemXml,
                        LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                        LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                        LIBXML_NOERROR |        # Suppress any errors
                        LIBXML_NOWARNING        # or warnings about prefixes.
                    );
                }

                $errors = libxml_get_errors();
                if (!$parser instanceof XmlStringStreamer\Parser\StringWalker && $errors) {
                    $errorMessages = array_map(static function (\LibXMLError $error) {
                        return trim($error->message);
                    }, $errors);

                    $parsingFailed = in_array('htmlParseStartTag: invalid element name', $errorMessages, true) || in_array('EndTag: \'</\' not found', $errorMessages, true);
                    if (!$parsingFailed) {
                        foreach ($errorMessages as $errorMessage) {
                            if (strpos($errorMessage, 'Premature end of data in tag') !== false) {
                                $parsingFailed = true;
                                break;
                            }
                        }
                    }

                    if ($parsingFailed) {
                        $parser = new XmlStringStreamer\Parser\StringWalker();
                        goto parseXml;
                    }
                }

                $domXPath = new \DOMXPath($domDocument);

                $item = $domDocument->documentElement;
            } else {
                if (\PHP_VERSION_ID < 80000) {
                    libxml_disable_entity_loader(false);
                }
                libxml_clear_errors();
                libxml_use_internal_errors(true);
                $domDocument = new \DOMDocument();
                $source = $this->getFileOrUrl();
                if (!@$domDocument->load(
                        $source,
                        LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                        LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                        LIBXML_NOERROR |        # Suppress any errors
                        LIBXML_NOWARNING        # or warnings about prefixes.
                    ) && !$domDocument->loadHTMLFile(
                        $source,
                        LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                        LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                        LIBXML_NOERROR |        # Suppress any errors
                        LIBXML_NOWARNING        # or warnings about prefixes.
                    )) {
                    throw new \Exception('Cannot load '.$source);
                }

                $domXPath = new \DOMXPath($domDocument);

                if(empty($this->config['itemxpath'])) {
                    try {
                        $item = $this->getXmlItem($domDocument->documentElement);

                        if($item === null) {
                            foreach ($domDocument->documentElement->childNodes as $childNode) {
                                if ($childNode->nodeType === XML_ELEMENT_NODE) {
                                    $item = $childNode;
                                    break;
                                }
                            }
                        }

                        $guessedConfig['itemxpath'] = '/'.$domDocument->documentElement->nodeName.'//'.$item->nodeName;
                    } catch (\Throwable $e) {
                        throw new \Exception('Could not parse items.'.$e);
                    }
                } else {
                    $item = $domXPath->query($this->config['itemxpath'])->item(0);
                }
            }

            if($item instanceof \DOMNode) {
                $guessedConfig['fields'] = $this->getXpaths($item, $domXPath, $item);
            } else {
                throw new \Exception('Could not find any items');
            }
        } elseif($this->config['sourceType'] === 'pimcore') {
            $guessedConfig['fields'] = $this->getDataQuerySelectors(['itemClass' => $this->config['sourceClass'], 'masterDocument' => $this->config['masterDocument']]);
        } elseif ($this->config['sourceType'] === 'report') {
            $guessedConfig['fields'] = [];

            $config = Config::getByName($this->config['file']);
            if($config instanceof Config) {
                foreach((array)$config->getColumnConfiguration() as $columnConfig) {
                    if($columnConfig['export']) {
                        $guessedConfig['fields'][] = [
                            'name' => $columnConfig['label'] ?: $columnConfig['name'],
                            'column' => $columnConfig['name'],
                        ];
                    }
                }
            }
        } elseif ($this->config['sourceType'] === 'json') {
            $handle = $this->getStream();

            if ($this->config['item-json-path'] === '.') {
                $streamContents = stream_get_contents($handle);
                if (strpos($streamContents, '[') !== 0) {
                    $streamContents = '['.$streamContents.']';
                }
                $rawItems = Items::fromString($streamContents, ['pointer' => '', 'decoder' => new ErrorWrappingDecoder(new JsonDecoder())]);
            } else {
                if ($this->config['item-json-path'] && strpos($this->config['item-json-path'], '/') !== 0) {
                    $this->config['item-json-path'] = '/'.$this->config['item-json-path'];
                }
                $this->config['item-json-path'] = preg_replace(
                    '/\.(?=(?:[^"]*"[^"]*")*[^"]*$)/',
                    '/',
                    $this->config['item-json-path']
                );
                $this->config['item-json-path'] = str_replace('"', '', $this->config['item-json-path']);
                $rawItems = Items::fromStream($handle, ['pointer' => $this->config['item-json-path'], 'decoder' => new ErrorWrappingDecoder(new JsonDecoder())]);
            }

            $rawItemIterator = $rawItems->getIterator();

            if ($rawItemIterator->valid() && (string)$rawItemIterator->key() !== '0') {
                $rawItemIterator = new ArrayIterator([(object)iterator_to_array($rawItemIterator)]);
            }

            $item = (array)$rawItemIterator->current();

            $guessedConfig['fields'] = $this->getJsonFields($item);
        } elseif ($this->config['sourceType'] === 'fixed-length') {
            $handle = $this->getStream();

            $data = fgets($handle);

            $data = Encoding::toUTF8($data);
            $data = preg_split('/\s{2}(?=\S)/', $data);

            foreach ($data as $index => $field) {
                if (!empty($this->config['hasHeader'])) {
                    $guessedConfig['fields'][] = [
                        'name' => trim($field),
                        'length' => mb_strlen($field) + 2,
                    ];
                } else {
                    $guessedConfig['fields'][] = [
                        'name' => $this->translator->trans('Field', [], 'admin').' '.$index,
                        'length' => mb_strlen($field) + 2,
                    ];
                }
            }
        }

        return $guessedConfig;
    }

    /**
     * @param DOMNode $rootElement
     * @return DOMNode
     */
    private function getXmlItem(DOMNode $rootElement) {
        foreach ($rootElement->childNodes as $childNode) {
            if ($childNode->nodeType === XML_ELEMENT_NODE) {
                foreach ($rootElement->childNodes as $otherChildNode) {
                    if ($otherChildNode->nodeType === XML_ELEMENT_NODE && $otherChildNode->nodeName === $childNode->nodeName && $otherChildNode !== $childNode) {
                        return $childNode;
                    }
                }

                $subItem = $this->getXmlItem($childNode);
                if($subItem !== null) {
                    foreach ($subItem->childNodes as $subItemChildNode) {
                        if ($subItemChildNode->nodeType === XML_ELEMENT_NODE) {
                            return $subItem;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function getDataQuerySelectors($targetConfig, $dataQuerySelectorPrefix = '', $prefixName = '') {
        $dataQuerySelectors = [];
        /** @var Helper $helper */
        $helper = Helper::getInstance();

        if(substr_count($dataQuerySelectorPrefix, ':') > 4) {
            return $dataQuerySelectors;
        }

        $dataQuerySelectorParts = explode(':', \str_replace('.:.:.', '', $dataQuerySelectorPrefix));
        for($blockLength=1;$blockLength <= 4;$blockLength++) {
            if(count($dataQuerySelectorParts) > $blockLength) {
                $lastOccurence = \array_slice($dataQuerySelectorParts, count($dataQuerySelectorParts) - $blockLength, $blockLength);
                $lastButOneOccurence = \array_slice($dataQuerySelectorParts, count($dataQuerySelectorParts) - $blockLength*2, $blockLength);

                if($lastOccurence == $lastButOneOccurence) {
                    return $dataQuerySelectors;
                }
            }
        }

        if($targetConfig['itemClass'] instanceof \OpenDxp\Model\DataObject\Fieldcollection\Definition || $targetConfig['itemClass'] instanceof ClassDefinition\Data\Localizedfields) {
            $fieldDefinitions = $targetConfig['itemClass']->getFieldDefinitions();
        } else {
            $fieldDefinitions = $helper->getFieldDefinitions($targetConfig);

            if(is_a($targetConfig['itemClass'], PageSnippet::class, true)) {
                $fieldDefinitions[] = Importer::getFieldDefinition($targetConfig['itemClass'], 'html');
            }
        }

        foreach($fieldDefinitions as $fieldDefinition) {
            if($fieldDefinition->getName() === 'delete element') {
                continue;
            }
            $newDataQuerySelectorPrefix = ($dataQuerySelectorPrefix?$dataQuerySelectorPrefix.':':'').$fieldDefinition->getName();
            if($fieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation) {
                if(\method_exists($fieldDefinition, 'getClasses')) {
                    foreach((array)$fieldDefinition->getClasses() as $relationClass) {
                        $allowedClass = Helper::getClassDefinitionByName($relationClass['classes']);
                        if($allowedClass instanceof ClassDefinition) {
                            $additionalSelectors = $this->getDataQuerySelectors(['itemClass' => $allowedClass->getId()], $newDataQuerySelectorPrefix, ($prefixName?$prefixName.' > ':''). $this->translator->trans($fieldDefinition->getTitle()?:ucfirst($fieldDefinition->getName()), [], 'admin'));
                            $parameters = [];
                            if(count($additionalSelectors) > 0 && strpos($newDataQuerySelectorPrefix, ':each:') === false) {
                                foreach($additionalSelectors as $additionalSelector) {
                                    $parameters[] = str_replace($newDataQuerySelectorPrefix.':', '', $additionalSelector['parameters']);
                                }

                                usort($parameters, static function ($dataQuerySelector1, $dataQuerySelector2) {
                                    $containsAll1 = strpos($dataQuerySelector1, ':each:');
                                    $containsAll2 = strpos($dataQuerySelector2, ':each:');
                                    if($containsAll1 === false && $containsAll2 !== false) {
                                        return -1;
                                    }

                                    if($containsAll1 !== false && $containsAll2 === false) {
                                        return 1;
                                    }
                                    return 0;
                                });
                            }
                            $dataQuerySelectors[] = [
                                'name' => ($prefixName ? $prefixName.' > ' : '').$this->translator->trans($fieldDefinition->getTitle() ?: ucfirst($fieldDefinition->getName()), [], 'admin'),
                                'parameters' => count($parameters) <=8 ? $newDataQuerySelectorPrefix.($fieldDefinition instanceof ClassDefinition\Data\ManyToOneRelation ? '' : ':each').':('.implode(';', $parameters).')' : $newDataQuerySelectorPrefix.($fieldDefinition instanceof ClassDefinition\Data\ManyToOneRelation ? ':levels#1' : ':each(self:levels#1)'),
                                'exportKey' => false,
                            ];
                        }
                    }
                }

                if(\method_exists($fieldDefinition, 'getDocumentsAllowed') && $fieldDefinition->getDocumentsAllowed()) {
                    $additionalSelectors = $this->getDataQuerySelectors(['itemClass' => Page::class], $newDataQuerySelectorPrefix, ($prefixName?$prefixName.' > ':''). $this->translator->trans($fieldDefinition->getTitle()?:ucfirst($fieldDefinition->getName()), [], 'admin'));
                    foreach($additionalSelectors as $additionalSelector) {
                        $dataQuerySelectors[] = [
                            'name' => $additionalSelector['name'],
                            'parameters' => $newDataQuerySelectorPrefix.':'.($fieldDefinition instanceof ClassDefinition\Data\ManyToOneRelation ? '' : 'each').':('.str_replace($newDataQuerySelectorPrefix.':', '', $additionalSelector['parameters']).')',
                            'exportKey' => $additionalSelector['exportKey'],
                        ];
                    }
                }

                if (\method_exists($fieldDefinition, 'getAssetsAllowed') && $fieldDefinition->getAssetsAllowed()) {
                    $assetTypes = (array)$fieldDefinition->getAssetTypes();
                    if (count($assetTypes) === 0) {
                        $assetTypes = [['assetTypes' => '']];
                    }
                    $methods = [];
                    foreach($assetTypes as $assetType) {
                        $class = rtrim('\\OpenDxp\\Model\\Asset\\'.ucfirst($assetType['assetTypes']), '\\');

                        try {
                            foreach(['fullpath', 'thumbnail', 'id', 'url'] as $method) {
                                if(method_exists($class, 'get'.ucfirst($method))) {
                                    $methods[] = $method;
                                }
                            }
                        } catch(\Throwable $e) {
                            Logger::warning($e->getMessage());
                        }
                    }

                    if ($fieldDefinition instanceof AdvancedManyToManyRelation) {
                        $methods = array_merge($methods, $fieldDefinition->getColumnKeys());
                    }

                    $dataQuerySelectors[] = [
                        'name' => ($prefixName ? $prefixName.' > ' : '').$this->translator->trans($fieldDefinition->getTitle() ?: ucfirst($fieldDefinition->getName()), [], 'admin').' '.$method,
                        'parameters' => $newDataQuerySelectorPrefix.':'.($fieldDefinition instanceof ClassDefinition\Data\ManyToOneRelation ? '' : 'each').':('.implode(',', array_unique($methods)).')',
                        'exportKey' => false,
                    ];
                }
            } elseif($fieldDefinition instanceof ClassDefinition\Data\Localizedfields) {
                $dataQuerySelectors = \array_merge($dataQuerySelectors, $this->getDataQuerySelectors(['itemClass' => $fieldDefinition], $dataQuerySelectorPrefix, $prefixName));
            } elseif($fieldDefinition instanceof Objectbricks) {
                $dataQuerySelectors[] = [
                    'name' => ($prefixName ? $prefixName.' > ' : '').$this->translator->trans($fieldDefinition->getTitle() ?: ucfirst($fieldDefinition->getName()), [], 'admin'),
                    'parameters' => $newDataQuerySelectorPrefix.':labels',
                    'exportKey' => false,
                ];
            } elseif($fieldDefinition instanceof ClassDefinition\Data\Fieldcollections) {
                foreach($fieldDefinition->getAllowedTypes() as $allowedType) {
                    $fieldCollectionDefinition = \OpenDxp\Model\DataObject\Fieldcollection\Definition::getByKey($allowedType);

                    $additionalSelectors = $this->getDataQuerySelectors(['itemClass' => $fieldCollectionDefinition], $newDataQuerySelectorPrefix, ($prefixName?$prefixName.' > ':''). $this->translator->trans($fieldDefinition->getTitle()?:$fieldDefinition->getName(), [], 'admin'));
                    foreach($additionalSelectors as $additionalSelector) {
                        $dataQuerySelectors[] = [
                            'name' => $additionalSelector['name'],
                            'parameters' => $newDataQuerySelectorPrefix.':items:each:('.str_replace($newDataQuerySelectorPrefix.':', '', $additionalSelector['parameters']).')',
                            'exportKey' => $additionalSelector['exportKey'],
                        ];
                    }
                }
            } elseif($fieldDefinition instanceof \OpenDxp\Model\DataObject\ClassDefinition\Data\Image) {
                foreach(['fullpath', 'url', 'thumbnail', 'hash', 'id'] as $method) {
                    $dataQuerySelectors[] = [
                        'name' => ($prefixName?$prefixName.' > ':''). $this->translator->trans($fieldDefinition->getTitle()?:$fieldDefinition->getName(), [], 'admin').' '.ucfirst($method),
                        'parameters' => $newDataQuerySelectorPrefix.':'.$method,
                        'exportKey' => false,
                    ];
                }
            } elseif ($fieldDefinition instanceof \OpenDxp\Model\DataObject\ClassDefinition\Data\ImageGallery) {
                $dataQuerySelectors[] = [
                    'name' => ($prefixName ? $prefixName.' > ' : '').$this->translator->trans($fieldDefinition->getTitle() ?: $fieldDefinition->getName(), [], 'admin'),
                    'parameters' => $newDataQuerySelectorPrefix.':each:(fullpath;url;thumbnail;id)',
                    'exportKey' => false,
                ];
            } else {
                $phpDocTypes = method_exists($fieldDefinition, 'getPhpdocReturnType') ? $fieldDefinition->getPhpdocReturnType() : $fieldDefinition->getPhpdocType();

                $typeSupportsScalarReturnType = false;
                foreach(explode('|', $phpDocTypes) as $phpDocType) {
                    if (in_array(preg_replace('/\[\]$/', '', $phpDocType), ['string', 'int', 'double', 'float', 'bool', 'boolean']) || (\class_exists($phpDocType) && (new \ReflectionClass($phpDocType))->hasMethod('__toString'))) {
                        $typeSupportsScalarReturnType = true;
                        break;
                    }
                }
                if($typeSupportsScalarReturnType) {
                    $dataQuerySelectors[] = [
                        'name' => ($prefixName ? $prefixName . ' > ' : '') . $this->translator->trans($fieldDefinition->getTitle() ?: $fieldDefinition->getName(), [], 'admin'),
                        'parameters' => $newDataQuerySelectorPrefix,
                        'exportKey' => $newDataQuerySelectorPrefix === 'id' || $fieldDefinition->getUnique(),
                    ];
                }
            }
        }

        foreach($dataQuerySelectors as &$dataQuerySelector) {
            $dataQuerySelector['name'] = preg_replace('/[^\p{L}\p{Nd}\s]+/u', ' ', $dataQuerySelector['name']);
        }

        return $dataQuerySelectors;
    }

    private function getXpaths(\DOMNode $node, \DOMXPath $domXPath, \DOMNode $rootNode) {
        $xpaths = [];

        if($node->hasAttributes()) {
            $xpath = substr($node->getNodePath(), strlen($rootNode->getNodePath())+1);
            
            /** @var \DOMNode $attribute */
            foreach($node->attributes as $attribute) {
                $xpaths[] = [
                    'name' => str_replace('/', ' ', ($xpath?$xpath.'/':'')).' '.$attribute->localName,
                    'xpath' => ($xpath?$xpath.'/':'').'@'.$attribute->localName,
                ];
            }
        }

        $nodeList = $domXPath->query('*', $node);
        if($nodeList instanceof \DOMNodeList && $nodeList->length > 0) {
            /** @var \DOMNode $item */
            foreach($nodeList as $item) {
                $xpath = substr($item->getNodePath(), strlen($rootNode->getNodePath())+1);
                if(preg_match('/\[\d+\]$/', $xpath)) {
                    $xpath = preg_replace('/\[\d+\]$/', '', $xpath);

                    $name = str_replace('/', ' ', $xpath);
                    $xpath = implode('/', array_map(static function ($xpathPart) {
                        if (strpos($xpathPart, ':') !== false) {
                            return '*[name()="'.$xpathPart.'"]';
                        }
                        return $xpathPart;
                    }, explode('/', $xpath)));

                    $xpaths[] = [
                        'name' => $name,
                        'xpath' => $xpath,
                        'multiValues' => true,
                    ];
                } else {
                    $xpaths = \array_merge($xpaths, $this->getXpaths($item, $domXPath, $rootNode));
                }
            }
        } else {
            $xpath = substr($node->getNodePath(), strlen($rootNode->getNodePath())+1);

            $name = str_replace('/', ' ', $xpath);
            $xpath = implode('/', array_map(static function ($xpathPart) {
                if (strpos($xpathPart, ':') !== false) {
                    return '*[name()="'.$xpathPart.'"]';
                }
                return $xpathPart;
            }, explode('/', $xpath)));

            $xpaths[] = [
                'name' => $name,
                'xpath' => $xpath,
            ];
        }

        return $xpaths;
    }

    private function getJsonFields(array $item, $jmesPathPrefix = '') {
        if(is_array($item) && count($item) === 1 && !empty(reset($item))) {
            $jmesPathPrefix = key($item);
            $item = reset($item);
        }

        $fields = [];
        foreach($item as $field => $value) {
            if(strpos($field, ' ') !== false) {
                $field = '"'.$field.'"';
            }
            $jmesPath = ($jmesPathPrefix?$jmesPathPrefix.'.':'').$field;
            if(is_array($value) && call_user_func(static function(array $arr) {
                foreach (array_keys($arr) as $key) {
                    if (is_string($key)) {
                        return true;
                    }
                }
                return false;
            }, $value) === true) {
                $fields = array_merge($fields, $this->getJsonFields($value, $jmesPath));
            } else {
                $fields[] = [
                    'name' => str_replace(['.', '"'], [' ', ''], $jmesPath),
                    'json-path' => $jmesPath
                ];
            }
        }

        return $fields;
    }
}