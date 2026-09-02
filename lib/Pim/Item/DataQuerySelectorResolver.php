<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LazyLog;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ArrayMapIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\TypedArrayMapIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Carbon\Carbon;
use Countable;
use ErrorException;
use Exception;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpenDxp;
use OpenDxp\File;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Asset\Image;
use OpenDxp\Model\Asset\Video;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ReverseObjectRelation;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\Hotspotimage;
use OpenDxp\Model\DataObject\Data\ImageGallery;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Fieldcollection;
use OpenDxp\Model\DataObject\Objectbrick;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\OwnerAwareFieldInterface;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Tag;
use OpenDxp\Model\Tool\TmpStore;
use OpenDxp\Tool;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\Registry;
use Throwable;

class DataQuerySelectorResolver
{
    private $cacheDirectory;

    private static $newFunctions = [];

    /** @var Serializer */
    private static $serializer;

    public function __construct($cacheDirectory = null)
    {
        $this->cacheDirectory = $cacheDirectory ?? Installer::getCachePath();
    }

    /**
     * @param string $identifier
     * @param mixed $object
     * @param bool $force transpile function (true) or use cached (false)
     *
     * @return string
     */
    public function resolve($identifier, $object, $force = false, LoggerInterface $logger = null) {
        if($object instanceof TypedArrayMapIterator) {
            $className = $object->getClass();
        } elseif(is_object($object)) {
            $className = \get_class($object);
        } else {
            $className = '';
        }

        $functionName = 'data_bridge_'.md5($className.'_'.$identifier);

        $compiledFunctionsFile = $this->cacheDirectory.'/'.preg_replace('/\W+/', '_', $className.' '.(strlen($identifier)>150?md5($identifier):$identifier)).'.php';
        if(!$force && !\function_exists($functionName) && \file_exists($compiledFunctionsFile)) {
            include_once $compiledFunctionsFile;
        }

        $inheritanceEnabled = DataObject\AbstractObject::getGetInheritedValues();
        try {
            $logger->debug('Using function '.$functionName.' in '.$compiledFunctionsFile.' to resolve data query selector');

            if ($force || !\function_exists($functionName)) {
                if (!isset(self::$newFunctions[$functionName])) {
                    $transpiler = new DataQuerySelectorTranspiler();
                    $code = $transpiler->getCode($identifier);

                    $functionCode = '// data query selector: '.$identifier.'
function '.$functionName.'($object, $logger) {'.PHP_EOL.$code.'}'.PHP_EOL.PHP_EOL;

                    // do not use FILE_APPEND here as in the meantime another process could have written the file which would result in the function being defined twice -> Fatal error
                    // also locking with flock or a separate .lock file did not work always
                    $currentFileContent = '';
                    if (\file_exists($compiledFunctionsFile)) {
                        $currentFileContent = \file_get_contents($compiledFunctionsFile);
                    }

                    if ($currentFileContent === '') {
                        $currentFileContent = '<?php'.PHP_EOL;
                    }
                    if (strpos($currentFileContent, 'function '.$functionName.'(') === false) {
                        \file_put_contents($compiledFunctionsFile, $currentFileContent.$functionCode, LOCK_EX);
                    }

                    self::$newFunctions[$functionName] = static function ($object, $logger) use ($code) {
                        return eval($code);
                    };
                }

                $function = self::$newFunctions[$functionName];
                return $function($object, $logger);
            }
            
            return $functionName($object, $logger);
        } finally {
            Helper::useInheritance($inheritanceEnabled);
        }
    }

