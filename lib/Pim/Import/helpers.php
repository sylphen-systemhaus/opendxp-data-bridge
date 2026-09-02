<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Import;

use ArrayAccess;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\DataQuerySelectorResolver;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\TypedArrayMapIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\lib\Pim\Translate\TranslationProvider;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Renderer\RendererConstant;
use League\HTMLToMarkdown\HtmlConverter;
use OpenDxp;
use OpenDxp\File;
use OpenDxp\Logger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\GeoCoordinates;
use OpenDxp\Model\DataObject\Data\Hotspotimage;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Data\StructuredTable;
use OpenDxp\Model\DataObject\Listing;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Objectbrick;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Version;
use OpenDxp\Tool;
use OpenDxp\Tool\Mime;
use OpenDxp\Cache;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Tool\Serialize;
use Psr\Log\NullLogger;
use Rinvex\Country\Country;
use Speckcommerce\Uom\UomList;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Workflow\Registry;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

function arrayToHTMLTable($list) {
    if(!is_array($list)) {
        return null;
    }
    if (count($list) > 0) {
        $html = '<table border=1 cellspacing=0 cellpadding=2>
            <thead>
              <tr>
                <th>'.implode('</th><th>', array_map('htmlspecialchars', array_keys(current($list)))).'</th>
              </tr>
            </thead>
            <tbody>';
        foreach ($list as $row) {
            $html .= '
                <tr>
                  <td>'.implode('</td><td>', array_map('htmlspecialchars', $row)).'</td>
                </tr>';
        }
        $html .= '
    </tbody>
</table>';
        return $html;
    }

    return null;
}

function translate(string $term, string $locale = null, string $sourceLanguage = null): string
{
    return Importer::translate($term, $locale, $sourceLanguage);
}

function add(float $number1, float $number2): float
{
    return $number1 + $number2;
}

function subtract(float $number1, float $number2): float
{
    return $number1 - $number2;
}

function multiply(float $factor1, float $factor2): float
{
    return $factor1 * $factor2;
}

function divide(float $number1, float $number2): float
{
    return $number1 / $number2;
}

function cefactUnitCommonCode($unit)
{
    if(!is_string($unit)) {
        return $unit;
    }

    if(in_arrayi($unit, ['stück','piece','pieces','stck', 'stk', 'pc', 'pcs', 'pce','C62'])) {
        return 'C62';
    }
    $unit = OpenDxp::getContainer()->get('translator')->trans($unit, [], 'admin', 'en');

    $list = new UomList();

    if($list->find($unit) !== null) {
        return $unit;
    }

    $cefactUnits = $list->getAll();

    foreach($cefactUnits as $cefactUnit) {
        if($cefactUnit === $unit) {
            return $cefactUnit['commoncode'];
        }
    }

    $lowestDistance = ['commoncode' => null, 'distance' => INF];
    foreach ($cefactUnits as $cefactUnit) {
        $distance = levenshtein($cefactUnit['name'], $unit);
        if($lowestDistance['distance'] > $distance) {
            $lowestDistance = ['commoncode' => $cefactUnit['commoncode'], 'distance' => $distance];
        }
    }

    if($lowestDistance['distance'] < 2) {
        return $lowestDistance['commoncode'];
    }

    return $unit;
}

function languageCode($language, $standard = 'iso639-1')
{
    $languageConverter = new LanguageCode();

    $languageCode = $languageConverter->convert($language, $standard);

    if ($languageCode === null && strpos($language, '_') !== false) {
        $language = substr($language, 0, strpos($language, '_'));
    }

    return $languageConverter->convert($language, $standard);
}

function countryCode($country, $standard = 'iso_3166_1_alpha2') {
    $countries = \Rinvex\Country\CountryLoader::where('iso_3166_1_alpha2', $country);
    if (count($countries) > 0) {
        reset($countries);
        \Rinvex\Country\CountryLoader::country(key($countries))->get($standard);
        return reset($countries)[$standard];
    }
    $countries = \Rinvex\Country\CountryLoader::where('iso_3166_1_alpha3', $country);
    if (count($countries) > 0) {
        reset($countries);
        \Rinvex\Country\CountryLoader::country(key($countries))->get($standard);
        return reset($countries)[$standard];
    }
    /** @var Country[] $countries */
    $countries = \Rinvex\Country\CountryLoader::where('name.common', $country);
    if (count($countries) > 0) {
        reset($countries);
        \Rinvex\Country\CountryLoader::country(key($countries))->get($standard);
        return reset($countries)[$standard];
    }
}

