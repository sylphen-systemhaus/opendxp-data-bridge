<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

// @rodneyrehm
// http://stackoverflow.com/a/7917979/99923
use Exception;

class ParenthesisParser
{
    protected $regex;
    protected $offsetToToken;

    private const T_FIELD = 'FIELD';
    private const T_SEPARATOR = 'SEPARATOR';
    private const T_OPEN = 'OPEN';
    private const T_CLOSE = 'CLOSE';

    public function __construct()
    {
        $tokenMap = array(
            '"?[^;()]+"?' => self::T_FIELD,
            '"?;"?' => self::T_SEPARATOR,
            '"?\("?' => self::T_OPEN,
            '"?\)"?' => self::T_CLOSE
        );

        $this->regex = '(('.implode(')|(', array_keys($tokenMap)).'))A';
        $this->offsetToToken = array_values($tokenMap);
    }

    public function parse($string)
    {
        $tokens = array();

        $offset = 0;
        while (isset($string[$offset])) {
            if (!preg_match($this->regex, $string, $matches, 0, $offset)) {
                throw new Exception(sprintf('Unexpected character "%s"', $string[$offset]));
            }

            // find the first non-empty element (but skipping $matches[0]) using a quick for loop
            for ($i = 1; '' === $matches[$i]; ++$i) {
                ;
            }
            $tokens[] = array($matches[0], $this->offsetToToken[$i - 1]);
            $offset += strlen($matches[0]);
        }

        return $tokens;
    }

    // a recursive function to actually build the structure
    function generate($arr = array())
    {
        $output = array();
        $current = null;

        for ($i = 0, $iMax = count($arr); $i < $iMax; $i++) {
            $element = rtrim($arr[$i][0], ':"');
            $type = $arr[$i][1];

            if ($type === self::T_OPEN) {
                $openedBraces = 1;

                for($j=$i+1;$j<$iMax;$j++) {
                    if ($arr[$j][1] === self::T_OPEN) {
                        $openedBraces++;
                    } elseif($arr[$j][1] === self::T_CLOSE) {
                        $openedBraces--;
                        if($openedBraces === 0) {
                            $output[$current ?? 'self as '.count($output)] = $this->generate(array_slice($arr, $i+1, $j - $i));

                            $i = $j;
                            break;
                        }
                    }
                }

                if($openedBraces > 0) {
                    throw new Exception('Syntax error in data query selector: An opening brace did not get closed');
                }
            } elseif ($type === self::T_CLOSE) {
                return $output;
            } elseif ($type === self::T_FIELD) {
                if(preg_match('/^ as ([\p{L}\p{Nd}_\-]+)$/ui', $element, $match)) {
                    end($output);

                    $lastIndex = key($output);

                    if(strpos($lastIndex, 'self as ') === 0) {
                        $output['self as '.$match[1]] = $output[$lastIndex];
                    } else {
                        $output[$lastIndex.' as '.$match[1]] = $output[$lastIndex];
                    }
                    unset($output[$lastIndex]);
                } else {
                    $element = '"'.implode('":"', str_getcsv($element, ':', '"')).'"';
                    $element = str_replace('":"":"', '::', $element); // service / static method calls
                    $output[$element] = null;
                    $current = $element;
                }
            }
        }

        return $output;
    }
}