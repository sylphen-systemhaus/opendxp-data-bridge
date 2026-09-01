<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;


use Sylphen\DataBridgeBundle\lib\Pim\Translate\AbstractTranslationProvider;
use Sylphen\DataBridgeBundle\lib\Pim\Translate\DeepL;
use Sylphen\DataBridgeBundle\lib\Pim\Translate\TranslationProvider;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use OpenDxp\Event\Model\TranslationEvent;
use OpenDxp\Model\Tool\TmpStore;
use OpenDxp\Model\Translation;
use OpenDxp\Model\WebsiteSetting;
use OpenDxp\Tool;
use Symfony\Component\EventDispatcher\GenericEvent;

class TranslationListener
{
    /** @var TranslationProvider */
    private $translationprovider;

    public function __construct(TranslationProvider $translationProvider)
    {
        $this->translationprovider = $translationProvider;
    }

    /**
     * @param TranslationEvent|GenericEvent $event
     */
    public function updateGlossary($event)
    {
        if($event instanceof TranslationEvent) {
            $translation = $event->getTranslation();
            if(!$translation instanceof Translation || !method_exists($translation, 'getDomain') || $translation->getDomain() !== 'DataBridge_Glossary') {
                return;
            }
        }

        try {
            $dataportId = $event->getArgument('dataportId');

            if ($event instanceof TranslationEvent) {
                $translation = $event->getTranslation();
                if ($translation->getKey() === Dataport::getConfigurationPermissionName($dataportId)) {
                    return;
                }
            }
        } catch (\Exception $e) {
            $dataportId = null;
        }

        $fieldMappingModel = Fieldmapping::getInstance();
        $fieldMappingConditions = [
            'format LIKE ?' => '%"translateFromLanguage";s:2%',
        ];
        if ($dataportId) {
            $fieldMappingConditions['dataportId = ?'] = $dataportId;
        }

        $glossaryLanguagePairs = [];

        $translationFieldMappings = $fieldMappingModel->find($fieldMappingConditions);
        foreach($translationFieldMappings as $fieldMapping) {
            $translationSourceLanguage = unserialize($fieldMapping['format'], ['allowed_classes' => false])['translateFromLanguage'] ?? '';
            if (!Tool::isValidLanguage($translationSourceLanguage)) {
                foreach (Tool::getValidLanguages() as $language) {
                    if (strpos($language, $translationSourceLanguage) === 0) {
                        $translationSourceLanguage = $language;
                        break;
                    }
                }
            }

            $glossarySourceLanguage = substr($translationSourceLanguage, 0, 2);
            $glossaryTargetLanguage = substr($fieldMapping['locale'], 0, 2);

            // unique source / target language pairs
            $glossaryLanguagePairs[$glossarySourceLanguage.'_'.$glossaryTargetLanguage] = ['sourceLanguage' => $glossarySourceLanguage, 'targetLanguage' => $glossaryTargetLanguage];
        }

        foreach($glossaryLanguagePairs as $glossaryLanguagePair) {
            $hashName = 'glossary_'.$glossaryLanguagePair['sourceLanguage'].'_'.$glossaryLanguagePair['targetLanguage'];

            $translations = [];
            foreach (AbstractTranslationProvider::getGlossaryItems() as $translation) {
                foreach ($translation->getTranslations() as $language => $text) {
                    if (!empty($text)) {
                        $translations[$translation->getKey()][substr($language, 0, 2)] = preg_replace('/\r?\n|\r/', ' ', trim($text));
                    }
                }
            }

            $glossaryEntries = [];
            foreach($translations as $translation) {
                if (!empty($translation[$glossaryLanguagePair['sourceLanguage']])) {
                    if(empty($translation[$glossaryLanguagePair['targetLanguage']])) {
                        continue;
                    }
                    $glossaryEntries[$translation[$glossaryLanguagePair['sourceLanguage']]] = $translation[$glossaryLanguagePair['targetLanguage']];
                }
            }

            if (empty($glossaryEntries)) {
                continue;
            }

            $hash = md5(serialize($glossaryEntries));
            $lockData = TmpStore::get($hashName);
            if ($lockData !== null && $lockData->getData() === $hash) {
                continue;
            }

            try {
                if(method_exists($this->translationprovider, 'createGlossary')) {
                    $this->translationprovider->createGlossary($glossaryLanguagePair['sourceLanguage'], $glossaryLanguagePair['targetLanguage'], $glossaryEntries);

                    $systemLanguages = [];
                    foreach(Tool::getValidLanguages() as $language) {
                        if(substr($language, 0, 2) === $glossaryLanguagePair['targetLanguage']) {
                            $systemLanguages[] = $language;
                        }
                    }

                    if($systemLanguages) {
                        PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_TRANSLATION_CACHE.' WHERE language IN (?)', [$systemLanguages]);
                    }
                }
                TmpStore::set($hashName, $hash);

            } catch (\Exception $e) {
                OpenDxp\Logger::error('Could not create DeepL glossary: '.$e->getMessage());

                throw $e;
            }
        }
    }
}