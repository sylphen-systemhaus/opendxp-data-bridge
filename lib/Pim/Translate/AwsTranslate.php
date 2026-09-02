<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;


use Aws\Translate\TranslateClient;

class AwsTranslate extends AbstractTranslationProvider
{
    /** @var TranslateClient */
    private $api;

    public function __construct($accessKey = null, $secretKey = null)
    {
        $config = [];

        if(!empty($accessKey) && !empty($secretKey)) {
            $config['credentials'] = [
                'key' => $accessKey,
                'secret' => $secretKey,
            ];
        }

        $this->api = new TranslateClient($config);
    }

    public function translate($text, $languageTo, $languageFrom = null, $isHtml = false, array $translateOptions = [])
    {
        return $this->api->translateText([
            'SourceLanguageCode' => \strtolower(substr($languageFrom, 0, 2)),
            'TargetLanguageCode' => \strtolower(substr($languageTo, 0, 2)),
            'Text' => $text,
        ])['TranslatedText'] ?? null;
    }
}