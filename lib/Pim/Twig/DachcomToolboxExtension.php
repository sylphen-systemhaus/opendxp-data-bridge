<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

class DachcomToolboxExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_toolbox_areablock_config', function(Environment $twig, $context, $blockName) {
                if(class_exists(\ToolboxBundle\ToolboxBundle::class)) {
                    $toolboxAreablockConfigFunction = $twig->getFunction('toolbox_areablock_config');
                    if ($toolboxAreablockConfigFunction !== false) {
                        return $toolboxAreablockConfigFunction->getCallable()($context, $blockName);
                    }
                }

                return [];
            }, ['needs_environment' => true, 'needs_context' => true]),
        ];
    }
}