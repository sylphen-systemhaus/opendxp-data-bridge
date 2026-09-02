<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
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
use Sylphen\DataBridgeBundle\Tools\Installer;
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


class DataBridgeCodeAreaBrick extends DataBridgeWysiwygAreaBrick
{
    public function getName() : string
    {
        return 'Code';
    }

    public function getIcon(): ?string
    {
        return '/bundles/opendxpadmin/img/flat-color-icons/tag.svg';
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
                'name' => 'code',
                'label' => 'HTML / Twig / PHP Code',
                'config' => ['htmlspecialchars' => false]
            ],
        ]);

        return $config;
    }

    public function action(Info $info) : ?Response
    {
        $codeEditable = $info->getDocumentElement('code');

        if ($codeEditable !== null) {
            $code = html_entity_decode($codeEditable->getData(), ENT_HTML5|ENT_QUOTES);

            $importer = clone \OpenDxp::getContainer()->get(ImporterInterface::class);
            $importer->setLogger(new NullLogger());

            \Sylphen\DataBridgeBundle\lib\Pim\Helper::useInheritance(true);

            if(strpos($code, '<?php') !== false) {
                $tmpFilePath = sprintf(
                    '%s/data-bridge-callback-%s.%s',
                    Installer::getCachePath(),
                    md5($code),
                    'php'
                );
                if (!file_exists($tmpFilePath)) {
                    file_put_contents($tmpFilePath,$code);
                }


                $document = $info->getDocument();
                $elementsEditable = method_exists($document, 'getEditable') ? $document->getEditable(Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD) : $document->getElement(Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD);

                $elements = [];
                if ($elementsEditable) {
                    $elements = $elementsEditable->getValue();
                }

                try {
                    ob_start();
                    call_user_func(static function () use ($tmpFilePath, $elements) {
                        include($tmpFilePath);
                    });
                    $code = ob_get_clean();
                } catch(\Throwable $e) {
                    $code = (string)$e;
                }
            }

            $code = preg_replace('/\$?elements\[(\d+)\]/', '.:.:.:'.Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD.':$1', $code);
            $code = preg_replace('/\$?elements/', '.:.:.:'.Web2PrintController::DD_AREABRICK_ELEMENTS_FIELD, $code);

            $code = $importer->replaceObjectIdentifier($code, $info->getDocument());

            if($code === '') {
                $code = 'HTML / Twig / PHP Code';
            }

            $info->setParam('renderedHtml', $code);
        }

        return null;
    }
}
