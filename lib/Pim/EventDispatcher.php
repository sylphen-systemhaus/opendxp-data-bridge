<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EventDispatcher
{
    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    public function __construct($eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function dispatch($event, $eventName = null) {
        if(\version_compare(Kernel::VERSION, '4') >= 0) {
            $this->eventDispatcher->dispatch($event, $eventName);
        } else {
            $this->eventDispatcher->dispatch($eventName, $event);
        }
    }

    public function hasListeners($eventName) {
        if(method_exists($this->eventDispatcher, 'getListeners')) {
            return $this->eventDispatcher->hasListeners($eventName);
        }

        return true;
    }
}