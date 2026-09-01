<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\EventListener\IncompatibleTypeException;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\LockableTrait;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\FlysystemSortedFileIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\SplFileInfoSortedFileIterator;
use Sylphen\DataBridgeBundle\lib\Pim\TemporaryFileHelperTrait;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\StorageAttributes;
use LimitIterator;
use OpenDxp\File;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Element\AbstractElement;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Tool\Lock;
use Psr\Log\LoggerInterface;
use SplFileInfo;

class FilesystemParser implements Parser {
    use IteratableParser;
    use LockableTrait;
    use TemporaryFileHelperTrait;

    /** @var array */
    private $config;

    private $removeFileAfterImport = false;

    /** @var string */
    private $source;

    /** @var string */
    private $sourceFilePath;

    /** @var \GlobIterator|SplFileInfoSortedFileIterator */
    private $fileIterator;

    private $fileSystem;

    /** @var int */
    private $fileIteratorCount = 0;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(array $config)
    {
        $this->config = $config;
        if (empty($this->config['fields'])) {
            throw new \Exception('Please configure raw data fields');
        }

        $this->setSourceFile($this->config['file']);
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->fileIteratorCount;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        start:
        $fileInfo = $this->getImportFile();

        if ($this->position < $this->offset) {
            $this->next();
            if ($this->valid()) {
                goto start;
            } else {
                return null;
            }
        }

        if($fileInfo === null) {
            $this->current = null;
            return null;
        }

        foreach ($this->config['fields'] as $field => $values) {
            if(in_arrayi($values['cmd'], ['$filename', '${filename}', '$file', '${file}', ''])) {
                if ($this->removeFileAfterImport) {
                    $tempFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$this->config['dataportId'].'_'.$fileInfo->getBasename('.'.$fileInfo->getExtension()).uniqid().'.'.$fileInfo->getExtension();
                    copy($fileInfo->getPathname(), $tempFileName);
                    $this->current[$field] = $tempFileName;
                } else {
                    $this->current[$field] = $fileInfo->getPathname();
                }
            } elseif ($values['cmd'] === '__index') {
                $this->current[$field] = $this->position;
            } elseif ($values['cmd'] === '__count') {
                $this->current[$field] = $this->count();
            } elseif(!empty($values['cmd'])) {
                $this->current[$field] = trim(shell_exec(str_replace(['$filename', '${filename}', '$file', '${file}'], "'".$fileInfo."'", $values['cmd'])));
            }
        }

        $this->current['__updated'] = $fileInfo->getMTime();

        if($this->config['archiveFolder']) {
            $archiveFolder = Asset\Folder::getByPath($this->config['archiveFolder']);
            if ($archiveFolder instanceof Asset\Folder) {
                $filename = Service::getValidKey($fileInfo->getFilename(), 'asset');
                $asset = Asset::getByPath($archiveFolder->getFullPath().'/'.$filename);
                if($asset instanceof Asset) {
                    $asset->delete();
                }

                Asset::create($archiveFolder->getId(), ['filename' => $filename, 'sourcePath' => $fileInfo->getPathname()]);
            } else {
                copy($fileInfo->getPathname(), $this->config['archiveFolder'].'/'.$fileInfo->getFilename());
            }
        }

        if($this->removeFileAfterImport) {
            if(defined('OPENDXP_ASSET_DIRECTORY')) {
                $asset = Asset::getByPath(\str_replace(OPENDXP_ASSET_DIRECTORY, '', $fileInfo->getPathname()));
            } else {
                $asset = Asset::getByPath(\str_replace(\OPENDXP_WEB_ROOT.'/var/assets', '', $fileInfo->getPathname()));
            }

            if($asset instanceof Asset) {
                $asset->delete();
            } else {
                unlink($fileInfo->getPathname());
            }

            if(!\file_exists($fileInfo->getPathname())) {
                $this->release('import-'.$this->config['dataportId'].'-'.$fileInfo->getPathname());
            }
        } else {
            $this->release('import-'.$this->config['dataportId'].'-'.$fileInfo->getPathname());
        }

        $this->fileIterator->next();

        return $this->current;
    }

    public function setSourceFile($file) {
        if($file) {
            $asset = Asset::getByPath($file);
            if($asset instanceof Asset) {
                if (defined('OPENDXP_ASSET_DIRECTORY')) {
                    $file = \OPENDXP_ASSET_DIRECTORY.$asset->getRealFullPath();
                } else {
                    $file = \OPENDXP_WEB_ROOT.'/var/assets'.$asset->getRealFullPath();
                }
            }
            $this->source = $file;
            $this->sourceFilePath = null;
        }
    }

    /**
     * @param bool $removeFileAfterImport
     */
    public function removeFileAfterImport($removeFileAfterImport = true)
    {
        $this->removeFileAfterImport = (bool)$removeFileAfterImport;
    }

