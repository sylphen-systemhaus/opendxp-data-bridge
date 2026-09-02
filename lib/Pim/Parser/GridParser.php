<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Sylphen\SingleSignOnBundle\EventListener\SessionBagListener;
use Sylphen\SingleSignOnBundle\Service\ConfigService;
use Exception;
use InvalidArgumentException;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Bundle\AdminBundle\Event\AdminEvents;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data\CustomResourcePersistingInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\LazyLoadingSupportInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ResourcePersistenceAwareInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Bundle\AdminBundle\Model\GridConfig;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Model\User;
use OpenDxp\Tool;
use OpenDxp\Tool\Admin;
use OpenDxp\Tool\Session;
use OpenDxp\Tool\Storage;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class GridParser implements Parser
{
    use IteratableParser;

    /** @var array */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var AbstractListing */
    private $listing;

    /** @var int */
    private $count;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var GridConfig */
    private $gridConfig;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

    public function setConfig(array $config) {
        $this->config = $config;
        if (empty($this->config['fields'])) {
            throw new \Exception('Please configure raw data fields');
        }
    }

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * @return array|null
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        if (!$this->getListing()->valid()) {
            $this->gotoNextImportResource();
            return null;
        }

        $object = $this->getListing()->current();

        foreach ($this->config['fields'] as $fieldIndex => $values) {
            $item[$fieldIndex] = $object[$values['identifier']];
        }

        $this->current = $item;
        $this->getListing()->next();

        return $this->current;
    }

    private function getGridConfig() {
        if($this->gridConfig === null) {
            $this->gridConfig = GridConfig::getById((int)$this->config['file']);
            if (!$this->gridConfig instanceof GridConfig) {
                throw new InvalidArgumentException('Grid config #'.$this->config['file'].' does not exist');
            }
        }
        return $this->gridConfig;
    }

    public function getListing() {
        if ($this->listing === null) {
            $gridConfig = $this->getGridConfig();

            $gridHelperService = new OpenDxp\Bundle\AdminBundle\Helper\GridHelperService(\OpenDxp::getEventDispatcher());

            $user = Helper::getUser();

            $gridConfigConfig = json_decode($gridConfig->getConfig(), true);
            $gridConfigFields = $gridConfigConfig['columns'] ?? [];

            $ids = null;
            if(!empty($gridConfigConfig['ids'])) {
                $ids = explode(',', $gridConfigConfig['ids']);
            }

            $requestParams = ['folderId' => 1, 'classId' => $gridConfig->getClassId(), 'fields' => array_keys($gridConfigFields), 'limit' => PHP_INT_MAX, 'ids' => $ids];
            $this->listing = $gridHelperService->prepareListingForGrid($requestParams, \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale(), $user);
            $this->listing->setUnpublished(true);

            $beforeListLoadEvent = new GenericEvent($this, [
                'list' => $this->listing,
                'context' => $requestParams,
            ]);

            if(defined(AdminEvents::class.'::OBJECT_LIST_BEFORE_LIST_LOAD')) {
                \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($beforeListLoadEvent, AdminEvents::OBJECT_LIST_BEFORE_LIST_LOAD);
                $this->listing = $beforeListLoadEvent->getArgument('list');
            }


            if ($this->limit !== null) {
                $this->listing->setLimit($this->limit + 1);
            }

            // optimize memory usage -> do not load all elements into memory
            $idList = $this->listing->loadIdList();

            if ($user instanceof OpenDxp\Model\User && !$user->isAdmin() && count($idList) === 0) {
                $user->setAdmin(true);
                $elementListing = $gridHelperService->prepareListingForGrid($requestParams, \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale(), $user);
                $beforeListLoadEvent = new GenericEvent($this, [
                    'list' => $elementListing,
                    'context' => $requestParams,
                ]);

                if (defined(AdminEvents::class.'::OBJECT_LIST_BEFORE_LIST_LOAD')) {
                    \OpenDxp::getContainer()->get(EventDispatcher::class)->dispatch($beforeListLoadEvent, AdminEvents::OBJECT_LIST_BEFORE_LIST_LOAD);
                    /** @var OpenDxp\Model\DataObject\Listing $elementListing */
                    $elementListing = $beforeListLoadEvent->getArgument('list');
                }

                $elementListing->setLimit(1);

                if (count($elementListing->loadIdList()) > 0) {
                    $this->logger->warning('Requesting user does not have "view" permission for matching elements');
                }
            }

            $service = new Service();
            $userProxy = new \OpenDxp\Security\User\User($user);

            if (Kernel::MAJOR_VERSION > 10) {
                $token = new UsernamePasswordToken($userProxy, 'opendxp_admin', $userProxy->getRoles());
            } elseif (Kernel::MAJOR_VERSION > 5 || (Kernel::MAJOR_VERSION == 5 && Kernel::MINOR_VERSION >= 4)) {
                $token = new UsernamePasswordToken($userProxy, 'admin', $userProxy->getRoles());
            } else {
                $token = new UsernamePasswordToken($userProxy, $user->getPassword() ?: 'dummy', 'admin', $userProxy->getRoles());
            }
            $this->tokenStorage->setToken($token);

            $helperColumns = [];
            foreach($gridConfigFields as $fieldName => $fieldConfig) {
                if (strpos($fieldName, '#') === 0) {
                    $phpConfig = json_encode($fieldConfig['fieldConfig']);
                    $phpConfig = json_decode($phpConfig);
                    $helperColumns = [];
                    $helperColumns[$fieldName] = $phpConfig;
                }
            }

            if($helperColumns) {
                Tool\Session::useBag(Helper::getRequest()->getSession(), function (AttributeBagInterface $session) use ($helperColumns) {
                    $existingColumns = $session->get('helpercolumns', []);
                    $helperColumns = array_merge($helperColumns, $existingColumns);
                    $session->set('helpercolumns', $helperColumns);
                }, 'opendxp_gridconfig');
            }

            $this->listing = new ArrayMapIterator($idList, function ($id) use ($service, $gridConfigFields) {
                $object = Concrete::getById($id);

                return $service->gridObjectData($object, array_keys($gridConfigFields), \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale());
            });
            $this->listing->rewind();
        }
        return $this->listing;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        if ($this->count === null) {
            $this->count = $this->getListing()->count();
        }

        return $this->count;
    }

    public function gotoNextImportResource()
    {
        $this->current = null;
        return false;
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return $this->config['file'];
    }
}