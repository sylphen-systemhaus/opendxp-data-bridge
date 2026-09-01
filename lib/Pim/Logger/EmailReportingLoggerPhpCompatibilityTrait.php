<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Config;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use OpenDxp\Mail;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\User;
use OpenDxp\Model\User\Listing;
use OpenDxp\Tool;
use Psr\Log\LogLevel;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Throwable;

trait EmailReportingLoggerPhpCompatibilityTrait
{
    /** @var int */
    private $dataportId;

    /** @var resource */
    private $temporaryFileHandle;

    /** @var Environment */
    private $renderingEngine;

    /** @var RouterInterface */
    private $router;

    /** @var FileObject */
    private $logFileObject;

    public function __construct(Environment $renderingEngine, RouterInterface $router)
    {
        $this->renderingEngine = $renderingEngine;
        $this->router = $router;

        $this->temporaryFileHandle = fopen('php://temp', 'wb');
    }

    public function __destruct()
    {
        rewind($this->temporaryFileHandle);
        $logs = [];
        while (!feof($this->temporaryFileHandle)) {
            $log = json_decode(stream_get_line($this->temporaryFileHandle, PHP_INT_MAX, self::LINE_DELIMITER), true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                continue;
            }

            if (empty($log['message'])) {
                continue;
            }

            foreach ($logs as &$existingLog) {
                if (similar_text($log['message'], $existingLog['message'], $percentage) && $percentage >= 90) {
                    $existingLog['count']++;
                    continue 2;
                }
            }
            unset($existingLog);

            $log['count'] = 1;
            $logs[] = $log;
        }

        if (!$logs) {
            @fclose($this->temporaryFileHandle);
            return;
        }

        $dataport = Dataport::getInstance()->get($this->dataportId);

        $domain = parse_url(Helper::getHostUrl(), PHP_URL_HOST);
        if (empty($domain)) {
            $domain = Helper::getPimcoreSystemConfiguration('general')['domain'];
        }
        if (empty($domain)) {
            $domain = Tool::getHostname();
        }

        $mail = new \Sylphen\DataBridgeBundle\lib\Pim\Mail();
        if (empty($mail->getFrom())) {
            $mailFrom = Helper::getPimcoreSystemConfiguration('email')['debug']['email_addresses'];
            if (empty($mailFrom) && $domain) {
                $mailFrom = 'no-reply@'.$domain;
            }
            if (empty($mailFrom)) {
                $mailFrom = 'no-reply@Pimcore';
            }

            if (method_exists($mail, 'from')) {
                $mail->from($mailFrom);
            } else {
                $mail->setFrom($mailFrom);
            }
        }

        $targetConfig = $dataport['targetconfig'];
        $subject = '['.$dataport['name'].'] '.(empty($targetConfig['itemClass']) ? 'Export' : 'Import').' errors'.($domain ? ' ('.$domain.')' : '');
        $mail->setSubject($subject);

        $emailLog = new \OpenDxp\Model\Tool\Email\Log\Listing();
        $emailLog->addConditionParam('subject=?', $subject);
        $timeThreshold = 0;
        if (file_exists(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json')) {
            $timeThreshold = filemtime(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json');
        }
        $emailLog->addConditionParam('sentDate > ?', max($timeThreshold, time() - 300));
        $emailLog->setLimit(1);
        if ($emailLog->getTotalCount()) {
            return;
        }

        $logFileLink = '';
        if ($this->logFileObject instanceof FileObject && Helper::getApplicationLogStorage()->fileExists($this->logFileObject->getSystemPath())) {
            $logFileLink = $this->logFileObject->getSystemPath();
        }

        $html = $this->renderingEngine->render(
            '@SylphenDataBridge/email-report.html.twig',
            [
                'dataport' => $dataport,
                'fileObjectUrl' => $logFileLink ? Helper::getHostUrl().'/admin/SylphenDataBridge/import/log/'.$logFileLink : '',
                'logs' => $logs
            ]
        );

        $mail->setBodyHtml($html);

        $userListing = new Listing();
        $userListing->addConditionParam('active=1 AND email!=\'\' AND email IS NOT NULL');
        if (!empty($targetConfig['errorRecipients'])) {
            $userListing->addConditionParam('id IN ('.rtrim(str_repeat('?,', count($targetConfig['errorRecipients'])), ',').')', $targetConfig['errorRecipients']);
        }

        foreach ($userListing->getUsers() as $user) {
            try {
                if(!$user instanceof User || !Dataport::canDataportBeConfiguredBy($this->dataportId, $user)) {
                    continue;
                }

                $mail->addTo($user->getEmail());
            } catch (Throwable $e) {
                \OpenDxp\Logger::info('Could not send error notifying mail to user "'.$user->getEmail().'" which is the mail address of '.$user->getName());
            }
        }

        try {
            $mail->send();
        } catch (\Exception $e) {
            error_log('Error log mail could not be sent: '.$e->getMessage()."\n\nFollowing errors happended: \n".$mail->getBodyTextRendered());
            echo 'Error log mail could not be sent: '.$e->getMessage()."\n\nFollowing errors happended: \n".$mail->getBodyTextRendered();
        }

        @fclose($this->temporaryFileHandle); // do not close earlier, otherwise mail-sending errors do not appear in the log file object
    }

    /**
     * @param int $dataportId
     */
    public function setDataportId(int $dataportId): void
    {
        $this->dataportId = $dataportId;
    }

    /**
     * @param FileObject $logFileObject
     */
    public function setLogFileObject(FileObject $logFileObject): void
    {
        $this->logFileObject = $logFileObject;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function doLog($level, $message, array $context = array())
    {
        if ($this->dataportId && in_array($level, [LogLevel::ALERT, LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::WARNING], true)) {
            $hostUrl = Helper::getHostUrl();
            $deeplink = null;
            if ($hostUrl !== '' && ($context['relatedObject'] ?? null) instanceof ElementInterface) {
                // Decide what kind of link to create
                $objectType = $subtype = 'object';
                if ($context['relatedObject'] instanceof \OpenDxp\Model\Document) {
                    $objectType = 'document';
                    $subtype = $context['relatedObject']->getType();
                } elseif ($context['relatedObject'] instanceof \OpenDxp\Model\Asset) {
                    $objectType = 'asset';
                    $subtype = $context['relatedObject']->getType();
                }
                $deeplink = $hostUrl.$this->router->generate('opendxp_admin_login_deeplink').'?'.$objectType.'_'.$context['relatedObject']->getId().'_'.$subtype;
            }

            fwrite($this->temporaryFileHandle, json_encode(['level' => $level, 'relatedObject' => ($deeplink ? '<a href="'.$deeplink.'">'.$context['relatedObject']->getFullPath().'</a>' : ''), 'message' => $message]).self::LINE_DELIMITER);
        }
    }
}