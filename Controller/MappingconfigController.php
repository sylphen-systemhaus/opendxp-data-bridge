<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use Sylphen\DataBridgeBundle\EventListener\TranslationListener;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\InputWithPlaceholders;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\TextareaWithPlaceholders;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\Logger;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp;
use OpenDxp\Bundle\AdminBundle\Controller\AdminController;
use OpenDxp\Cache;
use OpenDxp\Controller\FrontendController;
use OpenDxp\Db;
use OpenDxp\Event\Model\TranslationEvent;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Model\User;
use OpenDxp\Model\WebsiteSetting;
use OpenDxp\Tool;
use OpenDxp\Tool\Console;
use OpenDxp\Translation\Translator;
use OpenDxp\Workflow\Dumper\GraphvizDumper;
use Ramsey\Uuid\Nonstandard\UuidV6;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

/**
 * @Route("/admin/{bundle}/mappingconfig", defaults={"bundle"="SylphenDataBridge"}, requirements={"bundle": "SylphenDataBridge"})
 */
class MappingconfigController {

    /** @var Translator */
    private $pimTranslator;

    /** @var Helper */
    private $helper;

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    /** @var ImporterInterface */
    private $importer;

    public function __construct(Translator $pimTranslator, Helper $helper, ItemMoldBuilder $itemMoldBuilder, ImporterInterface $importer)
    {
        $this->pimTranslator = $pimTranslator;
        $this->helper = $helper;
        $this->itemMoldBuilder = $itemMoldBuilder;
        $this->importer = $importer;
    }

    /**
     * @Route("/get-config/{dataportId}")
     */
	public function getConfigAction(Request $request, int $dataportId) {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);
		$config = array(
			'success' => false,
		);