    public static function resolveSingleField($currentField, $object, LoggerInterface $logger) {
        if($currentField === '') {
            return $object;
        }

        // handle array indexes, e.g. .:.:.:relationField:0:name, .:.:.:relationField:each:name
        if (is_array($object) || $object instanceof ArrayAccess || $object === null || $object instanceof \Iterator) {
            if ((is_array($object) || $object instanceof ArrayAccess) && isset($object[$currentField])) {
                if($object[$currentField] instanceof DataObject\Data\BlockElement) {
                    $object[$currentField] = $object[$currentField]->getData();
                }

                return self::debugLog($currentField, $object[$currentField], $logger);
            }

            if (in_array($currentField, ['each', 'all'], true)) {
                if($object instanceof \Iterator) {
                    return self::debugLog($currentField, iterator_to_array($object), $logger);
                }
                return self::debugLog($currentField, (array)$object, $logger);
            }

            if ($object instanceof \Iterator) {
                $items = iterator_to_array($object);
                if(isset($items[$currentField])) {
                    return self::debugLog($currentField, $items[$currentField], $logger);
                }
            }
        }

        if (in_array($currentField, ['each', 'all'], true)) {
            return self::debugLog($currentField, [$object], $logger);
        }

        if (in_array(strtolower($currentField), ['getclosestparentofclass', 'closestparentofclass'], true)) {
            $currentField = 'closestOfClass';
        }

        $arguments = [];
        $argumentsPosition = strpos($currentField, '#');

        $methodName = $currentField;
        if ($argumentsPosition !== false) {
            $argumentString = substr($methodName, $argumentsPosition + 1);
            $argumentString = str_replace('\\,', '","', $argumentString);

            $arguments = \str_getcsv($argumentString, ',');
            foreach($arguments as &$argument) {
                $argumentLowerCase = strtolower($argument);
                if($argumentLowerCase === 'false') {
                    $argument = false;
                } elseif($argumentLowerCase === 'true') {
                    $argument = true;
                } elseif($argumentLowerCase === 'null') {
                    $argument = null;
                }
            }
            unset($argument);

            $methodName = substr($methodName, 0, $argumentsPosition);
        }

        if(substr($methodName, 0, 3) === 'set' && method_exists($object, 'get'.substr($methodName, 3))) {
            $methodName = 'get' . substr($methodName, 3);
        }

        if (!self::method_exists($object, $methodName)) {
            if($object instanceof ParameterBagInterface) {
                $methodName = 'get'.$methodName;
            } else {
                $methodName = 'get'.\ucfirst($methodName);
            }
        }

        $value = null;
        if (self::method_exists($object, $methodName)) {
            $fieldName = preg_replace('/^get/', '', $methodName);
            if(($object instanceof Image || $object instanceof Hotspotimage) && strtolower($methodName) === 'getthumbnail') {
                if(count($arguments) === 1) {
                    // querying image / video thumbnail always requires deferred to be false, otherwise the image will not be generated for export
                    $arguments[] = false;
                }

                if(!empty($arguments[0])) {
                    $config = Image\Thumbnail\Config::getByName($arguments[0]);

                    if(!$config instanceof Image\Thumbnail\Config) {
                        $logger->warning('Image Thumbnail configuration "'.$arguments[0].'" does not exist');
                    } else {
                        if ($object instanceof Hotspotimage) {
                            $checkObject = $object->getImage();
                        } else {
                            $checkObject = $object;
                        }

                        if (strtolower($config->getFormat()) === 'source' && $checkObject->getType() === 'image') {
                            if (!$checkObject->isAnimated() && Helper::getFileExtension($checkObject->getFilename()) !== 'svg') {
                                if (method_exists(Image\Thumbnail\Config::class, 'getAutoFormats')) {
                                    $autoFormats = array_reverse(Image\Thumbnail\Config::getAutoFormats()); // array_reverse is only to get webp first
                                } else {
                                    $autoFormats = ['webp' => ['enabled' => true]];
                                }
                                foreach ($autoFormats as $autoFormat => $autoFormatConfig) {
                                    if ($autoFormatConfig['enabled']) {
                                        $config->setFormat($autoFormat);
                                        $arguments[0] = $config;

                                        break;
                                    }
                                }
                            } else {
                                $config->setFormat('original');
                                $arguments[0] = $config;
                            }
                        }
                    }
                }
            } elseif($object instanceof Video && strtolower($methodName) === 'getthumbnail') {
                return self::debugLog($currentField, self::generateVideoThumbnail($object, $arguments), $logger);
            } elseif(strtolower($methodName) === 'getembeddedmetadata' && count($arguments) === 0) {
                $arguments[] = false;
            }

            try {
                $value = $object->$methodName(...$arguments);
            } catch (Throwable $e) {
                if (count($arguments) === 0) {
                    try {
                        $value = @$object->$fieldName;
                        if($value === null && method_exists($object, $methodName)) {
                            throw $e;
                        }
                    } catch (Throwable $propertyException) {
                        throw $e;
                    }
                }
            }
            
            if($value instanceof OwnerAwareFieldInterface) {
                if(method_exists($value, '_getOwner') && !$value->_getOwner() && method_exists($value, '_setOwner')) {
                    $value->_setOwner($object);
                }

                if (method_exists($value, '_getOwnerFieldname') && !$value->_getOwnerFieldname() && self::method_exists($value, '_setOwnerFieldname')) {
                    $value->_setOwnerFieldname($fieldName);
                }
            }

            if (($value === null || $value === []) && count($arguments) === 1 && $arguments[0] === 'all') {
                $allLanguagesValue = [];
                foreach (Tool::getValidLanguages() as $language) {
                    $allLanguagesValue[$language] = self::resolveSingleField($methodName.'#'.$language, $object, $logger);
                }
                return self::debugLog($currentField, $allLanguagesValue, $logger);
            }

            if (strtolower($methodName) === 'getusermodification') {
                $value = new class($value) {
                    /** @var int */
                    private $userId;

                    /** @var OpenDxp\Model\User|null */
                    private $user;

                    private function getUser() {
                        if($this->user === null) {
                            $this->user = OpenDxp\Model\User::getbyId($this->userId);
                            if(!$this->user instanceof OpenDxp\Model\User) {
                                $this->user = new OpenDxp\Model\User();
                            }
                        }
                        return $this->user;
                    }

                    public function __construct($userId)
                    {
                        $this->userId = $userId;
                    }

                    public function getUsername()
                    {
                        return $this->getUser()->getUsername();
                    }

                    public function getId()
                    {
                        return $this->userId;
                    }

                    public function getName()
                    {
                        return $this->getUser()->getName();
                    }

                    public function getEmail()
                    {
                        return $this->getUser()->getEmail();
                    }

                    public function getFirstname()
                    {
                        return $this->getUser()->getFirstname();
                    }

                    public function getLastname()
                    {
                        return $this->getUser()->getLastname();
                    }

                    public function __toString()
                    {
                        return (string)$this->userId;
                    }
                };

                return self::debugLog($currentField, $value, $logger);
            }

            if ($object instanceof Concrete || $object instanceof AbstractData || $object instanceof Fieldcollection\Data\AbstractData) {
                $fieldDefinition = Importer::getFieldDefinition($object, $fieldName);

                if ($fieldDefinition instanceof Data\Select) {
                    $value = new class($value, $fieldDefinition) {
                        private $value;

                        /** @var Data\Select */
                        private $fieldDefinition;

                        public function __construct($value, $fieldDefinition)
                        {
                            $this->value = $value;
                            $this->fieldDefinition = $fieldDefinition;
                        }

                        public function getValue()
                        {
                            return $this->value;
                        }

                        public function getLabel($language = null)
                        {
                            $options = $this->fieldDefinition->getOptions();
                            foreach ($options as $option) {
                                if ($option['value'] == $this->value) {
                                    if ($language === null) {
                                        return \OpenDxp::getContainer()->get('translator')->trans($option['key'], [], 'admin');
                                    }

                                    return \OpenDxp::getContainer()->get('translator')->trans(
                                        $option['key'], [], 'admin', $language
                                    );
                                }
                            }

                            return null;
                        }

                        public function __toString()
                        {
                            return (string)$this->value;
                        }
                    };
                } elseif ($fieldDefinition instanceof Data\Multiselect) {
                    $value = new class($value, $fieldDefinition) implements ArrayAccess {
                        private $value;

                        /** @var Data\Select */
                        private $fieldDefinition;

                        public function __construct($value, $fieldDefinition)
                        {
                            $this->value = (array)$value;
                            $this->fieldDefinition = $fieldDefinition;
                        }

                        public function getValue()
                        {
                            return $this->value;
                        }

                        public function has($option) {
                            if(isset($this->value[$option])) {
                                return true;
                            }

                            $labels = $this->getLabel();
                            foreach($labels as $label) {
                                if($label == $option) {
                                    return true;
                                }
                            }
                        }

                        public function getLabels($language = null)
                        {
                            return $this->getLabel($language);
                        }

                        public function getLabel($language = null)
                        {
                            $options = $this->fieldDefinition->getOptions();
                            $values = [];
                            foreach ($options as $option) {
                                if (in_array($option['value'], $this->value)) {
                                    if ($language === null) {
                                        $values[] = \OpenDxp::getContainer()->get('translator')->trans($option['key'], [], 'admin');
                                        continue;
                                    }

                                    $values[] = \OpenDxp::getContainer()->get('translator')->trans(
                                        $option['key'], [], 'admin', $language
                                    );
                                }
                            }

                            return $values;
                        }

                        /**
                         * @return string
                         */
                        #[\ReturnTypeWillChange]
                        public function __toString()
                        {
                            return implode(',', $this->value);
                        }

                        /**
                         * @param $offset
                         * @return bool
                         */
                        #[\ReturnTypeWillChange]
                        public function offsetExists($offset)
                        {
                            return isset($this->value[$offset]);
                        }

                        /**
                         * @param $offset
                         * @return object
                         */
                        #[\ReturnTypeWillChange]
                        public function offsetGet($offset)
                        {
                            return new class($this->value[$offset], $this->fieldDefinition) {
                                private $value;

                                /** @var Data\Select */
                                private $fieldDefinition;

                                public function __construct($value, $fieldDefinition)
                                {
                                    $this->value = $value;
                                    $this->fieldDefinition = $fieldDefinition;
                                }

                                public function getValue()
                                {
                                    return $this->value;
                                }

                                public function getLabel($language = null)
                                {
                                    $options = $this->fieldDefinition->getOptions();
                                    foreach ($options as $option) {
                                        if ($option['value'] == $this->value) {
                                            if ($language === null) {
                                                return $option['key'];
                                            }

                                            return \OpenDxp::getContainer()->get('translator')->trans(
                                                $option['key'],
                                                [],
                                                'admin',
                                                $language
                                            );
                                        }
                                    }

                                    return null;
                                }

                                public function __toString()
                                {
                                    return (string)$this->value;
                                }
                            };
                        }

                        /**
                         * @param $offset
                         * @param $value
                         * @return mixed
                         */
                        #[\ReturnTypeWillChange]
                        public function offsetSet($offset, $value)
                        {
                            throw new Exception('Readonly object');
                        }

                        /**
                         * @param $offset
                         * @return mixed
                         */
                        #[\ReturnTypeWillChange]
                        public function offsetUnset($offset)
                        {
                            throw new Exception('Readonly object');
                        }
                    };
                } elseif ($fieldDefinition instanceof Data\Localizedfields) {
                    if (count($arguments) > 0 && Tool::isValidLanguage($arguments[0])) {
                        $languages = [$arguments[0]];
                    } else {
                        $languages = Tool::getValidLanguages();
                    }

                    $localizedFieldValues = [];
                    foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        foreach ($languages as $language) {
                            $localizedFieldValues[$localizedFieldDefinition->getName().'#'.$language] = self::resolveSingleField($localizedFieldDefinition->getName().'#'.$language, $object, $logger);
                        }
                    }
                    $value = $localizedFieldValues;
                }
            } elseif($object instanceof Classificationstore && strtolower($methodName) === 'getlocalizedkeyvalue') {
                $keyConfig = Classificationstore\DefinitionCache::get($arguments[1]);
                $fieldDefinition = Classificationstore\Service::getFieldDefinitionFromKeyConfig($keyConfig);

                $value = new class($value, $fieldDefinition) {
                    private $value;

                    /** @var Data\Select */
                    private $fieldDefinition;

                    public function __construct($value, $fieldDefinition)
                    {
                        $this->value = $value;
                        $this->fieldDefinition = $fieldDefinition;
                    }

                    public function getValue()
                    {
                        return $this->value;
                    }

                    public function getLabel($language = null)
                    {
                        $options = $this->fieldDefinition->getOptions();
                        foreach ($options as $option) {
                            if ($option['value'] == $this->value) {
                                if ($language === null) {
                                    return $option['key'];
                                }

                                return \OpenDxp::getContainer()->get('translator')->trans(
                                    $option['key'],
                                    [],
                                    'admin',
                                    $language
                                );
                            }
                        }

                        return null;
                    }

                    public function __toString()
                    {
                        return (string)$this->value;
                    }
                };
            }

            return self::debugLog($currentField, $value, $logger);
        }

