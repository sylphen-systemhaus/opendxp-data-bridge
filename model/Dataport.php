<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;
use avadim\FastExcelReader\Excel;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ParameterBag;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\CachingParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ExcelParserFast;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\FilesystemParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\FixedLengthFileParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\GridParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\JsonParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ObjectWizardParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\PimcoreParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\Parser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ReportParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\StreamingXmlParser;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\XmlParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\CsvParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ExcelParser;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GlobIterator;
use IntlDateFormatter;
use JsonException;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Db;
use OpenDxp\Model\Element\Service;
use OpenDxp\Bundle\AdminBundle\Model\GridConfig;
use OpenDxp\Bundle\SeoBundle\Model\Redirect\Listing;
use OpenDxp\Model\Translation;
use OpenDxp\Model\Translation\Admin;
use OpenDxp\Model\User;
use OpenDxp\Model\User\Permission\Definition;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use OpenDxp\Translation\Translator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Lexer;
use Twig\Source;
use Twig\Token;

class Dataport extends PimcoreDbRepository {
    private static $versions = [];

    private static $cache = [];

    public function getTableName(): string
    {
        return Installer::TABLE_DATAPORT;
    }

    public function get($id, $force = false): array
    {
        if(!isset(self::$cache[$id]) || $force) {
            self::$cache[$id] = $this->getInternal($id);
        }

        return self::$cache[$id] ?? [];
    }

    private function getInternal($id) {
        $dataport = parent::get($id);

        if ($dataport) {
            return $this->enrich($dataport);
        }

        if (!is_numeric($id)) {
            $dataport = $this->findOne(['name=?' => $id]);
            if ($dataport) {
                return $this->enrich($dataport);
            }

            $dataport = $this->findOne(['name=?' => \urldecode($id)]);
            if ($dataport) {
                return $this->enrich($dataport);
            }

            // try to redirect to new name (in case dataport has been renamed), todo: perhaps we can use route conditions or custom route loader so Symfony can handle redirects
            $redirects = new Listing();
            $redirects->addConditionParam('(source LIKE ? OR source LIKE ? OR source LIKE ? OR source LIKE ?)', ['%/rest/import/'.\urlencode($id).'%', '%/rest/export/'.\urlencode($id).'%', '%/rest/import/'.$id.'%', '%/rest/export/'.$id.'%']);
            $redirects->addConditionParam('active = 1');
            $redirects->setOrderKey('priority');
            $redirects->setOrder('DESC');
            $redirects->setLimit(1);
            $redirects = $redirects->load();

            if ($redirects && preg_match('#/rest/(import|export)/(.+)#', $redirects[0]->getTarget(), $newDataportId)) {
                $newDataportId = $newDataportId[2];

                if ($newDataportId != $id) {
                    return $this->get($newDataportId);
                }
            }
        }
    }

    private function enrich(array $dataport) {
        try {
            $sourceConfig = json_decode($dataport['sourceconfig'], true, 512, (defined('JSON_THROW_ON_ERROR') ? JSON_THROW_ON_ERROR : 0));

            if(!defined('JSON_THROW_ON_ERROR') && json_last_error()) {
                throw new JsonException('Invalid JSON');
            }

            $dataport['sourceconfig'] = $sourceConfig;
        } catch(JsonException $e) {
        }

        try {
            $targetConfig = json_decode($dataport['targetconfig'], true, 512, (defined('JSON_THROW_ON_ERROR') ? JSON_THROW_ON_ERROR : 0));

            if (!defined('JSON_THROW_ON_ERROR') && json_last_error()) {
                throw new JsonException('Invalid JSON');
            }

            $dataport['targetconfig'] = $targetConfig;
        } catch (JsonException $e) {
        }

        if($dataport['sourcetype'] === 'grid') {
            try {
                $gridConfig = GridConfig::getById((int)$dataport['sourceconfig']['file']);
            } catch (\Throwable $e) {
                $gridConfig = null;
            }

            if($gridConfig instanceof GridConfig) {
                $fields = [];
                $updateNecessary = false;
                $fieldNo = 1;
                foreach(json_decode($gridConfig->getConfig(), true)['columns'] ?? [] as $identifier => $gridConfigField) {
                    $name = $gridConfigField['fieldConfig']['attributes']['label'] ?? $gridConfigField['name'];
                    if(!isset($dataport['sourceconfig']['fields']['field_'.$fieldNo]) || $dataport['sourceconfig']['fields']['field_'.$fieldNo]['identifier'] !== $identifier) {
                        $updateNecessary = true;
                    }

                    $fields['field_'.$fieldNo] = ['fieldNo' => $fieldNo, 'name' => $name, 'identifier' => $identifier];

                    $fieldNo++;
                }

                if ($updateNecessary) {
                    $dataport['sourceconfig']['fields'] = $fields;
                    $this->update(['sourceconfig' => json_encode($dataport['sourceconfig'])], ['id' => $dataport['id']]);
                    RawItemField::getInstance()->deleteWhere(['dataportId' => $dataport['id']]);
                    foreach ($dataport['sourceconfig']['fields'] as $field) {
                        RawItemField::getInstance()->createOrUpdate(['dataportId' => $dataport['id'], 'fieldNo' => $field['fieldNo'], 'name' => $field['name'], 'priority' => $field['fieldNo']]);
                    }
                }
            }
        }

        self::$cache[$dataport['id']] = $dataport;

        return self::$cache[$dataport['id']];
    }

