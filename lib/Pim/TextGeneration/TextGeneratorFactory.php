<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\TextGeneration;

use OpenDxp\Model\WebsiteSetting;

class TextGeneratorFactory
{
    public static function get($config) {
        if(!empty($config['text_generation']['openai_api_key'])) {
            return new OpenAiTextGenerator($config['text_generation']['openai_api_key']);
        }

        $websiteSetting = WebsiteSetting::getByName('OpenAi.com API Key');
        if ($websiteSetting instanceof WebsiteSetting && $websiteSetting->getData()) {
            return new OpenAiTextGenerator($websiteSetting->getData());
        }

        return new NullTextGenerator();
    }
}