<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\RawData;

use Ramsey\Uuid\Uuid;

class UuidGenerator
{
    public static function generate() {
        if(method_exists(Uuid::class, 'uuid6')) {
            return Uuid::uuid6();
        }
        return Uuid::fromString(self::uuid1ToUuid6((string)Uuid::uuid1()));
    }

    /**
     * Convert UUID v1 to UUID v6.
     *
     * @see https://github.com/mikemix/php-uuid-v6/blob/master/src/Uuid.php
     * @see https://bradleypeabody.github.io/uuidv6/
     *
     * @param string $uuidString
     *
     * @return string
     */
    private static function uuid1ToUuid6($uuidString)
    {
        $uuidString = str_replace('-', '', $uuidString);
        $timeLow1 = substr($uuidString, 0, 5);
        $timeLow2 = substr($uuidString, 5, 3);
        $timeMid = substr($uuidString, 8, 4);
        $timeHigh = substr($uuidString, 13, 3);
        $rest = substr($uuidString, 16);

        return
            $timeHigh.$timeMid.$timeLow1[0].'-'.
            substr($timeLow1, 1).'-'.
            '6'.$timeLow2.'-'.
            substr($rest, 0, 4).'-'.
            substr($rest, 4);
    }
}