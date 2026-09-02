<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use OpenDxp\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;

class DocumentController extends FrontendController
{
    public function pageAction(Request $request)
    {
        return $this->render('@SylphenDataBridge/document/page.html.twig', ['dachcomToolboxInstalled' => class_exists(\ToolboxBundle\ToolboxBundle::class)]);
    }
}