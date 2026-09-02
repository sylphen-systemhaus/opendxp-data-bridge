<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;


interface TranslationProvider
{
    /**
     * @param string $text
     * @param string $languageTo
     * @param string $languageFrom
     * @param bool $isHtml
     * $param array $options
     * @return string
     */
    public function translate($text, $languageTo, $languageFrom = null, $isHtml = false, array $translateOptions = []);
}