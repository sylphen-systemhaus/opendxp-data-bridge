<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Sylphen\DataBridgeBundle\Tools\Installer;
use Exception;
use OpenDxp;
use Symfony\Component\Mime\MimeTypes;
use OpenDxp\Tool\Mime;

trait BackingUpResponsePhpCompatibilityTrait
{
    /** @var resource */
    protected $temporaryFileHandle;

    /** @var string */
    protected $temporaryFilename;

    protected $hasContent = false;
    protected $pointerIsAtEnd = false;
    protected $cachedContent = null;

    protected $storedToCache = false;

    protected $contentComplete = false;

    protected $paramsResponseNoticeLogged = 0;

    public function __construct($dataportId, $statusKey = null, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->temporaryFilename = Installer::getResultDocumentPath().'/response_'.$dataportId.'_'.($statusKey ?? uniqid());

        $this->headers->set('Access-Control-Allow-Origin', '*');
        $this->headers->set('X-Data-Bridge-Run', $statusKey);
    }

    public function __destruct()
    {
        if(is_resource($this->temporaryFileHandle)) {
            @fclose($this->temporaryFileHandle);
        }

        if(!$this->storedToCache) {
            @unlink($this->temporaryFilename);
        }
    }

    private function setUp() {
        if($this->temporaryFilename && !is_resource($this->temporaryFileHandle)) {
            $this->temporaryFileHandle = fopen($this->temporaryFilename, 'wb+');
            $this->pointerIsAtEnd = true;
        }
    }

    public function __sleep()
    {
        $this->contentComplete = true;

        $properties = array_keys(get_object_vars($this));

        foreach ($properties as $propertyIndex => $property) {
            if ($property === 'temporaryFileHandle') {
                unset($properties[$propertyIndex]);
                break;
            }
        }
        return $properties;
    }

    public function __wakeup()
    {
        if($this->hasContent()) {
            if($this->contentComplete && file_exists($this->temporaryFilename) && @filesize($this->temporaryFilename) > 0) {
                $this->temporaryFileHandle = @fopen($this->temporaryFilename, 'rb+');
            } else {
                $this->setUp();
                $this->hasContent = false;
            }
        }
        $this->storedToCache = true;
    }

    /**
     * @return string
     */
    public function doGetContent()
    {
        if (!$this->hasContent()) {
            return '';
        }

        if($this->pointerIsAtEnd && $this->cachedContent !== null) {
            return $this->cachedContent;
        }

        $this->setUp();
        if (is_resource($this->temporaryFileHandle)) {
            fseek($this->temporaryFileHandle, 0);
            $this->pointerIsAtEnd = false;
            $buffer = '';
            $blocks = 0;
            while (!feof($this->temporaryFileHandle)) {
                $buffer .= fgets($this->temporaryFileHandle, 4096);
                $blocks++;
            }
            $this->pointerIsAtEnd = true;

            if($blocks < 10000) { // 40MB
                $this->cachedContent = $buffer;
            }

            return $buffer;
        }

        return parent::getContent();
    }

    private function getFirstBytesOfContent($bytes) {
        if (!$this->hasContent()) {
            return '';
        }

        if ($this->pointerIsAtEnd && $this->cachedContent !== null) {
            return substr($this->cachedContent, 0, $bytes);
        }

        $this->setUp();
        if (is_resource($this->temporaryFileHandle)) {
            fseek($this->temporaryFileHandle, 0);

            $buffer = '';
            $remainingBytes = $bytes;
            while (!feof($this->temporaryFileHandle) && $remainingBytes > 0) {
                $block = fgets($this->temporaryFileHandle, min(4096, $remainingBytes));
                $lengthBlock = strlen($block);
                if($lengthBlock === 0) {
                    break;
                }
                $buffer .= $block;
                $remainingBytes -= $lengthBlock;
            }

            return $buffer;
        }

        return substr(parent::getContent(), 0, $bytes);
    }

