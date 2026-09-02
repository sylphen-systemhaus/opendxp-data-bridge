<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Cache;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Tool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteRawdataCommand extends AbstractCommand
{
    public static function getDefaultName(): string
    {
        return 'data-bridge:delete-rawdata';
    }

    protected function configure(): void
    {
        $this
            ->setName('data-bridge:delete-rawdata')
            ->setDescription('Remove all rawdata entries')
            ->addOption('dataport', null, InputOption::VALUE_REQUIRED, 'ID of a dataport')
            ->addOption('dataport-resource-id', null, InputOption::VALUE_REQUIRED, 'Dataport resource id, omit to import data from dataport\'s default import source')
            ->addOption('object-id', null, InputOption::VALUE_REQUIRED, 'Delete only rawdata of given object')
            ->addOption('object-type', null, InputOption::VALUE_REQUIRED, 'Required when object-id is given, valid values: object, document, asset')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataportId = $input->getOption('dataport');

        try {
            if (!empty($input->getOption('object-id'))) {
                $dataports = Dataport::getInstance();
                $object = Service::getElementById($input->getOption('object-type'), $input->getOption('object-id'));
                if (!$object instanceof ElementInterface) {
                    $output->writeln('Could not find object #'.$input->getOption('object-id').', type: '.$input->getOption('object-type'));

                    if ($dataportId) {
                        $dataport = $dataports->get($dataportId);

                        $success = false;
                        if ($dataport['sourcetype'] === 'pimcore') {
                            $keyFields = [];
                            $sourceConfig = $dataport['sourceconfig'];
                            foreach ($sourceConfig['fields'] as $fieldIndex => $field) {
                                if (!empty($field['exportKey'])) {
                                    $keyFields[$fieldIndex] = $field;
                                }
                            }

                            if(count($keyFields) === 1 && strtolower(reset($keyFields)['parameters']) === 'id') {
                                $hash = Importer::getHash([key($keyFields) => $input->getOption('object-id')]);
                                PimcoreDbRepository::getInstance()->execute('DELETE rawitem FROM '.Installer::TABLE_RAWITEM.' rawitem,'.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource WHERE rawitem.dataport_resource_id=dataport_resource.id AND dataport_resource.dataportId = ? AND hash=?', [$dataportId, $hash]);

                                $output->writeln('Deleted anyway because "id" is key field');
                                $success = true;
                            }
                        }

                        if(!$success) {
                            goto deleteRawdataOfDataport;
                        }
                    }

                    return 0;
                }

                $dataportResource = null;
                if ($input->getOption('dataport-resource-id')) {
                    $dataportResource = DataportResource::getInstance()->get($input->getOption('dataport-resource-id'));
                }

                $condition = [];
                if ($dataportId) {
                    $condition['id = ?'] = $dataportId;
                } elseif ($dataportResource !== null) {
                    $condition['id = ?'] = $dataportResource['dataportId'];
                }

                $countDeletedRawItems = 0;
                foreach ($dataports->find($condition, 'name') as $dataport) {
                    try {
                        $sourceConfig = $dataport['sourceconfig'];
                        $parser = $dataports->getParser($dataport['id']);

                        if (\method_exists($parser, 'setSourceFile') && \method_exists($parser, 'getFileConditionFromObject')) {
                            $source = $parser->getFileConditionFromObject($object, false);
                            if ($source !== null) {
                                if (\method_exists($parser, 'setConfig')) {
                                    if (\method_exists($parser, 'getConfig')) {
                                        $sourceConfig = $parser->getConfig();
                                    }
                                    $sourceConfig['file'] = '';

                                    $sourceConfig['dataportId'] = $dataport['id'];

                                    $parser->setConfig($sourceConfig);
                                }

                                $parser->setSourceFile($source);

                                $keyFields = [];
                                foreach ($sourceConfig['fields'] as $fieldIndex => $field) {
                                    if (!empty($field['exportKey'])) {
                                        $keyFields[$fieldIndex] = $field;
                                    }
                                }

                                $hashs = [];
                                if ($dataport['sourcetype'] === 'pimcore') {
                                    $locales = Tool::getValidLanguages();
                                    if ($dataportResource !== null) {
                                        $resource = \json_decode($dataportResource['resource'], true);
                                        if ($resource['locale']) {
                                            $locales = [$resource['locale']];
                                        }
                                    }
                                    foreach ($locales as $language) {
                                        \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($language);

                                        foreach ($parser as $rawItemData) {
                                            if ($rawItemData === null) {
                                                continue;
                                            }

                                            $rawItemData = array_filter(
                                                $rawItemData,
                                                static function ($fieldId) use ($keyFields) {
                                                    return isset($keyFields[$fieldId]);
                                                },
                                                ARRAY_FILTER_USE_KEY
                                            );

                                            $hashs[] = Importer::getHash($rawItemData);
                                        }
                                    }
                                } else {
                                    foreach ($parser as $rawItemData) {
                                        if ($rawItemData === null) {
                                            continue;
                                        }

                                        $rawItemData = array_filter(
                                            $rawItemData,
                                            static function ($fieldId) use ($keyFields) {
                                                return isset($keyFields[$fieldId]);
                                            },
                                            ARRAY_FILTER_USE_KEY
                                        );

                                        $hashs[] = Importer::getHash($rawItemData);
                                    }
                                }

                                $query = 'DELETE rawitem FROM '.Installer::TABLE_RAWITEM.' rawitem,'.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource WHERE rawitem.dataport_resource_id=dataport_resource.id AND dataport_resource.dataportId = ? AND hash IN (?)';
                                $parameters = [$dataport['id'], \array_unique($hashs)];

                                if ($dataportResource !== null) {
                                    $query .= ' AND dataport_resource.id = ?';
                                    $parameters[] = $dataportResource['id'];
                                }

                                $types = $dataports->getDataTypes($parameters);
                                $countDeletedRawItems += PimcoreDbRepository::getInstance()->execute($query, $parameters, $types);
                            }
                        }
                    } catch (\Exception $e) {
                        Logger::error((string)$e);
                    }
                }
                $output->writeln($countDeletedRawItems.' entries deleted.');
                return 0;
            }

            deleteRawdataOfDataport:
            if (!empty($dataportId)) {
                $currentlyRunning = PimcoreDbRepository::getInstance()->findInSql(
                    'SELECT dataport_resource_id FROM '.Installer::TABLE_IMPORTSTATUS.' status INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON status.dataport_resource_id=dataport_resource.id WHERE dataportId = ? AND status = ?',
                    [$dataportId, ImportStatus::STATUS_RUNNING]
                );
                $currentlyRunning = array_map(static function ($row) {
                    return (int)$row['dataport_resource_id'];
                }, $currentlyRunning);

                $result = 0;
                $dataportResources = DataportResource::getInstance();

                $unusedDataportResourceQuery = 'SELECT id FROM '.Installer::TABLE_DATAPORT_RESOURCE.' WHERE dataportId = ? AND id IN (SELECT dataport_resource_id FROM '.Installer::TABLE_RAWITEM.')';
                $unusedDataportResourceParameters = [$dataportId];
                if ($currentlyRunning) {
                    $unusedDataportResourceQuery .= ' AND id NOT IN (?)';
                    $unusedDataportResourceParameters[] = $currentlyRunning;
                }
                foreach ($dataportResources->findColumnInSql($unusedDataportResourceQuery, $unusedDataportResourceParameters) as $dataportResourceId) {
                    do {
                        $deletedRows = $dataportResources->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id = ? ORDER BY id LIMIT 10000', [$dataportResourceId]);
                        $result += $deletedRows;
                    } while ($deletedRows === 10000);
                }

                $output->writeln('Deleting rawdata of dataport '.$dataportId);
            } else {
                $output->writeln('Deleting all rawdata');
                $result = RawItem::getInstance()->execute('DELETE FROM '.Installer::TABLE_RAWITEM);
            }

            $output->writeln($result.' entries deleted.');
        } finally {
            if($dataportId) {
                $directoryIterator = new \DirectoryIterator(Installer::getCachePath());
                $filterIterator = new \CallbackFilterIterator($directoryIterator, static function (\SplFileInfo $fileInfo) use ($dataportId) {
                    return strpos($fileInfo->getFilename(), 'result_'.$dataportId.'_') === 0;
                });
                /** @var \SplFileInfo $compiledFileInfo */
                foreach ($filterIterator as $cachedResultFile) {
                    unlink($cachedResultFile->getPathname());
                }

                Cache::clearTag('mapping-preview-'.$dataportId);
            }
        }

        return 0;
    }
}