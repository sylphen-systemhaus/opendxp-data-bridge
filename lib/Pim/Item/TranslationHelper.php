<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use OpenDxp\Model\Translation;
use OpenDxp\Tool;
use OpenDxp\Tool\Admin;
use Symfony\Contracts\Translation\TranslatorInterface;

class TranslationHelper
{
    /** @var TranslatorInterface */
    private $translator;

    /**
     * @param TranslatorInterface|\OpenDxp\Translation\Translator $translator
     */
    public function __construct($translator)
    {
        $this->translator = $translator;
    }

    public function translate(string $originalId, string $locale = null, array $parameters = [], string $domain = null) {
        if($domain === null) {

            //cut the id due to limitation of the translation key length in Pimcore
            if (strlen($originalId) > 190) {
                $newId = substr($originalId, 0, 158). md5($originalId);
                $oldId = substr($originalId, 0, 190);
            }
            else {
                $newId = $originalId;
                $oldId = $originalId;
            }

            $translation = $this->translate($newId, $locale, $parameters, Translation::DOMAIN_DEFAULT);
            if ($translation != $newId) {
                return $translation;
            }

            //try with old id for backwards compatibility, if the id was shortened
            $translation = $this->translate($oldId, $locale, $parameters, Translation::DOMAIN_DEFAULT);
            if ($translation != $oldId) {
                return $translation;
            }

            $translation = $this->getTranslationWithDefaultLocale($newId, $locale, $parameters);
            if ($translation != $newId) {
                return $translation;
            }

            //try with old id for backwards compatibility, if the id was shortened
            $translation = $this->getTranslationWithDefaultLocale($oldId, $locale, $parameters);
            if ($translation != $oldId) {
                return $translation;
            }

            return $originalId;
        }

        return $this->translator->trans($originalId, $parameters, $domain, $locale);
    }

    /**
     * @param Translation|null $translationObject
     * @param string $id
     * @return void
     */
    private function saveDefaultTranslationIfNotExists($translationObject = null, string $id = ''): void
    {
        if ($translationObject instanceof Translation && !$translationObject->hasTranslation(Tool::getDefaultLanguage())) {
            $translationObject->addTranslation(Tool::getDefaultLanguage(), $id);
            $translationObject->save();
        }
    }

    private function getTranslationWithDefaultLocale($id, $locale, $parameters): string
    {
        $translationObject = Translation::getByKey($id, Translation::DOMAIN_DEFAULT);
        $this->saveDefaultTranslationIfNotExists($translationObject, $id);

        if(!$locale) {
            $locale = \OpenDxp::getContainer()->get('opendxp.locale')->getLocale();
        }

        if(!in_array($locale, Admin::getLanguages(), true)) {
            $locale = substr($locale, 0, 2);
        }

        return $this->translate($id, $locale, $parameters, Translation::DOMAIN_ADMIN);
    }
}