        if($object instanceof PageSnippet) {
            $editable = method_exists($object, 'getEditable') ? $object->getEditable($currentField) : $object->getElement($currentField);
            if($editable) {
                return self::debugLog($currentField, $editable->getValue(), $logger);
            }

            if (strtolower($methodName) === 'gethtml') {
                $html = \OpenDxp\Model\Document\Service::render($object);
                $html = \OpenDxp\Helper\Mail::setAbsolutePaths($html, $object, Helper::getHostUrl());
                return self::debugLog($currentField, $html, $logger);
            }
        }

        if (($object instanceof ElementMetadata || $object instanceof ObjectMetadata) && self::method_exists($object->getElement(), $methodName)) {
            if ($object instanceof ElementMetadata && strtolower($methodName) === 'getthumbnail') {
                if (count($arguments) === 1) {
                    // querying image thumbnail always requires deferred to be false, otherwise the image will not be generated for export
                    $arguments[] = false;
                }

                if (!empty($arguments[0])) {
                    $config = Image\Thumbnail\Config::getByName($arguments[0]);
                    if (!$config instanceof Image\Thumbnail\Config) {
                        $logger->warning('Image Thumbnail configuration "'.$arguments[0].'" does not exist');
                    } elseif (strtolower($config->getFormat()) === 'source' && $object->getElement()->getType() === 'image') {
                        if (!$object->getElement()->isAnimated() && Helper::getFileExtension($object->getElement()->getFilename()) !== 'svg') {
                            if (method_exists(Image\Thumbnail\Config::class, 'getAutoFormats')) {
                                $autoFormats = array_reverse(Image\Thumbnail\Config::getAutoFormats()); // array_reverse is only to get webp first
                            } else {
                                $autoFormats = ['webp' => ['enabled' => true]];
                            }
                            foreach ($autoFormats as $autoFormat => $autoFormatConfig) {
                                if ($autoFormatConfig['enabled']) {
                                    $config->setFormat($autoFormat);
                                    $arguments[0] = $config;
                                    break;
                                }
                            }
                        } else {
                            $config->setFormat('original');
                            $arguments[0] = $config;
                        }
                    }
                }
            }

            $value = $object->getElement()->$methodName(...$arguments);
            if (($value === null || $value === []) && count($arguments) === 1 && $arguments[0] === 'all') {
                $allLanguagesValue = [];
                foreach (Tool::getValidLanguages() as $language) {
                    $allLanguagesValue[$language] = self::resolveSingleField($methodName.'#'.$language, $object->getElement(), $logger);
                }
                return self::debugLog($currentField, $allLanguagesValue, $logger);
            }
            return self::debugLog($currentField, $value, $logger);
        }

