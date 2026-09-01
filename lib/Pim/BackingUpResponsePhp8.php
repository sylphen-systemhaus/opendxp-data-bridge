<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Exception;
use Symfony\Component\Mime\MimeTypes;
use OpenDxp\Tool\Mime;

class BackingUpResponsePhp8 extends \Symfony\Component\HttpFoundation\Response
    {
        use BackingUpResponsePhpCompatibilityTrait;

        /**
         * @return string
         */
        public function getContent(): string
        {
            return $this->doGetContent();
        }

        /**
         * @param string $content
         * @return static
         */
        public function setContent(?string $content): static
        {
            return $this->doSetContent($content);
        }

        /**
         * Sends content for the current web response.
         *
         * @return $this
         */
        public function sendContent(): static
        {
            return $this->doSendContent();
        }
    }