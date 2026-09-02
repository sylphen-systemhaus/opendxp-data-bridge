<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use OpenDxp\Model\DataObject\ClassDefinition\Layout;

class Button extends Layout
{
    /**
     * Static type of this element
     *
     * @internal
     *
     * @var string
     */
    public $fieldtype = 'dd_button';

    /**
     * @internal
     *
     * @var string
     */
    public $dataportId;

    /**
     * @internal
     *
     * @var string
     */
    public $text;

    /**
     * @internal
     *
     * @var string
     */
    public $icon;

    /**
     * @return string
     */
    public function getText()
    {
        return $this->text;
    }

    /**
     * @param string $text
     *
     * @return $this
     */
    public function setText($text)
    {
        $this->text = $text;

        return $this;
    }

    /**
     * @return string
     */
    public function getDataportId()
    {
        return $this->dataportId;
    }

    /**
     * @param string $dataportId
     *
     * @return $this
     */
    public function setDataportId($dataportId)
    {
        $this->dataportId = $dataportId;

        return $this;
    }

    /**
     * @return string
     */
    public function getIcon()
    {
        return $this->icon;
    }

    /**
     * @param string $icon
     *
     * @return $this
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;

        return $this;
    }
}