<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Exception;
use ForceUTF8\Encoding;
use InvalidArgumentException;
use LibXMLError;
use Prewk\XmlStringStreamer;
use Psr\Log\LoggerInterface;
use Traversable;

/**
 * Parses XML-Files according to given configuration
 */
class StreamingXmlParser implements Parser, CachableParser {
    use ResourceBasedParser, IteratableParser;

    /** @var array */
	private $config;

    /** @var int */
    private $countOfPreviouslyProcessedFiles = 0;

    /** @var int  */
    private $currentFileCount = 0;

    /** @var XmlStringStreamer */
    private $streamer;

    private $useStringWalker = false;

    private $cacheForElementsOutsideItemXpath = [];

    private $importFileContent = null;
    private $relativeXpathCache = [];

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->setSourceFile($this->config['file']);
    }

    /**
     * @return XmlStringStreamer|false
     */
    private function getStreamer() {
        if($this->streamer === null) {
            $this->streamer = false;

            $fileStream = $this->getStream();
            if(!$fileStream) {
                return false;
            }
            if($this->useStringWalker) {
                $parser = new XmlStringStreamer\Parser\StringWalker();
            } else {
                $parser = new XmlStringStreamer\Parser\UniqueNode([
                    'uniqueNode' => basename($this->config['itemxpath']),
                    'checkShortClosing' => false // should be set to true if input file has empty item tags
                ]);
            }

            $stream = new XmlStringStreamer\Stream\File($fileStream);
            $this->streamer = new XmlStringStreamer($parser, $stream);

            $this->currentFileCount = 0;
            if(!empty($this->limit)) {
                $countIsUsed = false;
                foreach ($this->config['fields'] as $field) {
                    if ($field['xpath'] === '__count') {
                        $countIsUsed = true;
                        break;
                    }
                }
            }

            while ($this->streamer->getNode()) {
                $this->currentFileCount++;
                if(!empty($this->limit) && $this->currentFileCount >= $this->limit && !$countIsUsed) {
                    break;
                }
            }

            if($this->currentFileCount === 0) {
                try {
                    $this->streamer = new StreamingXmlParserFallback($this->getFileOrUrl(), basename($this->config['itemxpath']), $this->logger);

                    $this->currentFileCount = 0;
                    while ($this->streamer->getNode()) {
                        $this->currentFileCount++;
                    }

                    $this->streamer->setCurrentIndex(0);
                } catch (Exception $e) {
                    $this->logger->warning('Could not parse XML: '.$e->getMessage());
                    $this->streamer = false;
                }
            }

            $stream->rewind();
            $this->position = 0;
        }

        return $this->streamer;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        // we have to use goto here and cannot call $this->current() because otherwise there could bee "Too much recursion" error
        start:
        $streamer = $this->getStreamer();
        if($streamer === false) {
            if (empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }
            $this->current = null;
            return null;
        }

        $itemXml = $streamer->getNode();
        if($itemXml === false) {
            if (empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }
            $this->current = null;
            return null;
        }

        if ($this->position < $this->offset) {
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }


        $itemXml = Encoding::toUTF8($itemXml);

        $domDocument = new \DOMDocument();
        libxml_clear_errors();
        libxml_use_internal_errors(true);
        $domLoaded = @$domDocument->loadXML('<?xml version="1.0" encoding="utf-8"?>'.$itemXml);
        if (!$domLoaded) {
            $domLoaded = $domDocument->loadHTML(
                $itemXml,
                LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                LIBXML_NOERROR |        # Suppress any errors
                LIBXML_NOWARNING        # or warnings about prefixes.
            );
        }

        $errors = libxml_get_errors();
        if ($errors) {
            $errorMessages = array_map(static function (\LibXMLError $error) {
                return trim($error->message);
            }, $errors);

            $parsingFailed = in_array('htmlParseStartTag: invalid element name', $errorMessages, true) || in_array('EndTag: \'</\' not found', $errorMessages, true);
            if(!$parsingFailed) {
                foreach ($errorMessages as $errorMessage) {
                    if(strpos($errorMessage, 'Premature end of data in tag') !== false) {
                        $parsingFailed = true;
                        break;
                    }
                }
            }

            if($parsingFailed) {
                $domLoaded = false;
            } else {
                $errorMessages = array_filter($errorMessages, static function ($errorMessage) {
                    return !preg_match('/^Tag \S+ invalid$/', $errorMessage) && !preg_match('/^Namespace prefix \S+ on \S+ is not defined$/', $errorMessage);
                });

                if ($errorMessages) {
                    if ($domLoaded) {
                        $this->logger->notice('Strict loading failed because: '.PHP_EOL.implode(PHP_EOL, $errorMessages).PHP_EOL.'Falling back to error-tolerant loading.');
                    } else {
                        throw new InvalidArgumentException('Could not parse XML: '.PHP_EOL.implode(PHP_EOL, $errorMessages));
                    }
                }
            }
        }

        if(!$domLoaded && !$this->useStringWalker) {
            $this->streamer = null;
            $this->useStringWalker = true;
            return $this->current();
        }

        $xpath = new \DOMXPath($domDocument);

        $item = [];
        foreach ($this->config['fields'] as $key => $values) {
            $item[$key] = '';
            if (empty($values['xpath'])) {
                continue;
            }

            $originalXpath = $values['xpath'];
            $values['xpath'] = preg_replace_callback('/\{\{\s*(\S+?( +\S+?)*?)\s*\}\}/', function ($matches) {
                return $this->getImporter()->replaceObjectIdentifier($matches[0], $this->config['parameters'] ?? null);
            }, $values['xpath']);

            try {
                if($values['xpath'] === '__source') {
                    $item[$key] = $this->getFileOrUrl();
                    continue;
                }

                if ($values['xpath'] === '__updated') {
                    $item[$key] = $this->getLastModified();
                    continue;
                }

                if ($values['xpath'] === '__index') {
                    $item[$key] = $this->position;
                    continue;
                }

                if ($values['xpath'] === '__count') {
                    $item[$key] = $this->currentFileCount;
                    continue;
                }

                if ($values['xpath'] === '__all') {
                    $values['xpath'] = '.';
                    $values['multiValues'] = true;
                }

                if(substr($values['xpath'], 0, 1) === '/' && substr($values['xpath'], 0, 2) !== '//') {
                    if(isset($this->cacheForElementsOutsideItemXpath[$values['xpath']])) {
                        return $this->cacheForElementsOutsideItemXpath[$values['xpath']];
                    }

                    $parser = new XmlStringStreamer\Parser\UniqueNode([
                        'uniqueNode' => basename($values['xpath']),
                        'checkShortClosing' => false // should be set to true if input file has empty item tags
                    ]);

                    $stream = new XmlStringStreamer\Stream\File($this->getStream());
                    $streamer = new XmlStringStreamer($parser, $stream);

                    $value = [];
                    while($outsideItemNode = $streamer->getNode()) {
                        $outsideItemXml = Encoding::toUTF8($outsideItemNode);

                        $outsideItemDomDocument = new \DOMDocument();
                        $domLoaded = @$outsideItemDomDocument->loadXML('<?xml version="1.0" encoding="utf-8"?>'.$outsideItemXml);
                        if (!$domLoaded) {
                            $outsideItemDomDocument->loadHTML(
                                $outsideItemXml,
                                LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                                LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                                LIBXML_NOERROR |        # Suppress any errors
                                LIBXML_NOWARNING        # or warnings about prefixes.
                            );
                        }

                        $value[] = $outsideItemDomDocument;
                    }

                    if ($values['multiValues'] === true) {
                        $itemValues = array();
                        /** @var \DOMNode $node */
                        foreach ($value as $node) {
                            if ($node->hasChildNodes()) {
                                $itemValues[] = XmlParser::xml_to_array($node);
                            } else {
                                $itemValues[] = $node->nodeValue;
                            }
                        }
                        $item[$key] = serialize($itemValues);
                    } elseif (count($value) > 0) {
                        $item[$key] = $value[0]->nodeValue;
                    } elseif ($originalXpath !== $values['xpath']) {
                        $item[$key] = $values['xpath'];
                    } else {
                        $item[$key] = null;
                    }

                    $this->cacheForElementsOutsideItemXpath[$values['xpath']] = $item[$key];
                    continue;
                }

                if(substr($values['xpath'], 0, 2) === '..') {
                    ini_set('pcre.backtrack_limit', PHP_INT_MAX);
                    if($this->importFileContent === null) {
                        $this->importFileContent = file_get_contents($this->getFileOrUrl());
                    }

                    $parentXml = $itemXml;
                    $itemXmlPosition = strpos($this->importFileContent, $parentXml) + strlen($itemXml);
                    $foundInCache = false;
                    foreach($this->relativeXpathCache as &$cachedPartial) {
                        if($cachedPartial['startPosition'] <= $itemXmlPosition && $cachedPartial['endPosition'] >= $itemXmlPosition) {
                            if(array_key_exists($originalXpath, $cachedPartial['value'])) {
                                $value = $cachedPartial['value'][$originalXpath];
                            } else {
                                $values['xpath'] = preg_replace('/^(..\/)+/', '/'.$cachedPartial['rootTag'].'/', $values['xpath']);

                                $value = $cachedPartial['xpathDocument']->query($values['xpath']);
                                $cachedPartial['value'][$originalXpath] = $value;
                            }

                            $foundInCache = true;
                            break;
                        }
                    }
                    unset($cachedPartial);

                    if(!$foundInCache) {
                        parseRelativeXpath:
                        $endTag = null;
                        $strlenContents = strlen($this->importFileContent);

                        $startPosition = null;
                        $endPosition = null;

                        $impossibleEndTags = [];
                        $remainingContent = substr($this->importFileContent, $itemXmlPosition);

                        while ($itemXmlPosition < $strlenContents) {
                            if (!preg_match('/.*?<\/('.($impossibleEndTags ? '((?!'.implode('|', $impossibleEndTags).').)*?':'.+?').')>/s', $remainingContent, $match)) {
                                $endTag = null;
                                $this->logger->error(preg_last_error_msg());
                                break;
                            }
                            $endTag = $match[1];
                            $strlenMatch = strlen($match[0]);
                            $itemXmlPosition += $strlenMatch;
                            $remainingContent = substr($remainingContent, $strlenMatch);

                            $parentXml .= $match[0];

                            if (substr_count($parentXml, '</'.$endTag.'>') - (substr_count($parentXml, '<'.$endTag.' ') + substr_count($parentXml, '<'.$endTag.'>')) > 0) {
                                break;
                            }

                            $impossibleEndTags[] = $endTag.'>';
                        }

                        if ($endTag) {
                            $endPosition = $itemXmlPosition;
                            $startPosition = max(strrpos($this->importFileContent, '<'.$endTag.'>', -(strlen($this->importFileContent) - $endPosition)) ?: 0, strrpos($this->importFileContent, '<'.$endTag.' ', -(strlen($this->importFileContent) - $endPosition)) ?: 0);
                            $parentXml = substr($this->importFileContent, $startPosition, $endPosition - $startPosition);

                            $values['xpath'] = ltrim(substr($values['xpath'], 2), '/');
                            if (substr($values['xpath'], 0, 2) === '..') {
                                goto parseRelativeXpath;
                            } else {
                                $values['xpath'] = '/'.$endTag.'/'.$values['xpath'];
                            }
                        }

                        $parentDomDocument = new \DOMDocument();
                        $domLoaded = $parentDomDocument->loadXML(
                            $parentXml,
                            LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                            LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                            LIBXML_NOERROR |        # Suppress any errors
                            LIBXML_NOWARNING        # or warnings about prefixes.
                        );
                        if (!$domLoaded) {
                            $parentDomDocument->loadHTML(
                                $parentXml,
                                LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                                LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                                LIBXML_NOERROR |        # Suppress any errors
                                LIBXML_NOWARNING        # or warnings about prefixes.
                            );
                        }

                        if (!$domLoaded) {
                            throw new \Exception('Could not extract partial XML for XPath '.$values['xpath']);
                        }

                        $root = $parentDomDocument->documentElement;
                        if ($root->getAttributeNode('xmlns')) {
                            $root->removeAttributeNS($root->getAttributeNode('xmlns')->nodeValue, '');

                            $parentDomDocument->loadXML($parentDomDocument->saveXML($parentDomDocument));
                        }

                        $parentXpath = new \DOMXPath($parentDomDocument);
                        $value = $parentXpath->query($values['xpath']);

                        if ($startPosition !== null && $endPosition !== null) {
                            $this->relativeXpathCache[] = ['startPosition' => $startPosition, 'endPosition' => $endPosition, 'value' => [$originalXpath => $value], 'xpathDocument' => $parentXpath, 'rootTag' => $endTag];
                        }
                    }
                } else {
                    $value = $xpath->query($values['xpath']);
                }

                if ($value instanceof \DOMNodeList) {
                    if ($values['multiValues'] === true) {
                        $itemValues = array();
                        /** @var \DOMNode $node */
                        foreach($value as $node) {
                            $itemValues[] = XmlParser::xml_to_array($node);
                        }

                        $item[$key] = serialize($itemValues);
                    } elseif($value->length > 0) {
                        $item[$key] = $value->item(0)->nodeValue;
                    } elseif ($originalXpath !== $values['xpath']) {
                        $item[$key] = $values['xpath'];
                    } else {
                        $item[$key] = null;
                    }
                }
            } catch (\Exception $ex) {
                if ($originalXpath != $values['xpath']) {
                    $item[$key] = $values['xpath'];
                } else {
                    $this->logger->error('Unable to execute xpath: '.$ex);
                }
            }
        }

        $item['__updated'] = $this->getLastModified();

        $this->current = $item;
        return $this->current;
    }

    public function gotoNextImportResource()
    {
        $this->archive();

        $this->countOfPreviouslyProcessedFiles += $this->position;

        $this->sourceFilePath = null;
        $this->streamer = null;
        $this->useStringWalker = false;
        $this->importFileContent = null;
        $this->relativeXpathCache = [];
        $this->setSourceFile($this->config['file']);

        return $this->getStreamer() !== false || $this->getStream();
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        try {
            if ($this->getStreamer() === false) {
                return $this->countOfPreviouslyProcessedFiles;
            }
            return $this->countOfPreviouslyProcessedFiles + $this->currentFileCount;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            return $this->countOfPreviouslyProcessedFiles;
        }
    }

    protected function getArchiveFileExtension() {
        return 'xml';
    }
}
