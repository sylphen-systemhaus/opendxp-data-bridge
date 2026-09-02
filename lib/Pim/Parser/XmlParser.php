<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use DOMNodeList;
use ForceUTF8\Encoding;
use Psr\Log\LoggerInterface;

/**
 * Parses XML-Files according to given configuration
 */
class XmlParser implements Parser, \SeekableIterator, CachableParser {
    use ResourceBasedParser, IteratableParser;

    /** @var array */
	private $config;

	/** @var \DOMXPath */
	private $domXPath;

	/** @var \DOMNodeList */
	private $rawItems;

	/** @var int */
	private $countOfPreviouslyProcessedFiles = 0;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;

        $this->logger = $logger;

        $this->setSourceFile($this->config['file']);
    }

	protected function getDomXPath() {
	    if($this->domXPath === null) {
            if (empty($this->config['itemxpath'])) {
                throw new \Exception('No itemxpath given in config');
            }

            if (empty($this->config['fields'])) {
                throw new \Exception('Please configure raw data fields');
            }

            // Bug-Workaround: See https://pyd.io/f/topic/failed-to-load-external-entity-boot-confmanifest-xml/page/3/#post-72211
            // and https://bugs.php.net/bug.php?id=64938
            if (\PHP_VERSION_ID < 80000) {
                libxml_disable_entity_loader(false);
            }
            libxml_clear_errors();
            libxml_use_internal_errors(true);
            $domDocument = new \DOMDocument();
            $source = $this->getFileOrUrl();
            if(!$source) {
                return null;
            }
            if(!@$domDocument->load(
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

            /*$root = $domDocument->documentElement;
            if($root->getAttributeNode('xmlns')) {
                $root->removeAttributeNS($root->getAttributeNode('xmlns')->nodeValue, '');

                $source = sprintf(
                    '%s/temp-file-%s.%s',
                    OPENDXP_SYSTEM_TEMP_DIRECTORY,
                    uniqid().'-'.bin2hex(random_bytes(15)),
                    'xml'
                );
                file_put_contents($source, $domDocument->saveXML($domDocument));
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
                @unlink($source);
            }*/

            $this->domXPath = new \DOMXPath($domDocument);

            // Check for namespaces and register them if present
            if (!empty($domDocument->documentElement->namespaceURI)) {
                $this->domXPath->registerNamespace('ns', $domDocument->documentElement->namespaceURI);

                $this->config['itemxpath'] = self::convertToNamespacedXpath($this->config['itemxpath']);
                foreach ($this->config['fields'] as &$values) {
                    $values['xpath'] = self::convertToNamespacedXpath($values['xpath']);
                }
                unset($values);
            }

            $this->position = 0;
        }

	    return $this->domXPath;
	}

    private static function convertToNamespacedXpath($xpath) {
        return preg_replace_callback(
            '/(^|\/|\[)([a-zA-Z_][a-zA-Z0-9_\-]*)/', // Match element names but not functions
            static function ($matches) {
                $prefix = $matches[1]; // Delimiter: "/", "[", or start of string
                $name = $matches[2];   // Element name
                // If the name matches a function, leave it untouched
                if (in_array(strtolower($name), ['not', 'and', 'or', 'div', 'mod', 'node'])) {
                    return $prefix.$name;
                }
                // Otherwise, add "ns:" prefix
                return $prefix.'ns:'.$name;
            },
            $xpath
        );
    }

	public static function xml_to_array(\DOMNode $root) {
        $result = null;

        if ($root->hasAttributes()) {
            if($result === null) {
                $result = [];
            }
            $attrs = $root->attributes;
            foreach ($attrs as $attr) {
                $result['@attributes'][$attr->name] = Encoding::toUTF8($attr->value);
            }
        }

        if ($root->hasChildNodes()) {
            if ($result === null) {
                $result = [];
            }

            $children = $root->childNodes;
            if ($children->length == 1) {
                $child = $children->item(0);
                if (in_array($child->nodeType,[XML_TEXT_NODE,XML_CDATA_SECTION_NODE])) {
                    $result['_value'] = Encoding::toUTF8($child->nodeValue);

                    return count($result) === 1
                        ? $result['_value']
                        : $result;
                }
            }
            $groups = array();
            foreach ($children as $child) {
                if (!isset($result[$child->nodeName])) {
                    $result[$child->nodeName] = self::xml_to_array($child);
                } else {
                    if (!isset($groups[$child->nodeName])) {
                        $result[$child->nodeName] = array($result[$child->nodeName]);
                        $groups[$child->nodeName] = 1;
                    }
                    $result[$child->nodeName][] = self::xml_to_array($child);
                }
            }
        } elseif($result !== null) {
            $result['_value'] = null;
        }

        return $result;
    }

    private function getRawItems() {
	    if($this->rawItems === null) {
            $xpath = $this->getDomXPath();
            if($xpath === null) {
                return new DOMNodeList();
            }

            if(substr($this->config['itemxpath'], 0, 1) !== '/') {
                $this->config['itemxpath'] = '//'.$this->config['itemxpath'];
            }

            $this->rawItems = $xpath->query($this->config['itemxpath']);
        }

	    return $this->rawItems;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        // we have to use goto here and cannot call $this->current() because otherwise there could bee "Too much recursion" error
        start:
        $xpath = null;
        try {
            $xpath = $this->getDomXPath();
        } catch(\Exception $e) {
            $this->logger->warning($e->getMessage());
        }

        if($xpath === null || $this->position >= $this->getRawItems()->length) {
            if (empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }
            $this->current = null;
            return null;
        }

        $rawItems = $this->getRawItems();

        if ($this->position < $this->offset) {
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        $rawItem = $rawItems->item($this->position);

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
                    $item[$key] = $rawItems->length;
                    continue;
                }

                if ($values['xpath'] === '__all') {
                    $values['xpath'] = '.';
                    $values['multiValues'] = true;
                }

                $value = $xpath->query($values['xpath'], $rawItem);
                if ($value instanceof \DOMNodeList) {
                    if ($values['multiValues'] === true) {
                        $itemValues = array();
                        /** @var \DOMNode $node */
                        foreach($value as $node) {
                            $itemValues[] = self::xml_to_array($node);
                        }
                        $item[$key] = serialize($itemValues);
                    } elseif($value->length > 0) {
                        $item[$key] = Encoding::toUTF8($value->item(0)->nodeValue);
                    } elseif ($originalXpath != $values['xpath']) {
                        $item[$key] = $values['xpath'];
                    } else {
                        $item[$key] = null;
                    }
                }
            } catch (\Exception $ex) {
                if($originalXpath != $values['xpath']) {
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

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        try {
            return $this->countOfPreviouslyProcessedFiles + $this->getRawItems()->length;
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->countOfPreviouslyProcessedFiles;
        }
    }

    /**
     * @param int $position
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function seek($position)
    {
        $this->position = $position;
    }

    public function gotoNextImportResource()
    {
        $this->archive();

        $this->countOfPreviouslyProcessedFiles += $this->position;

        $this->sourceFilePath = null;
        $this->domXPath = null;
        $this->rawItems = null;
        $this->setSourceFile($this->config['file']);

        try {
            if($this->getFileOrUrl()) {
                return true;
            }
        } catch(\Exception $e) {
            return false;
        }
    }

    protected function getArchiveFileExtension() {
        return 'xml';
    }
}
