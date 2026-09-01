<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use OpenDxp\File;
use Psr\Log\LoggerInterface;

trait TemporaryFileHelperTrait
{
    /**
     * Get local file path of the given file or URL
     *
     * @param string|resource $stream local path, wrapper or file handle
     *
     * @return string path to local file
     *
     * @throws \Exception
     */
    protected static function getLocalFileFromStream($stream): string
    {
        if (!stream_is_local($stream)) {
            $stream = self::getTemporaryFileFromStream($stream);
        }

        if (is_resource($stream)) {
            $streamMeta = stream_get_meta_data($stream);
            $stream = $streamMeta['uri'];
        }

        return $stream;
    }

    /**
     * @param resource|string $stream
     * @param bool $keep whether to delete this file on shutdown or not
     *
     * @return string
     *
     * @throws \Exception
     */
    protected static function getTemporaryFileFromStream($stream, bool $keep = false): string
    {
        if (is_string($stream)) {
            $src = fopen($stream, 'rb');
            $fileExtension = Helper::getFileExtension($stream);
        } else {
            $src = $stream;
            $streamMeta = stream_get_meta_data($src);
            $fileExtension = Helper::getFileExtension($streamMeta['uri']);
            if(strpos($fileExtension, '/') !== false) {
                $fileExtension = '';
            }
        }

        if(!is_resource($src)) {
            throw new \Exception('Invalid stream');
        }

        $tmpFilePath = sprintf(
            '%s/temp-dd-%s.%s',
            OPENDXP_SYSTEM_TEMP_DIRECTORY,
            uniqid().'-'.bin2hex(random_bytes(15)),
            $fileExtension ?: 'tmp'
        );

        $dest = fopen($tmpFilePath, 'wb', false, File::getContext());
        if (!$dest) {
            throw new \Exception(sprintf('Unable to create temporary file in %s', $tmpFilePath));
        }

        stream_copy_to_stream($src, $dest);
        fclose($dest);

        if (!$keep) {
            register_shutdown_function(
                static function () use ($tmpFilePath) {
                    @unlink($tmpFilePath);
                }
            );
        }

        return $tmpFilePath;
    }

    public static function getCurlResource($fileOrUrl) {
        $options = [
            CURLOPT_URL => $fileOrUrl,
            CURLOPT_RETURNTRANSFER => true,    // return web page
            CURLOPT_FOLLOWLOCATION => true,    // follow redirects
            CURLOPT_ENCODING => '',        // handle all encodings
            CURLOPT_AUTOREFERER => true,    // set referer on redirect
            CURLOPT_CONNECTTIMEOUT => 0,        // timeout on connect
            CURLOPT_TIMEOUT => 0,        // timeout on response
            CURLOPT_MAXREDIRS => 10,        // stop after 10 redirects
            CURLOPT_SSL_VERIFYPEER => false,    // Disabled SSL Cert checks
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:107.0) Gecko/20100101 Firefox/107.0',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        return $ch;
    }

    public static function getStreamFromFileOrUrl($fileOrUrl, ?LoggerInterface $logger = null) {
        $temporaryFile = self::getTemporaryFileFromFileOrUrl($fileOrUrl);

        if($temporaryFile) {
            return fopen($temporaryFile, 'rb');
        }

        $streamContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        return @fopen($fileOrUrl, 'rb', false, $streamContext);
    }

    public static function getTemporaryFileFromFileOrUrl($fileOrUrl, $fileExtension = null) {
        $tmpFilePath = sprintf(
            '%s/temp-dd-%s.%s',
            OPENDXP_SYSTEM_TEMP_DIRECTORY,
            md5($fileOrUrl),
            $fileExtension ?? Helper::getFileExtension($fileOrUrl)
        );

        if(file_exists($tmpFilePath)) {
            return $tmpFilePath;
        }

        try {
            $uri = new Uri($fileOrUrl);
            if (!in_arrayi($uri->getScheme(), ['http', 'https'])) {
                throw new \InvalidArgumentException('URI is not HTTP(S), falling back to local file / stream wrapper');
            }

            $retries = 3;

            loadFromStream:
            $ch = self::getCurlResource($fileOrUrl);
            $fileStream = fopen($tmpFilePath, 'w+b');

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $data) use ($fileStream) {
                fwrite($fileStream, $data);
                return strlen($data);
            });
            curl_exec($ch);

