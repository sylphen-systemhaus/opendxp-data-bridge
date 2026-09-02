<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Document\Areabrick;

use Sylphen\DataBridgeBundle\Controller\Web2PrintController;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use OpenDxp;
use OpenDxp\Extension\Document\Areabrick\AbstractAreabrick;
use OpenDxp\Extension\Document\Areabrick\AbstractTemplateAreabrick;
use OpenDxp\Extension\Document\Areabrick\EditableDialogBoxConfiguration;
use OpenDxp\Extension\Document\Areabrick\EditableDialogBoxInterface;
use OpenDxp\Model\DataObject\ShopwareConfig;
use OpenDxp\Model\DataObject\ShopwareProduct;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\Editable;
use OpenDxp\Model\Document\Editable\Area\Info;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

class DataBridgeImageAreaBrick extends AbstractTemplateAreabrick implements EditableDialogBoxInterface
{
    public function getName() : string
    {
        return 'Image';
    }

    public function getTemplateLocation(): string
    {
        return static::TEMPLATE_LOCATION_BUNDLE;
    }

    public function getIcon(): ?string
    {
        return '/bundles/opendxpadmin/img/flat-color-icons/image.svg';
    }

    public function getTemplateSuffix(): string
    {
        return static::TEMPLATE_SUFFIX_TWIG;
    }

    public function getEditableDialogBoxConfiguration(Document\Editable $area, ?Info $info): EditableDialogBoxConfiguration
    {
        $config = new EditableDialogBoxConfiguration();
        $config->setWidth(600);

        $config->setItems([
            [
                'type' => 'numeric',
                'name' => 'marginLeft',
                'label' => 'Margin from Left (in mm)',
            ],
            [
                'type' => 'numeric',
                'name' => 'marginTop',
                'label' => 'Margin from Top (in mm)',
            ],
            [
                'type' => 'numeric',
                'name' => 'width',
                'label' => 'Width in mm',
            ],
            [
                'type' => 'numeric',
                'name' => 'height',
                'label' => 'Height in mm',
            ],
            [
                'type' => 'input',
                'name' => 'dataQuerySelector',
                'label' => 'Data Query Selector',
            ],
            [
                'type' => 'textarea',
                'name' => 'cssRules',
                'label' => 'CSS rules',
            ],
        ]);

        $config->setReloadOnClose(true);

        return $config;
    }

    public function action(Info $info) : ?Response
    {
        $imageEditable = $info->getDocumentElement('image');
        $dataQuerySelectorEditable = $info->getDocumentElement('dataQuerySelector');

        if($imageEditable !== null && $dataQuerySelectorEditable !== null && $dataQuerySelectorEditable->getData()) {
            $dataQuerySelector = $dataQuerySelectorEditable->getData();
            $dataQuerySelector = '.:.:.:'.Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD.':'.$dataQuerySelector;

            $importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);
            $importer->setLogger(new NullLogger());

            $dataQuerySelectorValue = $importer->getObjectByIdentifier($dataQuerySelector, $info->getDocument());
            try {
                if (is_string($dataQuerySelectorValue)) {
                    $asset = OpenDxp\Model\Asset::getByPath($dataQuerySelectorValue);
                    if ($asset instanceof \OpenDxp\Model\Asset\Image) {
                        $dataQuerySelectorValue = $asset->getId();
                    }
                    $imageEditable->setDataFromEditmode(['id' => (int)$dataQuerySelectorValue]);
                }
            } catch (\Throwable $e) {
                die($e);
            }
        }

        return null;
    }
}
