<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\PathFormatter;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Localizedfields;
use OpenDxp\Model\DataObject\ClassDefinition\PathFormatterInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\toString;

class GridViewPathFormatter implements PathFormatterInterface
{
    public function formatPath(array $result, ElementInterface $source, array $targets, array $params): array
    {
        foreach ($targets as $key => $item) {
            $item = Service::getElementById($item['type'], $item['id']);
            if (!$item instanceof ElementInterface) {
                $result[$key] = '';
                continue;
            }
            $returnValue = [];
            if($item instanceof Concrete) {
                $classDefinition = $item->getClass();

                foreach($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof Localizedfields) {
                        foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                            if ($localizedFieldDefinition->getVisibleGridView() && !$localizedFieldDefinition instanceof Data\Checkbox && !$localizedFieldDefinition instanceof Data\BooleanSelect) {
                                $fieldValue = $this->renderValue(Importer::getValue($item, $localizedFieldDefinition->getName(), isset($params['context']['language']) ? [$params['context']['language']] : []), $localizedFieldDefinition);

                                if ($fieldValue !== null && (string)$fieldValue !== '') {
                                    $returnValue[] = $fieldValue;
                                }

                                if (count($returnValue) >= 3) {
                                    break;
                                }
                            }
                        }
                    } elseif($fieldDefinition->getVisibleGridView() && !$fieldDefinition instanceof Data\Checkbox && !$fieldDefinition instanceof Data\BooleanSelect) {
                        $fieldValue = $this->renderValue(Importer::getValue($item, $fieldDefinition->getName()), $fieldDefinition);
                        if ($fieldValue !== null && (string)$fieldValue !== '') {
                            $returnValue[] = $fieldValue;
                        }
                    }

                    if(count($returnValue) >= 3) {
                        break;
                    }
                }

                if (count($returnValue) < 3) {
                    $systemFields = $classDefinition->getPropertyVisibility()['grid'] ?? [];
                    $defaultSystemFields = [
                        'id' => true,
                        'key' => false,
                        'path' => true,
                        'published' => true,
                        'modificationDate' => true,
                        'creationDate' => true
                    ];
                    if (count($returnValue) === 0 || $systemFields != $defaultSystemFields) {
                        foreach ($systemFields as $systemProperty => $visible) {
                            if (!$visible || in_array($systemProperty, ['id', 'published', 'modificationDate', 'creationDate'])) {
                                continue;
                            }

                            if ($systemProperty === 'path') {
                                $fieldValue = $this->renderValue(Importer::getValue($item, $systemProperty), Importer::getFieldDefinition($item, $systemProperty));
                                $fieldValue .= $this->renderValue(Importer::getValue($item, 'key'), Importer::getFieldDefinition($item, $systemProperty));
                            } else {
                                $fieldValue = $this->renderValue(Importer::getValue($item, $systemProperty), Importer::getFieldDefinition($item, $systemProperty));
                            }

                            if ($fieldValue !== null && (string)$fieldValue !== '') {
                                $returnValue[] = $fieldValue;
                            }
                        }
                    }
                }

                if (count($returnValue) === 0) {
                    $folders = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT DISTINCT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE o_classId=? ORDER BY o_path', [$item->getClassId()]);
                    if (count($folders) === 0) {
                        $folders = ['/'];
                    }
                    
                    $pathPartsFirst = explode('/', $folders[0]);
                    $pathPartsLast = explode('/', $folders[count($folders) - 1]);

                    $len = min(count($pathPartsFirst), count($pathPartsLast));

                    $commonPrefix = [];
                    for ($i = 0; $i < $len; $i++) {
                        if ($pathPartsFirst[$i] !== $pathPartsLast[$i]) {
                            break;
                        }
                        $commonPrefix[] = $pathPartsFirst[$i];
                    }

                    $commonPrefix = rtrim(implode('/', $commonPrefix), '/');
                    $returnValue[] = preg_replace('/^'.preg_quote($commonPrefix, '/').'\//', '', $item->getRealFullPath());
                }
            } elseif($item instanceof Asset) {
                if($item instanceof Asset\Image) {
                    $returnValue[] = $this->renderValue($item, new Data\Image());
                    $returnValue[] = $this->renderValue($item->getRealFullPath(), new Data\Input());
                } elseif ($item instanceof Asset\Document) {
                    try {
                        $url = OpenDxp::getContainer()->get('router')->generate('opendxp_webdav', ['path' => $item->getFullPath()], RouterInterface::ABSOLUTE_URL);
                    } catch (RouteNotFoundException $e) {
                        try {
                            $url = OpenDxp::getContainer()->get('router')->generate('opendxp_admin_webdav', ['path' => $item->getFullPath()], RouterInterface::ABSOLUTE_URL);
                        } catch (RouteNotFoundException $e) {
                            $url = Helper::getHostUrl().'/asset/webdav/'.$item->getFullPath();
                        }
                    }

                    if (in_array($item->getMimeType(), ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'], true)) {
                        $returnValue[] = '<a href="ms-excel:ofe|u|'.$url.'">'.$this->renderValue($item->getFullPath(), new Data\Input()).'</a>';
                    } elseif (in_array($item->getMimeType(), ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'], true)) {
                        $returnValue[] = '<a href="ms-word:ofe|u|'.$url.'">'.$this->renderValue($item->getFullPath(), new Data\Input()).'</a>';
                    } elseif (in_array($item->getMimeType(), ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint'], true)) {
                        $returnValue[] = '<a href="ms-powerpoint:ofe|u|'.$url.'">'.$this->renderValue($item->getFullPath(), new Data\Input()).'</a>';
                    } else {
                        $returnValue[] = $this->renderValue($item->getFullPath(), new Data\Input());
                    }
                } else {
                    $returnValue[] = $this->renderValue($item->getFullPath(), new Data\Input());
                }
            } else {
                $returnValue[] = $this->renderValue($item->getFullPath(), new Data\Input());
            }

            $returnValue = array_filter($returnValue);

            if(count($returnValue) === 0) {
                $returnValue = [$item->getFullPath()];
            }

            usort($returnValue, static function($value1, $value2) {
                if(strpos($value1, '<img') === 0 && strpos($value2, '<img') !== 0) {
                    return -1;
                }

                if (strpos($value2, '<img') === 0 && strpos($value1, '<img') !== 0) {
                    return 1;
                }

                return 0;
            });

            $resultString = '';
            foreach($returnValue as $returnValueItem) {
                $resultString .= $returnValueItem;

                if (strpos($returnValueItem, '<img') !== 0) {
                    $resultString .= ' - ';
                }
            }

            $result[$key] = rtrim($resultString, ' -');
        }

        return $result;
    }

    private function renderValue($value, Data $def)
    {
        if (method_exists($def, 'getDiffVersionPreview')) {
            $originalValue = $value;
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

            if ($originalValue instanceof Asset) {
                require_once __DIR__.'/../Import/helpers.php';
                $metaDataList = [];
                foreach ($originalValue->getMetadata() as $metaData) {
                    $metaDataList[] = $metaData['name'].($metaData['language'] ? '('.$metaData['language'].')' : '').': '.toString($metaData['data']);
                }
                if ($metaDataList) {
                    $value = str_replace('<img ', '<img title="'.implode("\n", $metaDataList).'" ', $value);
                }
            }
        } elseif ($def instanceof Data\ImageGallery) {
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
        } elseif ($value instanceof ElementInterface) {
            if ($value instanceof Concrete) {
                $folders = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT DISTINCT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path'), [$value->getClassId()]);
                if (count($folders) === 0) {
                    $folders = ['/'];
                }

                $pathPartsFirst = explode('/', $folders[0]);
                $pathPartsLast = explode('/', $folders[count($folders) - 1]);

                $len = min(count($pathPartsFirst), count($pathPartsLast));

                $commonPrefix = [];
                for ($i = 0; $i < $len; $i++) {
                    if ($pathPartsFirst[$i] !== $pathPartsLast[$i]) {
                        break;
                    }
                    $commonPrefix[] = $pathPartsFirst[$i];
                }

                $commonPrefix = rtrim(implode('/', $commonPrefix), '/');
                return preg_replace('/^'.preg_quote($commonPrefix, '/').'\//', '', $value->getRealFullPath());
            }
            return $value->getRealFullPath();
        } else {
            try {
                $value = $def->getVersionPreview($value);
                if ($value === 'no preview') {
                    throw new InvalidArgumentException('Field type "'.$def->getFieldtype().'" does not support preview view');
                }
            } catch (\Throwable $e) {
                $value = Importer::getLogOutput($value);
            }
        }

        return strip_tags($value, ['img']);
    }
}