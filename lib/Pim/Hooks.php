<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

interface Hooks {
	public function shouldCreateItem($dataPortId, $rawData);
	
	public function shouldDeleteItem($dataPortId, $rawData);
	
	/*
	 *  @param Object $item the object to be updated
	 *  @return null to test all fields OR
	 *  		array with the names of the fields that should be tested (fields in Localisedfields or Bricks will be checked)
	 */
	public function fieldsToTestForIsModified($item);
}