        if ($object instanceof Hotspotimage) {
            if(self::method_exists($object->getImage(), $methodName)) {
                if (strtolower($methodName) === 'getthumbnail') {
                    if (count($arguments) === 1) {
                        // querying image thumbnail always requires deferred to be false, otherwise the image will not be generated for export
                        $arguments[] = false;
                    }

                    if (!empty($arguments[0])) {
                        $config = Image\Thumbnail\Config::getByName($arguments[0]);
                        if (!$config instanceof Image\Thumbnail\Config) {
                            $logger->warning('Image Thumbnail configuration "'.$arguments[0].'" does not exist');
                        } elseif (strtolower($config->getFormat()) === 'source' && $object->getImage()->getType() === 'image') {
                            if (!$object->getImage()->isAnimated() && Helper::getFileExtension($object->getImage()->getFilename()) !== 'svg') {
                                if (method_exists(Image\Thumbnail\Config::class, 'getAutoFormats')) {
                                    $autoFormats = array_reverse(Image\Thumbnail\Config::getAutoFormats()); // array_reverse is only to get webp first
                                } else {
                                    $autoFormats = ['webp' => ['enabled' => true]];
                                }
                                foreach ($autoFormats as $autoFormat => $autoFormatConfig) {
                                    if ($autoFormatConfig['enabled']) {
                                        $config->setFormat($autoFormat);
                                        $arguments[0] = $config;

                                        break;
                                    }
                                }
                            } else {
                                $config->setFormat('original');
                                $arguments[0] = $config;
                            }
                        }
                    }
                }
                return self::debugLog($currentField, $object->getImage()->$methodName(...$arguments), $logger);
            }

            if(strtolower($methodName) === 'geturl') {
                if(method_exists($object->getImage(), 'getFrontendFullPath')) {
                    $url = $object->getImage()->getFrontendFullPath();
                } else {
                    $url = urlencode_ignore_slash($object->getImage()->getFullPath());
                }

                $host = parse_url($url, PHP_URL_HOST);
                if(!$host) {
                    $host = Helper::getFrontendUrl();
                    if (!$host) {
                        $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                    }

                    $url = $host.$url;
                }

                return self::debugLog($currentField, $url, $logger);
            }

            if(strtolower($methodName) === 'gethash' || strtolower($methodName) === 'getassetchecksum') {
                return self::debugLog($currentField, Importer::getAssetChecksum($object->getImage()), $logger);
            }

            $metaData = $object->getImage()->getMetadata();
            $metaFieldName = preg_replace('/^get/', '', $methodName);
            foreach($metaData as $metaItem) {
                if(strtolower($metaItem['name']) === strtolower($metaFieldName)) {
                    if(empty($metaItem['language']) || (!empty($arguments[0]) && $arguments[0] === $metaItem['language']) || (empty($arguments[0]) && Helper::getRequest()->getLocale() === $metaItem['language'])) {
                        return self::debugLog($currentField, $metaItem['data'], $logger);
                    }
                }
            }
        }

        if ($object instanceof Asset) {
            if(strtolower($methodName) === 'geturl') {
                if (method_exists($object, 'getFrontendFullPath')) {
                    $url = $object->getFrontendFullPath();
                } else {
                    $url = urlencode_ignore_slash($object->getFullPath());
                }

                $host = parse_url($url, PHP_URL_HOST);
                if (!$host) {
                    $host = Helper::getFrontendUrl();
                    if (!$host) {
                        $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                    }

                    $url = $host.$url;
                }

                return self::debugLog($currentField, $url, $logger);
            }

            if (strtolower($methodName) === 'gethash' || strtolower($methodName) === 'getassetchecksum') {
                return self::debugLog($currentField, Importer::getAssetChecksum($object), $logger);
            }

            if (strtolower($methodName) === 'getmimetype') {
                require_once __DIR__.'/../Import/helpers.php';
                return self::debugLog($currentField, \Sylphen\DataBridgeBundle\lib\Pim\Import\mimeType($object->getRealFullPath()), $logger);
            }

            if (strtolower($methodName) === 'getcontent') {
                return self::debugLog($currentField, Helper::getAssetStorage()->read($object->getRealFullPath()), $logger);
            }

            if (strtolower($methodName) === 'getfilesystempath') {
                if (defined('OPENDXP_ASSET_DIRECTORY') && file_exists(\OPENDXP_ASSET_DIRECTORY.$object->getRealFullPath())) {
                    return self::debugLog($currentField, \OPENDXP_ASSET_DIRECTORY.$object->getRealFullPath(), $logger);
                }

                if (file_exists(\OPENDXP_WEB_ROOT.'/var/assets'.$object->getRealFullPath())) {
                    return self::debugLog($currentField, \OPENDXP_WEB_ROOT.'/var/assets'.$object->getRealFullPath(), $logger);
                }

                return self::debugLog($currentField, Helper::getTemporaryAssetFile($object->getRealFullPath()), $logger);
            }
        }

