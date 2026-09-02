<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\EventListener\SkipTriggerAutomaticImportException;
use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Cache;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\User;
use OpenDxp\Tool;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckAutomaticImportCommand extends AbstractCommand
{
    private static $automaticDataports = [];
    private static $processCommandRegistered = [];

    /** @var ElementInterface|null */
    private $element;

    /** @var ItemMoldBuilder */
    private static $itemMoldBuilder;

    protected function configure(): void
    {
        $this
            ->setName('data-bridge:start-automatic-dataports')
            ->setDescription('Check which automatic dataports shall be executed and add them to queue')
            ->addArgument('object-id', InputArgument::REQUIRED, 'Object id to be checked')
            ->addArgument('object-type', InputArgument::OPTIONAL, 'Object type. valid values: object, document, asset', 'object')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User id who triggered save')
            ->addOption('draft-version', null, InputOption::VALUE_NONE, 'Saved draft version')
            ->addOption('direct', null, InputOption::VALUE_NONE, 'Also execute automatic dataports which would get executed directly on save but were locked when saving');
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:start-automatic-dataports';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $element = $this->getElement();
        if (!$element instanceof ElementInterface) {
            return 0;
        }

        $objectVersionCountBefore = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT '.($input->getArgument('object-type') === 'object' ? Helper::prefixObjectSystemColumn('versionCount') : 'versionCount').' FROM '.$input->getArgument('object-type').'s WHERE '.($input->getArgument('object-type') === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?',
            [$input->getArgument('object-id')]
        );

        $user = null;
        if($input->getOption('user')) {
            $user = User::getById($input->getOption('user'));
            if (!$user instanceof User) {
                $user = User::getById(0);
            }
        }

        $queue = Queue::getInstance();

        $queueAddItems = [[]];
        $dataports = ['queueable' => self::getQueueableAutomaticDataports($element)];
        if($input->getOption('direct')) {
            $dataports['direct'] = self::getAutomaticDirectDataports($element);
        }

        foreach ($dataports as $type => $dataportList) {
            foreach($dataportList as $dataport) {
                $commands = self::getJobsToQueue($dataport, $element, $user, $output, $input->getOptions());

                if ($type === 'direct') {
                    foreach (array_column($commands, 'command') as $command) {
                        $output->writeln('Executing '.$command);
                        Cli::exec($command);
                    }
                    continue;
                }

                $queueAddItems[] = $commands;
            }
        }
        $queueAddItems = array_merge(...$queueAddItems);

        if (count($queueAddItems) > 0) {
            $queue->create($queueAddItems);
            foreach($queueAddItems as $queueAddItem) {
                $output->writeln('Queued command '.$queueAddItem['command']);
            }
        }

        $objectVersionCountAfter = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT '.($input->getArgument('object-type') === 'object' ? Helper::prefixObjectSystemColumn('versionCount') : 'versionCount').' FROM '.$input->getArgument('object-type').'s WHERE '.($input->getArgument('object-type') === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?',
            [$input->getArgument('object-id')]
        );

        // if element got changed in the meantime, there will not be another queue entry for start-automatic-dataports -> check here and execute again
        if($objectVersionCountAfter > $objectVersionCountBefore) {
            RuntimeCache::clear();
            Cache::clearTag($input->getArgument('object-type').'_'.$input->getArgument('object-id'));

            $this->execute($input, $output);
        }

        return 0;
    }

    private function getElement() {
        if($this->element === null) {
            try {
                $this->element = Service::getElementById($this->input->getArgument('object-type'), (int)$this->input->getArgument('object-id'));

                if (!$this->element instanceof ElementInterface) {
                    $this->output->writeln('Could not find '.$this->input->getArgument('object-type').' #'.$this->input->getArgument('object-id'));
                    return null;
                }
            } catch (\Throwable $e) {
                $this->output->writeln('Error loading '.$this->input->getArgument('object-type').' #'.$this->input->getArgument('object-id').': '.$e);
            }
        }
        return $this->element;
    }

    private static function getDataportsCacheKey(ElementInterface $element) {
        $cacheKey = Service::getElementType($element);
        if($element instanceof Concrete) {
            $cacheKey .= '_'.$element->getClassId();
        }
        return $cacheKey;
    }

    private static function getItemMoldBuilder() {
        if(self::$itemMoldBuilder === null) {
            self::$itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
        }
        return self::$itemMoldBuilder;
    }

    private static function getDataports(ElementInterface $element) {
        $cacheKey = self::getDataportsCacheKey($element);

        if (!isset(self::$automaticDataports[$cacheKey])) {
            self::$automaticDataports[$cacheKey] = ['queueable' => [], 'direct' => []];
            foreach (Dataport::getInstance()->find(['sourceconfig LIKE ?' => '%autoImport\":true%'], 'name') as $dataport) {
                $isQueueable = true;

                $itemMold = self::getItemMoldBuilder()->getItemMold($dataport['id']);
                if ($dataport['sourcetype'] === 'pimcore' && !empty($dataport['targetconfig']['itemClass']) && $dataport['sourceconfig']['sourceClass'] === $dataport['targetconfig']['itemClass'] && $element instanceof $itemMold) {
                    $initAction = Fieldmapping::getInstance()->findOne(
                        [
                            'dataportId = ?' => $dataport['id'],
                            'fieldName = ?' => '__init_action'
                        ]
                    );

                    if (!$initAction || (strpos($initAction['calculation'], '{{ Hours between executions }}') === false && strpos($initAction['calculation'], '{{ Cronjob expression }}') === false)) {
                        $isQueueable = false;
                    }
                }

                if($isQueueable) {
                    self::$automaticDataports[$cacheKey]['queueable'][] = $dataport;
                } else {
                    self::$automaticDataports[$cacheKey]['direct'][] = $dataport;
                }
            }
        }

        return self::$automaticDataports[$cacheKey];
    }

    public static function getQueueableAutomaticDataports(ElementInterface $element)
    {
        return self::getDataports($element)['queueable'];
    }


    public static function getAutomaticDirectDataports(ElementInterface $element): array
    {
        return self::getDataports($element)['direct'];
    }

    private static function queueProcessRawData($dataportResourceId, $dataportId, OutputInterface $output)
    {
        if (!isset(self::$processCommandRegistered[$dataportResourceId])) {
            self::$processCommandRegistered[$dataportResourceId] = true;

            $queue = Queue::getInstance();
            $cmd = 'data-bridge:process '.$dataportId.' --dataport-resource-id='.$dataportResourceId;
            register_shutdown_function(
                static function () use ($queue, $cmd, $dataportId, $output) {
                    $queue->create([
                        'command' => $cmd,
                        'triggered_by' => 'An item got deleted from raw data -> new result document has to be generated',
                        'worker_id' => $dataportId
                    ]);

                    $output->writeln('Queued command '.$cmd);
                }
            );
        }
    }

    private static function extractIds($query, &$match)
    {
        $startTerm = ' IN (';
        $endTerm = ')';
        $start = strpos($query, $startTerm);
        if ($start === false) {
            return null;
        }

        $end = strpos($query, $endTerm, $start);

        if ($end === false) {
            return null;
        }

        $match = [
            substr($query, $start, $end - $start + strlen($endTerm)),
            explode(',', substr($query, $start + strlen($startTerm), $end - $start - strlen($startTerm)))
        ];

        return true;
    }

    public static function getJobsToQueue($dataport, ElementInterface $object, User $user = null, OutputInterface $output = null, array $options = []): array{
        $queueAddItems = [];
        try {
            $sourceConfig = $dataport['sourceconfig'];
            $targetConfig = $dataport['targetconfig'];

            if (!empty($sourceConfig['autoImport'])) {
                $output->writeln('Checking dataport #'.$dataport['id'].' "'.$dataport['name'].'"');
                if (empty($sourceConfig['draftVersions']) && !empty($options['draft-version'])) {
                    return $queueAddItems;
                }

                if ($dataport['sourcetype'] === 'pimcore') {
                    $initAction = Fieldmapping::getInstance()->findOne(
                        [
                            'dataportId = ?' => $dataport['id'],
                            'fieldName = ?' => '__init_action'
                        ]
                    );

                    if ($initAction && (strpos($initAction['calculation'], '{{ Hours between executions }}') !== false || strpos($initAction['calculation'], '{{ Cronjob expression }}') !== false)) {
                        $queueAddItems[] = [
                            'command' => 'data-bridge:complete '.$dataport['id'],
                            'triggered_by' => 'Dataport executed automatically',
                            'worker_id' => $dataport['id']
                        ];

                        return $queueAddItems;
                    }
                }

                $parser = Dataport::getInstance()->getParser($dataport['id']);
                if (method_exists($parser, 'disableLoggingNotFoundImportResource')) {
                    $parser->disableLoggingNotFoundImportResource();
                }

                if (\method_exists($parser, 'setSourceFile') && \method_exists($parser, 'getFileConditionFromObject')) {
                    if (empty($targetConfig['itemClass'])) {
                        // Export
                        if (empty($sourceConfig['incrementalExport'])) {
                            $dataportResources = DataportResource::getInstance()->find(['dataportId = ?' => $dataport['id']]);
                        } else {
                            $dataportResources = [
                                DataportResource::getInstance()->create(
                                    [
                                        'dataportId' => $dataport['id'],
                                        'resource' => \json_encode([
                                            'file' => $parser->getResource(),
                                            'locale' => Tool::getDefaultLanguage()
                                        ], JSON_UNESCAPED_SLASHES)
                                    ]
                                )
                            ];
                        }

                        $dataportResourceIdsStillContainingObject = [];
                        foreach ($dataportResources as $dataportResource) {
                            try {
                                $resourceSettings = \json_decode($dataportResource['resource'], true);
                                if (!empty($resourceSettings['locale'])) {
                                    \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($resourceSettings['locale']);
                                } else {
                                    $resourceSettings['locale'] = \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale();
                                    if ($resourceSettings['locale'] === null) {
                                        $resourceSettings['locale'] = Tool::getDefaultLanguage();
                                    }
                                }

                                if (isset($resourceSettings['file'])) {
                                    $parser->setSourceFile($resourceSettings['file']);
                                }
                                $source = $parser->getFileConditionFromObject($object);
                                if ($source !== null) {
                                    $dataportResourceIdsStillContainingObject[$source][$resourceSettings['locale']][] = $dataportResource['id'];
                                } elseif (empty($sourceConfig['incrementalExport'])) {
                                    $source = $parser->getFileConditionFromObject($object, false);
                                    if ($source !== null) {
                                        $tmpSourceConfig = $sourceConfig;
                                        if (\method_exists($parser, 'setConfig')) {
                                            if (\method_exists($parser, 'getConfig')) {
                                                $tmpSourceConfig = $parser->getConfig();
                                            }
                                            $tmpSourceConfig['file'] = '';

                                            $tmpSourceConfig['dataportId'] = $dataport['id'];
                                            $parser->setConfig($tmpSourceConfig);
                                        }

                                        $parser->setSourceFile($source);

                                        $keyFields = [];
                                        foreach ($tmpSourceConfig['fields'] as $fieldIndex => $field) {
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

                                        if (count($hashs) > 0) {
                                            $countDeletedRawItems = PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id = ? AND hash IN ("'.implode('","', $hashs).'")', [$dataportResource['id']]);
                                            if ($countDeletedRawItems) {
                                                self::queueProcessRawData($dataportResource['id'], $dataport['id'], $output);
                                            }
                                        }
                                    }
                                }
                            } catch (\Throwable $e) {
                                if (!$e instanceof SkipTriggerAutomaticImportException) {
                                    $error = 'Check for automatic start for dataport #'.$dataport['id'].' failed: '.(string)$e;
                                    Logger::error($error);
                                }
                            }
                        }

                        foreach ($dataportResourceIdsStillContainingObject as $dataportResourceQuery => $dataportResourcesWithSameLocale) {
                            foreach ($dataportResourcesWithSameLocale as $locale => $dataportResourceIds) {
                                if (empty($sourceConfig['incrementalExport'])) {
                                    if ($dataport['sourcetype'] === 'pimcore' && self::extractIds($dataportResourceQuery, $match)) {
                                        $ids = $match[1];
                                        sort($ids, SORT_NUMERIC);
                                        foreach ($ids as $id) {
                                            $queueAddItems[] = [
                                                'command' => 'data-bridge:extract '.$dataport['id'].' '.escapeshellarg(str_replace([$match[0]], ['='.$id], $dataportResourceQuery)).(!empty($locale) ? ' --locale='.$locale : '').' --dataport-resource-id='.implode(',', $dataportResourceIds),
                                                'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                                'worker_id' => $dataport['id']
                                            ];
                                        }
                                    } else {
                                        $queueAddItems[] = [
                                            'command' => 'data-bridge:extract '.$dataport['id'].' '.escapeshellarg($dataportResourceQuery).(!empty($locale) ? ' --locale='.$locale : '').' --dataport-resource-id='.implode(',', $dataportResourceIds),
                                            'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                            'worker_id' => $dataport['id']
                                        ];
                                    }
                                } elseif ($dataport['sourcetype'] === 'pimcore' && self::extractIds($dataportResourceQuery, $match)) {
                                    $ids = $match[1];
                                    sort($ids, SORT_NUMERIC);
                                    foreach ($ids as $id) {
                                        $queueAddItems[] = [
                                            'command' => 'data-bridge:complete '.$dataport['id'].' '.escapeshellarg(str_replace([$match[0]], ['='.$id], $dataportResourceQuery)).(!empty($locale) ? ' --locale='.$locale : ''),
                                            'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                            'worker_id' => $dataport['id']
                                        ];
                                    }
                                } else {
                                    $queueAddItems[] = [
                                        'command' => 'data-bridge:complete '.$dataport['id'].' '.escapeshellarg($dataportResourceQuery),
                                        'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                        'worker_id' => $dataport['id']
                                    ];
                                }
                            }
                        }
                    } else {
                        try {
                            // Import
                            if (\method_exists($parser, 'getConfig')) {
                                $parser->setSourceFile($parser->getConfig()['file'] ?? null);
                            }

                            $source = $parser->getFileConditionFromObject($object);

                            if ($source !== null) {
                                if ($dataport['sourcetype'] === 'pimcore' && preg_match('/ IN \(((\d+,?)+)\)/', $source, $match)) {
                                    $ids = explode(',', $match[1]);
                                    sort($ids, SORT_NUMERIC);
                                    foreach ($ids as $id) {
                                        $queueAddItems[] = [
                                            'command' => 'data-bridge:complete '.$dataport['id'].' '.escapeshellarg(str_replace([$match[0]], ['='.$id], $source)).(($user instanceof User) ? ' --user='.$user->getId() : '').' --force',
                                            'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                            'worker_id' => $dataport['id']
                                        ];
                                    }
                                } else {
                                    $queueAddItems[] = [
                                        'command' => 'data-bridge:complete '.$dataport['id'].' '.escapeshellarg($source).' --force',
                                        'triggered_by' => 'Element '.$object->getFullPath().' (#'.$object->getId().') got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                                        'worker_id' => $dataport['id']
                                    ];
                                }
                            }
                        } catch (SkipTriggerAutomaticImportException $e) {
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Logger::error('Automatic processing of dataport #'.$dataport['id'].' "'.$dataport['name'].'" failed:'.(string)$e);
        }

        return $queueAddItems;
    }
}