    /**
     * @param string $content
     * @return static
     */
    public function doSetContent($content)
    {
        if($content === '' && !$this->hasContent()) {
            return $this;
        }

        if (strlen($content) < 40000000) { // 40MB
            $oldContent = $this->getFirstBytesOfContent(strlen($content));
            $this->cachedContent = $content;
            if(strpos($content, $oldContent) === 0) {
                if($this->paramsResponseNoticeLogged < 1) {
                    $this->paramsResponseNoticeLogged = 1;
                } elseif($this->paramsResponseNoticeLogged < 2) {
                    $this->paramsResponseNoticeLogged = 2;
                    OpenDxp\Logger::notice(
                        'For faster execution use $params[\'response\']->hasContent() and $params[\'response\']->addContent() in result callback function instead of $params[\'response\']->getContent() and $params[\'response\']->setContent(). Also using "return ..." will trigger $params[\'response\']->setContent() - please replace this with $params[\'response\']->addContent(). Alternatively have a look at the updated result callback function templates "Export raw data as ...")'
                    );
                }

                $this->addContent(substr($content, strlen($oldContent)));
                return $this;
            }
        } elseif ($this->paramsResponseNoticeLogged < 3) {
            $this->paramsResponseNoticeLogged = 3;
            OpenDxp\Logger::warning('Content too large to cache in memory, for better performance please use $params[\'response\']->hasContent() and $params[\'response\']->addContent() instead of $params[\'response\']->getContent() and $params[\'response\']->setContent()');
        }

        if (is_resource($this->temporaryFileHandle)) {
            fclose($this->temporaryFileHandle);
        }

        $this->setUp();

        if (is_resource($this->temporaryFileHandle)) {
            fwrite($this->temporaryFileHandle, $content);

            $this->hasContent = (bool)$content;
            $this->pointerIsAtEnd = true;

            return $this;
        }

        return parent::setContent($content);
    }

    /**
     * @param string|null $content
     * @return self
     */
    public function addContent($content)
    {
        if($content) {
            $this->hasContent = true;
        }

        $this->setUp();

        $this->cachedContent = null;
        if(is_resource($this->temporaryFileHandle)) {
            if(!$this->pointerIsAtEnd) {
                fseek($this->temporaryFileHandle, 0, SEEK_END);
                $this->pointerIsAtEnd = true;
            }
            fwrite($this->temporaryFileHandle, $content);
            return $this;
        }

        return parent::setContent($content);
    }

    public function hasContent() {
        return $this->hasContent;
    }

    public function getLength() {
        $this->setUp();
        if (is_resource($this->temporaryFileHandle)) {
            fseek($this->temporaryFileHandle, 0, SEEK_END);
            $this->pointerIsAtEnd = true;
            return ftell($this->temporaryFileHandle);
        }

        return 0;
    }

    /**
     * @return static
     */
    public function doSendContent()
    {
        $out = fopen('php://output', 'wb');

        if ($this->hasContent()) {
            $this->setUp();
            fseek($this->temporaryFileHandle, 0);
            $this->pointerIsAtEnd = false;
            stream_copy_to_stream($this->temporaryFileHandle, $out);
        }

        fclose($out);

        return $this;
    }

    public function getOutputStream()
    {
        $this->setUp();
        fseek($this->temporaryFileHandle, 0);
        $this->pointerIsAtEnd = false;
        return $this->temporaryFileHandle;
    }

    public function getBackupFile() {
        return $this->temporaryFilename;
    }

