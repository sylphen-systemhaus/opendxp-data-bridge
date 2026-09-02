<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ArrayIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\model\Dataport;
use ForceUTF8\Encoding;
use IteratorIterator;
use JmesPath\AstRuntime;
use JmesPath\CompilerRuntime;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Exception\SyntaxErrorException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use OpenDxp\File;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use stdClass;
use Traversable;

/**
 * Parses JSON files according to given configuration
 */
class JsonParser implements Parser, CachableParser {
    use ResourceBasedParser, IteratableParser;

    /** @var array */
	private $config;

	/** @var Items */
	private $rawItems;

	/** @var int */
	private $countOfPreviouslyProcessedItems = 0;

	private $currentFileCount = 0;

    private $staticResultCache = [];

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;

        $this->logger = $logger;

        $this->setSourceFile($this->config['file']);
    }

    private function getRawItems() {
        if($this->rawItems === null) {
            if (empty($this->config['fields'])) {
                throw new \Exception('No raw data fields given in config');
            }

            $handle = $this->getStream();

            if (!$handle) {
                return false;
            }

            if($this->config['item-json-path'] === '.') {
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

            try {
                if(empty($this->limit)) {
                    $this->currentFileCount = \iterator_count($rawItems);
                } else {
                    $countIsUsed = false;
                    foreach ($this->config['fields'] as $field) {
                        if ($field['json-path'] === '__count') {
                            $countIsUsed = true;
                            break;
                        }
                    }

                    $this->currentFileCount = 0;
                    foreach($rawItems as $item) {
                        if($countIsUsed || $this->currentFileCount < $this->limit) {
                            $this->currentFileCount++;
                        } else {
                            break;
                        }
                    }
                }

                $handle = $this->getStream();
                if ($this->config['item-json-path'] === '.') {
                    $rawItems = Items::fromString($streamContents, ['pointer' => '', 'decoder' => new ErrorWrappingDecoder(new JsonDecoder())]);
                } else {
                    $rawItems = Items::fromStream($handle, ['pointer' => $this->config['item-json-path'], 'decoder' => new ErrorWrappingDecoder(new JsonDecoder())]);
                }

                $this->rawItems = $rawItems->getIterator();

                if ($this->rawItems->valid() && (string)$this->rawItems->key() !== '0') {
                    $this->rawItems = new ArrayIterator([(object)iterator_to_array($this->rawItems)]);
                    $this->currentFileCount = 1;
                }
            } catch(\Exception $e) {
                if($e instanceof PathNotFoundException || $e->getMessage() === 'Cannot iterate empty JSON \'\' At position 0.') {
                    return false;
                }
                throw $e;
            }

            $this->position = 0;
        }

        return $this->rawItems;
    }

    public static function convertJsonPointerToJmespath($expression) {
        if(strpos($expression, '/') !== 0) {
            return $expression;
        }

        $parts = array_slice(array_map(static function ($jsonPointerPart) {
            $jsonPointerPart = str_replace(array('~1', '~0'), array('/', '~'), $jsonPointerPart);
            return is_numeric($jsonPointerPart) ? (int) $jsonPointerPart : $jsonPointerPart;
        }, explode('/', $expression)), 1);

        $jmesPath = '';
        foreach($parts as $part) {
            if(\is_numeric($part)) {
                $jmesPath .= '['.$part.']';
            } elseif($jmesPath === '') {
                $jmesPath = $part;
            } else {
                $jmesPath .= '.'.$part;
            }
        }

        return $jmesPath;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        // we have to use goto here and cannot call $this->current() because otherwise there could be "Too much recursion" error
        start:
        $rawItems = null;
        try {
            $rawItems = $this->getRawItems();
        } catch(\Exception $e) {
            $this->logger->warning($e->getMessage());
            $rawItems = null;
        }

        if(!$rawItems instanceof Traversable || !$rawItems->valid()) {
            if(empty($this->limit) && $this->gotoNextImportResource()) {
                goto start;
            }

            $this->current = null;
            return null;
        }

        if ($this->position < $this->offset) {
            $rawItems->next();
            $this->next();
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        $rawItem = $rawItems->current();
        if($rawItem instanceof DecodingError) {
            $rawItems->next();
            $this->next();
            $this->logger->error($rawItem->getErrorMessage().': '.$rawItem->getMalformedJson());
            if ($this->valid() || $this->gotoNextImportResource()) {
                goto start;
            } else {
                return null;
            }
        }

        if(!$rawItem instanceof stdClass && !is_scalar($rawItem)) {
            $rawItem = iterator_to_array($rawItems);
        }

        try {
            $runtime = new CompilerRuntime(\OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $this->config['dataportId'] . '_json');
        } catch(\RuntimeException $e) {
            $runtime = new CompilerRuntime();
        }

        $item = [];
        foreach ($this->config['fields'] as $key => $values) {
            if (empty($values['json-path']) && !is_scalar($rawItem)) {
                $item[$key] = '';
                continue;
            }

            $originalJsonPath = $values['json-path'];
            $values['json-path'] = preg_replace_callback('/\{\{\s*(\S+?( +\S+?)*?)\s*\}\}/', function ($matches) {
                return $this->getImporter()->replaceObjectIdentifier($matches[0], $this->config['parameters'] ?? null);
            }, $values['json-path']);

            if (strpos($values['json-path'], '/') !== 0) {
                $values['json-path'] = $this->config['item-json-path'] .'/'. $values['json-path'];
            }

            // support ../ in JmesPath expression, see https://mixable.blog/php-realpath-for-non-existing-path/
            $values['json-path'] = array_reduce(explode('/', $values['json-path']), static function($a, $b) {
                if ($a === null) {
                    $a = "/";
                }
                if ($b === '') {
                    return $a;
                }
                if ($b === '.') {
                    return '.'.$a;
                }
                if ($b === "..") {
                    return dirname($a);
                }
         
                return preg_replace("/\/+/", "/", "$a/$b");
            });

            $values['json-path'] = preg_replace('/^'.preg_quote($this->config['item-json-path'].'/', '/').'/', '', $values['json-path']);

            try {
                if($values['json-path'] === '__source') {
                    $value = $this->getFileOrUrl();
                } elseif ($values['json-path'] === '__updated') {
                    $value = $this->getLastModified();
                } elseif ($values['json-path'] === '__index') {
                    $value = $this->position;
                } elseif ($values['json-path'] === '__count') {
                    $value = $this->currentFileCount;
                } elseif (strpos($values['json-path'], '/') === 0) {
                    if(array_key_exists($values['json-path'], $this->staticResultCache)) {
                        $value = $this->staticResultCache[$values['json-path']];
                    } else {
                        $handle = fopen($this->getFileOrUrl(), 'rb');
                        $value = iterator_to_array(Items::fromStream($handle, ['pointer' => $values['json-path'], 'decoder' => new ErrorWrappingDecoder(new JsonDecoder())]));
                        if (is_array($value) && count($value) === 1) {
                            $value = reset($value);
                        }

                        $this->staticResultCache[$values['json-path']] = $value;
                    }
                } elseif(in_array($values['json-path'], ['', '.', null], true)) {
                    $value = $rawItem;
                } else {
                    try {
                        $value = $runtime(self::convertJsonPointerToJmespath($values['json-path']), $rawItem);
                    } catch(\RuntimeException $e) {
                        $this->logger->notice(\OPENDXP_SYSTEM_TEMP_DIRECTORY.' seems to not be writable to cache compiled JmesPath expressions. Falling back to in-memory JmesPath parser');
                        $runtime = new AstRuntime();
                        $value = $runtime(self::convertJsonPointerToJmespath($values['json-path']), $rawItem);
                    }
                }

                if($value === null && $values['json-path'] !== $originalJsonPath) {
                    $value = $values['json-path'];
                }

                if (!\is_scalar($value) && $value !== null) {
                    $item[$key] = json_encode($value, \JSON_UNESCAPED_SLASHES);
                } else {
                    $item[$key] = Encoding::toUTF8($value);
                }
            } catch (\Exception $ex) {
                if ($originalJsonPath != $values['json-path']) {
                    $item[$key] = $values['json-path'];
                } else {
                    $this->logger->error('Unable to execute json-path: '.$ex);
                }
            }
        }
        $item['__updated'] = $this->getLastModified();

        $rawItems->next();
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
            if ($this->getRawItems() === false) {
                return $this->countOfPreviouslyProcessedItems;
            }
            return $this->countOfPreviouslyProcessedItems + $this->currentFileCount;
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage());
            return $this->countOfPreviouslyProcessedItems;
        }
    }

    public function gotoNextImportResource()
    {
        $this->archive();

        $this->countOfPreviouslyProcessedItems += $this->position + 1;

        $this->sourceFilePath = null;
        $this->rawItems = null;
        $this->staticResultCache = [];

        try {
            return $this->getRawItems() !== false;
        } catch(\Exception $e) {
            return false;
        }
    }

    protected function getArchiveFileExtension() {
        return 'json';
    }
}
