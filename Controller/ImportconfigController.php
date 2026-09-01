<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use Sylphen\DataBridgeBundle\SylphenDataBridgeBundle;
use Sylphen\DataBridgeBundle\Command\QueueProcessorCommand;
use Sylphen\DataBridgeBundle\EventListener\SkipTriggerAutomaticImportException;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\AutocompleteParenthesisParser;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParenthesisParser;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\ConsoleLoggerFactory;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\InMemoryLogger;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\CsvParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\NaiveParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ObjectWizardParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\PimcoreParser;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\UuidGenerator;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use Sylphen\DataBridgeBundle\model\ApiKeys;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Favorites;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\model\Repository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Composer\InstalledVersions;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use DeepCopy\DeepCopy;
use DirectoryIterator;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InSquare\OpendxpProcessManagerBundle\Model\Configuration;
use Exception;
use GlobIterator;
use IntlDateFormatter;
use Iterator;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Renderer\RendererConstant;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use Michelf\Markdown;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\AggregatedType;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Self_;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use OpenDxp;
use OpenDxp\Bundle\AdminBundle\Controller\AdminController;
use OpenDxp\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use OpenDxp\Bundle\AdminBundle\Security\User\TokenStorageUserResolver;
use OpenDxp\Cache;
use OpenDxp\Config;
use OpenDxp\Controller\FrontendController;
use OpenDxp\Db;
use OpenDxp\File;
use OpenDxp\Loader\ImplementationLoader\Exception\UnsupportedException;
use OpenDxp\Logger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\QueryResourcePersistenceAwareInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\QuantityValue\Unit;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\Page;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Listing\AbstractListing;
use OpenDxp\Bundle\SeoBundle\Model\Redirect;
use OpenDxp\Model\User;
use OpenDxp\Model\User\Listing;
use OpenDxp\Tool;
use OpenDxp\Translation\Translator;
use OpenDxp\Version;
use Prewk\XmlStringStreamer;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Nonstandard\UuidV6;
use Ramsey\Uuid\Uuid;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Mime\FileinfoMimeTypeGuesser;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * @Route("/admin/{bundle}/importconfig", defaults={"bundle"="SylphenDataBridge"}, requirements={"bundle": "SylphenDataBridge"})
 */
class ImportconfigController {
	const DEFAULT_SOURCETYPE = 'xml';
	const MODE_CREATE = 1<<0;
	const MODE_EDIT = 1<<1;

	/** @var LoggerInterface */
	private $logger;

	/** @var ItemMoldBuilder */
	private $helper;

	/** @var Translator */
	protected $translator;

    private static $favoriteCache = null;
    private static $roleCache = null;