    /**
     * @param OpenDxp\Model\DataObject\ClassDefinition\Layout|OpenDxp\Model\Asset\MetaData\ClassDefinition\Data\Data $definition
     * @return mixed|null
     */
    private function getClassDefinitionsFromCustomLayout($def)
    {
        $fields = [];
        if ($def instanceof OpenDxp\Model\DataObject\ClassDefinition\Layout && $def->hasChildren()) {
            foreach ($def->getChildren() as $child) {
                $fields = array_merge($fields, $this->getClassDefinitionsFromCustomLayout($child));
            }
        }

        if ($def instanceof OpenDxp\Model\DataObject\ClassDefinition\Data) {
            $fields[] = $def;
        }

        return $fields;
    }

    public function create(array $data)
    {
        $returnValue = parent::create($data);

        $returnValue = $this->createPermissions($returnValue);

        $returnValue = $this->enrich($returnValue);

        return $returnValue;
    }

    public function update($data, $where)
    {
        $this->createPermissions(array_merge($data, $where));

        return parent::update($data, $where);
    }

    public function delete($id)
    {
        Db::get()->delete(
            'users_permission_definitions',
            [
                '`key`' => 'Dataport ' . $id . ' Configuration',
            ]
        );
        Db::get()->delete(
            'users_permission_definitions',
            [
                '`key`' => 'Dataport ' . $id . ' Execution',
            ]
        );

        return parent::delete($id);
    }


    private function createPermission($dataportId) {
        // direct SQL update because of https://github.com/pimcore/pimcore/pull/7368
        $permissionTableHasCategoryColumn = (bool)$this->findOneInSql('SHOW COLUMNS FROM `users_permission_definitions` LIKE "category"');

        $permissonData = ['key' => self::getConfigurationPermissionName($dataportId)];
        if($permissionTableHasCategoryColumn) {
            $permissonData['category'] = 'Data Bridge';
        }
        $this->insertOrUpdate('users_permission_definitions', $permissonData);

        $permissonData = ['key' => self::getExecutionPermissionName($dataportId)];
        if ($permissionTableHasCategoryColumn) {
            $permissonData['category'] = 'Data Bridge';
        }
        $this->insertOrUpdate('users_permission_definitions', $permissonData);
    }

