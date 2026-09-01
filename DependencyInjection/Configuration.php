<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        if (Kernel::MAJOR_VERSION >= 5) {
            $treeBuilder = new TreeBuilder('sylphen_data_bridge');
            $rootNode = $treeBuilder->getRootNode();
        } else {
            $treeBuilder = new TreeBuilder();
            $rootNode = $treeBuilder->root('sylphen_data_bridge');
        }

        $rootNode
            ->children()
                ->integerNode('ajax_timeout')
                    ->defaultValue(30)
                ->end()
                ->arrayNode('gui_translation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('asset')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('active')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('translation_key_prefix')
                                    ->defaultValue('')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('document')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('active')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('translation_key_prefix')
                                    ->defaultValue('')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('object')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('active')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('translation_key_prefix')
                                    ->defaultValue('')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('queue_processing')
                    ->children()
                        ->booleanNode('automatic_start')
                            ->info('Automatically start queue processing when a new job gets added to the queue')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('text_generation')
                    ->children()
                        ->scalarNode('openai_api_key')
                            ->info('API key to use for automatic text generation with OpenAI API')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('admin_style')
                    ->children()
                        ->enumNode('tooltip')
                            ->values(['grid-fields'])
                            ->info('Extend Pimcore admin UI element tree tooltip')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('translation')
                    ->children()
                        ->arrayNode('languages')
                            ->info('Define which target language shall be used for certain Pimcore languages, e.g. en: en-gb')
                            ->useAttributeAsKey('language')
                            ->prototype('scalar')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
