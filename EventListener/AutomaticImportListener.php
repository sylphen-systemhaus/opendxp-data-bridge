<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\Command\CheckAutomaticImportCommand;
use Sylphen\DataBridgeBundle\Controller\ImportController;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Db;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Event\Model\ElementEventInterface;
use OpenDxp\Logger;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Element\DirtyIndicatorInterface;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\User;
use OpenDxp\Model\Version;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;

class AutomaticImportListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private static $processCommandRegistered = [];

    private static $dataports = null;

    private static $currentlyInProcess = [];

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    public function __construct(ItemMoldBuilder $itemMoldBuilder)
    {
        $this->itemMoldBuilder = $itemMoldBuilder;
    }

    private static function getDataports() {
        if(self::$dataports === null) {
            self::$dataports = Dataport::getInstance()->find([], 'name');
        }

        return self::$dataports;
    }

    public function startImports(ElementEventInterface $e) {
        try {
            if ($e->getArgument('saveVersionOnly') && $e->getArgument('isAutoSave')) {
                return;
            }
        } catch (\InvalidArgumentException $exception) {
        }

        $object = $e->getElement();
        if(!$object instanceof ElementInterface) {
            return;
        }

        if(isset(self::$currentlyInProcess[$object->getId()])) {
            return;
        }

        self::$currentlyInProcess[$object->getId()] = true;

        $user = Helper::getUser();

        $savedDraftVersion = false;
        try {
            if ($e->getArgument('saveVersionOnly')) {
                $savedDraftVersion = true;
            }
        } catch (\InvalidArgumentException $exception) {
        }

        if($user->getId() == 0) {
            $objectIsLocked = true;
        } else {
            $objectIsLocked = (bool)PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM edit_lock WHERE cid=? AND ctype=? AND userId=?', [$object->getId(), Service::getElementType($object), 0]);
        }

        if($objectIsLocked) {
            if(count(CheckAutomaticImportCommand::getAutomaticDirectDataports($object)) > 0 || count(CheckAutomaticImportCommand::getQueueableAutomaticDataports($object)) > 0) {
                PimcoreDbRepository::retry(static function () use ($object, $user, $savedDraftVersion) {
                    Queue::getInstance()->create([
                        'command' => 'data-bridge:start-automatic-dataports '.$object->getId().' '.Service::getElementType($object).' --direct --user='.$user->getId().($savedDraftVersion ? ' --draft-version' : ''),
                        'triggered_by' => $object->getRealFullPath().' got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                        'worker_id' => 'check_automatic_run'
                    ]);
                });
            }
        } else {
            foreach (CheckAutomaticImportCommand::getAutomaticDirectDataports($object) as $dataport) {
                $commands = CheckAutomaticImportCommand::getJobsToQueue($dataport, $object, $user, new NullOutput(), $savedDraftVersion ? ['draft-version' => true] : []);

                foreach (array_column($commands, 'command') as $command) {
                    Cli::exec($command);
                }
            }

            if(Queue::getInstance()->countRows() < 10000) {
                PimcoreDbRepository::retry(static function () use ($object, $user, $savedDraftVersion) {
                    Queue::getInstance()->create([
                        'command' => 'data-bridge:start-automatic-dataports '.$object->getId().' '.Service::getElementType($object).' --user='.$user->getId().($savedDraftVersion ? ' --draft-version' : ''),
                        'triggered_by' => $object->getRealFullPath().' got saved'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                        'worker_id' => 'check_automatic_run'
                    ]);
                });
            } else {
                $queueAddItems = [[]];
                foreach(CheckAutomaticImportCommand::getQueueableAutomaticDataports($object) as $dataport) {
                    $commands = CheckAutomaticImportCommand::getJobsToQueue($dataport, $object, $user, new NullOutput(), $savedDraftVersion ? ['draft-version' => true] : []);
                    $queueAddItems[] = $commands;
                }
                $queueAddItems = array_merge(...$queueAddItems);

                if (count($queueAddItems) > 0) {
                    Queue::getInstance()->create($queueAddItems);
                }
            }
        }

        unset(self::$currentlyInProcess[$object->getId()]);
    }

    public function deleteRawdata(ElementEventInterface $e)
    {
        if (\method_exists($e, 'getArgument')) {
            try {
                $saveVersionOnly = $e->getArgument('saveVersionOnly');
                if ($saveVersionOnly) {
                    return;
                }
            } catch (\InvalidArgumentException $exception) {
            }
        }

        $object = $e->getElement();
        $objectType = Service::getElementType($object);

        $user = Helper::getUser();

        foreach (self::getDataports() as $dataport) {
            $sourceConfig = $dataport['sourceconfig'];
            $targetConfig = $dataport['targetconfig'];

            if (!empty($sourceConfig['autoImport']) && empty($sourceConfig['incrementalExport']) && empty($targetConfig['itemClass'])) {
                $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
                if ($object instanceof $itemMold) {
                    PimcoreDbRepository::retry(static function () use ($object, $dataport, $objectType, $user) {
                        Queue::getInstance()->create([
                            'command' => 'data-bridge:delete-rawdata --dataport='.$dataport['id'].' --object-id='.$object->getId().' --object-type='.$objectType,
                            'triggered_by' => $object->getRealFullPath().' got deleted'.(($user instanceof User) ? ' by '.$user->getUsername() : ''),
                            'worker_id' => $dataport['id']
                        ]);
                    });
                }
            }
        }

        PimcoreDbRepository::getInstance()->execute('DELETE FROM '.Installer::TABLE_QUEUE.' WHERE command LIKE ?', ['data-bridge:start-automatic-dataports '.$object->getId().' '.$objectType.' %']);
    }
}