    public function getFileConditionFromObject(ElementInterface $object) {
        if($object instanceof Asset) {
            if (defined('OPENDXP_ASSET_DIRECTORY')) {
                $filePath = \OPENDXP_ASSET_DIRECTORY.$object->getRealFullPath();
            } else {
                $filePath = \OPENDXP_WEB_ROOT.'/var/assets'.$object->getRealFullPath();
            }

            if($this->getImportFile($filePath) !== null) {
                $this->release($filePath);
                return $filePath;
            }

            return null;
        }

        throw new IncompatibleTypeException('This object type can currently not be handled');
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

    /**
     * @param string $source
     * @return \League\Flysystem\Filesystem|null
     */
    private function getFileSystem(string $source)
    {
        if($this->fileSystem === null) {
            if (strpos($source, 'ftp://') === 0) {
                $urlParts = parse_url($source);
                if ($urlParts === false && preg_match('/^ftp:\/\/(.+):(.+)@/', $this->source, $match)) {
                    $source = str_replace($match[0], 'ftp://', $source);
                    $urlParts = parse_url($source);
                    $urlParts['user'] = $match[1];
                    $urlParts['pass'] = $match[2];
                }
                $this->fileSystem = new \League\Flysystem\Filesystem(
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
            } elseif (strpos($source, 'ftps://') === 0) {
                $urlParts = parse_url($source);
                if ($urlParts === false && preg_match('/^ftps:\/\/(.+):(.+)@/', $source, $match)) {
                    $source = str_replace($match[0], 'ftps://', $source);
                    $urlParts = parse_url($source);
                    $urlParts['user'] = $match[1];
                    $urlParts['pass'] = $match[2];
                }
                $this->fileSystem = new \League\Flysystem\Filesystem(
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
            } elseif (strpos($source, 'sftp://') === 0) {
                $urlParts = parse_url($source);
                if ($urlParts === false && preg_match('/^sftp:\/\/(.+):(.+)@/', $source, $match)) {
                    $source = str_replace($match[0], 'sftp://', $source);
                    $urlParts = parse_url($source);
                    $urlParts['user'] = $match[1];
                    $urlParts['pass'] = $match[2];
                }
                $this->fileSystem = new \League\Flysystem\Filesystem(
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
            }
        }

        return $this->fileSystem;
    }

    /**
     * @return \SplFileInfo|StorageAttributes|null
     */
    private function getImportFile($filterForFile = null)
    {
        $fileSystem = $this->getFileSystem($this->source);
        if($this->fileIterator === null) {
            $urlParts = parse_url($this->source);
            if ($fileSystem instanceof \League\Flysystem\Filesystem) {
                $this->fileIterator = $fileSystem->listContents(
                    basename($urlParts['path']),
                    FilesystemReader::LIST_DEEP
                )->filter(static function (StorageAttributes $attributes) {
                    return $attributes->isFile();
                })->getIterator();
            } elseif (\is_dir($this->source)) {
                $directoryIterator =
                    new \RecursiveDirectoryIterator($this->source, \RecursiveDirectoryIterator::SKIP_DOTS);

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

                $this->fileIterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator($directoryIterator, $filter));

                // this is not really correct as it does not sort before limiting items but as parser limit does only get used for displaying in Pimcore backend, this is not a real problem
                if ($this->getLimit() > 0 && $this->getLimit() < INF) {
                    $this->fileIterator = new LimitIterator($this->fileIterator, 0, $this->getLimit());
                }

                $this->fileIterator = new SplFileInfoSortedFileIterator($this->fileIterator);
            } else {
                $this->fileIterator = new \GlobIterator($this->source, \GlobIterator::SKIP_DOTS);
                if ($this->getLimit() > 0 && $this->getLimit() < INF) {
                    $this->fileIterator = new LimitIterator($this->fileIterator, 0, $this->getLimit());
                }
            }

            // prevent import of partly uploaded files
            $this->fileIterator = new \CallbackFilterIterator($this->fileIterator, static function($file) use ($filterForFile) {
                if ($file instanceof StorageAttributes) {
                    if($filterForFile) {
                        return strpos($file->path(), $filterForFile) !== false;
                    }
                    return time() - $file->lastModified() > 5;
                }

                /** @var SplFileInfo $file */
                if ($filterForFile) {
                    return strpos($file->getPathname(), $filterForFile) !== false;
                }

                return time() - $file->getMTime() > 5;
            });

            // CallbackFilterIterator does not support count and using iterator_count + rewind does not work (iterator->valid() is false then) -> thus it is wrapped in a CachingIterator
            $this->fileIterator = new \CachingIterator($this->fileIterator, \CachingIterator::FULL_CACHE);
            $this->fileIteratorCount = $this->fileIterator->count();
            $this->fileIterator->rewind();
        }

        $tmpDirectory = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/SylphenDataBridge';
        if (!is_dir($tmpDirectory) && !mkdir($tmpDirectory, 0755) && !is_dir($tmpDirectory)) {
            throw new \Exception('Could not create temporary directory "'.$tmpDirectory.'"');
        }

        do {
            if (!$this->fileIterator->valid()) {
                return null;
            }

            /** @var \SplFileInfo|StorageAttributes $file */
            $file = $this->fileIterator->current();
            if ($file instanceof StorageAttributes) {
                /** @var StorageAttributes $file */
                $tmpFilePath = sprintf('%s/%s', $tmpDirectory, basename($file->path()));

                $dest = fopen($tmpFilePath, 'wb', false, File::getContext());
                if (!$dest) {
                    throw new \Exception(sprintf('Unable to create temporary file in %s', $tmpFilePath));
                }
                $src = $fileSystem->readStream($file->path());

                stream_copy_to_stream($src, $dest);
                fclose($dest);
                fclose($src);

                if ($this->removeFileAfterImport) {
                    $fileSystem->delete($file->path());
                }

                touch($tmpFilePath, $file->lastModified());

                $file = new SplFileInfo($tmpFilePath);
            }

            if($this->getFileIfNotLocked($file->getPathname()) !== null) {
                return $file;
            }

            $this->fileIterator->next();
        } while (true);
    }

    public function getFileIfNotLocked($file)
    {
        $lockKey = md5($file).'-import-'.($this->config['dataportId'] ?? 'unknown').'-'.$file;
        $lockKey = substr($lockKey, 0, 150); // Pimcore 6 compatibility: locks.id is varchar(150) but does also work in later Pimcore versions because md5() is built over complete key

        if ($this->getLimit() === null) {
            if (!$this->lock($lockKey)) {
                return null;
            }
        }

        return $file;
    }

    public function getResource()
    {
        return $this->source;
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
}
