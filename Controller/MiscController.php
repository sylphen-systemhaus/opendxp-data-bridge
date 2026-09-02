<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use OpenDxp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MiscController
{
    /**
     * @Route("/SylphenDataBridge/translation-language-icons")
     *
     * those language icons are necessary for DeepL icons but Pimcore does not create all of them in admin_css.html.twig
     */
    public function translationLanguageIconsAction(Request $request)
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'text/css');

        $css = '';
        foreach (MappingconfigController::getAutotranslateLanguages() as &$languageItem) {
            $css .= '
.dd_icon_language_'.strtolower($languageItem['value']).' {
    background: url('.\OpenDxp\Bundle\AdminBundle\Tool::getLanguageFlagFile($languageItem['value'], false).') center center/contain no-repeat;
}';
        }
        unset($languageItem);

        $response->setContent($css);

        return $response;
    }
    /**
     * @Route("/SylphenDataBridge/read-gui-translation-details")
     */
    public function readTranslationDetailsAction(Request $request)
    {
        $guiTranslationDetails = OpenDxp::getContainer()->getParameter('data_bridge.config')['gui_translation'] ?? [];
        if (!is_array($guiTranslationDetails) || empty($guiTranslationDetails)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error reading data',
                'data' => [],
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'OK',
            'data' => $guiTranslationDetails,
        ]);
    }

    /**
     * @Route("/SylphenDataBridge/read-ajax-timeout-from-config")
     */
    public function readAjaxTimeoutFromConfigAction(Request $request)
    {
        $timeout = OpenDxp::getContainer()->getParameter('data_bridge.config')['ajax_timeout'] ?? null;
        if (!is_int($timeout)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error reading data',
                'data' => 30,
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'OK',
            'data' => $timeout,
        ]);
    }
}