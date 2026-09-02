<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\GenericObjectRelation;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use OpenDxp\Db;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Element\ElementDescriptor;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Tool;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Pipeline;
use Rubix\ML\Tokenizers\Word;
use Rubix\ML\Transformers\TfIdfTransformer;
use Rubix\ML\Transformers\WordCountVectorizer;
use Traversable;
use voku\helper\StopWords;
use Wamania\Snowball\StemmerFactory;

class ManyToManyObjectRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return ($fieldDefinition instanceof Data\ManyToManyObjectRelation ||
            $fieldDefinition instanceof Data\ReverseObjectRelation ||
            $fieldDefinition->getFieldtype() === 'manyToManyObjectRelation' ||
            $fieldDefinition->getFieldtype() === 'nonownerobjects' ||
            $fieldDefinition->getFieldtype() === 'reverseManyToManyObjectRelation' ||
            $fieldDefinition->getFieldtype() === 'objects') && !(
                $fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation ||
                $fieldDefinition->getFieldtype() === 'advancedManyToManyObjectRelation' ||
                $fieldDefinition->getFieldtype() === 'objectsMetadata'
            ) && !(
                $fieldDefinition instanceof GenericObjectRelation || $fieldDefinition->getFieldtype() === 'genericObjectRelation'
            );
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ((!is_array($value) && !$value instanceof Traversable) || (count($value) > 0 && !isset($value[0]) && !isset(reset($value)['query'])) || isset($value['url']) || isset($value['query'])) {
            $value = [$value];
        }

        if (!empty($mapping['format']['auto_classification']) && $dataObject instanceof Concrete) {
            $modelFilepath = Installer::getConfigPath().'/compiled/training-model-'.$dataObject->getClassId().'-'.$mapping['fieldName'];
            $modelManager = new Filesystem($modelFilepath);

            $queryBuilder = $this->buildQueryForAiSampleData($dataObject);
            $classifier = null;

            if (\file_exists($modelFilepath.'-fields') && implode(',', $queryBuilder->getQueryPart('select')) === file_get_contents($modelFilepath.'-fields')) {
                try {
                    $classifier = $modelManager->load();
                } catch (Exception $e) {
                }
            }

            if ($classifier === null) {
                $samples = [];
                $targets = [];
                foreach(PimcoreDbRepository::getInstance()->findInSql($queryBuilder->getSQL()) as $data) {
                    $targets[] = $data['type'].':'.$data['dest_id'];
                    unset($data['type'], $data['dest_id']);

                    $samples[] = $this->preprocessAiSample($data);
                }

                $classifier = new Pipeline(
                    [
                        new WordCountVectorizer(),
                        new TfIdfTransformer()
                    ], new KNearestNeighbors(3)
                );

                $dataset = Labeled::build($samples, $targets);

                $classifier->train($dataset);

                // cannot test for accuracy here because one sample object can have multiple relation items - a classifier can only predict one and so accuracy will always be low when an object has multiple relation items

                $modelManager->save($classifier);
                file_put_contents($modelFilepath.'-fields', implode(',', $queryBuilder->getQueryPart('select')));
            }

            $predictionData = [];
            foreach ($queryBuilder->getQueryPart('select') as $selectField) {
                if (preg_match('/ as (\w+)$/i', $selectField, $column)) {
                    preg_match('/^(\w+)__(\w+)/', $column[1], $parts);
                    $locale = $parts[1];
                    $field = $parts[2];
                    if (!Tool::isValidLanguage($locale)) {
                        $locale = '';
                    }

                    $predictionData[] = Importer::getValue($dataObject, $field, $locale ? [$locale] : []);
                }
            }
            $predictionData = $this->preprocessAiSample($predictionData);

            $predictedRelations = $classifier->probaSample($predictionData);
            $value = [];
            foreach ($predictedRelations as $predictionLabel => $probability) {
                if ($probability > 0.33) {
                    $parts = explode(':', $predictionLabel);
                    $predictedElement = \OpenDxp\Model\Element\Service::getElementById($parts[0], $parts[1]);
                    $value[] = $predictedElement;
                    $this->importer->getLogger()->info('Auto-classification assigned '.$parts[0].': '.$predictedElement->getRealFullPath());
                }
            }
        }

        $objects = [];
        if ((empty($mapping['format']['purgeitems']) && $fieldDefinition->getMaxItems() != 1) || $dataObject === null || $this->isPurged($mapping, $dataObject)) {
            $objects = (is_callable($currentValue) ? $currentValue() : $currentValue);
            $objects = (array)$objects;
            $objects = array_unique($objects);
        } else {
            $this->setPurged($mapping, $dataObject);
        }

        foreach ($value as $objectIdentifier) {
            if ($objectIdentifier instanceof ElementInterface) {
                $objectIdentifier = get_class($objectIdentifier).':id:'.$objectIdentifier->getId();
            } elseif ($objectIdentifier instanceof ElementDescriptor) {
                $class = 'Concrete';
                $objectIdentifier = $class.':id:'.$objectIdentifier->getId();
            } elseif (is_array($objectIdentifier) && !empty($objectIdentifier['query']) && is_string($objectIdentifier['query'])) {
                $objectIdentifier = $objectIdentifier['query'];
            } elseif (is_array($objectIdentifier) && isset($objectIdentifier['id'])) {
                $objectIdentifier = 'Concrete:id:'.$objectIdentifier['id'];
            } elseif (is_array($objectIdentifier) && isset($objectIdentifier['fullpath'])) {
                $objectIdentifier = 'Concrete:path:'.$objectIdentifier['fullpath'];
            } elseif (is_array($objectIdentifier) && is_string(reset($objectIdentifier))) {
                $objectIdentifier = reset($objectIdentifier);
            } elseif ($objectIdentifier instanceof DataObject\Data\ObjectMetadata && $objectIdentifier->getObject() instanceof ElementInterface) {
                $objectIdentifier = get_class($objectIdentifier->getObject()).':id:'.$objectIdentifier->getObject()->getId();
            } elseif ($objectIdentifier instanceof DataObject\Data\ElementMetadata && $objectIdentifier->getElement() instanceof ElementInterface) {
                $objectIdentifier = get_class($objectIdentifier->getElement()).':id:'.$objectIdentifier->getElement()->getId();
            } elseif (!\is_string($objectIdentifier) && !$objectIdentifier instanceof Stringable) {
                continue;
            }

            $objectIdentifier = (string)$objectIdentifier;
            if (!\preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]*)*$/', $objectIdentifier)) {
                if (count($fieldDefinition->getClasses()) === 1) {
                    $relationClass = $fieldDefinition->getClasses()[0]['classes'];
                    if (is_numeric($objectIdentifier)) {
                        $relationField = 'id';
                    } else {
                        $relationField = 'path';
                    }

                    $relationClassDefinition = Helper::getClassDefinitionByName($relationClass);
                    if ($relationClassDefinition instanceof ClassDefinition) {
                        foreach ($relationClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                            if ($relationFieldDefinition->getUnique()) {
                                $relationField = $relationFieldDefinition->getName();
                                break;
                            }
                        }
                    }

                    $objectIdentifier = $relationClass.':'.$relationField.':'.$objectIdentifier;
                }
            }

            $objectToUpdateId = $this->importer->findInRelation($objects, $objectIdentifier);
            if ($objectToUpdateId === null) {
                $targetObjects = $this->importer->getObjectByIdentifier($objectIdentifier);

                if ($targetObjects === null && ($mapping['format']['auto_create_references'] ?? false)) {
                    $dataQuerySelectorParts = $this->importer->getObjectIdentifierParts($objectIdentifier);

                    $referenceObject = $this->itemMoldBuilder->getItemMoldByClassname($dataQuerySelectorParts[0]);
                    $this->importer->getLogger()->info('Trying to automatically generate a '.$referenceObject->getClassname().' object with '.$dataQuerySelectorParts[1].'='.$dataQuerySelectorParts[2]);
                    $referenceFilterField = explode('#', $dataQuerySelectorParts[1])[0];
                    $referenceObjectSetter = 'set'.ucfirst($referenceFilterField);
                    if (method_exists($referenceObject, $referenceObjectSetter)) {
                        $referenceObject->$referenceObjectSetter($dataQuerySelectorParts[2]);
                        if (strtolower($referenceObjectSetter) !== 'setpath') {
                            $referenceObject->setKey(Service::getValidKey($dataQuerySelectorParts[2], 'object'));


                            $firstFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' LIMIT 1', [$referenceObject->getClassId()]
                            ) ?: '/';
                            $lastFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' DESC LIMIT 1', [$referenceObject->getClassId()]
                            ) ?: '/';

                            $len = min(mb_strlen($firstFolder), mb_strlen($lastFolder));

                            for ($i = 0; $i < $len; $i++) {
                                if (mb_substr($firstFolder, $i, 1) !== mb_substr($lastFolder, $i, 1)) {
                                    break;
                                }
                            }

                            $commonPrefix = substr($firstFolder, 0, $i);

                            $rootFolder = $commonPrefix ?: '/';
                            if (substr($commonPrefix, -1) !== '/') {
                                $rootFolder = rtrim(dirname($commonPrefix), '/').'/';
                            }

                            if ($rootFolder !== '/') {
                                $referenceObjectParent = DataObject::getByPath($rootFolder);
                            } else {
                                $referenceObjectParent = Service::createFolderByPath('/'.$referenceObject->getClassname());
                            }

                            $referenceObject->setParent($referenceObjectParent);
                            $referenceObject->setPath(rtrim($referenceObjectParent->getRealFullPath(), '/').'/');
                        } else {
                            $referenceObject->setKey(Service::getValidKey(basename($dataQuerySelectorParts[2]), 'object'));
                            $referenceObjectParent = DataObject::getByPath(dirname($referenceObject->getPath()));
                            if (!$referenceObjectParent instanceof DataObject) {
                                $referenceObjectParent = Service::createFolderByPath(dirname($referenceObject->getPath()));
                                $referenceObject->setPath(rtrim($referenceObjectParent->getRealFullPath(), '/').'/');
                            }
                            $referenceObject->setParent($referenceObjectParent);
                        }

                        $this->importer->saveObject($referenceObject);
                        $targetObjects = [$referenceObject];
                    }
                }

                if ($targetObjects === null) {
                    $this->log($dataObject, 'Could not find any object which matches '.json_encode($objectIdentifier, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'notice');
                    continue;
                }

                if (!is_array($targetObjects)) {
                    $targetObjects = [$targetObjects];
                }

                foreach ($targetObjects as $targetObject) {
                    if ($targetObject instanceof Concrete) {
                        // data query selector could find results which findInRelation() does not find due to collation settings, e.g. for umlaut search
                        foreach($objects as $object) {
                            if($object->getId() == $targetObject->getId()) {
                                continue 2;
                            }
                        }

                        try {
                            $fieldDefinition->checkValidity(array_merge($objects, [$targetObject]), true);
                            $objects[] = $targetObject;
                        } catch (ValidationException $e) {
                            $this->log($dataObject, 'Skipped "'.$objectIdentifier.'" for field '.$fieldDefinition->getName().' because: '.$e->getMessage(), 'warning');
                            continue;
                        }
                    }
                }
            }
        }

        return $objects;
    }

    private function preprocessAiSample(array $data)
    {
        $stopWordLibrary = new StopWords();
        foreach ($data as $column => &$text) {
            $text = strip_tags($text);
            $text = mb_strtolower(preg_replace('/\s+/', ' ', trim($text)) ?: '');

            preg_match('/^(\w+)__/', $column, $locale);
            $locale = $locale[1];
            if (!Tool::isValidLanguage($locale)) {
                $locale = '';
            }
            try {
                $stopWords = $stopWordLibrary->getStopWordsFromLanguage($locale);
            } catch (Exception $e) {
                try {
                    $stopWords = $stopWordLibrary->getStopWordsFromLanguage(Tool::getDefaultLanguage());
                } catch (Exception $e) {
                    $stopWords = $stopWordLibrary->getStopWordsFromLanguage('en');
                }
            }

            foreach ($stopWords as $stopWord) {
                $text = preg_replace('/\b'.preg_quote($stopWord, '/').'\b/', '', $text);
            }

            try {
                $stemmer = StemmerFactory::create($locale);
            } catch (Exception $e) {
                try {
                    $stemmer = StemmerFactory::create(Tool::getDefaultLanguage());
                } catch (Exception $e) {
                    $stemmer = StemmerFactory::create('en');
                }
            }
            $tokens = array_map([$stemmer, 'stem'], (new Word())->tokenize($text));
            $text = implode(' ', $tokens);
        }
        unset($text);

        return [implode(' ', $data)];
    }

    /**
     * @param Concrete $dataObject
     * @return QueryBuilder
     */
    private function buildQueryForAiSampleData(Concrete $dataObject)
    {
        $fieldMappings = $this->importer->getMappings();
        $queryBuilder = Db::get()->createQueryBuilder();
        $queryBuilder->select('object_relations.dest_id');
        $queryBuilder->addSelect('object_relations.type');
        $queryBuilder->from('object_relations_'.$dataObject->getClassId(), 'object_relations');
        $queryBuilder->innerJoin('object_relations', 'object_'.$dataObject->getClassId(), 'object_query', 'object_relations.src_id=object_query.oo_id');
        $queryBuilder->where('object_relations.fieldname = ?');
        $queryBuilder->andWhere('object_relations.src_id != ?');
        $queryBuilder->andWhere('object_query.'.Helper::prefixObjectSystemColumn('published').' = 1');
        $joinedTables = [];
        foreach ($fieldMappings as $fieldMapping) {
            try {
                $fieldDefinition = Importer::getFieldDefinition($dataObject, $fieldMapping['fieldName']);
            } catch (Exception $e) {
                continue;
            }

            if (!$fieldDefinition instanceof Data\Wysiwyg && !$fieldDefinition instanceof Data\Textarea && !$fieldDefinition instanceof Input) {
                continue;
            }

            $tableAlias = 'object_query';
            if (!empty($fieldMapping['locale'])) {
                $tableAlias = $fieldMapping['locale'];
                if (!in_array('object_localized_query_'.$dataObject->getClassId().'_'.$fieldMapping['locale'], $joinedTables, true)) {
                    $queryBuilder->leftJoin(
                        'object_relations',
                        'object_localized_query_'.$dataObject->getClassId().'_'.$fieldMapping['locale'],
                        $fieldMapping['locale'],
                        'object_relations.src_id='.$fieldMapping['locale'].'.ooo_id'
                    );
                    $joinedTables[] =
                        'object_localized_query_'.$dataObject->getClassId().'_'.$fieldMapping['locale'];
                }
            }

            if (in_array(Helper::prefixObjectSystemColumn($fieldMapping['fieldName']), Helper::getSystemFields(), true)) {
                $queryBuilder->addSelect($tableAlias.'.'.Helper::prefixObjectSystemColumn($fieldMapping['fieldName']).' AS '.$tableAlias.'__'.$fieldMapping['fieldName']);
            } else {
                $queryBuilder->addSelect($tableAlias.'.'.$fieldMapping['fieldName'].' AS '.$tableAlias.'__'.$fieldMapping['fieldName']);
            }
        }
        return $queryBuilder;
    }
}