	/**
	 * Default values for dataport
	 * @var array
	 */
	public static $sourceconfigDefaults = array(
		'xml' => array(
		    'fields' => [],
			'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
			'itemxpath' => '',
		),
		'csv' => array(
            'fields' => [],
			'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
			'assetSource' => '/',
            'archiveFolder' => '',
			'hasHeader' => true,
			'separator' => ';',
			'quote' => '"',
		),
        'excel' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
            'hasHeader' => true,
            'sheet' => '',
            'dataArea' => '',
        ),
        'json' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
            'item-json-path' => '',
        ),
        'pimcore' => array(
            'fields' => [],
            'sourceClass' => null,
            'inheritanceEnabled' => true,
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'draftVersions' => false
        ),
        'report' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/'
        ),
        'grid' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/'
        ),
        'files' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
        ),
        'fixed-length' => array(
            'fields' => [],
            'file' => null,
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
        ),
        'object-wizard' => array(
            'fields' => [],
            'autoImport' => false,
            'incrementalExport' => false,
            'assetSource' => '/',
            'archiveFolder' => '',
        ),
	);

	/**
	 * Default values for each field in a dataport
	 * @var array
	 */
	public static $sourceconfigFields = array(
		'xml' => array(
			'xpath' => '',
			'multiValues' => false,
            'sort' => 'ASC',
		),
		'csv' => array(
			'column' => '',
            'sort' => 'ASC',
        ),
        'json' => [
            'json-path' => '',
            'sort' => 'ASC',
        ],
        'excel' => array(
            'column' => '',
            'sort' => 'ASC',
        ),
        'pimcore' => array(
            'parameters' => '',
            'exportKey' => false,
            'sort' => 'ASC',
        ),
        'report' => array(
            'column' => '',
            'sort' => 'ASC',
        ),
        'grid' => array(
            'field' => '',
            'identifier' => '',
            'sort' => 'ASC',
        ),
        'files' => array(
            'cmd' => '',
            'sort' => 'ASC',
        ),
        'fixed-length' => array(
            'length' => '',
            'sort' => 'ASC',
        ),
        'object-wizard' => array(
            'type' => '',
            'definition' => null
        ),
	);

	public static $targetconfigDefaults = array(
	    'mode' => self::MODE_EDIT | self::MODE_CREATE,
		'itemClass' => null,
		'itemFolder' => '/',
		'masterDocument' => null,
        'optimizeInheritance' => false,
        'skipVersioning' => false,
		'javascriptEngine' => CallbackFunction::ENGINE_PHP,
        'assetFolder' => '/',
		'idPrefix' => '',
		'categoryClass' => '',
		'fieldnameProducts' => '',
        'compatibilityMode' => false,
        'parallelProcesses' => 1,
        'errorRecipients' => [],
	);

    public function __construct(LoggerInterface $logger, ItemMoldBuilder $helper, Translator $translator)
    {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->translator = $translator;
    }

    public static function getSubscribedServices()
    {
        $services['translator'] = '?'.TranslatorInterface::class;

        return $services;
    }

    /**
     *  @Route("/get-dataports")
     */
    public function getDataportsAction() {
        $dataportList = Dataport::getInstance();
        $dataports = $dataportList->find(['name NOT LIKE \'Ad-hoc export%\' AND name NOT LIKE \'grid-import %\'' => null], 'name');
        $result = [];

        $user = Tool\Admin::getCurrentUser();
        $translator = $this->translator;

        $favorites = Favorites::getInstance();

        $roles = $user->getRoles();
        if ($user->isAdmin()) {
            $userListing = new User\Listing();
            $userListing->setCondition('admin=1');
            foreach ($userListing->load() as $adminUser) {
                $roles[] = $adminUser->getId();
            }
        }

        $favoriteDataportIds = array_map(static function ($favorite) {
            return $favorite['dataport_id'];
        }, $favorites->find(['users_id IN (?)' => array_merge([$user->getId()], $roles)]));

        $dataportResources = new DataportResource();
        foreach ($dataports as $dataport) {
            if (!Dataport::canDataportBeConfiguredBy($dataport['id'], $user) && !Dataport::canDataportBeExecutedBy($dataport['id'], $user)) {
                continue;
            }

            $data = [
                'id'   => $dataport['id'],
                'text' => $dataport['name'],
                'sourcetype' => $dataport['sourcetype'],
                'leaf' => true,
                'error' => false,
                'unused' => false,
                'lastModificationDate' => file_exists(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json') ? filemtime(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json') : null,
                'favorite' => [
                    $user->getId() => [
                        'favorite' => (bool)$favorites->findOne(['dataport_id = ?' => $dataport['id'], 'users_id = ?' => $user->getId()]),
                        'name' => $translator->trans('pim.dataport.favorite.personal', [], 'admin'),
                        'currentUserHasRole' => true,
                        'children' => []
                    ]
                ] + $this->getFavoriteRoles($dataport['id'], $user)
            ];

            $targetConfig = $dataport['targetconfig'];
            if($targetConfig['itemClass']) {
                try {
                    $itemMold = $this->helper->getItemMoldByClassId($targetConfig['itemClass']);
                    if ($itemMold instanceof Concrete) {
                        $classDefinition = $itemMold->getClass();
                        if ($classDefinition instanceof ClassDefinition && $classDefinition->getIcon()) {
                            $data['icon'] = $classDefinition->getIcon();
                        }
                    } elseif ($itemMold instanceof Asset) {
                        $data['icon'] = '/bundles/opendxpadmin/img/flat-color-icons/asset.svg';
                    } elseif ($itemMold instanceof PageSnippet) {
                        $data['icon'] = '/bundles/opendxpadmin/img/flat-color-icons/document.svg';
                    }
                } catch(\Exception $e) {}
            } else {
                $sourceConfig = $dataport['sourceconfig'];
                if(!empty($sourceConfig['sourceClass'])) {
                    try {
                    $itemMold = $this->helper->getItemMoldByClassId($sourceConfig['sourceClass']);
                    if($itemMold instanceof Concrete) {
                        $classDefinition = $itemMold->getClass();
                        if ($classDefinition instanceof ClassDefinition && $classDefinition->getIcon()) {
                            $data['icon'] = $classDefinition->getIcon();
                            $data['iconCls'] = 'opendxp_icon_overlay_download';
                        }
                    } elseif ($itemMold instanceof Asset) {
                        $data['icon'] = '/bundles/opendxpadmin/img/flat-color-icons/asset.svg';
                        $data['iconCls'] = 'opendxp_icon_overlay_download';
                    } elseif ($itemMold instanceof PageSnippet) {
                        $data['icon'] = '/bundles/opendxpadmin/img/flat-color-icons/document.svg';
                        $data['iconCls'] = 'opendxp_icon_overlay_download';
                    }
                    } catch (\Exception $e) {
                    }
                }
            }

            $failingDataportRun = PimcoreDbRepository::getInstance()->findRowInSql('SELECT startDate, totalItems FROM (SELECT startDate, totalItems, worst_error FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE dataport_id = ? ORDER BY startDate DESC LIMIT 100) t WHERE worst_error IS NOT NULL AND worst_error NOT LIKE \'[Notice] %\' ORDER BY startDate DESC LIMIT 1', [$dataport['id']]);

            if(!$failingDataportRun) {
                $failingDataportRun = PimcoreDbRepository::getInstance()->findRowInSql(
                    'SELECT startDate, totalItems FROM (SELECT startDate, totalItems, status FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE dataport_id = ? ORDER BY startDate DESC LIMIT 100) t WHERE status=? ORDER BY startDate DESC LIMIT 1',
                    [$dataport['id'], ImportStatus::STATUS_ABORTED]
                );
            }

            if(!empty($failingDataportRun['startDate'])) {
                $recovered = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.Installer::TABLE_IMPORTSTATUS.' WHERE dataport_id = ? AND startDate > ? AND worst_error IS NULL'.($failingDataportRun['totalItems'] > 1 && empty($dataport['sourceconfig']['incrementalExport']) ? ' AND totalItems > 1' : '').' LIMIT 1', [$dataport['id'], $failingDataportRun['startDate']]);
                if(!$recovered) {
                    $data['error'] = true;
                }
            }

            if(!in_array($dataport['id'], $favoriteDataportIds) && $data['lastModificationDate'] !== null && $data['lastModificationDate'] < time() - 86400 * 14) {
                $latestRun = $dataportResources->findOne(['dataportId = ?' => $dataport['id']], 'lastAccess DESC');

                if(!$latestRun || new DateTimeImmutable($latestRun['lastAccess']) < (new DateTimeImmutable())->sub(new DateInterval('P56D'))) {
                    $data['unused'] = true;
                }
            }

	        $result[] = $data;
        }

        $paths[] = [];
        foreach ($result as $index => $item) {
            $nameParts = preg_split('/[\s\-_\/]+/', $item['text']);
            $nameParts[] = $item['id'];

            $nameParts = array_map(static function($namePart) {
                $namePart = preg_replace('/^[^\pN\pL]/u', '', $namePart);
                $namePart = preg_replace('/[^\pN\pL]$/u', '', $namePart);
                return $namePart;
            }, $nameParts);

            $nameParts = array_filter($nameParts);

            // Build a nested assoc array representing the path.
            // Each key and value comes from the delimited parts of the string.
            // eg: site\projects\terrace_and_balcony\mexico.jpg
            // becomes: [
            //      'site' => [
            //              'projects' => [
            //                      'terrace_and_balcony' => [
            //                              'mexico.jpg'
            //                      ]
            //              ]
            //      ]
            // ]
            $path = [ucfirst(array_pop($nameParts))];
            foreach (array_reverse($nameParts) as $namePart) {
                $path = [ucfirst($namePart) => $path];
            }
            $paths[] = $path;
        }

        $tree = array_merge_recursive(...$paths);

        $tree = $this->groupDataports($tree);

        $tree = $this->convertPathTreeToObjectTree($tree, $result);

        usort($tree, static function($item1, $item2) {
            return \strnatcasecmp($item1['text'], $item2['text']) ;
        });

        return new JsonResponse($tree);
    }

    private function getFavoriteRoles($dataportId, User $user, $parentId = 0) {
        if(self::$favoriteCache === null) {
            self::$favoriteCache = Favorites::getInstance()->find();
        }

        if(self::$roleCache === null) {
            self::$roleCache = [];
            $roles = new User\Role\Listing();
            foreach ($roles as $role) {
                if (is_scalar($role)) {
                    $role = User\Role::getById($role);
                    if (!$role instanceof User\Role && !$role instanceof User\Role\Folder) {
                        continue;
                    }
                }

                self::$roleCache[] = $role;
            }
        }

        $roles = array_filter(self::$roleCache, static function($role) use ($parentId) {
            /** @var User\Role|User\Role\Folder $role */
            return $role->getParentId() == $parentId;
        });
        $data = [];

        foreach ($roles as $role) {
            $isFavorite = false;
            foreach(self::$favoriteCache as $favoriteForDataport) {
                if($favoriteForDataport['dataport_id'] == $dataportId && $favoriteForDataport['users_id'] == $role->getId()) {
                    $isFavorite = true;
                    break;
                }
            }

            $data[$role->getId()] = [
                'favorite' => $isFavorite,
                'name' => $role->getName(),
                'currentUserHasRole' => in_array($role->getId(), $user->getRoles()),
                'children' => $this->getFavoriteRoles($dataportId, $user, $role->getId())
            ];
        }

        return $data;
    }

    private function groupDataports($array)
    {
        $flat = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                if(count($val) === 1) {
                    $value = reset($val);
                    if (is_array($value)) {
                        $flat = array_merge($flat, $this->groupDataports([$key.' '.key($val) => $value]));
                    } else {
                        $flat = array_merge($flat, [$key.' '.$value]);
                    }
                } else {
                    $flat[$key] = $this->groupDataports($val);
                }
            } else {
                $flat[] = $val;
            }
        }

        return $flat;
    }

    private function convertPathTreeToObjectTree(array $paths, array $dataportTreeItems)
    {
        $tree = [];
        foreach ($paths as $name => $path) {
            if(is_array($path)) {
                $tree[] = [
                    'text' => $name,
                    'icon' => '',
                    'iconCls' => 'opendxp_icon_folder',
                    'children' => $this->convertPathTreeToObjectTree($path, $dataportTreeItems),
                    'leaf' => false
                ];
            } else {
                $lastWhitespace = strrpos($path, ' ');
                if($lastWhitespace === false) {
                    $dataportId = $path;
                } else {
                    $dataportId = substr($path, $lastWhitespace + 1);
                }

                foreach ($dataportTreeItems as $dataportTreeItem) {
                    if ($dataportTreeItem['id'] == $dataportId) {
                        $tree[] = $dataportTreeItem;
                        break;
                    }
                }
            }
        }

        return $tree;
    }

    /**
     * @Route("/get")
     */
	public function getAction(Request $request) {
		$id = (int)$request->get('id');

        $user = Tool\Admin::getCurrentUser();

        if (!Dataport::canDataportBeConfiguredBy($id, $user) && !Dataport::canDataportBeExecutedBy($id, $user)) {
            $response = array(
                'success' => false,
                'errorMessage' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getConfigurationPermissionName($id), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$table = Dataport::getInstance();
		$dataport = $table->get($id);

		$response = array(
			'success' => false
		);

		if ($dataport) {
			$response['data'] = $dataport;
			$targetConfig = $dataport['targetconfig'];

			$sourceConfig = $dataport['sourceconfig'];
			if (!is_array($sourceConfig)) {
				$sourceConfig = array();
			}
			if (!is_array($targetConfig)) {
				$targetConfig = array();
			}

            $response['data'] = array_merge($response['data'], $targetConfig);
            $response['data']['sourceconfig'] = array_merge(self::$sourceconfigDefaults[$response['data']['sourcetype']], $sourceConfig);
            $response['data']['modificationDate'] = null;
            if(file_exists(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json')) {
                $response['data']['modificationDate'] = filemtime(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json');
            }

            $response['data']['hasDependentDataport'] = false;
            $calculationField = Fieldmapping::getInstance()->findOne(
                [
                    'dataportId = ?' => $dataport['id'],
                    'fieldName = ?' => '__virtual_DEPENDENT DATAPORT ID'
                ]
            );
            if ($calculationField && !empty($calculationField['calculation'])) {
                $response['data']['hasDependentDataport'] = true;
            }

			$response['success'] = true;
		} else {
			$response['errorMessage'] = $this->translator->trans('pim.dataport.notfound', [], 'admin');
		}

		return new JsonResponse($response);
	}

    /**
     * @Route("/add-from-template")
     */
    public function addFromTemplateAction(Request $request)
    {
        $template = explode('_', $request->get('template'));

        try {
            if ($template[0] == 'process-manager') {
                $processManagerJobConfiguration = Configuration::getById($template[1]);

                $suffix = '';
                do {
                    $request->attributes->set('name', $processManagerJobConfiguration->getName().($suffix ? ' '.$suffix : ''));
                    $addActionResult = json_decode($this->addAction($request)->getContent(), true);
                    $suffix = ($suffix ? $suffix + 1 : 1);
                } while(!$addActionResult['success'] && $addActionResult['errorMessage'] === 'pim.dataport.nametaken');

                if(!$addActionResult['success']) {
                    throw new \Exception($addActionResult['errorMessage']);
                }

                $dataport = Dataport::getInstance()->get($addActionResult['dataport']['id']);
                $dataport['targetconfig']['itemClass'] = '0';

                Dataport::getInstance()->update(['sourcetype' => 'object-wizard', 'targetconfig' => json_encode($dataport['targetconfig'])], ['id' => $dataport['id']]);
                $mappingConfigController = OpenDxp::getContainer()->get(MappingconfigController::class);

                $request->attributes->set('dataportId', $dataport['id']);
                $request->attributes->set('mapping', json_encode(['attribute.key' => '__init_action', 'attributeKey' => '__init_action', 'field' => null, 'type' => 'calculatedValue', 'settings' => ['calculation' => '']]));
                $mappingConfigController->saveConfigAction($request);

                return new JsonResponse(['success' => true, 'dataport' => ['id' => $dataport['id']]]);
            }
        } catch(\Throwable $e) {
            return new JsonResponse(['success' => false, 'errorMessage' => (string)$e]);
        }
    }

    /**
     *  @Route("/add")
     */
    public function addAction(Request $request) {
        $name = $this->sanitizeName($request->get('name'));

        if (!$this->checkDataportName($name)) {
            $response = array(
                'success' => false,
                'errorMessage' => 'pim.dataport.nametaken',
            );
            return new JsonResponse($response);
        }

        if(!Tool\Admin::getCurrentUser()->isAllowed('plugin_sylphen_data_bridge')) {
            $response = array(
                'success' => false,
                'errorMessage' => 'pim.permission_missing_create_dataport',
            );
            return new JsonResponse($response);
        }

        $dataports = Dataport::getInstance();
        $success = true;
        $errorMessage = '';
        try {
            $config = [
                'name' => $name,
                'sourcetype' => self::DEFAULT_SOURCETYPE,
                'sourceconfig' => self::$sourceconfigDefaults[self::DEFAULT_SOURCETYPE],
                'targetconfig' => self::$targetconfigDefaults,
            ];

            if(stripos($name, 'export') !== false) {
                $config['sourcetype'] = 'pimcore';
                $config['sourceconfig'] = self::$sourceconfigDefaults[$config['sourcetype']];
                $config['targetconfig']['itemClass'] = 0;

                $classDefinitions = (new ClassDefinition\Listing())->load();
                $assetClassDefinition = new ClassDefinition();
                $assetClassDefinition->setName('Asset');
                $assetClassDefinition->setId(Asset::class);
                $classDefinitions[] = $assetClassDefinition;
                foreach($classDefinitions as $classDefinition) {
                    foreach (explode(' ', $name) as $dataportNamePart) {
                        if (stripos($dataportNamePart, $classDefinition->getName()) !== false || stripos($classDefinition->getName(), $dataportNamePart) !== false) {
                            $config['sourceconfig']['sourceClass'] = $classDefinition->getId();
                        }
                    }
                }
            } else {
                foreach (array_keys(self::$sourceconfigDefaults) as $sourceType) {
                    if(strpos(strtolower($name), strtolower($sourceType)) !== false) {
                        $config['sourcetype'] = $sourceType;
                        $config['sourceconfig'] = self::$sourceconfigDefaults[$sourceType];
                    }
                }

                $classDefinitions = (new ClassDefinition\Listing())->load();
                $assetClassDefinition = new ClassDefinition();
                $assetClassDefinition->setName('Asset');
                $assetClassDefinition->setId(Asset::class);
                $classDefinitions[] = $assetClassDefinition;
                foreach ($classDefinitions as $classDefinition) {
                    foreach(explode(' ', $name) as $dataportNamePart) {
                        if (stripos($dataportNamePart, $classDefinition->getName()) !== false ||
                            stripos($classDefinition->getName(), $dataportNamePart) !== false ||
                            stripos($dataportNamePart, $this->translator->trans($classDefinition->getName(), [], 'admin')) !== false ||
                            stripos($this->translator->trans($classDefinition->getName(), [], 'admin'), $dataportNamePart) !== false) {
                            $config['targetconfig']['itemClass'] = $classDefinition->getId();
                        }
                    }
                }
            }

            $config['sourceconfig'] = json_encode($config['sourceconfig']);
            $config['targetconfig'] = json_encode($config['targetconfig']);

            $dataport = $dataports->create($config);

            $user = Tool\Admin::getCurrentUser();
            if($user instanceof User) {
                $user->setPermission(Dataport::getConfigurationPermissionName($dataport['id']), true);
                $user->setPermission(Dataport::getExecutionPermissionName($dataport['id']), true);
                $user->save();

                if(!$user->isAdmin()) {
                    foreach ($user->getRoles() as $roleId) {
                        $role = User\Role::getById($roleId);
                        if ($role instanceof User\Role) {
                            $role->setPermission(Dataport::getConfigurationPermissionName($dataport['id']), true);
                            $role->setPermission(Dataport::getExecutionPermissionName($dataport['id']), true);
                            $role->save();
                        }
                    }
                }
            }

            $dataportData = $dataports->exportDataport($dataport['id']);
            $jsonData = json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            @file_put_contents(Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json', $jsonData);
            @file_put_contents(Installer::getConfigVersionPath().'/dataport_'.$dataport['id'].'_'.time().'.json', $jsonData);
        } catch(\Exception $e) {
            $success = false;
            $errorMessage = $e->getMessage();
        }

        $response = array(
            'success' => $success,
            'dataport' => $dataport,
            'errorMessage' => $errorMessage
        );

        return new JsonResponse($response);
    }

    /**
     *  @Route("/copy")
     */
    public function copyAction(Request $request) {
        $name = $this->sanitizeName($request->get('name'));

        if (!$this->checkDataportName($name)) {
            $response = [
                'success' => false,
                'errorMessage' => 'pim.dataport.nametaken',
            ];
            return new JsonResponse($response);
        }

        if (!Tool\Admin::getCurrentUser()->isAllowed('plugin_sylphen_data_bridge')) {
            $response = array(
                'success' => false,
                'errorMessage' => 'pim.permission_missing_create_dataport',
            );
            return new JsonResponse($response);
        }

        $copyId = $request->get('copy');
        $db = PimcoreDbRepository::getInstance();
        try {
            $db->beginTransaction();
            $db->execute('INSERT INTO '.Installer::TABLE_DATAPORT.' (`name`, `description`, `sourcetype`, `sourceconfig`, `targetconfig`) SELECT ?, `description`, `sourcetype`, `sourceconfig`, `targetconfig` FROM '.Installer::TABLE_DATAPORT.' WHERE `id` = ?', [$name, $copyId]);
            $dataportId = $db->lastInsertId();

            $db->execute('INSERT INTO '.Installer::TABLE_FIELDMAPPING.' (`dataportId`, `fieldName`, `locale`, `fieldNo`, `keyMapping`, `format`, `calculation`, `brickName`, `targetBrickField`) SELECT ?, `fieldName`, `locale`, `fieldNo`, `keyMapping`, `format`, `calculation`, `brickName`, `targetBrickField` FROM '.Installer::TABLE_FIELDMAPPING.' WHERE `dataportId` = ?', [$dataportId, $copyId]);
            $db->execute('INSERT INTO '.Installer::TABLE_RAWITEMFIELD.' (`dataportId`, `fieldNo`, `name`, `priority`) SELECT ?, `fieldNo`, `name`, `priority` FROM '.Installer::TABLE_RAWITEMFIELD.' WHERE `dataportId` = ?', [$dataportId, $copyId]);

            $dataportRepository = new Dataport();
            $dataport = $dataportRepository->get($dataportId);
            $sourceConfig = $dataport['sourceconfig'];
            if (!empty($sourceConfig['autoImport'])) {
                $sourceConfig['autoImport'] = false;
                $dataportRepository->update(['sourceconfig' => json_encode($sourceConfig)], ['id' => $dataportId]);
            }

            $db->commit();

            Dataport::createPermissions($dataport);

            $user = Tool\Admin::getCurrentUser();
            if ($user instanceof User) {
                $user->setPermission(Dataport::getConfigurationPermissionName($dataportId), true);
                $user->setPermission(Dataport::getExecutionPermissionName($dataportId), true);
                $user->save();

                foreach ($user->getRoles() as $roleId) {
                    $role = User\Role::getById($roleId);
                    if ($role instanceof User\Role) {
                        $role->setPermission(Dataport::getConfigurationPermissionName($dataport['id']), true);
                        $role->setPermission(Dataport::getExecutionPermissionName($dataport['id']), true);
                        $role->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            try {
                $db->rollBack();
            } catch(\Throwable $e2) {
            }


            $response = array(
                'success' => false,
                'errorMessage' => (string)$e,
            );

            return new JsonResponse($response);
        }

        $response = array(
            'success' => true,
            'dataport' => $dataportId,
        );

        return new JsonResponse($response);
    }

    /**
     * @Route("/delete/{id}")
     */
	public function deleteAction(int $id) {
        Helper::setMemoryLimit();
        // delete raw items, otherwise the foreign key restricts deleting the dataport
	    $dataportResources = DataportResource::getInstance();
        $dataportResources->deleteWhere(['dataportId' => $id]);

		$table = Dataport::getInstance();
		$table->delete($id);

        $queueRepository = Queue::getInstance();
        $queueItems = $queueRepository->find();
        foreach ($queueItems as $queueItem) {
            $queueItemDataportId = null;
            if (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim) "?(\d+)"?/', $queueItem['command'], $matches)) {
                $queueItemDataportId = $matches[1];
            } elseif (preg_match('/^(?:data-bridge):delete-rawdata --dataport-resource-id="?(\d+)"?/', $queueItem['command'], $matches)) {
                $queueItemDataportId = $dataportResources->get($matches[1])['dataportId'] ?? 'unknown';
            }

            if ($queueItemDataportId == $id) {
                $queueRepository->delete($queueItem['id']);
            }
        }

		if(file_exists(Installer::getConfigPath().'/dataport_'.$id.'.json')) {
            unlink(Installer::getConfigPath().'/dataport_'.$id.'.json');
        }

        $fileIterator = new \GlobIterator(Installer::getConfigVersionPath().'/dataport_'.$id.'_*.json', \GlobIterator::SKIP_DOTS);
        /** @var SplFileInfo $fileInfo */
        foreach ($fileIterator as $fileInfo) {
            unlink($fileInfo->getPathname());
        }

		return new JsonResponse(['dataportId' => $id]);
	}

    /**
     * @Route("/update")
     */
	public function updateAction(Request $request) {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

		$id = (int)$request->get('id');

        $user = Tool\Admin::getCurrentUser();
        if (!Dataport::canDataportBeConfiguredBy($id, $user))  {
            $response = array(
                'success' => false,
                'errorMessage' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getConfigurationPermissionName($id), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

        if(file_exists(Installer::getConfigPath().'/dataport_'.$id.'.json') && $request->get('lastModified') && filemtime(Installer::getConfigPath().'/dataport_'.$id.'.json') > $request->get('lastModified')) {
            $data = json_decode(file_get_contents(Installer::getConfigPath().'/dataport_'.$id.'.json'), true);

            if(!empty($data['user']['id']) && $data['user']['id'] !== $user->getId()) {
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
                    'lastModified' => filemtime(Installer::getConfigPath().'/dataport_'.$id.'.json'),
                    'lastModifiedUser' => $previousUser
                );
                return new JsonResponse($response);
            }
        }

		$dataports = Dataport::getInstance();
		$dataport = $dataports->get($id);

		$response = array(
			'success' => false
		);

		if (!$dataport) {
			$response['errorMessage'] = $this->translator->trans('pim.dataport.notfound', [], 'admin');
			return new JsonResponse($response);
		}

		$oldName = $dataport['name'];

		$name = $this->sanitizeName($request->get('name'));

		if (!$this->checkDataportName($name, $id)) {
			$response['errorMessage'] = $this->translator->trans('pim.dataport.nametaken', [], 'admin');
			return new JsonResponse($response);
		}

		$sourcetype = $request->get('sourcetype');

		if (!array_key_exists($sourcetype, self::$sourceconfigDefaults)) {
			$sourcetype = self::DEFAULT_SOURCETYPE;
		}

		$data = array(
            'name' => $name,
            'description' => $request->get('description'),
            'sourcetype' => $sourcetype,
		);

        $skipVersioning = false;
		$targetClassId = $request->get('itemClass');
		if($targetClassId !== '0') {
            if (empty($targetClassId)) {
                $targetClassId = null;
            } elseif (!\class_exists($targetClassId)) {
                $targetClass = ClassDefinition::getById($targetClassId);
                if (!($targetClass instanceof ClassDefinition)) {
                    $targetClassId = null;
                }
            } else {
                $skipVersioning = $request->get('skipVersioning');
            }
        }

        $optimizeInheritance = $request->get('optimizeInheritance');

        $archiveFolderPath = $request->get('archiveFolder');
        $prefixArchiveFolderPath = $archiveFolderPath;
        createArchiveFolderPath:
		if($prefixArchiveFolderPath) {
            if(strpos($prefixArchiveFolderPath, 'ftp') !== 0 && strpos($prefixArchiveFolderPath, 'sftp') !==0 && strpos($prefixArchiveFolderPath, 'ftps') !== 0 && strpos($prefixArchiveFolderPath, 's3') !== 0) {
                $prefixArchiveFolder = Asset\Folder::getByPath($prefixArchiveFolderPath);

                if ($prefixArchiveFolder instanceof Asset\Folder) {
                    Asset\Service::createFolderByPath($archiveFolderPath);
                } elseif (is_dir($prefixArchiveFolderPath)) {
                    if (!is_dir($archiveFolderPath)) {
                        mkdir($archiveFolderPath, 0777, true);
                    }
                } else {
                    $prefixArchiveFolderPath = dirname($prefixArchiveFolderPath);
                    goto createArchiveFolderPath;
                }
            }
        }

		// Source config
        $sourceConfig = array_merge(self::$sourceconfigDefaults[$sourcetype], $request->request->all());
		$sourceConfig = array_intersect_key($sourceConfig, self::$sourceconfigDefaults[$sourcetype]);
        $sourceConfig['hasHeader'] = isset($sourceConfig['hasHeader']) && $sourceConfig['hasHeader'] === 'true';
        $sourceConfig['autoImport'] = isset($sourceConfig['autoImport']) && $sourceConfig['autoImport'] === 'true';
        $sourceConfig['incrementalExport'] = $sourceConfig['autoImport'] && ((isset($sourceConfig['incrementalExport']) && $sourceConfig['incrementalExport'] === 'true') || ($sourcetype === 'pimcore' && !empty($targetClassId)));

        if (!empty($sourceConfig['file'])) {
            $sourceConfig['file'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $sourceConfig['file']);
        }

        if (!empty($sourceConfig['assetSource'])) {
            $sourceConfig['assetSource'] = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]/u', '', $sourceConfig['assetSource']));
        }

		$sourceConfig['fields'] = [];

        $importSourceBefore = null;
		if (!empty($dataport['sourceconfig'])) {
			$config = $dataport['sourceconfig'];
			if (!empty($config['fields'])) {
				$sourceConfig['fields'] = $config['fields'];
			}

            $importSourceBefore = $config['file'] ?? null;
		}

		$rawDataFieldsBeforeSave = $sourceConfig['fields'];

        $errorRecipients = $request->get('error_recipients') ?? [];
        if (!in_array($user->getId(), $errorRecipients)) {
            $errorRecipients[] = $user->getId();
        }

		// Target config
        $targetData = array(
            'itemClass' => $targetClassId,
            'itemFolder' => $request->get('itemFolder'),
            'optimizeInheritance' => $optimizeInheritance,
            'parallelProcesses' => $request->get('parallelProcesses'),
            'compatibilityMode' => $request->get('compatibilityMode'),
            'skipVersioning' => $skipVersioning,
            'assetFolder' => $request->get('assetFolder'),
            'idPrefix' => File::getValidFilename($request->get('idPrefix') ?? ''),
            'errorRecipients' => array_filter($errorRecipients),
        );

        $targetData = array_merge(self::$targetconfigDefaults, $request->request->all(), $targetData);
		$targetConfig = array_intersect_key($targetData, self::$targetconfigDefaults);
        if(empty($targetConfig['mode'])) {
            $targetConfig['mode'] = self::$targetconfigDefaults['mode'];
        }

        if (empty($targetConfig['javascriptEngine'])) {
            $targetConfig['javascriptEngine'] = self::$targetconfigDefaults['javascriptEngine'];
        }

        $data['targetconfig'] = json_encode($targetConfig);

		// Find existing fields
		$fieldTable = RawItemField::getInstance();
		$rawItemFields = array();
		$toBeDeleted = array();
		$existingFields = $fieldTable->find(array('dataportId = ?' => $id));
		foreach ($existingFields as $field) {
			$rawItemFields['field_' . $field['fieldNo']] = $field;
			$toBeDeleted['field_' . $field['fieldNo']] = $field;
		}

        $rawitemData = json_decode($request->get("rawitemData"));

		// Create new fields and update existing ones
		if (\is_array($rawitemData)) {
			$sourceConfig['fields'] = [];
			foreach ($rawitemData as $index => $setting) {
				$fieldNo = (int)$setting->fieldNo;

				if ($fieldNo !== 0) {
                    $rawItemFieldName = trim($setting->name ?? '');
					if (empty($rawItemFieldName)) {
						continue;
					}

                    $key = 'field_'.$fieldNo;

					if (array_key_exists($key, $rawItemFields)) {
                        $rawItemFields[$key]['name'] = $rawItemFieldName;
                        $rawItemFields[$key]['priority'] = $index;
					} else {
						$rawItemFields[$key] = [
							'dataportId' => $id,
							'fieldNo' => $fieldNo,
							'name' => $rawItemFieldName,
                            'priority' => $index
						];
					}

					unset($toBeDeleted[$key]);

					// Custom field config
					foreach (array_keys(self::$sourceconfigFields[$sourcetype]) as $config) {
                        $sourceConfig['fields'][$key][$config] = json_decode(json_encode($setting->$config ?? null), true); // convert stdClass definition from object-wizard to array

                        if($config === 'exportKey' && !empty($targetConfig['itemClass'])) {
                            $sourceConfig['fields'][$key][$config] = false;
                        }
					}
				}
			}
		}
		$data['sourceconfig'] = json_encode($sourceConfig);

		try {
			$dataports->update($data, ['id' => $id]);
            $response['success'] = true;

            $fieldTable->beginTransaction();
            if($oldName && $oldName !== $name) {
                // add redirect for old REST API URL
                $redirect = new Redirect();
                $redirect->setActive(true);
                $redirect->setType(Redirect::TYPE_PATH);
                $redirect->setSource(\OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($oldName)]));
                $redirect->setTarget(\OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($name)]));
                $redirect->setStatusCode(301);
                $redirect->save();

                PimcoreDbRepository::getInstance()->execute('UPDATE redirects SET target=? WHERE target=?', [
                    \OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($name)]),
                    \OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($oldName)])
                ]);

                $redirect = new Redirect();
                $redirect->setActive(true);
                $redirect->setType(Redirect::TYPE_PATH);
                $redirect->setSource(\OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($oldName)]));
                $redirect->setTarget(\OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($name)]));
                $redirect->setStatusCode(301);
                $redirect->save();

                PimcoreDbRepository::getInstance()->execute('UPDATE redirects SET target=? WHERE target=?', [
                    \OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($name)]),
                    \OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($oldName)])
                ]);

                PimcoreDbRepository::getInstance()->execute('DELETE FROM redirects WHERE source=target AND target IN (?,?)', [
                    \OpenDxp::getContainer()->get('router')->generate('dataport_export', ['dataportId' => urlencode($name)]),
                    \OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($name)])
                ]);
            }

			foreach ($rawItemFields as $field) {
				$fieldTable->createOrUpdate($field);
			}

			foreach ($toBeDeleted as $field) {
                $fieldTable->deleteWhere(['dataportId' => $field['dataportId'],
                                          'fieldNo' => $field['fieldNo']]);
			}

            $fieldTable->commit();

			$dataportData = $dataports->exportDataport($id);
            $jsonConfig = json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            @file_put_contents(Installer::getConfigPath().'/dataport_'.$id.'.json', $jsonConfig);
            @file_put_contents(Installer::getConfigVersionPath().'/dataport_'.$id.'_'.time().'.json', $jsonConfig);

            /*if ($rawDataFieldsBeforeSave != $sourceConfig['fields'] || array_keys($rawDataFieldsBeforeSave) != array_keys($sourceConfig['fields'])) {
                $tableSchema = new Table('sylphen_dd_rawdata_'.$id);
                $tableSchema->addColumn('id', Types::INTEGER, ['unsigned' => true, 'length' => 10, 'notnull' => true, 'autoincrement' => true]);
                $columns = [];
                foreach($rawItemFields as $field) {
                    $columnName = 'field_'.$field['fieldNo'];

                    $options = $sourceConfig['fields']['field_'.$field['fieldNo']];
                    $tableSchema->addColumn($columnName, Types::TEXT, ['length' => '4294967295', 'notnull' => true, 'comment' => $field['name'].($options?' '.json_encode($options):'')]);
                    $columns[] = $columnName;
                }

                $tableSchema->setPrimaryKey(['id']);
                $tableSchema->addUniqueIndex($columns);

                if(Db::get()->getSchemaManager()->tablesExist($tableSchema->getName())) {
                    Db::get()->getSchemaManager()->dropTable($tableSchema->getName());
                }

                Db::get()->getSchemaManager()->createTable($tableSchema);
            }*/

			if($rawDataFieldsBeforeSave != $sourceConfig['fields'] || array_keys($rawDataFieldsBeforeSave) != array_keys($sourceConfig['fields']) || $importSourceBefore != ($sourceConfig['file'] ?? null)) {
                Dataport::clearRawdataCache($id);
                Dataport::clearResultCache($id);
            }
		} catch (\Throwable $e) {
            try {
                $fieldTable->rollback();
            } catch (\Exception $rollbackException) {
            }

			$response['success'] = false;
			$response['errorMessage'] = 'Unable to save configuration: ' . $e;
		}

		return new JsonResponse($response);
	}

    public function importDataport(array $data) {
        $data = Installer::normalizeExportData($data);
        $dataports = Dataport::getInstance();

        if (is_string($data[$dataports->getTableName()]['targetconfig'])) {
            $data[$dataports->getTableName()]['targetconfig'] = unserialize($data[$dataports->getTableName()]['targetconfig'], ['allowed_classes' => false]);
        }

        if (is_string($data[$dataports->getTableName()]['sourceconfig'])) {
            $data[$dataports->getTableName()]['sourceconfig'] = unserialize($data[$dataports->getTableName()]['sourceconfig'], ['allowed_classes' => false]);
        }

        $data[$dataports->getTableName()]['sourceconfig'] = json_encode($data[$dataports->getTableName()]['sourceconfig']);
        $data[$dataports->getTableName()]['targetconfig'] = json_encode($data[$dataports->getTableName()]['targetconfig']);

        if(empty($data[$dataports->getTableName()]['id']) || !$dataports->get($data[$dataports->getTableName()]['id'])) {
            $existingDataportWithSameName = $dataports->findOne(['name = ?' => $data[$dataports->getTableName()]['name']]);
            if($existingDataportWithSameName) {
                $data[$dataports->getTableName()]['id'] = $existingDataportWithSameName['id'];
                $dataports->update($data[$dataports->getTableName()], ['id' => $data[$dataports->getTableName()]['id']]);
            } else {
                $dataportData = $dataports->create($data[$dataports->getTableName()]);

                try {
                    $user = Tool\Admin::getCurrentUser();
                    if ($user instanceof User) {
                        $user->setPermission(Dataport::getConfigurationPermissionName($dataportData['id']), true);
                        $user->setPermission(Dataport::getExecutionPermissionName($dataportData['id']), true);

                        foreach ($user->getRoles() as $roleId) {
                            $role = User\Role::getById($roleId);
                            if ($role instanceof User\Role) {
                                $role->setPermission(Dataport::getConfigurationPermissionName($dataportData['id']), true);
                                $role->setPermission(Dataport::getExecutionPermissionName($dataportData['id']), true);
                                $role->save();
                            }
                        }
                    }
                } catch (Throwable $e) {
                }

                $data[$dataports->getTableName()]['id'] = $dataportData['id'];
            }
        } else {
            $dataports->update($data[$dataports->getTableName()], ['id' => $data[$dataports->getTableName()]['id']]);
        }

        $fieldMappingModel = Fieldmapping::getInstance();
        $fieldMappingModel->deleteWhere(['dataportId' => $data[$dataports->getTableName()]['id']]);
        foreach ($data[$fieldMappingModel->getTableName()] as $f) {
            $f['dataportId'] = $data[$dataports->getTableName()]['id'];
            if (is_array($f['format'])) {
                $f['format'] = serialize($f['format']);
            }
            if (is_array($f['calculation'])) {
                $f['calculation'] = implode("\n", $f['calculation']);
            }
            $fieldMappingModel->createOrUpdate($f);
        }

        $rawitemFieldModel = RawItemField::getInstance();
        $rawitemFieldModel->deleteWhere(['dataportId' => $data[$dataports->getTableName()]['id']]);
        foreach ($data[$rawitemFieldModel->getTableName()] as $f) {
            $f['dataportId'] = $data[$dataports->getTableName()]['id'];
            $rawitemFieldModel->createOrUpdate($f);
        }

        $dataportData = $dataports->exportDataport($data[$dataports->getTableName()]['id']);
        $jsonData = json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        @file_put_contents(Installer::getConfigPath().'/dataport_'.$data[$dataports->getTableName()]['id'].'.json', $jsonData);
        @file_put_contents(Installer::getConfigVersionPath().'/dataport_'.$data[$dataports->getTableName()]['id'].'_'.time().'.json', $jsonData);

        Dataport::clearRawdataCache($data[$dataports->getTableName()]['id']);

        return $dataportData;
    }

    /**
     * @Route("/get-rawitemfield-config/{id}")
     */
	public function getRawitemfieldConfigAction(Request $request, int $id) {
        $user = Tool\Admin::getCurrentUser();

        if (!Dataport::canDataportBeConfiguredBy($id, $user) && !Dataport::canDataportBeExecutedBy($id, $user)) {
            $response = array(
                'errorMessage' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getConfigurationPermissionName($id), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$data = array();

		$table = Dataport::getInstance();
		$dataport = $table->get($id);

		if (!$dataport) {
			return new JsonResponse(array(
				'total' => 0,
				'fields' => array(),
			));
		}

        $sourcetype = $dataport['sourcetype'];
        $fieldConfig = $dataport['sourceconfig'];

        $fieldTable = RawItemField::getInstance();
        $fields = $fieldTable->find(array('dataportId = ?' => $id), 'priority, fieldNo');

        foreach ($fields as $field) {
            $key = 'field_'.$field['fieldNo'];

            $fieldData = array(
                'fieldNo' => $field['fieldNo'],
                'name' => $field['name']
            );

            foreach (self::$sourceconfigFields[$sourcetype] as $config => $defaultValue) {
                if (isset($fieldConfig['fields'][$key]) && is_array($fieldConfig['fields'][$key]) && array_key_exists($config, $fieldConfig['fields'][$key])) {
                    $fieldData[$config] = $fieldConfig['fields'][$key][$config];
                } else {
                    $fieldData[$config] = $defaultValue;
                }
            }

            $fieldData['data1'] = $this->translator->trans('loading', [], 'admin').' ...';

            $data[] = $fieldData;
        }

		return new JsonResponse(array(
			'success' => true,
			'total' => count($data),
			'fields' => $data,
		));
	}

    /**
     * @Route("/get-demo-data")
     */
	public function getDemoDataAction(Request $request) {
        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);

        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        if(method_exists(Db::getConnection()->getConfiguration(), 'setSQLLogger')) {
            Db::getConnection()->getConfiguration()->setSQLLogger(null);
        }
        if (class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }
        if (method_exists(\Doctrine\Deprecations\Deprecation::class, 'disable')) {
            \Doctrine\Deprecations\Deprecation::disable();
        }

	    $dataports = Dataport::getInstance();
	    $dataport = $dataports->get($request->get('dataportId'));
        if(!$dataport) {
            return new JsonResponse(['data' => []]);
        }

        $request->attributes->set(OpenDxp\Http\Request\Resolver\OpenDxpContextResolver::ATTRIBUTE_OPENDXP_CONTEXT, 'webservice');
        $request->attributes->set(OpenDxp\Http\RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, false);

        fetchDemoData:
        $rawData = PimcoreDbRepository::getInstance()->findInSql('SELECT fieldNo, value FROM '.Installer::TABLE_RAWITEMDATA.' WHERE rawItemId=(SELECT rawitem.id FROM '.Installer::TABLE_DATAPORT_RESOURCE.' resource INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON resource.id = rawitem.dataport_resource_id WHERE resource.dataportId = ? LIMIT 1)', [$dataport['id']]);
        $errors = [];

        if (!$rawData) {
            $currentDate = new DateTimeImmutable();
            $currentDate = $currentDate->setTimezone(new DateTimeZone('UTC'));
            $currentlyRunningRawdataImport = ImportStatus::getInstance()->findOne(
                [
                    'dataport_id = ?' => $dataport['id'],
                    'status = ?' => ImportStatus::STATUS_RUNNING,
                    'importType & ?' => ImportStatus::TYPE_RAWDATA,
                    'startDate > ?' => $currentDate->sub(new DateInterval('PT5M'))->format('Y-m-d H:i:s')
                ]
            );

            if($currentlyRunningRawdataImport) {
                sleep(1);
                goto fetchDemoData;
            }
        }

        if(!$rawData && (!empty($dataport['sourceconfig']['file']) || ($dataport['sourcetype'] === 'pimcore' && !empty($dataport['sourceconfig']['sourceClass'])))) {
            $predefinedStatusKey = uniqid('', true);
            Cli::exec('data-bridge:extract '.$dataport['id'].' -vv -f --limit=1 --status-key="'.$predefinedStatusKey.'"');
            $fileObject = \OpenDxp::getContainer()->get('pim.logger')->getLogFileObject();
            $applicationLogStorage = Helper::getApplicationLogStorage();
            if($fileObject && $applicationLogStorage->fileExists($fileObject->getSystemPath())) {
                $logs = $applicationLogStorage->read($fileObject->getSystemPath());
                preg_match_all('/^\[(WARNING|ERROR|ALERT|EMERGENCY|NOTICE)\] (.*(?:\r?\n(?!\[(WARNING|ERROR|ALERT|EMERGENCY|NOTICE|INFO|DEBUG)\]).*)*)/m', $logs, $errors, PREG_PATTERN_ORDER);
                $applicationLogStorage->delete($fileObject->getSystemPath());
            }

            $rawData = PimcoreDbRepository::getInstance()->findInSql(
                'SELECT rawItemId,fieldNo, value FROM '.Installer::TABLE_RAWITEMDATA.' WHERE rawItemId=(SELECT rawitem.id FROM '.Installer::TABLE_DATAPORT_RESOURCE.' resource INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON resource.id = rawitem.dataport_resource_id WHERE resource.dataportId = ? LIMIT 1)',
                [$dataport['id']]
            );

            ImportStatus::getInstance()->deleteWhere(['`key`' => $predefinedStatusKey]);
            if ($rawData) {
                RawItem::getInstance()->delete(reset($rawData)['rawItemId']);
            }
        }
        
        $fields = RawItemField::getInstance()->find(['dataportId = ?' => $dataport['id']], 'priority');
        $previewData = [];
        foreach ($fields as $field) {
            $previewData[$field['fieldNo']] = ['fieldNo' => $field['fieldNo'], 'value' => ''];
        }

        if($rawData) {
            foreach($rawData as $rawDataItem) {
                $previewData[$rawDataItem['fieldNo']]['value'] = $rawDataItem['value'];
            }
        } elseif($errors) {
            $errorString = implode("\n", $errors[0]);
            foreach ($previewData as &$previewField) {
                $previewField['value'] = $errorString;
            }
            unset($previewField);
        }
        
        return new JsonResponse(['data' => array_values($previewData)]);
    }

    /**
     * @Route("/get-previewgrid-config")
     */
	public function getPreviewgridConfigAction(Request $request) {
		$response = array(
			'success' => false
		);

        $id = (int)$request->get('dataportId');

        $user = Tool\Admin::getCurrentUser();
        if (!Dataport::canDataportBeConfiguredBy($id, $user) && !Dataport::canDataportBeExecutedBy($id, $user)) {
            $response = array(
                'errorMessage' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($id), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$table = Dataport::getInstance();
		$dataport = $table->get($id);

		if (!$dataport) {
			$response['errorMessage'] = $this->translator->trans('pim_dataport_notfound', [], 'admin');
			return new JsonResponse($response);
		}

        $fieldMappingTable = Fieldmapping::getInstance();
        $keyFieldNumbers = array_map(static function($keyFieldMapping) {
                return $keyFieldMapping['fieldNo'];
            },
            $fieldMappingTable->find(
            [
                'dataportId = ?' => $id,
                'keyMapping = ?' => 1,
                'fieldName NOT IN (?)' => ['__result_callback', '__result_action', '__init_action']
            ])
        );

        $sourceConfig = $dataport['sourceconfig'];
        foreach ((array)$sourceConfig['fields'] as $fieldIndex => $field) {
            if (!empty($field['exportKey'])) {
                $keyFieldNumbers[] = str_replace('field_', '', $fieldIndex);
            }
        }

		$columns = [];

        foreach(Dataport::getUnmappedVirtualFields($dataport['id']) as $parameterField) {
            $columns[] = array(
                'name' => $parameterField,
                'fieldNo' => $parameterField,
                'keyField' => false
            );
        }

		$fieldTable = RawItemField::getInstance();
		$rawItemFields = $fieldTable->find(['dataportId = ?' => $dataport['id']], 'priority');
		foreach ($rawItemFields as $field) {
			$columns[] = array(
                'name'    => $field['name'],
                'fieldNo' => $field['fieldNo'],
                'keyField' => in_array($field['fieldNo'], $keyFieldNumbers, false)
			);
		}

		$response['success'] = true;
		$response['columns'] = $columns;

		return new JsonResponse($response);
	}

    /**
     * @Route("/get-rawdata/{dataportId}")
     */
	public function getRawdataAction(Request $request, int $dataportId) {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests
        $user = Tool\Admin::getCurrentUser();

        if (!Dataport::canDataportBeConfiguredBy($dataportId, $user) && Dataport::canDataportBeExecutedBy($dataportId, $user)) {
            $response = array(
                'message' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$data = [];

		$table = Dataport::getInstance();
		$dataport = $table->get($dataportId);

		if (!$dataport) {
			return new JsonResponse(array(
				'success' => false,
				'total' => 0,
				'fields' => [],
			));
		}

        $isMultivalue = [];
        $sourceconfig = $dataport['sourceconfig'];
        if (is_array($sourceconfig['fields'])) {
            $isMultivalue = array_filter(
                $sourceconfig['fields'],
                static function ($values) {
                    return isset($values['multiValues']) && $values['multiValues'] === true;
                }
            );
        }

		$start = (int)$request->get('start');
        if (!$start || $start < 0) {
            $start = 0;
        }

		$limit = (int)$request->get('limit');
        if (!$limit || $limit < 1) {
            $limit = 25;
        }

        $query = $request->get('query');
        $sort = json_decode($request->get('sort', ''));
		$condition = 'FROM '.Installer::TABLE_RAWITEM.' i'.((!empty($query) || !empty($sort))?' INNER JOIN ' . Installer::TABLE_RAWITEMDATA . ' d ON i.id = d.rawItemId':'').' INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON i.dataport_resource_id = dataport_resource.id'.(!empty($query)?' INNER JOIN '.Installer::TABLE_RAWITEMFIELD.' field ON d.fieldNo=field.fieldNo AND dataport_resource.dataportId=field.dataportId':'').' WHERE dataport_resource.dataportId = ?';

		$variables = [$dataportId];

		if (!empty($query)) {
			$conditionParts = [];
            $query = preg_replace('/(?!"[^"]*)([\p{L}\p{Nd}_-]+)(?![^"]*")/u', '$1*', $query);
            $query = preg_replace('/([^ ]+-)\*( |$)/', '"$1"', $query);
            $query = preg_replace('/([^ ]+)-([^ ]+)\*( |$)/', '"$1-$2"', $query);

            $conditionParts[] = 'MATCH(d.value) AGAINST (? IN BOOLEAN MODE)';
            $variables[] = $query;

			if($conditionParts) {
                $condition .= ' AND ('.implode(' OR ', $conditionParts).')';
            }
		}

        if($request->get('filter')) {
            $conditions = [1];
            foreach(\json_decode($request->get('filter'), true) as $filter) {
                if($filter['value'] && $filter['operator'] === 'in' && in_array($filter['property'], ['file', 'locale'], true)) {
                    $conditionsOr = [];
                    foreach($filter['value'] as $filterValue) {
                        if($filterValue == -1) {
                            $conditionsOr[] = 'resource NOT LIKE \'%"'.$filter['property'].'":%\'';
                        } else {
                            $conditionsOr[] = 'resource LIKE \'%"'.$filter['property'].'":"'.str_replace('\'', '\\\'',$filterValue).'"%\'';
                        }
                    }
                    $conditions[] = implode(' OR ', $conditionsOr);
                }
            }

            $condition .= ' AND ('.implode(') AND (', $conditions).')';
        }

		$db = PimcoreDbRepository::getInstance();

        if (!empty($sort)) {
            $sort = array_map(static function($orderFragment) use ($db) {
                if($orderFragment->property === 'file') {
                    return 'dataport_resource.resource '.$orderFragment->direction;
                }
                if($orderFragment->property === 'updated') {
                    return 'i.updated '.$orderFragment->direction;
                }
                if($orderFragment->property === 'id') {
                    return 'i.id '.$orderFragment->direction;
                }
                return '
                    GROUP_CONCAT(IF(d.fieldNo = '.Db::get()->quote(str_replace('field_', '', $orderFragment->property)).', d.value, "")) '.$orderFragment->direction;
            }, $sort);

            $sortQuery = implode(',', $sort);
        } else {
            $targetConfig = $dataport['targetconfig'];
            $sortQuery = 'i.dataport_resource_id, '.((empty($targetConfig['itemClass']) || $dataport['sourcetype'] === 'pimcore') ? 'i.priority' : 'updated,i.priority');
        }

        $items = $db->findInSql('SELECT i.id, i.updated, dataport_resource.resource ' . $condition. ((!empty($query) || !empty($sort)) ? ' GROUP BY i.id':'').' ORDER BY '.$sortQuery.' LIMIT '.$start.','.$limit, $variables);

        if(!empty($query) || !empty($sort)) {
            $total = $db->findOneInSql('SELECT COUNT(*) FROM (SELECT 1 '.$condition.((!empty($query) || !empty($sort)) ? ' GROUP BY i.id' : '').') t', $variables);
        } else {
            $total = $db->findOneInSql('SELECT COUNT(*) '.$condition, $variables);
        }

        foreach ($items as $item) {
            $data[] = $this->getRawItemPreview($item, array_merge($item, ['dataportId' => $dataportId]), $isMultivalue);
        }

		return new JsonResponse(array(
			'success' => true,
			'total' => $total,
			'fields' => $data,
		));
	}

    /**
     * @Route("/get-dataport-resources/{dataportId}")
     */
    public function getDataportResourcesAction(Request $request, int $dataportId) {
        $dataportResources = DataportResource::getInstance();

        $data = ['dataportResources' => []];
        foreach($dataportResources->find(['dataportId = ?' => $dataportId], 'lastAccess DESC', 10) as $dataportResource) {
            $data['dataportResources'][] = \json_decode($dataportResource['resource'], true)['file'] ?? '';
        }

        $data['dataportResources'] = \array_unique($data['dataportResources']);

        $data['dataportResources'] = array_map(static function($dataportResource) {
            return ['text' => $dataportResource?:'(Default)', 'id' => $dataportResource?:-1];
        }, $data['dataportResources']);

        return new JsonResponse($data);
    }

    /**
     * @Route("/get-dataport-resource-locales/{dataportId}")
     */
    public function getDataportResourceLocalesAction(Request $request, int $dataportId) {
        $dataportResources = DataportResource::getInstance();

        $data = ['dataportResourceLocales' => []];
        foreach($dataportResources->find(['dataportId = ?' => $dataportId]) as $dataportResource) {
            $data['dataportResourceLocales'][] = \json_decode($dataportResource['resource'], true)['locale'] ?? '';
        }

        $data['dataportResourceLocales'] = \array_unique($data['dataportResourceLocales']);

        $data['dataportResourceLocales'] = array_map(static function($dataportResource) {
            return ['text' => $dataportResource?:'(Default)', 'id' => $dataportResource?:-1];
        }, $data['dataportResourceLocales']);

        return new JsonResponse($data);
    }

    /**
     * @Route("/delete-rawdata", methods={"POST"})
     */
	public function deleteRawdataAction(Request $request) {
        $requestData = json_decode($request->getContent(), true);

		if (empty($requestData['id'])) {
            if (is_array($requestData)) {
                $requestData['id'] = array_column($requestData, 'id');
            }

            if (empty($requestData['id'])) {
                return new JsonResponse(array(
                    'success' => false,
                ));
            }
		}

        $rawItemIds = array_map(static function($id) {
            return Uuid::fromInteger($id)->getBytes();
        }, (array)$requestData['id']);

		$dataportId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT dataport_resource.dataportId FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE rawitem.id IN ('.rtrim(str_repeat('?,', count($rawItemIds)), ',').') LIMIT 1', $rawItemIds);
		if(!$dataportId) {
            $response = array(
                'success' => false,
                'message' => 'Could not find raw item'
            );
            return new JsonResponse($response);
        }

        $user = Tool\Admin::getCurrentUser();

        if (!Dataport::canDataportBeExecutedBy($dataportId, $user)) {
            $response = array(
                'success' => false,
                'message' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$table = RawItem::getInstance();
		$numDeleted = 0;
        foreach($rawItemIds as $id) {
            $numDeleted += $table->delete($id);
        }

        Cache::clearTag('mapping-preview-'.$dataportId);

		return new JsonResponse(array(
			'success' => $numDeleted > 0,
		));
	}

    /**
     * @Route("/get-pimcore-classes")
     */
    public function getPimcoreClassesAction() {
        $result = [];

        $user = Tool\Admin::getCurrentUser();

        if (!$user->isAdmin()) {
            $userClasses = $user->getClasses();

            $roleClasses = [[]];
            foreach ($user->getRoles() as $roleId) {
                $role = User\Role::getById($roleId);
                $roleClasses[] = $role->getClasses();
            }
            $roleClasses = array_merge(...$roleClasses);

            $permittedClasses = array_unique(array_merge($userClasses, $roleClasses));
        }

        $list = new ClassDefinition\Listing();

        if (!empty($permittedClasses)) {
            $list->setCondition('id IN (?)', [$permittedClasses]);
        }

        $classes = $list->load();

        /**
         * @var $class ClassDefinition
         */
        foreach ($classes as $class) {
            if(!$class instanceof ClassDefinition) {
                // happens when there is a class in classes DB table which does not exist in filesystem
                continue;
            }

            $supportsInheritance = $class->getAllowInherit();

            if (!$supportsInheritance) {
                foreach ($class->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof Data\Relations\AbstractRelations) {
                        foreach ($fieldDefinition->getClasses() as $allowedClass) {
                            $allowedClassDefinition = ClassDefinition::getByName($allowedClass['classes']);

                            if (!$allowedClassDefinition instanceof ClassDefinition) {
                                continue;
                            }

                            if ($allowedClassDefinition->getAllowInherit()) {
                                $supportsInheritance = true;
                                break;
                            }
                        }
                    }
                }
            }

            if(!$supportsInheritance && $class->getFieldDefinition('localizedfields')) {
                foreach(Tool::getValidLanguages() as $language) {
                    if(Tool::getFallbackLanguagesFor($language)) {
                        $supportsInheritance = true;
                        break;
                    }
                }
            }

            if (!$supportsInheritance) {
                $supportsInheritance = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM objects INNER JOIN properties ON objects.'.Helper::prefixObjectSystemColumn('parentId').'=properties.cid AND properties.ctype=\'object\' WHERE '.Helper::prefixObjectSystemColumn('classId').' = ? AND inheritable=1 LIMIT 1', [$class->getId()]);
            }

            if (!$supportsInheritance) {
                $supportsInheritance = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM objects object INNER JOIN objects parent ON object.'.Helper::prefixObjectSystemColumn('parentId').'=parent.'.Helper::prefixObjectSystemColumn('id').' INNER JOIN properties ON parent.'.Helper::prefixObjectSystemColumn('parentId').'=properties.cid AND properties.ctype=\'object\' WHERE object.'.Helper::prefixObjectSystemColumn('classId').' = ? AND inheritable=1 LIMIT 1', [$class->getId()]);
            }

            $result[] = [
                'group' => $this->translator->trans('pim.dataport_targetclass.data_object_classes', [], 'admin'),
                'id' => $class->getId(),
                'name' => $class->getName(),
                'supportsInheritance' => $supportsInheritance
            ];
        }

        usort($result, static function($class1, $class2) {
            return strcasecmp($class1['name'], $class2['name']);
        });

        $result[] = [
            'group' => $this->translator->trans('pim.dataport_targetclass.core_classes', [], 'admin'),
            'id'   => Asset::class,
            'name' => $this->translator->trans('asset', [], 'admin'),
            'supportsInheritance' => false,
        ];

        $result[] = [
            'group' => $this->translator->trans('pim.dataport_targetclass.core_classes', [], 'admin'),
            'id'   => Page::class,
            'name' => $this->translator->trans('document', [], 'admin'),
            'supportsInheritance' => true,
        ];

        $result[] = [
            'group' => $this->translator->trans('export', [], 'admin'),
            'id'   => '0',
            'name' => $this->translator->trans('export', [], 'admin'),
            'supportsInheritance' => false,
        ];

        return new JsonResponse(array(
            'success' => true,
            'classes' => $result
        ));
    }

    /**
     * @Route("/suggest_condition")
     */
    public function suggestCondition(Request $request)
    {
        $suggestions = [];

        if(!$request->get('sourceClass')) {
            return new JsonResponse([
                'success' => false,
                'items' => []
            ]);
        }

        $itemMold = $this->helper->getItemMoldByClassId($request->get('sourceClass'));
        $listing = $itemMold->getList(
            [
                'objectTypes' => []
            ]
        );

        $condition = $request->get('condition');
        $currentTokenIndex = null;
        $sqlTemplate = '$$$';
        if($condition) {
            if($itemMold instanceof Concrete) {
                $localizedFields = $itemMold->getClass()->getFieldDefinition('localizedfields');
                if ($localizedFields instanceof Data\Localizedfields) {
                    $listLocale = null;
                    foreach (Tool::getValidLanguages() as $language) {
                        if (stripos($condition, '#'.$language) !== false) {
                            if ($listLocale === null) {
                                $listing->setLocale($language);
                                $listLocale = $language;
                                $condition = str_ireplace('#'.$language, '', $condition);
                            } else {
                                throw new Exception('Currently it is not possible to use localized fields in different locales');
                            }
                        }
                    }
                }

                $fieldDefinitions = $itemMold->getClass()->getFieldDefinitions();
                foreach ($fieldDefinitions as $fieldDefinition) {
                    if ($fieldDefinition instanceof Data\Objectbricks) {
                        $allowedBricks = $fieldDefinition->getAllowedTypes();
                        foreach ($allowedBricks as $allowedBrick) {
                            if (stripos($condition, $allowedBrick.'.') !== false) {
                                $listing->addObjectBrick($allowedBrick);
                            }
                        }
                    } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations && stripos($condition, $fieldDefinition->getName().'.') !== false) {
                        preg_match_all('/('.$fieldDefinition->getName().'.(\S+))\s*(=|>=|<=|<|>|LIKE)\s*(\S+)/', $condition, $relationalConditions, PREG_SET_ORDER);
                        foreach ($relationalConditions as $relationalCondition) {
                            foreach ($fieldDefinition->getClasses() as $allowedClass) {
                                $allowedClassDefinition = ClassDefinition::getByName($allowedClass['classes']);

                                if (!$allowedClassDefinition instanceof ClassDefinition) {
                                    continue;
                                }

                                $relationFieldDefinition = $allowedClassDefinition->getFieldDefinition($relationalCondition[2]);
                                if ($relationFieldDefinition instanceof Data) {
                                    $replacementCondition = Helper::prefixObjectSystemColumn('id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id IN (SELECT o_id FROM object_'.$allowedClassDefinition->getId(
                                        ).' WHERE `'.$relationalCondition[2].'` '.$relationalCondition[3].' '.$relationalCondition[4].') AND type = \'object\' AND ownertype = \'object\' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')';

                                    $condition = str_replace($relationalCondition[0], $replacementCondition, $condition);
                                }
                            }
                        }
                    }
                }

                if (count($listing->getObjectbricks()) > 0) {
                    foreach ($fieldDefinitions as $fieldDefinition) {
                        $condition = preg_replace('/(^|[^.])('.$fieldDefinition->getName().'($|[^0-9_A-Za-z]))/', '$1'.$listing->getDao()->getTableName().'.$2', $condition);
                    }
                    foreach (Helper::getSystemFields() as $systemField) {
                        $condition = preg_replace('/(^|[^.])('.$systemField.'($|[^0-9_A-Za-z]))/', '$1'.$listing->getDao()->getTableName().'.$2', $condition);
                    }
                }
            }

            $cursorPosition = $request->get('cursorPosition');

            if(method_exists($listing->getDao(), 'getQueryBuilder')) {
                $listing->setCondition('('.$condition.')');
                $sqlQuery = (string)$listing->getDao()->getQueryBuilder('*');
            } else {
                $listing->setCondition($condition);
                $sqlQuery = (string)$listing->getQuery();
            }

            $parser = new Parser($sqlQuery);
            $sqlTemplate = '';
            $isConditionPart = false;

            // where (ean
            $parser->list->idx = 0;
            foreach($parser->list->tokens as $index => $token) {
                if ($isConditionPart) {
                    if ($beginningWhereClause + $cursorPosition !== $token->position + strlen($token->token)) {
                        $sqlTemplate .= $token->token;
                    } else {
                        $sqlTemplate .= ($token->position !== $beginningWhereClause?' ':'').'$$$ ';
                        $currentTokenIndex = $index;
                    }
                }

                if($token->token === 'WHERE') {
                    $isConditionPart = true;
                    $beginningWhereClause = $token->position + strlen($token->token) + 2 /* opening WHERE clause brace + whitespace */;
                }
            }

            $sqlTemplate = substr($sqlTemplate, 2, -1); // remove braces at beginning + end
        }

        if ($currentTokenIndex === null) {
            $currentToken = new Token('', Token::TYPE_NONE);
        } else {
            $currentToken = $parser->list->tokens[$currentTokenIndex];
        }

        if (method_exists($listing->getDao(), 'getTableName')) {
            $tableName = $listing->getDao()->getTableName();
        } else {
            $tableName = \OpenDxp\Model\Element\Service::getElementType($itemMold).'s';
        }

        if ($currentToken->type === Token::TYPE_NONE) {
            if (stripos('Unreferenced objects', $currentToken->token) !== false || stripos($this->translator->trans('pim.dataport.pimcore.condition.suggest.unreferenced_elements', [], 'admin'), $currentToken->token) !== false) {
                $suggestions[] = [
                    'label' => $this->translator->trans('pim.dataport.pimcore.condition.suggest.unreferenced_elements', [], 'admin'),
                    'value' => str_replace('$$$', 'type!=\'folder\' AND '.($itemMold instanceof Concrete ? Helper::prefixObjectSystemColumn('id') : 'id').' NOT IN (SELECT targetid FROM dependencies WHERE targettype=\''.OpenDxp\Model\Element\Service::getElementType($itemMold).'\')', $sqlTemplate)
                ];
            }

            $columns = $listing->getValidTableColumns($tableName, true);
            foreach ($columns as $column) {
                $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                $label = '';
                if ($fieldDefinition instanceof Data) {
                    $label = $fieldDefinition->getTitle() ?? '';
                }

                if (stripos($column, $currentToken->token) !== false || stripos($this->translator->trans($label, [], 'admin'), $currentToken->token) !== false) {
                    if ($fieldDefinition instanceof Data && $itemMold instanceof Concrete) {
                        $localizedFields = $itemMold->getClass()->getFieldDefinition('localizedfields');
                        if ($localizedFields instanceof Data\Localizedfields && $localizedFields->getFieldDefinition($column) instanceof Data) {
                            foreach (Tool::getValidLanguages() as $language) {
                                $suggestions[] = [
                                    'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').' '.\Locale::getDisplayLanguage($language, $request->getLocale()).')' : ''),
                                    'value' => str_replace('$$$', $column.'#'.$language, $sqlTemplate)
                                ];
                            }
                        } else {
                            $suggestions[] = [
                                'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').')' : ''),
                                'value' => str_replace('$$$', $column, $sqlTemplate)
                            ];
                        }
                    } else {
                        $suggestions[] = [
                            'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').')' : ''),
                            'value' => str_replace('$$$', $column, $sqlTemplate)
                        ];
                    }
                }
            }
        } elseif($currentToken->type === Token::TYPE_WHITESPACE) {
            $previousTokenIndex = $currentTokenIndex;
            do {
                $previousTokenIndex--;

                /** @var Token $previousToken */
                $previousToken = $parser->list->tokens[$previousTokenIndex] ?? null;
            } while($previousToken !== null && $previousToken->type === Token::TYPE_WHITESPACE);

            if($previousToken->type === Token::TYPE_NONE) {
                $columns = $listing->getValidTableColumns($tableName, true);
                foreach ($columns as $column) {
                    if (strtolower($column) === strtolower($previousToken->token)) {
                        $suggestions[] = [
                            'label' => '=',
                            'value' => str_replace('$$$', '=', $sqlTemplate)
                        ];

                        $suggestions[] = [
                            'label' => '<',
                            'value' => str_replace('$$$', '<', $sqlTemplate)
                        ];

                        $suggestions[] = [
                            'label' => '<=',
                            'value' => str_replace('$$$', '<=', $sqlTemplate)
                        ];

                        $suggestions[] = [
                            'label' => '>',
                            'value' => str_replace('$$$', '>', $sqlTemplate)
                        ];

                        $suggestions[] = [
                            'label' => '>',
                            'value' => str_replace('$$$', '>=', $sqlTemplate)
                        ];

                        $suggestions[] = [
                            'label' => 'LIKE (' . $this->translator->trans('pim.dataport.pimcore.condition.suggest.like', [], 'admin') . ')',
                            'value' => str_replace('$$$', 'LIKE', $sqlTemplate)
                        ];
                    }
                }
            } elseif ($previousToken->type === Token::TYPE_OPERATOR) {
                $previousPreviousTokenIndex = $previousTokenIndex;
                do {
                    $previousPreviousTokenIndex--;

                    /** @var Token $previousPreviousToken */
                    $previousPreviousToken = $parser->list->tokens[$previousPreviousTokenIndex] ?? null;
                } while ($previousPreviousToken !== null && $previousPreviousToken->type === Token::TYPE_WHITESPACE);

                $column = $previousPreviousToken->token;

                if ($column) {
                    try {
                        $values = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT ' . $column . ' FROM ' . $tableName . ' WHERE '.$column.' IS NOT NULL GROUP BY ' . $column . ' ORDER BY COUNT(*)');
                        foreach ($values as $value) {
                            $suggestions[] = [
                                'label' => Db::get()->quote($value),
                                'value' => str_replace('$$$', Db::get()->quote($value), $sqlTemplate)
                            ];
                        }
                    } catch (Exception $e) {
                    }
                }
            } elseif(in_array($previousToken->type, [Token::TYPE_STRING, Token::TYPE_NUMBER, Token::TYPE_BOOL], true)) {
                $suggestions[] = [
                    'label' => 'AND',
                    'value' => str_replace('$$$', 'AND', $sqlTemplate)
                ];
                $suggestions[] = [
                    'label' => 'OR',
                    'value' => str_replace('$$$', 'OR', $sqlTemplate)
                ];
            } elseif($previousToken->type === Token::TYPE_KEYWORD) {
                $columns = $listing->getValidTableColumns($tableName, true);

                foreach ($columns as $column) {
                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $column);
                    $label = '';
                    if ($fieldDefinition instanceof Data) {
                        $label = $fieldDefinition->getTitle() ?? '';
                    }

                    if ($column !== $currentToken->token && (stripos($column, $currentToken->token) !== false || stripos($this->translator->trans($label, [], 'admin'), $currentToken->token) !== false)) {
                        if ($fieldDefinition instanceof Data && $itemMold instanceof Concrete) {
                            $localizedFields = $itemMold->getClass()->getFieldDefinition('localizedfields');
                            if($localizedFields instanceof Data\Localizedfields && $localizedFields->getFieldDefinition($column) instanceof Data) {
                                foreach(Tool::getValidLanguages() as $language) {
                                    $suggestions[] = [
                                        'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').' '.\Locale::getDisplayLanguage($language, $request->getLocale()).')' : ''),
                                        'value' => str_replace('$$$', $column.'#'.$language, $sqlTemplate)
                                    ];
                                }
                            } else {
                                $suggestions[] = [
                                    'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').')' : ''),
                                    'value' => str_replace('$$$', $column, $sqlTemplate)
                                ];
                            }
                        } else {
                            $suggestions[] = [
                                'label' => $column.($label ? ' ('.$this->translator->trans($label, [], 'admin').')' : ''),
                                'value' => str_replace('$$$', $column, $sqlTemplate)
                            ];
                        }
                    }
                }
            }
        }

        $suggestions = array_filter($suggestions, function($suggestion) use ($condition) {
            return $condition !== $suggestion['value'];
        });

        if(count($suggestions) > 100) {
            $suggestions = array_slice($suggestions, 0, 100);
        }

        return new JsonResponse([
            'success' => true,
            'items' => $suggestions
        ]);
    }

    /**
     * @Route("/get-data-query-selector-items")
     */
    public function getDataQuerySelectorItems(Request $request) {
        $result = [];

        $value = $request->get('value');
        $cursorPosition = $request->get('cursorPosition');

        $previousSeparatorPosition = false;
        if($cursorPosition > 0) {
            $previousSeparatorPosition = strrpos($value, ':', $cursorPosition-strlen($value)-1);
        }
        if($previousSeparatorPosition === false) {
            $previousSeparatorPosition = -1;
        }

        $parenthesisParser = new AutocompleteParenthesisParser();
        $bracedItems = $parenthesisParser->parse('('.$value.'))');
        if(count($bracedItems) === 0) {
            $bracedItems = $parenthesisParser->parse('('.$value.')');
        }

        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($bracedItems));
        $dataQuerySelectorBraces = [];
        $suggestSuffix = '';

        $suggestPrefix = substr($value, 0, $cursorPosition);
        $prefixPosition = max(strrpos($suggestPrefix, ':') ?: 0, strrpos($suggestPrefix, ';') ?: 0, strrpos($suggestPrefix, '(') ?: 0);

        if($prefixPosition > 0) {
            $suggestPrefix = substr($suggestPrefix, 0, $prefixPosition+1);
        } else {
            $suggestPrefix = '';
        }

        foreach ($it as $v) {
            $dataQuerySelectorBraces[] = $v;
        }

        $dataQuerySelector = implode(':', $dataQuerySelectorBraces);

        $currentItems = \str_getcsv($dataQuerySelector, ':');

        $result[] = [
            'label' => $this->translator->trans('pim.dataport.pimcore.fields.your_input', [], 'admin'),
            'value' => ''
        ];

        if (!empty($request->get('sourceClass'))) {
            try {
                $itemMold = $this->helper->getItemMoldByClassId($request->get('sourceClass'));
                $classFqn = \get_class($itemMold);
            } catch (\Exception $e) {
                goto processResults;
            }
        } else {
            goto processResults;
        }

        try {
            $targetClasses = $this->getDataQueryTargetClass($classFqn, $currentItems);
            if(count($targetClasses) === 0) {
                $targetClasses = [$classFqn];
            }
            foreach($targetClasses as $targetClass) {
                if(in_array($targetClass, ['bool', 'int', 'double', 'string', 'float'], true)) {
                    foreach($result as $resultIndex => $resultItem) {
                        if($resultItem['value'] === '' && $resultItem['label'] === $this->translator->trans('pim.dataport.pimcore.fields.your_input', [], 'admin')) {
                            unset($result[$resultIndex]);
                        }
                    }

                    $result[] = [
                        'label' => $this->translator->trans('pim.dataport.pimcore.fields.your_input', [], 'admin'),
                        'value' => $currentItems[count($currentItems)-1]
                    ];
                    continue;
                }

                $itemMold = null;
                if(is_a($targetClass, Concrete::class, true)) {
                    try {
                        $itemMold = $this->helper->getItemMoldByClassId($targetClass::classId());
                    } catch (\Exception $e) {
                        $itemMold = new Concrete();
                    }
                }

                try {
                    $classReflection = new \ReflectionClass($targetClass);
                    $methods = $classReflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                    $traits = $classReflection->getTraits();
                    if (is_a($targetClass, Asset::class, true)) {
                        $traits[] = new ReflectionClass(Asset\MetaData\EmbeddedMetaDataTrait::class);
                    }

                    foreach($traits as $trait) {
                        $methods = array_merge($methods, $trait->getMethods(\ReflectionMethod::IS_PUBLIC));
                    }

                    $docBlockFactory = DocBlockFactory::createInstance();
                    $contextFactory = new ContextFactory();
                    $getterMethodPrefix = 'get';
                    foreach($methods as $method) {
                        if($method->isStatic() || in_array($method->getName(), ['getLocalizedfields'], true) || strpos($method->getName(), $getterMethodPrefix) !== 0 || $method->getName() === $getterMethodPrefix) {
                            continue;
                        }

                        $name = substr($method->getName(), strlen($getterMethodPrefix));

                        $fieldDefinition = null;
                        if($itemMold !== null) {
                            $fieldDefinition = Importer::getFieldDefinition($itemMold, $name);
                            $label = $this->translator->trans($fieldDefinition->getTitle() ?? '', [], 'admin');
                        } else {
                            try {
                                $docBlockData = $docBlockFactory->create($method, $contextFactory->createFromReflector($classReflection));
                            } catch(\Exception $e) {
                                continue;
                            }

                            $label = $name;
                            if($docBlockData->getSummary() && strpos(strtolower($docBlockData->getSummary()), 'inheritdoc') === false) {
                                $label .= ' ('.$docBlockData->getSummary().')';
                            }
                        }

                        $methodParameters = $method->getParameters();
                        $isLocalized = count($methodParameters)>=1 && $methodParameters[0]->getName() === 'language';

                        if($isLocalized) {
                            $result[] = [
                                'label' => $label.' ('.$this->translator->trans('pim.dataport.pimcore.fields.in_requested_language', [], 'admin').')',
                                'value' => $name
                            ];
                            foreach(Tool::getValidLanguages() as $language) {
                                $result[] = [
                                    'label' => $label.' '.\Locale::getDisplayLanguage($language),
                                    'value' => $name.'#'.$language
                                ];
                            }

                            $result[] = [
                                'label' => $label.' ('.$this->translator->trans('pim.dataport.pimcore.fields.in_all_languages', [], 'admin').')',
                                'value' => $name.'#all'
                            ];
                        } elseif($method->getName() === 'getThumbnail') {
                            $list = new Asset\Image\Thumbnail\Config\Listing();
                            $thumbnailDefinitions = $list->getThumbnails();

                            foreach($thumbnailDefinitions as $thumbnailDefinition) {
                                $result[] = [
                                    'label' => $label . ' ' . $thumbnailDefinition->getName().($thumbnailDefinition->getDescription()?' - '.$thumbnailDefinition->getDescription():''),
                                    'value' => $name . '#' . $thumbnailDefinition->getName()
                                ];
                            }
                        } else {
                            $result[] = [
                                'label' => $label,
                                'value' => $name
                            ];
                        }
                    }

                    if (is_a($targetClass, OpenDxp\Model\DataObject\Data\Hotspotimage::class, true) || is_a($targetClass, Asset::class, true)) {
                        $result[] = [
                            'label' => $this->translator->trans('URL', [], 'admin'),
                            'value' => 'url'
                        ];
                    }

                    if (is_a($targetClass, ElementInterface::class, true)) {
                        $result[] = [
                            'label' => $this->translator->trans('deeplink', [], 'admin'),
                            'value' => 'deeplink'
                        ];

                        $result[] = [
                            'label' => $this->translator->trans('tags', [], 'admin'),
                            'value' => 'tags'
                        ];
                    }

                    if (is_a($targetClass, ElementInterface::class, true)) {
                        $result[] = [
                            'label' => $this->translator->trans('pim.dataport.pimcore.fields.ancestors', [], 'admin'),
                            'value' => 'ancestors'
                        ];
                        $result[] = [
                            'label' => $this->translator->trans('pim.dataport.pimcore.fields.descendants', [], 'admin'),
                            'value' => 'descendants'
                        ];
                        $result[] = [
                            'label' => $this->translator->trans('pim.dataport.pimcore.fields.before', [], 'admin'),
                            'value' => 'before'
                        ];
                    }


                    if (is_a($targetClass, PageSnippet::class, true)) {
                        $result[] = [
                            'label' => $this->translator->trans('HTML', [], 'admin'),
                            'value' => 'html'
                        ];
                    }

                    if (is_a($targetClass, OpenDxp\Model\DataObject\Objectbrick::class, true) || is_a($targetClass, OpenDxp\Model\DataObject\Classificationstore::class, true)) {
                        $result[] = [
                            'label' => $this->translator->trans('pim.dataport.pimcore.fields.labels', [], 'admin'),
                            'value' => 'labels'
                        ];

                        $result[] = [
                            'label' => $this->translator->trans('pim.dataport.pimcore.fields.fields', [], 'admin'),
                            'value' => 'fields'
                        ];
                    }
                } catch(\Exception $e) {
                    $isSelect = false;
                    try {
                        // todo: actually we need to fetch item mold of last but one data query selector part
                        $itemMold = $this->helper->getItemMoldByClassname($classFqn);

                        $fieldDefinition = Importer::getFieldDefinition($itemMold, $currentItems[count($currentItems)-1] === '' ? $currentItems[count($currentItems) - 2] : $currentItems[count($currentItems) - 1]);

                        if ($fieldDefinition instanceof Data\Select || $fieldDefinition instanceof Data\Multiselect) {
                            $isSelect = true;
                            $result[] = [
                                'label' => $this->translator->trans('display_name', [], 'admin'),
                                'value' => 'label'
                            ];

                            foreach (Tool::getValidLanguages() as $language) {
                                $result[] = [
                                    'label' => $this->translator->trans('display_name', [], 'admin').' (' . \Locale::getDisplayLanguage($language) . ')',
                                    'value' => 'label#' . $language
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                    }

                    if(!$isSelect) {
                        if ($targetClass === 'array') {
                            $result[] = [
                                'label' => $this->translator->trans('pim.each', [], 'admin') . ' (Array)',
                                'value' => 'each:('
                            ];
                            for ($i = 0; $i <= 5; $i++) {
                                $set_format = numfmt_create($request->getLocale(), \NumberFormatter::ORDINAL);
                                $result[] = [
                                    'label' => numfmt_format($set_format, $i + 1) . ' ' . $this->translator->trans('element', [], 'admin'),
                                    'value' => $i
                                ];
                            }
                        }
                    }
                }
            }
        } catch(\InvalidArgumentException $e) {
        }

        processResults:
        $result = \array_filter($result, function($suggestion) use ($currentItems) {
            if($currentItems[count($currentItems) - 1] === '') {
                return true;
            }

            $inputLength = mb_strlen($currentItems[count($currentItems) - 1]);

            for($cutStart=0, $cutStartMax = mb_strlen($suggestion['value']) - $inputLength; $cutStart <= $cutStartMax; $cutStart++) {
                if(levenshtein(strtolower(mb_substr($suggestion['value'], $cutStart, $inputLength)), strtolower($currentItems[count($currentItems) - 1])) <= min($inputLength / 2, 2)) {
                    return true;
                }
            }

            for ($cutStart = 0, $cutStartMax = mb_strlen($suggestion['label']) - $inputLength; $cutStart <= $cutStartMax; $cutStart++) {
                if (levenshtein(strtolower(mb_substr($suggestion['label'], $cutStart, $inputLength)), strtolower($currentItems[count($currentItems) - 1])) <= min($inputLength / 2, 2)) {
                    return true;
                }
            }

            return false;
        });

        $suggestions = array_map(function($suggestion) use ($suggestPrefix, $suggestSuffix) {
            $suggestion['label'] = $suggestion['label'] ?: $suggestion['value'];

            $suggestion['value'] = $suggestPrefix.$suggestion['value'].$suggestSuffix;
            if($suggestion['label'] !== $this->translator->trans('pim.dataport.pimcore.fields.your_input', [], 'admin')) {
                if(substr($suggestion['value'], -1) !== '(') {
                    $suggestion['value'] .= ':';
                }
            } else {
                $suggestion['value'] = rtrim($suggestion['value'], ':');
                $suggestion['value'] .= str_repeat(')', substr_count($suggestion['value'], '(') - substr_count($suggestion['value'], ')'));
            }

            return $suggestion;
        }, $result);

        usort($suggestions, static function($suggestion1, $suggestion2) use ($currentItems) {
            if(substr($suggestion1['value'], -7) === ':each:(' && substr($suggestion2['value'], -7) !== ':each:(') {
                return -1;
            }
            if (substr($suggestion1['value'], -7) !== ':each:(' && substr($suggestion2['value'], -7) === ':each:(') {
                return 1;
            }

            $inputLength = mb_strlen($currentItems[count($currentItems) - 1]);
            $lowestDistance1 = INF;
            for ($cutStart = 0, $cutStartMax = mb_strlen($suggestion1['value']) - $inputLength; $cutStart <= $cutStartMax; $cutStart++) {
                $distance = levenshtein(strtolower(mb_substr($suggestion1['value'], $cutStart, $inputLength)), strtolower($currentItems[count($currentItems) - 1])) + ($cutStart === 0 ? 0 : 1);
                if($distance < $lowestDistance1) {
                    $lowestDistance1 = $distance;
                }
            }

            $lowestDistance2 = INF;
            for ($cutStart = 0, $cutStartMax = mb_strlen($suggestion2['value']) - $inputLength; $cutStart <= $cutStartMax; $cutStart++) {
                $distance = levenshtein(strtolower(mb_substr($suggestion2['value'], $cutStart, $inputLength)), strtolower($currentItems[count($currentItems) - 1])) + ($cutStart === 0 ? 0 : 1);
                if ($distance < $lowestDistance2) {
                    $lowestDistance2 = $distance;
                }
            }

            return $lowestDistance1 <=> $lowestDistance2;
        });

        return new JsonResponse([
            'success' => true,
            'items' => array_values(array_column($suggestions, null, 'value')) // array_unique -> https://stackoverflow.com/a/50479421
        ]);
    }

	private function sanitizeName($name) {
		$name = trim($name);
		$name = Service::getValidKey($name, 'object');

		return $name;
	}

	/**
	 * Checks if the given name is still available.
	 * @param $name string Name to check
	 * @param $id int (Optional) ID of a dataport to exclude from check
	 * @return bool true if it is, false otherwise
	 */
	private function checkDataportName($name, $id = null) {
        $existing = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.Installer::TABLE_DATAPORT.' WHERE (name=? OR name=?) AND id!=?', [
            $name,
            urlencode($name),
            (int)$id
        ]);

        return empty($existing);
    }

    /**
     * @Route("/get-javascript-engines")
     */
	public function getJavascriptEnginesAction(Request $request)
	{
		$data = [
            CallbackFunction::ENGINE_PHP,
			CallbackFunction::ENGINE_V8JS,
			CallbackFunction::ENGINE_SPIDERMONKEY,
            CallbackFunction::ENGINE_SPIDERMONKEY_LEGACY,
		];

		$result = [];

		foreach ($data as $key) {
			$nameSuffix = '';
			$available = CallbackFunction::isEngineAvailable($key);
			if (!$available) {
				$nameSuffix = ' (' . $this->translator->trans('pim.jsengine.unavailable', [], 'admin') . ')';
			}

			$result[] = [
				'id' => $key,
				'available' => $available,
				'name' => $this->translator->trans('pim.jsengine.' . $key, [], 'admin') . $nameSuffix
			];
		}

        return new JsonResponse(array(
			'success' => true,
			'data' => $result
		));
	}

    /**
     * @Route("/get-recipients/{dataportId}")
     */
    public function getRecipientsAction(Request $request, int $dataportId)
    {
        $users = new Listing();
        $users->setCondition('type = ?', ['user']);
        $users->setOrderKey('name');

        $potentialRecipients = [];
        foreach($users->load() as $user) {
            if(!$user instanceof User) {
                continue;
            }

            if (Dataport::canDataportBeConfiguredBy($dataportId, $user) || Dataport::canDataportBeExecutedBy($dataportId, $user)) {
                $name = $user->getName();
                if (!$user->getActive()) {
                    $name .= ' ('.$this->translator->trans('pim.rest_api.user_disabled', [], 'admin').')';
                }
                if(!$user->getEmail()) {
                    $name .= ' ('.$this->translator->trans('user_no_email_address', [], 'admin').')';
                }
                $potentialRecipients[] = [
                    'name' => $name,
                    'userId' => $user->getId()
                ];
            }
        }

        return new JsonResponse([
			'success' => true,
			'data' => $potentialRecipients
		]);
	}

    /**
     * @param string $class FQN of class
     * @param array $dataQuerySelectorParts
     *
     * @return string[] FQN of last data query selector item
     */
	public function getDataQueryTargetClass($class, array $dataQuerySelectorParts, array $previousClasses = []) {
	    if(count($dataQuerySelectorParts) === 0) {
	        return [$class];
        }

        $returnTypes = [[]];
        $currentField = \array_shift($dataQuerySelectorParts);

        if(in_array($class, ['\\'.OpenDxp\Model\DataObject\Data\ObjectMetadata::class, '\\'.OpenDxp\Model\DataObject\Data\ElementMetadata::class], true) && $previousClasses) {
            $previousClass = $previousClasses[count($previousClasses)-1];
            if(is_a($previousClass['class'], Concrete::class, true)) {
                $itemMold = $this->helper->getItemMoldByClassId($previousClass['class']);
                $fieldDefinition = Importer::getFieldDefinition($itemMold, $previousClass['field']);

                if($fieldDefinition->getObjectsAllowed()) {
                    foreach ($fieldDefinition->getClasses() as $allowedClass) {
                        $targetItemMold = $this->helper->getItemMoldByClassname($allowedClass['classes']);
                        if ($targetItemMold instanceof Concrete) {
                            $returnTypes[] = $this->getDataQueryTargetClass(get_class($targetItemMold), $dataQuerySelectorParts, $previousClasses);
                        }
                    }
                } elseif ($fieldDefinition->getAssetsAllowed()) {
                    $targetItemMold = $this->helper->getItemMoldByClassId(Asset::class);
                    if ($targetItemMold instanceof Asset) {
                        $returnTypes[] = $this->getDataQueryTargetClass(get_class($targetItemMold), $dataQuerySelectorParts, $previousClasses);
                    }
                } elseif ($fieldDefinition->getDocumentsAllowed()) {
                    $targetItemMold = $this->helper->getItemMoldByClassId(Page::class);
                    if ($targetItemMold instanceof OpenDxp\Model\Document) {
                        $returnTypes[] = $this->getDataQueryTargetClass(get_class($targetItemMold), $dataQuerySelectorParts, $previousClasses);
                    }
                }
            }
        } elseif(in_array($class, ['\\'.Concrete::class, '\\'.AbstractObject::class], true) && $previousClasses) {
            $previousClass = $previousClasses[count($previousClasses) - 1];
            if (is_a($previousClass['class'], OpenDxp\Model\DataObject\Data\ObjectMetadata::class, true)) {
                $previousClass = $previousClasses[count($previousClasses) - 2];
                if (is_a($previousClass['class'], Concrete::class, true)) {
                    $itemMold = $this->helper->getItemMoldByClassId($previousClass['class']);
                    $fieldDefinition = Importer::getFieldDefinition($itemMold, $previousClass['field']);

                    $targetItemMold = $this->helper->getItemMoldByClassname($fieldDefinition->getAllowedClassId());
                    if ($targetItemMold instanceof Concrete) {
                        $returnTypes[] = $this->getDataQueryTargetClass(get_class($targetItemMold), $dataQuerySelectorParts, $previousClasses);
                    }
                }
            }
        } elseif (in_array($class, ['\\'.OpenDxp\Model\DataObject\Fieldcollection::class, '\\'.OpenDxp\Model\DataObject\Objectbrick::class], true) && $previousClasses) {
            $previousClass = $previousClasses[count($previousClasses) - 1];
            if (is_a($previousClass['class'], Concrete::class, true)) {
                $itemMold = $this->helper->getItemMoldByClassId($previousClass['class']);
                /** @var Data\Fieldcollections $fieldDefinition */
                $fieldDefinition = Importer::getFieldDefinition($itemMold, $previousClass['field']);

                foreach($fieldDefinition->getAllowedTypes() as $fieldCollectionType) {
                    if($class === '\\'.OpenDxp\Model\DataObject\Fieldcollection::class) {
                        $fieldCollectionClass = '\\OpenDxp\\Model\\DataObject\\Fieldcollection\\Data\\'.ucfirst($fieldCollectionType);
                    } else {
                        $fieldCollectionClass = '\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\'.ucfirst($fieldCollectionType);
                    }

                    try {
                        $fieldCollectionMold = \OpenDxp::getContainer()->get('opendxp.model.factory')->build($fieldCollectionClass, [$itemMold]);
                        if ($fieldCollectionMold instanceof OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData || $fieldCollectionMold instanceof AbstractData) {
                            $returnTypes[] = $this->getDataQueryTargetClass(get_class($fieldCollectionMold), $dataQuerySelectorParts, $previousClasses);
                        }
                    } catch(\Throwable $e) {
                    }
                }
            }
        } elseif (strtolower($currentField) === 'parent') {
            $previousClass = $previousClasses[count($previousClasses) - 1] ?? ['class' => $class];
            if (is_a($previousClass['class'], Concrete::class, true)) {
                /** @var Concrete $itemMold */
                $itemMold = $this->helper->getItemMoldByClassname($previousClass['class']);

                if($itemMold->getClassId()) {
                    $potentialParentClasses = PimcoreDbRepository::getInstance()->findColumnInSql(
                        'SELECT objects.'.Helper::prefixObjectSystemColumn('classId').' FROM object_'.$itemMold->getClassId().' INNER JOIN objects ON object_'.$itemMold->getClassId().'.'.Helper::prefixObjectSystemColumn('parentId').'=objects.'.Helper::prefixObjectSystemColumn(
                            'id'
                        ).' GROUP BY objects.'.Helper::prefixObjectSystemColumn('classId')
                    );
                    foreach ($potentialParentClasses as $potentialParentClass) {
                        $returnTypes[] = $this->getDataQueryTargetClass(get_class($this->helper->getItemMoldByClassId($potentialParentClass)), $dataQuerySelectorParts, $previousClasses);
                    }
                }
            }
        } elseif (strtolower($currentField) === 'children') {
            $returnTypes[] = $this->getDataQueryTargetClass(Concrete::class, $dataQuerySelectorParts, $previousClasses);
        } elseif (strtolower($currentField) === 'before' && is_a($class, ElementInterface::class, true)) {
            $returnTypes[] = $this->getDataQueryTargetClass($class, $dataQuerySelectorParts, $previousClasses);
        } elseif (is_a($class, Iterator::class, true) && $currentField !== 'current') {
            if (count($dataQuerySelectorParts)) {
                $iteratorDataQuerySelectors = $dataQuerySelectorParts;
                array_unshift($iteratorDataQuerySelectors, 'current');
                $returnTypes[] = $this->getDataQueryTargetClass($class, $iteratorDataQuerySelectors, $previousClasses);
            } else {
                $returnTypes[] = $this->getDataQueryTargetClass('array', $dataQuerySelectorParts, $previousClasses);
            }
        } elseif (is_a($class, OpenDxp\Model\DataObject\Data\Hotspotimage::class, true)) {
            $returnTypes[] = $this->getDataQueryTargetClass(Asset\Image::class, $dataQuerySelectorParts, $previousClasses);
        }

        if ($currentField === '' || ctype_digit($currentField) || in_array($currentField, ['each', 'all'], true)) {
            $returnTypes[] = $this->getDataQueryTargetClass($class, $dataQuerySelectorParts, $previousClasses);
            return array_unique(array_merge(...$returnTypes));
        }

        $previousClasses[] = ['class' => $class, 'field' => $currentField];
        $currentField = explode('#', $currentField)[0];

        $methodName = $currentField;

        if (in_array(\strtolower($methodName), ['getpath', 'path'])) {
            $methodName = 'fullPath';
        }

        if((\class_exists($class) || \interface_exists($class))) {
            $previousClasses[] = ['class' => $class, 'field' => $currentField];
            $reflectionClass = new \ReflectionClass($class);

            try {
                $itemMold = $this->helper->getItemMoldByClassname($class);
                $fieldDefinition = Importer::getFieldDefinition($itemMold, $currentField);

                $returnClasses = [[]];
                if ($fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation) {
                    $returnClasses = $this->getDataQueryTargetClass(get_class($this->helper->getItemMoldByClassId($fieldDefinition->getAllowedClassId())), $dataQuerySelectorParts, $previousClasses);
                    $returnClasses[] = ['array'];
                } elseif($fieldDefinition instanceof Data\ManyToManyObjectRelation || ($fieldDefinition instanceof Data\ManyToManyRelation && $fieldDefinition->getObjectsAllowed()) || ($fieldDefinition instanceof Data\ManyToOneRelation && $fieldDefinition->getObjectsAllowed())) {
                    foreach((array)$fieldDefinition->getClasses() as $className) {
                        $returnClasses[] = $this->getDataQueryTargetClass(get_class($this->helper->getItemMoldByClassname($className['classes'])), $dataQuerySelectorParts, $previousClasses);
                    }

                    if ($fieldDefinition instanceof Data\ManyToManyObjectRelation || $fieldDefinition instanceof Data\ManyToManyRelation) {
                        $returnClasses[] = ['array'];
                    }
                }

                if(count($returnClasses) > 1) {
                    return array_unique(array_merge(...$returnClasses));
                }
            } catch(\Throwable $e) {
            }


            if(!$reflectionClass->hasMethod($methodName)) {
                $methodName = 'get'.\ucfirst($methodName);
            }

            $docBlockFactory = DocBlockFactory::createInstance();
            $contextFactory = new ContextFactory();
            $returnDocBlock = null;

            if ($reflectionClass->hasMethod($methodName)) {
                $reflectionMethod = $reflectionClass->getMethod($methodName);

                try {
                    $docBlockData = $docBlockFactory->create($reflectionMethod, $contextFactory->createFromReflector($reflectionClass));
                    /** @var Return_[] $returnDocBlock */
                    $returnDocBlock = $docBlockData->getTagsByName('return');
                } catch (\Exception $e) {
                }

                if (!$returnDocBlock && $reflectionMethod->hasReturnType()) {
                    $typeResolver = new TypeResolver;
                    $returnDocBlock = new Return_($typeResolver->resolve((string)$reflectionMethod->getReturnType()));
                }
            } else {
                $functionName = $currentField;
                if(strpos($currentField, '%s') !== false) {
                    $functionName = trim(substr($currentField, 0, strpos($currentField, '(')));
                }

                if(is_callable($functionName)) {
                    $reflectionFunction = new \ReflectionFunction($functionName);

                    try {
                        $docBlockData = $docBlockFactory->create($reflectionFunction);
                        $returnDocBlock = $docBlockData->getTagsByName('return');
                    } catch(\Exception $e) {
                    }

                    if (!$returnDocBlock && $reflectionFunction->hasReturnType()) {
                        $typeResolver = new TypeResolver;
                        $returnDocBlock = new Return_($typeResolver->resolve((string)$reflectionFunction->getReturnType()));
                    }
                }
            }

            if ($returnDocBlock) {
                foreach ($returnDocBlock as $returnType) {
                    $returnType = $returnType->getType();

                    $remainingDataQuerySelectorItems = $dataQuerySelectorParts;
                    \array_shift($remainingDataQuerySelectorItems);

                    if ($returnType instanceof Array_ && count($remainingDataQuerySelectorItems) > 0) {
                        $returnType = $returnType->getValueType();

                        $dataQuerySelectorParts = $remainingDataQuerySelectorItems;
                    }

                    if ($returnType instanceof Object_) {
                        $returnTypes[] = $this->getDataQueryTargetClass((string)$returnType->getFqsen(), $dataQuerySelectorParts, $previousClasses);
                    } elseif ($returnType instanceof Self_) {
                        $returnTypes[] = $this->getDataQueryTargetClass($class, $dataQuerySelectorParts, $previousClasses);
                    } elseif ($returnType instanceof Array_) {
                        if($returnType->getValueType() instanceof Object_) {
                            $returnTypes[] = $this->getDataQueryTargetClass((string)$returnType->getValueType()->getFqsen(), $dataQuerySelectorParts, $previousClasses);
                        } else {
                            $returnTypes[] = $this->getDataQueryTargetClass('array', $dataQuerySelectorParts, $previousClasses);
                        }
                    } elseif ($returnType instanceof AggregatedType) {
                        foreach ($returnType as $compoundReturnType) {
                            if ($compoundReturnType instanceof Object_) {
                                $returnTypes[] = $this->getDataQueryTargetClass((string)$compoundReturnType->getFqsen(), $dataQuerySelectorParts, $previousClasses);
                            } elseif (!$compoundReturnType instanceof Null_) {
                                $returnTypes[] = $this->getDataQueryTargetClass((string)$compoundReturnType, $dataQuerySelectorParts, $previousClasses);
                            }
                        }
                    } else {
                        $returnTypes[] = $this->getDataQueryTargetClass((string)$returnType, $dataQuerySelectorParts, $previousClasses);
                    }
                }
            }

            return array_unique(array_merge(...$returnTypes));
        }

        $returnTypes[] = $this->getDataQueryTargetClass($class, $dataQuerySelectorParts, $previousClasses);
        return array_unique(array_merge(...$returnTypes));
    }

    /**
     * @Route("/update-raw-data/{dataportId}", methods={"POST"})
     */
    public function updateRawDataAction(Request $request, $dataportId) {
        if (!Dataport::canDataportBeExecutedBy($dataportId, Tool\Admin::getCurrentUser())) {
            $response = array(
                'success' => false,
                'message' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

        $requestData = json_decode($request->getContent(), true);
        $rawDataId = $requestData['id'];

        $response = [];

        $rawItemData = RawItemData::getInstance();
        if($rawDataId) {
            $rawDataId = Uuid::fromInteger($rawDataId)->getBytes();
            foreach($requestData as $key => $data) {
                if(preg_match('/^field_(\d+)/', $key, $fieldNo)) {
                    $fieldNo = $fieldNo[1];
                    $rawItemData->create(['rawItemId' => $rawDataId, 'fieldNo' => $fieldNo, 'value' => $data]);
                }
            }

            $dataport = Dataport::getInstance()->get($dataportId);
            $sourceConfig = $dataport['sourceconfig'];

            $keyFieldFieldNumbers = [];
            foreach ($sourceConfig['fields'] as $fieldNo => $field) {
                if (!empty($field['exportKey'])) {
                    $keyFieldFieldNumbers[] = (int)substr($fieldNo, strrpos($fieldNo, '_') + 1);;
                }
            }

            $allData = PimcoreDbRepository::getInstance()->findInSql('SELECT raw_item_data.fieldNo, value FROM '.Installer::TABLE_RAWITEMDATA.' raw_item_data INNER JOIN '.Installer::TABLE_RAWITEM.' raw_item ON raw_item_data.rawItemId=raw_item.id INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON raw_item.dataport_resource_id=dataport_resource.id INNER JOIN '.Installer::TABLE_RAWITEMFIELD.' raw_item_field ON dataport_resource.dataportId=raw_item_field.dataportId AND raw_item_data.fieldNo=raw_item_field.fieldNo WHERE raw_item_data.rawItemId = ? ORDER BY raw_item_field.priority', [$rawDataId]);
            $priority = [];
            $keyValues = [];
            foreach($allData as $dataItem) {
                $value = trim($dataItem['value']);
                if($value !== '') {
                    if (is_numeric($value)) {
                        $priority[] = str_pad($value, 10, '0', STR_PAD_LEFT);
                    } else {
                        $priority[] = $value;
                    }
                } else {
                    $priority[] = ' '; // whitespace is first (visible) character in sorting
                }

                if (\in_array($dataItem['fieldNo'], $keyFieldFieldNumbers)) {
                    $keyValues[] = $value;
                }
            }

            if (count($keyValues) === 0) {
                $hash = \Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer::getHash(array_map(function($data) {
                    return $data['value'];
                }, $allData));
            } else {
                $hash = \Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer::getHash($keyValues);
            }

            $priority = \mb_substr(implode(' ', $priority), 0, 255);

            $rawItem = RawItem::getInstance();
            try {
                $rawItem->update([
                    'hash' => $hash,
                    'priority' => $priority
                ], ['id' => $rawDataId]);
            } catch(\Throwable $e) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }

            $isMultivalue = [];
            if (is_array($sourceConfig['fields'])) {
                $isMultivalue = array_filter(
                    $sourceConfig['fields'],
                    static function ($values) {
                        return isset($values['multiValues']) && $values['multiValues'] === true;
                    }
                );
            }

            $rawItem = $rawItem->get($rawDataId);
            $dataportResource = DataportResource::getInstance()->get($rawItem['dataport_resource_id']);

            $response = ['fields' => [$this->getRawItemPreview($rawItem, $dataportResource, $isMultivalue)]];
        }

        Dataport::clearResultCache($dataportId);

        return new JsonResponse($response);
    }

    /**
     * @Route("/create-raw-data/{dataportId}", methods={"POST"})
     */
    public function createRawDataAction(Request $request, $dataportId) {
        if (!Dataport::canDataportBeExecutedBy($dataportId, Tool\Admin::getCurrentUser())) {
            $response = array(
                'success' => false,
                'message' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

        $dataportResources = DataportResource::getInstance();
        $dataportResource = $dataportResources->create([
            'dataportId' => $dataportId,
            'resource' => json_encode([
                'locale' => Tool\Admin::getCurrentUser()->getLanguage()
            ])
        ]);

        $rawItems = RawItem::getInstance();
        $rawItemId = UuidGenerator::generate()->getBytes();
        $rawItem = [
            'id' => $rawItemId,
            'dataport_resource_id' => $dataportResource['id'],
            'hash' => md5(''),
            'updated' => new \DateTime()
        ];
        $rawItem['id'] = $rawItems->create($rawItem);

        $rawItemField = RawItemField::getInstance();
        $rawDataFields = $rawItemField->find([
            'dataportId = ?' => $dataportId
        ]);
        $insertData = [];
        foreach($rawDataFields as $rawDataField) {
            $insertData[] = [
                'rawItemId' => $rawItemId,
                'fieldNo' => $rawDataField['fieldNo'],
                'value' => ''
            ];
        }

        $rawItemData = RawItemData::getInstance();
        $rawItemData->create($insertData);

        Dataport::clearResultCache($dataportId);

        $isMultivalue = [];
        $dataport = Dataport::getInstance()->get($dataportId);
        $sourceConfig = $dataport['sourceconfig'];
        if (is_array($sourceConfig['fields'])) {
            $isMultivalue = array_filter(
                $sourceConfig['fields'],
                static function ($values) {
                    return isset($values['multiValues']) && $values['multiValues'] === true;
                }
            );
        }

        $response = $this->getRawItemPreview($rawItem, $dataportResource, $isMultivalue);

        return new JsonResponse(['fields' => $response]);
    }

    /**
     * @Route("/get-xpath-suggestions", methods={"GET"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getXPathSuggestionsAction(Request $request) {
        $parameters = $request->query->all();

        if(!empty($parameters['dataport'])) {
            $parameters['dataportId'] = $parameters['dataport'];
        }

        $parser = new NaiveParser($request->get('file'), $parameters);
        $itemXPaths = $parser->findXmlNodes($request->get('value'));

        return new JsonResponse(array(
            'success' => true,
            'items' => $itemXPaths
        ));
    }

    /**
     * @Route("/download/{dataportId}", methods={"GET"})
     * @param Request $request
     *
     * @return BinaryFileResponse
     */
    public function downloadAction($dataportId) {
        $dataportJsonDefinitionFile = Installer::getConfigPath().'/dataport_'.$dataportId.'.json';
        if(!file_exists($dataportJsonDefinitionFile)) {
            $dataports = Dataport::getInstance();
            $dataportData = $dataports->exportDataport($dataportId);
            if(!@file_put_contents($dataportJsonDefinitionFile, json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))) {
                $dataportJsonDefinitionFile = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/dataport_'.$dataportId.'.json';
                @file_put_contents($dataportJsonDefinitionFile, json_encode($dataportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }

        $response = new BinaryFileResponse($dataportJsonDefinitionFile);

        $dataports = Dataport::getInstance();
        $dataport = $dataports->get($dataportId);

        $response->headers->set('Content-Type', 'application/json');

        // Set content disposition inline of the file
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT, File::getValidFilename(Helper::toASCII($dataport['name'], Tool::getDefaultLanguage()).'.json')
        );

        return $response;
    }

    /**
     * @Route("/import", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function importAction(Request $request) {
        if (!Tool\Admin::getCurrentUser()->isAllowed('plugin_sylphen_data_bridge')) {
            $response = array(
                'success' => false,
                'msg' => $this->translator->trans('pim.permission_missing_create_dataport', [], 'admin')
            );
            return new JsonResponse($response);
        }

        /** @var UploadedFile $importFile */
        $importFile = $request->files->get('importfile');

        if(\file_exists($importFile->getRealPath())) {
            $data = json_decode(file_get_contents($importFile->getRealPath()), true);

            if(!$data) {
                return new JsonResponse([
                    'success' => false,
                    'msg' => json_last_error()
                ]);
            }

            $data = Installer::normalizeExportData($data);

            $dataportId = $request->get('dataportId');
            if(!$dataportId) {
                return new JsonResponse([
                    'success' => false,
                    'msg' => 'No dataport id given'
                ]);
            }

            $dataports = Dataport::getInstance();
            $dataport = $dataports->get($dataportId);
            if(!$dataport) {
                return new JsonResponse([
                    'success' => false,
                    'msg' => 'Could not find dataport with id "'.$dataportId.'"'
                ]);
            }

            $data[Installer::TABLE_DATAPORT]['id'] = $dataportId;
            $data[Installer::TABLE_DATAPORT]['name'] = $dataport['name'];

            $this->importDataport($data);

            return new JsonResponse([
                'success' => true
            ]);
        }

        return new JsonResponse([
            'success' => false,
            'msg' => 'Could not read import file'
        ]);
    }

    /**
     * @Route("/can-be-executed", methods={"GET"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function canBeImportedAction(Request $request) {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $elementType = $request->get('type');
        $id = $request->get('id');

        $showAll = false;
        if($elementType === null && $id === null) {
            $showAll = true;
        }

        $object = null;
        if(!$showAll) {
            $object = \OpenDxp\Model\Element\Service::getElementById($elementType, $id);
            if (!$object instanceof ElementInterface) {
                return new JsonResponse(['success' => false, 'errorMessage' => 'Object not found']);
            }
        }

        $user = Tool\Admin::getCurrentUser();

        $executableDataports = [
            'imports' => [],
            'exports' => []
        ];
        $dataports = Dataport::getInstance();

        $dataportCandidates = $request->get('dataportIds');
        if($dataportCandidates) {
            $dataportCandidates = $dataports->find(['id IN (?)' => $dataportCandidates], 'name');
        } elseif($request->query->has('dataportIds')) {
            $dataportCandidates = [];
        } else {
            $dataportCandidates = $dataports->find([], 'name');
        }

        foreach($dataportCandidates as $dataport) {
            if (!Dataport::canDataportBeExecutedBy($dataport['id'], $user)) {
                continue;
            }

            $targetConfig = $dataport['targetconfig'];

            if(!$request->get('only-exports') && !empty($targetConfig['itemClass']) && (!$object || $targetConfig['itemFolder'] === $object->getFullPath())) {
                if($object && in_array($dataport['sourcetype'], ['object-wizard', 'pimcore'])) {
                    try {
                        $parser = $dataports->getParser($dataport['id']);
                        $source = $parser->getFileConditionFromObject($object, true, false);
                    } catch (\Throwable $e) {
                        $source = null;
                    }

                    if ($source === null) {
                        continue;
                    }
                }

                if(!$showAll) {
                    try {
                        $itemMold = $this->helper->getItemMoldByClassId($targetConfig['itemClass']);
                        if (\OpenDxp\Model\Element\Service::getElementType($itemMold) !== \OpenDxp\Model\Element\Service::getElementType($object)) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }

                $icon = null;
                $classDefinition = ClassDefinition::getById($targetConfig['itemClass']);
                if ($classDefinition instanceof ClassDefinition) {
                    $icon = $classDefinition->getIcon();
                }

                $executableDataports['imports'][] = [
                    'id' => $dataport['id'],
                    'name' => $this->translator->trans($dataport['name'], [], 'admin'),
                    'description' => $dataport['description'],
                    'sourcetype' => $dataport['sourcetype'],
                    'icon' => $icon ?: '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg',
                    'url' => \OpenDxp::getContainer()->get('router')->generate(
                        'dataport_import', ['dataportId' => urlencode($dataport['name']), 'force' => 1, 'async' => 1]
                    ),
                    'hasResult' => Dataport::hasResultCallbackWithOutput($dataport['id'])
                ];

                continue;
            }

            if (empty($request->get('classId')) && !empty($request->get('className'))) {
                $classDefinition = ClassDefinition::getByName($request->get('className'));
                if($classDefinition instanceof ClassDefinition) {
                    $request->attributes->set('classId', $classDefinition->getId());
                }
            }

            if(!empty($request->get('classId'))) {
                if ($targetConfig['itemClass'] && $targetConfig['itemClass'] !== $request->get('classId')) {
                    continue;
                }

                $sourceConfig = $dataport['sourceconfig'];
                if (empty($targetConfig['itemClass']) && ($sourceConfig['sourceClass'] ?? '') !== $request->get('classId') && $dataport['sourcetype'] !== 'object-wizard') {
                    continue;
                }
            }

            try {
                $parser = $dataports->getParser($dataport['id']);

                if(\method_exists($parser, 'setSourceFile') && \method_exists($parser, 'getFileConditionFromObject') && (!$request->get('only-exports') || empty($targetConfig['itemClass']))) {
                    if(!$object) {
                        $source = true;
                    } else {
                        try {
                            $source = $parser->getFileConditionFromObject($object, true, false);
                        } catch (\Throwable $e) {
                            $source = null;
                        }
                    }

                    if ($source !== null) {
                        $icon = null;
                        $classDefinition = null;
                        if ($targetConfig['itemClass']) {
                            $classDefinition = ClassDefinition::getById($targetConfig['itemClass']);
                            if ($classDefinition instanceof ClassDefinition) {
                                $icon = $classDefinition->getIcon();
                            }
                        } else {
                            $sourceConfig = $dataport['sourceconfig'];
                            if (!empty($sourceConfig['sourceClass'])) {
                                $classDefinition = ClassDefinition::getById($sourceConfig['sourceClass']);
                                if ($classDefinition instanceof ClassDefinition) {
                                    $icon = $classDefinition->getIcon();
                                }
                            }
                        }

                        $parameters = [
                            'dataportId' => urlencode($dataport['name']),
                            'query' => $source,
                            'force' => 1,
                            'async' => 1
                        ];

                        $dataParameters = [];
                        if($parser instanceof ObjectWizardParser) {
                            if(is_array($source)) {
                                $dataParameters = $source;
                                unset($parameters['query']);
                            }
                        } elseif(!empty($dataport['sourceconfig']['file'])) {
                            if($classDefinition === null) {
                                if ($targetConfig['itemClass']) {
                                    $classDefinition = ClassDefinition::getById($targetConfig['itemClass']);
                                } elseif (!empty($dataport['sourceconfig']['sourceClass'])) {
                                    $classDefinition = ClassDefinition::getById($dataport['sourceconfig']['sourceClass']);
                                }
                            }

                            if($classDefinition instanceof ClassDefinition) {
                                $variables = Importer::getTwigVariables($dataport['sourceconfig']['file']);
                                foreach ($variables as $variable) {
                                    $getter = 'get'.ucfirst($variable);
                                    if($object && method_exists($object, $getter)) {
                                        $dataParameters[$variable] = $object->$getter();
                                    }
                                }
                            }
                        }

                        $executableDataports[empty($targetConfig['itemClass'])?'exports':'imports'][] = [
                            'id' => $dataport['id'],
                            'name' => $this->translator->trans($dataport['name'], [], 'admin'),
                            'sourcetype' => $dataport['sourcetype'],
                            'description' => $dataport['description'],
                            'icon' => $icon ?: '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg',
                            'url'  => \OpenDxp::getContainer()->get('router')->generate(empty($targetConfig['itemClass'])?'dataport_export':'dataport_import', array_merge($parameters, $dataParameters)),
                            'parameters' => $dataParameters,
                            'hasResult' => Dataport::hasResultCallbackWithOutput($dataport['id'])
                        ];
                        continue;
                    }
                }
            } catch(\Throwable $e) {
            }
        }

        return new JsonResponse(['success' => true, 'dataports' => $executableDataports]);
    }

    /**
     * @Route("/get-parameters")
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getParametersAction(Request $request)
    {
        $elementType = $request->get('type');
        $id = $request->get('id');

        $element = \OpenDxp\Model\Element\Service::getElementById($elementType, $id);
        if (!$element instanceof ElementInterface) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Element not found']);
        }

        $parser = Dataport::getInstance()->getParser($request->get('dataportId'));
        return new JsonResponse(['success' => true, 'parameters' => $parser->getFileConditionFromObject($element)]);
    }

    /**
     * @Route("/auto-create-rawdata-fields")
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function autoCreateRawdataFieldsAction(Request $request) {
        try {
            $file = $request->get('file');
            $params = array_merge($request->query->all(), $request->request->all());

            /** @var UploadedFile */
            $uploadedFile = $request->files->get('file');
            if($uploadedFile) {
                if (!$uploadedFile->isValid()) {
                    throw new \InvalidArgumentException(sprintf($this->translator->trans('pim.manual.importForm.file_too_large', [], 'admin'), formatBytes(UploadedFile::getMaxFilesize())));
                }

                $file = $uploadedFile->getPathname();
                $params = $request->request->all();
            }

            $parser = new NaiveParser($file, $params);
            return new JsonResponse(['success' => true, 'config' => $parser->guessConfig()]);
        } catch(\Throwable $e) {
            return new JsonResponse(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * @Route("/rest-auth", methods={"GET"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function restAuthAction(Request $request)
    {
        $users = new Listing();
        $users->setCondition('type = ?', ['user']);
        $users->setOrderKey('name');
        $users->load();

        $dataports = Dataport::getInstance();
        $dataports = $dataports->find([], 'name');

        $apiKeyListing = ApiKeys::getInstance();

        $adminUser = Tool\Admin::getCurrentUser();

        $permissions = [];
        foreach($users->getUsers() as $user) {
            if(!$user instanceof User) {
                continue;
            }

            if(!$adminUser->isAllowed('users') && $adminUser->getId() !== $user->getId()) {
                continue;
            }

            $allowedDataports = [];

            foreach ($dataports as $dataport) {
                if (!Dataport::canDataportBeConfiguredBy($dataport['id'], $adminUser)) {
                    continue;
                }

                $targetConfig = $dataport['targetconfig'];
                if (Dataport::canDataportBeConfiguredBy($dataport['id'], $user) || Dataport::canDataportBeExecutedBy($dataport['id'], $user)) {
                    $itemMold = null;
                    try {
                        if (empty($targetConfig['itemClass'])) {
                            $sourceConfig = $dataport['sourceconfig'];
                            $itemMold = $this->helper->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
                        } else {
                            $itemMold = $this->helper->getItemMoldByClassId($targetConfig['itemClass']);
                        }
                    } catch (Throwable $e) {
                        continue;
                    }

                    $targetTypePermission = 'objects';
                    if ($itemMold !== null) {
                        $targetTypePermission = \OpenDxp\Model\Element\Service::getElementType($itemMold).'s';
                    }

                    $targetTypeAllowed = $user->isAllowed($targetTypePermission);

                    $targetClassAllowed = $itemMold === null ||
                        ($itemMold instanceof Concrete && (empty($itemMold->getClassId()) || $user->isAllowed(
                                    $itemMold->getClassId(),
                                    'class'
                                ))) ||
                        ($itemMold instanceof PageSnippet && $user->isAllowed(
                                $itemMold->getType(),
                                'docType'
                            )) ||
                        ($itemMold instanceof Asset && $targetTypeAllowed);

                    $necessaryClass = null;
                    if (!$targetClassAllowed) {
                        if ($itemMold instanceof Concrete) {
                            $necessaryClass = $itemMold->getClassName();
                        } elseif ($itemMold instanceof PageSnippet) {
                            $necessaryClass = $itemMold->getType();
                        }
                    }

                    $allowedDataports[] = [
                        'name' => $dataport['name'],
                        'allowRun' => Dataport::canDataportBeExecutedBy($dataport['id'], $user),
                        'targetTypeMissingPermission' => $targetTypeAllowed ? null : $targetTypePermission,
                        'targetClassMissingPermission' => $targetClassAllowed ? null : $necessaryClass,
                    ];
                }
            }

            $apiKeys = $apiKeyListing->find(['users_id = ?' => $user->getId()], 'valid_to DESC');
            if(empty($apiKeys)) {
                $apiKeys = [['id' => 'no_key_'.$user->getId(), 'api_key' => null, 'valid_to' => null]];
            }

            foreach($apiKeys as $apiKey) {
                $permissions[] = [
                    'permission_id' => $apiKey['id'],
                    'users_id' => $user->getId(),
                    'username' => $user->getName(),
                    'api_key' => $apiKey['api_key'],
                    'valid_to' => $apiKey['valid_to'],
                    'dataports' => $allowedDataports,
                    'active' => $user->isActive()
                ];
            }
        }

        return new JsonResponse(['permissions' => $permissions]);
    }

    /**
     * @Route("/rest-auth/update", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function restAuthUpdateAction(Request $request)
    {
        $requestData = json_decode($request->getContent(), true);
        $apiKeyListing = ApiKeys::getInstance();

        $validTo = $requestData['valid_to'] ? new DateTimeImmutable($requestData['valid_to']) : null;
        $permissionId = $requestData['permission_id'];

        if((int)$permissionId === 0) {
            $permission = $apiKeyListing->create(
                [
                    'users_id' => $requestData['users_id'],
                    'api_key' => $requestData['api_key'],
                    'valid_to' => $validTo
                ]
            );
            $permissionId = $permission['id'];
        } else {
            $apiKeyListing->update(
                [
                    'users_id' => $requestData['users_id'],
                    'api_key' => $requestData['api_key'],
                    'valid_to' => $validTo
                ],
                ['id' => $permissionId]
            );
        }

        return new JsonResponse(
            [
                'permissions' => [
                    'permission_id' => $permissionId,
                    'users_id' => $requestData['users_id'],
                    'api_key' => $requestData['api_key'],
                    'valid_to' => $validTo ? $validTo->format('Y-m-d H:i:s') : null
                ]
            ]
        );
    }

    private function getRawItemPreview($rawItem, array $dataportResource = [], array $isMultivalue = []) {
        $dataportResourceResource = json_decode($dataportResource['resource'], true);

        $itemData = [
            'id' => (string)Uuid::fromBytes($rawItem['id'])->getInteger(),
            'updated' => $rawItem['updated'],
            'file' => $dataportResourceResource['file'] ?? '-1',
            'locale' => $dataportResourceResource['locale'] ?? '-1'
        ];

        if($itemData['updated'] instanceof DateTime) {
            $itemData['updated'] = $itemData['updated']->format('Y-m-d H:i:s');
        } else {
            $itemData['updated'] = (new DateTimeImmutable($itemData['updated'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }

        foreach($dataportResourceResource['parameters'] ?? [] as $parameterName => $parameterValue) {
            if (is_array($parameterValue) || is_object($parameterValue)) {
                $parameterValue = json_encode($parameterValue, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            $itemData['field_'.$parameterName] = $parameterValue;
        }

        $rawItemDataRepository = RawItemData::getInstance();
        $rawItemData = $rawItemDataRepository->find(['rawItemId = ?' => $rawItem['id']]);
        foreach ($rawItemData as $row) {
            $value = $row['value'];
            if (array_key_exists('field_'.$row['fieldNo'], $isMultivalue)) {
                $unserialized = unserialize($value, ['allowed_classes' => false]);
                if (\is_array($unserialized)) {
                    $itemData['field_'.$row['fieldNo']] = json_encode($unserialized, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                } elseif (\is_object($unserialized)) {
                    $itemData['field_'.$row['fieldNo']] = json_encode(\get_object_vars($unserialized), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                } else {
                    $itemData['field_'.$row['fieldNo']] = $value;
                }
            } else {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                }
                $itemData['field_'.$row['fieldNo']] = $value;
            }
        }

        $existingFields = RawItemField::getInstance()->find(array('dataportId = ?' => $dataportResource['dataportId']), 'priority');
        foreach ($existingFields as $field) {
            if(!isset($itemData['field_'.$field['fieldNo']])) {
                $itemData['field_'.$field['fieldNo']] = '';
            }
        }

        return $itemData;
    }

    /**
     * @Route("/add-favorite", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addFavoriteAction(Request $request)
    {
        $user = Tool\Admin::getCurrentUser();

        $userId = $request->get('userId', $user->getId());

        if ($user instanceof User && ($userId == $user->getId() || $user->isAllowed('users'))) {
            $favorites = Favorites::getInstance();
            $favorites->create(['dataport_id' => $request->get('dataportId'), 'users_id' => $userId]);

            $role = User\Role::getById($userId);
            if($role instanceof User\Role) {
                $role->setPermission(Dataport::getExecutionPermissionName($request->get('dataportId')), true);
                $role->save();
            }
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/remove-favorite", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function removeFavoriteAction(Request $request)
    {
        $user = Tool\Admin::getCurrentUser();

        $userId = $request->get('userId', $user->getId());

        if ($user instanceof User && ($userId == $user->getId() || $user->isAllowed('users'))) {
            $favorites = Favorites::getInstance();
            $favorites->deleteWhere(['dataport_id' => $request->get('dataportId'), 'users_id' => $userId]);
        }

        return new JsonResponse(['success' => true]);
    }


    /**
     * @Route("/get-favorites", methods={"GET"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getFavoritesAction(Request $request)
    {
        $user = Tool\Admin::getCurrentUser();
        $dataports = [];
        if ($user instanceof User) {
            $favorites = Favorites::getInstance();

            $roles = $user->getRoles();
            if($user->isAdmin()) {
                $userListing = new User\Listing();
                $userListing->setCondition('admin=1');
                foreach ($userListing->load() as $adminUser) {
                    $roles[] = $adminUser->getId();
                }
            }

            $favorites = $favorites->find(['users_id IN (?)' => array_merge([$user->getId()], $roles)]);

            $dataportRepository = Dataport::getInstance();

            foreach($favorites as $favorite) {
                if (isset($dataports[$favorite['dataport_id']]) || !Dataport::canDataportBeExecutedBy($favorite['dataport_id'], $user)) {
                    continue;
                }

                $dataport = $dataportRepository->get($favorite['dataport_id']);

                $targetConfig = $dataport['targetconfig'];
                $icon = null;
                if (!Dataport::hasResultCallbackWithOutput($dataport['id'])) {
                    $type = 'import';
                    if($targetConfig['itemClass']) {
                        $classDefinition = ClassDefinition::getById($targetConfig['itemClass']);
                        if ($classDefinition instanceof ClassDefinition && $classDefinition->getIcon()) {
                            $icon = $classDefinition->getIcon();
                        }
                    }
                } else {
                    $type = 'export';
                    $sourceConfig = $dataport['sourceconfig'];
                    if (!empty($sourceConfig['sourceClass'])) {
                        $classDefinition = ClassDefinition::getById($sourceConfig['sourceClass']);
                        if ($classDefinition instanceof ClassDefinition && $classDefinition->getIcon()) {
                            $icon = $classDefinition->getIcon();
                        }
                    }
                }

                $dataports[$dataport['id']] = [
                    'id' => $dataport['id'],
                    'name' => $dataport['name'],
                    'icon' => $icon ?: '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg',
                    'type' => $type,
                    'sourceType' => $dataport['sourcetype']
                ];
            }
        }

        $dataports = array_values($dataports);

        usort($dataports, static function($dataport1, $dataport2) {
            if($dataport1['type'] === $dataport2['type']) {
                return strcasecmp($dataport1['name'], $dataport2['name']);
            }

            if ($dataport1['type'] === 'import') {
                return -1;
            }

            if ($dataport2['type'] === 'import') {
                return 1;
            }
        });

        return new JsonResponse(['success' => true, 'dataports' => $dataports]);
    }

    /**
     * @Route("/create-report", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function createReportAction(Request $request) {
        $dataport = (new Dataport())->get($request->get('dataportId'));
        if(!$dataport) {
            return new JsonResponse(['success' => false, 'message' => 'Dataport does not exist']);
        }

        $reportName = preg_replace('/[^a-z0-9\-~_]+/i', '-', Helper::toASCII($dataport['name'], Tool::getDefaultLanguage()));

        $report = OpenDxp\Bundle\CustomReportsBundle\Tool\Config::getByName($reportName);
        if ($report) {
            return new JsonResponse(['success' => false, 'message' => 'Report with name "'.$dataport['name'].'" already exists']);
        }

        $report = new OpenDxp\Bundle\CustomReportsBundle\Tool\Config();
        if (method_exists($report, 'isWriteable') && !$report->isWriteable()) {
            return new JsonResponse(['success' => false, 'message' => 'Cannot create report, please configure OPENDXP_WRITE_TARGET_CUSTOM_REPORTS in your .env file']);
        }

        $report->setName($reportName);
        $report->setGroup('Data Bridge');
        $report->setGroupIconClass('opendxp_icon_data_bridge');
        $report->setNiceName($dataport['name']);
        $report->setDataSourceConfig([['dataport' => $dataport['id'],'force' => true, 'type' => 'dataBridge']]);
        $report->setMenuShortcut(false);

        $columnConfigurations = [];
        $rawItemFields = new RawItemField();

        $targetConfig = $dataport['targetconfig'];
        $classDefinition = null;
        if ($targetConfig['itemClass']) {
            $classDefinition = ClassDefinition::getById($targetConfig['itemClass']);
        } else {
            $sourceConfig = $dataport['sourceconfig'];
            if (!empty($sourceConfig['sourceClass'])) {
                $classDefinition = ClassDefinition::getById($sourceConfig['sourceClass']);
            }
        }

        $fieldDefinitions = [];
        if($classDefinition instanceof ClassDefinition) {
            $fieldDefinitions = $classDefinition->getFieldDefinitions();
        }

        foreach($rawItemFields->find(['dataportId = ?' => $dataport['id']], 'priority') as $rawItemField) {
            $columnConfiguration = [
                'name' => $rawItemField['name'],
                'display' => true,
                'export' => true,
                'order' => true
            ];

            if (!empty($sourceConfig['fields']['field_'.$rawItemField['fieldNo']]['exportKey'])) {
                $columnConfiguration['filter_drilldown'] = 'filter_and_show';
            } elseif(!empty($sourceConfig['fields']['field_'.$rawItemField['fieldNo']]['parameters'])) {
                $dataQuerySelectorParts = str_getcsv($sourceConfig['fields']['field_'.$rawItemField['fieldNo']]['parameters'], ':');

                foreach($fieldDefinitions as $fieldDefinition) {
                    if(strtolower($fieldDefinition->getName()) === strtolower($dataQuerySelectorParts[0])) {
                        $columnConfiguration['filter_drilldown'] = 'filter_and_show';
                        break;
                    }
                }
            }

            $columnConfigurations[] = $columnConfiguration;
        }
        $report->setColumnConfiguration($columnConfigurations);
        $report->save();

        return new JsonResponse(['success' => true, 'name' => $report->getName(), 'niceName' => $report->getNiceName(), 'iconClass' => 'opendxp_icon_custom_report_default']);
    }

    /**
     * @Route("/get-versions/{id}")
     */
    public function getVersionsAction(Request $request, int $id)
    {
        if (!Dataport::canDataportBeConfiguredBy($id, Tool\Admin::getCurrentUser())) {
            $response = array(
                'errorMessage' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getConfigurationPermissionName($id), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

        $versions = Dataport::getInstance()->getVersions($id);

        array_shift($versions);

        $versions = array_map(static function($version) {
            unset($version['config']);
            return $version;
        }, $versions);

        return new JsonResponse(['versions' => $versions]);
    }

    /**
     * @Route("/compare-version/{id}_{timestamp}")
     */
    public function compareVersionAction(Request $request, $id, $timestamp) {
        $oldFile = Installer::getConfigVersionPath().'/dataport_'.$id.'_'.$timestamp.'.json';
        $newFile = Installer::getConfigPath().'/dataport_'.$id.'.json';

        $oldConfig = json_decode(file_get_contents($oldFile), true);
        if($oldConfig === null) {
            return new JsonResponse(['result' => 'Could not read version file '.$oldFile]);
        }
        if(is_string($oldConfig[Installer::TABLE_DATAPORT]['sourceconfig'])) {
            $oldConfig[Installer::TABLE_DATAPORT]['sourceconfig'] = unserialize($oldConfig[Installer::TABLE_DATAPORT]['sourceconfig'], ['allowed_classes' => false]);
        }

        if (is_string($oldConfig[Installer::TABLE_DATAPORT]['targetconfig'])) {
            $oldConfig[Installer::TABLE_DATAPORT]['targetconfig'] = unserialize($oldConfig[Installer::TABLE_DATAPORT]['targetconfig'], ['allowed_classes' => false]);
        }

        $newConfig = @file_get_contents($newFile);
        if(!$newConfig) {
            $newConfig = Dataport::getInstance()->exportDataport($id);
        } else {
            $newConfig = json_decode(file_get_contents($newFile), true);
        }
        if (is_string($newConfig[Installer::TABLE_DATAPORT]['sourceconfig'])) {
            $newConfig[Installer::TABLE_DATAPORT]['sourceconfig'] = unserialize($newConfig[Installer::TABLE_DATAPORT]['sourceconfig'], ['allowed_classes' => false]);
        }

        if (is_string($newConfig[Installer::TABLE_DATAPORT]['targetconfig'])) {
            $newConfig[Installer::TABLE_DATAPORT]['targetconfig'] = unserialize($newConfig[Installer::TABLE_DATAPORT]['targetconfig'], ['allowed_classes' => false]);
        }

        $oldConfig[Installer::TABLE_DATAPORT]['sourceconfig'] = array_merge(array_intersect_key(array_flip(array_keys($newConfig[Installer::TABLE_DATAPORT]['sourceconfig'] ?? [])), $oldConfig[Installer::TABLE_DATAPORT]['sourceconfig']), $oldConfig[Installer::TABLE_DATAPORT]['sourceconfig']);
        $oldConfig[Installer::TABLE_DATAPORT]['targetconfig'] = array_merge(array_intersect_key(array_flip(array_keys($newConfig[Installer::TABLE_DATAPORT]['targetconfig'] ?? [])), $oldConfig[Installer::TABLE_DATAPORT]['targetconfig']), $oldConfig[Installer::TABLE_DATAPORT]['targetconfig']);

        foreach ($oldConfig[Installer::TABLE_FIELDMAPPING] as &$fieldMapping) {
            if (isset($fieldMapping['format']) && is_string($fieldMapping['format'])) {
                $fieldMapping['format'] = unserialize($fieldMapping['format'], ['allowed_classes' => false]);
            }

            if (!empty($fieldMapping['calculation'])) {
                if (is_string($fieldMapping['calculation'])) {
                    $fieldMapping['calculation'] = explode("\n", $fieldMapping['calculation']);
                }
                $fieldMapping['calculation'] = implode("~dd_line_break~", $fieldMapping['calculation']);
            }
        }
        unset($fieldMapping);

        foreach ($newConfig[Installer::TABLE_FIELDMAPPING] as &$fieldMapping) {
            if (isset($fieldMapping['format']) && is_string($fieldMapping['format'])) {
                $fieldMapping['format'] = unserialize($fieldMapping['format'], ['allowed_classes' => false]);
            }

            if (!empty($fieldMapping['calculation'])) {
                if (is_string($fieldMapping['calculation'])) {
                    $fieldMapping['calculation'] = explode("\n", $fieldMapping['calculation']);
                }
                $fieldMapping['calculation'] = implode('~dd_line_break~', $fieldMapping['calculation']);
            }
        }
        unset($fieldMapping);

        $oldConfiguration = json_encode($oldConfig, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $oldConfiguration = str_replace('~dd_line_break~', "\n".str_repeat(' ', 28), $oldConfiguration);
        $newConfiguration = json_encode($newConfig, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $newConfiguration = str_replace('~dd_line_break~', "\n".str_repeat(' ', 28), $newConfiguration);

        $dateFormatter = Helper::getDateFormatter();
        $result = DiffHelper::calculate($oldConfiguration, $newConfiguration, 'SideBySide', [
            // show how many neighbor lines
            // Differ::CONTEXT_ALL can be used to show the whole file
            'context' => Differ::CONTEXT_ALL,
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => true,
        ], [
            // how detailed the rendered HTML in-line diff is? (none, line, word, char)
            'detailLevel' => 'word',
            // renderer language: eng, cht, chs, jpn, ...
            // or an array which has the same keys with a language file
            'language' => [
                'old_version' => $dateFormatter->format((new DateTimeImmutable('@0'))->setTimestamp($timestamp)->setTimezone(new DateTimeZone(date_default_timezone_get()))),
                'new_version' => $this->translator->trans('workflow_current_state', [], 'admin')
            ],
            // show line numbers in HTML renderers
            'lineNumbers' => false,
            // show a separator between different diff hunks in HTML renderers
            'separateBlock' => true,
            // show the (table) header
            'showHeader' => true,
            // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
            // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
            'spacesToNbsp' => false,
            // HTML renderer tab width (negative = do not convert into spaces)
            'tabSize' => 4,
            // this option is currently only for the Combined renderer.
            // it determines whether a replace-type block should be merged or not
            // depending on the content changed ratio, which values between 0 and 1.
            'mergeThreshold' => 0.8,
            // this option is currently only for the Unified and the Context renderers.
            // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
            // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
            // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
            'cliColorization' => RendererConstant::CLI_COLOR_ENABLE,
            // this option is currently only for the Json renderer.
            // internally, ops (tags) are all int type but this is not good for human reading.
            // set this to "true" to convert them into string form before outputting.
            'outputTagAsString' => true,
            // this option is currently only for the Json renderer.
            // it controls how the output JSON is formatted.
            // see available options on https://www.php.net/manual/en/function.json-encode.php
            'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            // this option is currently effective when the "detailLevel" is "word"
            // characters listed in this array can be used to make diff segments into a whole
            // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
            // this should bring better readability but set this to empty array if you do not want it
            'wordGlues' => [' ', '-'],
            // change this value to a string as the returned diff if the two input strings are identical
            'resultForIdenticals' => $this->translator->trans('pim.versions.identical', [], 'admin'),
            // extra HTML classes added to the DOM of the diff container
            'wrapperClasses' => ['diff-wrapper'],
        ]);

        return new JsonResponse(['result' => $result]);
    }

    /**
     * @Route("/restore-version/{id}_{timestamp}")
     */
    public function restoreVersionAction(Request $request, $id, $timestamp)
    {
        if(!file_exists(Installer::getConfigVersionPath().'/dataport_'.$id.'_'.$timestamp.'.json')) {
            return new JsonResponse(['result' => false, 'msg' => 'Dataport configuration file not found']);
        }
        $data = json_decode(file_get_contents(Installer::getConfigVersionPath().'/dataport_'.$id.'_'.$timestamp.'.json'), true);

        $this->importDataport($data);
        return new JsonResponse(['result' => true]);
    }

    /**
     * @Route("/get-config-path")
     */
    public function getConfigPathAction(Request $request) {
        return new JsonResponse(['result' => true, 'path' => str_replace(OPENDXP_PROJECT_ROOT.'/', '', Installer::getConfigPath())]);
    }

    /**
     * @Route("/get-grid-configs")
     */
    public function getGridConfigs(Request $request) {
        $configListing = new OpenDxp\Bundle\AdminBundle\Model\GridConfig\Listing();
        $configListing->addConditionParam('searchType = ?', ['folder']);
        $configListing->setOrderKey(['classId', 'name']);
        $configListing->setOrder('ASC');
        $gridConfigs = $configListing->load();

        $resultData = [];
        foreach($gridConfigs as $gridConfig) {
            $classDefinition = ClassDefinition::getById($gridConfig->getClassId());
            if (!$classDefinition instanceof ClassDefinition) {
                continue;
            }
            $icon = null;
            if ($classDefinition->getIcon()) {
                $icon = $classDefinition->getIcon();
            }
            $resultData[] = [
                'id' => $gridConfig->getId(),
                'name' => $gridConfig->getName(),
                'group' => $this->translator->trans($classDefinition->getName(), [], 'admin'),
                'icon' => $icon ?? '/bundles/opendxpadmin/img/flat-color-icons/object.svg'
            ];
        }

        return new JsonResponse(['data' => $resultData]);
    }

    /**
     * @Route("/get-version")
     */
    public function getVersion(Request $request)
    {
        if (class_exists(InstalledVersions::class)) {
            $version = InstalledVersions::getPrettyVersion('sylphen/opendxp-data-bridge');
            if (strpos($version, 'dev') !== false && class_exists(InstalledVersions::class)) {
                $version .= ' ('.InstalledVersions::getReference('sylphen/opendxp-data-bridge').')';
            }
            return new JsonResponse(['success' => true, 'version' => $version]);
        }
        return new JsonResponse(['success' => false]);
    }

    /**
     * @Route("/update-available")
     */
    public function updateAvailable(Request $request)
    {
        return new JsonResponse(['success' => false]);
    }

    /**
     * @Route("/get-search-by-field-fields")
     */
    public function getSearchByFieldFields()
    {
        $menuItems = [];
        $classDefinitionListing = new ClassDefinition\Listing();

        $addDirectOpenMenuItem = static function(Data $fieldDefinition, ClassDefinition $classDefinition) use (&$menuItems) {
            if ($fieldDefinition instanceof Data\QueryResourcePersistenceAwareInterface && !$fieldDefinition instanceof Data\Relations\AbstractRelations && !$fieldDefinition instanceof Data\ImageGallery && !$fieldDefinition instanceof Data\Checkbox && !$fieldDefinition instanceof Data\BooleanSelect && !$fieldDefinition instanceof Data\StructuredTable && !$fieldDefinition instanceof Data\Hotspotimage && ($fieldDefinition->getIndex(
                    ) || $fieldDefinition->getUnique())) {
                if (count(PimcoreDbRepository::getInstance()->findInSql('SELECT 1 FROM object_'.$classDefinition->getId().' LIMIT 101')) > 100) {
                    $menuItems[] = [
                        'classId' => $classDefinition->getId(),
                        'field' => $fieldDefinition->getName(),
                        'className' => $classDefinition->getName(),
                        'fieldName' => $fieldDefinition->getTitle() ?: $fieldDefinition->getName(),
                        'icon' => $classDefinition->getIcon() ?: '/bundles/opendxpadmin/img/flat-color-icons/object.svg'
                    ];
                }
            }
        };

        foreach($classDefinitionListing->load() as $classDefinition) {
            foreach($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                if($fieldDefinition instanceof Data\Localizedfields) {
                    foreach($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                        $addDirectOpenMenuItem($localizedFieldDefinition, $classDefinition);
                    }
                    continue;
                }

                $addDirectOpenMenuItem($fieldDefinition, $classDefinition);
            }
        }

        return new JsonResponse(['success' => true, 'searchFields' => $menuItems]);
    }

    /**
     * @Route("/open-objects-by-search-field")
     */
    public function openObjectsBySearchField(Request $request) {
        $itemMold = $this->helper->getItemMoldByClassId($request->get('classId'));
        $listing = $itemMold::getList([
            'unpublished' => true,
            'objectTypes' => [
                AbstractObject::OBJECT_TYPE_OBJECT,
                AbstractObject::OBJECT_TYPE_VARIANT,
            ],
            'locale' => Helper::getUser()->getLanguage(),
        ]);

        $values = array_filter(preg_split('/[,|;\n]\s*/', $request->get('value')));

        $listing->addConditionParam(Db::get()->quoteIdentifier($request->get('field')).' IN ('.rtrim(str_repeat('?,', count($values)), ',').')', $values);

        $elementIds = $listing->loadIdList();
        if(count($elementIds) === 0) {
            $listing = $itemMold::getList([
                'unpublished' => true,
                'objectTypes' => [
                    AbstractObject::OBJECT_TYPE_OBJECT,
                    AbstractObject::OBJECT_TYPE_VARIANT,
                ],
                'locale' => Tool::getDefaultLanguage(),
            ]);

            $listing->addConditionParam(Db::get()->quoteIdentifier($request->get('field')).' LIKE ?', [$request->get('value').'%']);

            $elementIds = $listing->loadIdList();
        }

        return new JsonResponse(['success' => true, 'ids' => $elementIds]);
    }

    /**
     * @Route("/get-templates")
     */
    public function getTemplates(Request $request)
    {
        $templates = [];

        $bundles = OpenDxp::getContainer()->getParameter('kernel.bundles');
        if (isset($bundles['InSquareOpendxpProcessManagerBundle'])) {
            $list = new Configuration\Listing();
            $list->setOrderKey('name');

            $jobs = [];
            foreach($list->load() as $item) {
                $jobs[] = ['itemId' => 'add_dataport_template_process-manager_'.$item->getId(), 'text' => $item->getName()];
            }

            $templates[] = [
                'text' => 'Process Manager',
                'icon' => '/bundles/opendxpadmin/img/flat-color-icons/cable_release.svg',
                'menu' => $jobs
            ];
        }

        return new JsonResponse(['success' => true, 'templates' => $templates]);
    }

    /**
     * @Route("/update-max-parallel-processes")
     */
    public function updateMaxParallelprocesses(Request $request)
    {
        $maxParallelProcessesSetting = OpenDxp\Model\WebsiteSetting::getByName('queue_processor.max_processing');
        if (!$maxParallelProcessesSetting instanceof OpenDxp\Model\WebsiteSetting) {
            $maxParallelProcessesSetting = new OpenDxp\Model\WebsiteSetting();
        }
        $maxParallelProcessesSetting->setName('queue_processor.max_processing');
        $maxParallelProcessesSetting->setType('text');
        $maxParallelProcessesSetting->setData($request->get('maxProcesses'));
        $maxParallelProcessesSetting->save();

        return new JsonResponse(['success' => true]);
    }
}
