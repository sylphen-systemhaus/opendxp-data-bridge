<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Event;

use OpenDxp\Model\DataObject\ClassDefinition\Data;
use Symfony\Component\EventDispatcher\GenericEvent;

class CallbackFunctionTemplateEvent extends GenericEvent
{
    const EVENT_NAME = 'sylphen.data-bridge.callback-function-template-event';

    /** @var array Dataport configuration */
    private $dataport;

    /** @var Data Field definition which we try to provide templates for */
    private $fieldDefinition;

    /** @var array */
    private $templates = [];

    /**
     * @param array $dataport
     * @param Data $fieldDefinition
     */
    public function __construct(array $dataport, Data $fieldDefinition)
    {
        parent::__construct();
        $this->dataport = $dataport;
        $this->fieldDefinition = $fieldDefinition;
    }

    /**
     * @return array
     */
    public function getDataport(): array
    {
        return $this->dataport;
    }

    /**
     * @return Data
     */
    public function getFieldDefinition(): Data
    {
        return $this->fieldDefinition;
    }

    public function addTemplate($label, $code, $group = null) {
        $templateData = [
            'value' => $code,
            'key' => $label
        ];

        if($group) {
            $templateData['group'] = $group;
        }

        $this->templates[] = $templateData;
    }

    /**
     * @return array
     */
    public function getTemplates(): array
    {
        return $this->templates;
    }
}