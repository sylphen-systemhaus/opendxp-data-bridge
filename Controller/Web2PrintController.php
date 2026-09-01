<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use OpenDxp\Controller\FrontendController;
use OpenDxp\Model\Document\Hardlink;
use OpenDxp\Tool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

class Web2PrintController extends FrontendController
{
    public function onKernelResponse(FilterResponseEvent $event)
    {
        if (!$this->editmode) {
            $response = $event->getResponse();
            $response->setContent(\OpenDxp\Helper\Mail::setAbsolutePaths($response->getContent(), $this->document, \OpenDxp\Tool::getHostUrl('https')));
        }
    }

    public const DD_AREABRICK_ELEMENTS_FIELD = 'dd_elements';

    /**
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function containerAction(Request $request)
    {
        $paramsBag = [
            'document' => $this->document
        ];

        foreach ($request->attributes as $key => $value) {
            $paramsBag[$key] = $value;
        }

        $allChildren = [];

        //prepare children for include
        foreach ($this->document->getAllChildren() as $child) {
            if ($child instanceof Hardlink) {
                $child = Hardlink\Service::wrap($child);
            }

            $child->setProperty('hide-layout', 'bool', true, false, true);

            $allChildren[] = $child;
        }

        $paramsBag['allChildren'] = $allChildren;

        return $this->render('@SylphenDataBridge/web2print/container.html.twig', $paramsBag);
    }

    public function pageAction(Request $request)
    {
        $params = [
            'elementsField' => self::DD_AREABRICK_ELEMENTS_FIELD,
            'hostUrl' => Tool::getHostUrl(),
        ];

        return $this->render('@SylphenDataBridge/web2print/page.html.twig', $params);
    }
}