function mimeType($filePath) {
    if(is_string($filePath)) {
        $fileExtension = Helper::getFileExtension($filePath);
        if(empty($fileExtension) || strlen($fileExtension) > 4) {
            $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
            $filePath = $importer->getAssetSourceFile($filePath);

            if (file_exists($filePath)) {
                if (class_exists(MimeTypes::class)) {
                    $mimeTypes = new MimeTypes();
                    $mimeType = $mimeTypes->guessMimeType($filePath);
                } else {
                    $mimeType = Mime::detect($filePath);
                }
            }

            if (!empty($mimeType)) {
                return $mimeType;
            }
        }
    }

    if(is_resource($filePath)) {
        $streamMeta = stream_get_meta_data($filePath);
        $filePath = $streamMeta['uri'];
    }

    if(!$filePath) {
        return 'application/octet-stream';
    }

    if (class_exists(MimeTypes::class)) {
        $mimeTypes = new MimeTypes();

        $fileExtension = Helper::getFileExtension($filePath);

        $mimeTypes = $mimeTypes->getMimeTypes($fileExtension);
        if ($mimeTypes) {
            return $mimeTypes[0];
        }
    }

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

    $fileExtension = Helper::getFileExtension($filePath);

    $mimeType = array_search($fileExtension, $mimeMap);

    if($mimeType) {
        return $mimeType;
    }

    return 'application/octet-stream';
}

function fileExtensionFromMimeType($mimeType) {
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

    return $mimeMap[$mimeType] ?? null;
}

function getAncestors(OpenDxp\Model\Element\ElementInterface $element, $includingSelf = false)
{
    $ancestors = [];
    if ($includingSelf) {
        $ancestors[] = $element;
    }

    $parent = $element->getParent();
    while ($parent instanceof $element || $element instanceof $parent) {
        $parent = $element->getParent();
        $ancestors[] = $parent;
        $element = $parent;
        $parent = $element->getParent();
    }

    return array_reverse($ancestors);
}

function ancestors(OpenDxp\Model\Element\ElementInterface $element, $includingSelf = false)
{
    return getAncestors($element, $includingSelf);
}

