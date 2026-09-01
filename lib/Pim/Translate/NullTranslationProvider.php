<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;


class NullTranslationProvider implements TranslationProvider
{
    public function translate($text, $languageTo, $languageFrom = null, $isHtml = false, array $translateOptions = [])
    {
        throw new \Exception('Please provide DeepL API Key in attribute mapping / website settings, see https://www.deepl.com/de/docs-api/api-access/authentication/');
    }
}