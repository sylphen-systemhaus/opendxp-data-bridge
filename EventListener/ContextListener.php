<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use OpenDxp\Http\Request\Resolver\OpenDxpContextResolver;
use OpenDxp\Http\RequestHelper;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class ContextListener
{
    /**
     * @param RequestEvent|\Symfony\Component\HttpKernel\Event\GetResponseEvent $event
     * @return void
     */
    public function onKernelRequest($event)
    {
        $request = $event->getRequest();

        if (strpos($request->getPathInfo(), '/api/rest') === 0) {
            $request->attributes->set(OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, 'webservice');
            $request->attributes->set(RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);
        } elseif (strpos($request->getPathInfo(), '/admin/SylphenDataBridgeBundle/adminer') === 0 || strpos($request->getPathInfo(), '/adminer') === 0) {
            $request->attributes->set(OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, 'adminer');
        } elseif (strpos($request->getPathInfo(), '/SylphenDataBridge') === 0) {
            $request->attributes->set(OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, 'data_bridge');
        }
    }
}