        if ($object instanceof ElementMetadata) {
            if(strtolower($methodName) === 'geturl') {
            if (method_exists($object->getElement(), 'getFrontendFullPath')) {
                $url = $object->getElement()->getFrontendFullPath();
            } else {
                $url = urlencode_ignore_slash($object->getElement()->getFullPath());
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) {
                $host = Helper::getFrontendUrl();
                if (!$host) {
                    $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                }

                $url = $host.$url;
            }

            return self::debugLog($currentField, $url, $logger);
            }

            if (strtolower($methodName) === 'gethash' || strtolower($methodName) === 'getassetchecksum') {
                return self::debugLog($currentField, Importer::getAssetChecksum($object->getElement()), $logger);
            }
        }

        if ($object instanceof DataObject\Data\Video) {
            if(strtolower($methodName) === 'geturl') {
                if ($object->getType() === 'asset') {
                    if (method_exists($object->getData(), 'getFrontendFullPath')) {
                        $url = $object->getData()->getFrontendFullPath();
                    } else {
                        $url = urlencode_ignore_slash($object->getData()->getFullPath());
                    }

                    $host = parse_url($url, PHP_URL_HOST);
                    if (!$host) {
                        $host = Helper::getFrontendUrl();
                        if (!$host) {
                            $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                        }

                        $url = $host.$url;
                    }

                    return self::debugLog($currentField, $url, $logger);
                } elseif ($object->getType() === 'youtube') {
                    return self::debugLog($currentField, 'https://youtu.be/'.$object->getData(), $logger);
                } elseif ($object->getType() === 'vimeo') {
                    return self::debugLog($currentField, 'https://vimeo.com/'.$object->getData(), $logger);
                } elseif ($object->getType() === 'dailymotion') {
                    return self::debugLog($currentField, 'https://www.dailymotion.com/video/'.$object->getData(), $logger);
                }
            } elseif ($object->getData() instanceof Video && self::method_exists($object->getData(), $methodName)) {
                if (strtolower($methodName) === 'getthumbnail') {
                    return self::debugLog($currentField, self::generateVideoThumbnail($object->getData(), $arguments), $logger);
                }

                if (strtolower($methodName) === 'gethash' || strtolower($methodName) === 'getassetchecksum') {
                    return self::debugLog($currentField, Importer::getAssetChecksum($object->getData()), $logger);
                }

                return self::debugLog($currentField, $object->getData()->$methodName(...$arguments), $logger);
            }
        }

        if (($object instanceof Image\Thumbnail || $object instanceof Asset\Document\ImageThumbnail || $object instanceof Video\ImageThumbnail)) {
            if(strtolower($methodName) === 'geturl') {
                $host = Helper::getFrontendUrl();
                if (!$host) {
                    $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                }

                if(!isset($arguments[0])) {
                    $arguments[0] = [];
                }
                $arguments[0]['frontend'] = true;

                return self::debugLog($currentField, $host.$object->getPath(...$arguments), $logger);
            }

            if ((strtolower($methodName) === 'gethash' || strtolower($methodName) === 'getassetchecksum') && $object->getAsset() instanceof Asset) {
                return self::debugLog($currentField, Importer::getAssetChecksum($object->getAsset()), $logger);
            }
        }

        if ($object instanceof Asset\Document && strtolower($methodName) === 'getthumbnail') {
            if (count($arguments) === 1) {
                $arguments[] = 1; // page
                // querying image / video thumbnail always requires deferred to be false, otherwise the image will not be generated for export
                $arguments[] = false;
            } elseif (count($arguments) === 2) {
                $arguments[] = false;
            }
            return self::debugLog($currentField, $object->getImageThumbnail(...$arguments), $logger);
        }

        if($object instanceof Concrete && in_array(strtolower($methodName), ['getlink', 'geturl'], true)) {
            $linkGenerator = $object->getClass()->getLinkGenerator();
            if($linkGenerator instanceof ClassDefinition\LinkGeneratorInterface) {
                $url = $linkGenerator->generate($object, [
                    'referenceType' => UrlGeneratorInterface::ABSOLUTE_URL,
                    '_locale' => Helper::getRequest()->getLocale()
                ]);

                $host = parse_url($url, PHP_URL_HOST);
                if (!$host) {
                    $host = Helper::getFrontendUrl();
                    if (!$host) {
                        $logger->warning('Could not determine Domain. Please set it in Settings -> System -> Website -> Main Domain');
                    }

                    $url = $host.$url;
                }

                return self::debugLog($currentField, $url, $logger);
            }
        }

        if ($object instanceof ElementInterface && strtolower($methodName) === 'getdeeplink') {
            $objectType = OpenDxp\Model\Element\Service::getElementType($object);
            $type = 'object';
            if ($object instanceof \OpenDxp\Model\Document) {
                $type = $object->getType();
            } elseif ($object instanceof \OpenDxp\Model\Asset) {
                $type = $object->getType();
            }

            $router = OpenDxp::getContainer()->get('router');
            return self::debugLog($currentField, Helper::getHostUrl().$router->generate('opendxp_admin_login_deeplink').'?'.$objectType.'_'.$object->getId().'_'.$type, $logger);
        }

