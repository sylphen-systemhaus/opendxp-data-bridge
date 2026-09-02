<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

class StripByteOrderMarkFilter extends \php_user_filter
{
    private $bomPatterns = [
        "\xEF\xBB\xBF", // UTF-8 BOM
        "\xFF\xFE",     // UTF-16LE BOM
        "\xFE\xFF",     // UTF-16BE BOM
    ];

    #[\ReturnTypeWillChange]
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            foreach ($this->bomPatterns as $bom) {
                if (substr($bucket->data, 0, strlen($bom)) === $bom) {
                    $bucket->data = substr($bucket->data, strlen($bom));
                }
            }

            $bucket->data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{00A0}\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $bucket->data);

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}