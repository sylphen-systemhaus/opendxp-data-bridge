<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use Exception;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\ParserInterface;
use Prewk\XmlStringStreamer\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * if XML cannot be read by StreamingXmlParser (e.g. for UTF-16 files), this class will get used as fallback using DomDocument
 */
class StreamingXmlParserFallback extends XmlStringStreamer
{
    private $file;
    private $itemXpath;

    /** @var \DOMXPath|null */
    private $domXPath;

    /** @var DOMNodeList|null */
    private $rawItems;

    private $currentIndex = 0;

    /** @var DOMDocument */
    private $domDocument;

    private $logger;

    public function __construct($file, $itemXpath, LoggerInterface $logger)
    {
        $this->file = $file;
        $this->itemXpath = $itemXpath;
        $this->logger = $logger;
    }

    protected function getDomXPath()
    {
        if ($this->domXPath === null) {
            if (empty($this->itemXpath)) {
                throw new \Exception('No itemxpath given in config');
            }

            // Bug-Workaround: See https://pyd.io/f/topic/failed-to-load-external-entity-boot-confmanifest-xml/page/3/#post-72211
            // and https://bugs.php.net/bug.php?id=64938
            if (\PHP_VERSION_ID < 80000) {
                libxml_disable_entity_loader(false);
            }
            libxml_clear_errors();
            libxml_use_internal_errors(true);
            $this->domDocument = new \DOMDocument();
            $source = $this->file;
            if (!$source) {
                return null;
            }
            if (!@$this->domDocument->load(
                    $source,
                    LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                    LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                    LIBXML_NOERROR |        # Suppress any errors
                    LIBXML_NOWARNING        # or warnings about prefixes.
                ) && !$this->domDocument->loadHTMLFile(
                    $source,
                    LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                    LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                    LIBXML_NOERROR |        # Suppress any errors
                    LIBXML_NOWARNING        # or warnings about prefixes.
                )) {
                throw new \Exception('Cannot load '.$source);
            }

            $root = $this->domDocument->documentElement;
            if(!$root) {
                throw new \Exception('Cannot load '.$source);
            }
            if ($root->getAttributeNode('xmlns')) {
                $root->removeAttributeNS($root->getAttributeNode('xmlns')->nodeValue, '');

                $source = sprintf(
                    '%s/temp-file-%s.%s',
                    OPENDXP_SYSTEM_TEMP_DIRECTORY,
                    uniqid().'-'.bin2hex(random_bytes(15)),
                    'xml'
                );
                file_put_contents($source, $this->domDocument->saveXML($this->domDocument));
                if (!@$this->domDocument->load(
                        $source,
                        LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                        LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                        LIBXML_NOERROR |        # Suppress any errors
                        LIBXML_NOWARNING        # or warnings about prefixes.
                    ) && !$this->domDocument->loadHTMLFile(
                        $source,
                        LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                        LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                        LIBXML_NOERROR |        # Suppress any errors
                        LIBXML_NOWARNING        # or warnings about prefixes.
                    )) {
                    throw new \Exception('Cannot load '.$source);
                }
                @unlink($source);
            }

            $this->domXPath = new \DOMXPath($this->domDocument);

            $this->position = 0;
        }

        return $this->domXPath;
    }

    private function getRawItems()
    {
        if ($this->rawItems === null) {
            $xpath = $this->getDomXPath();
            if ($xpath === null) {
                return new DOMNodeList();
            }

            if (substr($this->itemXpath, 0, 1) !== '/') {
                $this->itemXpath = '//'.$this->itemXpath;
            }

            $this->rawItems = $xpath->query($this->itemXpath);
            $this->currentIndex = 0;
        }

        return $this->rawItems;
    }

    public function getNode() {
        $rawItems = $this->getRawItems();

        $currentItem = $rawItems->item($this->currentIndex);

        if(!$currentItem instanceof DOMNode) {
            return false;
        }

        $this->currentIndex++;

        return $this->domDocument->saveXML($currentItem);
    }

    public function setCurrentIndex(int $currentIndex): void
    {
        $this->currentIndex = $currentIndex;
    }
}