    private function getParserInternal($id) {
        chdir(OPENDXP_PROJECT_ROOT);

        $dataport = $this->get($id);
        if (!empty($dataport['sourceconfig'])) {
            $sourceconfig = $dataport['sourceconfig'];
            $sourceconfig['dataportId'] = $id;

            $parameters = [Helper::getEnvironmentVariables()];
            $request = Helper::getRequest();
            if ($request instanceof Request) {
                $parameters[] = $request->request->all();
                $parameters[] = $request->query->all();
                $parameters[] = $request->attributes->all();
                $parameters[] = ['domain' => $request->getHost(), 'hostname' => $request->getHost()];
            }

            $parameters = array_replace(...$parameters);
            $sourceconfig['parameters'] = new ParameterBag($parameters);

            switch ($dataport['sourcetype']) {
                case 'xml':
                    $hasRawDataFieldWithMultiLevelParentXpath = false;
                    foreach ($dataport['sourceconfig']['fields'] as $field) {
                        if ( strpos($field['xpath'], '../../') !== false ) {
                            $hasRawDataFieldWithMultiLevelParentXpath = true;
                            break;
                        }
                    }
                    if (!$hasRawDataFieldWithMultiLevelParentXpath && preg_match('/^[\pL\pN\/_-]+$/u', $sourceconfig['itemxpath'])) {
                        return new StreamingXmlParser($sourceconfig, new NullLogger());
                    }
                    return new XmlParser($sourceconfig, new NullLogger());
                case 'csv':
                    return new CsvParser($sourceconfig, new NullLogger());
                case 'excel':
                    if (!method_exists(Excel::class, 'getSheet')) {
                        return new ExcelParser($sourceconfig, new NullLogger());
                    }

                    return new ExcelParserFast($sourceconfig, new NullLogger());
                case 'json':
                    return new JsonParser($sourceconfig, new NullLogger());
                case 'pimcore':
                    $targetConfig = $dataport['targetconfig'];

                    return new PimcoreParser($sourceconfig, new NullLogger(), \OpenDxp::getContainer()->get(ItemMoldBuilder::class), $targetConfig);
                case 'report':
                    return new ReportParser($sourceconfig, new NullLogger());
                case 'grid':
                    /** @var GridParser $parser */
                    $parser = OpenDxp::getContainer()->get(GridParser::class);
                    $parser->setConfig($sourceconfig);
                    $parser->setLogger(new NullLogger());
                    return $parser;
                case 'files':
                    return new FilesystemParser($sourceconfig);
                case 'fixed-length':
                    return new FixedLengthFileParser($sourceconfig, new NullLogger());
                case 'object-wizard':
                    return new ObjectWizardParser($sourceconfig, new NullLogger());
            }

            throw new \InvalidArgumentException('Could not get a parser for source type "'.$dataport['sourcetype'].'"');
        }

        throw new \InvalidArgumentException('Please provide source configuration for dataport #'.$id);
    }

    /**
     * Returns a matching parser for this dataport
     *
     * @param int $id
     *
     * @return Parser
     * @throws \InvalidArgumentException
     */
	public function getParser($id, ?LoggerInterface $logger = null) {
        $parser = $this->getParserInternal($id);
        $parser->setLogger($logger ?? \OpenDxp::getContainer()->get('pim.logger'));

        return $parser;
	}

    public function exportDataport($dataportId) {
        $data[Installer::TABLE_DATAPORT] = $this->get($dataportId, true);

        if (empty($data[Installer::TABLE_DATAPORT])) {
            $translator = \OpenDxp::getContainer()->get('translator');
            throw new \UnderflowException($translator->trans('pim.dataport.invalidid', [], 'admin'));
        }

        $fieldMappingModel = Fieldmapping::getInstance();
        $data[Installer::TABLE_FIELDMAPPING] = $fieldMappingModel->find(array(
            'dataportId = ?' => $dataportId,
        ));
        foreach($data[Installer::TABLE_FIELDMAPPING] as &$fieldMapping) {
            if(!empty($fieldMapping['format'])) {
                $fieldMapping['format'] = unserialize($fieldMapping['format'], ['allowed_classes' => false]);
            }
            $fieldMapping['calculation'] = preg_split("/\r?\n/", $fieldMapping['calculation']);
        }
        unset($fieldMapping);

        $rawitemFieldModel = RawItemField::getInstance();
        $data[Installer::TABLE_RAWITEMFIELD] = $rawitemFieldModel->find(array(
            'dataportId = ?' => $dataportId,
        ));

        $user = \OpenDxp\Tool\Admin::getCurrentUser();
        if (!$user instanceof User) {
            $user = User::getById(0);
        }

        if($user instanceof User) {
            $data['user'] = ['id' => $user->getId(), 'username' => $user->getUsername()];
        }

        return $data;
    }

    public static function clearRawdataCache($dataportId) {
        $cmd = '"'.Cli::getPhpCli().'" '.
            realpath(
                OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console'
            )
            .' data-bridge:delete-rawdata --dataport='.$dataportId;

        Cli::execInBackground($cmd);
    }

    public static function clearImportHashes($dataportId) {
	    $itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
        $itemMold = null;
        try {
            $itemMold = $itemMoldBuilder->getItemMold($dataportId);

            if ($itemMold instanceof Export) {
                $dataport = self::getInstance()->get($dataportId);
                $sourceConfig = $dataport['sourceconfig'];
                $itemMold = $itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
            }

            Db::get()->delete('properties', ['ctype' => Service::getElementType($itemMold), 'name' => 'importhash_'.$dataportId]);
            Db::get()->delete('properties', ['ctype' => Service::getElementType($itemMold), 'name' => 'importhash_'.$dataportId.'_data']);
        } catch (\Exception $e) {
            OpenDxp\Logger::warning($e->getMessage());
        }
    }

