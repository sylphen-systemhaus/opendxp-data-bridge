<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Security\Guard;

use Sylphen\DataBridgeBundle\model\ApiKeys;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTimeImmutable;
use Exception;
use OpenDxp;
use OpenDxp\Bundle\AdminBundle\Security\Authentication\Token\TwoFactorRequiredToken;
use OpenDxp\Security\User\User as UserProxy;
use OpenDxp\Model\User;
use OpenDxp\Tool\Authentication;
use OpenDxp\Version;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
use Throwable;

/**
 * This class does get used in services.yml of some projects. Do not delete it
 */
class RestApiAuthenticator extends AbstractGuardAuthenticator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        throw $this->createAccessDeniedException($authException);
    }

    /**
     * @inheritDoc
     */
    public function getCredentials(Request $request)
    {
        // check for API key header
        if ($apiKey = $request->headers->get('x_api-key')) {
            return $apiKey;
        }

        // check for API key parameter
        if ($apiKey = $request->get('apikey')) {
            return $apiKey;
        }

        // check for existing session user
        if (null !== $pimcoreUser = Authentication::authenticateSession()) {
            return $pimcoreUser;
        }

        throw $this->createAccessDeniedException();
    }

    private function createAccessDeniedException(Throwable $previous = null)
    {
        return new AccessDeniedHttpException('API request needs either a valid API key or a valid session', $previous);
    }

    /**
     * @inheritDoc
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        /** @var UserProxy|null $user */
        $user = null;

        $pimcoreUser = null;
        if ($credentials instanceof User) {
            $pimcoreUser = $credentials;
        } elseif (is_string($credentials)) {
            $pimcoreUser = $this->loadUserForApiKey($credentials);
        }

        if ($pimcoreUser) {
            if (!$pimcoreUser->getPassword()) {
                $pimcoreUser->setPassword(md5(uniqid()));
            }
            $user = new UserProxy($pimcoreUser);
        }

        if ($user && Authentication::isValidUser($user->getUser())) {
            return $user;
        }

        return null;
    }

    /**
     * @param string $apiKey
     *
     * @return User|null
     */
    protected function loadUserForApiKey($apiKey)
    {
        $apiKeys = ApiKeys::getInstance();
        $params = [$apiKey, new DateTimeImmutable()];
        $user = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT api_keys.users_id 
            FROM '.Installer::TABLE_API_KEYS.' api_keys 
            INNER JOIN users ON api_keys.users_id=users.id 
            WHERE api_keys.api_key = ? AND (api_keys.valid_to >= ? OR api_keys.valid_to IS NULL) AND users.active=1',
            $params
        );

        if ($user) {
            return User::getById($user);
        }

        $userList = new User\Listing();
        $userList->setCondition('apiKey = ? AND type = ? AND active = 1', [$apiKey, 'user']);
        $userList->setLimit(1);
        $userList->load();

        return $userList->getUsers()[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        return $user instanceof UserProxy;
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $this->logger->warning(
            'Failed to authenticate for webservice request {path}',
            [
                'path' => $request->getPathInfo(),
            ]
        );

        throw $this->createAccessDeniedException($exception);
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $this->logger->debug(
            'Successfully authenticated user {user} for webservice request {path}',
            [
                'user' => $token->getUser()->getUsername(),
                'path' => $request->getPathInfo(),
            ]
        );

        return null;
    }

    /**
     * @inheritDoc
     */
    public function supportsRememberMe()
    {
        return false;
    }
}