        if ($object instanceof ElementMetadata && $object->getElement() instanceof Asset\Document && strtolower($methodName) === 'getthumbnail') {
            if (count($arguments) === 1) {
                $arguments[] = 1; // page
                // querying image / video thumbnail always requires deferred to be false, otherwise the image will not be generated for export
                $arguments[] = false;
            } elseif (count($arguments) === 2) {
                $arguments[] = false;
            }
            return self::debugLog($currentField, $object->getElement()->getImageThumbnail(...$arguments), $logger);
        }

        if($object instanceof ElementInterface && strtolower($methodName) === 'gettags') {
            $tags = Tag::getTagsForElement(OpenDxp\Model\Element\Service::getElementType($object), $object->getId());
            $resultTags = [];
            foreach($tags as $tag) {
                if ($tag instanceof Tag) {
                    $tag = $tag->getNamePath();
                }

                if(strpos($tag, '/Data Bridge/') !== 0 && strpos($tag, '/Data Director/') !== 0) {
                    $resultTags[] = $tag;
                }
            }

            return self::debugLog($currentField, $resultTags, $logger);
        }

        if (is_array($object) && isset($object[0])) {
            if(self::method_exists($object[0], $methodName)) {
                return self::debugLog($currentField, $object[0]->$methodName(...$arguments), $logger);
            }
            if(($object[0] instanceof ObjectMetadata || $object[0] instanceof ElementMetadata) && self::method_exists($object[0]->getElement(), $methodName)) {
                return self::debugLog($currentField, $object[0]->getElement()->$methodName(...$arguments), $logger);
            }
        }

        if($object instanceof Classificationstore) {
            if(strtolower($methodName) === 'getlabels') {
                $serializer = new Serializer();
                $activeGroups = $object->getActiveGroups();
                $groups = [];
                foreach (array_keys($activeGroups) as $groupId) {
                    $groupConfig = Classificationstore\GroupConfig::getById($groupId);
                    if ($groupConfig instanceof Classificationstore\GroupConfig) {
                        $groups[] = new Classificationstore\Group($object, $groupConfig);
                    }
                }

                $translator = \OpenDxp::getContainer()->get(TranslationHelper::class);
                if (count($arguments) > 1 && Tool::isValidLanguage($arguments[1])) {
                    $language = $arguments[1];
                } else {
                    $language = Helper::getRequest()->getLocale();
                }

                $classificationStoreData = [];
                /** @var Classificationstore\Group $group */
                foreach ($groups as $group) {
                    unset($classificationStoreValues);
                    $classificationStoreValues = [];
                    if (!empty($arguments[0])) {
                        $groupName = \OpenDxp::getContainer()->get('translator')->trans($group->getConfiguration()->getDescription() ?? '', [], 'admin', Tool::getDefaultLanguage()) ?: $group->getConfiguration()->getName();
                        $classificationStoreData[$groupName] = ['groupTitle' => $translator->translate($group->getConfiguration()->getDescription() ?? '', $language), 'values' => &$classificationStoreValues];
                    } else {
                        $groupName = $translator->translate($group->getConfiguration()->getDescription() ?? $group->getConfiguration()->getName(), $language);
                        $classificationStoreData[$groupName] = &$classificationStoreValues;
                    }
                    foreach ($group->getKeys() as $key) {
                        $fieldDefinition = $key->getFieldDefinition();

                        $classificationStoreValue = null;
                        if (!empty($arguments[0])) {
                            $title = \OpenDxp::getContainer()->get('translator')->trans($fieldDefinition->getTitle() ?? '', [], 'admin', Tool::getDefaultLanguage()) ?: $fieldDefinition->getName();
                            $classificationStoreValues[$title] = ['fieldTitle' => $translator->translate($fieldDefinition->getTitle() ?? '', $language) ?: $fieldDefinition->getName(), 'value' => &$classificationStoreValue];
                        } else {
                            $title = $translator->translate($fieldDefinition->getTitle() ?? '', $language) ?: $fieldDefinition->getName();
                            $classificationStoreValues[$title] = &$classificationStoreValue;
                        }

                        $classificationStoreValue = $serializer->serializeField($key, $fieldDefinition);

                        if ($fieldDefinition instanceof Data\Select) {
                            foreach ($fieldDefinition->getOptions() as $option) {
                                if ($option['value'] == $classificationStoreValue) {
                                    $classificationStoreValue = $translator->translate($option['key'] ?? $option['value'], $language);
                                    break;
                                }
                            }
                        } elseif (is_string($classificationStoreValue)) {
                            $classificationStoreValue = $translator->translate($classificationStoreValue, $language);
                        }

                        unset($classificationStoreValue);
                    }
                }
                return $classificationStoreData;
            }

            try {
                $activeGroups = $object->getActiveGroups();
                foreach (array_keys($activeGroups) as $groupId) {
                    $groupConfig = Classificationstore\GroupConfig::getById($groupId);
                    if ($groupConfig instanceof Classificationstore\GroupConfig && ($groupId == $currentField || $groupConfig->getName() == $currentField)) {
                        $group = new Classificationstore\Group($object, $groupConfig);
                        $serializer = new Serializer();

                        $groupValues = [];
                        foreach ($group->getKeys() as $key) {
                            $groupValues[$key->getFieldDefinition()->getName()] = $serializer->serializeField($key, $key->getFieldDefinition());
                        }

                        return $groupValues;
                    }
                }
            } catch (\Throwable $e) {
                $groups = [];
            }
        }
        
