<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use OpenDxp\Helper\Mail as MailHelper;
use OpenDxp\Model\Document;
use Twig\Environment;

trait MailPhpCompatibilityTrait
{
    private function resolveParams(string $string, string $context): string
    {
        $twig = new Environment(new \Twig\Loader\ArrayLoader(), array(
            'autoescape' => false
        ));

        $template = $twig->createTemplate($string);

        return $template->render($this->getParams());
    }

    /**
     * Renders the content (Twig) and returns the rendered HTML
     *
     * @return string|null
     * @internal
     *
     */
    public function doGetBodyHtmlRendered()
    {
        $html = method_exists($this, 'getHtmlBody') ? $this->getHtmlBody() : $this->getBody();

        // if the content was manually set with $obj->setBody(); this content will be used
        // and not the content of the Document!
        if (!$html) {
            // render document
            if ($this->getDocument() instanceof Document) {
                $attributes = $this->getParams();
                $attributes['_force_allow_processing_unpublished_elements'] = true;

                $html = Document\Service::render($this->getDocument(), $attributes);
            }
        }

        $content = null;
        if ($html) {
            $content = $this->resolveParams($html, 'body');

            // modifying the content e.g set absolute urls...
            $content = preg_replace_callback("@<link.*?href\s*=\s*[\"'](.*?)[\"'].*?(/?>|</\s*link>)@is", static function($match) {
                return preg_replace('@/cache-buster\-[\d]+\/@', '/', $match[0]);
            }, $content);
            $content = MailHelper::embedAndModifyCss($content, $this->getDocument());
            $content = MailHelper::setAbsolutePaths($content, $this->getDocument(), $this->getHostUrl());
        }

        return $content;
    }

    public function getSubject(): ?string
    {
        $subject = parent::getSubject();

        if (!$subject && $this->getDocument()) {
            $subject = $this->getDocument()->getSubject();
        }

        return $subject;
    }

    public function setSubject($subject)
    {
        if (method_exists($this, 'subject')) {
            $this->subject($subject);
        } else {
            parent::setSubject($subject);
        }
    }

    public function setBodyHtml($body, string $charset = 'utf-8')
    {
        if (method_exists($this, 'html')) {
            $this->html($body, $charset);
        } else {
            parent::setBodyHtml($body, $charset);
        }
    }
}