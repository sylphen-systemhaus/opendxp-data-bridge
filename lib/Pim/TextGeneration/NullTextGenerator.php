<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\TextGeneration;


class NullTextGenerator implements TextGenerator
{
    public function generate($text)
    {
        throw new \Exception('Please provide OpenAI.com API key in attribute mapping / website settings, see https://help.openai.com/en/articles/4936850-where-do-i-find-my-secret-api-key');
    }
}