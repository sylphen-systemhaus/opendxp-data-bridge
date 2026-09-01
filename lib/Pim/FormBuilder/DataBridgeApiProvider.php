<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\FormBuilder;

use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\RawItemField;
use FormBuilderBundle\Model\FormDefinitionInterface;
use FormBuilderBundle\OutputWorkflow\Channel\Api\ApiData;
use FormBuilderBundle\OutputWorkflow\Channel\Api\ApiProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use FormBuilderBundle\Model\OutputWorkflowInterface;
use FormBuilderBundle\Model\OutputWorkflowChannelInterface;
use FormBuilderBundle\Repository\OutputWorkflowRepositoryInterface;

class DataBridgeApiProvider implements ApiProviderInterface
{
    /** @var OutputWorkflowRepositoryInterface */
    private $outputWorkflowRepository;

    public function __construct(OutputWorkflowRepositoryInterface $outputWorkflowRepository)
    {
        $this->outputWorkflowRepository = $outputWorkflowRepository;
    }

    public function getName(): string
    {
        return 'Data Bridge';
    }

    public function getProviderConfigurationFields(FormDefinitionInterface $formDefinition): array
    {
        $dataports = Dataport::getInstance()->find([], 'name');

        $dataportStore = [];
        foreach ($dataports as $dataport) {
            $dataportStore[] = [
                'value' => $dataport['id'],
                'label' => $dataport['name']
            ];
        }

        return [
            [
                'type' => 'select',
                'label' => 'Dataport',
                'name' => 'dataport',
                'store' => $dataportStore,
                'required' => true,
            ]
        ];
    }

    public function getPredefinedApiFields(FormDefinitionInterface $formDefinition, array $providerConfiguration): array
    {
        $fields = RawItemField::getInstance()->find(['dataportId = ?' => $providerConfiguration['dataport']], 'priority');
        $fields = array_map(static function($field) {
            return $field['name'];
        }, $fields);
        return $fields;
    }

    public function process(ApiData $apiData): void
    {
        $dataportId = $apiData->getProviderConfigurationNode('dataport');

        $parameters = $apiData->getApiNodes();

        /** @var OutputWorkflowInterface $outputWorkflow */
        $outputWorkflow = $this->outputWorkflowRepository->findById($apiData->getFormRuntimeData()['form_output_workflow']);

        $formData = $apiData->getForm()->getData()->getData();
        /** @var OutputWorkflowChannelInterface $outputChannel */
        foreach($outputWorkflow->getChannels() as $outputChannel) {
            if($outputChannel->getType() === 'api' && $outputChannel->getConfiguration()['apiProvider'] === $apiData->getApiProviderName()) {
                foreach($formData as $fieldName => $fieldValue) {
                    foreach($outputChannel->getConfiguration()['apiMappingData'] as $mappingData) {
                        if($fieldName === $mappingData['name']) {
                            $fieldDisplayName = $apiData->getForm()->getData()->getFormDefinition()->getField($fieldName)->getDisplayName();
                            if(!array_key_exists($fieldDisplayName, $parameters)) {
                                $parameters[$fieldDisplayName] = $fieldValue;
                            }
                        }
                    }
                }
            }
        }

        $cmd = '"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" data-bridge:complete '.$dataportId.' --force --locale='.$apiData->getLocale().' --parameters='.escapeshellarg(json_encode($parameters));

        Cli::exec($cmd);
    }
}