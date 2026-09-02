<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use OpenDxp\Bundle\CoreBundle\EventListener\Frontend\ElementListener;
use OpenDxp\Helper\Mail as MailHelper;
use OpenDxp\Model\Document;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class MailPhp8 extends \OpenDxp\Mail
{
    use MailPhpCompatibilityTrait;

    /**
     * Renders the content (Twig) and returns the rendered HTML
     *
     * @return string|null
     * @internal
     *
     */
    public function getBodyHtmlRendered(): null|string
    {
        return $this->doGetBodyHtmlRendered();
    }

    public function setFrom($from)
    {
        $this->from($from);
    }
}