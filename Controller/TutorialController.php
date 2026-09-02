<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use OpenDxp\Controller\FrontendController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * @Route("/{bundle}/tutorial", defaults={"bundle"="SylphenDataBridge"}, requirements={"bundle": "SylphenDataBridge"})
 */
class TutorialController extends FrontendController
{
    /**
     * @Route("/", methods={"GET"})
     */
    public function indexAction() {
        return $this->render('@SylphenDataBridge/Tutorial/index.html.twig');
    }
}