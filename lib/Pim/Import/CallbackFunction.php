<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Import;

use Sylphen\DataBridgeBundle\lib\Pim\Item\LazyParams;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use RuntimeException;
use Symfony\Component\Process\PhpProcess;
use Throwable;

class CallbackFunction
{
    const ENGINE_SPIDERMONKEY_LEGACY = 'spidermonkeyLegacy';
    const ENGINE_SPIDERMONKEY = 'spidermonkey';
    const ENGINE_V8JS = 'v8Js';
    const ENGINE_PHP = 'php';

    const PHP_PREFIX = '<?php';

    private static $preJS;
    private static $functionTranspiled = [];
    private static $staticCache = [];

    private static $warnings;

    public static function isEngineAvailable($engine)
    {
        switch ($engine) {
            case self::ENGINE_PHP:
                return true;
            case self::ENGINE_V8JS:
                return class_exists('V8Js');
            case self::ENGINE_SPIDERMONKEY:
            case self::ENGINE_SPIDERMONKEY_LEGACY:
                return class_exists('JSContext');

            default:
                return false;
        }
    }

    private static function getPreJS() {
        if(self::$preJS === null) {
            self::$preJS = file_get_contents(__DIR__ . '/../Item/libraries.js');
        }
        return self::$preJS;
    }

    /**
     * @param $code string Javascript code to execute
     * @param $engine string Javascirpt engine name
     * @param $params array
     *
     * @return mixed
     */
    public static function evaluateScript($code, $engine, $params)
    {
        switch ($engine) {
            case self::ENGINE_PHP:
                return self::evaluateScriptPHP($code, $params);
            case self::ENGINE_V8JS:
                try {
                    return self::evaluateScriptV8(self::getPreJS()."\n\n".$code, $params);
                } catch(\V8JsScriptException $e) {
                    try {
                        return self::evaluateScriptPHP($code, $params);
                    } catch(\Throwable $phpException) {
                        throw $e;
                    }

                }
            case self::ENGINE_SPIDERMONKEY_LEGACY:
                return self::evaluateScriptSpidermonkeyLegacy(self::getPreJS()."\n\n".$code, $params);

            case self::ENGINE_SPIDERMONKEY:
                return self::evaluateScriptSpidermonkey(self::getPreJS()."\n\n".$code, $params);

            default:
                return null;
        }
    }

    private static function evaluateScriptSpidermonkeyLegacy($js, $params)
    {
        $jsCtx = new \JSContext();

        foreach ($params as $key => $param) {
            $jsCtx->assign($key, $param);
        }

        return $jsCtx->evaluateScript($js);
    }

    private static function evaluateScriptSpidermonkey($js, $params)
    {

        $jsCtx = new \JSContext();
        $jsCtx->assign('input', $params);

        $code = [
            ';(function(params) {',
            $js,
            '})(input);'
        ];

        return $jsCtx->evaluateScript(implode(PHP_EOL, $code));
    }

    private static function evaluateScriptV8($js, $params)
    {
        $js = preg_replace(
            '/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/',
            'params[\'virtualFields\'][\'__virtual_$1\']',
            $js
        );

        $v8 = new \V8Js();

        if (strpos($js, 'params.currentObjectData') !== false || strpos($js, 'params[\'currentObjectData\']') !== false) {
            $params['currentObjectData'] = new LazyParams($params['currentObjectData']);
            $params['currentObjectData'] = $params['currentObjectData']();
        }

        $v8->input = $params;

        $code = [
            ';(function(params) {',
            $js,
            '})(PHP.input);'
        ];

        return $v8->executeString(implode(PHP_EOL, $code), 'V8Js::pimimport()', \V8Js::FLAG_FORCE_ARRAY);
    }

    /**
     * @return bool
     * Checks if a debugger is installed; for XDebug also if it is currently active
     */
    private static function isDebuggerActive() : bool
    {
        if(extension_loaded('xdebug') && function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active()) {
            return true;
        }

        if(extension_loaded('Zend Debugger') && function_exists('debugger_start_debug')) {
            return true;
        }

        return false;
    }

    public static function hasStaticCachedValue($code) {
        $codeHash = md5($code);
        return array_key_exists($codeHash, self::$staticCache);
    }

