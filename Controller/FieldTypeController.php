<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueDataQuerySelector;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\Maintenance\CleanupImportStatus;
use OpenDxp;
use OpenDxp\Bundle\AdminBundle\Controller\AdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

/**
 * @Route("/admin/SylphenDataBridge/field")
 */
#RouteAttribute("/admin/SylphenDataBridge/field")
class FieldTypeController
{
    public static function getSubscribedServices(): array
    {
        return [ImporterInterface::class => ImporterInterface::class];
    }

    /**
     * @Route("/resolve")
     */
    public function getResolvedValueAction(Request $request)
    {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $importer = OpenDxp::getContainer()->get(ImporterInterface::class);

        $object = OpenDxp\Model\DataObject\Concrete::getById($request->get('objectId'));

        foreach(json_decode($request->get('fieldValues'), true) as $field => $fieldValue) {
            try {
                $fieldDefinition = Importer::getFieldDefinition($object, $field);
                $fieldValue = $importer->map(['fieldName' => $field], $fieldValue, null, $fieldDefinition, $object);
                $importer::setValue($object, $field, $fieldValue);
            } catch (Throwable $e) {
            }
        }

        $resolvedValue = null;
        if($request->get('input')) {
            $resolvedValue = $importer->replaceObjectIdentifier($request->get('input'), $object);
        } elseif($request->get('field')) {
            $field = explode('#', $request->get('field'));
            $fieldMapping = ['fieldName' => $field[0], 'locale' => $field[1] ?? null];
            CalculatedValueDataQuerySelector::disableDatabaseFetch();
            CalculatedValueDataQuerySelector::disableDatabaseStore();

            try {
                $resolvedValue = $importer::getValue($object, $fieldMapping, [$fieldMapping['locale']]);
            } catch (Throwable $e) {
                return new JsonResponse(['success' => false]);
            }
        }


        return new JsonResponse(['success' => true, 'resolved' => $resolvedValue]);
    }
}