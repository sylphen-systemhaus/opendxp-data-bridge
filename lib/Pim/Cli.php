<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;


use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp;
use OpenDxp\File;
use OpenDxp\Logger;
use OpenDxp\Tool\Console;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Cli
{
    /** @var null|string */
    private static $systemEnvironment = null;

    private static $application = null;

    private static $executables = [];

    /**
     * @param string $cmd
     * @param string|null $outputFile
     *
     * @return string
     */
    public static function exec($cmd, $outputFile = null)
    {
        if (strpos($cmd, 'data-bridge:') === 0 || strpos($cmd, 'data-bridge:') === 0 || strpos($cmd, 'import:') === 0) {
            $importer = \OpenDxp::getContainer()->get(ImporterInterface::class);
            $dataport = $importer->getDataport();
            $itemCache = $importer->getItemCache();
            $listCache = $importer->getListCacheObject();
            $relationCache = $importer->getRelationCache();
            $writeBuffer = $importer->getWriteBuffer();
            $pruneCacheIds = $importer->getPruneCacheIds();
            $rawItems = $importer->getRawItems();
            $fileObject = \OpenDxp::getContainer()->get('pim.logger')->getLogFileObject();
            $parameters = Helper::getRequest()->attributes->all();
            if ($fileObject instanceof FileObject) {
                \OpenDxp::getContainer()->get('pim.logger')->info('Executing command "'.$cmd.'" (Started at '.date('Y-m-d H:i:s').')');
            }

            if(self::$application === null) {
                self::$application = new OpenDxp\Console\Application(OpenDxp::getKernel());
                self::$application->setAutoExit(false);
            }

            $input = new StringInput(escapeshellcmd($cmd));

            Helper::clearEnvironmentVariables();
            CallbackFunction::clearCache();

            $output = new BufferedOutput();
            self::$application->run($input, $output);

            $logger = \OpenDxp::getContainer()->get('pim.logger');
            if($fileObject instanceof FileObject) {
                $logger->setLogFileObject($fileObject);
            }

            if($dataport) {
                $importer->setDataport($dataport);
            }

            $importer->setItemCache($itemCache);
            $importer->setListCacheObject($listCache);
            $importer->setRelationCache($relationCache);
            $importer->setWriteBuffer($writeBuffer);
            $importer->setPruneCacheIds($pruneCacheIds);
            $importer->setRawItems($rawItems);
            Helper::getRequest()->attributes->add($parameters);

            if($outputFile) {
                file_put_contents($outputFile, $output->fetch());
                return;
            }
            return $output->fetch();
        }

        $returnOutput = false;
        if ($outputFile === null) {
            $outputFile = sprintf(
                '%s/temp-file-%s.%s',
                OPENDXP_SYSTEM_TEMP_DIRECTORY,
                uniqid().'-'.bin2hex(random_bytes(15)),
                'tmp'
            );
            $returnOutput = true;
            register_shutdown_function(static function () use ($outputFile) {
                if (file_exists($outputFile)) {
                    unlink($outputFile);
                }
            });
        }

        $cmd .= ' > "'.$outputFile.'" 2>&1';
        Logger::debug('Executing command `'.$cmd.'` on the current shell');
        chdir(OPENDXP_PROJECT_ROOT);

        if (in_array('shell_exec', explode(',', ini_get('disable_functions')), true)) {
            $process = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($cmd, null, null, null, null) : new Process($cmd, null, null, null, null);
            $process->run();

            if ($returnOutput) {
                return $process->getOutput();
            }
        }

        shell_exec($cmd);

        if ($returnOutput) {
            return file_get_contents($outputFile);
        }
    }

    /**
     * @static
     *
     * @param string $cmd
     * @param null|string $outputFile
     *
     * @return int
     */
    public static function execInBackground($cmd, $outputFile = null)
    {
        if (strpos($cmd, 'data-bridge:') === 0 || strpos($cmd, 'data-bridge:') === 0 || strpos($cmd, 'import:') === 0) {
            $cmd = '"'.self::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').' '.$cmd;
        }

        chdir(OPENDXP_PROJECT_ROOT);

        // windows systems
        if (self::getSystemEnvironment() === 'windows') {
            return self::execInBackgroundWindows($cmd, $outputFile);
        }

        if (self::getSystemEnvironment() === 'darwin') {
            return self::execInBackgroundUnix($cmd, $outputFile, false);
        }

        return self::execInBackgroundUnix($cmd, $outputFile);
    }

    protected static function execInBackgroundUnix($cmd, $outputFile, $useNohup = true)
    {
        if (!$outputFile) {
            $outputFile = '/dev/null';
        }

        $nice = (string)self::getExecutable('nice');
        if ($nice) {
            $nice .= ' -n 19 ';
        }

        if ($useNohup) {
            $nohup = (string)self::getExecutable('nohup');
            if ($nohup) {
                $nohup .= ' ';
            }
        } else {
            $nohup = '';
        }

        /**
         * mod_php seems to lose the environment variables if we do not set them manually before the child process is started
         */
        if (strpos(php_sapi_name(), 'apache') !== false) {
            foreach (['PIMCORE_ENVIRONMENT', 'APP_ENV'] as $envVarName) {
                if ($envValue = $_SERVER[$envVarName] ?? $_SERVER['REDIRECT_'.$envVarName] ?? null) {
                    putenv($envVarName.'='.$envValue);
                }
            }
        }

        $commandWrapped = $nohup.$nice.$cmd.' > '.$outputFile.' 2>&1 & echo $!';
        Logger::debug('Executing command `'.$commandWrapped.'´ on the current shell in background');

        if(in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
            $process = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($commandWrapped, null, null, null, null) : new Process($commandWrapped, null, null, null, null);
            $process->start();
            $pid = $process->getPid();
            $process->wait();
        } else {
            $pid = shell_exec($commandWrapped);
        }

        return (int)$pid;
    }

    /**
     * @static
     *
     * @param string $cmd
     * @param string $outputFile
     *
     * @return int
     */
    protected static function execInBackgroundWindows($cmd, $outputFile)
    {
        if (!$outputFile) {
            $outputFile = 'NUL';
        }

        $commandWrapped = 'cmd /c '.$cmd.' > '.$outputFile.' 2>&1';
        Logger::debug('Executing command `'.$commandWrapped.'´ on the current shell in background');

        $WshShell = new \COM('WScript.Shell');
        $WshShell->Run($commandWrapped, 0, false);
        // returning the PID is not supported on Windows Systems

        return 0;
    }

    public static function getPhpCli()
    {
        try {
            if (\OpenDxp::getContainer()->hasParameter('opendxp_executable_php')) {
                $executablePath = \OpenDxp::getContainer()->getParameter('opendxp_executable_php');

                if ($executablePath) {
                    return $executablePath;
                }
            }

            $phpFinder = new PhpExecutableFinder();
            $phpPath = $phpFinder->find(true);
            if ($phpPath && strpos($phpPath, '-cgi') === false) {
                return $phpPath;
            }

            $phpPath = Console::getPhpCli();
            if($phpPath && strpos($phpPath, '-cgi') === false) {
                return $phpPath;
            }
            throw new \RuntimeException('The php executable could not be found, add it to your PATH environment variable and try again');
        } catch(\Exception $e) {
            $checkCmd = 'which php';
            if (self::getSystemEnvironment() === 'windows') {
                $checkCmd = 'where php';
            }

            if (in_array('shell_exec', explode(',', ini_get('disable_functions')), true)) {
                symfonyProcess:
                $process = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($checkCmd, null, null, null, null) : new Process($checkCmd, null, null, null, null);
                $process->run();
                $executablePath = $process->getOutput();
            } else {
                $executablePath = shell_exec($checkCmd);

                if(!$executablePath) {
                    goto symfonyProcess;
                }
            }

            $executablePath = trim(strtok($executablePath, "\n")); // get the first line/result

            if ($executablePath) {
                return $executablePath;
            }

            throw new \RuntimeException('The php executable could not be found, add it to your PATH environment variable and try again');
        }
    }

    /**
     * @return string
     */
    private static function getSystemEnvironment()
    {
        if (self::$systemEnvironment === null) {
            if (stripos(php_uname('s'), 'windows') !== false) {
                self::$systemEnvironment = 'windows';
            } elseif (stripos(php_uname('s'), 'darwin') !== false) {
                self::$systemEnvironment = 'darwin';
            } else {
                self::$systemEnvironment = 'unix';
            }
        }

        return self::$systemEnvironment;
    }

    public static function getExecutable($name, $throwException = false) {
        if(!array_key_exists($name, self::$executables)) {
            self::$executables[$name] = null;
            try {
                self::$executables[$name] = Console::getExecutable($name, $throwException);
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'executable was disabled manually') !== false) {
                    throw $e;
                }
            }

            if (!self::$executables[$name]) {
                $checkCmd = 'which '.escapeshellarg($name);

                if (in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
                    $process = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($checkCmd, null, null, null, null) : new Process($checkCmd, null, null, null, null);
                    $process->run();
                    $executablePath = $process->getOutput();
                } else {
                    $executablePath = shell_exec($checkCmd);
                }

                self::$executables[$name] = trim(strtok($executablePath, "\n"));
            }
        }

        if (!self::$executables[$name] && $throwException) {
            throw new \Exception("No '$name' executable found, please install the application or add it to the PATH (in system settings or to your PATH environment variable)");
        }

        return self::$executables[$name];
    }
}