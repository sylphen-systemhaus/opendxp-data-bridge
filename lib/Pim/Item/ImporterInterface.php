<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use OpenDxp\Model\DataObject\ClassDefinition\Data;
use stdClass;
use Symfony\Component\HttpFoundation\Request;


interface ImporterInterface {
	/**
	 * Importiert alle vorhandenen Rohdatensätze in die dazugehörigen Artikel und Produkte. Nicht vorhandene Artikel
	 * und Produkte werden angelegt.
	 */
	public function import($dataport, $rawItems, ?stdClass $transfer = null, bool $dryRun = false);
}
