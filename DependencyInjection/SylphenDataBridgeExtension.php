<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\DependencyInjection;

use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class SylphenDataBridgeExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container)
    {
        $bundles = $container->getParameter('kernel.bundles');

        if (isset($bundles['DoctrineMigrationsBundle'])) {
            $config = ['migrations_paths' => ['Sylphen\DataBridgeBundle\Migrations' => '@SylphenDataBridgeBundle/Migrations']];
            foreach ($container->getExtensions() as $name => $extension) {
                if ($name === 'doctrine_migrations') {
                    $container->prependExtensionConfig($name, $config);
                }
            }
        }

        foreach ($container->getExtensions() as $name => $extension) {
            if ($name === 'framework') {
                $container->prependExtensionConfig($name, [
                    'html_sanitizer' => [
                        'sanitizers' => [
                            'opendxp.translation_sanitizer' => [
                                'allow_relative_medias' => true,
                                'allow_elements' => [
                                    'span' => ['class', 'style', 'id'],
                                    'div' => ['class', 'style', 'id'],
                                    'p' => ['class', 'style', 'id'],
                                    'strong' => 'class',
                                    'em' => 'class',
                                    'h1' => ['class', 'id'],
                                    'h2' => ['class', 'id'],
                                    'h3' => ['class', 'id'],
                                    'h4' => ['class', 'id'],
                                    'h5' => ['class', 'id'],
                                    'h6' => ['class', 'id'],
                                    'a' => ['class', 'id', 'href', 'target', 'title', 'rel', 'style'],
                                    'table' => ['class', 'style', 'cellspacing', 'cellpadding', 'border', 'width', 'height', 'id'],
                                    'colgroup' => 'class',
                                    'col' => ['class', 'style', 'id'],
                                    'thead' => ['class', 'id', 'style'],
                                    'tbody' => ['class', 'id', 'style'],
                                    'tr' => ['class', 'id', 'style', 'colspan', 'rowspan'],
                                    'td' => ['class', 'id', 'style', 'colspan', 'rowspan', 'data-row', 'width', 'height'],
                                    'th' => ['class', 'id', 'scope', 'style', 'colspan', 'rowspan', 'width', 'height'],
                                    'ul' => ['class', 'style', 'id'],
                                    'li' => ['class', 'style', 'id'],
                                    'ol' => ['class', 'style', 'id'],
                                    'u' => ['class', 'id'],
                                    'i' => ['class', 'id'],
                                    'b' => ['class', 'id'],
                                    'caption' => ['class', 'id'],
                                    'sub' => ['class', 'id'],
                                    'sup' => ['class', 'id'],
                                    'blockquote' => ['class', 'id'],
                                    's' => ['class', 'id'],
                                    'iframe' => ['frameborder', 'height', 'longdesc', 'name', 'sandbox', 'scrolling', 'src', 'title', 'width'],
                                    'br' => '',
                                    'img' => ['class', 'id', 'alt', 'style', 'src'],
                                    'hr' => '',
                                    $container->getParameter('sylphen_data_bridge.skip_translation_tag') => ''
                                ]
                            ]
                        ]
                    ]
                ]);
            } elseif ($name === 'opendxp') {
                $container->prependExtensionConfig($name, [
                    'translations' => [
                        'domains' => [
                            'DataBridge_Cache',
                            'DataBridge_Glossary'
                        ]
                    ],
                    'assets' => [
                        'image' => [
                            'max_pixels' => 100000000
                        ],
                        'document' => [
                            'process_page_count' => false,
                            'scan_pdf' => false
                        ]
                    ]
                ]);
            }
        }

        $reportAdapterConfig = ['custom_report' => ['adapters' => ['dataBridge' => 'opendxp.custom_report.adapter.factory.dataBridge']]];
        if (isset($bundles['OpenDxpCustomReportsBundle'])) {
            foreach ($container->getExtensions() as $name => $extension) {
                if ($name === 'opendxp_custom_reports') {
                    $container->prependExtensionConfig($name, $reportAdapterConfig['custom_report']);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadInternal(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('data_bridge.config', $config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }
}