function getDescendants(OpenDxp\Model\Element\ElementInterface $element, $includingSelf = false)
{
    /** @var OpenDxp\Model\DataObject\Listing $list */
    $list = $element::getList([
        'unpublished' => true,
        'objectTypes' => [
            AbstractObject::OBJECT_TYPE_OBJECT,
            AbstractObject::OBJECT_TYPE_VARIANT,
        ]
    ]);

    $elementType = OpenDxp\Model\Element\Service::getElementType($element);
    $list->addConditionParam(($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' LIKE ?', $element->getRealFullPath().'%');
    $list->setOrderKey(($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path'));
    $list->setOrder('ASC');

    $descendants = $list->load();

    if($includingSelf) {
        $descendants[] = $element;
    }

    return $descendants;
}

function descendants(OpenDxp\Model\Element\ElementInterface $element, $includingSelf = false)
{
    return getDescendants($element, $includingSelf);
}

function is($item, $selector, $desiredValue, $comparisonOperator = '=') {
    $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
    $actualValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $item);

    if ($desiredValue instanceof DateTimeImmutable) {
        $actualValue = new DateTimeImmutable($actualValue);
    }

    switch (true) {
        case in_array($comparisonOperator, ['=', 'in'], true) && in_array($desiredValue, (array)$actualValue, false):
        case $comparisonOperator === '>' && $actualValue > $desiredValue:
        case $comparisonOperator === '>=' && $actualValue >= $desiredValue:
        case $comparisonOperator === '<' && $actualValue < $desiredValue:
        case $comparisonOperator === '<=' && $actualValue <= $desiredValue:
        case in_array($comparisonOperator, ['!=', '<>'], true) && $actualValue != $desiredValue:
        case $comparisonOperator === 'in' && is_array($actualValue) && in_array($desiredValue, $actualValue):
            return true;
    }

    return false;
}

/**
 * @param iterable|array $items
 * @param string $selector data query selector
 * @param mixed $desiredValue filter value
 * @return array
 */
function filter($items, $selector, $desiredValue, $comparisonOperator = '=') {
    $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
    $result = [];

    $desiredValueLowerCase = strtolower($desiredValue);
    if ($desiredValueLowerCase === 'false') {
        $desiredValue = false;
    } elseif ($desiredValueLowerCase === 'true') {
        $desiredValue = true;
    } elseif ($desiredValueLowerCase === 'null') {
        $desiredValue = null;
    } elseif ($desiredValue === 'now') {
        $desiredValue = new DateTimeImmutable();
    }

    if ($items instanceof ElementInterface) {
        $items = [$items];
    }

    foreach($items as $item) {
        $actualValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $item);

        if ($desiredValue instanceof DateTimeImmutable) {
            $actualValue = new DateTimeImmutable($actualValue);
        }

        switch(true) {
            case in_array($comparisonOperator,['=', 'in'], true) && in_array($desiredValue, (array)$actualValue, false):
            case $comparisonOperator === '>' && $actualValue > $desiredValue:
            case $comparisonOperator === '>=' && $actualValue >= $desiredValue:
            case $comparisonOperator === '<' && $actualValue < $desiredValue:
            case $comparisonOperator === '<=' && $actualValue <= $desiredValue:
            case in_array($comparisonOperator, ['!=', '<>'], true) && $actualValue != $desiredValue:
            case $comparisonOperator === 'in' && is_array($actualValue) && in_array($desiredValue, $actualValue):
                $result[] = $item;
                break;
        }
    }

    return $result;
}

function orderBy($items, $field, $direction = 'ASC') {
    if($items instanceof ElementInterface) {
        $items = [$items];
    }
    usort($items, static function($item1, $item2) use ($field, $direction) {
        $getter = 'get'.ucfirst($field);

        if(empty($direction) || strtoupper($direction) === 'ASC') {
            return $item1->$getter() <=> $item2->$getter();
        } else {
            return $item2->$getter() <=> $item1->$getter();
        }
    });

    return $items;
}

function withoutInheritance($context)
{
    Helper::useInheritance(false);

    return $context;
}

function withInheritance($context)
{
    Helper::useInheritance(true);

    return $context;
}

function self($context)
{
    return $context;
}

function not($context)
{
    return !$context;
}

function exists($context)
{
    return !empty($context);
}

function closestOfClass($context, string $classId)
{
    if($context instanceof ObjectMetadata) {
        $context = $context->getObject();
    } elseif ($context instanceof ElementMetadata) {
        $context = $context->getElement();
    }

    $parent = $context->getParent();
    if ($parent instanceof AbstractObject) {
        while ($parent && (!$parent instanceof Concrete || $parent->getClassId() !== $classId)) {
            $parents[$parent->getId()] = $parent->getRealPath();
            $parent = $parent->getParent();
            if(array_key_exists($parent->getId(), $parents)) {
                // prevent infinite loop due to circular references
                asort($parents);
                $parent = DataObject::getByPath(reset($parents));
            }
        }

        if ($parent && in_array($parent->getType(), [Concrete::OBJECT_TYPE_OBJECT, Concrete::OBJECT_TYPE_VARIANT], true)) {
            /** @var Concrete $parent */
            if ($parent->getClassId() === $classId) {
                return $parent;
            }
        }
    }

    return null;
}

function uppermostOfClass($context, string $classId)
{
    $parentOfClass = null;
    do {
        $uppermost = $parentOfClass;
        if ($uppermost != null) {
            $context = $uppermost;
        }
        $parentOfClass = closestOfClass($context, $classId);
    } while($parentOfClass !== null);

    return $uppermost;
}

function latestVersion(OpenDxp\Model\Element\ElementInterface $element) {
    $versionIds = PimcoreDbRepository::getInstance()->findColumnInSql(
        'SELECT id FROM versions WHERE ctype = ? AND cid = ?'.' AND autoSave=0'.' ORDER BY `versionCount` DESC',
        [
            \OpenDxp\Model\Element\Service::getElementType($element),
            $element->getId()
        ]
    );

    foreach ($versionIds as $versionId) {
        $version = null;
        if ($versionId) {
            $version = Version::getById($versionId);
        }
        if (!$version instanceof Version) {
            continue;
        }

        Helper::loadVersion($version);
        $element = $version->getData();
        return $element;
    }

    return null;
}

function latestVersionWith(OpenDxp\Model\Element\ElementInterface $element, $selector, $desiredValue, $comparisonOperator = '=') {
    $versionIds = PimcoreDbRepository::getInstance()->findColumnInSql(
        'SELECT id FROM versions WHERE ctype = ? AND cid = ? AND autoSave=0 ORDER BY `versionCount` DESC',
        [
            \OpenDxp\Model\Element\Service::getElementType($element),
            $element->getId()
        ]
    );

    foreach($versionIds as $versionId) {
        $version = null;
        if ($versionId) {
            $version = Version::getById($versionId);
        }
        if (!$version instanceof Version) {
            continue;
        }

        Helper::loadVersion($version);
        $element = $version->getData();

        if(is($element, $selector, $desiredValue, $comparisonOperator)) {
            return $element;
        }
    }

    return null;
}

function changes(OpenDxp\Model\Element\ElementInterface $element, $selector = null, $date = null)
{
    if ($date === null && $selector && ctype_digit($selector)) {
        $date = $selector;
        $selector = null;
    }

    if (!$selector) {
        Serializer::trimOutputForBetterPerformance();
        $selector = 'self';
    }

    $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
    $currentValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $element);

    $cacheKey = __FUNCTION__.'-'.$element->getId().'-'.($date instanceof DateTimeInterface ? $date->format('Y-m-d H:i:s') : $date);
    try {
        $latestVersion = RuntimeCache::get($cacheKey);
        $versionId = $latestVersion->getId();
    } catch (\Exception $e) {
        $query = 'SELECT id FROM versions WHERE ctype = ? AND cid = ?'.' AND autoSave = 0';
        $queryParams = [
            \OpenDxp\Model\Element\Service::getElementType($element),
            $element->getId(),
        ];

        if (!empty($date)) {
            if (is_string($date) && ctype_digit($date)) {
                $dateThreshold = (new DateTimeImmutable('@0'))->setTimestamp($date)->setTimezone(new DateTimeZone(date_default_timezone_get()));
            } elseif ($date) {
                $dateThreshold = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            } else {
                $dateThreshold = new DateTimeImmutable();
            }
            $query .= ' AND `date` <= ?';
            $queryParams[] = $dateThreshold->getTimestamp();
            $query .= ' ORDER BY `versionCount` DESC';
        } else {
            $query .= ' AND 1=0';
        }
        $query .= ' LIMIT 1';

        $versionId = PimcoreDbRepository::getInstance()->findOneInSql($query, $queryParams);

        $latestVersion = null;
        if ($versionId) {
            $latestVersion = Version::getById($versionId);
        }

        if (!$latestVersion instanceof Version) {
            $currentLog = Importer::getLogOutput($currentValue);

            if (is_array($currentValue)) {
                $versionValue = [];

                $currentValue = Helper::reindex_by_object_id($currentValue);
                $versionValue = Helper::reindex_by_object_id($versionValue);

                $diffCurrentValue = Helper::array_diff_assoc_recursive($currentValue, $versionValue);
                $diffVersionValue = Helper::array_intersect_key_recursive($versionValue, $diffCurrentValue);

                $translator = OpenDxp::getContainer()->get(ImporterInterface::class)->getTranslator();
                $diff = '<table class="diff-wrapper diff"><thead><tr><th>'.$translator->trans('pim.mapping.field', [], 'admin').'</th>';
                $diff .= '<th>'.$translator->trans('pim.mapping.field.difference', [], 'admin').'</th></tr></thead><tbody>';
                foreach (array_keys($diffCurrentValue) as $index => $fieldName) {
                    if ($fieldName === 'query') {
                        continue;
                    }
                    $diffVersionFieldValue = trim(preg_replace('/<\/p>$/', '', preg_replace('/^<p>/', '', Importer::getLogOutput($diffVersionValue[$fieldName] ?? ''))));
                    $diffCurrentFieldValue = trim(preg_replace('/<\/p>$/', '', preg_replace('/^<p>/', '', Importer::getLogOutput($diffCurrentValue[$fieldName] ?? ''))));
                    if ($diffVersionFieldValue === $diffCurrentFieldValue) {
                        continue;
                    }
                    $diff .= '<tr><td style="background:'.(($index % 2) ? '#eee' : '#fff').'">'.$fieldName.'</td><td style="background:'.(($index % 2) ? '#eee' : '#fff').'">'.diffString($diffCurrentFieldValue, $diffVersionFieldValue).'</td></tr>';
                }
                $diff .= '</tbody></table>';

                $cssToInlineStyles = new CssToInlineStyles();
                $diff = $cssToInlineStyles->convert($diff, file_get_contents(__DIR__.'/../../../Resources/public/vendor/php-diff/diff-table.css'));

                $diffStartBody = strpos($diff, '<body') + 6;
                $diffEndBody = strrpos($diff, '</body>');
                $diff = substr($diff, $diffStartBody, $diffEndBody - $diffStartBody);
            } else {
                $diffCurrentValue = $currentValue;
                $versionValue = '';
                $diff = diffString(
                    html_entity_decode($currentLog, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'),
                    html_entity_decode($versionValue, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'),
                );
            }
            return [
                'date' => (new DateTimeImmutable('1970-01-01'))->format('Y-m-d H:i:s'),
                'oldValue' => json_encode($versionValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                'newValue' => json_encode($currentValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                'diff' => $diff,
                'changes' => $diffCurrentValue,
                'versionId' => 0,
            ];
        }

        $versionDate = (new DateTimeImmutable('@0'))->setTimestamp($latestVersion->getDate())->setTimezone(new DateTimeZone(date_default_timezone_get()));

        Helper::loadVersion($latestVersion);

        RuntimeCache::save($latestVersion, $cacheKey);
    }

    $oldItem = $latestVersion->getData();
    if (!$element instanceof $oldItem && !$oldItem instanceof $element) {
        // This could be the case if unserialization goes wrong (and $oldItem is an __PHP_Incomplete_Class_Name)
        $oldItem = null;
    }

    Serializer::clearCache();
    $versionValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $oldItem);

    if ($element->getModificationDate() < $oldItem->getModificationDate()) {
        $tmp = $versionValue;
        $versionValue = $currentValue;
        $currentValue = $tmp;
    }


    $currentLog = Importer::getLogOutput($currentValue);
    $versionLog = Importer::getLogOutput($versionValue);

    $versionDate = new DateTimeImmutable('@'.$latestVersion->getDate());

    if ($currentLog !== $versionLog) {
        if (is_array($currentValue) && is_array($versionValue)) {
            $currentValue = Helper::reindex_by_object_id($currentValue);
            $versionValue = Helper::reindex_by_object_id($versionValue);

            $diffCurrentValue = Helper::array_diff_assoc_recursive($currentValue, $versionValue);
            $diffVersionValue = Helper::array_intersect_key_recursive($versionValue, $diffCurrentValue);

            $isDraftVersion = $versionDate > (new DateTimeImmutable('@0'))->setTimestamp($element->getModificationDate())->setTimezone(new DateTimeZone(date_default_timezone_get()));
            if ($isDraftVersion) {
                $tmp = $diffVersionValue;
                $diffVersionValue = $diffCurrentValue;
                $diffCurrentValue = $tmp;
            }

            $translator = OpenDxp::getContainer()->get(ImporterInterface::class)->getTranslator();
            $diff = '<table class="diff-wrapper diff"><thead><tr><th>'.$translator->trans('pim.mapping.field', [], 'admin').'</th>';
            $diff .= '<th>'.$translator->trans('pim.mapping.field.difference', [], 'admin').'</th></tr></thead><tbody>';
            foreach (array_keys($diffCurrentValue) as $index => $fieldName) {
                if ($fieldName === 'query') {
                    continue;
                }
                $diffVersionFieldValue = trim(preg_replace('/<\/p>$/', '', preg_replace('/^<p>/', '', Importer::getLogOutput($diffVersionValue[$fieldName] ?? ''))));
                $diffCurrentFieldValue = trim(preg_replace('/<\/p>$/', '', preg_replace('/^<p>/', '', Importer::getLogOutput($diffCurrentValue[$fieldName] ?? ''))));
                if ($diffVersionFieldValue === $diffCurrentFieldValue) {
                    continue;
                }
                $diff .= '<tr><td style="background:'.(($index % 2) ? '#eee' : '#fff').'">'.$fieldName.'</td><td style="background:'.(($index % 2) ? '#eee' : '#fff').'">'.diffString($diffCurrentFieldValue, $diffVersionFieldValue).'</td></tr>';
            }
            $diff .= '</tbody></table>';

            $cssToInlineStyles = new CssToInlineStyles();
            $diff = $cssToInlineStyles->convert($diff, file_get_contents(__DIR__.'/../../../Resources/public/vendor/php-diff/diff-table.css'));

            $diffStartBody = strpos($diff, '<body') + 6;
            $diffEndBody = strrpos($diff, '</body>');
            $diff = substr($diff, $diffStartBody, $diffEndBody - $diffStartBody);
        } else {
            $diffCurrentValue = $currentValue;
            $diff = diffString(
                html_entity_decode($currentLog, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'),
                html_entity_decode($versionLog, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'),
            );
        }

        return [
            'date' => $versionDate->format('Y-m-d H:i:s'),
            'oldValue' => json_encode($versionValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'newValue' => json_encode($currentValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'diff' => $diff,
            'changes' => $diffCurrentValue,
            'user' => $latestVersion->getUser()->getUsername(),
            'versionId' => $versionId,
        ];
    }

    return [];
}

/**
 * @param OpenDxp\Model\Element\ElementInterface $element
 * @return OpenDxp\Model\Element\ElementInterface
 * @throws Exception
 */
function before(OpenDxp\Model\Element\ElementInterface $element, $afterDate = null) {
    if (!$element->getId()) {
        return null;
    }

    $dateThreshold = null;
    if ($afterDate !== null) {
        if (is_string($afterDate) && ctype_digit($afterDate)) {
            $dateThreshold = (new DateTimeImmutable('@0'))->setTimestamp($afterDate)->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } else {
            $dateThreshold = (new DateTimeImmutable($afterDate))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
    }

    if($dateThreshold instanceof DateTimeInterface) {
        $versionId = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT id FROM versions WHERE ctype = ? AND cid = ? AND `date` <= ?'.' AND autoSave=0'.' ORDER BY `versionCount` DESC LIMIT 1',
            [
                \OpenDxp\Model\Element\Service::getElementType($element),
                $element->getId(),
                $dateThreshold->getTimestamp()
            ]
        );

        if(!$versionId) {
            $versionId = PimcoreDbRepository::getInstance()->findOneInSql(
                'SELECT id FROM versions WHERE ctype = ? AND cid = ?'.' AND autoSave=0'.' ORDER BY versionCount LIMIT 1',
                [
                    \OpenDxp\Model\Element\Service::getElementType($element),
                    $element->getId(),
                ]
            );
        }
    } else {
        $versionId = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT id FROM versions WHERE ctype = ? AND cid = ? AND (`date` < ? OR versionCount < ?)'.' AND autoSave=0'.' ORDER BY `versionCount` DESC LIMIT 1',
            [
                \OpenDxp\Model\Element\Service::getElementType($element),
                $element->getId(),
                $element->getModificationDate(),
                $element->getVersionCount(),
            ]
        );
    }

    $latestVersion = null;
    if ($versionId) {
        $latestVersion = Version::getById($versionId);
    }
    if (!$latestVersion instanceof Version) {
        throw new Exception('Could not find latest version');
    }

    Helper::loadVersion($latestVersion);

    $oldItem = $latestVersion->getData();
    if (!$element instanceof $oldItem && !$oldItem instanceof $element) {
        // This could be the case if unserialization goes wrong (and $oldItem is an __PHP_Incomplete_Class_Name)
        $oldItem = null;
    }

    return $oldItem;
}

function diffString($logOutputCurrent, $logOutputOld) {
    switch (Helper::getRequest()->getLocale()) {
        case 'de':
            $language = 'deu';
            break;
        case 'fr':
            $language = 'fra';
            break;
        case 'it':
            $language = 'ita';
            break;
        case 'jp':
            $language = 'jpn';
            break;
        case 'ru':
            $language = 'rus';
            break;
        case 'es':
            $language = 'spa';
            break;
        case 'en':
        default:
            $language = 'eng';
    }

    return DiffHelper::calculate(
        $logOutputOld,
        $logOutputCurrent,
        'Combined',
        [
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => false,
        ], [
            // how detailed the rendered HTML in-line diff is? (none, line, word, char)
            'detailLevel' => 'word',
            // renderer language: eng, cht, chs, jpn, ...
            // or an array which has the same keys with a language file
            'language' => $language,
            // show line numbers in HTML renderers
            'lineNumbers' => false,
            // show a separator between different diff hunks in HTML renderers
            'separateBlock' => false,
            // show the (table) header
            'showHeader' => false,
            // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
            // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
            'spacesToNbsp' => false,
            // HTML renderer tab width (negative = do not convert into spaces)
            'tabSize' => 4,
            // this option is currently only for the Combined renderer.
            // it determines whether a replace-type block should be merged or not
            // depending on the content changed ratio, which values between 0 and 1.
            'mergeThreshold' => 0,
            // this option is currently only for the Unified and the Context renderers.
            // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
            // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
            // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
            'cliColorization' => RendererConstant::CLI_COLOR_DISABLE,
            // this option is currently only for the Json renderer.
            // internally, ops (tags) are all int type but this is not good for human reading.
            // set this to "true" to convert them into string form before outputting.
            'outputTagAsString' => true,
            // this option is currently only for the Json renderer.
            // it controls how the output JSON is formatted.
            // see available options on https://www.php.net/manual/en/function.json-encode.php
            'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            // this option is currently effective when the "detailLevel" is "word"
            // characters listed in this array can be used to make diff segments into a whole
            // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
            // this should bring better readability but set this to empty array if you do not want it
            'wordGlues' => [' ', '-'],
            // extra HTML classes added to the DOM of the diff container
            'wrapperClasses' => ['diff-wrapper summary'],
        ]
    );
}

function diff(OpenDxp\Model\Element\ElementInterface $element, $selector) {
    $latestVersionId = PimcoreDbRepository::getInstance()->findOneInSql(
        'SELECT id FROM versions WHERE ctype = ? AND cid = ? AND (`date` >= ? OR versionCount >= ?) ORDER BY `versionCount` DESC LIMIT 1',
        [
            \OpenDxp\Model\Element\Service::getElementType($element),
            $element->getId(),
            $element->getModificationDate(),
            $element->getVersionCount()
        ]
    );

    $latestVersion = null;
    if ($latestVersionId) {
        $latestVersion = Version::getById($latestVersionId);
    }
    if ($latestVersion instanceof Version) {
        if ($latestVersion->getSerialized()) {
            // in Version::loadData the runtime cache gets cleared -> restore it afterwards
            $runtimeCacheData = RuntimeCache::getInstance()->getArrayCopy();
            @$latestVersion->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
            RuntimeCache::getInstance()->exchangeArray($runtimeCacheData);
        } else {
            @$latestVersion->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
        }

        $currentElement = $latestVersion->getData();
    } else {
        $currentElement = Service::getElementById(OpenDxp\Model\Element\Service::getElementType($element), $element->getId());
    }

    $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
    $currentValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $currentElement);
    $oldValue = $importer->getObjectByIdentifier('.:.:.:'.$selector, $element);

    $logOutputCurrent = Importer::getLogOutput($currentValue);
    $logOutputOld = Importer::getLogOutput($oldValue);

    return diffString($logOutputCurrent, $logOutputOld);
}

function childrenIDs(OpenDxp\Model\Element\ElementInterface $object) {
    if ($object instanceof DataObject) {
        $list = new Listing();
    } elseif ($object instanceof Asset) {
        $list = new Asset\Listing();
    } elseif ($object instanceof Document) {
        $list = new Document\Listing();
    }

    $list->setValues([
        'unpublished' => true,
        'objectTypes' => [
            AbstractObject::OBJECT_TYPE_OBJECT,
            AbstractObject::OBJECT_TYPE_VARIANT,
        ],
        'locale' => Tool::getDefaultLanguage(),
    ]);

    $list->setCondition((\OpenDxp\Model\Element\Service::getElementType($object) === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' = ?', $object->getId());
    if ($object instanceof DataObject) {
        $list->setOrderKey(Helper::prefixObjectSystemColumn($object->getChildrenSortBy()));
        $list->setOrder($object->getChildrenSortOrder());
    }

    return $list->loadIdList();
}

function brick(Concrete $object, $brickName) {
    foreach($object->getClass()->getFieldDefinitions() as $fieldDefinition) {
        if(($fieldDefinition instanceof DataObject\ClassDefinition\Data\Objectbricks) && in_array($brickName, $fieldDefinition->getAllowedTypes(), true)) {
            $containerGetter = 'get'.ucfirst($fieldDefinition->getName());
            $brickGetter = 'get'.ucfirst($brickName);
            $brick = $object->$containerGetter()->$brickGetter();

            if($brick instanceof AbstractData) {
                return $brick;
            }
        }
    }

    throw new \Exception('Could not find brick "'.$brickName.'"');
}

function htmlToText($htmlContent) {
    if(is_array($htmlContent)) {
        foreach($htmlContent as &$htmlItem) {
            $htmlItem = \Soundasleep\Html2Text::convert((string)$htmlItem, ['ignore_errors' => true]);
        }
        unset($htmlItem);
        return $htmlContent;
    }
    return \Soundasleep\Html2Text::convert((string)$htmlContent, ['ignore_errors' => true]);
}

function toString($value) {
    if (is_bool($value)) {
        if ($value) {
            $logOutput = 'true';
        } else {
            $logOutput = 'false';
        }
    } elseif ($value === null) {
        $logOutput = null;
    } elseif (is_scalar($value)) {
        $logOutput = strip_tags($value);
    } elseif ($value instanceof ElementInterface) {
        $logOutput = $value->getRealFullPath();
    } elseif ($value instanceof QuantityValue) {
        $logOutput = (string)$value;
    } elseif (is_array($value) && reset($value) instanceof ElementInterface) {
        $logOutput = implode(
            ', ',
            array_map(static function ($item) {
                return $item->getRealFullPath();
            }, $value)
        );
    } elseif (is_array($value) && (reset($value) instanceof ElementMetadata || reset($value) instanceof ObjectMetadata)) {
        $logOutput = implode(
            ', ',
            array_map(static function ($item) {
                $element = $item->getElement();
                if ($element instanceof ElementInterface) {
                    $metaDataString = urldecode(http_build_query($item->getData(), '', ', '));
                    return $element->getRealFullPath().($metaDataString ? ' ('.$metaDataString.')' : '');
                }
            }, $value)
        );
    } elseif (is_array($value) && reset($value) instanceof Hotspotimage) {
        $logOutput = implode(
            ', ',
            array_filter(
                array_map(static function ($item) {
                    $image = $item->getImage();
                    if ($image instanceof Asset\Image) {
                        return $image->getRealFullPath();
                    }
                }, $value)
            )
        );
    } elseif (is_array($value) && count($value) === 0) {
        $logOutput = null;
    } elseif (is_array($value) && isset($value['fullpath'])) {
        $logOutput = $value['fullpath'];
    } elseif (is_array($value) && isset(reset($value)['fullpath'])) {
        $logOutput = implode(
            ', ',
            array_filter(
                array_map(static function ($item) {
                    return $item['fullpath'] ?? null;
                }, $value)
            )
        );
    } elseif (is_array($value) && isset(reset($value)['object']['fullpath'])) {
        $logOutput = implode(
            ', ',
            array_filter(
                array_map(static function ($item) {
                    $metaDataString = urldecode(http_build_query($item['meta'], '', ', '));
                    return ($item['object']['key'] ?? null).($metaDataString ? ' ('.$metaDataString.')' : '');
                }, $value)
            )
        );
    } elseif (is_array($value) && isset($value['key'])) {
        $logOutput = $value['key'];
    } elseif (is_array($value) && isset(reset($value)['key'])) {
        $logOutput = implode(
            ', ',
            array_filter(
                array_map(static function ($item) {
                    return $item['key'] ?? null;
                }, $value)
            )
        );
    } elseif (is_array($value) && isset(reset($value)['object']['key'])) {
        $logOutput = implode(
            ', ',
            array_filter(
                array_map(static function ($item) {
                    $metaDataString = urldecode(http_build_query($item['meta'], '', ', '));
                    return ($item['object']['key'] ?? null).($metaDataString ? ' ('.$metaDataString.')' : '');
                }, $value)
            )
        );
    } elseif (is_array($value) && isset($value['value'])) {
        $logOutput = $value['value'].($value['unit'] ? ' '.$value['unit'] : '');
    } elseif (is_array($value) && is_scalar(reset($value))) {
        $logOutput = urldecode(http_build_query($value, '', ', '));
    } elseif ($value instanceof StructuredTable) {
        $logOutput = json_encode($value->getData(), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } elseif ($value instanceof DataObject\Classificationstore) {
        $logOutput = toString(DataQuerySelectorResolver::resolveSingleField('labels', $value, new NullLogger()));
    } elseif ($value instanceof DataObject\Objectbrick) {
        $logOutput = toString(DataQuerySelectorResolver::resolveSingleField('labels', $value, new NullLogger()));
    } elseif ($value instanceof \Iterator) {
        $logOutput = toString(iterator_to_array($value));
    } else {
        $serializer = new Serializer();
        $logOutput = json_encode($serializer->serialize($value), JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    return $logOutput;
}

function array_merge(array $value)
{
    if(!is_array(reset($value))) {
        return $value;
    }
    return \array_merge(...$value);
}

function implode($value, $separator = ',')
{
    if(is_array($value)) {
        return \implode($separator, $value);
    }

    return \implode($value, $separator);
}

function currentUser() {
    return Tool\Admin::getCurrentUser();
}

function getWorkflowState(ElementInterface $element, $workflowName) {
    /** @var Registry $workflowRegistry */
    $workflowRegistry = \OpenDxp::getContainer()->get(Registry::class);
    $workflow = $workflowRegistry->get($element, $workflowName);

    return $workflow->getMarking($element);
}

function fields(OpenDxp\Model\AbstractModel $object) {
    $removeDisplayfields = static function($field) {
        if (!empty($field->customSettings)) {
            foreach ($field->customSettings as $customFieldPropertyName => $customFieldPropertyValue) {
                if (strpos($customFieldPropertyName, 'displayfield') === 0 || strpos($customFieldPropertyName, 'inputEl') !== false || in_array($customFieldPropertyName, ['childs','children'])) {
                    unset($field->customSettings[$customFieldPropertyName]);
                }
            }
        }

        return $field;
    };

    if ($object instanceof ElementInterface) {
        $fieldDefinitions = [];
        foreach ($object->getClass()->getFieldDefinitions() as $fieldDefinition) {
            if ($fieldDefinition instanceof Data\Localizedfields) {
                foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                    $localizedFieldDefinition->localized = true;

                    $fieldDefinitions[] = $removeDisplayfields($localizedFieldDefinition);
                }
                continue;
            }

            $fieldDefinition->localized = false;
            $fieldDefinitions[] = $fieldDefinition;
        }
        return $fieldDefinitions;
    }

    if ($object instanceof Objectbrick) {
        $fields = [];
        foreach ($object->getObject()->getClass()->getFieldDefinition($object->getFieldname())->getAllowedTypes() as $brickName) {
            $brickGetter = 'get'.ucfirst($brickName);
            $brickItem = $object->$brickGetter();
            if (!$brickItem instanceof AbstractData) {
                try {
                    $brickItem = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($brickName), [$object->getObject()]);
                    $brickItem->setFieldname($object->getFieldname());
                } catch (\Exception $e) {
                    throw new \Exception('"'.$brickName.'" is not a valid brick in '.$object->getFieldname().'.');
                }
            }
            /** @var Definition $brickDefinition */
            $brickDefinition = $brickItem->getDefinition();
            $fieldDefinitions = [];
            foreach ($brickDefinition->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof Data\Localizedfields) {
                    foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        $localizedFieldDefinition->localized = true;

                        $fieldDefinitions[] = $removeDisplayfields($localizedFieldDefinition);
                    }
                    continue;
                }

                $fieldDefinition->localized = false;

                $fieldDefinitions[] = $removeDisplayfields($fieldDefinition);
            }
            $fields[$brickDefinition->getKey()] = $fieldDefinitions;
        }
        return $fields;
    }
}

function levels(OpenDxp\Model\AbstractModel $object, $levels = 1)
{
    $serializer = new Serializer($levels);
    return $serializer->serialize($object);
}

function sql($query, array $parameters = []) {
    return PimcoreDbRepository::getInstance()->findInSql($query, $parameters);
}