        if($object instanceof Objectbrick && strtolower($methodName) === 'getlabels') {
            $serializer = new Serializer();

            $translator = \OpenDxp::getContainer()->get(TranslationHelper::class);
            if (count($arguments) > 1 && Tool::isValidLanguage($arguments[1])) {
                $language = $arguments[1];
            } else {
                $language = Helper::getRequest()->getLocale();
            }

            $objectBrickData = [];
            foreach ($object->getBrickGetters() as $brickGetter) {
                $brickItem = $object->$brickGetter();

                if ($brickItem instanceof AbstractData) {
                    /** @var Definition $brickDefinition */
                    $brickDefinition = $brickItem->getDefinition();

                    unset($objectBrickValues);
                    $objectBrickValues = [];
                    if (!empty($arguments[0])) {
                        $groupName = $brickItem->getType();
                        $objectBrickData[$groupName] = [
                            'groupTitle' => $translator->translate($brickItem->getDefinition()->getTitle() ?: $brickItem->getType(), $language),
                            'values' => &$objectBrickValues
                        ];
                    } else {
                        $groupName = $translator->translate($brickItem->getDefinition()->getTitle() ?: $brickItem->getType(), $language);
                        $objectBrickData[$groupName] = &$objectBrickValues;
                    }

                    $fieldDefinitions = [];
                    foreach($brickDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if($fieldDefinition instanceof Data\Localizedfields) {
                            $localizedFieldDefinitions = $fieldDefinition->getFieldDefinitions();
                            foreach ($localizedFieldDefinitions as $localizedFieldDefinition) {
                                $fieldDefinitions[] = $localizedFieldDefinition;
                            }
                        } else {
                            $fieldDefinitions[] = $fieldDefinition;
                        }
                    }

                    foreach ($fieldDefinitions as $fieldDefinition) {
                        $objectBrickValue = null;
                        if (!empty($arguments[0])) {
                            $title = $fieldDefinition->getName();
                            $objectBrickValues[$title] = [
                                'fieldTitle' => $translator->translate($fieldDefinition->getTitle() ?? '', $language) ?: $fieldDefinition->getName(),
                                'value' => &$objectBrickValue,
                                'fieldType' => $fieldDefinition->getFieldtype(),
                            ];
                        } else {
                            $title = $translator->translate($fieldDefinition->getTitle() ?? '', $language) ?: $fieldDefinition->getName();
                            if(isset($objectBrickValues[$title])) {
                                $title .= ' '.$fieldDefinition->getName();
                            }
                            $objectBrickValues[$title] = &$objectBrickValue;
                        }

                        $objectBrickValue = $serializer->serializeField($brickItem, $fieldDefinition);

                        if ($fieldDefinition instanceof Data\Select) {
                            foreach ($fieldDefinition->getOptions() as $option) {
                                if ($option['value'] == $objectBrickValue) {
                                    $objectBrickValue = $translator->translate($option['key'] ?? $option['value'], $language);
                                    break;
                                }
                            }
                        } elseif ($fieldDefinition instanceof Data\Multiselect) {
                            if (is_array($objectBrickValue)) {
                                foreach ($objectBrickValue as &$selectedOption) {
                                    foreach ($fieldDefinition->getOptions() as $option) {
                                        if ($option['value'] == $selectedOption) {
                                            $selectedOption = $translator->translate($option['key'] ?? $option['value'], $language);
                                            break;
                                        }
                                    }
                                }
                                unset($selectedOption);
                            }
                        } elseif (is_string($objectBrickValue)) {
                            $objectBrickValue = $translator->translate($objectBrickValue);
                        }

                        unset($objectBrickValue);
                    }
                }
            }
            return $objectBrickData;
        }

        if (($object instanceof Concrete || $object instanceof AbstractData || $object instanceof Fieldcollection\Data\AbstractData)) {
            $fieldName = preg_replace('/^get/', '', $methodName);
            $fieldDefinition = Importer::getFieldDefinition($object, $fieldName);

            if ($fieldDefinition instanceof Data\ReverseObjectRelation || $fieldDefinition instanceof Data\ReverseManyToManyObjectRelation) {
                $refKey = $fieldDefinition->getOwnerFieldName();
                $refId = Helper::getClassDefinitionByName($fieldDefinition->getOwnerClassId());

                $relationData = $object->getRelationData($refKey, false, $refId);
                $value = [];
                foreach ($relationData as $relation) {
                    $relatedObject = DataObject::getById($relation['src_id']);
                    if ($relatedObject instanceof DataObject\Concrete) {
                        $value[] = $relatedObject;
                    }
                }

                return self::debugLog($currentField, $value, $logger);
            }
        }

        $functionName = $currentField;
        if($argumentsPosition !== false) {
            $functionName = substr($currentField, 0, $argumentsPosition);
            $placeholderFound = false;
            foreach($arguments as $index => $argument) {
                if($argument === '%s') {
                    $arguments[$index] = $object;
                    $placeholderFound = true;
                }
            }

            if(!$placeholderFound) {
                array_unshift($arguments, $object);
            }
        } else {
            $arguments = [$object];
        }

        $functionNameParts = explode('::', $functionName);
        if(count($functionNameParts) === 2) {
            if (strpos($functionNameParts[0], '@') === 0) {
                $serviceName = substr($functionNameParts[0], 1);
                $functionNameParts[0] = \OpenDxp::getKernel()->getContainer()->get($serviceName);
            }

            if (class_exists($functionNameParts[0]) && self::method_exists($functionNameParts[0], $functionNameParts[1])) {
                return self::debugLog($currentField, call_user_func([$functionNameParts[0], $functionNameParts[1]], ...$arguments), $logger);
            } else {
                $currentField = str_replace('::', ':', $currentField);
                $logger->notice('Could not find service / class method '.$functionNameParts[0].'::'.$functionNameParts[1].', falling back to '.$currentField);
            }
        }

        require_once __DIR__.'/../Import/helpers.php';
        $functionIsLanguageConstruct = in_array($functionName, ['empty', 'isset'], true);

