<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Translate;


use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use DeepL\DeepLException;
use DeepL\GlossaryEntries;
use DeepL\Translator;
use DeepL\GlossaryInfo;

class DeepL extends AbstractTranslationProvider
{
    /** @var Translator */
    private $api;

    /** @var array */
    private $config = [];

    /** @var GlossaryInfo[] */
    private $glossaries = [];

    /** @var GlossaryInfo[] */
    private $listGlossaries = null;

    public function __construct($apiKey = null, $config = [])
    {
        $this->api = new Translator($apiKey);
        $this->config = $config;
    }

    /**
     * @inheritDoc
     * @see https://github.com/DeepLcom/deepl-php#translating-text
     *
     * @throws DeepLException
     */
    public function translate($text, $languageTo, $languageFrom = null, $isHtml = false, array $translateOptions = [])
    {
        if($isHtml) {
            // wrap <br> tags with whitespaces, otherwise DeepL adds too many <br>s
            $text = preg_replace('/<br ?\/?>/i', ' <br> ', $text);
        }

        $languageTo = strtolower($languageTo);
        if(isset($this->config['translation']['languages'])) {
            foreach ($this->config['translation']['languages'] as $mappedLanguage => $targetLanguage) {
                if (strtolower($mappedLanguage) === $languageTo && $targetLanguage) {
                    $languageTo = strtolower($targetLanguage);
                }
            }
        }

        $languageTo = self::getDeeplLanguage($languageTo);
        $languageFrom = \strtolower(substr($languageFrom, 0, 2));

        $options = ['ignore_tags' => [\OpenDxp::getContainer()->getParameter('sylphen_data_bridge.skip_translation_tag')]];
        if($isHtml) {
            $options['tag_handling'] = 'xml';
        }

        if (count($translateOptions) > 0) {
            $options = array_merge($options, $translateOptions);
        }

        $glossary = $this->findGlossaryByLanguagePair($languageFrom, $languageTo);
        if ($glossary) {
            $options['glossary'] = $glossary->glossaryId;
        }

        $translation = $this->api->translateText(
            $text,
            $languageFrom,
            $languageTo,
            $options
        );

        return is_array($translation) ? $translation[0]->text : $translation->text;
    }

    private static function getDeeplLanguage($language) {
        switch ($language) {
            case 'en':
            case 'en_gb':
            case 'en-gb':
                return 'en-GB';
            case 'pt':
            case 'pt_pt':
            case 'pt-pt':
                return 'pt-PT';
            case 'en_us':
            case 'en-us':
                return 'en-US';
            case 'pt_br':
            case 'pt-br':
                return 'pt-BR';
            default:
                return substr($language, 0, 2);
        }
    }

    /**
     * @return GlossaryInfo[]
     */
    private function listGlossaries(): array
    {
        if (null === $this->listGlossaries) {
            $this->listGlossaries = $this->api->listGlossaries();
        }

        return $this->listGlossaries;
    }

    /**
     * @param $sourceLanguage
     * @param $targetLanguage
     * @param $entries
     * @return void
     * @throws DeepLException
     */
    public function createGlossary($sourceLanguage, $targetLanguage, $entries): void {
        $glossary = $this->findGlossaryByLanguagePair($sourceLanguage, $targetLanguage);
        if ($glossary) {
            if(substr($glossary->name, -3) === '_DD') {
                try {
                    $this->api->deleteGlossary($glossary);
                } catch (DeepLException $e) {}
            } else {
                return;
            }
        }

        $name = $this->getGlossaryName($sourceLanguage, $targetLanguage);
        try {
            $entries = GlossaryEntries::fromEntries($entries);
            $this->api->createGlossary($name, $sourceLanguage, $targetLanguage, $entries);
        } catch (DeepLException $e) {
            throw new DeepLException('Unable to create Glossary: ' . $e->getMessage());
        }
    }

    private function getGlossaryName($sourceLanguage, $targetLanguage): string {
        return (parse_url(Helper::getHostUrl(), PHP_URL_HOST) ?: 'Pimcore').'_'.$sourceLanguage.'_'.$targetLanguage.'_DD';
    }

    private function findGlossaryByLanguagePair(string $sourceLang, string $targetLang): ?GlossaryInfo
    {
        $sourceLang = substr($sourceLang, 0, 2);
        $targetLang = substr($targetLang, 0, 2);

        $glossaryKey = $sourceLang . $targetLang;
        if (!array_key_exists($glossaryKey, $this->glossaries)) {
            $this->glossaries[$glossaryKey] = null;

            // search for non-DD glossaries first
            foreach ($this->listGlossaries() as $glossaryInfo) {
                if (substr($glossaryInfo->name, -3) !== '_DD' && $glossaryInfo->sourceLang === $sourceLang && $glossaryInfo->targetLang === $targetLang) {
                    $this->glossaries[$glossaryKey] = $glossaryInfo;
                    break;
                }
            }

            // search for any glossary which matches source and target language, if no non-DD glossary was found
            if(!$this->glossaries[$glossaryKey]) {
                foreach ($this->listGlossaries() as $glossaryInfo) {
                    if ($glossaryInfo->sourceLang === $sourceLang && $glossaryInfo->targetLang === $targetLang) {
                        $this->glossaries[$glossaryKey] = $glossaryInfo;
                        break;
                    }
                }
            }
        }

        return $this->glossaries[$glossaryKey];
    }

    /**
     * @param \OpenDxp\Model\Translation|\OpenDxp\Model\Translation\Website $translation
     * @param string $languageFrom
     * @param string $languageTo
     * @return bool
     */
    public function isCachingAllowed($translation, string $languageFrom, string $languageTo): bool
    {
        $languageFrom = self::getDeeplLanguage($languageFrom);
        $languageTo = self::getDeeplLanguage($languageTo);

        $glossary = $this->findGlossaryByLanguagePair($languageFrom, $languageTo);

        if ($glossary === null) {
            return true;
        }

        $translationDateTime = new \DateTime('@0');
        $translationDateTime->setTimestamp($translation->getModificationDate());

        return $translationDateTime >= $glossary->creationTime;
    }

    public static function supportsGlossaries(): bool
    {
        return true;
    }
}