    private static function evaluateScriptPHP($code, $params)
    {
        $codeHash = md5($code);
        if(array_key_exists($codeHash, self::$staticCache)) {
            return self::$staticCache[$codeHash];
        }

        if(!isset(self::$functionTranspiled[$codeHash])) {
            if (substr($code, 0, strlen(self::PHP_PREFIX)) === self::PHP_PREFIX) {
                $code = substr($code, strlen(self::PHP_PREFIX));
            }

            if (strpos($code, 'return') === false && strpos($code, "\n") === false && strpos($code, '(') === false && strpos($code, ';') === false) {
                self::$staticCache[$codeHash] = $code;
                self::$functionTranspiled[$codeHash] = static function () use ($code) {
                    return $code;
                };
                return $code;
            }

            $untouchablePlaceholders = [];
            replacePlaceholders:
            $replacements = [];
            $code = preg_replace_callback(
                '/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/',
                static function($match) use ($code, &$replacements, $untouchablePlaceholders, $params) {
                    if(in_array($match[0], $untouchablePlaceholders)) {
                        return $match[0];
                    }
                    $transpiledCode = '($params[\'virtualFields\'][\'__virtual_'.$match[1].'\'] ?? $params[\'virtualFields\'][\''.$match[1].'\'] ?? $params[\'request\']->get(\''.$match[1].'\', null)';
                    if(array_key_exists('rawItemData', $params)) {
                        $transpiledCode .= ' ?? $params[\'rawItemData\'][\''.$match[1].'\'][\'value\']';
                        $rawItemFieldCandidate = str_replace('_', ':', $match[1]);
                        if ($rawItemFieldCandidate !== $match[1]) {
                            $transpiledCode .= ' ?? $params[\'rawItemData\'][\''.$rawItemFieldCandidate.'\'][\'value\']';
                        }
                    }

                    $transpiledCode .= ' ?? \Sylphen\DataBridgeBundle\lib\Pim\Helper::getEnvironmentVariables()[\''.$match[1].'\'] ?? null)';
                    $replacements[$transpiledCode] = $match[0];
                    return $transpiledCode;
                },
                $code
            );

            try {
                token_get_all("<?php\n".$code, TOKEN_PARSE);
            } catch (Throwable $ex) {
                $line = $ex->getLine()-1; // 1st line is <?php
                $codeLines = preg_split('/\r\n|\r|\n/', $code);
                foreach($replacements as $transpiledPlaceholder => $originalPlaceholder) {
                    $transpiledLine = $codeLines[$line-1];
                    $codeLines[$line-1] = str_replace(array_keys($replacements), array_values($replacements), $codeLines[$line-1]);
                    if($transpiledLine !== $codeLines[$line-1]) {
                        $untouchablePlaceholders[] = $originalPlaceholder;
                        $code = implode("\n", $codeLines);

                        goto replacePlaceholders;
                    }
                }
            }

            $code = preg_replace('/if\s*\(\s*(!*)\s*\$params\[\'response\'\]->getContent\s*\(\s*\)\s*\)/i', 'if($1$params[\'response\']->hasContent())', $code);
            $code = preg_replace('/if\s*\(\s*(!*)\s*empty\(\s*\$params\[\'response\'\]->getContent\s*\(\s*\)\s*\)\s*\)/i', 'if(!$1$params[\'response\']->hasContent())', $code);

            $code = preg_replace('/isset\(\$params\[\'currentValue\'\]\)/i', 'array_key_exists(\'currentValue\', $params)', $code);
            $code = preg_replace('/isset\(\$params\[\'currentObjectData\'\]\)/i', 'array_key_exists(\'currentObjectData\', $params)', $code);
            $code = preg_replace('/\$params\[\'currentValue\'\]([^(]|$)/i', '($params[\'currentValue\']())$1', $code);
            $code = preg_replace('/\$params\[\'currentObjectData\'\]([^(]|$)/i', '$params[\'currentObjectData\']()$1', $code);

            $strictTypesSet = false;
            $code = preg_replace_callback('/declare\s*\(\s*strict_types\s*=\s*(0|1)\s*\);/i', function($match) use (&$strictTypesSet) {
                $strictTypesSet = true;
                return 'declare(strict_types='.$match[1].'); use Sylphen\DataBridgeBundle\lib\Pim\Import as DD; ';
            }, $code);
            if(!$strictTypesSet) {
                $code = 'declare(strict_types=0); use Sylphen\DataBridgeBundle\lib\Pim\Import as DD; '.$code;
            }

            require_once __DIR__.'/helpers.php';

            if (!in_array('eval', explode(',', ini_get('disable_functions')), true) && !static::isDebuggerActive()) {
                self::$functionTranspiled[$codeHash] = static function ($params) use ($code) {
                    return eval($code);
                };
            } else {
                $tmpFilePath = sprintf(
                    '%s/data-bridge-callback-%s.%s',
                    Installer::getCachePath(),
                    md5($code),
                    'php'
                );
                if (!file_exists($tmpFilePath)) {
                    file_put_contents(
                        $tmpFilePath,
                        '<?php
                '.$code
                    );
                }

                self::$functionTranspiled[$codeHash] = static function ($params) use ($tmpFilePath) {
                    $return = include $tmpFilePath;

                    if ($return !== 1) {
                        return $return;
                    }

                    return null;
                };
            }
        }

        if(isset($params['rawItemData']) && is_array($params['rawItemData'] ?? []) && count($params['rawItemData']) > 0) {
            $params['rawItemData'] = new \Sylphen\DataBridgeBundle\lib\Pim\Item\RawItem($params['rawItemData']);
        }

        if(array_key_exists('currentValue', $params)) {
            $params['currentValue'] = new LazyParams($params['currentValue']);
        }

        if (array_key_exists('currentObjectData', $params)) {
            $params['currentObjectData'] = new LazyParams($params['currentObjectData']);
        }

        try {
            self::$warnings = [];
            set_error_handler([self::class, 'errorHandler']);

            $result = self::$functionTranspiled[$codeHash]($params);

            if(strpos($code, '$params') === false && strpos($code, '(') === false && strpos($code, '{') === false) {
                self::$staticCache[$codeHash] = $result;
            }
            return $result;
        } catch(\Throwable $e) {
            $codeLines = explode("\n", $code);
            if (isset($codeLines[$e->getLine() - 1])) {
                $codeLines[$e->getLine() - 1] = '>>> '.$codeLines[$e->getLine() - 1].' <<<';
                $codeLines = array_slice($codeLines, max(0, $e->getLine() - 1 - 5), 10);
            }

            throw new RuntimeException('Error "'.$e->getMessage().'" in callback function'.(!empty($params['field'])?' for field "'.$params['field'].'"':'').', line '.$e->getLine().': '.PHP_EOL.implode("\n", $codeLines), 0);
        } finally {
            restore_error_handler();

            foreach(self::$warnings as $errorLevelCode => $errors) {
                foreach($errors as $error) {
                    $errorHappenedInCallbackFunction = strpos($error['file'], __FILE__) === 0;

                    $codeLines = '';
                    if ($errorHappenedInCallbackFunction) {
                        $stackTrace = array_reverse(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                        $callbackFunctionOriginIndex = null;
                        foreach ($stackTrace as $index => $stack) {
                            if (isset($stack['file']) && strpos($stack['file'], __FILE__) === 0 && $stack['function'] === 'eval') {
                                $callbackFunctionOriginIndex = $index;
                                break;
                            }
                        }

                        if($callbackFunctionOriginIndex === null) {
                            $stackTrace = [['file' => 'callback function', 'line' => 0]];
                        } else {
                            $stackTrace = array_slice($stackTrace, $callbackFunctionOriginIndex ? $callbackFunctionOriginIndex + 1 : 1);
                        }

                        if (count($stackTrace) === 1) {
                            $codeLines = explode("\n", $code);
                            if (isset($codeLines[$error['line'] - 1])) {
                                $codeLines[$error['line'] - 1] = '>>> '.$codeLines[$error['line'] - 1].' <<<';
                                $codeLines = array_slice($codeLines, max(0, $error['line'] - 1 - 5), 10);
                            } else {
                                $errorHappenedInCallbackFunction = false;
                            }
                        } else {
                            $error['line'] = $stackTrace[0]['line'];
                            $codeLines = array_map(static function ($stack) {
                                return $stack['function'].(!empty($stack['line']) ? ', line '.$stack['line'] : '');
                            }, $stackTrace);
                            array_unshift($codeLines, 'Follow-up call stack: ');
                        }
                    }

                    trigger_error(
                        $error['level'].' "'.$error['error'].'" in '.($errorHappenedInCallbackFunction ? 'callback function' : $error['file']).(!empty($params['field']) ? ' for field "'.$params['field'].'"' : '').', line '.$error['line'].($errorHappenedInCallbackFunction ? ': '.PHP_EOL.implode("\n", $codeLines) : ''),
                        $errorLevelCode
                    );
                }
            }

            self::$warnings = [];
        }
    }

    public static function errorHandler($errorLevelCode, $errstr, $errfile, $errline) {
        // error was suppressed with the @-operator
        if ((PHP_VERSION_ID < 80000 && 0 === error_reporting()) || (PHP_VERSION_ID >= 80000 && (!(error_reporting() & $errorLevelCode)))) {
            return false;
        }

        if ($errorLevelCode === E_WARNING) {
            $errorLevelCode = E_USER_WARNING;
        } elseif ($errorLevelCode === E_ERROR) {
            $errorLevelCode = E_USER_ERROR;
        } elseif ($errorLevelCode === E_NOTICE) {
            $errorLevelCode = E_USER_NOTICE;
        } elseif ($errorLevelCode === E_DEPRECATED) {
            $errorLevelCode = E_USER_DEPRECATED;
        }

        if ($errorLevelCode === E_USER_DEPRECATED) {
            return true;
        }

        $errorLevel = 'Error';
        $errorLevelConstants = array_flip(
            array_slice(
                get_defined_constants(true)['Core'],
                0,
                15,
                true
            )
        );

        foreach ($errorLevelConstants as $errorLevelCodeConst => $name) {
            if ($errorLevelCode & $errorLevelCodeConst) {
                $errorLevel = $name;
                break;
            }
        }

        if (!isset($warnings[$errorLevelCode])) {
            self::$warnings[$errorLevelCode] = [];
        }

        self::$warnings[$errorLevelCode][] = ['level' => $errorLevel, 'error' => $errstr, 'file' => $errfile, 'line' => $errline];

        return true;
    }

    public static function clearCache() {
        self::$staticCache = [];
    }
}
