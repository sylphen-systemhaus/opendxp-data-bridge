<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ArrayIterator;
use Aws\S3\S3Client;
use Sylphen\DataBridgeBundle\EventListener\IncompatibleTypeException;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBag;
use Sylphen\DataBridgeBundle\lib\Pim\LockableTrait;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\FlysystemSortedFileIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\SplFileInfoSortedFileIterator;
use Sylphen\DataBridgeBundle\lib\Pim\TemporaryFileHelperTrait;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\Tools\Installer;
use CachingIterator;
use CallbackFilterIterator;
use Exception;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\StorageAttributes;
use OpenDxp;
use OpenDxp\Config;
use OpenDxp\File;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\Element\AbstractElement;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Tool\Lock;
use OpenDxp\Tool\Mime;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Mime\MimeTypes;
use UnexpectedValueException;

trait ResourceBasedParser
{
    use TemporaryFileHelperTrait;
    use LockableTrait;

    /** @var LoggerInterface */
    private $logger;

    private $removeFileAfterImport = false;

    private $source;

    private $sourceFilePath;

    private $archiveSafeSourceFilePath;

    /** @var string */
    private $statusKey;

    /** @var null|callable */
    private $deleteImportFile;

    /** @var string */
    private $archiveFilename;

    /** @var string */
    private $lockKey;

    /** @var string[] */
    private $lockKeys = [];

    private $stream;

    /** @var ImporterInterface */
    private $importer;

    private static $cacheLastModifiedFiles = [];

    private $foundImportResource = false;

    /** @var bool */
    private $force = false;

