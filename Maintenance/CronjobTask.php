<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Maintenance;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBag;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\Queue;
use OpenDxp;
use OpenDxp\Logger;
use OpenDxp\Maintenance\TaskInterface;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class CronjobTask implements TaskInterface
{
    public function execute(): void
    {
        $queue = new Queue();
        foreach(Dataport::getInstance()->find() as $dataport) {
            $sourceConfig = $dataport['sourceconfig'];

            if (!empty($sourceConfig['autoImport'])) {
                Logger::info('Checking dataport #'.$dataport['id'].' "'.$dataport['name'].'"');
                $sourceFile = $sourceConfig['file'];
                if($dataport['sourcetype'] === 'pimcore') {
                    $initAction = Fieldmapping::getInstance()->findOne(
                        [
                            'dataportId = ?' => $dataport['id'],
                            'fieldName = ?' => '__init_action'
                        ]
                    );

                    if($initAction && (strpos($initAction['calculation'], '{{ Hours between executions }}') !== false || strpos($initAction['calculation'], '{{ Cronjob expression }}') !== false)) {
                        $queue->create([
                            'command' => 'data-bridge:complete '.$dataport['id'],
                            'triggered_by' => 'Dataport executed automatically',
                            'worker_id' => $dataport['id']
                        ]);

                        Logger::info('Queued dataport #'.$dataport['id'].' "'.$dataport['name'].'" to be executed');
                    }
                    continue;
                }

                $inheritanceEnabled = AbstractObject::getGetInheritedValues();
                try {
                    $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
                    $importer->setDataport(Dataport::getInstance()->get($dataport['id']));

                    Helper::useInheritance(true);

                    $resolvedImportSource = \preg_replace('/[\x00-\x08\x0B\x0C\xC2\xA0\xAD\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $sourceFile);
                    $resolvedImportSource = \preg_replace('/\{\{\s*\$?(\w+)([^}]+)*\}\}/u', '{{ .:.:.:$1$2}}', $resolvedImportSource);
                    $resolvedImportSource = \preg_replace('/\$(\w+)/', '{{ .:.:.:$1 }}', $resolvedImportSource);

                    $object = OpenDxp::getContainer()->get(ItemMoldBuilder::class)->getItemMold($dataport['id']);
                    foreach ($importer->getObjectIdentifiers($resolvedImportSource) as $objectIdentifier) {
                        try {
                            $identifierParts = $importer->getObjectIdentifierParts($objectIdentifier[1], $object);
                            if (is_array($identifierParts) && get_class($object) === $identifierParts[0]) {
                                continue 2;
                            }
                        } catch (\Throwable $e) {
                        }
                    }

                    $sourceFile = $importer->replaceObjectIdentifier($sourceFile, new ParameterBag(Helper::getEnvironmentVariables()));
                } finally {
                    Helper::useInheritance($inheritanceEnabled);
                }

                $sourceFile = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $sourceFile);

                $sourceFile = rtrim($sourceFile, '/');

                $firstGlobExpression = strpos($sourceFile, '*');
                if($firstGlobExpression !== false) {
                    $sourceFile = substr($sourceFile, 0, $firstGlobExpression);
                    $sourceFile = rtrim($sourceFile, '/');
                }

                $assetRootFolder = substr($sourceFile, 0, strpos($sourceFile, '/', 1));
                if($assetRootFolder) {
                    $asset = Asset::getByPath($assetRootFolder);
                    if ($asset instanceof Asset) {
                        continue;
                    }
                }

                $queue->create([
                    'command' => 'data-bridge:complete '.$dataport['id'],
                    'triggered_by' => 'Dataport executed automatically',
                    'worker_id' => $dataport['id']
                ]);

                Logger::info('Queued dataport #'.$dataport['id'].' "'.$dataport['name'].'" to be executed');
            }
        }
    }
}
