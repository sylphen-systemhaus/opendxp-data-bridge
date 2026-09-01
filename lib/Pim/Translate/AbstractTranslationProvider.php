<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;

use Sylphen\DataBridgeBundle\lib\Pim\Item\TranslationHelper;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use OpenDxp\Model\Translation;
use OpenDxp\Tool;
use OpenDxp\Translation\Translator;

abstract class AbstractTranslationProvider implements TranslationProvider
{
    public static function translateText(string $term, string $locale = null, string $sourceLanguage = null, $translateOptions = []): string
    {
        $translator = OpenDxp::getContainer()->get(TranslationHelper::class);
        if ($sourceLanguage === null) {
            $translation = $translator->translate($term, $locale);

            if ($translation !== (string)$term) {
                return $translation;
            }
        }

        if ($sourceLanguage !== null && !empty($term)) {
            $term = self::preProcessTranslation($term, $sourceLanguage);

            $cacheKey = 'import_translation_'.$sourceLanguage.'_'.md5($term);
            $translationObject = OpenDxp\Model\Translation::getByKey($cacheKey, 'DataBridge_Cache');
            if ($translationObject === null) {
                if (method_exists(OpenDxp\Model\Translation::class, 'setKey')) {
                    $translationObject = new OpenDxp\Model\Translation();
                } else {
                    $translationObject = new OpenDxp\Model\Translation\Website();
                }

                if (method_exists($translationObject, 'setDomain')) {
                    $translationObject->setDomain('DataBridge_Cache');
                }

                $translationObject->setKey($cacheKey);
                $translationObject->setCreationDate(time());
                $translationObject->setModificationDate(time());

                $systemSourceLanguage = $sourceLanguage;
                if (!Tool::isValidLanguage($systemSourceLanguage)) {
                    foreach (Tool::getValidLanguages() as $language) {
                        if (strpos($language, $systemSourceLanguage) === 0) {
                            $systemSourceLanguage = $language;
                            break;
                        }
                    }
                }
                $translationObject->addTranslation($systemSourceLanguage, $term);
            }

            $cacheContent = null;
            $translationProvider = OpenDxp::getContainer()->get(TranslationProvider::class);

            if($translationObject->hasTranslation($locale)) {
                if(
                    !method_exists($translationProvider, 'isCachingAllowed') ||
                    $translationProvider->isCachingAllowed($translationObject, $sourceLanguage, $locale))
                {
                    $cacheContent = $translationObject->getTranslation($locale);
                }
            }

            if ($cacheContent) {
                return self::postProcessTranslation($cacheContent, $sourceLanguage, $locale);
            }

            $value = $translationProvider->translate($term, $locale, $sourceLanguage, preg_match('/<[^<]+>/', $term), $translateOptions) ?? null;

            if ($value != $term) {
                $translationObject->addTranslation($locale, $value);
                $translationObject->save();
            }

            $value = self::postProcessTranslation($value, $sourceLanguage, $locale);
            return $value;
        }

        return $term;
    }

    /**
     * wrap skip_translation_tag around values from glossary
     * @param string $text
     * @param string $languageFrom
     * @return string
     */
    private static function preProcessTranslation($text, $languageFrom)
    {
        $translationProvider = OpenDxp::getContainer()->get(TranslationProvider::class);
        if($translationProvider::supportsGlossaries()) {
            return $text;
        }

        $skipTranslationTag = \OpenDxp::getContainer()->getParameter('sylphen_data_bridge.skip_translation_tag');

        $languages = Tool::getValidLanguages();
        $languagesFrom = array_filter($languages, static function ($language) use ($languageFrom) {
            return stripos($language, strtolower(substr($languageFrom, 0, 2))) === 0;
        });

        /** @var Translator $translator */
        $translator = OpenDxp::getContainer()->get('translator');

        preg_match_all('~<'.$skipTranslationTag.'>([^<]+)</'.$skipTranslationTag.'>~', $text, $manuallyIgnoredTerms);
        foreach (array_filter($manuallyIgnoredTerms) as $manuallyIgnoredTerm) {
            $existingManualTranslation = OpenDxp\Model\Translation::getByKey($manuallyIgnoredTerm[1], 'DataBridge_Glossary');
            if($existingManualTranslation === null) {
                $existingManualTranslation = OpenDxp\Model\Translation::getByKey('translate.'.$manuallyIgnoredTerm[1]);
            }

            if ($existingManualTranslation === null) {
                if (method_exists(OpenDxp\Model\Translation::class, 'setKey')) {
                    $manualTranslation = new OpenDxp\Model\Translation();
                } else {
                    $manualTranslation = new OpenDxp\Model\Translation\Website();
                }

                if(method_exists($manualTranslation, 'setDomain')) {
                    $manualTranslation->setDomain('DataBridge_Glossary');
                    $manualTranslation->setKey($manuallyIgnoredTerm[1]);
                } else {
                    $manualTranslation->setKey('translate.'.$manuallyIgnoredTerm[1]);
                }

                $manualTranslation->setCreationDate(time());
                $manualTranslation->setModificationDate(time());
                foreach ($languagesFrom as $languageFrom) {
                    $manualTranslation->addTranslation($languageFrom, $translator->trans($manuallyIgnoredTerm[1], [], null, $languageFrom));
                }
                $manualTranslation->save();
            }
        }

        $translations = self::getGlossaryItems();

        foreach ($translations as $translation) {
            foreach ($languagesFrom as $languageFrom) {
                $ignoredTerm = trim($translation->getTranslation($languageFrom));
                if ((string)$ignoredTerm === '') {
                    $ignoredTerm = substr($translation->getKey(), strlen('translate.'));
                }
                if ($ignoredTerm) {
                    $text = preg_replace(
                        '/(^|[^\p{L}\p{Nd}])('.preg_quote($ignoredTerm, '/').')($|[^\p{L}\p{Nd}])/u',
                        '$1<'.$skipTranslationTag.'>$2</'.$skipTranslationTag.'>$3',
                        $text
                    );
                    break;
                }
            }
        }

        return $text;
    }

