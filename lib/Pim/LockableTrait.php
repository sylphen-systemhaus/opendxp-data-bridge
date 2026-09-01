<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;


use OpenDxp;
use OpenDxp\Db;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Lock\Lock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\FlockStore;

trait LockableTrait
{
    /** @var Lock|null */
    private static $locks = [];

    /**
     * Locks a command.
     */
    private function lock(?string $name = null, bool $blocking = false, $ttl = 4 * 3600): bool
    {
        if ($name === null) {
            $name = get_class($this);
        }

        if(!isset(self::$locks[$name])) {
            try {
                $lockFactory = OpenDxp::getContainer()->get(LockFactory::class);
            } catch (\Throwable $e) {
                $store = new FlockStore(OPENDXP_SYSTEM_TEMP_DIRECTORY);
                $lockFactory = new LockFactory($store);
            }

            self::$locks[$name] = $lockFactory->createLock($name, $ttl);

            if (!self::$locks[$name]->acquire($blocking)) {
                return false;
            }

            $abortFunction = function () use ($name) {
                $this->release($name);
            };
            register_shutdown_function($abortFunction);
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);

                pcntl_signal(SIGINT, $abortFunction); // SIGINT is sent by the TTY driver to the current foreground job when the interactive attention character (typically ^C, which has ASCII code 3) appears in the input stream
                pcntl_signal(SIGTERM, $abortFunction);
                pcntl_signal(SIGHUP, $abortFunction); // SIGHUP is sent by the UART driver to the entire session when a hangup condition has been detected.
            }
        } else {
            return self::$locks[$name]->isExpired();
        }

        return true;
    }

    /**
     * Releases the command lock if there is one.
     */
    private function release(?string $name = null)
    {
        if ($name === null) {
            $name = get_class($this);
        }

        if (isset(self::$locks[$name])) {
            self::$locks[$name]->release();
            unset(self::$locks[$name]);
        }
    }

    private function refreshLock(?string $name = null) {
        if ($name === null) {
            $name = get_class($this);
        }

        if(!isset(self::$locks[$name])) {
            $this->lock($name);
        }

        return self::$locks[$name]->refresh();
    }
}