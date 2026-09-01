<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use JsonMachine\JsonDecoder\ExtJsonDecoder;

class JsonDecoder extends ExtJsonDecoder
{
    public function decode($jsonValue)
    {
        $jsonValue = preg_replace(
            '/"\\\\u0000\*\\\\u0000(.+?)"\s*:/',
            '"$1":',
            $jsonValue
        );

        $jsonValue = preg_replace_callback(
            '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/s',
            function ($m) {
                return '"' . str_replace(["\r", "\n"], '', $m[1]) . '"';
            },
            $jsonValue
        );

        return parent::decode($jsonValue);
    }
}