    /**
     * translate values manually via glossary translations
     * @param string $text
     * @param string $languageFrom
     * @param string $languageTo
     * @return string
     */
    private static function postProcessTranslation($text, $languageFrom, $languageTo)
    {
        $translationProvider = OpenDxp::getContainer()->get(TranslationProvider::class);
        if ($translationProvider::supportsGlossaries()) {
            if (!preg_match('/<[^<]+>/', $text)) {
                $text = html_entity_decode($text, ENT_NOQUOTES | ENT_HTML5);
            }
            return $text;
        }

        $skipTranslationTag = \OpenDxp::getContainer()->getParameter('sylphen_data_bridge.skip_translation_tag');

        $languages = Tool::getValidLanguages();
        $languagesFrom = array_filter(
            $languages,
            static function ($language) use ($languageFrom) {
                return stripos($language, strtolower(substr($languageFrom, 0, 2))) === 0;
            }
        );

        /** @var Translator $translator */
        $translator = OpenDxp::getContainer()->get('translator');
        foreach (self::getGlossaryItems() as $translation) {
            $translatedTerm = $translation->getTranslation($languageTo);
            if ((string)$translatedTerm === '') {
                $translatedTerm = $translation->getTranslation($languageFrom);
                if ((string)$translatedTerm === '') {
                    $translatedTerm = substr($translation->getKey(), strlen('translate.'));
                }
            }

            if (trim($translatedTerm)) {
                foreach ($languagesFrom as $languageFrom) {
                    $ignoredTerm = $translation->getTranslation($languageFrom);
                    if ((string)$ignoredTerm === '') {
                        $ignoredTerm = substr($translation->getKey(), strlen('translate.'));
                    }

                    $text = str_replace(
                        '<'.$skipTranslationTag.'>'.$ignoredTerm.'</'.$skipTranslationTag.'>',
                        '<'.$skipTranslationTag.'>'.$translatedTerm.'</'.$skipTranslationTag.'>',
                        $text
                    );
                }
            }
        }

        $text = str_replace(['<'.$skipTranslationTag.'>', '</'.$skipTranslationTag.'>'], '', $text);
        if (!preg_match('/<[^<]+>/', $text)) {
            $text = html_entity_decode($text, ENT_NOQUOTES | ENT_HTML5);
        }
        return $text;
    }

    public static function supportsGlossaries(): bool
    {
        return false;
    }

    /**
     * @return array|Translation[]
     */
    public static function getGlossaryItems(): array
    {
        $glossaryData = PimcoreDbRepository::getInstance()->findInSql('SELECT `key`, language, text FROM '.Installer::TABLE_TRANSLATION_GLOSSARY.' ORDER BY LENGTH(text) DESC');
        $translations = [];
        if ($glossaryData) {
            foreach ($glossaryData as $glossaryItem) {
                if (!isset($translations[$glossaryItem['key']])) {
                    if (method_exists(OpenDxp\Model\Translation::class, 'setKey')) {
                        $translations[$glossaryItem['key']] = new OpenDxp\Model\Translation();
                    } else {
                        $translations[$glossaryItem['key']] = new OpenDxp\Model\Translation\Website();
                    }

                    if (method_exists($translations[$glossaryItem['key']], 'setDomain')) {
                        $translations[$glossaryItem['key']]->setDomain('DataBridge_Glossary');
                    }
                    $translations[$glossaryItem['key']]->setKey($glossaryItem['key']);
                    $translations[$glossaryItem['key']]->setCreationDate(time());
                    $translations[$glossaryItem['key']]->setModificationDate(time());
                }

                $translations[$glossaryItem['key']]->addTranslation($glossaryItem['language'], $glossaryItem['text']);
            }
        }

        if (count($translations) === 0) {
            $list = new OpenDxp\Model\Translation\Listing();
            $list->setCondition('`key` LIKE \'translate.%\'');
            $list->setOrderKey('LENGTH(text)', false);
            $list->setOrder('DESC');
            $translations = $list->load();
        }
        return $translations;
    }
}