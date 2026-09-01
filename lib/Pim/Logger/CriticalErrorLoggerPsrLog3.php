<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Psr\Log\AbstractLogger;

class CriticalErrorLoggerPsrLog3 extends AbstractLogger
{
    use CriticalErrorLoggerTrait;

    public function log($level, \Stringable|string $message, array $context = array()): void
    {
        $this->doLog($level, $message, $context);
    }
}