<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\AdminStyle;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\PathFormatter\SearchViewPathFormatter;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Localizedfields;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Folder;
use OpenDxp\Model\Element\AdminStyle;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Translation\Translator;
use Symfony\Component\Routing\RouterInterface;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\toString;

class GridViewFieldsAdminStyle extends AdminStyle
{
    /** @var ElementInterface */
    private $element;

    /** @var Translator */
    private $translator;

    /** @var AdminStyle */
    private $previousAdminStyle;

    public function __construct(ElementInterface $element, Translator $translator, AdminStyle $previousAdminStyle = null)
    {
        parent::__construct($element);
        if($previousAdminStyle instanceof AdminStyle) {
            $this->setElementIcon($previousAdminStyle->getElementIcon());
            $this->setElementCssClass($previousAdminStyle->getElementCssClass());
            $this->setElementIconClass($previousAdminStyle->getElementIconClass());
            $this->setElementQtipConfig($previousAdminStyle->getElementQtipConfig());
        }

        $this->element = $element;
        $this->translator = $translator;
        $this->previousAdminStyle = $previousAdminStyle;
    }

    public function getElementQtipConfig(): ?array
    {
        if($this->previousAdminStyle instanceof AdminStyle) {
            if (!$this->element instanceof Concrete && !$this->element instanceof Asset) {
                return $this->previousAdminStyle->getElementQtipConfig();
            }

            if(isset($this->previousAdminStyle->getElementQtipConfig()['text']) && $this->previousAdminStyle->getElementQtipConfig()['text'] !== 'Type: '.$this->element->getClass()->getName()) {
                return $this->previousAdminStyle->getElementQtipConfig();
            }
        }

        if (!$this->element instanceof Concrete && !$this->element instanceof Asset) {
            return $this->previousAdminStyle->getElementQtipConfig();
        }

        try {
            $returnValue = [];
            if ($this->element instanceof Concrete) {
                $classDefinition = $this->element->getClass();

                foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof Localizedfields) {
                        foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                            if ($localizedFieldDefinition->getVisibleGridView()) {
                                $language = Helper::getUser()->getLanguage();

                                if(!OpenDxp\Tool::isValidLanguage($language)) {
                                    foreach(OpenDxp\Tool::getValidLanguages() as $languageCandidate) {
                                        if(strpos($languageCandidate, $language.'_') === 0) {
                                            $language = $languageCandidate;
                                            break;
                                        }
                                    }
                                }
                                $returnValue[] = $this->renderValue(Importer::getValue($this->element, $localizedFieldDefinition->getName(), [$language]), $localizedFieldDefinition);
                            }
                        }
                    } elseif ($fieldDefinition->getVisibleGridView()) {
                        $returnValue[] = $this->renderValue(Importer::getValue($this->element, $fieldDefinition->getName()), $fieldDefinition);
                    }
                }

                foreach ($classDefinition->getPropertyVisibility()['grid'] ?? [] as $systemProperty => $visible) {
                    if (!$visible || in_array($systemProperty, ['path', 'key','id','published'])) {
                        continue;
                    }

                    $returnValue[] = $this->renderValue(Importer::getValue($this->element, $systemProperty), Importer::getFieldDefinition($this->element, $systemProperty));
                }
            } elseif ($this->element instanceof Asset) {
                require_once __DIR__.'/../Import/helpers.php';
                foreach ($this->element->getMetadata() as $metaData) {
                    $fieldDefinition = new Data\Input();
                    $fieldDefinition->setName($metaData['name'].($metaData['language'] ? '('.$metaData['language'].')' : ''));
                    $fieldDefinition->setTitle($fieldDefinition->getName());
                    $returnValue[] = $this->renderValue(toString($metaData['data']), $fieldDefinition);
                }
            } else {
                $returnValue[] = $this->renderValue($this->element->getFullPath(), new Data\Input());
            }

            $returnValue = array_values(
                array_filter(array_unique($returnValue, SORT_REGULAR), static function ($fieldValue) {
                    return $fieldValue['value'] !== null || (string)$fieldValue['value'] !== '';
                })
            );

            if ($returnValue) {
                $titleResult = 'ID: '.$this->element->getId();

                $text = '';
                if ($this->element instanceof Concrete) {
                    $text = $this->translator->trans('class', [], 'admin').': '.$this->translator->trans($this->element->getClassName(), [], 'admin');
                } elseif ($this->element instanceof Asset) {
                    $text = $this->translator->trans('type', [], 'admin').': '.$this->translator->trans($this->element->getType(), [], 'admin');
                } elseif ($this->element instanceof OpenDxp\Model\Document) {
                    $text = $this->translator->trans('type', [], 'admin').': '.$this->translator->trans($this->element->getType(), [], 'admin');
                }
                if ($text) {
                    $text .= '<hr>';
                }

                $text .= '<table class="tree-tooltip">';
                foreach ($returnValue as $index => $returnField) {
                    $text .= '<tr><td>'.$returnField['field'].'</td><td>'.$returnField['value'].'</td></tr>';
                }
                $text .= '</table>';

                return [
                    'title' => $titleResult,
                    'text' => $text,
                ];
            }

            return $this->previousAdminStyle->getElementQtipConfig();
        } catch (\Throwable $e) {
            return $this->previousAdminStyle->getElementQtipConfig();
        }
    }

    private function renderValue($value, Data $def)
    {
        if ($def instanceof Data\ImageGallery){
            /** @var OpenDxp\Model\DataObject\Data\ImageGallery $value */
            $items = $value->getItems();
            foreach ($items as $item) {
                if ($item instanceof OpenDxp\Model\DataObject\Data\Hotspotimage && $item->getImage() instanceof Asset\Image) {
                    $imageFieldDefinition = new Data\Image();
                    $imageFieldDefinition->setName($def->getName());
                    $imageFieldDefinition->setTitle($def->getTitle());
                    return $this->renderValue($item->getImage(), $imageFieldDefinition);
                }
            }
            $value = '';
        } elseif ($def instanceof Data\Checkbox) {
            if($value) {
                $value = '<img style="height:16px;vertical-align: middle" src="/bundles/opendxpadmin/img/flat-color-icons/approve.svg">';
            } else {
                $value = '<img style="height:16px;vertical-align: middle" src="/bundles/opendxpadmin/img/flat-color-icons/disapprove.svg">';
            }
        } elseif ($value instanceof Concrete) {
            $searchPathFormatter = new SearchViewPathFormatter();
            $value = $searchPathFormatter->formatPath([], new Concrete(), [['type' => 'object', 'id' => $value->getId()]], [])[0];
        } elseif (method_exists($def, 'getDiffVersionPreview')) {
            $value = $def->getDiffVersionPreview($value);

            if (isset($value['html'])) {
                $value = $value['html'];
            } elseif (isset($value['src'])) {
                if (isset($value['type']) && $value['type'] === 'img') {
                    $value = '<img src="'.$value['src'].'">';
                } else {
                    $value = $value['src'];
                }
            }

            $value = str_replace('<img ', '<img style="height:80px;margin-right:5px;" ', $value);
        } else {
            try {
                $value = $def->getVersionPreview($value);
                if($value === 'no preview') {
                    throw new InvalidArgumentException('Field type "'.$def->getFieldtype().'" does not support preview view');
                }
            } catch (\Throwable $e) {
                $value = Importer::getLogOutput($value);
            }
        }

        if($value === null || (string)$value === '') {
            $value = null;
        }

        return ['field' => $this->translator->trans($def->getTitle() ?: $def->getName(), [], 'admin'), 'value' => $value];
    }
}