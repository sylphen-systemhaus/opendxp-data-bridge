<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle;

use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Composer\InstalledVersions;
use OpenDxp\Db;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\Installer\InstallerInterface;
use OpenDxp\Extension\Bundle\OpenDxpBundleAdminClassicInterface;
use OpenDxp\Extension\Bundle\Traits\BundleAdminClassicTrait;
use OpenDxp\Extension\Bundle\Traits\PackageVersionTrait;
use OpenDxp\Model\Asset;
use OpenDxp\Model\WebsiteSetting;

class SylphenDataBridgeBundle extends AbstractOpenDxpBundle implements OpenDxpBundleAdminClassicInterface
{
    use PackageVersionTrait {
        getVersion as protected getComposerVersion;
    }
    use BundleAdminClassicTrait;

    /**
     * @return Installer
     */
    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    /**
     * @return string[]
     */
    public function getJsPaths(): array
    {
        return self::getClientLibraryPaths()['js'];
    }

    public function getEditmodeJsPaths(): array
    {
        return ['/bundles/sylphendatabridge/js/editMode.js'];
    }

    public function getEditmodeCssPaths(): array
    {
        return ['/bundles/sylphendatabridge/css/editMode.css'];
    }

    public static function getClientLibraryPaths() {
        $jsPaths = [
            '/bundles/sylphendatabridge/js/portlets/queueMonitor.js',
            '/bundles/sylphendatabridge/js/portlets/errorMonitor.js',
            '/bundles/sylphendatabridge/js/portlets/taggedElements.js',
            '/bundles/sylphendatabridge/js/fieldType/calculateValueDataQuerySelector/data.js',
            '/bundles/sylphendatabridge/js/fieldType/calculateValueDataQuerySelector/tag.js',
            '/bundles/sylphendatabridge/js/fieldType/inputWithPlaceholders/data.js',
            '/bundles/sylphendatabridge/js/fieldType/inputWithPlaceholders/tag.js',
            '/bundles/sylphendatabridge/js/fieldType/wysiwygWithPlaceholders/data.js',
            '/bundles/sylphendatabridge/js/fieldType/wysiwygWithPlaceholders/tag.js',
            '/bundles/sylphendatabridge/js/fieldType/textareaWithPlaceholders/data.js',
            '/bundles/sylphendatabridge/js/fieldType/textareaWithPlaceholders/tag.js',
            '/bundles/sylphendatabridge/js/fieldType/dataportButton/data.js',
            '/bundles/sylphendatabridge/js/fieldType/dataportButton/tag.js',
            '/bundles/sylphendatabridge/js/fieldType/htmlContainer/data.js',
            '/bundles/sylphendatabridge/js/fieldType/htmlContainer/tag.js',
            '/bundles/sylphendatabridge/js/ImportConfig.js',
            '/bundles/sylphendatabridge/js/plugin.js',
            '/bundles/sylphendatabridge/js/components/DataportPanel.js',
            '/bundles/sylphendatabridge/js/components/DataportPreview.js',
            '/bundles/sylphendatabridge/js/components/ManualImport.js',
            '/bundles/sylphendatabridge/js/components/MappingPanel.js',
            '/bundles/sylphendatabridge/js/gridOperatorDataQuerySelector.js',
            '/bundles/sylphendatabridge/js/gridImportXlsx.js',
            '/bundles/sylphendatabridge/js/gridExport.js',
            '/bundles/sylphendatabridge/js/gridExportCsv.js',
            '/bundles/sylphendatabridge/js/gridExportXml.js',
            '/bundles/sylphendatabridge/js/gridExportJson.js',
            '/bundles/sylphendatabridge/js/reportAdapter.js',
            '/bundles/sylphendatabridge/js/components/VersionPanel.js',
        ];

        $jsPaths[] = '/bundles/opendxpadmin/build/admin/ace-builds/src-min-noconflict/ext-language_tools.js';

        $jsPaths[] = '/bundles/sylphendatabridge/vendor/jquery/jquery.min.js';

        $cssPaths = [
            '/bundles/sylphendatabridge/css/pim.css',
            '/bundles/sylphendatabridge/vendor/php-diff/diff-table.css',
            '/SylphenDataBridge/translation-language-icons'
        ];
        $customCssWebsiteSetting = WebsiteSetting::getByName('custom.css');
        if ($customCssWebsiteSetting instanceof WebsiteSetting && $customCssWebsiteSetting->getData() instanceof Asset) {
            $cssPaths[] = $customCssWebsiteSetting->getData()->getRealFullPath();
        }

        return [
            'css' => $cssPaths,
            'js' => $jsPaths
        ];
    }

    /**
     * @return string[]
     */
    public function getCssPaths(): array
    {
        return self::getClientLibraryPaths()['css'];
    }

    /**
     * Returns the composer package name used to resolve the version
     *
     * @return string
     */
    protected function getComposerPackageName(): string
    {
        return 'sylphen/opendxp-data-bridge';
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        try {
            $version = $this->getComposerVersion();

            if(strpos($version, 'dev') !== false && class_exists(InstalledVersions::class)) {
                $version .= ' ('.InstalledVersions::getReference($this->getComposerPackageName()).')';
            }

            return $version;
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