        try {
            $table = Dataport::getInstance();
            $dataport = $table->get($dataportId);

            if ($dataport) {
                $config['success'] = true;
                $config['dataport'] = array(
                    'id' => $dataport['id'],
                    'name' => $dataport['name'],
                );

                $config['mapping'] = $this->helper->createFieldMappings($dataport);

                $config['currentObjectData'] = json_encode($config['mapping'][0]['currentObjectData'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                $config['rawItemData'] = array_filter($config['mapping'][0]['rawItemData'] ?? [], static function($mappingKey) {
                    return strpos($mappingKey, 'field_') === false;
                }, ARRAY_FILTER_USE_KEY);

                foreach($config['rawItemData'] as &$rawItemData) {
                    unset($rawItemData['rawValue']);
                }
                unset($rawItemData);

                $config['virtualFields'] = array_map(
                    static function ($virtualField) {
                        return json_encode($virtualField, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    },
                    array_diff_key(Helper::getEnvironmentVariables(), array_flip([
                        'apikey',
                        '_stopwatch_token',
                        'force',
                        'limit',
                        'offset',
                        '_opendxp_context',
                        'bundle',
                        '_controller',
                        '_firewall_context',
                        '_event_controller',
                        '_opendxp_frontend_request',
                        'DeepL API Key',
                        'OpenAi.com API Key',
                        'queue_processor.max_processing',
                        'GPG_KEYS',
                        'HOME',
                        'PHPIZE_DEPS',
                        'TERM',
                        'PHP_URL',
                        'SHLVL',
                        'COMPOSER_ALLOW_SUPERUSER',
                        'COMPOSER_MEMORY_LIMIT',
                        'PATH',
                        'COMPOSER_HOME',
                        '_',
                        'SYMFONY_DOTENV_VARS',
                        'CONTENT_LENGTH',
                        'CONTENT_TYPE',
                        'REQUEST_METHOD',
                        'DOCTRINE_DEPRECATIONS',
                        'DOCUMENT_URI',
                        'FCGI_ROLE',
                        'GATEWAY_INTERFACE',
                        'HOSTNAME',
                        'PATH_INFO',
                        'PIMCORE_DEV_MODE',
                        'PWD',
                        'QUERY_STRING',
                        'REDIRECT_STATUS',
                        'REQUEST_METHOD',
                        'SCRIPT_FILENAME',
                        'SCRIPT_NAME',
                        'SERVER_PROTOCOL',
                        'SERVER_SOFTWARE',
                        'SHELL_VERBOSITY',
                        'SYMFONY_ASSETS_INSTALL'
                    ]))
                );
                foreach(array_keys($config['virtualFields']) as $virtualFieldName) {
                    if(strpos($virtualFieldName, 'HTTP_') === 0 || strpos($virtualFieldName, 'PHP_') === 0 || strpos($virtualFieldName, 'PIMCORE_INSTALL_') === 0) {
                        unset($config['virtualFields'][$virtualFieldName]);
                    }
                }
                unset($config['virtualFields']['queue_processor.max_processing']);
                ksort($config['virtualFields']);

                foreach ($config['mapping'] as $index => $mapping) {
                    unset($config['mapping'][$index]['rawItemData'], $config['mapping'][$index]['currentObjectData'], $config['mapping'][$index]['virtualFields']);
                }
            }
        } catch(\Throwable $e) {
            $config['success'] = false;
            $config['errorMessage'] = (string)$e;
        }

        $response = new JsonResponse();
        $response->setJson(json_encode($config, JSON_PARTIAL_OUTPUT_ON_ERROR));
        return $response;
	}

    /**
     * @Route("/save-config")
     */
	public function saveConfigAction(Request $request) {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $dataportId = (int)$request->get('dataportId');

        if (!Dataport::canDataportBeConfiguredBy($dataportId, Tool\Admin::getCurrentUser())) {
            $response = array(
                'success' => false,
                'errorMessage' => sprintf(
                    $this->pimTranslator->trans('pim.permission_missing', [], 'admin'),
                    $this->pimTranslator->trans(Dataport::getConfigurationPermissionName($dataportId), [], 'admin')
                )
            );
            return new JsonResponse($response);
        }

        if (file_exists(Installer::getConfigPath().'/dataport_'.$dataportId.'.json') && $request->get('lastModified') && filemtime(Installer::getConfigPath().'/dataport_'.$dataportId.'.json') > $request->get('lastModified')) {
            $data = json_decode(file_get_contents(Installer::getConfigPath().'/dataport_'.$dataportId.'.json'), true);
            $user = Tool\Admin::getCurrentUser();

            if (!empty($data['user']['id']) && $data['user']['id'] !== $user->getId()) {
                $user = User::getById($data['user']['id']);
                if ($user instanceof User) {
                    $previousUser = $user->getUsername();
                } elseif (!empty($data['user']['username'])) {
                    $previousUser = $data['user']['username'];
                } else {
                    $previousUser = $this->translator->trans('user_unknown', [], 'admin');
                }

                $response = array(
                    'success' => false,
                    'lastModified' => filemtime(Installer::getConfigPath().'/dataport_'.$dataportId.'.json'),
                    'lastModifiedUser' => $previousUser
                );
                return new JsonResponse($response);
            }
        }

        ob_start();

		$mappings = json_decode($request->get('mapping'));

		$config = array(
			'success' => true,
            'mapping' => []
		);

		$dataports = Dataport::getInstance();
		$dataport = $dataports->get($dataportId);

		if ($dataport && $mappings) {
			if (!is_array($mappings)) {
				$mappings = array($mappings);
			}

            $itemMold = null;
			try {
                $itemMold = $this->itemMoldBuilder->getItemMold($dataportId);

                if ($itemMold instanceof PageSnippet) {
                    $targetConfig = $dataport['targetconfig'];
                    $itemMold = PageSnippet::getByPath($targetConfig['masterDocument'] ?? null);
                }
            } catch(\Throwable $e) {
            }

            $user = Tool\Admin::getCurrentUser();
            $errorRecipients = $dataport['targetconfig']['errorRecipients'] ?? [];
            if (!in_array($user->getId(), $errorRecipients)) {
                $errorRecipients[] = $user->getId();
                $dataport['targetconfig']['errorRecipients'] = $errorRecipients;
                $dataports->update(['targetconfig' => json_encode($dataport['targetconfig'])], ['id' => $dataportId]);
            }

            /** @var callable[] $returnMappings */
            $returnMappings = [];
            $usingTranslations = false;
			foreach ($mappings as $mapping) {
				$key = $mapping->{'attribute.key'};
				$field = $mapping->field;
				$type = $mapping->type;
				$settings = $mapping->settings;
				$localized = !empty($mapping->locale);

				$keyMapping = ($settings->keyMapping ?? false) === true;
				$calculation = $settings->calculation;

				$defaults = Helper::getMappingDefaults();
                $format = [];
                $settings->format = (array)($settings->format ?? []);

                if(!empty($settings->format['translateFromLanguage'])) {
                    $usingTranslations = true;
                }
				if (array_key_exists($type, $defaults)) {
                    $format = $defaults[$type];

                    foreach ($settings->format as $attr => $value) {
                        if($attr === 'openAiKey') {
                            $websiteSetting = WebsiteSetting::getByName('OpenAi.com API Key');
                            if(!$websiteSetting instanceof WebsiteSetting) {
                                $websiteSetting = new WebsiteSetting();
                                $websiteSetting->setName('OpenAi.com API Key');
                                $websiteSetting->setType('text');
                            }

                            if($websiteSetting->getData() != $value) {
                                $websiteSetting->setData($value);
                                $websiteSetting->save();
                            }
                        } elseif ($attr === 'deeplApiKey') {
                            $websiteSetting = WebsiteSetting::getByName('DeepL API Key');
                            if (!$websiteSetting instanceof WebsiteSetting) {
                                $websiteSetting = new WebsiteSetting();
                                $websiteSetting->setName('DeepL API Key');
                                $websiteSetting->setType('text');
                            }

                            if ($websiteSetting->getData() != $value) {
                                $websiteSetting->setData($value);
                                $websiteSetting->save();
                            }
                        } else {
                            $format[$attr] = $value;
                        }
                    }
				}
                $format['writeProtected'] = !empty($settings->format['writeProtected']);

				$attributeName = $key;
				$attributeLanguage = null;

				if ($localized === true && strpos($key, '#') !== false) {
					$parts = explode('#', $key);
					$attributeName = $parts[0];
					$attributeLanguage = $parts[1];
				}

                $brickName = $mapping->brickName ?? null;
                $targetBrickField = $mapping->targetBrickField ?? null;

				$mappingTable = Fieldmapping::getInstance();

				if(!empty($format['auto_classification'])) {
				    $field = null;
                    $calculation = '';
                }

                if(!empty($field) || !empty($calculation) || !empty($format['auto_classification']) || !empty($format['optimize'])) {
                    $validLocales = Tool::getValidLanguages();
                    $validLocales[] = 'default';
                    if (!in_array($attributeLanguage, $validLocales)) {
                        $attributeLanguage = null;
                    }

                    $mappingTable->createOrUpdate(array(
                        'dataportId' => $dataport['id'],
                        'fieldName' => $attributeName,
                        'fieldNo' => $field,
                        'format' => serialize($format),
                        'calculation' => $calculation,
                        'keyMapping' => $keyMapping,
                        'locale' => $attributeLanguage ?? '',
                        'brickName' => $brickName ?? '',
                        'targetBrickField' => $targetBrickField
                    ));
                } else {
                    $mappingTable->deleteWhere([
                        'dataportId' => $dataport['id'],
                        'fieldName' => $attributeName,
                        'locale' => $attributeLanguage ?? '',
                        'brickName' => $brickName ?? '',
                    ]);
                }


                try {
                    if($key === '__result_callback') {
                        $fieldDefinition = Importer::getFieldDefinition(null, '__result_callback');
                        $fieldDefinition->setTitle($this->pimTranslator->trans('pim.mapping.result_callback', [], 'admin'));
                    } elseif ($key === '__result_action') {
                        $fieldDefinition = Importer::getFieldDefinition(null, '__result_action');
                        $fieldDefinition->setTitle($this->pimTranslator->trans('pim.mapping.result_action', [], 'admin'));
                    } elseif ($key === '__init_action') {
                        $fieldDefinition = Importer::getFieldDefinition(null, '__init_action');
                        $fieldDefinition->setTitle($this->pimTranslator->trans('pim.mapping.init_action', [], 'admin'));
                    } elseif(strpos($key, '__virtual_') === 0) {
                        $fieldDefinition = Importer::getFieldDefinition(null, $key);
                        $fieldDefinition->setTitle(substr($key, strlen('__virtual_')));
                    } else {
                        $updatableObject = Importer::getUpdatableObject($itemMold, (array)$mapping);
                        $fieldDefinition = Importer::getFieldDefinition($updatableObject, [
                            'fieldName' => $attributeName,
                            'locale' => $attributeLanguage ?? '',
                            'brickName' => $brickName ?? '',
                            'targetBrickField' => $targetBrickField
                        ]);
                    }

                    if(($itemMold instanceof Concrete || $itemMold instanceof PageSnippet) && in_array($fieldDefinition->getName(), ['id', 'key', 'path', 'published'])) {
                        $fieldDefinition->setTitle($this->pimTranslator->trans($fieldDefinition->getName(), [], 'admin'));
                    }

                    $returnMappings[] = function() use ($fieldDefinition, $dataport, $attributeLanguage, $targetBrickField, $brickName) {
                        $this->helper->runInitFunction($dataport);
                        $mapping = $this->helper->createMapping($fieldDefinition, $dataport, ['locale' => $attributeLanguage ?? '', 'targetBrickField' => $targetBrickField, 'brickName' => $brickName ?? '']);
                        $mapping = reset($mapping);
                        unset($mapping['example_value']);
                        return $mapping;
                    };


                    // fetch dependent fields -> sadly ExtJS does not understand
                    /*
                    $fieldMappings = Fieldmapping::getInstance();
                    foreach($fieldMappings->find(['dataportId = ?' => $dataportId, 'calculation REGEXP ?' => '{{[[:space:]]*'.Helper::getFieldKey(['fieldName' => strpos($key, '__virtual_') === 0 ? substr($key, strlen('__virtual_')) : $key, 'locale' => $attributeLanguage ?? '', 'targetBrickField' => $targetBrickField, 'brickName' => $brickName ?? '']).'[[:space:]]*}}']) as $fieldMapping) {

                        $fieldDefinition = Importer::getFieldDefinition(Importer::getUpdatableObject($itemMold, $fieldMapping), $fieldMapping);
                        $fieldMappingData = $this->helper->createMapping($fieldDefinition, $dataport, $fieldMapping);
                        $config['mapping'][] = reset($fieldMappingData);
                    }*/
                } catch(\Throwable $e) {
                    die($e);
                }
			}

            if($usingTranslations) {
                $translationListener = OpenDxp::getContainer()->get(TranslationListener::class);
                $event = new GenericEvent();
                $event->setArgument('dataportId', $dataportId);
                try {
                    $translationListener->updateGlossary($event);
                } catch (\Exception $e) {
                    $config['success'] = false;
                    $config['errorMessage'] = 'Could not create glossary for translations: '.$e->getMessage();
                }
            }

            $dataportData = $dataports->exportDataport($dataportId);
            file_put_contents(Installer::getConfigPath().'/dataport_'.$dataportId.'.json', json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            file_put_contents(Installer::getConfigVersionPath().'/dataport_'.$dataportId.'_'.time().'.json', json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            Dataport::clearImportHashes($dataportId);
            Dataport::clearResultCache($dataportId);

            foreach($returnMappings as $returnMapping) {
                $config['mapping'][] = $returnMapping();
            }
		}

        $response = new JsonResponse();
        $config['currentObjectData'] = json_encode($config['mapping'][0]['currentObjectData'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $config['rawItemData'] = array_filter($config['mapping'][0]['rawItemData'] ?? [], static function ($mappingKey) {
            return strpos($mappingKey, 'field_') === false;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($config['mapping'] as $index => $mapping) {
            unset($config['mapping'][$index]['rawItemData']);
        }
        $response->setJson(json_encode($config, JSON_PARTIAL_OUTPUT_ON_ERROR));
        return $response;
	}

    /**
     * @return array
     */
    public static function getAutotranslateLanguages() {
        // see https://www.deepl.com/docs-api/translate-text -> source_lang

        foreach (
            [
                'en' => 'en',
                'de' => 'de',
                'fr' => 'fr',
                'es' => 'es',
                'it' => 'it',
                'nl' => 'nl',
                'pl' => 'pl',
                'pt' => 'pt',
                'ru' => 'ru',
                'bg' => 'bg',
                'cs' => 'cs',
                'da' => 'da',
                'el' => 'el',
                'et' => 'et',
                'fi' => 'fi',
                'hu' => 'hu',
                'id' => 'id',
                'ja' => 'ja',
                'ko' => 'ko',
                'lt' => 'lt',
                'lv' => 'lv',
                'nb' => 'nb',
                'ro' => 'ro',
                'sk' => 'sk',
                'sl' => 'sl',
                'sv' => 'sv',
                'tr' => 'tr',
                'uk' => 'uk',
                'zh' => 'zh'
            ] as $value => $textId
        ) {
            $user = Tool\Admin::getCurrentUser();
            if($user instanceof User) {
                $userLanguage = Tool\Admin::getCurrentUser()->getLanguage();
            } else {
                $userLanguage = 'en';
            }
            $text = \Locale::getDisplayLanguage($textId, $userLanguage);

            $result[] = ['value' => $value, 'text' => $text];
        }

        return $result;
    }

    /**
     * @Route("/get-autotranslate-languages")
     */
    public function getAutotranslateLanguagesAction(Request $request) {
        $languageData = self::getAutotranslateLanguages();

        $defaultLanguage = substr(Tool::getDefaultLanguage(), 0, 2);
        foreach($languageData as &$languageItem) {
            $languageItem['defaultLanguage'] = ($defaultLanguage === $languageItem['value']);
        }
        unset($languageItem);

        usort($languageData, static function ($language1, $language2) {
            if(!empty($language1['defaultLanguage']) && empty($language2['defaultLanguage'])) {
                return -1;
            }
            if (empty($language1['defaultLanguage']) && !empty($language2['defaultLanguage'])) {
                return 1;
            }
            return $language1['text'] <=> $language2['text'];
        });

        array_unshift($languageData, ['value' => '', 'text' => '- '.$this->pimTranslator->trans('disabled', [], 'admin').' -']);

        return new JsonResponse(['languages' => $languageData]);
    }

    public static function getMappingProposals($dataportId, $locale = null) {
        $dataport = Dataport::getInstance()->get($dataportId);

        $rawData = PimcoreDbRepository::getInstance()->findInSql(
            'SELECT fieldNo, value FROM '.Installer::TABLE_RAWITEMDATA.' WHERE rawItemId=(SELECT rawitem.id FROM '.Installer::TABLE_DATAPORT_RESOURCE.' resource INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON resource.id = rawitem.dataport_resource_id WHERE resource.dataportId = ? LIMIT 1)',
            [$dataport['id']]
        );

        $rawDataFields = RawItemField::getInstance()->find(['dataportId = ?' => $dataportId]);
        $targetFields = array_map(
            function($mapping) {
                return $mapping;
            }, Helper::getInstance()->createFieldMappings($dataport)
        );

        $dataport = Dataport::getInstance()->get($dataportId);
        $itemClass = $dataport['targetconfig']['itemClass'] ?? null;
        $itemMold = \OpenDxp::getContainer()->get(ItemMoldBuilder::class)->getItemMoldByClassId($itemClass);

        $dataport = Dataport::getInstance()->get($dataportId);
        $targetConfig = $dataport['targetconfig'];
        $importer = \OpenDxp::getContainer()->get(ImporterInterface::class);
        $importer->setDataport($dataport);

        $helper = Helper::getInstance();

        $mappingProposals = [];

        foreach($rawDataFields as $rawDataField) {
            $matchingField = null;

            foreach ($targetFields as $targetField) {
                if (mb_strtolower($targetField['attributeKey']) === mb_strtolower($rawDataField['name']) || mb_strtolower($targetField['attributeKey']) === mb_strtolower($rawDataField['name']).'#'.$locale) {
                    $matchingField = $targetField;
                    break;
                }
            }

            if($matchingField === null) {
                foreach ($targetFields as $targetField) {
                    if (mb_strtolower($targetField['attributeName']) === mb_strtolower($rawDataField['name']) || mb_strtolower($targetField['attributeName']) === mb_strtolower($rawDataField['name']).'#'.$locale) {
                        $matchingField = $targetField;
                        break;
                    }
                }
            }

            if($matchingField === null) {
                usort($targetFields, static function ($targetField1, $targetField2) use ($rawDataField) {
                    return levenshtein(mb_strtolower($targetField1['attributeKey']), mb_strtolower($rawDataField['name'])) <=> levenshtein(mb_strtolower($targetField2['attributeKey']), mb_strtolower($rawDataField['name']));
                });

                $fieldWithLowestDistance = reset($targetFields);
                if(levenshtein(mb_strtolower($fieldWithLowestDistance['attributeKey']), mb_strtolower($rawDataField['name'])) < strlen($rawDataField['name']) * 0.5) {
                    $matchingField = $fieldWithLowestDistance;
                }
            }

            if($matchingField === null) {
                usort($targetFields, static function ($targetField1, $targetField2) use ($rawDataField) {
                    return levenshtein(mb_strtolower($targetField1['attributeName']), mb_strtolower($rawDataField['name'])) <=> levenshtein(mb_strtolower($targetField2['attributeName']), mb_strtolower($rawDataField['name']));
                });

                $fieldWithLowestDistance = reset($targetFields);
                if(levenshtein(mb_strtolower($fieldWithLowestDistance['attributeName']), mb_strtolower($rawDataField['name'])) < strlen($rawDataField['name']) * 0.5) {
                    $matchingField = $fieldWithLowestDistance;
                }
            }

            if($matchingField !== null) {
                $updatableObject = Importer::getUpdatableObject($itemMold, $matchingField);
                $matchingField['fieldName'] = $matchingField['attributeKey'];
                $matchingFieldDefinition = Importer::getFieldDefinition($updatableObject, $matchingField);

                $calculation = '';

                if($matchingFieldDefinition instanceof ClassDefinition\Data) {
                    try {
                        $rawDataValue = null;
                        foreach($rawData as $rawDataItem) {
                            if($rawDataItem['fieldNo'] === $rawDataField['fieldNo']) {
                                $rawDataValue = $rawDataItem['value'];
                                break;
                            }
                        }

                        if($rawDataValue !== null) {
                            $parsedValue = $importer->map($targetField, $rawDataValue, null, $matchingFieldDefinition);
                            $matchingFieldDefinition->checkValidity($parsedValue);
                        }
                    } catch (Throwable $e) {
                        if(CallbackFunction::isEngineAvailable($targetConfig['javascriptEngine'])) {
                            try {
                                $templates = $helper->getTemplates($matchingFieldDefinition, $dataport['id'], $targetField);
                                foreach($templates as $template) {
                                    try {
                                        $value = CallbackFunction::evaluateScript($template['value'], $targetConfig['javascriptEngine'], ['value' => $rawDataField['data1']]);
                                        $parsedValue = $importer->map($targetField, $value, null, $matchingFieldDefinition);

                                        $matchingFieldDefinition->checkValidity($parsedValue);
                                        $calculation = $template['value'];
                                        break;
                                    } catch(Throwable $e) {
                                        continue;
                                    }
                                }
                            } catch (Throwable $e) {
                                continue;
                            }
                        } else {
                            continue;
                        }
                    }

                    $mappingProposals[$matchingField['attributeKey']] = [
                        'field' => $rawDataField['fieldNo'],
                        'calculation' => $calculation,
                        'keyField' => (bool)$matchingFieldDefinition->getUnique() || $matchingField['attributeKey'] === 'id' || $matchingField['attributeKey'] === 'o_id'
                    ];
                }
            }
        }

        return $mappingProposals;
    }

    /**
     * @Route("/auto-assign-rawdata-fields")
     */
    public function autoAssignRawdataFieldsAction(Request $request) {
        try {
            $mappingProposals = self::getMappingProposals($request->get('dataportId'), Tool::getDefaultLanguage());
        } catch (\Throwable $e) {
            throw $e;
        }

        return new JsonResponse(['mappingProposals' => $mappingProposals]);
    }

    /**
     * @Route("/get-alternative-raw-item-data")
     */
    public function getAlternativeRawItemData(Request $request) {
        $alternatives = PimcoreDbRepository::getInstance()->findInSql(
            'SELECT rawItem.id AS rawItemId, rawItemData.value 
            FROM '.Installer::TABLE_RAWITEMDATA.' rawItemData 
            INNER JOIN '.Installer::TABLE_RAWITEM.' rawItem ON rawItemData.rawItemId=rawItem.id 
            INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataportResource ON rawItem.dataport_resource_id=dataportResource.id 
            WHERE rawItemData.fieldNo = ? AND dataportId = ?
            GROUP BY rawItemData.value
            ORDER BY rawItemData.value',
            [$request->get('fieldNo'), $request->get('dataportId')]
        );

        $alternatives = array_map(static function($alternative) {
            $alternative['rawItemId'] = (string)Uuid::fromBytes($alternative['rawItemId'])->getInteger();
            return $alternative;
        }, $alternatives);

        return new JsonResponse(['alternatives' => $alternatives]);
    }

    /**
     * @Route("/visualize-dependencies")
     */
    public function visualizeDependenciesAction(Request $request) {
        try {
            $dotExecutable = Cli::getExecutable('dot', true);
        } catch (\Exception $e) {
            return new Response('Please install graphviz to visualize dependency graph');
        }

        $defaultOptions = [
            'graph' => ['splines' => 'ortho', 'rankdir' => 'LR'],
            'node' => ['fontsize' => 10, 'fontname' => 'Arial', 'color' => '#333333', 'fillcolor' => 'lightblue', 'fixedsize' => false, 'width' => 1, 'height' => 0.8],
            'edge' => ['fontsize' => 10, 'fontname' => 'Arial', 'color' => '#333333', 'arrowhead' => 'normal', 'arrowsize' => 0.5],
        ];

        $return = sprintf(
            "digraph dependency_graph {\n  %s\n  node [%s];\n  edge [%s];\n\n",
            $this->addDotOptions($defaultOptions['graph']),
            $this->addDotOptions($defaultOptions['node']),
            $this->addDotOptions($defaultOptions['edge'])
        );

        $targetClass = $request->get('targetClass');

        $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($targetClass);
        $mapping = ['fieldName' => $request->get('field'), 'locale' => $request->get('locale', ''), 'brickName' => $request->get('brickName', '')];
        $updatableObject = Importer::getUpdatableObject($itemMold, $mapping);
        $fieldDefinition = Importer::getFieldDefinition($updatableObject, $mapping);

        $label = $fieldDefinition->getTitle() ?: $fieldDefinition->getName();
        if(strtolower($label) !== strtolower($fieldDefinition->getName())) {
            $label .= ' ('.$fieldDefinition->getName().')';
        }
        $return .= '  target_field [label="'.str_replace('"','\"', $this->pimTranslator->trans('class', [], 'admin').': '.substr(get_class($itemMold), strrpos(get_class($itemMold), '\\') + 1).
            ($request->get('brickName') && !$updatableObject instanceof $itemMold ? "\n".substr(get_class($updatableObject), strrpos(get_class($updatableObject), '\\') + 1) : '')
            ."\n".$this->pimTranslator->trans('field', [], 'admin').': '.$label.($request->get('locale')?"\n".$this->pimTranslator->trans('language', [],'admin').': '.\Locale::getDisplayLanguage($request->get('locale'), Tool\Admin::getCurrentUser()->getLanguage()).' ('.$request->get('locale').')':'')).'", tooltip="Target field", shape=box, pos="0,0!"];'."\n";

        $dataports = Dataport::getInstance();

        $fieldMappings = Fieldmapping::getInstance();
        $pos = 0;
        $posExport = 0;
        foreach($dataports->find([], 'name') as $dataport) {
            $targetConfig = $dataport['targetconfig'];

            $dataportAdded = false;
            if($targetConfig['itemClass'] === $targetClass) {
                $fieldMappingParameters = [
                    'dataportId = ?' => $dataport['id'],
                    'fieldName = ?' => $request->get('field'),
                    'brickName = ?' => $request->get('brickName') ?? ''
                ];

                if ($request->get('locale')) {
                    $fieldMappingParameters['locale'] = $request->get('locale');
                }

                $isMapped = $fieldMappings->findOne($fieldMappingParameters);
                if($isMapped) {
                    $return .= '  dataport_'.$dataport['id'].' [label="'.str_replace('"', '\"', $dataport['name']).' ('.$dataport['id'].')'.'", tooltip="'.str_replace('"', '\"', $dataport['description']).'", URL="javascript:this.parent.opendxp.plugin.Pim.plugin.openDataport('.$dataport['id'].')", shape=ellipse, pos="-6,'.$pos++.'!"];'."\n";
                    $return .= '  dataport_'.$dataport['id'].' -> target_field [style="solid"];'."\n";

                    $isUsedAsVirtualField = $fieldMappings->find(['dataportId = ?' => $dataport['id'], 'calculation LIKE ?' => '%{{ '.Helper::getFieldKey($mapping).' }}%'], null, 1);
                    if ($isUsedAsVirtualField) {
                        $return .= '  target_field -> dataport_'.$dataport['id'].' [style="dashed", label="'.Helper::getFieldKey($isUsedAsVirtualField[0]).'", labeltooltip="'.$this->pimTranslator->trans('pim.mapping.used_as_virtual_field.tooltip', [], 'admin').'"];'."\n";
                    }

                    $dataportAdded = true;
                }
            } elseif(!$targetConfig['itemClass'] && $dataport['sourcetype'] === 'pimcore') {
                $sourceConfig = $dataport['sourceconfig'];
                if($sourceConfig['sourceClass'] === $targetClass) {
                    foreach($sourceConfig['fields'] as $rawDataField) {
                        if(strtolower($rawDataField['parameters']) === strtolower($request->get('field')) || ($request->get('locale') && strtolower($rawDataField['parameters']) === strtolower($request->get('field').'#'.$request->get('locale')))) {
                            $return .= '  dataport_'.$dataport['id'].' [label="'.str_replace('"', '\"', $dataport['name']).' ('.$dataport['id'].')'.'", tooltip="'.str_replace('"', '\"', $dataport['description']).'", URL="javascript:this.parent.opendxp.plugin.Pim.plugin.openDataport('.$dataport['id'].')", shape=ellipse, pos="6,'.$posExport++.'!"];'."\n";
                            $return .= '  target_field -> dataport_'.$dataport['id'].' [style="solid"];'."\n";

                            $dataportAdded = true;
                        }
                    }
                }
            }

            if($dataportAdded) {
                foreach ($dataports->find([], 'name') as $triggeringDataport) {
                    if ($fieldMappings->findOne(['dataportId = ?' => $triggeringDataport['id'], 'fieldName = ?' => '__virtual_DEPENDENT DATAPORT ID', 'calculation REGEXP ?' => '(^|[\'"])'.$dataport['id'].'([\'"]|$)'])
                        || $fieldMappings->findOne(['dataportId = ?' => $triggeringDataport['id'], 'calculation LIKE ?' => '%data-bridge:complete '.$dataport['id'].'%'])) {
                        $return .= '  dataport_'.$triggeringDataport['id'].' [label="'.str_replace('"', '\"', $triggeringDataport['name']).' ('.$triggeringDataport['id'].')'.'", tooltip="'.$triggeringDataport['description'].'", URL="javascript:this.parent.opendxp.plugin.Pim.plugin.openDataport('.$triggeringDataport['id'].')", shape=ellipse, pos="'.($targetConfig['itemClass'] ? '-6' : '6').','.($targetConfig['itemClass'] ? $pos++ : $posExport++).'!"];'."\n";
                        $return .= '  dataport_'.$triggeringDataport['id'].' -> dataport_'.$dataport['id'].' [style="solid"];'."\n";
                    }
                }
            }
        }

        $pos = 0;
        if($itemMold instanceof Concrete) {
            foreach ($itemMold->getClass()->getFieldDefinitions() as $classFieldDefinition) {
                if ($classFieldDefinition instanceof TextareaWithPlaceholders || $classFieldDefinition instanceof InputWithPlaceholders) {
                    $usedAsPlaceholder = PimcoreDbRepository::getInstance()->findRowInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' AS path, `'.Helper::prefixObjectSystemColumn('key').'` AS key, '.Helper::prefixObjectSystemColumn('id').' AS id FROM object_store_'.$itemMold->getClassId().' store INNER JOIN objects ON store.oo_id=objects.o_id WHERE `'.$classFieldDefinition->getName().'` LIKE ? LIMIT 1', ['%{{ '.$fieldDefinition->getName().' }}%']);
                    if ($usedAsPlaceholder) {
                        $label = $classFieldDefinition->getTitle() ?: $classFieldDefinition->getName();
                        if (strtolower($label) !== strtolower($classFieldDefinition->getName())) {
                            $label .= ' ('.$classFieldDefinition->getName().')';
                        }

                        $return .= '  placeholder_field_'.$classFieldDefinition->getName().' [label="'.str_replace('"', '\"', $this->pimTranslator->trans('class', [], 'admin').': '.substr(get_class($itemMold), strrpos(get_class($itemMold), '\\') + 1)."\n".$this->pimTranslator->trans('field', [], 'admin').': '.$label."\n".$this->pimTranslator->trans('example', [], 'admin').': '.$usedAsPlaceholder['path'].$usedAsPlaceholder['key']).'", URL="javascript:this.parent.opendxp.helpers.openObject('.$usedAsPlaceholder['id'].')", shape=ellipse, pos="0,-'.$pos++.'!"];'."\n";
                        $return .= '  placeholder_field_'.$classFieldDefinition->getName().' -> target_field [style="dashed", tooltip="'.$this->pimTranslator->trans('pim.mapping.used_as_virtual_field.tooltip', [], 'admin').'"];'."\n";
                    }
                }
            }
        }

        $return .= "}\n";

        $format = 'svg';
        $mimetype = 'image/svg+xml';
        if($request->get('format') === 'png') {
            $format = 'png';
            $mimetype = 'image/png';
        }

        // see https://stackoverflow.com/a/1250279 for replacing ' by '"'"'
        return new Response(Cli::exec('echo \''.str_replace(['\'', '$', '`'], ['\'"\'"\'', '\\$', '\\`'], $return).'\' | XDG_CACHE_HOME="$(mktemp -d)" "'.$dotExecutable.'" -Kfdp -T'.$format), Response::HTTP_OK, ['Content-Type' => $mimetype]);
    }

    private function addDotOptions(array $options)
    {
        $code = [];

        foreach ($options as $k => $v) {
            $code[] = sprintf('%s="%s"', $k, $v);
        }

        return implode(' ', $code);
    }

    /**
     * @Route("/get-meta-columns")
     */
    public function getMetaColumnsAction(Request $request) {
        $fieldDefinition = Importer::getFieldDefinition(
            $this->itemMoldBuilder->getItemMoldByClassId($request->get('targetClass')),
            [
                'fieldName' => explode('#', $request->get('attributeKey'))[0],
                'locale' => $request->get('locale'),
                'brickName' => $request->get('brickName'),
                'targetBrickField' => $request->get('targetBrickField')
            ]
        );

        if(!$fieldDefinition instanceof ClassDefinition\Data\AdvancedManyToManyObjectRelation) {
            return new JsonResponse(['success' => false]);
        }

        $columns = array_map(function($column) {
            $column['label'] = $this->pimTranslator->trans($column['label'] ?? $column['key'], [], 'admin');
            return ['key' => $column['key'], 'label' => $column['label']];
        }, $fieldDefinition->getColumns());
        return new JsonResponse(['success' => true, 'columns' => $columns]);
    }
}
