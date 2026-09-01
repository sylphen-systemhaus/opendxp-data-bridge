<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Iterator;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset\Video;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Element\ValidationException;

class VideoMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Video || $fieldDefinition->getFieldtype() === 'video';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $assetSource = $value;
        if ($assetSource instanceof Iterator) {
            $assetSource->rewind();
            $assetSource = $assetSource->current();
        }

        if (is_callable($currentValue)) {
            $currentValue = $currentValue();
        }

        if (!$currentValue instanceof \OpenDxp\Model\DataObject\Data\Video || empty($mapping['format']['writeProtected'])) {
            try {
                $value = null;
                if (is_string($assetSource)) {
                    if (preg_match('/^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/', $assetSource, $match)) {
                        $value = new \OpenDxp\Model\DataObject\Data\Video();
                        $value->setType('youtube');
                        $value->setData($match[2]);
                    } elseif (preg_match('/vimeo.com\/(\d+)($|\/)/', $assetSource, $match)) {
                        $value = new \OpenDxp\Model\DataObject\Data\Video();
                        $value->setType('vimeo');
                        $value->setData($match[1]);
                    } elseif (preg_match('/dailymotion.*\/video\/([^_]+)/', $assetSource, $match)) {
                        $value = new \OpenDxp\Model\DataObject\Data\Video();
                        $value->setType('dailymotion');
                        $value->setData($match[1]);
                    }
                } elseif (isset($assetSource['type'], $assetSource['data'])) {
                    $value = new \OpenDxp\Model\DataObject\Data\Video();
                    $value->setType($assetSource['type']);
                    if ($assetSource['type'] === 'asset') {
                        $assetSource['data'] = $this->getAsset($assetSource['data'], ['overwrite' => !empty($mapping['format']['overwrite']), 'preventDuplicates' => !empty($mapping['format']['preventDuplicates'])]);
                    }
                    $value->setData($assetSource['data']);
                }

                if ($value === null) {
                    $asset = $this->getAsset($assetSource, ['overwrite' => !empty($mapping['format']['overwrite']), 'preventDuplicates' => !empty($mapping['format']['preventDuplicates'])]);
                    if ($asset instanceof Video) {
                        $value = new \OpenDxp\Model\DataObject\Data\Video();
                        $value->setType('asset');
                        $value->setData($asset);
                    }
                }

                $fieldDefinition->checkValidity($value);
            } catch (ValidationException $e) {
                $this->log($dataObject, 'Skipped '.Importer::getLogOutput($value).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
                $value = $currentValue;
            }
        }

        return $value;
    }
}