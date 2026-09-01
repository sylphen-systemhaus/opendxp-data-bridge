<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueDataQuerySelector;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\LocationAwareConfigRepository;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\TagLogger;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Bundle\AdminBundle\Event\AdminEvents;
use OpenDxp\Db;
use OpenDxp\Event\Model\DataObject\ClassDefinitionEvent;
use OpenDxp\Event\Model\DataObject\ObjectbrickDefinitionEvent;
use OpenDxp\File;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Hotspotimage;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Image;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Objectbricks;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use OpenDxp\Model\DataObject\ClassDefinition\Listing;
use OpenDxp\Model\DataObject\ClassDefinition\PathFormatterAwareInterface;
use OpenDxp\Model\DataObject\Data\ImageGallery;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\User;
use OpenDxp\Bundle\AdminBundle\Perspective\Config;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;

class ClassChangedListener
{
    /** @var array<string, string> */
    public const PORTLET_TYPE_MAP = [
        'pimcore.layout.portlets.DataDirector_ErrorMonitor' => 'opendxp.layout.portlets.DataBridge_ErrorMonitor',
        'opendxp.layout.portlets.DataDirector_ErrorMonitor' => 'opendxp.layout.portlets.DataBridge_ErrorMonitor',
        'pimcore.layout.portlets.DataBridge_ErrorMonitor' => 'opendxp.layout.portlets.DataBridge_ErrorMonitor',
        'pimcore.layout.portlets.DataDirector_TaggedElements' => 'opendxp.layout.portlets.DataBridge_TaggedElements',
        'opendxp.layout.portlets.DataDirector_TaggedElements' => 'opendxp.layout.portlets.DataBridge_TaggedElements',
        'pimcore.layout.portlets.DataBridge_TaggedElements' => 'opendxp.layout.portlets.DataBridge_TaggedElements',
        'pimcore.layout.portlets.DataDirector_QueueMonitor' => 'opendxp.layout.portlets.DataBridge_QueueMonitor',
        'opendxp.layout.portlets.DataDirector_QueueMonitor' => 'opendxp.layout.portlets.DataBridge_QueueMonitor',
        'pimcore.layout.portlets.DataBridge_QueueMonitor' => 'opendxp.layout.portlets.DataBridge_QueueMonitor',
    ];

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    public function __construct(ItemMoldBuilder $itemMoldBuilder)
    {
        $this->itemMoldBuilder = $itemMoldBuilder;
    }

    public function removeCompiledDataQuerySelectors(ClassDefinitionEvent $e) {
        try {
            $dummyObject = $this->itemMoldBuilder->getItemMoldByClassname($e->getClassDefinition()->getName());
            $classFqn = \get_class($dummyObject);
        } catch (\Exception $classNotFoundException) {
            $classFqn = 'OpenDxp\\Model\\DataObject\\'.$e->getClassDefinition()->getName();
        }

        $directoryIterator = new \DirectoryIterator(Installer::getCachePath());
        $fileNamePrefix = preg_replace('/\W+/', '_', $classFqn.' ');
        $filterIterator = new \CallbackFilterIterator($directoryIterator, static function (\SplFileInfo $fileInfo) use ($fileNamePrefix) {
            return strpos($fileInfo->getFilename(), $fileNamePrefix) === 0 || strpos($fileInfo->getFilename(), 'Dao_'.$fileNamePrefix) === 0 || strpos($fileInfo->getFilename(), 'Listing_'.$fileNamePrefix) === 0;
        });

        /** @var \SplFileInfo $compiledFileInfo */
        foreach ($filterIterator as $compiledFile) {
            unlink($compiledFile->getPathname());
        }
    }

    public function removeAllCompiledDataQuerySelectors() {
        /** @var \SplFileInfo $compiledFileInfo */
        foreach(new \DirectoryIterator(Installer::getCachePath()) as $compiledFileInfo) {
            if (!$compiledFileInfo->isDot()) {
                @unlink($compiledFileInfo->getPathname());
            }
        }
    }