    public static function clearResultCache($dataportId) {
        $directoryIterator = new \DirectoryIterator(Installer::getResultDocumentPath());
        $filterIterator = new \CallbackFilterIterator($directoryIterator, static function(\SplFileInfo $fileInfo) use ($dataportId) {
            return strpos($fileInfo->getFilename(), 'result_'.$dataportId.'_') === 0 || strpos($fileInfo->getFilename(), 'response_'.$dataportId.'_') === 0;
        });
        /** @var \SplFileInfo $compiledFileInfo */
        foreach($filterIterator as $cachedResultFile) {
            unlink($cachedResultFile->getPathname());
        }
    }

    public static function hasResultCallbackWithOutput($dataportId)
    {
        $fieldMappingTable = Fieldmapping::getInstance();
        return (bool)$fieldMappingTable->findOneInSql(
            'SELECT 1 FROM '.Installer::TABLE_FIELDMAPPING.' WHERE dataportId = ? AND fieldName = ? AND calculation NOT LIKE ? AND (calculation REGEXP \'\\\\$params\\\\[[\\\'"]response[\\\'"]\\\\]->setContent\' OR calculation REGEXP \'\\\\$params\\\\[[\\\'"]response[\\\'"]\\\\]->addContent\')',
            [$dataportId, '__result_callback', '%X-Data-Bridge-Run%']);
    }

    public static function getConfigurationPermissionName($dataportId)
    {
        return 'Dataport ' . $dataportId . ' Configuration';
    }

    public static function getExecutionPermissionName($dataportId)
    {
        return 'Dataport ' . $dataportId . ' Execution';
    }

    public static function canDataportBeExecutedBy($dataportId, User $user)
    {
        return $user->isAllowed(self::getExecutionPermissionName($dataportId)) || $user->isAllowed('plugin_sylphen_data_bridge_admin_permission');
    }

    public static function canDataportBeConfiguredBy($dataportId, User $user)
    {
        return $user->isAllowed(self::getConfigurationPermissionName($dataportId)) || $user->isAllowed('plugin_sylphen_data_bridge_admin_permission');
    }

    public function isRunning($dataportId, $exceptStatusKey = null)
    {
        $query = 'SELECT 1 
            FROM '.Installer::TABLE_IMPORTSTATUS.'
            WHERE `status` = ?
            AND `dataport_id` = ?';

        $params = [ImportStatus::STATUS_RUNNING, $dataportId];

        if ($exceptStatusKey !== null) {
            $query .= ' AND `key` != ?';
            $params[] = $exceptStatusKey;
        }

        $query .= ' LIMIT 1';

        return (bool)$this->findOneInSql($query, $params);
    }

    public function countRunningJobs($dataportId, $exceptStatusKey = null)
    {
        $query = 'SELECT COUNT(*)
            FROM '.Installer::TABLE_IMPORTSTATUS.'
            WHERE `status` = ?
            AND `dataport_id` = ?';

        $params = [ImportStatus::STATUS_RUNNING, $dataportId];
        if ($exceptStatusKey !== null) {
            $query .= ' AND `key` != ?';
            $params[] = $exceptStatusKey;
        }

        return $this->findOneInSql($query, $params);
    }

    public function isQueued($dataportId)
    {
        return (bool)$this->findOneInSql('SELECT 1 FROM '.Installer::TABLE_QUEUE.' WHERE worker_id = ? AND started_at IS NULL LIMIT 1', [$dataportId]);
    }

    public function countQueuedJobs($dataportId)
    {
        return $this->findOneInSql('SELECT COUNT(*) FROM '.Installer::TABLE_QUEUE.' WHERE worker_id = ? AND started_at IS NULL', [$dataportId]);
    }

