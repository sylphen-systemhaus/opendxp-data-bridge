<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;


use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\OpenAiTextGenerator;
use OpenDxp\Model\WebsiteSetting;
use Throwable;

class TranslationProviderFactory
{
    public static function get($deeplApiKey, $awsAccessKey, $awsSecretKey, $config) {
        if($deeplApiKey) {
            return new DeepL($deeplApiKey, $config);
        }

        $websiteSetting = WebsiteSetting::getByName('DeepL API Key');
        if ($websiteSetting instanceof WebsiteSetting && $websiteSetting->getData()) {
            return new DeepL($websiteSetting->getData());
        }

        try {
            return new AwsTranslate($awsAccessKey, $awsSecretKey);
        } catch(Throwable $e) {
            return new NullTranslationProvider();
        }
    }
}