    public function createStoreView(ClassDefinitionEvent $e)
    {
        if ($e->getClassDefinition()->getAllowInherit()) {
            $hasLocalizedFields = false;
            foreach ($e->getClassDefinition()->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof Data\Localizedfields) {
                    $hasLocalizedFields = true;
                    break;
                }
            }

            foreach (Tool::getValidLanguages() as $language) {
                $query = 'CREATE OR REPLACE VIEW `object_localized_store_'.$e->getClassDefinition()->getId().'_'.$language.'` AS SELECT * FROM `object_store_'.$e->getClassDefinition()->getId().'` JOIN `objects` ON `objects`.`'.Helper::prefixObjectSystemColumn('id').'` = `object_store_'.$e->getClassDefinition()->getId().'`.`oo_id`';
                $parameters = [];
                if($hasLocalizedFields) {
                    $query .= ' JOIN `object_localized_data_'.$e->getClassDefinition()->getId().'` ON `object_store_'.$e->getClassDefinition()->getId().'`.oo_id=`object_localized_data_'.$e->getClassDefinition()->getId().'`.ooo_id AND language=?';
                    $parameters[] = $language;
                }

                PimcoreDbRepository::getInstance()->execute($query, $parameters);
            }
        } else {
            $this->removeStoreView($e);
        }
    }

    public function removeStoreView(ClassDefinitionEvent $e)
    {
        foreach (Tool::getValidLanguages() as $language) {
            PimcoreDbRepository::getInstance()->execute('DROP VIEW IF EXISTS `object_localized_store_'.$e->getClassDefinition()->getId().'_'.$language.'`');
        }
    }

    public function addPreviewService(ClassDefinitionEvent $e)
    {
        $classDefinition = $e->getClassDefinition();
        if (method_exists($classDefinition, 'setPreviewGeneratorReference')) {
            $preview = $classDefinition->getPreviewGeneratorReference();
            if ($preview === '@DataDirectorPreview') {
                $classDefinition->setPreviewGeneratorReference('@DataBridgePreview');
            } elseif (!$preview && !$classDefinition->getLinkGeneratorReference()) {
                $classDefinition->setPreviewGeneratorReference('@DataBridgePreview');
            }
        } elseif (method_exists($classDefinition, 'setPreviewUrl')) {
            if (!$classDefinition->getPreviewUrl()) {
                $classDefinition->setPreviewUrl('/admin/SylphenDataBridge/import/object-preview?id=%o_id');
            }
        }
    }

    /**
     * @param ClassDefinitionEvent|ObjectbrickDefinitionEvent $e
     * @return void
     */
    public function setLayoutFieldNames($e)
    {
        if ($e instanceof ObjectbrickDefinitionEvent) {
            $classDefinition = $e->getObjectbrickDefinition();
        } else {
            $classDefinition = $e->getClassDefinition();
        }

        $layout = $classDefinition->getLayoutDefinitions();
        if($layout instanceof ClassDefinition\Layout) {
            $this->setLayoutFieldName($layout, $classDefinition);
        }
    }

    /**
     * @param ClassDefinition\Layout|ClassDefinition\Data $layout
     * @param ClassDefinition|Definition $classDefinition
     * @return void
     */
    private function setLayoutFieldName($layout, $classDefinition): void
    {
        $translator = \OpenDxp::getContainer()->get('translator');

        $replaceableLayoutNames = [
            '',
            'Layout',
            'admin_ext',#$translator->trans($layout->fieldtype, [], 'admin_ext', Tool::getDefaultLanguage()),
            'admin_ext',#$translator->trans($layout->fieldtype, [], 'admin_ext', Helper::getUser()->getLanguage())
        ];

        if($layout instanceof ClassDefinition\Layout && property_exists($layout, 'fieldtype') && in_array($layout->name, $replaceableLayoutNames, true)) {
            if($layout->title) {
                $name = trim(preg_replace('/[^a-z0-9_]+/i', '', Helper::toASCII($layout->title, Tool::getDefaultLanguage())));
                if(!$classDefinition->getFieldDefinition($name) instanceof ClassDefinition\Data) {
                    $layout->setName($name);
                }
            } elseif (property_exists($layout, 'text') && $layout->text) {
                $name = trim(preg_replace('/[^a-z0-9_]+/i', '', Helper::toASCII($layout->text, Tool::getDefaultLanguage())));
                if (!$classDefinition->getFieldDefinition($name) instanceof ClassDefinition\Data) {
                    $layout->setName($name);
                }
            }
        }

        if (method_exists($layout, 'getChildren')) {
            $children = $layout->getChildren();
            if (is_array($children)) {
                foreach ($children as $child) {
                    $this->setLayoutFieldName($child, $classDefinition);
                }
            }
        }
    }

    /**
     * @param ClassDefinitionEvent|ObjectbrickDefinitionEvent $e
     * @return void
     */
    public function addPathFormatterService($e)
    {
        if($e instanceof ObjectbrickDefinitionEvent) {
            $classDefinition = $e->getObjectbrickDefinition();
        } else {
            $classDefinition = $e->getClassDefinition();
        }

        foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
            if ($fieldDefinition instanceof PathFormatterAwareInterface) {
                $this->applyPathFormatter($fieldDefinition);
            } elseif ($fieldDefinition instanceof Data\Localizedfields) {
                foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                    if ($localizedFieldDefinition instanceof PathFormatterAwareInterface) {
                        $this->applyPathFormatter($localizedFieldDefinition);
                    }
                }
            }
        }
    }

    private function applyPathFormatter(PathFormatterAwareInterface $fieldDefinition): void
    {
        if (!method_exists($fieldDefinition, 'setPathFormatterClass')) {
            return;
        }

        $formatter = $fieldDefinition->getPathFormatterClass();
        if ($formatter === '@DataDirectorSearchViewPathFormatter') {
            $fieldDefinition->setPathFormatterClass('@DataBridgeSearchViewPathFormatter');
        } elseif ($formatter === '@DataDirectorGridViewPathFormatter') {
            $fieldDefinition->setPathFormatterClass('@DataBridgeGridViewPathFormatter');
        } elseif (!$formatter) {
            $fieldDefinition->setPathFormatterClass('@DataBridgeSearchViewPathFormatter');
        }
    }

    /**
     * @param ClassDefinitionEvent|ObjectbrickDefinitionEvent $e
     * @return void
     */
    public function clearCalculatedValueFieldCache($e)
    {
        if ($e instanceof ObjectbrickDefinitionEvent) {
            $classDefinition = $e->getObjectbrickDefinition();
        } else {
            $classDefinition = $e->getClassDefinition();
        }

        foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
            if ($fieldDefinition instanceof CalculatedValueDataQuerySelector) {
                PimcoreDbRepository::getInstance()->execute('UPDATE object_query_'.$classDefinition->getId().' SET '.Db::get()->quoteIdentifier($fieldDefinition->getName()).'=NULL');
            } elseif ($fieldDefinition instanceof Data\Localizedfields) {
                foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                    if ($localizedFieldDefinition instanceof CalculatedValueDataQuerySelector) {
                        foreach (Tool::getValidLanguages() as $language) {
                            PimcoreDbRepository::getInstance()->execute('UPDATE object_localized_query_'.$classDefinition->getId().'_'.$language.' SET '.Db::get()->quoteIdentifier($localizedFieldDefinition->getName()).'=NULL');
                        }
                    }
                }
            }
        }
    }
    
    public function setClassIcon(ClassDefinitionEvent $e)
    {
        $classDefinition = $e->getClassDefinition();

        if(!$classDefinition->getIcon()) {
            $icons = ['00_purple.svg', '01_magenta.svg', '02_red.svg', '03_vermilion.svg', '04_orange.svg', '05_amber.svg', '06_yellow.svg', '07_chartreuse.svg','08_green.svg', '09_teal.svg', '10_blue.svg', '11_violet.svg'];
            
            $classDefinition->setIcon('/bundles/opendxpadmin/img/object-icons/'.$icons[crc32($classDefinition->getName()) % count($icons)]);
        }
    }

    public static function createPerspective()
    {
        if(RuntimeCache::isRegistered(__CLASS__.__METHOD__)) {
            return;
        }

        $existingPerspectives = Config::get();

        if(!$existingPerspectives) {
            return;
        }

        if($existingPerspectives instanceof \OpenDxp\Config\Config || $existingPerspectives instanceof \OpenDxp\Bundle\AdminBundle\Perspective\Config) {
            $existingPerspectives = $existingPerspectives->toArray();
        }

        foreach($existingPerspectives as $perspectiveName => $existingPerspective) {
            if($perspectiveName !== 'pim.perspective.PIM' && $perspectiveName !== 'pim.perspective.default' && $perspectiveName !== 'default') {
                return;
            }

            $welcomeType = $existingPerspective['dashboards']['predefined']['welcome']['positions'][0][0]['type'] ?? null;
            if($perspectiveName === 'pim.perspective.PIM' && !self::isGeneratedPimWelcomePortlet($welcomeType)) {
                return;
            }
        }

        $dependencies = [];
        $customViews = [];
        foreach ((new Listing())->load() as $classDefinition) {
            $groupExistsAsFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT `'.Helper::prefixObjectSystemColumn('key').'` FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').'=\'/\' AND CAST(objects.'.Helper::prefixObjectSystemColumn('key').' AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci LIKE ? LIMIT 1', [$classDefinition->getGroup()]);

            $customViewId = 'dd_'.File::getValidFilename($classDefinition->getGroup() ?? '');
            if($groupExistsAsFolder) {
                $rootFolder = '/'.$groupExistsAsFolder.'/';
            } else {
                $firstFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' LIMIT 1', [$classDefinition->getId()]
                ) ?: '/';
                $lastFolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('path').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').'=? ORDER BY '.Helper::prefixObjectSystemColumn('path').' DESC LIMIT 1', [$classDefinition->getId()]
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
            }

            if (!isset($dependencies[$customViewId])) {
                $dependencies[$customViewId] = [];
            }


            if (!isset($customViews[$customViewId])) {
                $customViews[$customViewId] = [
                    'id' => $customViewId,
                    'name' => $classDefinition->getGroup() ?: 'data_objects',
                    'treetype' => 'object',
                    'position' => 'left',
                    'rootfolder' => $rootFolder,
                    'classes' => $classDefinition->getId(),
                    'showroot' => true,
                    'sort' => 0,
                    'treeContextMenu' => [
                        'object' => [
                            'items' => [
                                'add' => true,
                                'addFolder' => true,
                                'importCsv' => true,
                                'cut' => true,
                                'copy' => true,
                                'paste' => true,
                                'delete' => true,
                                'rename' => true,
                                'reload' => true,
                                'publish' => true,
                                'unpublish' => true,
                                'searchAndMove' => true,
                                'lock' => true,
                                'unlock' => true,
                                'lockAndPropagate' => true,
                                'unlockAndPropagate' => true,
                                'changeChildrenSortBy' => true
                            ]
                        ]
                    ],
                    'icon' => $classDefinition->getIcon() ?: '/bundles/opendxpadmin/img/flat-white-icons/opendxp-main-icon-object.svg'
                ];
            } else {
                $customViews[$customViewId]['classes'] .= ','.$classDefinition->getId();

                if (!$groupExistsAsFolder && $firstFolder !== '/') {
                    $firstFolder = (mb_strlen($customViews[$customViewId]['rootfolder']) <= mb_strlen($commonPrefix) ? $customViews[$customViewId]['rootfolder'] : $commonPrefix);
                    $lastFolder = (mb_strlen($customViews[$customViewId]['rootfolder']) > mb_strlen($commonPrefix) ? $customViews[$customViewId]['rootfolder'] : $commonPrefix);
                    $len = min(mb_strlen($firstFolder), mb_strlen($lastFolder));

                    for ($i = 0; $i < $len; $i++) {
                        if (mb_substr($firstFolder, $i, 1) !== mb_substr($lastFolder, $i, 1)) {
                            break;
                        }
                    }

                    $rootFolder = substr($firstFolder, 0, $i);
                    if (substr($commonPrefix, -1) !== '/') {
                        $rootFolder = rtrim(dirname($commonPrefix), '/').'/';
                    } else {
                        $countObjectsOutsidePreviousRootfolder = PimcoreDbRepository::getInstance()->findOneInSql('SELECT COUNT(*) FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').' IN (?) AND '.Helper::prefixObjectSystemColumn('path').' NOT LIKE ?', [explode(',', $customViews[$customViewId]['classes']), $customViews[$customViewId]['rootfolder'].'%']);
                        if($countObjectsOutsidePreviousRootfolder < 5) {
                            $rootFolder = $customViews[$customViewId]['rootfolder'];
                        }
                    }

                    $customViews[$customViewId]['rootfolder'] = $rootFolder;
                }
            }

            foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof AbstractRelations && method_exists($fieldDefinition, 'getDocumentsAllowed') && $fieldDefinition->getDocumentsAllowed()) {
                    $dependencies[$customViewId]['document'] = 'document';
                } elseif($fieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation && method_exists($fieldDefinition, 'getObjectsAllowed') && $fieldDefinition->getObjectsAllowed()) {
                    foreach ((array)$fieldDefinition->getClasses() as $allowedClass) {
                        $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                        if($allowedClass instanceof ClassDefinition) {
                            $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup() ?? '');
                        }
                    }
                } elseif ($fieldDefinition instanceof Data\Localizedfields) {
                    foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        if ($localizedFieldDefinition instanceof AbstractRelations && method_exists($localizedFieldDefinition, 'getDocumentsAllowed') && $localizedFieldDefinition->getDocumentsAllowed()) {
                            $dependencies[$customViewId]['document'] = 'document';
                        } elseif ($localizedFieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation && method_exists($localizedFieldDefinition, 'getObjectsAllowed') && $localizedFieldDefinition->getObjectsAllowed()) {
                            foreach ((array)$localizedFieldDefinition->getClasses() as $allowedClass) {
                                $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                if ($allowedClass instanceof ClassDefinition) {
                                    $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup());
                                }
                            }
                        }
                    }
                } elseif ($fieldDefinition instanceof Objectbricks) {
                    foreach ($fieldDefinition->getAllowedTypes() as $brickName) {
                        $brickDefinition = Definition::getByKey($brickName);
                        if(!$brickDefinition instanceof Definition) {
                            continue;
                        }
                        foreach ($brickDefinition->getFieldDefinitions() as $brickFieldDefinition) {
                            if ($brickFieldDefinition instanceof Data\Localizedfields) {
                                foreach ($brickFieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                                    if ($localizedFieldDefinition instanceof AbstractRelations && method_exists($localizedFieldDefinition, 'getDocumentsAllowed') && $localizedFieldDefinition->getDocumentsAllowed()) {
                                        $dependencies[$customViewId]['document'] = 'document';
                                    } elseif ($localizedFieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation && method_exists($localizedFieldDefinition, 'getObjectsAllowed') && $localizedFieldDefinition->getObjectsAllowed()) {
                                        foreach ((array)$localizedFieldDefinition->getClasses() as $allowedClass) {
                                            $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                            if ($allowedClass instanceof ClassDefinition) {
                                                $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup());
                                            }
                                        }
                                    }
                                }
                            } elseif ($brickFieldDefinition instanceof AbstractRelations && method_exists($brickFieldDefinition, 'getDocumentsAllowed') && $brickFieldDefinition->getDocumentsAllowed()) {
                                $dependencies[$customViewId]['document'] = 'document';
                            } elseif ($brickFieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation && method_exists($brickFieldDefinition, 'getObjectsAllowed') && $brickFieldDefinition->getObjectsAllowed()) {
                                foreach ((array)$brickFieldDefinition->getClasses() as $allowedClass) {
                                    $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                    if ($allowedClass instanceof ClassDefinition) {
                                        $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup());
                                    }
                                }
                            }
                        }
                    }
                } elseif ($fieldDefinition instanceof ClassDefinition\Data\Fieldcollections) {
                    foreach ($fieldDefinition->getAllowedTypes() as $brickName) {
                        $brickDefinition = \OpenDxp\Model\DataObject\Fieldcollection\Definition::getByKey($brickName);
                        foreach ($brickDefinition->getFieldDefinitions() as $brickFieldDefinition) {
                            if ($brickFieldDefinition instanceof Data\Localizedfields) {
                                foreach ($brickFieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                                    if ($localizedFieldDefinition instanceof AbstractRelations && method_exists($localizedFieldDefinition, 'getDocumentsAllowed') && $localizedFieldDefinition->getDocumentsAllowed()) {
                                        $dependencies[$customViewId]['document'] = 'document';
                                    } elseif ($localizedFieldDefinition instanceof AbstractRelations && method_exists($localizedFieldDefinition, 'getObjectsAllowed') && $localizedFieldDefinition->getObjectsAllowed()) {
                                        foreach ((array)$localizedFieldDefinition->getClasses() as $allowedClass) {
                                            $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                            if ($allowedClass instanceof ClassDefinition) {
                                                $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup());
                                            }
                                        }
                                    }
                                }
                            } elseif ($brickFieldDefinition instanceof AbstractRelations && method_exists($brickFieldDefinition, 'getDocumentsAllowed') && $brickFieldDefinition->getDocumentsAllowed()) {
                                $dependencies[$customViewId]['document'] = 'document';
                            } elseif ($brickFieldDefinition instanceof AbstractRelations && !$fieldDefinition instanceof ClassDefinition\Data\ReverseManyToManyObjectRelation && !$fieldDefinition instanceof ClassDefinition\Data\ReverseObjectRelation && method_exists($brickFieldDefinition, 'getObjectsAllowed') && $brickFieldDefinition->getObjectsAllowed()) {
                                foreach ((array)$brickFieldDefinition->getClasses() as $allowedClass) {
                                    $allowedClass = Helper::getClassDefinitionByName($allowedClass['classes']);
                                    if ($allowedClass instanceof ClassDefinition) {
                                        $dependencies[$customViewId][$allowedClass->getId()] = 'dd_'.File::getValidFilename($allowedClass->getGroup());
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $perspectives = [
            'pim.perspective.default' => [
                'elementTree' => [
                    [
                        'type' => 'documents',
                        'position' => 'left',
                        'expanded' => false,
                        'hidden' => false,
                        'sort' => 0
                    ],
                    [
                        'type' => 'assets',
                        'position' => 'left',
                        'expanded' => false,
                        'hidden' => false,
                        'sort' => 1
                    ],
                    [
                        'type' => 'objects',
                        'position' => 'left',
                        'expanded' => false,
                        'hidden' => false,
                        'sort' => 2
                    ]
                ],
                'iconCls' => 'opendxp_nav_icon_perspective',
                'icon' => null,
                'dashboards' => [
                    'predefined' => [
                        'welcome' => [
                            'positions' => [
                                [
                                    [
                                        'id' => 1,
                                        'type' => 'opendxp.layout.portlets.modificationStatistic',
                                        'config' => null
                                    ],
                                    [
                                        'id' => 2,
                                        'type' => 'opendxp.layout.portlets.modifiedAssets',
                                        'config' => null
                                    ]
                                ],
                                [
                                    [
                                        'id' => 3,
                                        'type' => 'opendxp.layout.portlets.modifiedObjects',
                                        'config' => null
                                    ],
                                    [
                                        'id' => 4,
                                        'type' => 'opendxp.layout.portlets.modifiedDocuments',
                                        'config' => null
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        if (count($customViews) > 0) {
            foreach($customViews as $customViewIndex => $customView) {
                $element = Service::getElementByPath($customView['treetype'], $customView['rootfolder']);
                if(! $element instanceof ElementInterface) {
                    unset($customViews[$customViewIndex]);
                }

                $roleExistsWhichCanAccessRoot = false;
                $roleListing = new \OpenDxp\Model\User\Role\Listing();
                $roles = $roleListing->load();
                foreach($roles as $role) {
                    $userListing = new \OpenDxp\Model\User\Listing();
                    $userListing->addConditionParam('roles=?', $role->getId());
                    $userListing->addConditionParam('admin=0');
                    $userListing->setLimit(1);
                    $user = $userListing->load()[0] ?? null;
                    if($user !== null && $element instanceof ElementInterface && $element->isAllowed('list', $user)) {
                        $roleExistsWhichCanAccessRoot = true;
                        break;
                    }
                }

                if(! $roleExistsWhichCanAccessRoot && count($roles) > 0) {
                    unset($customViews[$customViewIndex]);
                }
            }

            self::saveCustomView($customViews);

            uksort($customViews, static function ($customViewId1, $customViewId2) use ($customViews, $dependencies) {
                $order = count($dependencies[$customViewId2]) <=> count($dependencies[$customViewId1]);
                if ($order !== 0) {
                    return $order;
                }

                $order = substr_count($customViews[$customViewId2]['classes'], ',') <=> substr_count($customViews[$customViewId1]['classes'], ',');
                if ($order !== 0) {
                    return $order;
                }
                if ($customViews[$customViewId1]['name'] === 'data_objects' && $customViews[$customViewId2]['name'] !== 'data_objects') {
                    return 1;
                }
                if ($customViews[$customViewId1]['name'] !== 'data_objects' && $customViews[$customViewId2]['name'] === 'data_objects') {
                    return -1;
                }

                return strcasecmp($customViews[$customViewId1]['name'], $customViews[$customViewId2]['name']);
            });

            $elementTree = [
                [
                    'type' => 'objects',
                    'position' => 'left',
                    'expanded' => false,
                    'hidden' => false,
                    'sort' => 0,
                ],
                [
                    'type' => 'assets',
                    'position' => 'right',
                    'expanded' => false,
                    'hidden' => false,
                    'sort' => 9000,
                ],
            ];

            $documentsAdded = false;
            foreach ($customViews as $customViewId => $customView) {
                if (in_array('document', $dependencies[$customViewId], true)) {
                    $elementTree[] = [
                        'type' => 'documents',
                        'position' => 'left',
                        'expanded' => false,
                        'hidden' => false,
                        'sort' => 9001
                    ];
                    $documentsAdded = true;
                    break;
                }
            }

            if(!$documentsAdded && count(PimcoreDbRepository::getInstance()->findColumnInSql('SELECT 1 FROM documents LIMIT 2')) > 1) {
                $elementTree[] = [
                    'type' => 'documents',
                    'position' => 'right',
                    'expanded' => false,
                    'hidden' => false,
                    'sort' => 9001
                ];
            }

            $index = 0;
            foreach ($customViews as $customViewId => $customView) {
                $position = 'left';
                foreach ($elementTree as $perspectiveView) {
                    if ($perspectiveView['type'] === 'customview' && in_array($customViewId, $dependencies[$perspectiveView['id']] ?? [])) {
                        $position = 'right';
                        break;
                    }
                }

                $elementTree[] = [
                    'type' => 'customview',
                    'position' => $position,
                    'expanded' => false,
                    'hidden' => false,
                    'sort' => $customViewId === 'dd_' ? 8000 : ($index++),
                    'id' => $customViewId
                ];
            }

            $perspectives['pim.perspective.PIM'] = [
                'iconCls' => 'opendxp_icon_object',
                'elementTree' => $elementTree,
                'dashboards' => [
                    'predefined' => [
                        'welcome' => [
                            'positions' => [
                                [
                                    [
                                        'id' => 1,
                                        'type' => 'opendxp.layout.portlets.DataBridge_ErrorMonitor',
                                        'config' => null
                                    ],
                                    [
                                        'id' => 2,
                                        'type' => 'opendxp.layout.portlets.DataBridge_TaggedElements',
                                        'config' => json_encode([
                                            'title' => 'Objects with import errors',
                                            'tags' => [TagLogger::getOrCreateTag('Data Bridge')->getId()]
                                        ])
                                    ]
                                ],
                                [
                                    [
                                        'id' => 3,
                                        'type' => 'opendxp.layout.portlets.DataBridge_QueueMonitor',
                                        'config' => null
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        self::savePerspective($perspectives);

        RuntimeCache::save(true, __CLASS__.__METHOD__);
    }

    private static function savePerspective(array $data) {
        $repository = self::getPerspectiveRepository();

        foreach ($data as $key => $value) {
            $dataSource = $repository->loadConfigByKey($key)[1];
            if ($repository->isWriteable($key, $dataSource) === true) {
                unset($value['writeable']);
                $repository->saveConfig($key, $value, function ($key, $data) {
                    return [
                        'opendxp' => [
                            'perspectives' => [
                                'definitions' => [
                                    $key => $data,
                                ],
                            ],
                        ],
                    ];
                });
            }
        }
    }

    private static function getPerspectiveRepository(): LocationAwareConfigRepository
    {
        $containerConfig = \OpenDxp::getContainer()->getParameter('opendxp.config');
        $config = $containerConfig['perspectives']['definitions'];

        if (method_exists(\OpenDxp\Config\LocationAwareConfigRepository::class, 'getStorageConfigurationCompatibilityLayer')) {
            $storageConfig = \OpenDxp\Config\LocationAwareConfigRepository::getStorageConfigurationCompatibilityLayer(
                $containerConfig,
                'perspectives',
                'OPENDXP_CONFIG_STORAGE_DIR_PERSPECTIVES',
                'OPENDXP_WRITE_TARGET_PERSPECTIVES'
            );
        } else {
            $storageConfig = $containerConfig['config_location']['perspectives'] ?? null;
        }

        if (empty($storageConfig['write_target']['options']['directory'])) {
            $storageConfig['write_target']['options']['directory'] = OPENDXP_CONFIGURATION_DIRECTORY.'/perspectives';
        }

        try {
            return new LocationAwareConfigRepository(
                $config,
                'opendxp_perspectives',
                $storageConfig
            );
        } catch (\Throwable $e) {
            return new LocationAwareConfigRepository(
                $config,
                'opendxp_perspectives',
                $storageConfig['write_target']['options']['directory'],
                null
            );
        }
    }

    private static function saveCustomView(array $data)
    {
        $containerConfig = \OpenDxp::getContainer()->getParameter('opendxp.config');
        $config = $containerConfig['custom_views']['definitions'];

        if (method_exists(\OpenDxp\Config\LocationAwareConfigRepository::class, 'getStorageConfigurationCompatibilityLayer')) {
            $storageConfig = \OpenDxp\Config\LocationAwareConfigRepository::getStorageConfigurationCompatibilityLayer(
                $containerConfig,
                'custom_views',
                'OPENDXP_CONFIG_STORAGE_DIR_CUSTOM_VIEWS',
                'OPENDXP_WRITE_TARGET_CUSTOM_VIEWS'
            );
        } else {
            $storageConfig = $containerConfig['config_location']['custom_views'] ?? null;
        }

        if(empty($storageConfig['write_target']['options']['directory'])) {
            $storageConfig['write_target']['options']['directory'] = OPENDXP_CONFIGURATION_DIRECTORY.'/custom-views';
        }

        try {
            $repository = new LocationAwareConfigRepository(
                $config,
                'opendxp_custom_views',
                $storageConfig
            );
        } catch(\Throwable $e) {
            $repository = new LocationAwareConfigRepository(
                $config,
                'opendxp_custom_views',
                $storageConfig['write_target']['options']['directory'],
                null
            );
        }

        foreach ($data as $key => $value) {
            $key = (string)$key;
            $dataSource = $repository->loadConfigByKey($key)[1];
            if ($repository->isWriteable($key, $dataSource)) {
                unset($value['writeable']);
                $repository->saveConfig($key, $value, function ($key, $data) {
                    return [
                        'opendxp' => [
                            'custom_views' => [
                                'definitions' => [
                                    $key => $data,
                                ],
                            ],
                        ],
                    ];
                });
            }
        }
    }

    public function updatePerspective(ClassDefinitionEvent $e)
    {
        self::createPerspective();
    }

    public static function isGeneratedPimWelcomePortlet(?string $type): bool
    {
        return $type === 'opendxp.layout.portlets.DataBridge_ErrorMonitor';
    }

    /**
     * Persist DataDirector_* / pimcore.layout.portlets.* types as DataBridge_*.
     * Throws if a leftover type cannot be rewritten (e.g. read-only perspective YAML).
     */
    public static function rewriteLegacyPortletTypes(): void
    {
        $existingPerspectives = Config::get();
        if ($existingPerspectives instanceof \OpenDxp\Config\Config || $existingPerspectives instanceof \OpenDxp\Bundle\AdminBundle\Perspective\Config) {
            $existingPerspectives = $existingPerspectives->toArray();
        }

        $changed = [];
        if (is_array($existingPerspectives)) {
            foreach ($existingPerspectives as $name => $perspective) {
                $rewritten = self::replacePortletTypesIn($perspective);
                if ($rewritten !== $perspective) {
                    $changed[$name] = $rewritten;
                }
            }
        }

        if ($changed !== []) {
            $unwritable = [];
            $repository = self::getPerspectiveRepository();
            foreach (array_keys($changed) as $key) {
                $dataSource = $repository->loadConfigByKey($key)[1];
                if ($repository->isWriteable($key, $dataSource) !== true) {
                    $unwritable[] = $key;
                }
            }

            if ($unwritable !== []) {
                throw new \RuntimeException(self::portletRewriteFailureMessage($unwritable));
            }

            self::savePerspective($changed);
        }

        self::rewriteLegacyPortletTypesInUserDashboards();

        $leftovers = self::collectLegacyPortletHits();
        if ($leftovers !== []) {
            throw new \RuntimeException(
                self::portletRewriteFailureMessage([])."\nLeftover: ".implode('; ', $leftovers)
            );
        }
    }

    /**
     * @param list<string> $unwritableKeys
     */
    private static function portletRewriteFailureMessage(array $unwritableKeys): string
    {
        $parts = [
            'Cannot rewrite Data Director portlet types to Data Bridge.',
            'Make var/config/perspectives/ writable (or set OPENDXP_WRITE_TARGET_PERSPECTIVES) and re-run the install/migration. The rewrite is idempotent.',
        ];
        if ($unwritableKeys !== []) {
            $parts[] = 'Not writable: '.implode(', ', $unwritableKeys).'.';
        }

        return implode(' ', $parts);
    }

    private static function rewriteLegacyPortletTypesInUserDashboards(): void
    {
        if (!self::tableHasColumn('users', 'dashboards')) {
            return;
        }

        foreach (self::PORTLET_TYPE_MAP as $from => $to) {
            PimcoreDbRepository::getInstance()->execute(
                'UPDATE users SET dashboards = REPLACE(dashboards, ?, ?) WHERE dashboards LIKE ?',
                [$from, $to, '%'.$from.'%']
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function collectLegacyPortletHits(): array
    {
        $hits = [];
        $needles = array_keys(self::PORTLET_TYPE_MAP);
        $dir = rtrim(OPENDXP_CONFIGURATION_DIRECTORY, '/').'/perspectives';
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.yaml') ?: [] as $file) {
                $content = (string) file_get_contents($file);
                foreach ($needles as $old) {
                    if (str_contains($content, $old)) {
                        $hits[] = basename($file).' contains '.$old;
                    }
                }
            }
        }

        if (self::tableHasColumn('users', 'dashboards')) {
            $likes = [];
            $params = [];
            foreach ($needles as $old) {
                $likes[] = 'dashboards LIKE ?';
                $params[] = '%'.$old.'%';
            }
            $count = PimcoreDbRepository::getInstance()->findOneInSql(
                'SELECT COUNT(*) FROM users WHERE dashboards IS NOT NULL AND ('.implode(' OR ', $likes).')',
                $params
            );
            if ((int) $count > 0) {
                $hits[] = 'users.dashboards still contains legacy portlet types';
            }
        }

        return $hits;
    }

    private static function tableHasColumn(string $table, string $column): bool
    {
        try {
            $row = PimcoreDbRepository::getInstance()->findOneInSql(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$table, $column]
            );

            return (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private static function replacePortletTypesIn($data)
    {
        if (is_string($data)) {
            return self::PORTLET_TYPE_MAP[$data] ?? $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if ($key === 'type' && is_string($value) && isset(self::PORTLET_TYPE_MAP[$value])) {
                $data[$key] = self::PORTLET_TYPE_MAP[$value];
            } else {
                $data[$key] = self::replacePortletTypesIn($value);
            }
        }

        return $data;
    }
}