    /**
     * @param string $source
     * @param \SplFileInfo $filterForFile check if given file is covered by set source path
     *
     * @return null|string
     */
    private function getFileOrUrl(\SplFileInfo $filterForFile = null) {
        if($this->sourceFilePath === null) {
            if(!$this->source) {
                return null;
            }

            $this->archiveSafeSourceFilePath = null;
            $this->deleteImportFile = null;
            $this->archiveFilename = null;

            $this->source = preg_replace('/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $this->source);

            $inheritanceEnabled = AbstractObject::getGetInheritedValues();
            try {
                $importer = $this->getImporter();
                if(!empty($this->config['dataportId'])) {
                    $importer->setDataport(Dataport::getInstance()->get($this->config['dataportId']));
                }

                Helper::useInheritance(true);

                $this->source = $importer->replaceObjectIdentifier($this->source, $this->config['parameters'] ?? null);
            } catch (\Throwable $e) {
                $this->logger->warning((string)$e);
            } finally {
                Helper::useInheritance($inheritanceEnabled);
            }

            $this->source = ($this->source === '/' ? $this->source : rtrim($this->source, '/'));

            $asset = Asset::getByPath($this->source);
            if($asset instanceof Asset) {
                if (defined('OPENDXP_ASSET_DIRECTORY')) {
                    $this->source = \OPENDXP_ASSET_DIRECTORY.$asset->getRealFullPath();
                } else {
                    $this->source = \OPENDXP_WEB_ROOT.'/var/assets'.$asset->getRealFullPath();
                }
            }

            if (strpos($this->source, 'ftp://') === 0) {
                if ($filterForFile !== null) {
                    $this->sourceFilePath = null;
                    return;
                }
                $urlParts = parse_url($this->source);
                if ($urlParts === false && preg_match('/^ftp:\/\/(.+):(.+)@/', $this->source, $match)) {
                    $source = str_replace($match[0], 'ftp://', $this->source);
                    $urlParts = parse_url($source);
                }

                $fileSystem = Helper::getFileSystem($this->source);

                $pathIncludingFilters = explode('|', $urlParts['path']);
                $filters = array_slice($pathIncludingFilters, 1);

                $urlParts['path'] = rtrim($pathIncludingFilters[0]);
                $pathParts = explode('/', $urlParts['path']);
                $pathPartsWithoutWildcard = [];
                $pathWithWildcards = [];
                foreach ($pathParts as $pathPartIndex => $pathPart) {
                    if (strpos($pathPart, '*') === false) {
                        $pathPartsWithoutWildcard[] = $pathPart;
                    } else {
                        $pathWithWildcards = array_slice($pathParts, $pathPartIndex);
                        break;
                    }
                }
                $pathPartsWithoutWildcard = implode('/', $pathPartsWithoutWildcard);
                $pathWithWildcards = implode('/', $pathWithWildcards);

                $directoryIterator = $this->getDirectoryIterator($fileSystem, $pathPartsWithoutWildcard, $pathWithWildcards);
                try {
                    $sortedIterator = new FlysystemSortedFileIterator($directoryIterator);

                    foreach ($filters as $filter) {
                        if (trim($filter) === 'latest') {
                            $lastFile = null;
                            foreach ($sortedIterator as $file) {
                                $lastFile = $file;
                            }
                            if ($lastFile) {
                                $sortedIterator = new ArrayIterator([$lastFile]);
                            } else {
                                $sortedIterator = new ArrayIterator([]);
                            }
                        }
                    }

                    $remoteFilePath = $this->getFileFromIterator($sortedIterator);
                } catch (UnableToConnectToFtpHost $e) {
                    $remoteFilePath = null;
                }

                if ($remoteFilePath === null) {
                    $remoteFilePath = $this->getFileIfNotLocked($urlParts['path']); // fetch single file (above Iterator only lists directory contents)
                }

                if ($remoteFilePath) {
                    try {
                        $stream = $fileSystem->readStream($remoteFilePath);
                        $this->logger->info('Importing '.$remoteFilePath);
                        $localFilePath = (new SplFileInfo(Helper::getTemporaryFileFromStream($stream)))->getRealPath();
                        $this->sourceFilePath = $this->extractFile($localFilePath);
                        @fclose($stream);

                        if ($localFilePath === $this->sourceFilePath) {
                            $this->deleteImportFile = static function () use ($fileSystem, $remoteFilePath) {
                                $fileSystem->delete($remoteFilePath);
                            };

                            $this->archiveFilename = basename($remoteFilePath);
                        }
                    } catch (\Exception $e) {
                        if ($fileSystem->fileExists($remoteFilePath)) {
                            throw $e;
                        }
                        $this->sourceFilePath = null;
                    }
                }

                if ($this->sourceFilePath !== null) {
                    $this->foundImportResource = true;
                } elseif (!$this->foundImportResource) {
                    $this->logger->info('Could not find an import resource for '.$this->source);
                }

                return $this->sourceFilePath;
            }

            if (strpos($this->source, 'ftps://') === 0) {
                if ($filterForFile !== null) {
                    $this->sourceFilePath = null;
                    return null;
                }
                $urlParts = parse_url($this->source);
                if ($urlParts === false && preg_match('/^ftps:\/\/(.+):(.+)@/', $this->source, $match)) {
                    $source = str_replace($match[0], 'ftps://', $this->source);
                    $urlParts = parse_url($source);
                }

                $fileSystem = Helper::getFileSystem($this->source);

                $pathIncludingFilters = explode('|', $urlParts['path']);
                $filters = array_slice($pathIncludingFilters, 1);

                $urlParts['path'] = rtrim($pathIncludingFilters[0]);
                $pathParts = explode('/', $urlParts['path']);
                $pathPartsWithoutWildcard = [];
                $pathWithWildcards = [];
                foreach ($pathParts as $pathPartIndex => $pathPart) {
                    if (strpos($pathPart, '*') === false) {
                        $pathPartsWithoutWildcard[] = $pathPart;
                    } else {
                        $pathWithWildcards = array_slice($pathParts, $pathPartIndex);
                        break;
                    }
                }

                $pathPartsWithoutWildcard = implode('/', $pathPartsWithoutWildcard);
                $pathWithWildcards = implode('/', $pathWithWildcards);

                $directoryIterator = $this->getDirectoryIterator($fileSystem, $pathPartsWithoutWildcard, $pathWithWildcards);

                try {
                    $sortedIterator = new FlysystemSortedFileIterator($directoryIterator);

                    foreach ($filters as $filter) {
                        if (trim($filter) === 'latest') {
                            $lastFile = null;
                            foreach ($sortedIterator as $file) {
                                $lastFile = $file;
                            }
                            if ($lastFile) {
                                $sortedIterator = new ArrayIterator([$lastFile]);
                            } else {
                                $sortedIterator = new ArrayIterator([]);
                            }
                        }
                    }

                    $remoteFilePath = $this->getFileFromIterator($sortedIterator);
                } catch(UnableToConnectToFtpHost $e) {
                    $remoteFilePath = null;
                }

                if ($remoteFilePath === null) {
                    $remoteFilePath = $this->getFileIfNotLocked($urlParts['path']); // fetch single file (above Iterator only lists directory contents)
                }

                if ($remoteFilePath) {
                    try {
                        $stream = $fileSystem->readStream($remoteFilePath);
                        $this->logger->info('Importing '.$remoteFilePath);
                        $localFilePath = (new SplFileInfo(Helper::getTemporaryFileFromStream($stream)))->getRealPath();
                        $this->sourceFilePath = $this->extractFile($localFilePath);
                        @fclose($stream);

                        if ($localFilePath === $this->sourceFilePath) {
                            $this->deleteImportFile = static function () use ($fileSystem, $remoteFilePath) {
                                $fileSystem->delete($remoteFilePath);
                            };

                            $this->archiveFilename = basename($remoteFilePath);
                        }
                    } catch (\Exception $e) {
                        if ($fileSystem->fileExists($remoteFilePath)) {
                            throw $e;
                        }
                        $this->sourceFilePath = null;
                    }
                }

                if ($this->sourceFilePath !== null) {
                    $this->foundImportResource = true;
                } elseif (!$this->foundImportResource) {
                    $this->logger->info('Could not find an import resource for '.$this->source);
                }

                return $this->sourceFilePath;
            }

            if (strpos($this->source, 'sftp://') === 0) {
                if ($filterForFile !== null) {
                    $this->sourceFilePath = null;
                    return null;
                }

                $urlParts = parse_url($this->source);
                if($urlParts === false && preg_match('/^sftp:\/\/(.+):(.+)@/', $this->source, $match)) {
                    $source = str_replace($match[0], 'sftp://', $this->source);
                    $urlParts = parse_url($source);
                }

                $fileSystem = Helper::getFileSystem($this->source);

                $pathIncludingFilters = explode('|', $urlParts['path']);
                $filters = array_slice($pathIncludingFilters, 1);

                $urlParts['path'] = rtrim($pathIncludingFilters[0]);
                $pathParts = explode('/', $urlParts['path']);
                $pathPartsWithoutWildcard = [];
                $pathWithWildcards = [];
                foreach($pathParts as $pathPartIndex => $pathPart) {
                    if (strpos($pathPart, '*') === false) {
                        $pathPartsWithoutWildcard[] = $pathPart;
                    } else {
                        $pathWithWildcards = array_slice($pathParts, $pathPartIndex);
                        break;
                    }
                }

                $pathPartsWithoutWildcard = implode('/', $pathPartsWithoutWildcard);
                $pathWithWildcards = implode('/', $pathWithWildcards);

                $directoryIterator = $this->getDirectoryIterator($fileSystem, $pathPartsWithoutWildcard, $pathWithWildcards);
                $sortedIterator = new FlysystemSortedFileIterator($directoryIterator);

                foreach($filters as $filter) {
                    if(trim($filter) === 'latest') {
                        $lastFile = null;
                        foreach($sortedIterator as $file) {
                            $lastFile = $file;
                        }
                        if($lastFile) {
                            $sortedIterator = new ArrayIterator([$lastFile]);
                        } else {
                            $sortedIterator = new ArrayIterator([]);
                        }
                    }
                }

                $remoteFilePath = $this->getFileFromIterator($sortedIterator);

                if($remoteFilePath === null) {
                    $remoteFilePath = $this->getFileIfNotLocked($urlParts['path']);
                }

                if ($remoteFilePath) {
                    try {
                        $stream = $fileSystem->readStream($remoteFilePath);
                        $this->logger->info('Importing '.$remoteFilePath);
                        $localFilePath = (new SplFileInfo(Helper::getTemporaryFileFromStream($stream)))->getRealPath();
                        $this->sourceFilePath = $this->extractFile($localFilePath);
                        @fclose($stream);

                        if ($localFilePath === $this->sourceFilePath) {
                            $this->deleteImportFile = static function () use ($fileSystem, $remoteFilePath) {
                                $fileSystem->delete($remoteFilePath);
                            };

                            $this->archiveFilename = basename($remoteFilePath);
                        }
                    } catch(\Exception $e) {
                        if($fileSystem->fileExists($remoteFilePath)) {
                            throw $e;
                        }
                        $this->sourceFilePath = null;
                    }
                }

                if ($this->sourceFilePath !== null) {
                    $this->foundImportResource = true;
                } elseif(!$this->foundImportResource) {
                    $this->logger->info('Could not find an import resource for '.$this->source);
                }

                return $this->sourceFilePath;
            }

            if (strpos($this->source, 's3://') === 0) {
                if ($filterForFile !== null) {
                    $this->sourceFilePath = null;
                    return null;
                }
                $urlParts = parse_url($this->source);
                if ($urlParts === false && preg_match('/^s3:\/\/(.+):(.+)@/', $this->source, $match)) {
                    $source = str_replace($match[0], 's3://', $this->source);
                    $urlParts = parse_url($source);
                }

                $pathIncludingFilters = explode('|', $urlParts['path']);

                $urlParts['path'] = rtrim($pathIncludingFilters[0]);
                $pathParts = array_filter(explode('/', $urlParts['path']));

                array_shift($pathParts);

                $fileSystem = Helper::getFileSystem($this->source);

                $filters = array_slice($pathIncludingFilters, 1);

                $pathPartsWithoutWildcard = [];
                $pathWithWildcards = [];
                foreach ($pathParts as $pathPartIndex => $pathPart) {
                    if (strpos($pathPart, '*') === false) {
                        $pathPartsWithoutWildcard[] = $pathPart;
                    } else {
                        $pathWithWildcards = array_slice($pathParts, $pathPartIndex);
                        break;
                    }
                }
                $pathPartsWithoutWildcard = implode('/', $pathPartsWithoutWildcard);
                $pathWithWildcards = implode('/', $pathWithWildcards);

                $directoryIterator = $this->getDirectoryIterator($fileSystem, $pathPartsWithoutWildcard, $pathWithWildcards);

                $sortedIterator = new FlysystemSortedFileIterator($directoryIterator);

                foreach ($filters as $filter) {
                    if (trim($filter) === 'latest') {
                        $lastFile = null;
                        foreach ($sortedIterator as $file) {
                            $lastFile = $file;
                        }
                        if ($lastFile) {
                            $sortedIterator = new ArrayIterator([$lastFile]);
                        } else {
                            $sortedIterator = new ArrayIterator([]);
                        }
                    }
                }

                $remoteFilePath = $this->getFileFromIterator($sortedIterator);

                if ($remoteFilePath === null) {
                    $remoteFilePath = $this->getFileIfNotLocked($urlParts['path']); // fetch single file (above Iterator only lists directory contents)
                }

                if ($remoteFilePath) {
                    try {
                        $stream = $fileSystem->readStream($remoteFilePath);
                        $this->logger->info('Importing '.$remoteFilePath);
                        $localFilePath = (new SplFileInfo(Helper::getTemporaryFileFromStream($stream)))->getRealPath();
                        $this->sourceFilePath = $this->extractFile($localFilePath);
                        @fclose($stream);

                        if ($localFilePath === $this->sourceFilePath) {
                            $this->deleteImportFile = static function () use ($fileSystem, $remoteFilePath) {
                                $fileSystem->delete($remoteFilePath);
                            };

                            $this->archiveFilename = basename($remoteFilePath);
                        }
                    } catch (\Exception $e) {
                        if ($fileSystem->fileExists($remoteFilePath)) {
                            throw $e;
                        }
                        $this->sourceFilePath = null;
                    }
                }

                if ($this->sourceFilePath !== null) {
                    $this->foundImportResource = true;
                } elseif (!$this->foundImportResource) {
                    $this->logger->info('Could not find an import resource for '.$this->source);
                }

                return $this->sourceFilePath;
            }

            if (@\is_dir($this->source)) {
                $directoryIterator = new \RecursiveDirectoryIterator($this->source, \RecursiveDirectoryIterator::SKIP_DOTS);

                /**
                 * @param \SplFileInfo $file
                 * @param mixed $key
                 * @param \RecursiveCallbackFilterIterator $iterator
                 *
                 * @return bool True if you need to recurse or if the item is acceptable
                 */
                $filter = function ($file, $key, $iterator) {
                    if ($iterator->hasChildren() && strpos(realpath($file->getPathname()), realpath($this->source).'/archive') === false && (!$this->config['archiveFolder'] || strpos(realpath($file->getPathname()), $this->config['archiveFolder']) === false)) {
                        return true;
                    }

                    return $file->isFile();
                };
                $fileIterator = new SplFileInfoSortedFileIterator(
                    new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator($directoryIterator, $filter))
                );

                if($filterForFile !== null) {
                    $fileIterator = new \CallbackFilterIterator($fileIterator, static function(\SplFileInfo $current) use ($filterForFile) {
                        $currentPath = $current->getRealPath();
                        if(!$currentPath) {
                            $currentPath = $current->getPathname();
                        }
                        return strpos($currentPath, $filterForFile->getPathname()) !== false;
                    });
                } else {
                    // prevent import of partly uploaded files
                    $fileIterator = new \CallbackFilterIterator($fileIterator, static function(\SplFileInfo $current) {
                        return (preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-/', $current->getFilename()) || time() - $current->getMTime() > 2) && !$current->isLink();
                    });
                }

                $this->sourceFilePath = $this->getFileFromIterator($fileIterator);

                $this->sourceFilePath = $this->extractFile($this->sourceFilePath);

                if ($this->sourceFilePath !== null) {
                    $this->foundImportResource = true;

                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->sourceFilePath);
                    }
                } elseif (!$this->foundImportResource) {
                    $this->logger->info('Could not find an import resource for '.$this->source);
                }

                return $this->sourceFilePath;
            }

            try {
                $fileIterator = new \GlobIterator($this->source);

                $fileIterator = new \CallbackFilterIterator(
                    $fileIterator, static function (\SplFileInfo $current) {
                        return $current->getExtension() !== 'php' && $current->isFile() && !$current->isLink();
                    }
                );

                if ($filterForFile !== null) {
                    $fileIterator = new \CallbackFilterIterator($fileIterator, static function (\SplFileInfo $current) use ($filterForFile) {
                        return strpos($current->getRealPath(), $filterForFile->getPathname()) !== false;
                    });
                } elseif (empty($this->config['autoImport'])) {
                    // prevent import of partly uploaded files
                    $fileIterator = new \CallbackFilterIterator(
                        $fileIterator, static function (\SplFileInfo $current) {
                        return strpos($current->getPathname(), \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_') === 0 || preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-/', $current->getFilename()) || time() - $current->getMTime() > 2;
                    });
                }

                $this->sourceFilePath = $this->getFileFromIterator($fileIterator);
                if ($this->sourceFilePath !== null) {
                    $this->sourceFilePath = $this->extractFile($this->sourceFilePath);
                    $this->foundImportResource = true;
                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->sourceFilePath);
                    }
                    return $this->sourceFilePath;
                }
            } catch(\Throwable $e) {
            }


            if(!defined('OPENDXP_ASSET_DIRECTORY') && $asset instanceof Asset && !file_exists($this->source)) {
                // Pimcore >= 10
                try {
                    $fileSystem = Helper::getAssetStorage();
                    $flysystemListing = $fileSystem->listContents($asset->getRealFullPath(), true);
                    if(empty($this->config['autoImport'])) {
                        $flysystemListing = $flysystemListing->filter(
                            function (StorageAttributes $attributes) {
                                return time() - $attributes->lastModified() > 2;
                            }
                        );
                    }
                    if ($filterForFile !== null) {
                        $flysystemListing = $flysystemListing->filter(
                            function (StorageAttributes $attributes) use ($filterForFile) {
                                return strpos($attributes->path(), $filterForFile->getPathname()) !== false;
                            }
                        );
                    }

                    $remoteFilePath = $this->getFileFromIterator($flysystemListing->getIterator());

                    if ($remoteFilePath === null && ($filterForFile === null || $filterForFile->getPathname() === $asset->getRealFullPath())) {
                        $remoteFilePath = $this->getFileIfNotLocked($asset->getRealFullPath()); // fetch single file (above Iterator only lists directory contents)
                    }

                    if ($remoteFilePath) {
                        $remoteFilePath = Helper::getTemporaryAssetFile($remoteFilePath);
                        $this->sourceFilePath = $this->extractFile($remoteFilePath);

                        if ($remoteFilePath === $this->sourceFilePath) {
                            $this->deleteImportFile = static function () use ($fileSystem, $remoteFilePath) {
                                $fileSystem->delete($remoteFilePath);
                            };

                            $this->archiveFilename = basename($remoteFilePath);
                        }

                        $this->foundImportResource = true;
                        return $this->sourceFilePath;
                    }

                    if ($this->sourceFilePath !== null) {
                        $this->sourceFilePath = $this->extractFile($this->sourceFilePath);
                        $this->foundImportResource = true;

                        if ($this instanceof Parser && $this->getLimit() === null) {
                            $this->logger->info('Importing '.$remoteFilePath);
                        }
                        return $this->sourceFilePath;
                    }
                } catch(FilesystemException $e) {
                }
            }

            // Pimcore assets with glob expression
            try {
                if (defined('OPENDXP_ASSET_DIRECTORY')) {
                    $fileIterator = new \GlobIterator(OPENDXP_ASSET_DIRECTORY.$this->source);
                } else {
                    $fileIterator = new \GlobIterator(\OPENDXP_WEB_ROOT.'/var/assets'.$this->source);
                }

                $fileIterator = new \CallbackFilterIterator(
                    $fileIterator,
                    static function (\SplFileInfo $current) {
                        return $current->getExtension() !== 'php' && $current->isFile() && !$current->isLink();
                    }
                );

                if(empty($this->config['autoImport'])) {
                    // prevent import of partly uploaded files
                    $fileIterator = new \CallbackFilterIterator(
                        $fileIterator,
                        static function (\SplFileInfo $current) {
                            return preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-/', $current->getFilename()) // archive files of another dataport, @see self::archive()
                                || time() - $current->getMTime() > 2;
                        }
                    );
                }

                if ($filterForFile !== null) {
                    $fileIterator = new \CallbackFilterIterator(
                        $fileIterator,
                        function (\SplFileInfo $current) use ($filterForFile) {
                            $filterPath = $filterForFile->getPathname();
                            if (!defined('OPENDXP_ASSET_DIRECTORY')) {
                                $filterPath = \OPENDXP_WEB_ROOT.'/var/assets'.$filterForFile->getPathname();
                            }

                            return strpos($current->getRealPath(), $filterPath) !== false;
                        }
                    );
                }
                $this->sourceFilePath = $this->getFileFromIterator($fileIterator);
                if ($this->sourceFilePath !== null) {
                    $this->sourceFilePath = $this->extractFile($this->sourceFilePath);

                    $this->foundImportResource = true;
                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->sourceFilePath);
                    }
                    return $this->sourceFilePath;
                }
            } catch(UnexpectedValueException $e) {
            }

            if ($filterForFile !== null) {
                $this->sourceFilePath = null;
                return null;
            }

            if (strpos($this->source, '/') === 0 && !\file_exists($this->source) && !\file_exists(dirname($this->source)) && !Asset::getByPath(dirname($this->source)) instanceof Asset\Folder && $this->getFileIfNotLocked($this->source)) {
                // relative HTTP route
                try {
                    $mainDomain = Helper::getHostUrl();
                    if ($mainDomain) {
                        $readHandle = @fopen($mainDomain.$this->source, 'rb');

                        if ($readHandle !== false) {
                            $originalSource = $this->source;
                            $filename = Helper::getTemporaryFileFromStream($readHandle);
                            @fclose($readHandle);

                            $file = new \SplFileObject($filename, 'r');
                            $file->seek(PHP_INT_MAX);

                            $lineCount = $file->key();
                            if($lineCount == 1) {
                                $fileContent = file_get_contents($file->getPathname());

                                if(filter_var($fileContent, FILTER_VALIDATE_URL) || file_exists($fileContent)) {
                                    if ($this instanceof Parser && $this->getLimit() === null) {
                                        $this->logger->info('Importing '.$fileContent.' (via '.$mainDomain.$originalSource.')');
                                    }

                                    $this->source = $fileContent;
                                    $this->sourceFilePath = $this->getFileOrUrl();
                                    return $this->sourceFilePath;
                                }
                            }

                            $this->sourceFilePath = $this->extractFile($filename);
                            if ($this instanceof Parser && $this->getLimit() === null) {
                                $this->logger->info('Importing '.$mainDomain.$originalSource.' (as '.$filename.')');
                            }

                            return $this->sourceFilePath;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }

            if (strpos($this->source, 'curl ') !== false) {
                if($this->foundImportResource) {
                    $this->sourceFilePath = null;
                    return null;
                }
                if($this->getFileIfNotLocked($this->source) === null) {
                    $this->sourceFilePath = null;
                    return null;
                }
                $filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.md5($this->source);

                preg_match_all('/(?<!\\\)\n+/', $this->source, $lineBreaks, PREG_OFFSET_CAPTURE);
                $startPosition = 0;
                $command = '';
                $insideQuote = false;
                foreach ($lineBreaks[0] ?? [] as $lineBreak) {
                    $commandFragment = substr($this->source, $startPosition, $lineBreak[1] - $startPosition);

                    if((substr_count($commandFragment, '\'') % 2 === 0 && !$insideQuote) || (substr_count($commandFragment, '\'') % 2 === 1 && $insideQuote)) {
                        $command .= $commandFragment.' && ';
                        $insideQuote = false;
                    } else {
                        $command .= $commandFragment;
                        $insideQuote = true;
                    }
                    $startPosition = $lineBreak[1];
                }
                $command .= substr($this->source, $startPosition);
                $command = preg_replace('/(?<!\\\\)\R/', '\\n', $command);
                $command = str_replace('curl ', 'curl -s ', $command);
                $command = preg_replace('/ -v(\s|$)/m', ' ', $command);
                $command = preg_replace('/ --verbose(\s|$)/m', ' ', $command);
                Cli::exec($command, $filename);

                if (\file_exists($filename)) {
                    $this->sourceFilePath = $this->extractFile($filename);

                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->source);
                    }

                    $this->foundImportResource = true;
                    return $this->sourceFilePath;
                }
            }

            if(strpos($this->source, '<?php') !== false) {
                $filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.md5($this->source).'.php';
                file_put_contents($filename, $this->source);
                $this->source = $filename;
            }

            if (Helper::getFileExtension($this->source) === 'php' && $this->getFileIfNotLocked($this->source)) {
                // call to PHP script
                $arguments = \array_filter(explode(' ', $this->source));
                $script = \array_shift($arguments);
                $config = $this->config;
                $sourceResult = \call_user_func(
                    static function () use ($script, $arguments, $config) {
                        if(!file_exists($script)) {
                            $script = OPENDXP_PROJECT_ROOT.'/'.$script;
                        }
                        if(!\file_exists($script)) {
                            return null;
                        }
                        ob_start();
                        $return = include $script;

                        if($return === 1) {
                            return ob_get_clean();
                        }

                        return $return;
                    }
                );

                if ($sourceResult && strpos($sourceResult, "\n") === false) {
                    if (filter_var($sourceResult, FILTER_VALIDATE_URL) || file_exists($sourceResult) || Asset::getByPath($sourceResult) instanceof Asset) {
                        if ($this instanceof Parser && $this->getLimit() === null) {
                            $this->logger->info('Importing '.$sourceResult.' (via '.$this->source.')');
                        }

                        $this->source = $sourceResult;
                        $this->sourceFilePath = $this->getFileOrUrl();
                        return $this->sourceFilePath;
                    }
                }

                if($sourceResult) {
                    $filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.md5($sourceResult);
                    file_put_contents($filename, $sourceResult);
                    $sourceResult = $filename;
                }

                if (\file_exists($sourceResult)) {
                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->source);
                    }
                    $this->sourceFilePath = $this->extractFile($sourceResult);
                    $this->foundImportResource = true;
                    return $this->sourceFilePath;
                }
            }

            if (!$this->foundImportResource && filter_var($this->source, FILTER_VALIDATE_URL) && $this->getFileIfNotLocked($this->source)) {
                $localFilePath = Helper::getTemporaryFileFromFileOrUrl($this->source, $this->getArchiveFileExtension());
                $this->sourceFilePath = $this->extractFile($localFilePath);

                if ($this instanceof Parser && $this->getLimit() === null) {
                    $this->logger->info('Importing '.$this->source);
                }

                $this->foundImportResource = true;
                if ($this instanceof Parser && $this->getLimit() === null) {
                    $this->logger->info('Importing '.$this->source);
                }
                return $this->sourceFilePath;
            }

            if (\file_exists($this->source)) {
                if(!is_link($this->source)) {
                    $this->sourceFilePath = $this->extractFile($this->getFileIfNotLocked($this->source));
                    $this->foundImportResource = true;

                    if ($this instanceof Parser && $this->getLimit() === null) {
                        $this->logger->info('Importing '.$this->source);
                    }
                }

                return $this->sourceFilePath;
            }

            if($this->getFileIfNotLocked($this->source)) {
                $filename = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.md5($this->source);
                file_put_contents($filename, $this->source);
                if ($this instanceof Parser && $this->getLimit() === null) {
                    $this->logger->info('Importing '.$this->source);
                }
                $this->sourceFilePath = $this->extractFile($filename);

                $this->foundImportResource = true;
            }

            if(!$this->foundImportResource) {
                $this->logger->info('Could not find an import resource for '.$this->source);
            }
        }

        return $this->sourceFilePath;
    }

    /**
     * @param Filesystem $fileSystem
     * @param string $prefixPath static prefix path without wildcards (= base folder)
     * @param string $pathWithWildcards dynamic path suffix including wildcards
     * @return mixed
     */
    private function getDirectoryIterator(Filesystem $fileSystem, $prefixPath, $pathWithWildcards) {
        return $fileSystem->listContents($prefixPath, FilesystemReader::LIST_DEEP)
            ->filter(static function (StorageAttributes $attributes) use ($pathWithWildcards, $prefixPath) {
                return $attributes->isFile()
                    && (
                        !$pathWithWildcards
                        || preg_match('#^'.preg_quote(ltrim($prefixPath, '/'), '#').'/'.str_replace(['**', '*'], ['.+', '[^/]+'], $pathWithWildcards).'$#', $attributes->path())
                    );
            });
    }

    private function archive() {
        $filePath = $this->sourceFilePath;
        if(!$filePath) {
            return;
        }

        $importer = $this->getImporter();
        if (!empty($this->config['dataportId'])) {
            $importer->setDataport(Dataport::getInstance()->get($this->config['dataportId']));
        }

        Helper::useInheritance(true);

        $this->config['archiveFolder'] = $importer->replaceObjectIdentifier($this->config['archiveFolder'], $this->config['parameters'] ?? null);

        if(!empty($this->config['archiveFolder'])) {
            $stream = @fopen($filePath, 'rb');

            if($this->archiveFilename !== null) {
                $shortFilePath = $this->archiveFilename;
            } else {
                $shortFilePath = substr(basename($filePath), -150).'.'.Helper::getFileExtension($filePath);
                if (!$shortFilePath) {
                    $shortFilePath = $filePath;
                }
            }

            if(\is_resource($stream)) {
                if (strpos($this->config['archiveFolder'], 'sftp://') === 0 || strpos($this->config['archiveFolder'], 'ftp://') === 0 || strpos($this->config['archiveFolder'], 'ftps://') === 0) {
                    $urlParts = parse_url($this->config['archiveFolder']);
                    if ($urlParts === false && preg_match('/^(s?ftps?):\/\/(.+):(.+)@/', $this->config['archiveFolder'], $match)) {
                        $source = str_replace($match[0], $match[1].'://', $this->config['archiveFolder']);
                        $urlParts = parse_url($source);
                        $urlParts['user'] = $match[2];
                        $urlParts['pass'] = $match[3];
                    }

                    $fileName = File::getValidFilename(date('Y-m-d-H-i-s').'-'.$shortFilePath);
                    if (strpos($this->config['archiveFolder'], 'sftp://') === 0) {
                        $fileSystem = new \League\Flysystem\Filesystem(
                            new SftpAdapter(
                                new SftpConnectionProvider(
                                    $urlParts['host'],
                                    $urlParts['user'],
                                    $urlParts['pass'],
                                    null,
                                    null,
                                    $urlParts['port'] ?? 22
                                ),
                                '/'
                            )
                        );
                        $fileSystem->writeStream($urlParts['path'].'/'.$fileName, $stream);
                    } elseif (strpos($this->config['archiveFolder'], 'ftp://') === 0) {
                        $fileSystem = new \League\Flysystem\Filesystem(
                            new \League\Flysystem\Ftp\FtpAdapter(
                                \League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
                                    'host' => $urlParts['host'],
                                    'username' => $urlParts['user'],
                                    'password' => $urlParts['pass'],
                                    'port' => $urlParts['port'] ?? 21,
                                    'root' => dirname($urlParts['path']),
                                    'ssl' => false,
                                    'ignorePassiveAddress' => true
                                ])
                            )
                        );
                        $fileSystem->writeStream($fileName, $stream);
                    } elseif (strpos($this->config['archiveFolder'], 'ftps://') === 0) {
                        $fileSystem = new \League\Flysystem\Filesystem(
                            new \League\Flysystem\Ftp\FtpAdapter(
                                \League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
                                    'host' => $urlParts['host'],
                                    'username' => $urlParts['user'],
                                    'password' => $urlParts['pass'],
                                    'port' => $urlParts['port'] ?? 21,
                                    'root' => dirname($urlParts['path']),
                                    'ssl' => true,
                                    'ignorePassiveAddress' => true
                                ])
                            )
                        );
                        $fileSystem->writeStream($fileName, $stream);
                    }
                } elseif (strpos($this->config['archiveFolder'], 's3://') === 0) {
                    $urlParts = parse_url($this->config['archiveFolder']);
                    if ($urlParts === false && preg_match('/^s3:\/\/(.+):(.+)@/', $this->config['archiveFolder'], $match)) {
                        $source = str_replace($match[0], $match[1].'://', $this->config['archiveFolder']);
                        $urlParts = parse_url($source);
                        $urlParts['user'] = $match[2];
                        $urlParts['pass'] = $match[3];
                    }

                    $pathParts = array_filter(explode('/', $urlParts['path']));
                    $bucket = array_shift($pathParts);
                    $urlParts['path'] = implode('/', $pathParts);

                    $fileName = File::getValidFilename(date('Y-m-d-H-i-s').'-'.$shortFilePath);
                    $fileSystem = new \League\Flysystem\Filesystem(
                        new AwsS3V3Adapter(new S3Client([
                            'region' => $urlParts['host'],
                            'credentials' => [
                                'key' => $urlParts['user'],
                                'secret' => $urlParts['pass'],
                            ]
                        ]), $bucket)
                    );
                    $fileSystem->writeStream($urlParts['path'].'/'.$fileName, $stream);
                } elseif(@file_exists($this->config['archiveFolder']) && is_writable($this->config['archiveFolder']) && $this->config['archiveFolder'] !== '/') {
                    $fileName = File::getValidFilename(date('Y-m-d-H-i-s').'-'.$shortFilePath);
                    \file_put_contents($this->config['archiveFolder'].'/'.$fileName, $stream);
                } else {
                    $archiveFolderPath = $this->config['archiveFolder'];
                    if($this->getStatusKey()) {
                        $dataportResourceParameters = DataportResource::getInstance()->findOneInSql('SELECT resource FROM '.Installer::TABLE_DATAPORT_RESOURCE.' resource INNER JOIN '.Installer::TABLE_IMPORTSTATUS.' status ON resource.id=status.dataport_resource_id WHERE status.key=?', [$this->getStatusKey()]);
                        if($dataportResourceParameters) {
                            $dataportResourceParameters = json_decode($dataportResourceParameters, true)['parameters'] ?? [];
                            $dataportResourceParameters = array_filter($dataportResourceParameters, function($parameterValue) {
                                return is_scalar($parameterValue) && $parameterValue;
                            });
                            ksort($dataportResourceParameters);
                            $dataportResourceParameters = array_map(function($parameterValue, $parameterName) {
                                return $parameterName.'='.$parameterValue;
                            }, $dataportResourceParameters, array_keys($dataportResourceParameters));

                            if($dataportResourceParameters) {
                                $archiveFolderPath .= '/'.implode('/', $dataportResourceParameters);
                            }
                        }
                    }
                    $archiveFolderPath .= '/'.date('Y/m/d');
                    $archiveFolder = Asset\Folder::getByPath($archiveFolderPath);
                    if (!$archiveFolder instanceof Asset\Folder) {
                        try {
                            $archiveFolder = Asset\Service::createFolderByPath($archiveFolderPath);
                        } catch(OpenDxp\Model\Element\DuplicateFullPathException $e) {
                            $archiveFolder = Asset\Service::createFolderByPath($archiveFolderPath);
                        }
                    }
                    for($i=0, $iMax = strlen($shortFilePath);$i < $iMax;$i++) {
                        $fileName = Service::getValidKey(date('H-i-s').'-'.substr($shortFilePath, $i), 'asset');
                        try {
                            if(method_exists($this->logger, 'disablePimcoreLogger')) {
                                $this->logger->disablePimcoreLogger();
                            }
                            $asset = Asset::create($archiveFolder->getId(), ['filename' => $fileName, 'stream' => $stream], false);
                            if (method_exists($this->logger, 'enablePimcorelogger')) {
                                $this->logger->enablePimcorelogger();
                            }

                            if($this->getStatusKey()) {
                                $asset->setProperty('statusKey', 'text', $this->getStatusKey());
                            }

                            if (method_exists($this->logger, 'disablePimcoreLogger')) {
                                $this->logger->disablePimcoreLogger();
                            }
                            $asset->save();
                            if (method_exists($this->logger, 'enablePimcoreLogger')) {
                                $this->logger->enablePimcoreLogger();
                            }
                            @fclose($asset->getStream()); // bypass https://github.com/pimcore/pimcore/pull/11039
                            break;
                        } catch(\Exception $e) {
                        }
                    }
                }

                if(is_resource($stream)) {
                    @fclose($stream);
                }
            }
        } elseif (@\is_dir($this->source) && strpos($this->source, 'ftp://') !== 0 && strpos($this->source, 'ftps://') !== 0 && strpos($this->source, 'sftp://') !== 0) {
            $source = realpath($this->source);
            if (!$source) {
                $source = $this->source;
            }
            $file = new \SplFileInfo($filePath);
            if ($file->isFile()) {
                $archiveDirectory = $source.'/archive/'.\preg_replace('~^'.$source.'~', '', realpath($file->getPath()));
                if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0755, true) && !is_dir($archiveDirectory)) {
                    throw new \InvalidArgumentException('Could not create directory "'.$archiveDirectory.'"');
                }

                \copy($filePath, $archiveDirectory.$file->getFilename());
            }
        }

        if($this->removeFileAfterImport) {
            if(is_callable($this->deleteImportFile)) {
                call_user_func($this->deleteImportFile);
            } else {
                if (defined('OPENDXP_ASSET_DIRECTORY')) {
                    $asset = Asset::getByPath(\str_replace(OPENDXP_ASSET_DIRECTORY, '', $filePath));
                } else {
                    $asset = Asset::getByPath(\str_replace(\OPENDXP_WEB_ROOT.'/var/assets', '', $filePath));
                }

                if ($asset instanceof Asset) {
                    $asset->delete();
                } elseif (\is_file($filePath)) {
                    @unlink($filePath);
                }
            }
        }
    }

    public function setSourceFile($file) {
        if($file) {
            $this->source = $file;
            $this->sourceFilePath = null;

            $this->config['file'] = $file;
        }
    }

    /**
     * @param bool $removeFileAfterImport
     */
    public function removeFileAfterImport($removeFileAfterImport = true)
    {
        if(!$removeFileAfterImport && !empty($this->config['archiveFolder']) && !$this->force && (Helper::getRequest()->get('rm', null) === null || Helper::getRequest()->get('rm')) && (Helper::getRequest()->get('clear-file-after-import', null) === null || Helper::getRequest()->get('clear-file-after-import'))) {
            $removeFileAfterImport = true;
        }
        $this->removeFileAfterImport = (bool)$removeFileAfterImport;
    }

    /**
     * @param bool $force
     */
    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    public function getFileConditionFromObject(ElementInterface $object) {
        if($object instanceof Asset) {
            $fileSystemPath = $object->getRealFullPath();
            if (defined('OPENDXP_ASSET_DIRECTORY')) {
                $fileSystemPath = realpath(\OPENDXP_ASSET_DIRECTORY.$fileSystemPath);
                if(!$fileSystemPath) {
                    $fileSystemPath = \OPENDXP_ASSET_DIRECTORY.$object->getRealFullPath();
                }
            }
            $file = $this->getFileOrUrl(new \SplFileInfo($fileSystemPath));

            if($file !== null) {
                return $object->getRealFullPath();
            }
        } elseif($object instanceof Concrete) {
            $itemMold = OpenDxp::getContainer()->get(ItemMoldBuilder::class)->getItemMold($this->config['dataportId']);
            if($object instanceof $itemMold) {
                /** @var Importer $importer */
                $importer = OpenDxp::getContainer()->get(ImporterInterface::class);

                $sourceContainsContextSensitivePlaceholders = false;

                $resolvedImportSource = \preg_replace('/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $this->source ?? '');

                $replacedImportSource = $importer->replaceObjectIdentifier($resolvedImportSource, $object);
                if ($replacedImportSource !== $resolvedImportSource) {
                    $resolvedImportSource = $replacedImportSource;
                    $sourceContainsContextSensitivePlaceholders = true;
                }

                if($sourceContainsContextSensitivePlaceholders) {
                    return $resolvedImportSource;
                }
            }
        }

        throw new IncompatibleTypeException('This object type can currently not be handled');
    }

    /**
     * @param \Iterator $iterator
     * @param Filesystem|null $filesystem
     * @return string|null
     */
    private function getFileFromIterator(iterable $iterator)
    {
        foreach($iterator as $file) {
            if ($file instanceof StorageAttributes) {
                if ($this->getFileIfNotLocked($file->path()) !== null) {
                    return $file->path();
                }
                continue;
            }

            /** @var SplFileInfo $file */
            $filePath = $file->getRealPath();
            if(!$filePath) {
                $filePath = $file->getPathName();
            }
            if($this->getFileIfNotLocked($filePath) !== null) {
                return $filePath;
            }
        }
        return null;
    }

    public function getFileIfNotLocked($file) {
        $lockKey = md5($file).'-import-'.($this->config['dataportId'] ?? 'unknown').'-'.$file;
        $lockKey = substr($lockKey, 0, 150); // Pimcore 6 compatibility: locks.id is varchar(150) but does also work in later Pimcore versions because md5() is built over complete key

        if($this instanceof Parser) {
            if (!$this->lock($lockKey)) {
                $lockValid = true;
                if(!empty($this->config['dataportId']) && $this->getStatusKey() && !in_array($lockKey, $this->lockKeys, true)) {
                    $currentlyRunningRawdataImports = ImportStatus::getInstance()->findOne(
                        [
                            'dataport_id = ?' => $this->config['dataportId'],
                            '`key` != ?' => $this->getStatusKey(),
                            'status = ?' => ImportStatus::STATUS_RUNNING,
                            'importType & ?' => ImportStatus::TYPE_RAWDATA
                        ]
                    );

                    if (!$currentlyRunningRawdataImports) {
                        $lockValid = false;
                    }
                }

                if($lockValid) {
                    return null;
                }

                $this->logger->info('Releasing lock as there are no dataport runs using this file');
            }

            $this->lockKey = $lockKey;
            $this->lockKeys[] = $lockKey;
        }

        return $file;
    }

    public function getStream() {
        if(is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $filePathOrUrl = $this->getFileOrUrl();
        $this->stream = null;
        if(!$filePathOrUrl) {
            return null;
        }

        $this->stream = @fopen($filePathOrUrl, 'rb');
        if(!\is_resource($this->stream)) {
            throw new \Exception('Cannot read "'.$filePathOrUrl.'"');
        }

        if(!@rewind($this->stream)) {
            $tmpFile = OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $this->config['dataportId'] . '_' . uniqid();
            $originalHandle = $this->stream;
            $this->stream = fopen($tmpFile, 'wb+', false);
            stream_copy_to_stream($originalHandle, $this->stream);
            rewind($this->stream);
            @fclose($originalHandle);
        }

        $bom = fread($this->stream, 3);

        // Remove BOM
        $charset = null;
        if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
            $charset = 'UTF-8';
        } elseif (substr($bom, 0, 2) === "\xFF\xFE") {
            $charset = 'UTF-16LE';
        } elseif(substr($bom, 0, 2) === "\xFE\xFF") {
            $charset = 'UTF-16BE';
        }

        @rewind($this->stream);

        $streamFilter = 'convert.iconv.'.$charset.'/UTF-8';
        if($charset && self::streamFilterExists($streamFilter)) {
            stream_filter_append($this->stream, $streamFilter);
        }

        if($this instanceof JsonParser) {
            if(!in_array('string.strip_newline', stream_get_filters(), true)) {
                stream_filter_register('string.strip_newline', NewLineFilter::class);
            }
            stream_filter_append($this->stream, 'string.strip_newline');
        }

        if(!in_array('string.strip_bom', stream_get_filters(), true)) {
            stream_filter_register('string.strip_bom', StripByteOrderMarkFilter::class);
        }

        stream_filter_append($this->stream, 'string.strip_bom');

        return $this->stream;
    }

    private static function streamFilterExists($filterName)
    {
        $existingFilters = stream_get_filters();
        foreach ($existingFilters as $existingFilter) {
            if (preg_match('/^'.str_replace('*', '.*', $existingFilter).'/', $filterName)) {
                return true;
            }
        }
        return false;
    }

    public function getLastModified() {
        $filePathOrUrl = $this->getFileOrUrl();

        if (!isset(self::$cacheLastModifiedFiles[$filePathOrUrl])) {
            self::$cacheLastModifiedFiles[$filePathOrUrl] = $this->getCachedLastModified($filePathOrUrl);
        }

        return self::$cacheLastModifiedFiles[$filePathOrUrl];
    }

    /**
     * @param string $filePathOrUrl
     * @return int
     * @throws Exception
     */
    private function getCachedLastModified($filePathOrUrl) {
        if (filter_var($filePathOrUrl, FILTER_VALIDATE_URL) !== false && in_arrayi(parse_url($filePathOrUrl, PHP_URL_SCHEME), ['http', 'https'])) {
            $sourceFileHeaders = get_headers($filePathOrUrl, 1);

            foreach ($sourceFileHeaders as $headerName => $headerValue) {
                if (strtolower(trim($headerName)) === 'last-modified') {
                    return (new \DateTime($headerValue))->getTimestamp();
                }
            }
        } else {
            return \filemtime($filePathOrUrl);
        }

        return time();
    }

    private function extractFile($filePath) {
        if(!is_string($filePath)) {
            return null;
        }

        try {
            if(class_exists(MimeTypes::class)) {
                $mimeTypes = new MimeTypes();
                $mimeType = $mimeTypes->guessMimeType($filePath);
            } else {
                $mimeType = Mime::detect($filePath);
            }

            if($mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'){
                return $filePath;
            }
        } catch(Exception $e) {
            return $filePath;
        }

        $zip = new \ZipArchive();
        if(filesize($filePath) > 0 && $zip->open($filePath) === true) {
            $extractDirImportResource = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_'.$this->config['dataportId'].'_'.File::getValidFilename($filePath);

            $extractOnlyAssets = true;
            if(!\is_dir($extractDirImportResource)) {
                $extractOnlyAssets = false;
            }

            if(!$extractOnlyAssets) {
                if(!mkdir($extractDirImportResource) && !is_dir($extractDirImportResource)) {
                    throw new \RuntimeException(sprintf('Directory "%s" could not be not created', $extractDirImportResource));
                }

                register_shutdown_function(
                    static function () use ($extractDirImportResource) {
                        $it = new RecursiveDirectoryIterator($extractDirImportResource, RecursiveDirectoryIterator::SKIP_DOTS);
                        $files = new RecursiveIteratorIterator(
                            $it,
                            RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($files as $file) {
                            if ($file->isDir()) {
                                rmdir($file->getRealPath());
                            } else {
                                unlink($file->getRealPath());
                            }
                        }
                        rmdir($extractDirImportResource);
                    }
                );
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileName = $zip->getNameIndex($i);

                if (strpos($fileName, '__MACOSX') === 0) {
                    continue;
                }

                $fileExtension = Helper::getFileExtension($fileName);
                if (($this instanceof CsvParser && $fileExtension === 'csv') ||
                    ($this instanceof JsonParser && $fileExtension === 'json') ||
                    ($this instanceof XmlParser && $fileExtension === 'xml') ||
                    ($this instanceof StreamingXmlParser && $fileExtension === 'xml') ||
                    ($this instanceof ExcelParser && in_array($fileExtension, ['xlsx', 'xls'])) ||
                    ($this instanceof NaiveParser && in_array($fileExtension, ['csv','json', 'xml', 'xlsx', 'xls']))) {
                    if($extractOnlyAssets) {
                        continue;
                    }
                    $extractTo = $extractDirImportResource;
                } else {
                    $extractTo = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $this->config['dataportId'] . '_zipAsset';
                }

                $extractDir = pathinfo($extractTo, PATHINFO_DIRNAME);
                if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $extractDir));
                }

                $zip->extractTo($extractTo, $fileName);
            }

            $zip->close();

            $directoryIterator =
                new \RecursiveDirectoryIterator($extractDirImportResource, \RecursiveDirectoryIterator::SKIP_DOTS);

            /**
             * @param \SplFileInfo $file
             * @param mixed $key
             * @param \RecursiveCallbackFilterIterator $iterator
             *
             * @return bool True if you need to recurse or if the item is acceptable
             */
            $filter = static function ($file, $key, $iterator) {
                if ($iterator->hasChildren()) {
                    return true;
                }

                return $file->isFile();
            };
            $fileIterator = new SplFileInfoSortedFileIterator(
                new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator($directoryIterator, $filter))
            );

            $filePathFromIterator = $this->getFileFromIterator($fileIterator);
            if ($filePathFromIterator !== null) {
                return $filePathFromIterator;
            }
        }

        return $filePath;
    }

    public function getStatusKey()
    {
        return $this->statusKey;
    }

    public function setStatusKey(string $statusKey): void
    {
        $this->statusKey = $statusKey;
    }

    public function getResource() {
        return $this->source;
    }

    /**
     * @return ImporterInterface
     */
    protected function getImporter()
    {
        if ($this->importer === null) {
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
    public function getConfig()
    {
        return $this->config;
    }

    public function disableLoggingNotFoundImportResource() {
        $this->foundImportResource = true;
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

    protected function getArchiveFileExtension() {
        return 'txt';
    }
}