    public function getVersions($dataportId) {
        if(!isset(self::$versions[$dataportId])) {
            $dateFormatter = Helper::getDateFormatter();

            self::$versions[$dataportId] = [];
            foreach (new GlobIterator(Installer::getConfigVersionPath().'/dataport_'.$dataportId.'_*.json') as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                $data = json_decode(file_get_contents($fileInfo->getRealPath()), true);
                if (!empty($data['user'])) {
                    $user = User::getById($data['user']['id']);
                    if ($user instanceof User) {
                        $username = $user->getUsername();
                    } elseif (!empty($data['user']['username'])) {
                        $username = $data['user']['username'];
                    } else {
                        $username = 'unknown user';
                    }
                } else {
                    $username = 'unknown user';
                }

                if(!preg_match('/dataport_'.$dataportId.'_(\d+).json/', $fileInfo->getBasename(), $timestamp)) {
                    continue;
                }
                $timestamp = $timestamp[1];

                self::$versions[$dataportId][] = ['user' => $username, 'date' => $dateFormatter->format((new DateTimeImmutable('@0'))->setTimestamp($timestamp)->setTimezone(new DateTimeZone(date_default_timezone_get()))), 'timestamp' => $timestamp, 'config' => $data];
            }
            usort(self::$versions[$dataportId], static function ($version1, $version2) {
                return $version2['timestamp'] <=> $version1['timestamp'];
            });
        }

        return self::$versions[$dataportId];
    }

    public function find(array $where = [], ?string $order = null, ?int $count = null, int $offset = 0, ?string $groupBy = null, array $columns = ['*']): array
    {
        $dataports = parent::find($where, $order, $count, $offset, $groupBy, $columns);

        $dataports = array_map(function($dataport) {
            return $this->get($dataport['id']);
        }, $dataports);

        return $dataports;
    }

    public static function getUnmappedVirtualFields($dataportId) {
        $dataport = self::getInstance()->get($dataportId);

        $fieldMappings = Fieldmapping::getInstance()->find(
            [
                'dataportId = ?' => $dataportId,
                'fieldName NOT IN (?)' => ['__result_callback', '__result_action']
            ],
            'fieldName'
        );

        $mappedFields = array_column($fieldMappings, 'fieldName');
        if($dataport['sourcetype'] === 'pimcore') {
            $rawDataFields = RawItemField::getInstance()->find(['dataportId = ?' => $dataportId]);

            $mappedFields = array_merge($mappedFields, array_map(static function($rawDataField) {
                return $rawDataField['name'];
            }, $rawDataFields));
        }
        $mappedFields = array_merge($mappedFields, array_keys(Helper::getEnvironmentVariables()));

        $variables = [];

        if (!empty($dataport['sourceconfig']['file'])) {
            foreach(Importer::getTwigVariables($dataport['sourceconfig']['file']) as $variable) {
                if (!in_array('__virtual_'.$variable, $mappedFields, true) && !in_array($variable, $mappedFields, true)) {
                    $variables[] = $variable;
                }
            }
        }

        foreach ($fieldMappings as $mapping) {
            if ($mapping['calculation']) {
                foreach(Importer::getTwigVariables($mapping['calculation']) as $variable) {
                    if(!in_array('__virtual_'.$variable, $mappedFields, true) && !in_array($variable, $mappedFields, true)) {
                        $variables[] = $variable;
                    }
                }
            }
        }

        $variables = array_unique($variables);
        $variables = array_filter($variables, static function($variable) {
            return preg_match('/^[A-Za-z0-9_-]+$/', $variable) && strpos($variable, '_hashtag_') ===false && strpos($variable, '_semicolon_') === false && strpos($variable, '_current_value_') === false;
        });

        return array_values(array_unique($variables));
    }

    /**
     * @param $returnValue
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public static function createPermissions(array $data)
    {
        if (isset($data['id'])) {
            self::getInstance()->createPermission($data['id']);

            if (isset($data['name'])) {
                /** @var Translator $translator */
                $translator = \OpenDxp::getContainer()->get('translator');

                $translation = Translation::getByKey(Dataport::getConfigurationPermissionName($data['id']), Translation::DOMAIN_ADMIN, true);
                foreach (Translation::getValidLanguages(Translation::DOMAIN_ADMIN) as $language) {
                    $translation->addTranslation($language, 'Dataport #'.$data['id'].' '.$data['name'].' ('.$translator->trans('configuration', [], 'admin', $language).')');
                }
                $translation->save();

                $translation = Translation::getByKey(Dataport::getExecutionPermissionName($data['id']), Translation::DOMAIN_ADMIN, true);
                foreach (Translation::getValidLanguages(Translation::DOMAIN_ADMIN) as $language) {
                    $translation->addTranslation($language, 'Dataport #'.$data['id'].' '.$data['name'].' ('.$translator->trans('pim.permission_execution', [], 'admin', $language).')');
                }
                $translation->save();
            }
        }
        return $data;
    }
}