<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\AdminStyle\GridViewFieldsAdminStyle;
use OpenDxp\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use OpenDxp\Model\Element\AdminStyle;
use OpenDxp\Translation\Translator;

class AdminStyleListener
{
    /** @var Translator */
    private $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @param ElementAdminStyleEvent|\OpenDxp\Event\Admin\ElementAdminStyleEvent $event
     * @return void
     */
    public function onResolveElementAdminStyle($event)
    {
        if(!$event->getAdminStyle() instanceof AdminStyle) {
            return;
        }

        $event->setAdminStyle(new GridViewFieldsAdminStyle($event->getElement(), $this->translator, $event->getAdminStyle()));
    }
}