            $curlError = curl_error($ch);
            if($curlError) {
                fclose($fileStream);
                @unlink($tmpFilePath);
                throw new \InvalidArgumentException($curlError);
            }

            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatus === 429 && $retries-- > 0) {
                \OpenDxp\Logger::info('Service for URL '.$fileOrUrl.' is rate limited: response: '.file_get_contents($tmpFilePath).' -> retrying access after 60 seconds');

                fclose($fileStream);
                @unlink($tmpFilePath);
                sleep(60);
                goto loadFromStream;
            }

            fclose($fileStream);

            if ($httpStatus >= 400 || $httpStatus < 200) {
                @unlink($tmpFilePath);
                return null;
            }

            register_shutdown_function(
                static function () use ($tmpFilePath) {
                    @unlink($tmpFilePath);
                }
            );

            return $tmpFilePath;
        } catch (\InvalidArgumentException $e) {
            $sourceStream = null;

            if (strpos($fileOrUrl, 'ftp://') === 0) {
                $urlParts = parse_url($fileOrUrl);
                if ($urlParts === false && preg_match('/^ftp:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                    $source = str_replace($match[0], 'ftp://', $fileOrUrl);
                    $urlParts = parse_url($source);
                }
                $fileSystem = Helper::getFileSystem($fileOrUrl);

                if ($fileSystem->fileExists($urlParts['path'])) {
                    $sourceStream = $fileSystem->readStream($urlParts['path']);
                }

                $pathWithLowercaseExtension = preg_replace_callback('/\.([A-Z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtolower($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithLowercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithLowercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithLowercaseExtension);
                }

                $pathWithUppercaseExtension = preg_replace_callback('/\.([a-z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtoupper($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithUppercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithUppercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithUppercaseExtension);
                }
            } elseif (strpos($fileOrUrl, 'ftps://') === 0) {
                $urlParts = parse_url($fileOrUrl);
                if ($urlParts === false && preg_match('/^ftps:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                    $source = str_replace($match[0], 'ftps://', $fileOrUrl);
                    $urlParts = parse_url($source);
                }
                $fileSystem = Helper::getFileSystem($fileOrUrl);

                if ($fileSystem->fileExists($urlParts['path'])) {
                    $sourceStream = $fileSystem->readStream($urlParts['path']);
                }

                $pathWithLowercaseExtension = preg_replace_callback('/\.([A-Z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtolower($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithLowercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithLowercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithLowercaseExtension);
                }

                $pathWithUppercaseExtension = preg_replace_callback('/\.([a-z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtoupper($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithUppercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithUppercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithUppercaseExtension);
                }
            } elseif (strpos($fileOrUrl, 'sftp://') === 0) {
                $urlParts = parse_url($fileOrUrl);
                if ($urlParts === false && preg_match('/^sftp:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                    $source = str_replace($match[0], 'sftp://', $fileOrUrl);
                    $urlParts = parse_url($source);
                }
                $fileSystem = Helper::getFileSystem($fileOrUrl);

                if ($fileSystem->fileExists($urlParts['path'])) {
                    $sourceStream = $fileSystem->readStream($urlParts['path']);
                }

                $pathWithLowercaseExtension = preg_replace_callback('/\.([A-Z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtolower($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithLowercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithLowercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithLowercaseExtension);
                }

                $pathWithUppercaseExtension = preg_replace_callback('/\.([a-z0-9]+)$/', static function ($fileExtension) {
                    return '.'.strtoupper($fileExtension[1]);
                }, $urlParts['path']);
                if ($pathWithUppercaseExtension !== $urlParts['path'] && $fileSystem->fileExists($pathWithUppercaseExtension)) {
                    $sourceStream = $fileSystem->readStream($pathWithUppercaseExtension);
                }
            } elseif (strpos($fileOrUrl, 's3://') === 0) {
                $urlParts = parse_url($fileOrUrl);
                if ($urlParts === false && preg_match('/^s3:\/\/(.+):(.+)@/', $fileOrUrl, $match)) {
                    $source = str_replace($match[0], 's3://', $fileOrUrl);
                    $urlParts = parse_url($source);
                }
                $pathParts = array_filter(explode('/', $urlParts['path']));
                array_shift($pathParts);
                $urlParts['path'] = implode('/', $pathParts);

                $fileSystem = Helper::getFileSystem($fileOrUrl);

                if ($fileSystem->fileExists($urlParts['path'])) {
                    $sourceStream = $fileSystem->readStream($urlParts['path']);
                }
            } else {
                $streamContext = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ]);

                $sourceStream = @fopen($fileOrUrl, 'rb', false, $streamContext);
            }

            if (is_resource($sourceStream)) {
                $fileStream = fopen($tmpFilePath, 'w+b');
                stream_copy_to_stream($sourceStream, $fileStream);

                fclose($fileStream);
                fclose($sourceStream);

                register_shutdown_function(
                    static function () use ($tmpFilePath) {
                        @unlink($tmpFilePath);
                    }
                );

                return $tmpFilePath;
            }
        }

        return null;
    }
}
