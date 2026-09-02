<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Tools;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

class InstallerKernel
{
    use MicroKernelTrait;

    /**
     * @var string
     */
    private $projectRoot;

    public function __construct(string $projectRoot, string $environment, bool $debug)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function getProjectDir()// : string
    {
        return OPENDXP_PROJECT_ROOT;
    }

    /**
     * @return string
     */
    public function getBundlesConfigFile() {
        return $this->getBundlesPath();
    }
}