<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use Symfony\Component\Workflow\Registry;

class DataQuerySelectorTranspiler
{
    /**
     * @param string $identifier
     *
     * @return string
     */
    public function getCode($identifier) {
        $parenthesisParser = new ParenthesisParser();
        $structure = $parenthesisParser->parse($identifier);

        $bracedItems = $parenthesisParser->generate($structure);

        $code = '$object = call_user_func(static function($object, $logger) {
            '.$this->doResolve($bracedItems).'
        }, $object, $logger);
return (new \Sylphen\DataBridgeBundle\lib\Pim\Serializer())->serialize($object);'.PHP_EOL;

        return $code;
    }

    private function doResolve($bracedItems) {
        $code = '$result = [];'.PHP_EOL;
        $aliasUsed = false;
        $prevArrayKey = null;

        foreach($bracedItems as $arrayKey => $bracedItem) {
            $field = $arrayKey;

            if (preg_match('/(.+) as ([\p{L}\p{Nd}_\-]+)"?$/ui', $field, $tmp)) {
                $field = $tmp[1];
                $arrayKey = $tmp[2];

                if(!is_numeric($arrayKey)) {
                    $aliasUsed = true;
                }
            }

            $field = preg_replace('/(^|:)"?([^:]+::[^:]+?)"?(:|$)/', '$1"$2"$3', $field);

            if($prevArrayKey !== null && preg_match('/^"*:/', $field)) {
                $code .= '$data = $result[\''.str_replace("'", '\\\'', $prevArrayKey).'\'];
                unset($result[\''.str_replace("'", '\\\'', $prevArrayKey).'\']);'.PHP_EOL;
                $field = preg_replace('/^"*:/', '', $field);
                $arrayKey = preg_replace('/^"*:/', '', $arrayKey);
            } else {
                $code .= '$data = $object;'.PHP_EOL;
            }

            $tokens = \str_getcsv($field, ':', '"');
            foreach($tokens as $token) {
                $token = str_replace("'", '\\\'', $token);
                if((string)$token !== '') {
                    $code .= '$data = \Sylphen\DataBridgeBundle\lib\Pim\Item\DataQuerySelectorResolver::resolveSingleField(\''.$token.'\', $data, $logger);'.PHP_EOL;
                }
            }

            $arrayKey = str_replace(['"', "'"], ['', '\\\''], $arrayKey);
            if(is_array($bracedItem)) {
                $code .= '$queryFunction = static function($object) use ($logger, &$queryFunction) {
                    '.$this->doResolve($bracedItem).'
                };'.PHP_EOL;
                $code .= '
                if(is_array($data)) {
                    $result[\''.$arrayKey.'\'] = array_map($queryFunction, $data);
                } else {
                    $result[\''.$arrayKey.'\'] = $queryFunction($data);
                }'.PHP_EOL;
            } else {
                $code .= '$result[\''.$arrayKey.'\'] = $data;'.PHP_EOL;
            }

            $prevArrayKey = $arrayKey;
        }

        if(!$aliasUsed) {
            $code .= 'if(count($result) === 1) {
                $result = reset($result);
            }'.PHP_EOL;
        }

        $code .= 'return $result;'.PHP_EOL;
        return $code;
    }
}