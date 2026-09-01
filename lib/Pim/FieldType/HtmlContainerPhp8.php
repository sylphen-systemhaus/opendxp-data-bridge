<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FieldType;

use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use OpenDxp;
use OpenDxp\Model\DataObject\ClassDefinition\Data\LayoutDefinitionEnrichmentInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Layout;
use OpenDxp\Model\DataObject\Concrete;

class HtmlContainerPhp8 extends Layout implements LayoutDefinitionEnrichmentInterface
{
    use HtmlContainerPhpCompatibilityTrait;

    /**
     * {@inheritdoc}
     */
    public function enrichLayoutDefinition(?Concrete $object, array $context = []): static
    {
        $importer = OpenDxp::getContainer()->get(ImporterInterface::class);
        $this->html = $importer->replaceObjectIdentifier($this->getHtml(), $object);

        return $this;
    }
}