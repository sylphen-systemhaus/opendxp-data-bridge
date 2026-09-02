<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Version;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RevertImportCommand extends AbstractCommand
{
    /** @var ItemMoldBuilder */
    private $helper;

    /** @var resource|null */
    private $logFileHandle;

    public function __construct(ItemMoldBuilder $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    public function __destruct()
    {
        if(is_resource($this->logFileHandle)) {
            @fclose($this->logFileHandle);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reverts object changes of an import by setting field contents of mapped fields to the version which was up to date before the given date')
            ->setHelp('Only mapped fields are converted, so if the object got changed manually or via another import, fields which have no attribute mapping for the given dataport, objects keep their values as they are now.')
            ->addArgument('objects', InputArgument::REQUIRED, 'Comma-separated list of object ids which shall be reverted. Alternatively provide SQL condition to use (based on target class of set dataport)')
            ->addArgument('date', InputArgument::REQUIRED, 'Date (format is automatically detected, but it is safer to use YYYY-MM-DD HH:MM:SS or Unix timestamp) to which object state shall be reverted')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to restore. If omitted, all fields get restored. Alternative to --dataport')
            ->addOption('dataport', null, InputOption::VALUE_REQUIRED, 'Only mapped fields of this dataport will get restores. Alternative to --fields')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only output what would be restored, nothing will get saved')
            ->addOption('status-key', null, InputOption::VALUE_REQUIRED, 'Job ID to retrieve status information for progress bar')
        ;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:revert';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        if($input->getOption('status-key')) {
            $this->logFileHandle = fopen(OPENDXP_LOG_DIRECTORY.'/restore-'.$input->getOption('status-key').'.html', 'wb');
        }

        try {
            $date = $input->getArgument('date');
            if(ctype_digit($date)) {
                $date = new \DateTime('@'.$date);
            } else {
                $date = new \DateTime($date);
            }
        } catch(\Exception $e) {
            $this->logOutput('Cannot understand date format of "'.$input->getArgument('date').'"');
            return 1;
        }

        $objectIds = array_filter(explode(',', $input->getArgument('objects')));
        if(count($objectIds) === 0) {
            $this->logOutput('No matching objects found');
        }

        $this->logOutput('Start rollback of '.count($objectIds).' objects ('.date('Y-m-d H:i:s').')');

        $fields = array_filter(explode(',', $input->getOption('fields')));
        $fieldMappings = [];
        if(!$fields) {
            if($input->getOption('dataport')) {
                $fieldMappings = Fieldmapping::getInstance()->find(['dataportId = ?' => $input->getOption('dataport')]);
            }
        } else {
            foreach($fields as $field) {
                $fieldParts = explode('#', $field);
                $fieldMapping = ['fieldName' => $fieldParts[0]];
                if(isset($fieldParts[1])) {
                    $fieldMapping['locale'] = $fieldParts[1];
                }
                $fieldMappings[] = $fieldMapping;
            }
        }

        foreach($objectIds as $index => $objectId) {
            if($index % 1000) {
                OpenDxp::getContainer()->get(OpenDxp\Helper\LongRunningHelper::class)->cleanUp();
            }

            $object = OpenDxp\Model\Element\Service::getElementById('object', $objectId);

            $this->logOutput('Trying to revert #'.$object->getId().' '. $object->getFullPath());

            if(!$object instanceof AbstractModel) {
                $this->logOutput('<error>Skipping object #'.$objectId.' as the object does not exist anymore</error>');
                continue;
            }

            /** @var Version[] $versions */
            $versions = \array_reverse($object->getVersions());
            foreach($versions as $version) {
                if($version->getDate() <= $date->getTimestamp()) {
                    if ($version->getSerialized()) {
                        // in Version::loadData the runtime cache gets cleared -> restore it afterwards
                        $runtimeCacheData = RuntimeCache::getInstance()->getArrayCopy();
                        @$version->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
                        RuntimeCache::getInstance()->exchangeArray($runtimeCacheData);
                    } else {
                        @$version->loadData(false); // bypass Pimcore bug https://github.com/pimcore/pimcore/pull/8094 in older versions
                    }
                    $versionObject = $version->getData();

                    if($versionObject === null || (!$versionObject instanceof $object && !$object instanceof $versionObject)) {
                        continue;
                    }

                    if($versionObject->getVersionCount() == $object->getVersionCount()) {
                        $this->logOutput('Current latest version is the same as on "'.$date->format('Y-m-d H:i:s').'" -> no change');
                        continue 2;
                    }

                    if(empty($fieldMappings)) {
                        $object = $versionObject;
                    } else {
                        foreach ($fieldMappings as $mapping) {
                            $updatableObject = Importer::getUpdatableObject($versionObject, $mapping);
                            $getter = 'get'.ucfirst($mapping['fieldName']);
                            $value = null;
                            if (method_exists($updatableObject, $getter)) {
                                try {
                                    $method = new \ReflectionMethod($updatableObject, $getter);
                                    $params = $method->getParameters();

                                    if ($method->getNumberOfParameters() >= 1 && $params[0]->name === 'language') {
                                        $value = $updatableObject->$getter($mapping['locale']);
                                    } else {
                                        $value = $updatableObject->$getter();
                                    }
                                } catch (\Throwable $e) {
                                    $this->logOutput('<error>Unable to get value for field "'.$mapping['fieldName'].'". '.$e.'</error>');
                                    continue;
                                }
                            } else {
                                continue;
                            }

                            $updatableObject = Importer::getUpdatableObject($object, $mapping);

                            $setter = 'set'.ucfirst($mapping['fieldName']);
                            try {
                                $method = new \ReflectionMethod($updatableObject, $setter);
                                $params = $method->getParameters();

                                $getter = 'get'.ucfirst($mapping['fieldName']);
                                if ($method->getNumberOfParameters() > 1 && $params[1]->name === 'language') {
                                    $oldValue = $updatableObject->$getter($mapping['locale']);
                                    $this->logOutput('Setting "'.Importer::getLogOutput($value).'" for "'.$mapping['fieldName'].'#'.$mapping['locale'].'" (old value: "'.Importer::getLogOutput($oldValue).'")');
                                    $updatableObject->$setter($value, $mapping['locale']);
                                } else {
                                    $oldValue = $updatableObject->$getter();
                                    $this->logOutput('Setting "'.Importer::getLogOutput($value).'" for "'.$mapping['fieldName'].'" (old value: "'.Importer::getLogOutput($oldValue).'")');
                                    $updatableObject->$setter($value);
                                }
                            } catch (\Throwable $e) {
                                $this->logOutput("<error>Unable to set value for field '{$mapping['fieldName']}, ".$e.'</error>');
                                continue;
                            }

                            if ($updatableObject instanceof AbstractData) {
                                if (is_array($value)) {
                                    $testValue = array_filter($value);
                                } else {
                                    $testValue = $value;
                                }

                                $brickfieldGetter = 'get'.ucfirst($mapping['targetBrickField']);
                                $brickField = $object->$brickfieldGetter();

                                $brickGetter = 'get'.ucfirst($mapping['brickName']);
                                if (!empty($testValue)) {
                                    $brickSetter = 'set'.ucfirst($mapping['brickName']);
                                    $brickField->$brickSetter($updatableObject);
                                } elseif (!$brickField->$brickGetter() instanceof AbstractData) {
                                    continue;
                                }
                            }
                        }
                    }

                    if(!$input->getOption('dry-run')) {
                        $object->setUserModification(0);
                        try {
                            $object->save(['versionNote' => 'Restored from version #'.$version->getId()]);
                        } catch(\Throwable $e) {
                            $this->logOutput('Error trying to revert '.$object->getFullPath().': '.$e->getMessage());
                            continue 2;
                        }

                    }
                    $this->logOutput('Reverted #'.$object->getId().' '.$object->getFullPath());

                    continue 2;
                }
            }

            $this->logOutput('<error>Object '.$object->getFullPath().' has no version of '.$date->format('Y-m-d H:i:s').' or older</error>');
        }
        return 0;
    }

    private function logOutput($log) {
        $this->output->writeln($log);

        if (is_resource($this->logFileHandle)) {
            fwrite($this->logFileHandle, $log."\n");
        }
    }
}