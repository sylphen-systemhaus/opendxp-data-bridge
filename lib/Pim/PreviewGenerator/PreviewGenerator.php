<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\PreviewGenerator;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Model\DataObject\ClassDefinition\PreviewGeneratorInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Tool;

class PreviewGenerator implements PreviewGeneratorInterface
{
    public function generatePreviewUrl(Concrete $object, array $params): string
    {
        if(empty($params['dataport'])) {
            return \OpenDxp::getContainer()->get('router')->generate('data_bridge_dependency_visualization', ['id' => $object->getId()]);

        }

        if ($params['dataport'] === 'data_table') {
            return \OpenDxp::getContainer()->get('router')->generate('data_bridge_object_preview', ['id' => $object->getId()]);
        }

        return \OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($params['dataport']), 'query' => Helper::prefixObjectSystemColumn('id').'='.$object->getId(), 'locale' => $params['locale']]);
    }

    public function getPreviewConfig(Concrete $object): array
    {
        $options = [];
        $languageOptions = [];
        foreach(Tool::getValidLanguages() as $language) {
            $languageOptions[\Locale::getDisplayLanguage($language, \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->getLocale())] = $language;
        }

        $options[] = [
            'name' => 'locale',
            'label' => 'Language',
            'values' => $languageOptions,
            'defaultValue' => Tool::getDefaultLanguage()
        ];

        $dataportsWithPreview = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT dataportId 
            FROM '.Installer::TABLE_FIELDMAPPING.' 
            WHERE fieldName=? AND calculation LIKE ?',
            ['__result_callback', '%\'Content-Disposition\', \'inline\'%']
        );

        $matchingDataports = [];
        foreach($dataportsWithPreview as $dataportId) {
            $dataport = Dataport::getInstance()->get($dataportId);
            $sourceConfig = $dataport['sourceconfig'];

            if (($sourceConfig['sourceClass'] ?? null) == $object->getClassId()) {
                $matchingDataports[$dataport['name']] = $dataport['name'];
            }
        }

        $lowestDistance = INF;
        $defaultDataport = reset($matchingDataports) ?: null;
        foreach($matchingDataports as $dataport) {
            $distance = levenshtein($dataport, $object->getClassName());
            if($distance < $lowestDistance) {
                $lowestDistance = $distance;
                $defaultDataport = $dataport;
            }
        }

        asort($matchingDataports);

        $dataportOption = [
            'name' => 'dataport',
            'label' => 'Dataport',
            'values' => $matchingDataports,
            'defaultValue' => $defaultDataport
        ];

        $dataportOption['values']['Dependency visualization'] = '';
        $dataportOption['values']['Data Table'] = 'data_table';

        $options[] = $dataportOption;

        return $options;
    }
}