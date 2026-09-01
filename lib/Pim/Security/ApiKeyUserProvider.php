<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Security;

use OpenDxp\Model\DataObject\AbstractObject;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

/**
 * This class does get used in services.yml of some projects. Do not delete it
 */
class ApiKeyUserProvider implements UserProviderInterface
{
    /**
     * The pimcore class name to be used. Needs to be a fully qualified class
     * name (e.g. OpenDxp\Model\DataObject\User or your custom user class extending
     * the generated one.
     *
     * @var string
     */
    protected $className;

    /**
     * @var string
     */
    protected $usernameField = 'username';

    /**
     * ApiKeyUserProvider constructor.
     * @param string $className
     * @param string $usernameField
     */
    public function __construct(string $className, string $usernameField)
    {
        $this->className = $className;
        $this->usernameField = $usernameField;
    }

    /**
     * @inheritDoc
     */
    public function loadUserByUsername($username)
    {
        $getter = sprintf('getBy%s', ucfirst($this->usernameField));

        // User::getByUsername($username, 1);
        $user = call_user_func([$this->className, $getter], $username, 1);
        if ($user instanceof $this->className) {
            return $user;
        }

        throw new UsernameNotFoundException(sprintf('User %s was not found', $username));
    }

    public function loadUserByIdentifier($username): UserInterface
    {
        return $this->loadUserByUsername($username);
    }

    /**
     * @inheritDoc
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof $this->className || !$user instanceof AbstractObject) {
            throw new UnsupportedUserException();
        }

        return call_user_func([$this->className, 'getById'], $user->getId());
    }

    /**
     * @inheritDoc
     */
    public function supportsClass($class): bool
    {
        return $class === $this->className;
    }
}