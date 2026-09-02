<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\lib\Pim\BackingUpResponse;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importmanager;
use Sylphen\DataBridgeBundle\lib\Pim\RawData\UuidGenerator;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\RawItem;
use DOMNode;
use DOMText;
use Monolog\ErrorHandler;
use OpenDxp;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Db;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Ramsey\Uuid\Uuid;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Symfony\Component\HttpFoundation\Response;

class CreateTestCommand extends AbstractCommand
{
    /** @var Helper */
    private $helper;

    /** @var ImporterInterface */
    private $importer;

    public function __construct(Helper $helper, ImporterInterface $importer, LoggerInterface $defaultLogger)
    {
        parent::__construct();

        $this->helper = $helper;
        $this->importer = $importer;

        if(method_exists($this->importer, 'setLogger')) {
            $this->importer->setLogger($defaultLogger);

            $handler = new ErrorHandler($defaultLogger);
            $handler->registerErrorHandler([], false);
            $handler->registerExceptionHandler([], false);
            $handler->registerFatalHandler();
        }
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:create-test';
    }

    protected function configure()
    {
        $this
            ->setDescription('Create a PHPUnit test class for dataport')
            ->addArgument('dataport', InputArgument::OPTIONAL, 'Comma-separated list of Dataport IDs or namse for which a PHPUnit test shall get created')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataportIds = array_filter(str_getcsv($input->getArgument('dataport'), ','));
        if(!$dataportIds) {
            $dataportIds = array_map(static function ($dataport) {
                return $dataport['id'];
            }, Dataport::getInstance()->find());
        }

        foreach($dataportIds as $dataportId) {
            $dataport = Dataport::getInstance()->get($dataportId);

            if(empty($dataport)) {
                $output->writeln('Could not find dataport '.$dataportId);
                continue;
            }

            $this->importer->setDataport($dataport);
            $mappings = $this->importer->getMappings(true);
            $resultCallbackMapping = Fieldmapping::getInstance()->findOne(
                [
                    'dataportId = ?' => $dataportId,
                    'fieldName = ?' => '__result_callback'
                ]
            );
            if($resultCallbackMapping) {
                $mappings[] = $resultCallbackMapping;
            }
            $dataProviderArray = [];

            $rawItemIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                'SELECT rawitem.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ? GROUP BY priority ORDER BY RAND() LIMIT 100',
                [$dataport['id']]
            );

            if(count($rawItemIds) === 0) {
                Cli::exec('"'.Cli::getPhpCli().'" '.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').' data-bridge:extract '.$dataport['id']);
                $rawItemIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                    'SELECT rawitem.id FROM '.Installer::TABLE_RAWITEM.' rawitem INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON rawitem.dataport_resource_id=dataport_resource.id WHERE dataportId = ? GROUP BY priority ORDER BY RAND() LIMIT 100',
                    [$dataport['id']]
                );
            }


            foreach ($rawItemIds as $rawItemId) {
                Helper::getRequest()->cookies->set('rawItem-'.$dataport['id'], Uuid::fromBytes($rawItemId)->getInteger()->toString());
                $this->helper->clearPreviewItem();

                $fieldMappings = $this->helper->createFieldMappings($dataport);
                foreach ($fieldMappings as $fieldMapping) {
                    if (!isset($mappings[$fieldMapping['attributeKey']]) || in_array($fieldMapping['attributeKey'], ['__result_callback', '__result_action', '__init_action'], true)) {
                        continue;
                    }

                    $dataProviderArray[$rawItemId]['expected'][$fieldMapping['attributeKey']] = $fieldMapping['example_parsed']['result'];
                }

                $rawItemData = [];
                $rawItemDataItems = RawItemData::getInstance()->find([
                    'rawItemId = ?' => $rawItemId,
                ]);
                foreach ($rawItemDataItems as $rawItemDataItem) {
                    $rawItemData['field_'.$rawItemDataItem['fieldNo']] = $rawItemDataItem['value'];
                }

                $dataProviderArray[$rawItemId]['input'] = $rawItemData;
            }


            $testCode = '<?php
    namespace DataportTest;
    
    use App\Kernel;
    use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
    use Sylphen\DataBridgeBundle\lib\Pim\Item\Importmanager;
    use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
    use Sylphen\DataBridgeBundle\lib\Pim\Logger\InMemoryLogger;
    use Sylphen\DataBridgeBundle\model\Dataport;
    use Sylphen\DataBridgeBundle\model\DataportResource;
    use Sylphen\DataBridgeBundle\lib\Pim\Helper;
    use Sylphen\DataBridgeBundle\model\RawItem;
    use OpenDxp\Test\KernelTestCase;
    use Sylphen\DataBridgeBundle\lib\Pim\RawData\Importer as RawItemImporter;
    use Ramsey\Uuid\Uuid;
    use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
    use Symfony\Component\HttpFoundation\Response;
    use Sylphen\DataBridgeBundle\model\Fieldmapping;
    use Sylphen\DataBridgeBundle\lib\Pim\BackingUpResponse;
    
    class ' .$this->getValidClassName($dataport["name"]) . 'Test extends KernelTestCase
    {
        private $dataport;
        private $dataportResourceId;
        private $rawItemImporter;
        private $helper;
        private $itemMold;
        private $logger;
    
        protected function setUp(): void
        {
            parent::setUp();
            
            \OpenDxp\Bootstrap::setProjectRoot();
            \OpenDxp\Bootstrap::bootstrap();
            $kernel = self::bootKernel();
            
            $this->itemMold = $kernel->getContainer()->get(ItemMoldBuilder::class)->getItemMold('.$dataportId.');
            $this->dataport = Dataport::getInstance()->get('.$dataport["id"].');
            $this->dataportResourceId = DataportResource::getInstance()->create(
                [
                    "dataportId" => '.$dataport["id"].',
                    "resource" => \json_encode([], JSON_UNESCAPED_SLASHES)
                ]
            )["id"];
            
            $this->logger = new InMemoryLogger();
            $this->rawItemImporter = new RawItemImporter($this->dataportResourceId, $this->logger);
            $this->helper = Helper::getInstance();
            $this->helper->setLogger($this->logger);
            
            RawItem::getInstance()->deleteWhere(["dataport_resource_id" => $this->dataportResourceId]);
        }
    
        protected function tearDown(): void
        {
            parent::tearDown();
            
            $this->helper = null;
            $this->dataport = null;
            $this->itemMold = null;
            $this->rawItemImporter = null;
            $this->logger = null;
            
            RawItem::getInstance()->deleteWhere(["dataport_resource_id" => $this->dataportResourceId]);
        }
    
        protected static function getKernelClass(): string
        {
            return Kernel::class;
        }
        ';

            foreach ($mappings as $fieldMapping) {
                if(in_array($fieldMapping['fieldName'], ['__result_action', '__init_action'], true)) {
                    continue;
                }

                if($fieldMapping['fieldName'] === '__result_callback' && empty($dataport['targetconfig']['itemClass'])) {
                    $resultCallbackVariables = [];
                    preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $fieldMapping['calculation'], $resultCallbackVariables);
                    if ($resultCallbackVariables) {
                        $resultCallbackVariables = array_unique($resultCallbackVariables[1]);
                    }

                    $importManager = new Importmanager(OpenDxp::getContainer()->get(ImporterInterface::class), new NullLogger(), true);
                    $done = 0;
                    $offset = 0;
                    $changedObjectIds = [];
                    $virtualFields = [];
                    $response = new BackingUpResponse($dataportId);
                    $resultCallbackVariables = [];
                    if ($fieldMapping['calculation']) {
                        preg_match_all('/[\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\'"]?/', $fieldMapping['calculation'], $resultCallbackVariables);
                        if ($resultCallbackVariables) {
                            $resultCallbackVariables = array_unique($resultCallbackVariables[1]);
                        }
                    }

                    $resultCallback = Fieldmapping::getInstance()->findOne(
                        [
                            'dataportId = ?' => $dataportId,
                            'fieldName = ?' => '__result_callback'
                        ]
                    );

                    $rawItems = RawItem::getInstance()->find(
                        ['id IN (?)' => $rawItemIds],
                        (empty($dataport['targetconfig']['itemClass']) || $dataport['sourcetype'] === 'pimcore') ? 'priority' : 'updated,priority',
                        null,
                        0,
                        null,
                        ['id', 'hash']
                    );

                    $importManager->processChunk(
                        $rawItems,
                        $done,
                        $offset,
                        $dataport,
                        new \stdclass,
                        null,
                        $changedObjectIds,
                        $resultCallback,
                        $resultCallbackVariables,
                        null,
                        $virtualFields,
                        DataportResource::getInstance()->find(['dataportId=?' => $dataportId]),
                        true,
                        $response,
                        count($rawItems)
                    );

                    $expectedResult = $response->getContent();

                    $testCode .= '
        public function test'.$this->getValidClassName(str_replace('#', '_', Helper::getFieldKey($fieldMapping))).'()
        {
            $testData = self::provideTestData();
            
            $rawItemIds = [];
            foreach(self::provideTestData() as $testData) {
                $rawItemIds[] = $this->rawItemImporter->insert($testData[\'input\']);
            }
            
            $importManager = new Importmanager(self::$container->get(ImporterInterface::class), $this->logger, true);
            $done = 0;
            $offset = 0;
            $changedObjectIds = [];
            $virtualFields = [];
            $response = new BackingUpResponse('.$dataportId.');
            $greatestProcessedRawItemId = null;
            $lastPaginationItem = null;
            $resultCallback = Fieldmapping::getInstance()->findOne(
                [
                    \'dataportId = ?\' => '.$dataportId.',
                    \'fieldName = ?\' => [\'__result_callback\']
                ]
            );
            $resultCallbackVariables = [];
            if ($fieldMapping[\'calculation\']) {
                preg_match_all(\'/[\\\'"]?\{\{\s*(\S+?( +\S+?)*?)\s*\}\}[\\\'"]?/\', $fieldMapping[\'calculation\'], $resultCallbackVariables);
                if ($resultCallbackVariables) {
                    $resultCallbackVariables = array_unique($resultCallbackVariables[1]);
                }
            }
            
            $importManager->processChunk([\'id IN (?)\' => $rawItemIds], INF, $done, INF, $offset, '.var_export($dataport, true).', new \stdclass, null, $changedObjectIds, $resultCallback, $resultCallbackVariables, null, $virtualFields, [DataportResource::getInstance()->get($this->dataportResourceId)], 0, 1, $greatestProcessedRawItemId, $response, $lastPaginationItem);
            
            $this->assertEquals('.var_export($expectedResult, true).', $response->getContent());
        }
    ';
                    continue;
                }

                $fieldMapping = array_intersect_key($fieldMapping, array_flip(['fieldName', 'locale', 'brickName', 'targetBrickField']));
                $testCode .= '
        /**
         * @dataProvider provideTestData
         */
        public function test' . $this->getValidClassName(str_replace('#', '_', Helper::getFieldKey($fieldMapping))) . '($expectedResult, $input)
        {
            $rawItemId = $this->rawItemImporter->insert($input);
            Helper::getRequest()->cookies->set(\'rawItem-'.$dataportId.'\', Uuid::fromBytes($rawItemId)->getInteger()->toString());
            $this->helper->clearPreviewItem();
    
            $mappingParams = '.var_export($fieldMapping, true).';
            $updatableObject = Importer::getUpdatableObject($this->itemMold, $mappingParams);
            $fieldDefinition = Importer::getFieldDefinition($updatableObject, $mappingParams);
            $result = $this->helper->createMapping($fieldDefinition, $this->dataport, $mappingParams);
            $actualValue = reset($result)[\'example_parsed\'][\'result\'];
            $this->assertEquals($expectedResult["'.Helper::getFieldKey($fieldMapping).'"], $actualValue);
            
            $this->triggerLogs();
        }
    ';
            }
            $testCode .= '
    
        /**
         * @return array[]
         */
        public static function provideTestData(): array
        {
            return ' . var_export(array_values($dataProviderArray), true) . ';
        }
        
        public function triggerLogs() {
            foreach($this->logger->getLogs() as $log) {
                $this->markTestIncomplete($log);
            }
        }
    }
            ';

            $testDirectory = OPENDXP_PROJECT_ROOT.'/tests/SylphenDataBridgeBundle';
            if(!is_dir($testDirectory) && !mkdir($testDirectory, 0755, true) && !is_dir($testDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $testDirectory));
            }

            $testFilePath = OPENDXP_PROJECT_ROOT.'/tests/SylphenDataBridgeBundle/'.$this->getValidClassName($dataport['name']).'Test.php';
            file_put_contents($testFilePath, $testCode);
            $output->writeln('Successfully created test for dataport "'.$dataport['name'].' (#'.$dataport['id'].') in '.$testFilePath);
        }

        $this->createPhpunitXml($output);

        if(file_exists(OPENDXP_PROJECT_ROOT.'vendor/bin/phpunit')) {
            $output->writeln('Run the test(s) by executing '.OPENDXP_PROJECT_ROOT.'/vendor/bin/phpunit');
        } elseif(file_exists(OPENDXP_PROJECT_ROOT.'/phpunit')) {
            $output->writeln('Run the test(s) by executing '.OPENDXP_PROJECT_ROOT.'/phpunit');
        }


        return 0;
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function createPhpunitXml(OutputInterface $output) {
        $domDocument = new \DOMDocument();
        if (file_exists(OPENDXP_PROJECT_ROOT.'/phpunit.xml')) {
            if (!@$domDocument->load(
                    OPENDXP_PROJECT_ROOT.'/phpunit.xml',
                    LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                    LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                    LIBXML_NOERROR |        # Suppress any errors
                    LIBXML_NOWARNING        # or warnings about prefixes.
                ) && !$domDocument->loadHTMLFile(
                    OPENDXP_PROJECT_ROOT.'/phpunit.xml',
                    LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
                    LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
                    LIBXML_NOERROR |        # Suppress any errors
                    LIBXML_NOWARNING        # or warnings about prefixes.
                )) {
                throw new \Exception(OPENDXP_PROJECT_ROOT.'/phpunit.xml');
            }
        } else {
            $domDocument->loadXML(
                '<?xml version="1.0" encoding="utf-8" ?>
<phpunit bootstrap="./vendor/autoload.php" stopOnFailure="true" stopOnError="true" colors="true" displayDetailsOnIncompleteTests="true">
    <testsuites></testsuites>
</phpunit>'
            );
        }

        /** @var DOMNode $existingTestsuite */
        foreach ($domDocument->documentElement->getElementsByTagName('testsuites')[0]->childNodes as $existingTestsuite) {
            if($existingTestsuite instanceof DOMText) {
                continue;
            }
            $directory = $existingTestsuite->getElementsByTagName('directory')[0] ?? null;

            if ($directory->nodeValue === './tests/SylphenDataBridgeBundle') {
                return;
            }
        }

        $testsuiteFragment = $domDocument->createDocumentFragment();
        $testsuiteFragment->appendXml('<testsuite name="Data Bridge tests">
            <directory>./tests/SylphenDataBridgeBundle</directory>
        </testsuite>');
        $domDocument->documentElement->getElementsByTagName('testsuites')[0]->appendChild($testsuiteFragment);

        file_put_contents(OPENDXP_PROJECT_ROOT.'/phpunit.xml', $domDocument->saveXML());

        $output->writeln('Successfully created test for dataport "'.$dataport['name'].' (#'.$dataport['id'].') in '.$testFilePath);
    }

    private function getValidClassName($value) {
        return trim(preg_replace('/[^a-z0-9_]+/i', '', Helper::toASCII(ucwords($value))));
    }
}
