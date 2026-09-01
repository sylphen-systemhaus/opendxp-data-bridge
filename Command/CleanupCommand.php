<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\Maintenance\CleanupImportStatus;
use OpenDxp\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends AbstractCommand
{
    private $cleanupImportStatus;

    public function __construct(CleanupImportStatus $cleanupImportStatus)
    {
        parent::__construct();
        $this->cleanupImportStatus = $cleanupImportStatus;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:cleanup';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clean up Data Bridge resources, temporary files etc. The same logic gets called by OpenDxp\'s maintenance job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cleanupImportStatus->execute();
        return 0;
    }
}