    /**
     * @return string
     */
    public function getFileExtension() {
        $fileExtension = '';

        if(!$this->hasContent()) {
            return $fileExtension;
        }

        $mimeType = $this->headers->get('Content-Type');
        if (class_exists(MimeTypes::class)) {
            $mimeTypes = new MimeTypes();

            $fileExtensions = $mimeTypes->getExtensions($mimeType ?? '');
            if ($fileExtensions) {
                $fileExtension = $fileExtensions[0];
            } else {
                $mimeType = $mimeTypes->guessMimeType($this->getBackupFile());
                $fileExtensions = $mimeTypes->getExtensions($mimeType ?? '');
                if ($fileExtensions) {
                    $fileExtension = $fileExtensions[0];
                }
            }
        } else {
            $mimeType = Mime::detect($this->getBackupFile());

            $mimeMap = [
                'video/3gpp2' => '3g2',
                'video/3gp' => '3gp',
                'video/3gpp' => '3gp',
                'application/x-compressed' => '7zip',
                'audio/x-acc' => 'aac',
                'audio/ac3' => 'ac3',
                'application/postscript' => 'ai',
                'audio/x-aiff' => 'aif',
                'audio/aiff' => 'aif',
                'audio/x-au' => 'au',
                'video/x-msvideo' => 'avi',
                'video/msvideo' => 'avi',
                'video/avi' => 'avi',
                'application/x-troff-msvideo' => 'avi',
                'application/macbinary' => 'bin',
                'application/mac-binary' => 'bin',
                'application/x-binary' => 'bin',
                'application/x-macbinary' => 'bin',
                'image/bmp' => 'bmp',
                'image/x-bmp' => 'bmp',
                'image/x-bitmap' => 'bmp',
                'image/x-xbitmap' => 'bmp',
                'image/x-win-bitmap' => 'bmp',
                'image/x-windows-bmp' => 'bmp',
                'image/ms-bmp' => 'bmp',
                'image/x-ms-bmp' => 'bmp',
                'application/bmp' => 'bmp',
                'application/x-bmp' => 'bmp',
                'application/x-win-bitmap' => 'bmp',
                'application/cdr' => 'cdr',
                'application/coreldraw' => 'cdr',
                'application/x-cdr' => 'cdr',
                'application/x-coreldraw' => 'cdr',
                'image/cdr' => 'cdr',
                'image/x-cdr' => 'cdr',
                'zz-application/zz-winassoc-cdr' => 'cdr',
                'application/mac-compactpro' => 'cpt',
                'application/pkix-crl' => 'crl',
                'application/pkcs-crl' => 'crl',
                'application/x-x509-ca-cert' => 'crt',
                'application/pkix-cert' => 'crt',
                'text/css' => 'css',
                'text/x-comma-separated-values' => 'csv',
                'text/comma-separated-values' => 'csv',
                'text/csv' => 'csv',
                'application/vnd.msexcel' => 'csv',
                'application/x-director' => 'dcr',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/x-dvi' => 'dvi',
                'message/rfc822' => 'eml',
                'application/x-msdownload' => 'exe',
                'video/x-f4v' => 'f4v',
                'audio/x-flac' => 'flac',
                'video/x-flv' => 'flv',
                'image/gif' => 'gif',
                'application/gpg-keys' => 'gpg',
                'application/x-gtar' => 'gtar',
                'application/x-gzip' => 'gzip',
                'application/mac-binhex40' => 'hqx',
                'application/mac-binhex' => 'hqx',
                'application/x-binhex40' => 'hqx',
                'application/x-mac-binhex40' => 'hqx',
                'text/html' => 'html',
                'image/x-icon' => 'ico',
                'image/x-ico' => 'ico',
                'image/vnd.microsoft.icon' => 'ico',
                'text/calendar' => 'ics',
                'application/java-archive' => 'jar',
                'application/x-java-application' => 'jar',
                'application/x-jar' => 'jar',
                'image/jp2' => 'jp2',
                'video/mj2' => 'jp2',
                'image/jpx' => 'jp2',
                'image/jpm' => 'jp2',
                'image/jpeg' => 'jpeg',
                'image/pjpeg' => 'jpeg',
                'application/x-javascript' => 'js',
                'application/json' => 'json',
                'text/json' => 'json',
                'application/vnd.google-earth.kml+xml' => 'kml',
                'application/vnd.google-earth.kmz' => 'kmz',
                'text/x-log' => 'log',
                'audio/x-m4a' => 'm4a',
                'audio/mp4' => 'm4a',
                'application/vnd.mpegurl' => 'm4u',
                'audio/midi' => 'mid',
                'application/vnd.mif' => 'mif',
                'video/quicktime' => 'mov',
                'video/x-sgi-movie' => 'movie',
                'audio/mpeg' => 'mp3',
                'audio/mpg' => 'mp3',
                'audio/mpeg3' => 'mp3',
                'audio/mp3' => 'mp3',
                'video/mp4' => 'mp4',
                'video/mpeg' => 'mpeg',
                'application/oda' => 'oda',
                'audio/ogg' => 'ogg',
                'video/ogg' => 'ogg',
                'application/ogg' => 'ogg',
                'font/otf' => 'otf',
                'application/x-pkcs10' => 'p10',
                'application/pkcs10' => 'p10',
                'application/x-pkcs12' => 'p12',
                'application/x-pkcs7-signature' => 'p7a',
                'application/pkcs7-mime' => 'p7c',
                'application/x-pkcs7-mime' => 'p7c',
                'application/x-pkcs7-certreqresp' => 'p7r',
                'application/pkcs7-signature' => 'p7s',
                'application/pdf' => 'pdf',
                'application/octet-stream' => 'pdf',
                'application/x-x509-user-cert' => 'pem',
                'application/x-pem-file' => 'pem',
                'application/pgp' => 'pgp',
                'application/x-httpd-php' => 'php',
                'application/php' => 'php',
                'application/x-php' => 'php',
                'text/php' => 'php',
                'text/x-php' => 'php',
                'application/x-httpd-php-source' => 'php',
                'image/png' => 'png',
                'image/x-png' => 'png',
                'application/powerpoint' => 'ppt',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.ms-office' => 'ppt',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'application/x-photoshop' => 'psd',
                'image/vnd.adobe.photoshop' => 'psd',
                'audio/x-realaudio' => 'ra',
                'audio/x-pn-realaudio' => 'ram',
                'application/x-rar' => 'rar',
                'application/rar' => 'rar',
                'application/x-rar-compressed' => 'rar',
                'audio/x-pn-realaudio-plugin' => 'rpm',
                'application/x-pkcs7' => 'rsa',
                'text/rtf' => 'rtf',
                'text/richtext' => 'rtx',
                'video/vnd.rn-realvideo' => 'rv',
                'application/x-stuffit' => 'sit',
                'application/smil' => 'smil',
                'text/srt' => 'srt',
                'image/svg+xml' => 'svg',
                'application/x-shockwave-flash' => 'swf',
                'application/x-tar' => 'tar',
                'application/x-gzip-compressed' => 'tgz',
                'image/tiff' => 'tiff',
                'font/ttf' => 'ttf',
                'text/plain' => 'txt',
                'text/x-vcard' => 'vcf',
                'application/videolan' => 'vlc',
                'text/vtt' => 'vtt',
                'audio/x-wav' => 'wav',
                'audio/wave' => 'wav',
                'audio/wav' => 'wav',
                'application/wbxml' => 'wbxml',
                'video/webm' => 'webm',
                'image/webp' => 'webp',
                'audio/x-ms-wma' => 'wma',
                'application/wmlc' => 'wmlc',
                'video/x-ms-wmv' => 'wmv',
                'video/x-ms-asf' => 'wmv',
                'font/woff' => 'woff',
                'font/woff2' => 'woff2',
                'application/xhtml+xml' => 'xhtml',
                'application/excel' => 'xl',
                'application/msexcel' => 'xls',
                'application/x-msexcel' => 'xls',
                'application/x-ms-excel' => 'xls',
                'application/x-excel' => 'xls',
                'application/x-dos_ms_excel' => 'xls',
                'application/xls' => 'xls',
                'application/x-xls' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-excel' => 'xlsx',
                'application/xml' => 'xml',
                'text/xml' => 'xml',
                'text/xsl' => 'xsl',
                'application/xspf+xml' => 'xspf',
                'application/x-compress' => 'z',
                'application/x-zip' => 'zip',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip',
                'application/s-compressed' => 'zip',
                'multipart/x-zip' => 'zip',
                'text/x-scriptzsh' => 'zsh',
            ];

            $fileExtension = $mimeMap[$mimeType] ?? '';
        }

        return $fileExtension;
    }

    public function cache($path) {
        $serializedResponse = \serialize($this);
        \file_put_contents($path, $serializedResponse);

        $this->storedToCache = true;
    }
}