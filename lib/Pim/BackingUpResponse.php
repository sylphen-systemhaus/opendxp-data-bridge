<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use Exception;
use Symfony\Component\Mime\MimeTypes;
use OpenDxp\Tool\Mime;

if (PHP_VERSION_ID > 80000) {
    class BackingUpResponse extends BackingUpResponsePhp8 {}
} else {
    class BackingUpResponse extends \Symfony\Component\HttpFoundation\Response
    {
        use BackingUpResponsePhpCompatibilityTrait;

        /**
         * @return string
         */
        public function getContent()
        {
            return $this->doGetContent();
        }

        /**
         * @param string $content
         * @return static
         */
        public function setContent($content)
        {
            return $this->doSetContent($content);
        }

        /**
         * Sends content for the current web response.
         *
         * @return $this
         */
        public function sendContent()
        {
            return $this->doSendContent();
        }
    }
}