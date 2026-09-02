<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\PathFormatter;

use OpenDxp\Model\DataObject\ClassDefinition\PathFormatterInterface;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;

class DefaultPathFormatter implements PathFormatterInterface
{
    public function formatPath(array $result, ElementInterface $source, array $targets, array $params): array
    {
        foreach ($targets as $key => $item) {
            $item = Service::getElementById($item['type'], $item['id']);
            if(!$item instanceof ElementInterface) {
                continue;
            }

            $result[$key] = $item->getRealFullPath();
        }

        return $result;
    }
}