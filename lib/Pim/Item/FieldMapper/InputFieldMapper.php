<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Sylphen\DataBridgeBundle\lib\Pim\TextGeneration\TextGenerator;
use Locale;
use OutOfBoundsException;
use OpenDxp;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Tool;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use RtfHtmlPhp\Html\HtmlFormatter;
use Symfony\Component\EventDispatcher\GenericEvent;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\htmlToText;

class InputFieldMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null) {
        return $fieldDefinition instanceof Data\Input ||
            $fieldDefinition instanceof Data\Wysiwyg ||
            $fieldDefinition instanceof Data\Textarea ||
            $fieldDefinition->getFieldtype() === 'input' ||
            $fieldDefinition->getFieldtype() === 'wysiwyg' ||
            $fieldDefinition->getFieldtype() === 'textarea';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null) {
        if (!empty($mapping['locale']) && is_array($value) && isset($value[$mapping['locale']])) {
            $value = $value[$mapping['locale']];
        }

        if (is_string($value)) {
            if($fieldDefinition instanceof Data\Wysiwyg) {
                if (strpos($value, '{\rtf') !== false) {
                    try {
                        $document = new \RtfHtmlPhp\Document($value);
                        $formatter = new HtmlFormatter('UTF-8');
                        $value = $formatter->Format($document);

                        $value = str_replace(
                            array('\'fc', '\'e4', '\'c4', '\'f6', '\'d6', '\'df', '\'85', '\'80', '\'91', '\'92', '\'93', '\'94', '\'84', '\'a7', '\'a9', '\'e9', '\'ea', '\'e8', '\'e0', '\'e1', '\'e2', '\'f9', '\'fa', '\'fb', '\'ae'),
                            array('ü', 'ä', 'Ä', 'ö', 'Ö', 'ß', '…', '€', '\'', '\'', '\'', '\'', '\'', '§', '©', 'é', 'ê', 'è', 'à', 'á', 'â', 'ù', 'ú', 'û', '®'),
                            $value
                        );
                    } catch (\Exception $e) {
                    }
                }

                if (strip_tags($value) === $value) {
                    $value = nl2br($value);
                }
            } else {
                $value = html_entity_decode($value, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (empty($mapping['locale']) && $dataObject instanceof PageSnippet) {
            $mapping['locale'] = $dataObject->getProperty('language');
        }

        if (empty($mapping['locale'])) {
            $mapping['locale'] = Tool::getDefaultLanguage();
        }

        $translateOptions = [];
        if (is_array($value) && array_key_exists('text', $value) && !empty($mapping['format']['translateFromLanguage'])) {
            $translateOptions = $value;
            unset($translateOptions['text']);
            $value = $value['text'];
        }

        if (!empty($mapping['locale']) && !empty($mapping['format']['translateFromLanguage']) && $mapping['locale'] !== $mapping['format']['translateFromLanguage'] && is_string($value) && trim(strip_tags($value)) !== '') {
            try {
                $value = Importer::translate($value, $mapping['locale'], $mapping['format']['translateFromLanguage'], $translateOptions);

                $event = new GenericEvent($dataObject, [
                    'mapping' => $mapping,
                    'value' => $value
                ]);
                \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($event, 'pim.afterTranslate');

                $this->importer->getLogger()->info('Translation result for field "'.$mapping['fieldName'].'#'.$mapping['locale'].'": '.$value);
            } catch (\Throwable $e) {
                $this->log($dataObject, 'Could not translate value to '.$mapping['locale'].'. '.$e->getMessage(), 'alert');

                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            }
        }

        if (!empty($mapping['format']['infer'])) {
            try {
                $value = $this->infer($value, $fieldDefinition, $dataObject, $mapping, $mapping['format']);
            } catch (OutOfBoundsException $e) {
                $this->log($dataObject, $e->getMessage(), 'info');
                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            } catch (\Throwable $e) {
                $this->log($dataObject, 'Could not infer value. '.$e->getMessage(), 'alert');

                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            }
        }

        if (!empty($mapping['format']['generateText']) && $value !== (is_callable($currentValue) ? $currentValue() : $currentValue)) {
            $textGenerator = OpenDxp::getContainer()->get(TextGenerator::class);
            if (method_exists($textGenerator, 'setLogger')) {
                $textGenerator->setLogger($this->importer->getLogger());
            }

            try {
                if (strpos($value, '<no-prompt-extension>') === false) {
                    $maxLength = null;
                    if (method_exists($fieldDefinition, 'getMaxLength') && $fieldDefinition->getMaxLength()) {
                        $maxLength = $fieldDefinition->getMaxLength();
                    } elseif (method_exists($fieldDefinition, 'getColumnLength') && $fieldDefinition->getColumnLength()) {
                        $maxLength = $fieldDefinition->getColumnLength();
                    }

                    $prompt = 'For the '.$this->importer->getTranslator()->trans(($dataObject ?? $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']))->getClassname(), [], 'admin', 'en').' "'.($dataObject ?? $this->itemMoldBuilder->getItemMold($this->importer->getDataport()['id']))->getKey().'" with properties

'.$value.'

Create a detailed '.\Locale::getDisplayLanguage($mapping['locale'] ?: Tool::getDefaultLanguage(), 'en').' '.$this->translator->trans($fieldDefinition->getTitle(), [], 'admin', 'en').($maxLength ? ' with max. '.$maxLength.' characters' : '').'. '.($fieldDefinition instanceof Data\Wysiwyg ? 'Use HTML to highlight important things but do not use h1,h2,h3,h4,h5,h6 tags.' : '').' Return the text only.';
                } else {
                    $prompt = str_replace('<no-prompt-extension>', '', $value);
                }

                $cacheKey = 'text_generation_'.md5($prompt.$mapping['format']['textLength']);
                $generatedText = Helper::getFromCache($cacheKey);
                if (!$generatedText) {
                    $generatedTexts = [];
                    for ($i = 1; $i <= $mapping['format']['textLength']; $i++) {
                        $generatedTexts[] = $textGenerator->generate($prompt.(count($generatedTexts) > 0 ? "\n\nBegin with: ".implode("\n\n", $generatedTexts)."\n\nDescribe more details without repeating the beginning." : ''));
                    }

                    $generatedText = preg_replace('/(\s){2,}/', '$1$1', implode("\n\n", array_filter($generatedTexts)));

                    Helper::saveInCache($cacheKey, $generatedText);
                }

                $this->importer->getLogger()->info('Text generation result for field "'.$mapping['fieldName'].($mapping['locale'] ? '#'.$mapping['locale'] : '').'": '.$generatedText);

                $value = $generatedText;
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                if (strpos($errorMessage, 'check your plan and billing details') !== false) {
                    $errorMessage .= ' Please also ensure that you have entered credit card information as this is mandatory nowadays to work with the API.';
                }

                $this->log($dataObject, 'Could not generate text. '.$errorMessage, 'alert');

                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            }
        }

        if (strtolower($mapping['fieldName'] ?? '') === 'path' && !empty($mapping['format']['deleteChildren']) && !$this->isPurged($mapping, $dataObject)) {
            $this->setPurged($mapping, $dataObject);

            foreach ($dataObject->getChildren() as $child) {
                $child->delete();
            }
        }

        if (is_string($value)) {
            $value = trim($value, " \t\n\r\0\x0B");
        }

        return $value;
    }
}