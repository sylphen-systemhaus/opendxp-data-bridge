<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Document\Areabrick;

use Sylphen\DataBridgeBundle\Controller\Web2PrintController;
use Sylphen\DataBridgeBundle\lib\Pim\Document\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueDataQuerySelectorPhpCompatibilityTrait;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use OpenDxp\Extension\Document\Areabrick\AbstractAreabrick;
use OpenDxp\Extension\Document\Areabrick\AbstractTemplateAreabrick;
use OpenDxp\Extension\Document\Areabrick\EditableDialogBoxConfiguration;
use OpenDxp\Extension\Document\Areabrick\EditableDialogBoxInterface;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\Editable;
use OpenDxp\Model\Document\Editable\Area\Info;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;


class DataBridgeWysiwygAreaBrick extends AbstractTemplateAreabrick implements EditableDialogBoxInterface
{
    public function getName() : string
    {
        return 'Text';
    }

    public function getTemplateLocation(): string
    {
        return static::TEMPLATE_LOCATION_BUNDLE;
    }

    public function getIcon(): ?string
    {
        return '/bundles/opendxpadmin/img/flat-color-icons/wysiwyg.svg';
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
        $wysiwygEditable = $info->getDocumentElement('wysiwyg');

        if (!$info->getEditable()->getEditmode() && $wysiwygEditable !== null && $wysiwygEditable->getData()) {
            $content = $wysiwygEditable->getData();
            $importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);
            $importer->setLogger(new NullLogger());

            $content = preg_replace('/\$?elements\[(\d+)\]/\'', '.:.:.:'.Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD.':$1', $content);
            $content = preg_replace('/\$?elements/', '.:.:.:'.Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD, $content);

            $content = $importer->replaceObjectIdentifier($content, $info->getDocument());

            $wysiwygEditable->setDataFromEditmode($content);
        }

        return null;
    }
}