        if ((!function_exists($functionName) || in_array($functionName, ['array_merge', 'implode'], true)) && !$functionIsLanguageConstruct) {
            $functionName = '\\Sylphen\\DataBridgeBundle\\lib\\Pim\\Import\\'.$functionName;
        }

        if (\function_exists($functionName) || $functionIsLanguageConstruct) {
            if ($functionIsLanguageConstruct) {
                if ($functionName === 'empty') {
                    return self::debugLog($currentField, empty($object), $logger);
                }
                if($functionName === 'isset') {
                    return self::debugLog($currentField, isset($object), $logger);
                }
                return self::debugLog($currentField, eval('return '.$functionName.'("'.$object.'");'), $logger);
            }

            if ($functionName === 'key' && !is_array($arguments[0])) {
                return self::debugLog($currentField, null, $logger);
            }

            set_error_handler(static function ($errorLevelCode, $errstr, $errfile, $errline) {
                // error was suppressed with the @-operator
                if ((PHP_VERSION_ID < 80000 && 0 === error_reporting()) || (PHP_VERSION_ID >= 80000 && (!(error_reporting() & $errorLevelCode)))) {
                    return false;
                }

                throw new ErrorException($errstr, 0, $errorLevelCode, $errfile, $errline);
            });
            try {
                return self::debugLog($currentField, $functionName(...$arguments), $logger);
            } catch(Throwable $e) {
                $firstArgument = array_shift($arguments);

                if($firstArgument === $object) {
                    try {
                        return self::debugLog($currentField, @$functionName(...$arguments), $logger);
                    } catch (Throwable $fallbackException) {
                        $logger->debug('Exception was thrown: '.$e->getMessage());
                        return self::debugLog($currentField, null, $logger);
                    }
                } else {
                    $logger->debug('Exception was thrown: '.$e->getMessage());
                }
            } finally {
                restore_error_handler();
            }
        }

        if ($object instanceof ElementInterface && $currentField === '0') {
            return self::debugLog($currentField, $object, $logger);
        }

        if (is_object($object)) {
            try {
                return self::debugLog($currentField, @$object->$currentField, $logger);
            } catch (Throwable $propertyException) {
            }
        }

        $logger->debug('Field or function "'.$currentField.'" could not be resolved');
        return null;
    }

    private static function debugLog($part, $result, LoggerInterface $logger) {
        $logger->debug(new LazyLog(static function () use ($part, $result) {
            $logOutput = Importer::getLogOutput($result);
            return 'Result of data query selector part "'.$part.'" is "'.$logOutput.'"';
        }));
        return $result;
    }

    private static function getSerializer() {
        if(self::$serializer === null) {
            self::$serializer = new Serializer();
        }
        return self::$serializer;
    }

    private static function method_exists($object, $methodName) {
        if((is_object($object) || is_string($object)) && \method_exists($object, $methodName)) {
            return true;
        }

        if(strpos($methodName, 'get') !== 0) {
            return false;
        }

        if($object instanceof ObjectMetadata || $object instanceof ElementMetadata) {
            return in_array(strtolower(substr($methodName, 3)), array_map('strtolower', $object->getColumns()), true);
        }

        if($object instanceof ParameterBagInterface) {
            return $object->fieldExists(substr($methodName, 3));
        }

        return false;
    }

    private static function generateVideoThumbnail(Video $object, array $arguments = []) {
        if (!empty($arguments[1]) && is_string($arguments[1])) {
            // format
            $arguments[1] = explode(',', $arguments[1]);
        } else {
            $arguments[1] = [];
        }

        $thumbnailConfig = $object->getThumbnailConfig($arguments[0]);

        createThumbnail:
        $thumbnailProcessor = Video\Thumbnail\Processor::process($object, $thumbnailConfig, $arguments[1]);
        if ($thumbnailProcessor) {
            $thumbnailProcessor->save();
            $thumbnailProcessor->execute($thumbnailProcessor->getProcessId());
        }

        $thumbnail = $object->getThumbnail(...$arguments);

        if (isset($thumbnail['status']) && $thumbnail['status'] === 'finished') {
            $thumbnailPath = $object->getRealPath().ltrim(reset($thumbnail['formats']), '/');

            $thumbnailObject = new class($thumbnailPath) {
                private $thumbnailPath;

                public function __construct($thumbnailPath)
                {
                    $this->thumbnailPath = $thumbnailPath;
                }

                public function getUrl()
                {
                    $host = Helper::getHostUrl();
                    return $host.$this->thumbnailPath;
                }

                public function __toString()
                {
                    return $this->thumbnailPath;
                }
            };

            return $thumbnailObject;
        }

        if (isset($thumbnail['status']) && $thumbnail['status'] === 'inprogress') {
            if ($object->getModificationDate() < time() - 300 || !TmpStore::get('video-job-'.$thumbnail['processId'])) {
                $customSetting = $object->getCustomSetting('thumbnails');
                $customSetting = is_array($customSetting) ? $customSetting : [];

                unset($customSetting[$thumbnailConfig->getName()]);

                $object->setCustomSetting('thumbnails', $customSetting);

                OpenDxp\Model\Version::disable();
                $object->save();
                OpenDxp\Model\Version::enable();
                goto createThumbnail;
            } else {
                $maxThumbnailProcessingTime = 60;
                do {
                    $customSettings = PimcoreDbRepository::getInstance()->findOneInSql('SELECT customSettings FROM assets WHERE id=?', [$object->getId()]);
                    if (!$customSettings) {
                        goto createThumbnail;
                    }

                    $customSettings = Tool\Serialize::unserialize($customSettings);

                    if (!isset($customSettings['thumbnails'][$thumbnailConfig->getName()]['status']) || $customSettings['thumbnails'][$thumbnailConfig->getName()]['status'] !== 'finished') {
                        sleep(1);
                    }
                } while ($maxThumbnailProcessingTime-- > 0);
            }
        }

        return null;
    }
}
