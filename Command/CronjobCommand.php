<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\Maintenance\CleanupImportStatus;
use Sylphen\DataBridgeBundle\Maintenance\CronjobTask;
use OpenDxp\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CronjobCommand extends AbstractCommand
{
    private $cronjobTask;

    public function __construct(CronjobTask $cronjobTask)
    {
        parent::__construct();
        $this->cronjobTask = $cronjobTask;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:cronjobs';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Queue dataport runs for automatic dataports which cannot be run event-based (e.g. URL-based imports)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cronjobTask->execute();
        return 0;
    }
}
