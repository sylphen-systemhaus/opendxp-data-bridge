<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use ArrayAccess;
use Sylphen\DataBridgeBundle\Command\QueueProcessorCommand;
use Sylphen\DataBridgeBundle\lib\Pim\EventDispatcher;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Import\CallbackFunction;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ImporterInterface;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\ApplicationLoggerDb;
use Sylphen\DataBridgeBundle\lib\Pim\Logger\FileObject;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\NaiveParser;
use Sylphen\DataBridgeBundle\Maintenance\CleanupImportStatus;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Export;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\ImportIgnoreData;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Sylphen\DataBridgeBundle\model\Queue;
use Sylphen\DataBridgeBundle\model\RawItem;
use Sylphen\DataBridgeBundle\model\RawItemData;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Carbon\Carbon;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Renderer\RendererConstant;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use OpenDxp;
use OpenDxp\Bundle\AdminBundle\Controller\AdminController;
use OpenDxp\Bundle\AdminBundle\Event\AssetEvents;
use OpenDxp\Bundle\AdminBundle\Event\Model\AssetDeleteInfoEvent;
use OpenDxp\Bundle\AdminBundle\Event\Model\DataObjectDeleteInfoEvent;
use OpenDxp\Bundle\AdminBundle\Event\Model\DocumentDeleteInfoEvent;
use OpenDxp\Bundle\AdminBundle\Event\Model\ElementDeleteInfoEventInterface;
use OpenDxp\Bundle\AdminBundle\Helper\GridHelperService;
use OpenDxp\Cache;
use OpenDxp\Db;
use OpenDxp\Db\Connection;
use OpenDxp\Event\AdminEvents;
use OpenDxp\Event\DataObjectEvents;
use OpenDxp\Event\DocumentEvents;
use OpenDxp\File;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Logger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Document;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\Listing;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Bundle\AdminBundle\Model\GridConfig;
use OpenDxp\Model\User;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use OpenDxp\Controller\UserAwareController;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Date;
use OpenDxp\Tool\Storage;
use OpenDxp\Translation\Translator;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use stdClass;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;
use Throwable;

use function Sylphen\DataBridgeBundle\lib\Pim\Import\descendants;
use function Sylphen\DataBridgeBundle\lib\Pim\Import\getDescendants;

/**
 * Rohdatenimport (Datenquelle in DB) starten
 *
 * @Route("/admin/{bundle}/import", defaults={"bundle"="SylphenDataBridge"}, requirements={"bundle": "SylphenDataBridge"})
 */
class ImportController extends UserAwareController {
    const LOGTYPE_FILTERS = ['queued', 'errors', 'successful', 'running', 'aborted'];

    /** @var Translator */
    protected $translator;

    /** @var ItemMoldBuilder */
    private $itemMoldBuilder;

    /** @var ImporterInterface */
    private $importer;

    /** @var ImportIgnoreData */
    private $importIgnoreData;

    public function __construct(Translator $pimTranslator, ItemMoldBuilder $itemMoldBuilder, ImporterInterface $importer, ImportIgnoreData $importIgnoreData)
    {
        $this->translator = $pimTranslator;
        $this->itemMoldBuilder = $itemMoldBuilder;
        $this->importer = $importer;
        $this->importIgnoreData = $importIgnoreData;
    }

    /**
     * @Route("/delete-rawdata")
     */
	public function deleteRawdataAction(Request $request) {
        Helper::setMemoryLimit();
		$dataportId = (int)$request->get('dataportId');

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

        $responseData = [
            'success' => true,
            'runningProcesses' => false
        ];

        $currentlyRunning = PimcoreDbRepository::getInstance()->findInSql('SELECT dataport_resource_id FROM '.Installer::TABLE_IMPORTSTATUS.' status INNER JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource ON status.dataport_resource_id=dataport_resource.id WHERE dataportId = ? AND status = ?', [$dataportId, ImportStatus::STATUS_RUNNING]);
        $currentlyRunning = array_map(static function ($row) {
            return (int)$row['dataport_resource_id'];
        }, $currentlyRunning);

        $dataportResources = DataportResource::getInstance();
        $resourceCondition = ['dataportId = ?' => $dataportId];
        if (!$request->get('force') && $currentlyRunning) {
            $resourceCondition['id NOT IN (?)'] = $currentlyRunning;

            $responseData['runningProcesses'] = true;
        }
        foreach($dataportResources->find($resourceCondition) as $dataportResource) {
            do {
                $deletedRows = $dataportResources->execute('DELETE FROM '.Installer::TABLE_RAWITEM.' WHERE dataport_resource_id = ? ORDER BY id LIMIT 10000', [$dataportResource['id']]);
            } while ($deletedRows === 10000);
        }

        Dataport::clearImportHashes($dataportId);
        Dataport::clearResultCache($dataportId);

        $queueRepository = Queue::getInstance();
        $queueItems = $queueRepository->find();
        foreach ($queueItems as $queueItem) {
            $queueItemDataportId = null;
            if (preg_match('/^(?:data-bridge):(?:complete|extract|process|rawdata|pim)\s+"?(\d+)"?/', $queueItem['command'], $matches)) {
                $queueItemDataportId = $matches[1];
            } elseif (preg_match('/^(?:data-bridge):delete-rawdata\s+--dataport-resource-id="?(\d+)"?/', $queueItem['command'], $matches)) {
                $queueItemDataportId = $dataportResources->get($matches[1])['dataportId'] ?? 'unknown';
            }

            if($queueItemDataportId == $dataportId) {
                $queueRepository->delete($queueItem['id']);
            }
        }

        Cache::clearTag('mapping-preview-'.$dataportId);

		return new JsonResponse($responseData);
	}

    /**
     * @Route("/get-status/{dataportId}", defaults={"dataportId"=null})
     */
	public function getStatusAction($dataportId, Request $request) {
        $user = Tool\Admin::getCurrentUser();

        if ($dataportId && !Dataport::canDataportBeExecutedBy($dataportId, $user) && !Dataport::canDataportBeConfiguredBy($dataportId, $user)) {
            $response = array(
                'success' => false,
                'message' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getConfigurationPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

		$data = array(
			'success' => true,
			'status' => array()
		);

		$conditions = [1];
		if($request->get('query')) {
		    foreach(\json_decode($request->get('query'), true) as $filter) {
		        if($filter['value'] && $filter['operator'] === 'in' && in_array($filter['property'], ['file', 'locale'], true)) {
		            $conditionsOr = [];
		            foreach($filter['value'] as $filterValue) {
		                if($filterValue == -1) {
                            $conditionsOr[] = 'resource NOT LIKE \'%"'.$filter['property'].'":%\'';
                        } else {
                            $conditionsOr[] = 'resource LIKE \'%"'.$filter['property'].'":"'.$filterValue.'"%\'';
                        }
                    }
		            $conditions[] = implode(' OR ', $conditionsOr);
                }
            }
        }

        $status = [];

        $data['total'] = 0;
        $searchQueries = $request->get('search', []);
        $logTypeFiltered = count(array_intersect($searchQueries, self::LOGTYPE_FILTERS)) > 0;
        $showQueuedItems = !$logTypeFiltered || in_array('queued', $searchQueries, true);
        $showErrorItems = !$logTypeFiltered || in_array('errors', $searchQueries, true);
        $showAbortedItems = !$logTypeFiltered || in_array('aborted', $searchQueries, true);
        $showSuccessfulItems = !$logTypeFiltered || in_array('successful', $searchQueries, true);
        $showRunningItems = !$logTypeFiltered || in_array('running', $searchQueries, true);
        $showDryRunItems = in_array('dry-run', $searchQueries, true);
        $doneFilter = null;
        $totalFilter = null;
        foreach($searchQueries as $index => $searchQuery) {
            if(preg_match('/^done\s*([<=>]\s*(\d+|total))/', $searchQuery, $matches)) {
                if($matches[2] === 'total') {
                    $matches[1] = str_replace('total', 'totalItems', $matches[1]);
                }
                $doneFilter = $matches[1];
                unset($searchQueries[$index]);
            } elseif (preg_match('/^total\s*([<=>]\s*(\d+|done))/', $searchQuery, $matches)) {
                if ($matches[2] === 'done') {
                    $matches[1] = str_replace('done', 'doneItems', $matches[1]);
                }
                $totalFilter = $matches[1];
                unset($searchQueries[$index]);
            }
        }

        $searchQuery = null;
        $searchQueries = array_diff($searchQueries, self::LOGTYPE_FILTERS);
        if($searchQueries) {
            $searchQuery = implode('', array_map(
                static function ($searchQuery) {
                    return preg_quote($searchQuery, '/');
                },
                $searchQueries
            ));
        }

        $query = 'SELECT * FROM (';
        $unions = [];
        if ($showQueuedItems) {
            $unions[] = '
                SELECT "queue" AS type, queue.queued_at AS `startDate`, NOW() AS `endDate`, queue.id, "" AS importType, "queued" AS status, queue.command as resource, 0 AS totalItems, 0 AS doneItems, 0 AS dataport_resource_id, worker_id AS dataport_id, "" AS command_parameters, "" AS worst_error, triggered_by, restarts
                FROM '.Installer::TABLE_QUEUE.' queue 
                WHERE '.($dataportId ? 'worker_id = \''.$dataportId.'\'' : '1').' AND command NOT LIKE \'data-bridge:start-automatic-dataports%\' AND (started_at IS NULL OR restarts > 0) '.($searchQuery ? ' AND command REGEXP \''.$searchQuery.'\'' : '').'
                '.(($doneFilter !== null) ? 'AND 1=0' : '').'
                '.(($totalFilter !== null) ? 'AND 1=0' : '');
        }

        $logTypeCondition = '1';
        if ($showErrorItems && $showSuccessfulItems && $showRunningItems && $showAbortedItems) {
            $logTypeCondition = '1';
        } elseif ($showErrorItems && $showSuccessfulItems && $showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'status.status!='.ImportStatus::STATUS_ABORTED;
        } elseif ($showErrorItems && $showSuccessfulItems && !$showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'status.status!='.ImportStatus::STATUS_RUNNING;
        } elseif ($showErrorItems && $showSuccessfulItems && !$showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'status.status NOT IN ('.ImportStatus::STATUS_RUNNING.','.ImportStatus::STATUS_ABORTED.')';
        } elseif ($showErrorItems && !$showSuccessfulItems && $showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_RUNNING.' OR worst_error IS NOT NULL OR status.status='.ImportStatus::STATUS_ABORTED;
        } elseif ($showErrorItems && !$showSuccessfulItems && $showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_RUNNING.' OR worst_error IS NOT NULL';
        } elseif ($showErrorItems && !$showSuccessfulItems && !$showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'worst_error IS NOT NULL OR status.status='.ImportStatus::STATUS_ABORTED;
        } elseif ($showErrorItems && !$showSuccessfulItems && !$showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'worst_error IS NOT NULL';
        } elseif (!$showErrorItems && $showSuccessfulItems && $showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_RUNNING.' OR (worst_error IS NULL AND status.status='.ImportStatus::STATUS_FINISHED.') OR status.status='.ImportStatus::STATUS_ABORTED;
        } elseif (!$showErrorItems && $showSuccessfulItems && $showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_RUNNING.' OR (worst_error IS NULL AND status.status='.ImportStatus::STATUS_FINISHED.')';
        } elseif (!$showErrorItems && $showSuccessfulItems && !$showRunningItems && $showAbortedItems) {
            $logTypeCondition = '(worst_error IS NULL AND status.status='.ImportStatus::STATUS_FINISHED.') OR status.status='.ImportStatus::STATUS_ABORTED;
        } elseif (!$showErrorItems && $showSuccessfulItems && !$showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'worst_error IS NULL AND status.status='.ImportStatus::STATUS_FINISHED;
        } elseif (!$showErrorItems && !$showSuccessfulItems && $showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'status.status IN ('.ImportStatus::STATUS_RUNNING.','.ImportStatus::STATUS_ABORTED.')';
        } elseif (!$showErrorItems && !$showSuccessfulItems && $showRunningItems && !$showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_RUNNING;
        } elseif (!$showErrorItems && !$showSuccessfulItems && !$showRunningItems && $showAbortedItems) {
            $logTypeCondition = 'status.status='.ImportStatus::STATUS_ABORTED;
        } elseif (!$showErrorItems && !$showSuccessfulItems && !$showRunningItems && !$showAbortedItems) {
            $logTypeCondition = '0';
        }

        $unions[] = '
            SELECT "status" AS type, status.startDate, status.endDate, status.key AS id, status.importType, status.status, import_resource.resource, totalItems, doneItems, dataport_resource_id, dataport_id, command_parameters, worst_error, "" AS triggered_by, 0 AS restarts
            FROM '.Installer::TABLE_IMPORTSTATUS.' status 
            LEFT JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' import_resource ON status.dataport_resource_id = import_resource.id 
            WHERE '.($dataportId ? 'status.dataport_id = '.$dataportId.' AND':'').' ('.implode(') AND (', $conditions).')
            AND '.$logTypeCondition
            .(($doneFilter !== null) ? ' AND doneItems '.$doneFilter : '').'
            '.(($totalFilter !== null) ? ' AND totalItems '.$totalFilter : '').'
            '.($showDryRunItems ? ' AND importType & '.ImportStatus::TYPE_DRY_RUN:'');

        $unionsData = $unions;
        if ($searchQuery === null) {
            foreach($unionsData as &$union) {
                $union .= ' ORDER BY `startDate` DESC, id DESC LIMIT '.((int)$request->get('start') + (int)$request->get('limit', 25));
            }
            unset($union);
        }

        $query .= '('.implode(') UNION ALL (', $unionsData).')
        ) t
        ORDER BY `startDate` DESC, id DESC';

        if($searchQuery === null) {
            $query .= ' LIMIT '.(int)$request->get('start').','.(int)$request->get('limit', 25);
        }

        $result = PimcoreDbRepository::getInstance()->findInSql($query);

        $data['total'] = 0;
        foreach($unions as $union) {
            $union = preg_replace('/SELECT (.*) FROM/s', 'SELECT 1 FROM', $union);
            $union = str_replace('LEFT JOIN '.Installer::TABLE_DATAPORT_RESOURCE.' import_resource ON status.dataport_resource_id = import_resource.id', '', $union);

            $data['total'] += PimcoreDbRepository::getInstance()->findOneInSql('SELECT COUNT(*) FROM ('.$union.') t');
        }


        $startTime = time();

        $applicationLoggerEnabled = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.ApplicationLoggerDb::TABLE_NAME.' WHERE component = ? LIMIT 1', ['Sylphen/DataBridgeBundle']);

        $dataports = Dataport::getInstance();
        if($dataportId) {
            $dataport = $dataports->get($dataportId);
        } else {
            $dataport = null;
        }

        $dateFormatter = Helper::getDateFormatter();

        $statusFileMapping = [];
        $originalDataport = $dataport;
        foreach ($result as $entry) {
            $dataport = $originalDataport;
            if($dataport === null && $entry['dataport_id']) {
                $dataport = $dataports->get($entry['dataport_id']);
            }

            if (!Dataport::canDataportBeExecutedBy($dataport['id'], Tool\Admin::getCurrentUser()) && !Dataport::canDataportBeConfiguredBy($dataport['id'], Tool\Admin::getCurrentUser())) {
                continue;
            }

            if(strpos($entry['resource'], ':delete-rawdata') !== false) {
                continue;
            }

            if($entry['type'] === 'queue') {
                // options with only 1 "-" may be a problem?
                // support delete-rawdata
                // data-bridge:delete-rawdata --dataport=15 --object-id=3067 --object-type=document
                preg_match('/^([a-z-:]+)\s+"?(\d+)"?(\s+("(?:\\"|[^"])+"|((?!--)\S)*))?/', $entry['resource'], $commandParts);

                if(empty($commandParts[4]) && preg_match('/--parameters="(.*)"/', $entry['resource'], $parameterMatch)) {
                    $commandParts[4] = str_replace('\"', '"', $parameterMatch[1]);
                }
                $commandParts[4] = trim($commandParts[4] ?? '', '"');

                switch ($commandParts[1] ?? null) {
                    case 'data-bridge:complete':
                    case 'data-bridge:complete':
                    $type = ImportStatus::TYPE_COMPLETE;
                        break;
                    case 'import:pim':
                    case 'data-bridge:process':
                        $type = ImportStatus::TYPE_PIM;
                        break;
                    case 'data-bridge:extract':
                    case 'import:rawdata':
                    case 'data-bridge:extract':
                        $type = ImportStatus::TYPE_RAWDATA;
                        break;
                    default:
                        $type = 'unknown';
                        break;
                }

                $statusFileMappingKey = md5(json_encode($entry['resource']));
                $status[] = array(
                    'id' => 'queue-'.$entry['id'],
                    'file' => $commandParts[4] ?? -1,
                    'statusFileMappingKey' => $statusFileMappingKey,
                    'sourceElementIDs' => [],
                    'redoable' => false,
                    'locale' => -1,
                    'startDate' => $dateFormatter->format((new DateTimeImmutable($entry['startDate'], new DateTimeZone('UTC')))),
                    'duration' => 0,
                    'status' => 'queued',
                    'percentage' => '0 %',
                    'type' => $type,
                    'dryRun' => false,
                    'logFile' => '',
                    'worstLogType' => $entry['restarts'] >= 10 ? 'ERROR' : '',
                    'worstLog' => $entry['restarts'] >= 10 ? 'Queued job got restarted '.$entry['restarts'].'x without success' : '',
                    'triggeredBy' => $entry['triggered_by'] ?? '',
                    'dataportName' => $dataport['name'].' ('.$dataport['id'].')'
                );
            } elseif($entry['type'] === 'status') {
                $percentage = 0;
                $total = (int)$entry['totalItems'];
                $done = (int)$entry['doneItems'];

                if ($total > 0) {
                    $percentage = (int)($done / $total * 100);
                }

                $startDate = $entry['startDate'];
                $endDate = $entry['endDate'];

                if (!empty($startDate)) {
                    $startDate = new \DateTimeImmutable($startDate, new DateTimeZone('UTC'));
                }

                if (!empty($endDate)) {
                    $endDate = new \DateTimeImmutable($endDate, new DateTimeZone('UTC'));
                }

                $resource = \json_decode($entry['resource'] ?? '', true);

                $logFilePath = $entry['dataport_id'].'/'.$entry['id'];
                if(!Helper::getApplicationLogStorage()->fileExists($logFilePath) && Helper::getApplicationLogStorage()->fileExists($logFilePath.'.gz')) {
                    $logFilePath .= '.gz';
                }

                $searchStringFound = false;
                $worstLogType = 'DEBUG';
                $worstLog = $entry['worst_error'] ?? '';
                $errorLevels = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'];
                foreach ($errorLevels as $errorLevel) {
                    if (strpos($worstLog, '['.$errorLevel.'] ') !== false) {
                        $worstLogType = $errorLevel;
                        break;
                    }
                }

                if ($applicationLoggerEnabled && $searchQuery !== null) {
                    $found = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.ApplicationLoggerDb::TABLE_NAME.' WHERE component = ? AND fileobject = ? AND message REGEXP \''.$searchQuery.'\' LIMIT 1', ['Sylphen/DataBridgeBundle', preg_replace('/^'.preg_quote(\OPENDXP_PROJECT_ROOT, '/').'/', '', $logFilePath)]);
                    if ($found) {
                        $searchStringFound = true;
                    }
                }

                if ($searchQuery !== null && !$searchStringFound) {
                    if(time() > $startTime + 20) {
                        break;
                    }
                    if(Helper::getApplicationLogStorage()->fileExists($logFilePath)) {
                        try {
                            $logFileHandle = Helper::getApplicationLogStorage()->readStream($logFilePath);
                            if (str_ends_with($logFilePath, '.gz')) {
                                $localPath = Helper::getTemporaryFileFromStream($logFileHandle);
                                $logFileHandle = fopen('compress.zlib://' . $localPath, 'rb');
                            }
                        } catch(\Throwable $e) {
                            $logFileHandle = null;
                        }

                        $remainingSearchTerms = $searchQueries;

                        foreach ($remainingSearchTerms as $searchTermIndex => $searchTerm) {
                            if (preg_match('/'.preg_quote($searchTerm, '/').'/i', $logFilePath)) {
                                unset($remainingSearchTerms[$searchTermIndex]);

                                if (count($remainingSearchTerms) === 0) {
                                    $searchStringFound = true;
                                    break;
                                }
                            }
                        }

                        if (is_resource($logFileHandle)) {
                            while (($logFileLine = fgets($logFileHandle)) !== false) {
                                foreach ($remainingSearchTerms as $searchTermIndex => $searchTerm) {
                                    if (preg_match('/'.preg_quote($searchTerm, '/').'/i', $logFileLine)) {
                                        unset($remainingSearchTerms[$searchTermIndex]);

                                        if (count($remainingSearchTerms) === 0) {
                                            $searchStringFound = true;
                                            break 2;
                                        }
                                    }
                                }
                            }

                            fclose($logFileHandle);
                        }
                    }
                }

                if ($searchQuery !== null) {
                    if (!$searchStringFound) {
                        continue;
                    }

                    $data['total']++;
                    if ($request->get('start') > count($status)) {
                        continue;
                    }

                    if ($request->get('start') + $request->get('limit', 25) < count($status)) {
                        break;
                    }
                }

                $redoable = false;
                if ($entry['importType'] & ImportStatus::TYPE_RAWDATA) {
                    if (in_array($dataport['sourcetype'], ['pimcore', 'filesystem'])) {
                        $redoable = true;
                    } else {
                        $redoable = !empty(
                        PimcoreDbRepository::getInstance()->findOneInSql(
                            'SELECT cid FROM properties WHERE ctype = ? AND name = ? AND data = ?',
                            ['asset', 'statusKey', $entry['id']]
                        ));
                    }
                } elseif ($entry['importType'] & ImportStatus::TYPE_PIM) {
                    $commandParameters = json_decode($entry['command_parameters'], true);

                    $redoable = true;
                    if (!isset($commandParameters['--dataport-resource-id'])) {
                        $redoable = false;
                    } else {
                        $dataportResourceIds = explode(',', $commandParameters['--dataport-resource-id']);
                        $dataportResourceHasRawItem = PimcoreDbRepository::getInstance()->findOneInSql('SELECT 1 FROM '.Installer::TABLE_RAWITEM.' rawitem WHERE dataport_resource_id IN (?) LIMIT 1', [$dataportResourceIds]);
                        if (!$dataportResourceHasRawItem) {
                            $redoable = false;
                        }
                    }

                    if ($redoable && !empty($commandParameters['rawitem'])) {
                        $rawItems = RawItem::getInstance();
                        foreach (explode(',', $commandParameters['rawitem']) as $rawItemId) {
                            $rawItemExists = $rawItems->get($rawItemId);
                            if (!$rawItemExists) {
                                $redoable = false;
                                break;
                            }
                        }
                    }
                }

                $file = $resource['file'] ?? -1;
                $locale = $resource['locale'] ?? -1;
                $statusFileMappingKey = md5(json_encode($resource));

                if ($file === -1 && Helper::getApplicationLogStorage()->fileExists($logFilePath)) {
                    try {
                        $logFileHandle = Helper::getApplicationLogStorage()->readStream($logFilePath);
                        $firstRow = fgets($logFileHandle);
                        if (preg_match('/Resource: (.+)(?=, Started at)/', $firstRow, $match)) {
                            $matchParts = explode(', language: ', $match[1]);
                            $file = $matchParts[0];
                            if ($locale === -1 && isset($matchParts[1])) {
                                $locale = $matchParts[1];
                            }
                        }
                        fclose ($logFileHandle);
                    } catch (UnableToReadFile $e) {
                    }
                }

                $sourceElementIDs = [];
                if (!in_array($dataport['sourcetype'], ['pimcore', 'filesystem'])) {
                    $sourceElementIDs = PimcoreDbRepository::getInstance()->findColumnInSql(
                        'SELECT cid FROM properties WHERE ctype = ? AND name = ? AND data = ?',
                        ['asset', 'statusKey', $entry['id']]
                    );

                    if ($sourceElementIDs) {
                        $statusFileMapping[$statusFileMappingKey] = $sourceElementIDs;
                    }
                }

                $logFileLink = '';
                $logFileLinkAbsolute = '';
                if (Helper::getApplicationLogStorage()->fileExists($logFilePath)) {
                    $logFileLink = $logFilePath;

                    if (defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY')) {
                        $logFileLinkAbsolute = OPENDXP_LOG_FILEOBJECT_DIRECTORY.'/'.$logFilePath;
                    } else {
                        $logFileLinkAbsolute = OPENDXP_PRIVATE_VAR.'/application-logger/'.$logFilePath;
                    }

                    if(!file_exists($logFileLinkAbsolute)) {
                        $logFileLinkAbsolute = '';
                    }
                }

                $importType = $entry['importType'];
                if($importType & ImportStatus::TYPE_COMPLETE) {
                    if($importType & ImportStatus::TYPE_RAWDATA) {
                        $importType = ImportStatus::TYPE_COMPLETE.'.1';
                    } else {
                        $importType = ImportStatus::TYPE_COMPLETE.'.2';
                    }
                }

                $status[] = array(
                    'id' => $entry['id'],
                    'file' => $file,
                    'statusFileMappingKey' => $statusFileMappingKey,
                    'sourceElementIDs' => $sourceElementIDs,
                    'redoable' => $redoable,
                    'locale' => $locale,
                    'startDate' => $dateFormatter->format($startDate),
                    'duration' => ($endDate ? $endDate->getTimestamp() : (new \DateTimeImmutable())->getTimestamp()) - $startDate->getTimestamp(),
                    'status' => $entry['status'],
                    'percentage' => $percentage.' % ('.$done.' / '.$total.')',
                    'type' => $importType,
                    'dryRun' => $entry['importType'] & ImportStatus::TYPE_DRY_RUN,
                    'logFile' => $logFileLink,
                    'logFileAbsolutePath' => $logFileLinkAbsolute,
                    'worstLogType' => $worstLogType,
                    'worstLog' => $worstLog ?? '',
                    'triggeredBy' => '',
                    'dataportId' => $dataport['id'],
                    'dataportName' => $dataport['name'].' ('.$dataport['id'].')'
                );
            }
        }

        foreach($status as &$statusItem) {
            if(!$statusItem['sourceElementIDs'] && $statusItem['file'] && $statusItem['type'] & ImportStatus::TYPE_PIM) {
                $statusItem['sourceElementIDs'] = $statusFileMapping[$statusItem['statusFileMappingKey']] ?? [];
            }
            unset($statusItem['statusFileMappingKey']);
        }
        unset($statusItem);

        $data['status'] = $status;

        // check if queued jobs exists and queue processor is not running
        $data['queueProcessingError'] = false;

        $queue = Queue::getInstance();
        $data['queueItemExists'] = $queue->findOne(['queued_at < ?' => (new DateTimeImmutable('@'.(time() - 60), new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        if ($data['queueItemExists']) {
            try {
                $lockFactory = OpenDxp::getContainer()->get(LockFactory::class);
            } catch (\Throwable $e) {
                $store = new FlockStore(OPENDXP_SYSTEM_TEMP_DIRECTORY);
                $lockFactory = new \Symfony\Component\Lock\Factory($store);
            }

            $queueProcessorLock = $lockFactory->createLock(QueueProcessorCommand::LOCK_KEY);

            $queueProcessorRunning = !$queueProcessorLock->acquire(false);
            if (!$queueProcessorRunning) {
                $queueProcessorLock->release();

                if (OpenDxp::getContainer()->getParameter('data_bridge.config')['queue_processing']['automatic_start']) {
                    Queue::startQueueProcessor();
                    sleep(5);
                    $queueItemExists = $queue->findOne(['id = ?' => $data['queueItemExists']['id'], 'restarts < ?' => 10]);
                } else {
                    $queueItemExists = false;
                }

                if ($queueItemExists) {
                    $queueProcessorRunning = !$queueProcessorLock->acquire(false);
                    if (!$queueProcessorRunning) {
                        $queueProcessorLock->release();
                        $data['queueProcessingError'] = true;
                    }
                }
            }
        }

        return new JsonResponse($data);
	}

    /**
     * @Route("/manual-import", methods={"POST"})
     */
	public function manualImportAction(Request $request) {
		$response = array(
			'success' => true
		);

		try {
			$phpCliBin = (bool)Cli::getPhpCli();
		} catch (\Throwable $e) {
			$phpCliBin = false;
		}

		if ($phpCliBin) {
		    try {
                $dataportId = (int)$request->get('dataportId');

                if (!Dataport::canDataportBeExecutedBy($dataportId, Tool\Admin::getCurrentUser())) {
                    $response = array(
                        'success' => false,
                        'msg' => sprintf(
                            $this->translator->trans('pim.permission_missing', [], 'admin'),
                            $this->translator->trans(
                                Dataport::getConfigurationPermissionName($dataportId),
                                [],
                                'admin'
                            )
                        )
                    );

                    return new JsonResponse($response);
                }

                $dataportModel = Dataport::getInstance();
                $dataport = $dataportModel->get($dataportId);

                $locale = $request->get('locale');
                if ($request->get('statusKey')) {
                    $statusModel = ImportStatus::getInstance();
                    $status = $statusModel->findOne(['`key` = ?' => $request->get('statusKey')]);
                    if (!empty($status)) {
                        $commandParameters = json_decode($status['command_parameters'], true);
                        if (isset($commandParameters['--rm'])) {
                            $request->attributes->set('removeFileAfterImport', $commandParameters['--rm']);
                        }

                        $dataportResources = DataportResource::getInstance();
                        $dataportResource = $dataportResources->get($status['dataport_resource_id']);
                        if (!$dataportResource) {
                            return new JsonResponse([
                                'success' => false,
                                'msg' => 'Could not find dataport resource'
                            ]);
                        }
                        $resourceSettings = \json_decode($dataportResource['resource'], true);
                        $locale = $resourceSettings['locale'] ?? null;

                        if (in_array($dataport['sourcetype'], ['pimcore', 'filesystem'])) {
                            $importFileName = $resourceSettings['file'] ?? '';
                        } else {
                            $importFileIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                                'SELECT cid FROM properties WHERE ctype = ? AND name = ? AND data = ?',
                                ['asset', 'statusKey', $status['key']]
                            );
                            $rerunId = uniqid();
                            foreach($importFileIds as $importFileId) {
                                $asset = Asset::getById($importFileId);
                                if($asset instanceof Asset) {
                                    if (defined('OPENDXP_ASSET_DIRECTORY')) {
                                        $importFileName = \OPENDXP_ASSET_DIRECTORY.$asset->getRealFullPath();
                                    } else {
                                        $importFileName = \OPENDXP_WEB_ROOT.'/var/assets'.$asset->getRealFullPath();
                                    }

                                    if (!file_exists($importFileName)) {
                                        $importFileName = Helper::getLocalAssetFile($asset);
                                    }

                                    $tempFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataportId.'_rerun_'.$rerunId.'_'.substr(basename($importFileName), 150);
                                    copy($importFileName, $tempFileName);
                                }
                            }
                            $importFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataportId.'_rerun_'.$rerunId.'_*';
                        }
                    }
                } elseif(!$locale) {
                    $locale = Tool\Admin::getCurrentUser()->getLanguage();
                    if(!Tool::isValidLanguage($locale)) {
                        foreach (Tool::getValidLanguages() as $language) {
                            if (strpos($language, $locale) === 0) {
                                $locale = $language;
                                break;
                            }
                        }
                    }
                }

                if ($dataport) {
                    $type = $request->get('importType');
                    if (!isset($importFileName)) {
                        $importFileName = $this->getUploadedFile('importfile', $dataport);
                    }

                    if ($type === 'raw') {
                        // Rohdatenimport
                        $response['success'] = $this->startImport($importFileName, $dataport, false, $request, $locale);
                    } elseif ($type === 'complete') {
                        // Komplettimport
                        $response['success'] = true;

                        $parameters = [];
                        $parameters[] = $request->request->all();
                        $parameters[] = $request->query->all();
                        $parameters[] = $request->attributes->all();
                        $parameters = array_replace(...$parameters);

                        $parameters = array_filter($parameters, static function ($parameterName) {
                            return !in_array($parameterName, ['dataportId', 'importType', 'force', 'csrfToken', 'bundle', 'importfile', 'locale', 'apikey', 'dry-run']) && substr($parameterName, 0, 1) !== '_';
                        }, ARRAY_FILTER_USE_KEY);

                        if (Dataport::hasResultCallbackWithOutput($dataportId)) {
                            $statusKey = uniqid('', true);
                            $cmd = '"' .Cli::getPhpCli() . '" "'.realpath(OPENDXP_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console') . '" data-bridge:complete ' . $dataportId . ' '.escapeshellarg($importFileName).' -n --status-key='.$statusKey.' -f'.(($locale !== null) ? ' --locale='.$locale : '').($request->get('dry-run') ? ' --dry-run':'').($parameters ? ' --parameters='.escapeshellarg(json_encode($parameters)):'').' --user='.Tool\Admin::getCurrentUser()->getId();

                            Cli::execInBackground($cmd);

                            $statusUrlParams = ['autoRefresh' => 1, 'hideProgressBar' => 0, 'statusKey' => $statusKey];
                            $user = Tool\Admin::getCurrentUser();
                            $apiKey = PimcoreDbRepository::getInstance()->findOneInSql('SELECT api_key FROM '.Installer::TABLE_API_KEYS.' api_keys WHERE api_keys.users_id=? AND api_keys.api_key!=\'\' AND api_keys.api_key IS NOT NULL AND (api_keys.valid_to IS NULL OR api_keys.valid_to >= NOW())', [$user->getId()]);
                            if (!$apiKey && method_exists($user, 'getApiKey')) {
                                $apiKey = $user->getApiKey();
                            }

                            if($apiKey) {
                                $statusUrlParams['apikey'] = $apiKey;
                            }
                            $statusUrl = Helper::generateAbsoluteUrl('import_status', $statusUrlParams);
                            $response['url'] = $statusUrl;
                            $response['msg'] = sprintf(
                                $this->translator->trans('pim.manual.startimport.popup_blocked', [], 'admin'),
                                $response['url']
                            );
                            $response['statusKey'] = $statusKey;
                        } else {
                            $statusKey = uniqid('', true);
                            $response['success'] = $this->startImport($importFileName, $dataport, true, $request, $locale, $statusKey);
                            $response['statusKey'] = $statusKey;
                        }
                    } else {
                        $response['success'] = false;
                    }
                } else {
                    $response['success'] = false;
                }
            } catch(\Exception $e) {
                $response['success'] = false;
                $response['msg'] = $e->getMessage();
            }
		} else {
			$response['success'] = false;
			$response['msg'] = $this->translator->trans('pim.manual.error.noPhpCli', [], 'admin');
		}

        return new JsonResponse($response);
	}

    /**
     * @Route("/manual-pim-import")
     */
	public function manualPimImportAction(Request $request) {
		$response = array(
			'success' => true
		);

		if($request->get('statusKey')) {
		    $statusModel = ImportStatus::getInstance();
		    $status = $statusModel->findOne(['`key` = ?' => $request->get('statusKey')]);
		    if(!empty($status)) {
		        $commandParameters = json_decode($status['command_parameters'], true);
		        if(isset($commandParameters['rawitem'])) {
                    $request->attributes->set('rawDataId', $commandParameters['rawitem']);
                }
                if (isset($commandParameters['-f'])) {
                    $request->attributes->set('force', $commandParameters['-f']);
                }
                if (isset($commandParameters['--dataport-resource-id'])) {
                    $request->attributes->set('dataport-resource-id', $commandParameters['--dataport-resource-id']);
                }
            }
        }

        $dataportId = (int)$request->get('dataportId');

        if (!Dataport::canDataportBeExecutedBy($dataportId, Tool\Admin::getCurrentUser())) {
            $response = array(
                'success' => false,
                'msg' => sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );

            return new JsonResponse($response);
        }

        $dataportModel = Dataport::getInstance();
        $dataport = $dataportModel->get($dataportId);

        $this->guardPermission($dataport);

        $rawItemId = $request->get('rawDataId');

        if ($dataport) {
            $force = '';
            if($request->get('force') || $rawItemId !== null) {
                $force = ' --force';

                if($rawItemId !== null) {
                    $force .= ' -vv';
                }
            }

            $statusKey = uniqid('', true);
            $response['statusKey'] = $statusKey;
            $user = Tool\Admin::getCurrentUser();
            $cmd = '"' .Cli::getPhpCli() . '" ' .realpath(OPENDXP_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR  . 'console') . ' data-bridge:process ' . $dataportId.(($rawItemId !== null)?' '.$rawItemId:'').$force.' --status-key='.$statusKey.($request->get('dataport-resource-id')?' --dataport-resource-id='. $request->get('dataport-resource-id'):'').' --user='.$user->getId();

            if($rawItemId !== null) {
                // we need to use temporary filename because Cli::exec() discards stderr output otherwise
                $tempFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataportId.'_manual_'.md5($rawItemId).'_'. uniqid().'.log';

                $request->getSession()->save(); // session_write_close() to not block the session for other requests

                Cli::exec($cmd, $tempFileName);
                $logs = [];
                $logFileHandle = fopen($tempFileName, 'rb');
                while (($logFileLine = fgets($logFileHandle)) !== false) {
                    if(strpos($logFileLine, 'User Deprecated') === false && strpos($logFileLine, 'app.DEBUG') === false && strpos($logFileLine, 'E_DEPRECATED') === false && strpos($logFileLine, '[messenger]') === false) {
                        $logs[] = trim($logFileLine, "\n\r\0\x0B");
                    }
                }

                $response['logs'] = htmlentities(implode("\n", $logs));
                @unlink($tempFileName);
            } elseif (Dataport::hasResultCallbackWithOutput($dataportId)) {
                $cmd = '"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" data-bridge:process '.$dataportId.' --status-key='.$statusKey.($request->get('force') ? ' -f':'').' --user='.$user->getId();
                Cli::execInBackground($cmd);

                $statusUrlParams = ['autoRefresh' => 1, 'statusKey' => $statusKey];
                $user = Tool\Admin::getCurrentUser();
                $apiKey = PimcoreDbRepository::getInstance()->findOneInSql('SELECT api_key FROM '.Installer::TABLE_API_KEYS.' api_keys WHERE api_keys.users_id=? AND api_keys.api_key!=\'\' AND api_keys.api_key IS NOT NULL AND (api_keys.valid_to IS NULL OR api_keys.valid_to >= NOW())', [$user->getId()]);
                if (!$apiKey && method_exists($user, 'getApiKey')) {
                    $apiKey = $user->getApiKey();
                }

                if ($apiKey) {
                    $statusUrlParams['apikey'] = $apiKey;
                }
                $statusUrl = Helper::generateAbsoluteUrl('import_status', $statusUrlParams);
                $response['url'] = $statusUrl;
                $response['msg'] = sprintf(
                    $this->translator->trans('pim.manual.startimport.popup_blocked', [], 'admin'),
                    $response['url']
                );
            } else {
                $response['success'] = true;

                Cli::execInBackground($cmd);
            }
        } else {
            $response['success'] = false;
        }

		return new JsonResponse($response);
	}

	/**
	 * @param string $importFile path to import file
	 * @param array $dataport
	 * @param bool $complete
	 * @return bool
	 */
	private function startImport($importFile, array $dataport, $complete = false, Request $request = null, $locale = true, $statusKey = '') {
		$command = 'data-bridge:extract';
		if ($complete === true) {
			$command = 'data-bridge:complete';

            $this->guardPermission($dataport);
		}

        $cmd = '"'.Cli::getPhpCli().'" "'.
            realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" '.$command.' '.$dataport['id'];

        if (!empty($importFile)) {
            $cmd .= ' '.escapeshellarg($importFile).($request->get('removeFileAfterImport') ? ' --rm' : '');
        }

        $cmd .= ' --force';
        $cmd .= ' --user='.Tool\Admin::getCurrentUser()->getId();

        if ($locale !== null) {
            $cmd .= ' --locale='.$locale;
        }

        if($complete === true && $request->get('dry-run')) {
            $cmd .= ' --dry-run';
        }

        if($statusKey) {
            $cmd .= ' --status-key='.$statusKey;
        }

        $parameters = [];
        if($request instanceof Request) {
            $parameters[] = $request->request->all();
            $parameters[] = $request->query->all();
            $parameters[] = $request->attributes->all();
            $parameters = array_replace(...$parameters);
            $parameters = array_filter($parameters, static function($parameterName) {
                return !in_array($parameterName, ['dataportId', 'importType', 'force', 'csrfToken', 'bundle', 'importfile', 'locale', 'apikey', 'dry-run']) && substr($parameterName, 0, 1) !== '_';
            }, ARRAY_FILTER_USE_KEY);

            if($parameters) {
                $parameterJson = json_encode($parameters, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                if(strlen($parameterJson) > 100000) {
                    $parameterFile = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataport['id'].'_'.uniqid().'.json';
                    file_put_contents($parameterFile, $parameterJson);
                    $cmd .= ' --parameters='.escapeshellarg($parameterFile);
                } else {
                    $cmd .= ' --parameters='.escapeshellarg($parameterJson);
                }
            }
        }

        Cli::execInBackground($cmd);

        return true;
	}

	private function getUploadedFile($fileFieldName, $dataport) {
        if($dataport['sourcetype'] === 'pimcore') {
            $importFileName = $_POST[$fileFieldName];
            if($importFileName === 'SQL') {
                $importFileName = '';
            }
            return $importFileName;
        }

		$importFileName = null;
		if (!array_key_exists($fileFieldName, $_FILES)) {
			return null;
		}

        if (!empty($_FILES[$fileFieldName]['name']) && empty($_FILES[$fileFieldName]['tmp_name'])) {
            throw new \InvalidArgumentException(sprintf($this->translator->trans('pim.manual.importForm.file_too_large', [], 'admin'), formatBytes(UploadedFile::getMaxFilesize())));
        }

		if (!empty($_FILES[$fileFieldName]['tmp_name'])) {
			// Hochgeladene Datei importieren
			$fileData = $_FILES[$fileFieldName];

			if (!array_key_exists('tmp_name', $fileData) || $fileData['error'] == true) {
				return null;
			}

			$importFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$dataport['id'].'_'.$_FILES[$fileFieldName]['name'];

			if (!move_uploaded_file($fileData['tmp_name'], $importFileName)) {
				return null;
			}
		}

		return $importFileName;
	}

    /**
     * @Route("/cancel/{statusKey}", methods={"DELETE"})
     */
    public function cancelImportAction($statusKey) {
        $response = [
            'success' => true
        ];

        if(strpos($statusKey, 'queue-') === 0) {
            $queue = Queue::getInstance();
            $queue->delete(substr($statusKey, strlen('queue-')));
        } else {
            $status = ImportStatus::getInstance();

            $runGotAborted = $status->update(
                [
                    'status' => ImportStatus::STATUS_ABORTED,
                    'endDate' => new \DateTime()
                ],
                [
                    'key' => $statusKey,
                    'status' => ImportStatus::STATUS_RUNNING
                ]
            );

            if ($runGotAborted) {
                $statusData = $status->findOne(['`key` = ?' => $statusKey]);

                if ($statusData) {
                    $status->execute('DELETE FROM '.Installer::TABLE_QUEUE.' WHERE worker_id=? AND started_at IS NOT NULL LIMIT 1', [$statusData['dataport_id']]);

                    if ($statusData['pid']) {
                        $phpProcesses = Cli::exec('ps -ww -C '.basename(Cli::getPhpCli()).' -o user=,pid=,args=');

                        foreach (explode(PHP_EOL, $phpProcesses) as $process) {
                            $processParts = explode(' ', preg_replace('/\s{2,}/', ' ', trim($process)));

                            if (count($processParts) > 3 && $processParts[0] == get_current_user() && $processParts[1] == $statusData['pid']) {
                                $cmd = implode(' ', array_slice($processParts, 2));
                                if (preg_match('/(data-bridge):(complete|process|extract)\s+'.$statusData['dataport_id'].'/', $cmd)) {
                                    Cli::exec('kill '.$statusData['pid']);

                                    for($remainingWaitTime = 5; $remainingWaitTime > 0; $remainingWaitTime--) {
                                        $processStillRunning = Cli::exec('ps '.$statusData['pid'].' -o pid=');
                                        if(!$processStillRunning) {
                                            break;
                                        }
                                        sleep(1);
                                    }

                                    if($processStillRunning) {
                                        Cli::exec('kill -9 '.$statusData['pid']);
                                    }

                                    $fileObject = new FileObject('', $statusData['dataport_id'].'/'.$statusKey);
                                    $logger = OpenDxp::getContainer()->get('pim.logger');
                                    $logger->setLogFileObject($fileObject);
                                    $logger->info('Aborted by '.Tool\Admin::getCurrentUser()->getUsername().' at '.date('Y-m-d H:i:s'));
                                }
                            }
                        }
                    }
                }
            }
        }


        return new JsonResponse($response);
    }

    /**
     * @Route("/get-element-ids/{rawItemId}", methods={"GET"})
     */
    public function getElementIds($rawItemId, Request $request) {
        $rawItemId = Uuid::fromInteger($rawItemId)->getBytes();
        $dataportData = PimcoreDbRepository::getInstance()->findRowInSql('SELECT dataportId, resource FROM '.Installer::TABLE_DATAPORT_RESOURCE.' dataport_resource INNER JOIN '.Installer::TABLE_RAWITEM.' rawitem ON dataport_resource.id=rawitem.dataport_resource_id WHERE rawitem.id = ?', [$rawItemId]);
        if (!$dataportData) {
            return new JsonResponse([
                'elementIDs' => []
            ]);
        }
        $dataportId = $dataportData['dataportId'];
        $resourceParameters = \json_decode($dataportData['resource'], true);
        foreach ($resourceParameters['parameters'] ?? [] as $dataportResourceParameterName => $dataportResourceParameterValue) {
            $_ENV[$dataportResourceParameterName] = $dataportResourceParameterValue;
        }

        $dataport = Dataport::getInstance()->get($dataportId);
        $this->importer->setDataport($dataport);

        Helper::getInstance()->runInitFunction($dataport);

        $mappings = $this->importer->getMappings();
        $keyMappings = [];
        foreach ($mappings as $row) {
            if (!empty($row['keyMapping'])) {
                $keyMappings[] = $row;
            }
        }

        if (empty($keyMappings)) {
            $sourceConfig = $dataport['sourceconfig'];
            foreach ($sourceConfig['fields'] as $fieldIndex => $field) {
                $field['parameters'] = str_replace('.:.:.', '', $field['parameters']);
                if (!empty($field['exportKey']) && strpos($field['parameters'], ':') === false) {
                    $fieldParts = explode('#', $field['parameters']);
                    $keyMappings[] = [
                        'dataportId' => $dataportId,
                        'fieldName' => strtolower($fieldParts[0]),
                        'locale' => $fieldParts[1] ?? '',
                        'fieldNo' => str_replace('field_', '', $fieldIndex),
                        'keyMapping' => 1,
                        'calculation' => '',
                        'brickName' => '',
                        'targetBrickField' => null
                    ];
                }
            }
        }

        $sourceConfig = $dataport['sourceconfig'];

        $rawItemDataRepository = RawItemData::getInstance();
        $rawItemDataRows = $rawItemDataRepository->find([
            'rawItemId = ?' => $rawItemId,
        ]);
        $rawItemData = array();
        foreach ($rawItemDataRows as $row) {
            if (isset($sourceConfig['fields']['field_'.$row['fieldNo']]['multiValues']) && $sourceConfig['fields']['field_'.$row['fieldNo']]['multiValues'] === true) {
                $row['value'] = unserialize($row['value'], ['allowed_classes' => true]);
            } elseif (!is_numeric($row['value'])) {
                $decodedValue = json_decode($row['value'], true);
                if (json_last_error() === \JSON_ERROR_NONE) {
                    $row['value'] = $decodedValue;
                }
            }

            $rawItemData['field_' . $row['fieldNo']] = $row;
        }

        $fieldTable = RawItemField::getInstance();
        $existingFields = $fieldTable->find(array('dataportId = ?' => $dataportId), 'priority');
        foreach ($existingFields as $field) {
            // add dummy data if parser cannot find data field
            if (!array_key_exists('field_' . $field['fieldNo'], $rawItemData)) {
                $rawItemData['field_' . $field['fieldNo']] = array(
                    'value' => null,
                );
            }

            foreach ($rawItemDataRows as $row) {
                if($field['fieldNo'] == $row['fieldNo']) {
                    $rawItemData[$field['name']] = $row;
                    continue 2;
                }
            }

            // add dummy data if parser cannot find data field
            $rawItemData['field_'.$field['fieldNo']] = [
                'rawItemId' => $rawItemId,
                'fieldNo' => $field['fieldNo'],
                'value' => ''
            ];
            $rawItemData[$field['name']] = $rawItemData['field_'.$field['fieldNo']];
        }

        $targetConfig = $dataport['targetconfig'];
        if(empty($targetConfig['itemClass'])) {
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
        } else {
            $itemMold = $this->itemMoldBuilder->getItemMold($dataportId);
        }

        if($itemMold instanceof Concrete) {
            $class = $itemMold->getClass();
        } else {
            $class = $itemMold;
        }

        $transfer = new \stdClass();

        $virtualFields = [];

        if ($itemMold !== null && !$itemMold instanceof Export && $keyMappings) {
            $lastKeyMapping = end($keyMappings)['fieldName'];

            $targetconfig = $dataport['targetconfig'];

            foreach ($mappings as $mapping) {
                if (strpos($mapping['fieldName'], '__virtual_') === 0) {
                    $value = $rawItemData['field_'.$mapping['fieldNo']]['value'] ?? null;

                    if ($mapping['calculation'] && CallbackFunction::isEngineAvailable($targetconfig['javascriptEngine'])) {
                        $jsParams = [
                            'rawItemData' => $rawItemData,
                            'value' => $value,
                            'virtualFields' => $virtualFields,
                            'field' => $mapping['fieldName'],
                            'logger' => new NullLogger(),
                            'request' => $request ?? Helper::getRequest(),
                            'transfer' => $transfer ?? new stdClass()
                        ];

                        if (isset($mapping['locale'])) {
                            $jsParams['locale'] = $mapping['locale'];
                        }

                        $value = CallbackFunction::evaluateScript($mapping['calculation'], $targetconfig['javascriptEngine'], $jsParams);

                        $value = $this->importer->map($mapping, $value, null, new Data\CalculatedValue());
                    }

                    $virtualFields[\Sylphen\DataBridgeBundle\lib\Pim\Helper::getFieldKey($mapping)] = $value;
                }

                if ($mapping['fieldName'] === $lastKeyMapping) {
                    break;
                }
            }
        }

        $elementIDs = [[]];
        $keyValueDatasets = $this->importer->getKeyDatasets($keyMappings, $rawItemData, $transfer, $virtualFields);
        foreach($keyValueDatasets as $keyIndex => $keyValues) {
            if (count(array_filter($keyValues)) === 0) {
                continue;
            }

            $conditions = $this->importer->getKeyConditions($keyValues);
            try {
                if ($conditions === null) {
                    continue;
                }

                /** @var Listing|Asset\Listing|Document\Listing $list */
                $list = $itemMold::getList([
                    'unpublished' => true,
                    'objectTypes' => [
                        AbstractObject::OBJECT_TYPE_OBJECT,
                        AbstractObject::OBJECT_TYPE_VARIANT,
                    ],
                    'locale' => Tool::getDefaultLanguage()
                ]);

                foreach($conditions as $column => $keyValue) {
                    if(!is_array($keyValue) || !isset($keyValue[0])) {
                        $keyValue = [$keyValue];
                    }

                    foreach($keyValue as $keyValueItem) {
                        if (strpos($column, '/') !== false) {
                            $columnParts = explode('/', $column);
                            /** @var AbstractData $brick */
                            $brick = \OpenDxp::getContainer()->get('opendxp.model.factory')->build("\\OpenDxp\\Model\\DataObject\\Objectbrick\\Data\\".ucfirst($columnParts[0]), [$itemMold]);
                            $fieldDefinition = Importer::getFieldDefinition($brick, ['fieldName' => $columnParts[1]]);
                            $list->addObjectBrick($brick->getType());
                            $column = $columnParts[1];
                        } else {
                            $fieldDefinition = Importer::getFieldDefinition($class, $column);
                        }

                        addListCondition:
                        if ($fieldDefinition instanceof Data\ManyToOneRelation && $keyValueItem instanceof ElementInterface) {
                            $list->addConditionParam('`'.$column.'__id`=?', $keyValueItem->getId());
                            $list->addConditionParam('`'.$column.'__type`=?', \OpenDxp\Model\Element\Service::getElementType($keyValueItem));
                        } elseif ($fieldDefinition instanceof Data\Relations\AbstractRelations && $keyValueItem instanceof ElementInterface) {
                            try {
                                $list->addConditionParam(
                                    (OpenDxp\Model\Element\Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (SELECT src_id FROM object_relations_'.$itemMold->getClassId().' WHERE dest_id = '.$keyValueItem->getId().' AND type = '.Db::get()->quote(\OpenDxp\Model\Element\Service::getElementType($keyValueItem)).' AND ownertype = '.Db::get()->quote(OpenDxp\Model\Element\Service::getElementType($itemMold)).' AND fieldname = '.Db::get()->quote($fieldDefinition->getName()).')'
                                );
                            } catch (Throwable $e) {
                                if ($fieldDefinition instanceof Data\ManyToManyRelation) {
                                    $list->addConditionParam("`$column` LIKE '%,".\OpenDxp\Model\Element\Service::getElementType($keyValueItem).'|'.$keyValueItem->getId().",%'");
                                } else {
                                    $list->addConditionParam("`$column` LIKE '%,".$keyValueItem->getId().",%'");
                                }
                            }
                        } elseif ($fieldDefinition instanceof Data\Multiselect) {
                            $list->addConditionParam("`$column` LIKE '%,".$keyValueItem.",%'");
                        } elseif ($fieldDefinition instanceof Data\BooleanSelect) {
                            $list->addConditionParam("`$column` = ?", $keyValueItem ? 1 : -1);
                        } elseif ($fieldDefinition instanceof Data\QuantityValue) {
                            /** @var QuantityValue $keyValueItem */
                            $list->addConditionParam('`'.$column.'__value` = ?', $keyValueItem->getValue());
                            $list->addConditionParam('`'.$column.'__unit` = ?', $keyValueItem->getUnitId());
                        } elseif ($fieldDefinition instanceof Date) {
                            /** @var DateTimeInterface $keyValueItem */
                            if ($fieldDefinition->getColumnType() === 'date') {
                                $list->addConditionParam('`'.$column.'` = ?', $keyValueItem->format('Y-m-d'));
                            } else {
                                $dateRangeStart = clone $keyValueItem;
                                $dateRangeEnd = clone $keyValueItem;
                                $dateRangeStart->setTime(0, 0);
                                $dateRangeEnd->setTime(23, 59, 59);

                                $list->addConditionParam('`'.$column.'` BETWEEN ? AND ?', [$dateRangeStart->getTimestamp(), $dateRangeEnd->getTimestamp()]);
                            }
                        } elseif (is_scalar($keyValueItem)) {
                            if (@preg_match($keyValueItem, '') === false) {
                                $list->addConditionParam("`$column` = ?", $keyValueItem);
                            } else {
                                $list->addConditionParam("`$column` REGEXP ?", substr($keyValueItem, 1, -1));
                            }
                        } elseif (is_array($keyValueItem) && (isset($keyValue['id']) || isset($keyValue['type']) || isset($keyValue['fullpath']))) {
                            $keyValueProposal = null;
                            if (isset($keyValue['id'], $keyValue['type'])) {
                                $keyValueProposal = OpenDxp\Model\Element\Service::getElementById($keyValue['type'], $keyValue['id']);
                            } elseif (isset($keyValue['fullpath'], $keyValue['type'])) {
                                $keyValueProposal = OpenDxp\Model\Element\Service::getElementByPath($keyValue['type'], $keyValue['fullpath']);
                            }

                            if ($keyValueProposal) {
                                $keyValueItem = $keyValueProposal;
                                goto addListCondition;
                            }
                        } elseif ($fieldDefinition->getName() === 'Complete Object') {
                            $objectExistsQuery = 'SELECT 1 
                                FROM '.Service::getElementType($itemMold).'s 
                                WHERE '.(Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?';

                            $objectExistsParams = [$keyValueItem['id']];

                            if(Service::getElementType($itemMold) === 'object') {
                                $objectExistsQuery .= 'AND '.Helper::prefixObjectSystemColumn('className').' = ?';
                                $objectExistsParams[] = $keyValueItem['className'];
                            }

                            $objectExists = PimcoreDbRepository::getInstance()->findOneInSql($objectExistsQuery, $objectExistsParams);
                            if ($objectExists) {
                                $list->addConditionParam((Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' = ?', $keyValueItem['id']);
                            } else {
                                $list->addConditionParam((Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' = ?', $keyValueItem['path']);
                                $list->addConditionParam('`'.(Service::getElementType($itemMold) === 'object' ? Helper::prefixObjectSystemColumn('key') : 'key').'` = ?', $keyValueItem['key']);
                            }
                        }
                    }
                }

                $elementIDs[] = $list->loadIdList();
            } catch(\Throwable $e) {
                return new JsonResponse(['error' => 'Error while querying elements: '.$e]);
                continue;
            }
        }

        return new JsonResponse(['elementIDs' => array_merge(...$elementIDs), 'elementType' => \OpenDxp\Model\Element\Service::getElementType($itemMold)]);
    }

    private function guardPermission(array $dataport) {
        $targetConfig = $dataport['targetconfig'];

        if (empty($targetConfig['itemClass'])) {
            $sourceConfig = $dataport['sourceconfig'];

            // for object wizard this delivers object of class \OpenDxp\Model\DataObject\Concrete\Export
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
        } else {
            $itemMold = $this->itemMoldBuilder->getItemMoldByClassId($targetConfig['itemClass']);
        }

        if ($dataport['sourcetype'] === 'report' && !Tool\Admin::getCurrentUser()->isAllowed('reports')) {
            throw new AccessDeniedHttpException();
        }

        $targetTypePermission = 'objects';
        if ($itemMold !== null) {
            $targetTypePermission = \OpenDxp\Model\Element\Service::getElementType($itemMold) . 's';
        }

        if(!Tool\Admin::getCurrentUser()->isAllowed($targetTypePermission)) {
            throw new AccessDeniedHttpException();
        }

        $user = Tool\Admin::getCurrentUser();
        $targetClassAllowed = $itemMold === null || $itemMold instanceof Export ||
            ($itemMold instanceof Concrete && $user->isAllowed($itemMold->getClassId(), 'class')) ||
            ($itemMold instanceof PageSnippet && $user->isAllowed($itemMold->getType(), 'docType')) ||
            ($itemMold instanceof Asset && $user->isAllowed($targetTypePermission));

        if (!$targetClassAllowed) {
            throw new AccessDeniedHttpException(
                'User ' . Tool\Admin::getCurrentUser()->getName() . ' attempted to access element of type ' . get_class($itemMold) . ', but has no permission to do so'
            );
        }
    }

    public static function getSubscribedServices(): array
    {
        $services = parent::getSubscribedServices();
        $services[CleanupImportStatus::class] = CleanupImportStatus::class;
        return $services;
    }

    /**
     * @Route("/do-export", methods={"POST"})
     *
     * @param Request $request
     * @param LocaleServiceInterface $localeService
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function doExportAction(Request $request)
    {
        $fileHandle = \OpenDxp\File::getValidFilename($request->get('fileHandle'));
        $ids = $request->get('ids');

        $fp = fopen(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/'.$fileHandle.'.csv', 'ab');

        if($request->get('initial')) {
            fputcsv($fp, ['id'], ';');
        }

        foreach($ids as $id) {
            fputcsv($fp, [$id], ';');
        }

        fclose($fp);

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/do-nothing", methods={"POST"})
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function doExportGenericAction(Request $request)
    {
        if($request->get('ids')) {
            $storage = Helper::getTempStorage();
            $fileContent = '';
            try {
                $fileContent = $storage->read($request->get('fileHandle').'.csv');
                if ($fileContent) {
                    $fileContent .= ',';
                }
            } catch(FilesystemException $e) {}
            $storage->write($request->get('fileHandle').'.csv', $fileContent.implode(',', $request->get('ids')));
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/grid-import-prepare", methods={"POST"})
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function gridImportPrepareAction(Request $request)
    {
        $importFile = $request->files->get('importfile');

        if($importFile && \file_exists($importFile->getRealPath())) {
            $tmpFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/temp-dd-' . uniqid();
            copy($importFile->getRealPath(), $tmpFileName);

            $config = [
                'name' => 'grid-import '.uniqid(),
                'sourcetype' => 'excel',
                'sourceconfig' => ImportconfigController::$sourceconfigDefaults['excel'],
                'targetconfig' => ImportconfigController::$targetconfigDefaults,
            ];

            $config['sourceconfig']['hasHeader'] = true;
            $config['sourceconfig']['file'] = $tmpFileName;

            $config['targetconfig']['itemClass'] = $request->get('classId');
            $config['targetconfig']['mode'] = ImportconfigController::MODE_EDIT;

            $config['sourceconfig'] = json_encode($config['sourceconfig']);
            $config['targetconfig'] = json_encode($config['targetconfig']);

            $dataport = Dataport::getInstance()->create($config);

            $parser = new NaiveParser($tmpFileName, ['sourceType' => $dataport['sourcetype'], 'hasHeader' => true]);
            $dataport['sourceconfig']['fields'] = [];
            $rawItemFields = [];

            foreach(($parser->guessConfig()['fields'] ?? []) as $fieldNo => $rawItemFieldConfig) {
                $fieldNo++;
                $key = 'field_'.$fieldNo;

                $rawItemFields[$key] = [
                    'dataportId' => $dataport['id'],
                    'fieldNo' => $fieldNo,
                    'name' => $rawItemFieldConfig['name'],
                    'priority' => $fieldNo
                ];

                $dataport['sourceconfig']['fields'][$key] = ['column' => $rawItemFieldConfig['column']];
            }


            $sourceConfig = $dataport['sourceconfig'];
            $targetConfig = $dataport['targetconfig'];
            $dataport['sourceconfig'] = json_encode($dataport['sourceconfig']);
            $dataport['targetconfig'] = json_encode($dataport['targetconfig']);

            Dataport::getInstance()->update($dataport, ['id' => $dataport['id']]);
            $dataport['sourceconfig'] = $sourceConfig;
            $dataport['targetconfig'] = $targetConfig;

            $fieldTable = RawItemField::getInstance();
            foreach ($rawItemFields as $field) {
                $fieldTable->createOrUpdate($field);
            }

            $mappingTable = Fieldmapping::getInstance();

            $targetFields = array_map(
                function($mapping) {
                    $mapping['fieldName'] = $mapping['attributeKey'];
                    return $mapping;
                }, Helper::getInstance()->createFieldMappings($dataport)
            );

            $mappingProposals = MappingconfigController::getMappingProposals($dataport['id'], $request->get('locale'));

            foreach($targetFields as $targetField) {
                if(in_array($targetField['attributeKey'], array_keys($mappingProposals))) {
                    $attributeName = $targetField['attributeKey'];
                    $attributeLanguage = null;

                    if (strpos($attributeName, '#') !== false) {
                        $parts = explode('#', $attributeName);
                        $attributeName = $parts[0];
                        $attributeLanguage = $parts[1];
                    }

                    $mappingTable->createOrUpdate(array(
                        'dataportId' => $dataport['id'],
                        'fieldName' => $attributeName,
                        'fieldNo' => $mappingProposals[$targetField['attributeKey']]['field'],
                        'calculation' => $mappingProposals[$targetField['attributeKey']]['calculation'],
                        'keyMapping' => $mappingProposals[$targetField['attributeKey']]['keyField'] ? 1 : 0,
                        'locale' => $attributeLanguage ?? '',
                        'brickName' => $targetField['brickName'] ?? '',
                        'targetBrickField' => $targetField['targetBrickField']
                    ));
                }
            }

            $runUrl = \OpenDxp::getContainer()->get('router')->generate(
                'dataport_import', ['dataportId' => urlencode($dataport['name']), 'force' => 1, 'async' => 1, 'dry-run' => $request->get('dry-run', 0)]
            );
            return new JsonResponse(['success' => true, 'dataportId' => $dataport['id'], 'runUrl' => $runUrl]);
        }
        return new JsonResponse(['success' => false, 'message' => 'Could not find uploaded file']);
    }

    /**
     * @Route("/grid-import", methods={"POST"})
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function gridImportAction(Request $request)
    {
        return new JsonResponse(['success' => true, 'jobs' => []]);
    }

    /**
     * @Route("/start-queue-processing", methods={"GET"}, name="start-queue-processor")
     * @param Request $request
     *
     * @return Response
     */
    public function startQueueProcessingAction(Request $request)
    {
        Queue::startQueueProcessor();
        return $this->queueProcessorMonitorAction($request);
    }

    /**
     * @Route("/queue-processor-monitor", methods={"GET"})
     *
     * @param Request $request
     * @return Response
     */
    public function queueProcessorMonitorAction(Request $request) {
        $progress = '';
        if(file_exists(OPENDXP_LOG_DIRECTORY.'/data-bridge-queue-processor.html')) {
            $progress = file_get_contents(OPENDXP_LOG_DIRECTORY.'/data-bridge-queue-processor.html');
        }

        return $this->render('@SylphenDataBridge/Import/queue-processor-monitor.html.twig', ['progress' => $progress]);
    }

    /**
     * @Route("/queue-processor-monitor-ajax", methods={"GET"})
     *
     * @param Request $request
     * @return Response
     */
    public function queueProcessorMonitorAjaxAction(Request $request)
    {
        $progress = '';
        if (file_exists(OPENDXP_LOG_DIRECTORY.'/data-bridge-queue-processor.html')) {
            $progress = trim(file_get_contents(OPENDXP_LOG_DIRECTORY.'/data-bridge-queue-processor.html'));
        }
        return new JsonResponse(['progress' => $progress]);
    }

    /**
     * @Route("/has-element-changed", methods={"GET", "POST"})
     *
     * @param Request $request
     * @return Response
     */
    public function hasElementChangedAction(Request $request) {
        $elements = json_decode($request->get('elements'), true);

        if(is_array($elements)) {
            $groupedElements = [];
            foreach ($elements as $element) {
                $groupedElements[$element['type']][] = $element['id'];
            }
        }

        $result = [[]];
        foreach($groupedElements as $elementType => $elementIds) {
            $keyColumn = 'key';
            if ($elementType === 'object') {
                $keyColumn = Helper::prefixObjectSystemColumn('key');
            } elseif ($elementType === 'asset') {
                $keyColumn = 'filename';
            } elseif ($elementType === 'document') {
                $keyColumn = 'key';
            }
            $elementModificationDates = PimcoreDbRepository::getInstance()->findInSql('
                SELECT '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id, '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('modificationDate') : 'modificationDate').' AS modificationDate, `'.$keyColumn.'` AS `key`, '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('parentId') : 'parentId').' AS parentId
                FROM '.$elementType.'s 
                WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (?)',
                [$elementIds]
            );

            $result[] = array_map(static function ($elementModificationDate) use ($elementType) {
                $elementModificationDate['type'] = $elementType;
                return $elementModificationDate;
            }, $elementModificationDates);
        }

        $result = array_merge(...$result);

        return new JsonResponse(['success' => true, 'elements' => $result]);
    }

    /**
     * @Route("/generic-grid-export", methods={"POST"})
     */
    public function genericGridExportAction(Request $request) {
        $fields = json_decode($request->get('fields'), true);

        $columns = [];
        foreach($fields as $index => $field) {
            $columns[$field['key']] = ['name' => $field['key'], 'position' => $index, 'fieldConfig' => $field];
        }

        $gridConfig = new GridConfig();
        $gridConfig->setType('object');
        $gridConfig->setClassId($request->get('classId'));
        $classDefinition = DataObject\ClassDefinition::getById($request->get('classId'));
        $gridConfig->setName('Ad-hoc export '.$classDefinition->getName());
        $gridConfig->setSearchType('data_bridge'); // dummy to prevent this from being shown on real object grid
        $gridConfig->setCreationDate(time());
        $gridConfig->setModificationDate(time());
        $gridConfig->setOwnerId(0);

        $ids = [];
        if (!empty($request->get('fileHandle')))  {
            $storage = Helper::getTempStorage();
            $ids = $storage->read($request->get('fileHandle').'.csv');
            $storage->delete($request->get('fileHandle').'.csv');
        }
        $gridConfig->setConfig(json_encode([
            'language' => 'en',
            'pageSize' => 25,
            'sortinfo' => false,
            'classId' => $request->get('classId'),
            'columns' => $columns,
            'ids' => $ids
        ]));
        $gridConfig->save();

        $dataports = Dataport::getInstance()->find(['sourcetype=?' => 'grid']);
        $dataport = null;
        foreach($dataports as $dataportCandidate) {
            $sourceConfig = $dataportCandidate['sourceconfig'];
            if($sourceConfig['file'] == $gridConfig->getId()) {
                $dataport = $dataportCandidate;
                break;
            }
        }

        if($dataport === null) {
            $config = [
                'name' => $gridConfig->getName().' '.uniqid(),
                'sourcetype' => 'grid',
                'sourceconfig' => ImportconfigController::$sourceconfigDefaults['grid'],
                'targetconfig' => ImportconfigController::$targetconfigDefaults,
            ];

            $config['sourceconfig']['file'] = $gridConfig->getId();

            $config['sourceconfig'] = json_encode($config['sourceconfig']);
            $config['targetconfig'] = json_encode($config['targetconfig']);

            $dataport = Dataport::getInstance()->create($config);

            $user = Tool\Admin::getCurrentUser();
            if ($user instanceof User) {
                $user->setPermission(Dataport::getConfigurationPermissionName($dataport['id']), true);
                $user->setPermission(Dataport::getExecutionPermissionName($dataport['id']), true);
                $user->save();
            }

            switch($request->get('type')) {
                case 'csv':
                    $calculation = '$separator = {{ CSV separator }} ?: \';\';
                    
if({{ CSV ENCODING }}) {
    $separator = iconv(\'UTF-8\', {{ CSV ENCODING }}, $separator);
}

$fields = array_filter(
    $params[\'rawItemData\']->asArray(),
    function($key) {
        return strpos($key, \'field_\') !== 0;
    }, ARRAY_FILTER_USE_KEY
);

$fields = array_keys($fields);

if(!$params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/csv\');
    $result = \'"\'.implode($separator, $fields)."\"\n";
    
    $result = \'\';
    if(!{{ CSV ENCODING }} || strtolower({{ CSV ENCODING }}) === \'utf-8\') {
        $result .= "\xEF\xBB\xBF";
    }
    
    $result .= \'"\'.implode(\'"\'.$separator.\'"\', $fields)."\"\n";
} else {
    $result = \'\';
}

$decodeValue = function($value) use ($params) {
    if(is_string($value) && !is_numeric($value)) {
        $decodedValue = json_decode($value, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            $value = $decodedValue;
        }
    }
    
    if(!is_scalar($value)) {
        if(is_array($value) && is_scalar(reset($value))) {
            $value = implode(\', \', $value);
        } else {
            $value = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
    }
    
    if({{ CSV ENCODING }}) {
        $value = iconv(\'UTF-8\', {{ CSV ENCODING }}, $value);
    }
    
    return \'"\'.str_replace(\'"\', \'""\', $value).\'"\';
};

$line = [];
foreach($fields as $field) {
    $value = $params[\'rawItemData\'][$field][\'value\'];

    $line[] = $decodeValue($value);
}
$result .= implode($separator, $line)."\n";

$params[\'response\']->addContent($result);';
                    break;
                case 'xml':
                    $calculation = 'if(!$params[\'response\']->hasContent()) {
    $params[\'response\']->headers->set(\'Content-Type\', \'text/xml\');
    $result = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>";
} else {
    $result = \'\';
}

$fields = array_filter(
    $params[\'rawItemData\']->asArray(),
    function($key) {
        return strpos($key, \'field_\') !== 0;
    }, ARRAY_FILTER_USE_KEY
);

$fields = array_keys($fields);

$writeTag = function($data) use (&$writeTag) {
    $result = \'\';
    if(is_array($data)) {
        foreach($data as $tagName => $valueItem) {
            if(is_numeric($tagName)) {
                $tagName = \'item\';
            }
            $result .= \'<\'.preg_replace(\'/[^\p{L}\p{Nd}]/u\', \'_\', $tagName).\'>\';
            if($valueItem instanceof \stdClass || is_array($valueItem)) {
                $result .= $writeTag((array)$valueItem);
            } else {
                $result .= \'<![CDATA[\'.$valueItem.\']]>\';
            }
            $result .= \'</\'.preg_replace(\'/[^\p{L}\p{Nd}]/u\', \'_\', $tagName).\'>\';
        }
    } else {
        $result .= \'<![CDATA[\'.$data.\']]>\';
    }

    return $result;
};

$result .= \'<item>\';
foreach($fields as $field) {
    $value = $params[\'rawItemData\'][$field][\'value\'];

    $decodedValue = json_decode($value);
    if (json_last_error() === \JSON_ERROR_NONE) {
        $value = $decodedValue;
    }

    $result .= \'<\'.preg_replace(\'/[^\p{L}\p{Nd}]/u\', \'_\', $field).\'>\';
    $result .= $writeTag($value);
    $result .= \'</\'.preg_replace(\'/[^\p{L}\p{Nd}]/u\', \'_\', $field).\'>\';
}
$result .= \'</item>\';

if($params[\'lastCall\']) {
    $result .= \'</result>\';
}
$params[\'response\']->addContent($result);';
                    break;
                case 'json':
                    $calculation = 'if(!isset($params[\'transfer\']->resultData)) {
    $params[\'response\']->headers->set(\'Content-Type\', \'application/json\');
    $params[\'transfer\']->resultData = [];
}

$item = array_filter(
    $params[\'rawItemData\']->asArray(),
    function($key) {
        return strpos($key, \'field_\') !== 0;
    }, ARRAY_FILTER_USE_KEY
);

$decodeValue = function($value) {
    if(is_string($value) && !is_numeric($value)) {
        $decodedValue = json_decode($value, true);
        if (json_last_error() === \JSON_ERROR_NONE) {
            $value = $decodedValue;
        }
    }
    
    return $value;
};

$data = array_map(function($field) use ($decodeValue) {
    return $decodeValue($field[\'value\']);
}, $item);

if($data) {
    $params[\'transfer\']->resultData[] = $data;
}

if($params[\'lastCall\']) {
    return json_encode($params[\'transfer\']->resultData);
}';
                    break;
            }

            Fieldmapping::getInstance()->createOrUpdate([
                'dataportId' => $dataport['id'],
                'fieldName' => '__result_callback',
                'locale' => '',
                'fieldNo' => null,
                'keyMapping' => 0,
                'format' => '',
                'calculation' => $calculation,
                'brickName' => '',
                'targetBrickField' => null
            ]);
        }

        $routeParams = ['dataportId' => urlencode($dataport['name']), 'force' => 1];
        $url = Helper::generateAbsoluteUrl('dataport_export', $routeParams);
        return new JsonResponse(['success'=> true, 'downloadUrl' => $url]);
    }

    /**
     * @Route("/object-wizard-layout/{dataportId}", methods={"GET"})
     */
    public function objectWizardLayoutAction($dataportId)
    {
        $dataport = (new Dataport())->get($dataportId);
        if(!$dataport) {
            return new JsonResponse(['success' => false, 'message' => 'dataport not found']);
        }

        $sourceConfig = $dataport['sourceconfig'];
        $targetConfig = $dataport['targetconfig'];

        foreach ($sourceConfig['fields'] as &$field) {
            $fieldDefinition = OpenDxp\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($field['definition'], $field['definition']['fieldtype']);
            if($fieldDefinition instanceof Data) {
                if (method_exists($fieldDefinition, 'enrichFieldDefinition')) {
                    try {
                        $fieldDefinition->enrichFieldDefinition([]);
                    } catch (\Throwable $e) {
                        Logger::warning('Could not enrich field "'.$fieldDefinition->getName().'". '.$e->getMessage());
                    }
                }

                if (method_exists($fieldDefinition, 'getClasses') && is_array($fieldDefinition->getClasses())) {
                    $fieldDefinitionClasses = $fieldDefinition->getClasses();
                    foreach ($fieldDefinitionClasses as &$classData) {
                        if (is_array($classData)) {
                            foreach ($classData as $key => &$className) {
                                if (is_array($className)) {
                                    $className = reset($className);
                                }
                            }
                            unset($className);
                        }
                    }
                    unset($classData);
                    $fieldDefinition->setClasses($fieldDefinitionClasses);
                }

                if (method_exists($fieldDefinition, 'enrichLayoutDefinition')) {
                    try {
                        $fieldDefinition->enrichLayoutDefinition(null);
                    } catch (\Throwable $e) {
                        Logger::warning('Could not enrich layout definition for "'.$fieldDefinition->getName().'". '.$e->getMessage());
                    }
                }
            }
            $field['definition'] = json_decode(json_encode($fieldDefinition), true);
        }
        unset($field);

        $objectId = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('id').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL LIMIT 1');

        return new JsonResponse(['success' => true, 'fields' => $sourceConfig['fields'], 'isExport' => empty($targetConfig['itemClass']), 'objectId' => $objectId]);
    }

    private static function getClassIdFromLog($class, $path, $dataportId) {
        $lastPart = strrpos($path, '/') + 1;
        $key = substr($path, $lastPart);
        $path = substr($path, 0, $lastPart);

        if ($class === 'object') {
            $class = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.Helper::prefixObjectSystemColumn('classId').' FROM objects WHERE `'.Helper::prefixObjectSystemColumn('path').'`=? AND `'.Helper::prefixObjectSystemColumn('key').'`=?', [$path, $key]);
            if (!$class) {
                $itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
                $class = $itemMoldBuilder->getItemMold($dataportId)->getClassId();
            }
        } elseif (!in_array($class, ['asset', 'document'], true)) {
            $classDefinition = DataObject\ClassDefinition::getByName($class);
            if ($classDefinition instanceof DataObject\ClassDefinition) {
                $class = $classDefinition->getId();
            }
        }

        return $class;
    }

    /**
     * @Route("/get-changed-elements/{statusKey}", methods={"GET"})
     */
    public function getChangedElementsAction($statusKey, Request $request)
    {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $statuses = PimcoreDbRepository::getInstance()->findInSql('SELECT dataport_id, doneItems, totalItems, `status`, `key`, startDate, importType
            FROM ' . Installer::TABLE_IMPORTSTATUS . ' status 
            WHERE status.key LIKE ?
            ORDER BY status.key DESC',
            [explode('-', $statusKey)[0].'%']
        );
        if(!$statuses) {
            $statuses = [['dataport_id' => null, 'doneItems' => 0, 'totalItems' => 0, 'status' => ImportStatus::STATUS_RUNNING, 'startDate' => date('Y-m-d H:i:s')]];
        }

        $result = [];

        $totalElementsCount = 0;
        $logFileLink = '';
        $responseFile = null;
        $returnData = [
            'success' => true,
            'changedElements' => [],
            'finished' => null,
            'doneItems' => 0,
            'totalItems' => 0,
            'totalElements' => 0,
            'finishedIn' => null,
            'logFile' => [],
            'responseFile' => null,
            'comment' => '',
        ];

        $search = $request->get('search');
        if(empty($search)) {
            $search = ['unchanged', 'changed'];
        }

        $errors = [];
        foreach($statuses as $status) {
            if(count($statuses) > 1 && substr($status['key'], -2) === '-1') {
                continue;
            }

            if (count($statuses) > 2 && substr($status['key'], -2) === '-2') {
                $returnData['totalItems'] = $status['totalItems'];
            }
            if ($status['dataport_id']) {
                $returnData['comment'] = $this->translator->trans('pim.manual.statusgrid.type.'.$status['importType'], [], 'admin', Helper::getUser()->getLanguage());

                $logFileObjectPath = $status['dataport_id'].'/'.$status['key'];
                if (!Helper::getApplicationLogStorage()->fileExists($logFileObjectPath) && Helper::getApplicationLogStorage()->fileExists($logFileObjectPath.'.gz')) {
                    $localGzFile = Helper::getTemporaryFileFromStream(Helper::getApplicationLogStorage()->readStream($logFileObjectPath.'.gz'));
                    $logFileObjectPath = \OPENDXP_SYSTEM_TEMP_DIRECTORY.'/import_'.$status['dataport_id'].'_'.$status['key'].'.log';
                    $logFileStream = fopen($logFileObjectPath, 'wb+');

                    $fileData = @gzopen($localGzFile, 'rb');
                    while (!feof($fileData)) {
                        fwrite($logFileStream, gzread($fileData, 1024));
                    }
                    rewind($logFileStream);

                    register_shutdown_function(static function() use ($logFileObjectPath, $logFileStream) {
                        fclose($logFileStream);
                        @unlink($logFileObjectPath);
                    });
                } else {
                    try {
                        $logFileStream = Helper::getApplicationLogStorage()->readStream($logFileObjectPath);
                    } catch(UnableToReadFile $e) {
                        $logFileStream = null;
                    }
                }

                $changedElements = [];
                if (is_resource($logFileStream)) {
                    try {
                        $multilineError = false;
                        $multilineValue = false;
                        $isAssetUpdated = false;

                        $currentObject = ['elementType' => null, 'elementId' => null, 'elementPath' => null];
                        while (!feof($logFileStream)) {
                            $line = fgets($logFileStream);

                            if(count($statuses) > 2 && strpos($line, 'to be executed in parallel') !== false) {
                                // parent process which queued parallelized child processes
                                continue 2;
                            }

                            if ($multilineValue && preg_match('/^\s*\[(INFO|DEBUG|WARNING|ERROR|ALERT|EMERGENCY|NOTICE)\]/S', $line)) {
                                $multilineValue = false;
                            }

                            if ($multilineError && preg_match('/^\s*\[(INFO|DEBUG|WARNING|ERROR|ALERT|EMERGENCY|NOTICE)\]/S', $line)) {
                                $multilineError = false;
                            }

                            if (preg_match('/Importing (\S+)( #(\d+))? (.+)/', $line, $match)) {
                                if (empty($match[3])) {
                                    $match[1] = self::getClassIdFromLog($match[1], $match[4], $status['dataport_id']);

                                    $path = $match[4];
                                    $lastPart = strrpos($path, '/') + 1;
                                    $key = substr($path, $lastPart);
                                    $path = substr($path, 0, $lastPart);

                                    $match[3] = PimcoreDbRepository::getInstance()->findOneInSql('SELECT '.(!in_array($match[1], ['asset', 'document'], true) ? Helper::prefixObjectSystemColumn('id') : 'id').' FROM '.(!in_array($match[1], ['asset', 'document'], true) ? 'object' : $match[1]).'s WHERE `'.(!in_array($match[1], ['asset', 'document'], true) ? Helper::prefixObjectSystemColumn('path') : 'path').'`=? AND `'.(!in_array($match[1], ['asset', 'document'], true) ? Helper::prefixObjectSystemColumn('key') : 'key').'`=?', [$path, $key]);
                                }

                                $isNew = false;
                                if(empty($match[3])) {
                                    $match[3] = $match[4];
                                    $isNew = true;
                                }

                                $changedElements[$match[1].$match[3]] = [
                                    'classId' => $match[1],
                                    'elementId' => $match[3],
                                    'elementType' => in_array($match[1], ['asset', 'document'], true) ? $match[1] : 'object',
                                    'fullpath' => $match[4],
                                    'isNew' => $isNew,
                                    'fields' => []
                                ];

                                $currentObject = ['elementType' => $match[1], 'elementId' => $match[3], 'elementPath' => $match[4]];

                                $isAssetUpdated = false;
                            } elseif (preg_match('/Successfully saved (\S+) #(\d+) (.+) \(version #(\d+)\)$/S', $line, $match)) {
                                $match[1] = self::getClassIdFromLog($match[1], $match[3], $status['dataport_id']);
                                $versionFound = false;
                                if(count($changedElements) < $request->get('start') + $request->get('limit') && $match[4]) {
                                    $changedData = (string)PimcoreDbRepository::getInstance()->findOneInSql('SELECT note FROM versions WHERE id=?', [$match[4]]);
                                    if ($changedData) {
                                        $versionFound = true;
                                        if(isset($changedElements[$match[1].$match[3]])) {
                                            $changedElements[$match[1].$match[2]] = $changedElements[$match[1].$match[3]];
                                            $changedElements[$match[1].$match[2]]['elementId'] = $match[2];
                                            $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                            unset($changedElements[$match[1].$match[3]]);
                                        }

                                        if (isset($changedElements[$match[1].$match[2]])) {
                                            $changedElements[$match[1].$match[2]]['fields'] = $this->getChangedFields($changedData);
                                            $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                        }
                                    }
                                }

                                if (!$versionFound) {
                                    $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                    $changedElements[$match[1].$match[2]]['elementId'] = $match[2];
                                }
                            } elseif (preg_match('/(\S+) (\/(.+)) queued for saving$/S', $line, $match)) {
                                $match[1] = self::getClassIdFromLog($match[1], $match[2], $status['dataport_id']);

                                if(!preg_match('/^\d+$/', $currentObject['elementId'])) {
                                    if(isset($changedElements[$match[1].$currentObject['elementId']])) {
                                        $changedElements[$match[1].$match[2]] = $changedElements[$match[1].$currentObject['elementId']];
                                        $changedElements[$match[1].$match[2]]['fullpath'] = $match[2];
                                        unset($changedElements[$match[1].$currentObject['elementId']]);
                                    }

                                    $currentObject['elementPath'] = $match[2];
                                    $currentObject['elementId'] = $match[2];
                                }
                            } elseif (preg_match('/Successfully created (\S+) #(\d+) (.+) \(version #(\d+)\)$/S', $line, $match)) {
                                $match[1] = self::getClassIdFromLog($match[1], $match[3], $status['dataport_id']);

                                $versionFound = false;
                                if (count($changedElements) < $request->get('start') + $request->get('limit') && $match[4]) {
                                    $changedData = (string)PimcoreDbRepository::getInstance()->findOneInSql('SELECT note FROM versions WHERE id=?', [$match[4]]);
                                    if ($changedData) {
                                        $versionFound = true;
                                        if (isset($changedElements[$match[1].$match[3]])) {
                                            $changedElements[$match[1].$match[2]] = $changedElements[$match[1].$match[3]];
                                            $changedElements[$match[1].$match[2]]['elementId'] = $match[2];
                                            $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                            unset($changedElements[$match[1].$match[3]]);
                                        }

                                        if (isset($changedElements[$match[1].$match[2]])) {
                                            $changedElements[$match[1].$match[2]]['fields'] = $this->getChangedFields($changedData);
                                            $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                        }
                                    }
                                }

                                if(!$versionFound) {
                                    if (isset($changedElements[$match[1].$match[3]])) {
                                        $changedElements[$match[1].$match[2]] = $changedElements[$match[1].$match[3]];
                                        $changedElements[$match[1].$match[2]]['elementId'] = $match[2];
                                        $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                        unset($changedElements[$match[1].$match[3]]);
                                    }

                                    $changedElements[$match[1].$match[2]]['elementId'] = $match[2];
                                    $changedElements[$match[1].$match[2]]['status'] = 'saved';
                                }
                            } elseif (!$isAssetUpdated && (preg_match('/Not saving (\S+) #(\d+) (.+) because/S', $line, $match) || preg_match('/Skipped (\S+) #(\d+) (.+) because/S', $line, $match))) {
                                $match[1] = self::getClassIdFromLog($match[1], $match[3], $status['dataport_id']);
                                $elementKey = $match[1].$match[2];
                                $ignoredFields = [];
                                foreach ($changedElements[$elementKey]['fields'] ?? [] as $existingField) {
                                    if (is_array($existingField) && isset($existingField['value']) && strpos($existingField['value'], ImportIgnoreData::IGNORED_VALUE_LOG_SUFFIX) !== false) {
                                        $ignoredFields[] = $existingField;
                                    }
                                }
                                $changedElements[$elementKey]['fields'] = $ignoredFields ?: [['field' => 'All', 'value' => 'unchanged']];
                                $changedElements[$elementKey]['status'] = 'skipped';
                            } elseif (strpos($line, 'Value for field __virtual_') === false && preg_match('/Value for field ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_#:.\/]*): (.*)/m', $line, $match)) {
                                $multilineValue = true;
                                $changedElements[$currentObject['elementType'].($currentObject['elementId'] ?: $currentObject['elementPath'])]['fields'][] = ['field' => $match[1], 'value' => $match[2]."\n"];
                            } elseif (preg_match('/^\s*\[(WARNING|ERROR|ALERT|EMERGENCY|NOTICE)\] (.*)/m', $line, $match)) {
                                $errors[] = ['type' => $match[1], 'message' => $match[2], 'elementPath' => $currentObject['elementPath'], 'elementType' => $currentObject['elementType']];
                                $multilineError = true;
                            } elseif (preg_match('/Executing command "(data-bridge:complete|data-bridge:extract|data-bridge:process) "(\d+).+?" \(Started at (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)/', $line, $match)) {
                                $dependentDataport = Dataport::getInstance()->get($match[2]);
                                if($dependentDataport) {
                                    $returnData['comment'] = 'Dataport '.$dependentDataport['id'].' '.$dependentDataport['name'];
                                    $dependentImportStatus = ImportStatus::getInstance()->findOne([
                                        'status = ?' => ImportStatus::STATUS_RUNNING,
                                        'dataport_id = ?' => $dependentDataport['id'],
                                        'startDate >= ?' => (new \DateTime($match[3]))->setTimezone(new DateTimeZone('UTC'))
                                    ], 'startDate DESC');

                                    if($dependentImportStatus) {
                                        $status['doneItems'] = $dependentImportStatus['doneItems'];
                                        $status['totalItems'] = $dependentImportStatus['totalItems'];
                                    }
                                }
                            } elseif (preg_match('/Asset "(.*)" \(ID: ([\d]+)\) has been updated: ([^\s]*) -> (.*)/', $line, $match)) {
                                Importer::setBeforeSaveChecksum((int)$match[2], $match[3]);
                                $isAssetUpdated = true;
                                $changedElements[$currentObject['elementType'] . ($currentObject['elementId'] ?: $currentObject['elementPath'])]['status'] = 'saved';
                            } elseif ($multilineValue) {
                                $changedElements[$currentObject['elementType'].($currentObject['elementId'] ?: $currentObject['elementPath'])]['fields'][count($changedElements[$currentObject['elementType'].($currentObject['elementId'] ?: $currentObject['elementPath'])]['fields']) - 1]['value'] .= $line;
                            } elseif ($multilineError) {
                                $errors[count($errors) - 1]['message'] .= "\n".$line;
                            }
                        }
                    } catch (UnableToReadFile $e) {
                    }
                }

                $changedElements = array_filter($changedElements, static function ($changedElement) use ($search) {
                    foreach ($search as $searchTerm) {
                        if(!isset($changedElement['elementId'])) {
                            return false;
                        }

                        if ($searchTerm === 'unchanged') {
                            if (isset($changedElement['status']) && $changedElement['status'] === 'skipped') {
                                return true;
                            }
                        } elseif ($searchTerm === 'changed') {
                            if (isset($changedElement['status']) && $changedElement['status'] === 'saved') {
                                return true;
                            }
                        } elseif ($searchTerm === 'new') {
                            return $changedElement['isNew'];
                        } elseif (stripos(json_encode($changedElement), $searchTerm) !== false) {
                            return true;
                        }
                    }

                    return false;
                });

                $totalElementsCount = count($changedElements);

                $changedElements = array_slice($changedElements, $request->get('start'), $request->get('limit'));

                foreach ($changedElements as &$changedElement) {
                    $changedData = '';
                    if (!isset($changedElement['fields'][0]['old'], $changedElement['fields'][0]['new'])) {
                        foreach ($changedElement['fields'] as $changedField) {
                            if ($changedField['value'] == 'unchanged') {
                                $currentValue = 'unchanged';
                            } else {
                                $element = Service::getElementById($changedElement['elementType'], $changedElement['elementId']);
                                $currentValue = null;
                                if ($element) {
                                    Importer::useBeforeSaveChecksum();
                                    $currentValue = Importer::getLogOutput(Importer::getValue($element, $changedField['field']));
                                    Importer::useCurrentChecksum();
                                }
                                if (rtrim($changedField['value'], "\n\r") === $currentValue) {
                                    $changedField['value'] = 'unchanged';
                                    $currentValue = 'unchanged';
                                }
                            }
                            $changedData .= $changedField['field'].': '.$currentValue.' -> '.$changedField['value']."\n";
                        }

                        $changedElement['fields'] = $this->getChangedFields('Dataport '.$status['dataport_id']."\n".$changedData);
                    }
                }
                unset($changedElement);

                foreach ($changedElements as $changedElement) {
                    foreach ($changedElement['fields'] ?: [['field' => null, 'old' => null, 'new' => null]] as $field) {
                        unset($changedElement['fields']);
                        if (isset($changedElement['fullpath'], $field['field'], $changedElement['classId'])) {
                            $ignoreImportHash = $this->importIgnoreData->computeHash([
                                'classId'  => $changedElement['classId'],
                                'path'     => $changedElement['fullpath'],
                                'field'    => $field['field'],
                                'value'    => $field['new'],
                                'objectId' => is_numeric($changedElement['elementId'] ?? '') ? $changedElement['elementId'] : null,
                            ]);
                            $changedElement['lock'] = $this->importIgnoreData->findOne(['hash = ?' => $ignoreImportHash]) ? 1 : 0;

                            preg_match('/^(?:([a-zA-Z_\x7f-\xff][a-zA-Z0-9_:.]*)\/)?([a-zA-Z_\x7f-\xff][a-zA-Z0-9_:.]*)(#[a-z]{2}(?:_[A-Z]{2})?)?$/', $field['field'], $matches);
                            $mapping = ['fieldName' => $matches[2] ?? null, 'locale' => $matches[3] ?? null, 'brickName' => $matches[1] ?? null];

                            $classDefinition = Helper::getClassDefinitionById($changedElement['classId']);
                            $fieldDefinition = $classDefinition->getFieldDefinition($mapping['fieldName']);

                            if($fieldDefinition instanceof Data && !empty($fieldDefinition->getTitle())) {
                                $changedElement['fieldName'] = $this->translator->trans($fieldDefinition->getTitle(), [], 'admin', Helper::getUser()->getLanguage());
                            } else {
                                if($mapping['fieldName'] === 'key') {
                                    $fieldName = 'pim.mapping.key';
                                } else {
                                    $fieldName = Helper::getFieldKey($mapping);
                                }

                                $changedElement['fieldName'] = $this->translator->trans($fieldName, [], 'admin', Helper::getUser()->getLanguage());
                            }

                            if(!empty($mapping['locale'])) {
                                $changedElement['fieldName'] .= $mapping['locale'];
                            }

                            if(!empty($mapping['brickName'])) {
                                $changedElement['fieldName'] = $this->translator->trans($mapping['brickName'], [], 'admin', Helper::getUser()->getLanguage()).' / '.$changedElement['fieldName'];
                            }

                            $result[] = array_merge($changedElement, $field);
                        }
                    }
                }

                $logFilePath = $status['dataport_id'].'/'.$status['key'];
                if (Helper::getApplicationLogStorage()->fileExists($logFilePath)) {
                    $logFileLink = $logFilePath;
                }

                $responseFile = Installer::getResultDocumentPath().'/result_'.$status['dataport_id'].'_'.explode('-', $statusKey)[0].'-2';
                if (!file_exists($responseFile)) {
                    $responseFile = Installer::getResultDocumentPath().'/result_'.$status['dataport_id'].'_'.explode('-', $statusKey)[0];
                }
                if(file_exists($responseFile)) {
                    $response = \unserialize(\file_get_contents($responseFile));
                    if (!$response->hasContent()) {
                        $responseFile = null;
                    }
                } else {
                    $responseFile = null;
                }
            }

            $returnData['changedElements'][] = $result;
            if($returnData['finished'] === null && (!$status['dataport_id'] || $status['status'] == ImportStatus::STATUS_RUNNING || substr($status['key'], -2) === '-1')) {
                $returnData['finished'] = false;
            }

            $returnData['doneItems'] += $status['doneItems'];
            if (count($statuses) <= 2) {
                $returnData['totalItems'] += $status['totalItems'];
            }

            $returnData['totalElements'] += $totalElementsCount;

            Carbon::setLocale(Tool\Admin::getCurrentUser()->getLanguage());
            $now = Carbon::now(new DateTimeZone('UTC'));
            $startDate = new Carbon($status['startDate'], new DateTimeZone('UTC'));

            $finishedIn = ($status['doneItems'] == 0 || $status['doneItems'] == $status['totalItems']) ? null : $now->addMilliseconds($now->diffInMilliseconds($startDate) / $status['doneItems'] * ($status['totalItems'] - $status['doneItems']));
            if($finishedIn !== null && ($returnData['finishedIn'] === null || $finishedIn > $returnData['finishedIn'])) {
                $returnData['finishedIn'] = $finishedIn;
            }

            $returnData['logFile'][] = $logFileLink;

            if($responseFile) {
                $returnData['responseFile'] = Helper::generateAbsoluteUrl(
                    'import_status',
                    ['autoRefresh' => 1, 'statusKey' => substr($status['key'], 0, -2), 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key'), 'n' => time()]
                );
            }
        }

        $returnData['changedElements'] = array_merge(...$returnData['changedElements']);
        $returnData['finished'] = $returnData['finished'] === null;

        $returnData['logFile'] = explode('-', reset($returnData['logFile']))[0];
        $returnData['finishedIn'] = $returnData['finishedIn'] ? $returnData['finishedIn']->diffForHumans(null, true) : null;

        if (count($errors) === 0 && $returnData['finished']) {
            $errors[] = ['elementPath' => '', 'elementType' => '', 'field' => '', 'type' => 'info', 'message' => $this->translator->trans('pim.restore_version.errors.none', [], 'admin', Helper::getUser()->getLanguage())];
        }
        $returnData['errors'] = $errors;

        if (count($returnData['changedElements']) === 0 && $returnData['finished']) {
            $returnData['changedElements'][] = [
                'lock' => 0,
                'elementId' => '',
                'fullpath' => $this->translator->trans('pim.manual.startimport.summary.changes.none', [], 'admin', Helper::getUser()->getLanguage()),
                'status' => '',
                'elementType' => '',
                'field' => '',
                'old' => '',
                'new' => '',
                'diff' => $this->translator->trans('pim.manual.startimport.summary.changes.none', [], 'admin', Helper::getUser()->getLanguage())
            ];
        }

        return new JsonResponse($returnData);
    }

    public function setImportIgnoreDataAction(Request $request): JsonResponse {
        $content = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $missingObjectIds = [];
        try {
            $isObjectIdUsed = $this->importIgnoreData->isObjectIdUsed();
            foreach ($content as $item) {
                if ( $item['new'] === 'unchanged' ) {
                    continue;
                }

                if (!empty($item['hash'])) {
                    $this->importIgnoreData->deleteWhere(['hash' => $item['hash']]);
                } else {
                    $data = [
                        'classId'  => $item['classId'],
                        'path'     => !$isObjectIdUsed ? $item['fullpath'] : null,
                        'objectId' => $isObjectIdUsed ? $item['elementId'] : null,
                        'field'    => $item['field'],
                        'value'    => $item['new'],
                    ];
                    $hash = $this->importIgnoreData->computeHash($data);
                    if (empty($item['lock'])) {
                        $this->importIgnoreData->deleteWhere(['hash' => $hash]);
                    } else {
                        try {
                            $this->importIgnoreData->createOrUpdate(['hash' => $hash] + $data);
                        } catch (ForeignKeyConstraintViolationException $e) {
                            $missingObjectIds[] = ['fullpath' => $item['fullpath'] ?? null, 'field' => $item['field'] ?? null];
                        }
                    }
                }
            }
            return new JsonResponse(['success' => true, 'missingObjectIds' => $missingObjectIds]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getIgnoredValuesAction(Request $request) {
        $itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);
        $itemMold = $itemMoldBuilder->getItemMold($request->get('dataport'));

        if ($itemMold instanceof Concrete) {
            $classId = $itemMold->getClassId();
        } else {
            $classId = OpenDxp\Model\Element\Service::getElementType($itemMold);
        }

        $ignoredValues = array_map(static function ($ignoredValue) {
            $ignoredValue['fullpath'] = $ignoredValue['path'];
            unset($ignoredValue['path']);
            return $ignoredValue;
        }, $this->importIgnoreData->find(['classId = ?' => $classId, 'field = ?' => $request->get('field')], 'path,value'));


        $total = count($ignoredValues);
        $ignoredValues = array_slice($ignoredValues, $request->get('start', 0), $request->get('limit', 25));

        return new JsonResponse(['success' => true, 'ignoredValues' => $ignoredValues, 'total' => $total]);
    }

    /**
     * @param $value1
     * @param $value2
     * @return string
     */
    private function getDiff($value1, $value2): string {
        switch (Tool\Admin::getCurrentUser()->getLanguage()) {
            case 'de':
                $language = 'deu';
                break;
            case 'fr':
                $language = 'fra';
                break;
            case 'it':
                $language = 'ita';
                break;
            case 'jp':
                $language = 'jpn';
                break;
            case 'ru':
                $language = 'rus';
                break;
            case 'es':
                $language = 'spa';
                break;
            case 'en':
            default:
                $language = 'eng';
        }

        return DiffHelper::calculate($value1, $value2, 'Combined', [
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => false,
        ], [
            // how detailed the rendered HTML in-line diff is? (none, line, word, char)
            'detailLevel' => (strpos($value1, "\n") === false || strpos($value2, "\n") === false ) ? 'none' : 'word',
            // renderer language: eng, cht, chs, jpn, ...
            // or an array which has the same keys with a language file
            'language' => $language,
            // show line numbers in HTML renderers
            'lineNumbers' => false,
            // show a separator between different diff hunks in HTML renderers
            'separateBlock' => false,
            // show the (table) header
            'showHeader' => false,
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
            'resultForIdenticals' => '<img src="/bundles/opendxpadmin/img/flat-color-icons/checkmark.svg" width="20">',
            // extra HTML classes added to the DOM of the diff container
            'wrapperClasses' => ['diff-wrapper summary'],
        ]);
    }

    private function getChangedFields($changedData) {
        $changedData = preg_replace('/Dataport: \d+/', '', $changedData);
        preg_match_all('/^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_#:.\/]*): ([\S\s]*?) -> (.*(?:\r?\n(?![a-zA-Z_\x7f-\xff][a-zA-Z0-9_#:.\/]*:).*)*)/m', $changedData, $changedFields, PREG_SET_ORDER);

        $diffs = [];
        foreach ($changedFields as $field) {
            $diffs[] = [
                'field' => $field[1],
                'old' => $field[2],
                'new' => rtrim($field[3], "\n\r"),
                'diff' => $this->getDiff($field[2], rtrim($field[3], "\n\r"))
            ];
        }

        return $diffs;
    }

    /**
     * @Route("/object-preview", name="data_bridge_object_preview", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     *
     */
    public function objectPreviewAction(Request $request)
    {
        DataObject::setDoNotRestoreKeyAndPath(true);

        $id = (int)$request->get('id');
        $object = Concrete::getById($id);
        Service::loadAllObjectFields($object);

        if (method_exists($object, 'getLocalizedFields')) {
            /** @var DataObject\Localizedfield $localizedFields */
            $localizedFields = $object->getLocalizedFields();

            if(method_exists($localizedFields, 'setLoadedAllLazyData')) {
                $localizedFields->setLoadedAllLazyData();
            }
        }

        DataObject::setDoNotRestoreKeyAndPath(false);

        if ($object) {
            if ($object->isAllowed('view')) {
                return $this->render(
                    '@SylphenDataBridge/object-preview.html.twig',
                    [
                        'object' => $object,
                        'versionNote' => '',
                        'validLanguages' => Tool::getValidLanguages(),
                    ]
                );
            }

            throw $this->createAccessDeniedException('Permission denied, data object #'.$id);
        }

        throw $this->createNotFoundException('Data object #'.$id." does not exist");
    }

    /**
     * @Route("/dependency-visualization", name="data_bridge_dependency_visualization", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function dependencyVisualizationAction(Request $request)
    {
        $id = (int)$request->get('id');
        $object = Concrete::getById($id);

        if ($object) {
            if ($object->isAllowed('view')) {
                $node = $this->getNodeVisualizationData($object);
                $node['font'] = ['multi' => true];
                $node['label'] = '<i>'.$node['label'].'</i>';

                $nodes = [$node];

                foreach ($object->getClass()->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof AbstractRelations || $fieldDefinition instanceof Data\ImageGallery) {
                        $getter = 'get'.ucfirst($fieldDefinition->getName());

                        $relationValue = $object->$getter();
                        if($relationValue && $fieldDefinition instanceof Data\ManyToOneRelation) {
                            $relationValue = [$relationValue];
                        } elseif($fieldDefinition instanceof Data\ImageGallery) {
                            $relationValue = $relationValue->getItems();
                        }

                        foreach ((array)$relationValue as $relatedItem) {
                            if($relatedItem instanceof DataObject\Data\ObjectMetadata || $relatedItem instanceof DataObject\Data\ElementMetadata) {
                                $relatedItem = $relatedItem->getElement();
                            } elseif($relatedItem instanceof DataObject\Data\Hotspotimage) {
                                $relatedItem = $relatedItem->getImage();
                            }
                            $node = $this->getNodeVisualizationData($relatedItem);
                            $node['from'] = $fieldDefinition->getName();
                            $nodes[] = $node;

                            if($relatedItem instanceof $object) {
                                $secondLevelRelationValue = $relatedItem->$getter();
                                if ($secondLevelRelationValue && $fieldDefinition instanceof Data\ManyToOneRelation) {
                                    $secondLevelRelationValue = [$secondLevelRelationValue];
                                }
                                foreach ((array)$secondLevelRelationValue as $secondLevelRelatedItem) {
                                    if ($secondLevelRelatedItem instanceof DataObject\Data\ObjectMetadata || $secondLevelRelatedItem instanceof DataObject\Data\ElementMetadata) {
                                        $secondLevelRelatedItem = $secondLevelRelatedItem->getElement();
                                    }
                                    $node = $this->getNodeVisualizationData($secondLevelRelatedItem);
                                    $node['from'] = $relatedItem->getId();
                                    $nodes[] = $node;
                                }
                            }
                        }

                        $nodes[] = [
                            'id' => $fieldDefinition->getName(),
                            'label' => $fieldDefinition->getTitle() ? $this->translator->trans($fieldDefinition->getTitle(), [], 'admin') : $fieldDefinition->getName(),
                            'color' => Helper::stringToColorCode($fieldDefinition->getName()),
                            'font' => ['color' => Helper::getContrastColor(Helper::stringToColorCode($fieldDefinition->getName()))],
                            'shape' => 'box',
                            'from' => $object->getId()
                        ];
                    }
                }


                $edges = [];
                foreach ($nodes as $node) {
                    if (isset($node['from'])) {
                        $edges[] = [
                            'from' => $node['from'],
                            'to' => $node['id'],
                        ];
                    }
                }

                $nodes = array_values(array_intersect_key($nodes, array_unique(array_column($nodes, 'id'))));

                $html = '
<html><body style="background:#fff;">
<div class="wrapper">
    <img class="search-icon" src="/bundles/opendxpadmin/img/flat-color-icons/search.svg" />
    <input class="search" id="nodeFilter" type="search" >
    <img class="clear-icon" src="/bundles/opendxpadmin/img/flat-color-icons/delete.svg" />
</div>
<div id="dependency-graph"></div>

<script type="text/javascript" src="/bundles/sylphendatabridge/vendor/vis-network/vis-network.min.js"></script>

<link rel="stylesheet" href="/bundles/sylphendatabridge/vendor/vis-network/vis-network.min.css" />
<link rel="stylesheet" href="/bundles/sylphendatabridge/css/vis-network.css" />
<script type="text/javascript">
    var nodes = new vis.DataSet('.json_encode($nodes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).');
    
    var edges = new vis.DataSet('.json_encode($edges).');
    
    var container = document.getElementById("dependency-graph");
    var options = {
        layout: {
            randomSeed: '.$object->getId().'
        },
        nodes: {
            margin: 12,
            shadow: true
        },
        edges: {
            shadow: true
        },
        interaction: {
            hover: true
        }
    };
    
    var network = new vis.Network(container, {
        nodes: nodes,
        edges: edges
    }, options);
    
    network.on("click", function(properties) {
        var ids = properties.nodes;
        var clickedNodes = nodes.get(ids);
        if (clickedNodes.length > 0 && clickedNodes[0]["id"] != 1 && parseInt(clickedNodes[0]["id"]) == clickedNodes[0]["id"]) {
            opendxp.helpers.openElement(clickedNodes[0]["id"], clickedNodes[0]["elementType"]);
        }
    }.bind(this));
    
    network.on("hoverNode", function(properties) {
        container.getElementsByTagName("canvas")[0].style.cursor = "pointer";
    }.bind(this));
    
    network.on("blurNode", function(properties) {
        container.getElementsByTagName("canvas")[0].style.cursor = "default";
    }.bind(this));
    
    document.getElementById("nodeFilter").addEventListener("change", (e) => {
        if(e.target.value === "") {
            network.unselectAll();
            return true;
        }
        var matchingNodeIds = [];
        nodes.forEach(function(node) {
            if(node.label.toLowerCase().indexOf(e.target.value.toLowerCase()) > -1) {
                matchingNodeIds.push(node.id);
            }
        });
        
        if(matchingNodeIds.length > 0) {
            network.selectNodes(matchingNodeIds);
            network.focus(matchingNodeIds[0]);
        } else {
            network.unselectAll();
        }
    });

    const clearIcon = document.querySelector(".clear-icon");
    const searchBar = document.querySelector(".search");
    
    document.querySelector(".search").addEventListener("keyup", function() {
      if(searchBar.value && clearIcon.style.visibility !== "visible"){
        clearIcon.style.visibility = "visible";
      } else if(!searchBar.value) {
        clearIcon.style.visibility = "hidden";
      }
    });
    
    clearIcon.addEventListener("click", function() {
      searchBar.value = "";
      clearIcon.style.visibility = "hidden";
    });
    
    setTimeout(function() {
        network.focus('.$object->getId().');
    }, 500);
</script>
</body></html>';
                return new Response($html);
            }

            throw $this->createAccessDeniedException('Permission denied, version id ['.$id.']');
        }

        throw $this->createNotFoundException('Version with id ['.$id."] doesn't exist");
    }

    /**
     * Asset preview thumbnails vs. static admin icon paths (strings) both end up in $image.
     *
     * @param string|Asset\Image\Thumbnail|null $image
     */
    private function resolveVisualizationImageUrl($image, string $fallback): string
    {
        if (is_string($image)) {
            return $image;
        }

        if ($image instanceof Asset\Image\Thumbnail) {
            return $image->getPath();
        }

        return $fallback;
    }

    private function getNodeVisualizationData(ElementInterface $object) {
        $image = null;
        if($object instanceof Asset) {
            if($object instanceof Asset\Image) {
                $image = $object->getThumbnail(Asset\Image\Thumbnail\Config::getPreviewConfig());
            }

            if($image === null) {
                switch(Helper::getFileExtension($object->getFilename())) {
                    case 'vr':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/vr.svg';
                        break;
                    case 'pdf':
                    case 'ps':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/pdf.svg';
                        break;
                    case 'xls':
                    case 'xlsx':
                    case 'csv':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/excel.svg';
                        break;
                    case 'ppt':
                    case 'pps':
                    case 'ppsx':
                    case 'pptx':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/powerpoint.svg';
                        break;
                    case 'txt':
                    case 'log':
                    case 'css':
                    case 'rtf':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/text.svg';
                        break;
                    case 'json':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/file-types.svg';
                        break;
                    case 'mpg':
                    case 'mpeg':
                    case 'divx':
                    case 'avi':
                    case 'mov':
                    case 'wmv':
                    case '3gp':
                    case 'f4v':
                    case 'flv':
                    case 'mp4':
                    case 'webm':
                    case 'video':
                    case 'vob':
                    case 'mkv':
                    case 'ogv':
                    case 'qt':
                    case 'm4v':
                    case 'm4p':
                    case 'asf':
                    case 'mp2':
                    case 'ts':
                    case 'mpv':
                    case 'mpe':
                    case 'mts':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/video_file.svg';
                        break;
                    case 'mp3':
                    case 'aac':
                    case 'flac':
                    case 'ogg':
                    case 'wav':
                    case 'wma':
                    case 'm4a':
                        $image = '/bundles/opendxpadmin/img/flat-color-icons/audio_file.svg';
                        break;
                }
            }

            return [
                'id' => $object->getId(),
                'label' => $object->getKey(),
                'image' => $this->resolveVisualizationImageUrl(
                    $image,
                    '/bundles/opendxpadmin/img/flat-color-icons/asset.svg'
                ),
                'color' => Helper::stringToColorCode($object->getPath()),
                'shape' => 'circularImage',
                'elementType' => 'asset'
            ];
        }

        if ($object instanceof PageSnippet) {
            return [
                'id' => $object->getId(),
                'label' => $object->getKey(),
                'image' => '/bundles/opendxpadmin/img/flat-color-icons/document.svg',
                'color' => Helper::stringToColorCode($object->getPath()),
                'shape' => 'circularImage',
                'elementType' => 'document'
            ];
        }

        $classDefinition = $object->getClass();
        $color = Helper::stringToColorCode($classDefinition->getName());

        foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
            if ($fieldDefinition instanceof Data\Image) {
                $imageGetter = 'get'.ucfirst($fieldDefinition->getName());
                $image = $object->$imageGetter();
                if($image instanceof Asset\Image) {
                    $image = $image->getThumbnail(Asset\Image\Thumbnail\Config::getPreviewConfig());
                }
            }
        }

        if($image === null) {
            foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof Data\ImageGallery) {
                    $imageGetter = 'get'.ucfirst($fieldDefinition->getName());
                    $images = $object->$imageGetter();
                    $image = $images->getItems()[0] ?? null;
                    if ($image instanceof DataObject\Data\Hotspotimage) {
                        $image = $image->getImage();
                        if($image instanceof Asset\Image) {
                            $image = $image->getThumbnail(Asset\Image\Thumbnail\Config::getPreviewConfig());
                        }
                    }
                }
            }
        }

        if ($image === null) {
            foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof Data\Hotspotimage) {
                    $imageGetter = 'get'.ucfirst($fieldDefinition->getName());
                    $image = $object->$imageGetter();
                    if($image instanceof DataObject\Data\Hotspotimage) {
                        $image = $image->getImage();
                        if ($image instanceof Asset\Image) {
                            $image = $image->getThumbnail(Asset\Image\Thumbnail\Config::getPreviewConfig());
                        }
                    }
                }
            }
        }

        $image = $this->resolveVisualizationImageUrl(
            $image,
            $classDefinition->getIcon() ?: '/bundles/opendxpadmin/img/flat-color-icons/class.svg'
        );

        $label = method_exists($object, 'getName') ? $object->getName() : '';
        if(!$label) {
            $label = $object->getKey();
        }

        $node = [
            'id' => $object->getId(),
            'label' => strip_tags($label),
            'image' => $image,
            'color' => $color,
            'shape' => 'circularImage',
            'elementType' => 'object'
        ];

        return $node;
    }

    /**
     * @Route("/log/{dataportId}/{requestedFilePath}", methods={"GET"})
     */
    public function showFileObjectAction($dataportId, $requestedFilePath, Request $request)
    {
        if (!Dataport::canDataportBeExecutedBy($dataportId, Tool\Admin::getCurrentUser())) {
            throw new AccessDeniedHttpException(
                sprintf(
                    $this->translator->trans('pim.permission_missing', [], 'admin'),
                    $this->translator->trans(Dataport::getExecutionPermissionName($dataportId), [], 'admin')
                )
            );
        }

        $requestedFilePath = $dataportId.'/'.preg_replace('/(\-\d+)?\.gz$/', '', $requestedFilePath);

        $storage = Helper::getApplicationLogStorage();

        $filePaths = [];
        if($storage->fileExists($requestedFilePath)) {
            $filePaths = [$requestedFilePath];
        } elseif ($storage->fileExists($requestedFilePath.'.gz')) {
            $filePaths = [$requestedFilePath.'.gz'];
        }

        for ($i = 0; $i < 10; $i++) {
            if ($storage->fileExists($requestedFilePath.'-'.$i)) {
                $filePaths[] = $requestedFilePath.'-'.$i;
            } elseif ($storage->fileExists($requestedFilePath.'-'.$i.'.gz')) {
                $filePaths[] = $requestedFilePath.'-'.$i.'.gz';
            }
        }

        if (count($filePaths) > 0) {
            $response = new StreamedResponse(
                static function () use ($filePaths, $storage) {
                    foreach($filePaths as $filePath) {
                        $fileData = $storage->readStream($filePath);

                        if (substr($filePath, -3) === '.gz') {
                            $localGzFile = Helper::getTemporaryFileFromStream($fileData);

                            $fileData = @gzopen($localGzFile, 'rb');
                            while (!feof($fileData)) {
                                echo gzread($fileData, 1024);
                                flush();
                            }
                            @unlink($localGzFile);
                        } else {
                            while (!feof($fileData)) {
                                echo fread($fileData, 1024);
                                flush();
                            }
                        }

                        echo "\n\n";
                    }
                }
            );
            $response->headers->set('Content-Type', 'text/plain');
            $totalFilesize = 0;
            foreach ($filePaths as $filePath) {
                $totalFilesize += $storage->fileSize($filePath);
                if ((substr($filePath, -3) === '.gz' && $totalFilesize > 10e6) || (substr($filePath, -3) !== '.gz' && $totalFilesize > 100e6) || $request->get('download')) {
                    $response->headers->set('Content-Disposition', 'attachment;filename=log-'.str_replace('/', '-', $filePath));
                    break;
                }
            }

            return $response;
        }

        throw new FileNotFoundException(null, 0, null, $requestedFilePath);
    }

    /**
     * @Route("/get-parameter-fields/{dataportId}", methods={"GET"})
     */
    public function getParameterFieldsAction($dataportId)
    {
        return new JsonResponse([
            'success' => true,
            'parameterFields' => Dataport::getUnmappedVirtualFields($dataportId)
        ]);
    }

    /**
     * @Route("/delete-elements", methods={"POST"})
     */
    public function deleteElementsAction(Request $request, EventDispatcher $eventDispatcher)
    {
        @ini_set('max_execution_time', 0);
        set_time_limit(0);
        @ini_set('max_input_time', 0);
        ignore_user_abort(true);

        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $elementIds = $request->get('elementIds', '');
        $elementIds = explode(',', $elementIds);
        $elementType = $request->get('elementType');

        $errors = [];
        foreach ($elementIds as $elementId) {
            try {
                $element = \OpenDxp\Model\Element\Service::getElementById($elementType, (int)$elementId);
                if (!$element instanceof ElementInterface) {
                    continue;
                }

                $descendantsIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                    'SELECT '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id FROM '.$elementType.'s WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn(
                        'path'
                    ) : 'path').' LIKE ? ORDER BY '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path'),
                    [$element->getFullPath().'/%']
                );

                if (count($descendantsIds) <= 1000) {
                    OpenDxp\Model\Element\Recyclebin\Item::create($element, Helper::getUser());
                } else {
                    $recycleBinItemPaths = [];
                    foreach ($descendantsIds as $index => $descendantsId) {
                        try {
                            $descendant = $element::getById($descendantsId);
                        } catch(\Exception $e) {
                            continue;
                        }

                        if (!$descendant instanceof ElementInterface) {
                            continue;
                        }

                        $isAlreadyInRecycleBinViaAncestor = false;
                        foreach($recycleBinItemPaths as $recycleBinItemPath) {
                            if(strpos($descendant->getRealFullPath(), $recycleBinItemPath) === 0) {
                                $isAlreadyInRecycleBinViaAncestor = true;
                                break;
                            }
                        }

                        if(!$isAlreadyInRecycleBinViaAncestor) {
                            $descendantsIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                                'SELECT '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' AS id FROM '.$elementType.'s WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path').' LIKE ? ORDER BY '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('path') : 'path'),
                                [$descendant->getFullPath().'/%']
                            );

                            if(count($descendantsIds) < 1000) {
                                OpenDxp\Model\Element\Recyclebin\Item::create($descendant, Helper::getUser());
                                $recycleBinItemPaths[] = $descendant->getRealFullPath();
                            }
                        }

                        $descendant->delete();
                        if ($index % 100 == 99) {
                            OpenDxp::getContainer()->get(OpenDxp\Helper\LongRunningHelper::class)->cleanUp();
                        }
                    }
                }

                $element->delete();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return new JsonResponse(['success' => count($errors) === 0, 'errors' => $errors]);
    }

    /**
     * @Route("/delete-elements-status", methods={"POST"})
     */
    public function deleteElementsStatusAction(Request $request)
    {
        $request->getSession()->save(); // session_write_close() to not block the session for other requests

        $elementIds = $request->get('elementIds', '');
        $elementIds = explode(',', $elementIds);
        $elementType = $request->get('elementType');

        $remaining = PimcoreDbRepository::getInstance()->findOneInSql('SELECT COUNT(*) FROM '.$elementType.'s WHERE '.($elementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (?)', [$elementIds]);

        return new JsonResponse(['success' => true, 'progress' => round((count($elementIds) - $remaining) / count($elementIds), 2)]);
    }

    /**
     * @Route("/statistics", methods={"GET"})
     *
     * @throws \Exception
     */
    public function statisticsAction(Request $request)
    {
        $responseData = ['success' => true, 'defaultLanguage' => Tool::getDefaultLanguage()];

        $locked = OpenDxp\Model\Tool\TmpStore::get(__METHOD__);
        if ($locked) {
            return new JsonResponse($responseData);
        }

        try {
            $statistic = PimcoreDbRepository::getInstance()->findRowInSql('SELECT
              (SELECT COUNT(*) FROM assets) as assets,
              (SELECT COUNT(*) FROM documents) as documents,
              (SELECT COUNT(*) FROM objects) as objects'
            );
        } catch (\Exception $e) {
            $statistic = [];
        }

        try {
            $data = [
                'bundle' => 'DataBridge',
                'bundle_version' => \OpenDxp::getKernel()->getBundle('SylphenDataBridgeBundle')->getVersion(),
                'hostname' => parse_url(Helper::getHostUrl(), PHP_URL_HOST),
                'opendxp_version' => Helper::getOpenDxpVersion(),
                'php_version' => PHP_VERSION,
                'assets' => $statistic['assets'] ?? 0,
                'documents' => $statistic['documents'] ?? 0,
                'objects' => $statistic['objects'] ?? 0,
                'collect_date' => (new \DateTime())->format('Y-m-d H:i:s'),
            ];

            $ch = curl_init('https://mdm.sylphen.com/api/rest/import/Statistics+API?apikey=TODO');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
#            curl_exec($ch); // TODO: Sylphen-Endpoint
            OpenDxp\Model\Tool\TmpStore::set(__METHOD__, true, null, 86400);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($curlError) {
                $responseData['error'] = $curlError;
                $responseData['success'] = false;
            }
        } catch (\Exception $e) {
            $responseData['error'] = $e->getMessage();
            $responseData['success'] = false;
        }

        return new JsonResponse($responseData);
    }

    /**
     * @Route("/restore-elements", methods={"POST"})
     */
    public function restoreElementsAction(Request $request)
    {
        $restoreObjects = json_decode($request->get('objects'), true);
        foreach ($restoreObjects as $restoreObject) {
            $object = OpenDxp\Model\Element\Service::getElementById('object', $restoreObject['id']);
            if (empty($restoreObject['mode'])) {
                if ($object instanceof DataObject\Folder) {
                    $restoreObject['mode'] = 'descendants';
                } else {
                    $restoreObject['mode'] = 'objects';
                }
            }


            if ($restoreObject['mode'] === 'descendants') {
                $restoreElementIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                    'SELECT '.Helper::prefixObjectSystemColumn('id').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').' LIKE ? AND '.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL',
                    [rtrim($object->getFullPath(), '/').'/%']
                );
            } elseif($restoreObject['mode'] === 'objects') {
                $restoreElementIds = [$object->getId()];
            } else {
                $restoreElementIds = PimcoreDbRepository::getInstance()->findColumnInSql(
                    'SELECT '.Helper::prefixObjectSystemColumn('id').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').' LIKE ? AND '.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL AND ('.$restoreObject['mode'].')',
                    [rtrim($object->getFullPath(), '/').'/%']
                );
            }
        }

        $statusKey = uniqid();

        $fields = $request->get('fields');
        $dryRun = $request->get('dryRun');
        Cli::execInBackground('"'.Cli::getPhpCli().'" "'.realpath(OPENDXP_PROJECT_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console').'" data-bridge:revert --status-key="'.$statusKey.'"'.($fields ? ' --fields="'.$fields.'"':'').($dryRun ? ' --dry-run':'').' '.implode(',', $restoreElementIds).' "'.$request->get('date').'"');

        return new JsonResponse(['success' => true, 'statusKey' => $statusKey]);
    }

    /**
     * @Route("/get-restore-fields")
     */
    public function getRestoreFieldsAction(Request $request)
    {
        $objectIds = explode(',', $request->get('objectIds', ''));

        $fields = [];
        foreach($objectIds as $objectId) {
            $object = OpenDxp\Model\Element\Service::getElementById('object', $objectId);

            if($object instanceof Concrete) {
                foreach ($object->getClass()->getFieldDefinitions() as $fieldDefinition) {
                    if ($fieldDefinition instanceof Data\Localizedfields) {
                        foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                            foreach (Tool::getValidLanguages() as $language) {
                                $fields[] = [
                                    'group' => $object->getClass()->getName(),
                                    'name' => $localizedFieldDefinition->getName().'#'.$language,
                                    'title' => $this->translator->trans($localizedFieldDefinition->getTitle(), [], 'admin').' ('.\Locale::getDisplayLanguage($language, $request->getLocale()).')'
                                ];
                            }
                        }
                    } else {
                        $fields[] = [
                            'group' => $object->getClass()->getName(),
                            'name' => $fieldDefinition->getName(),
                            'title' => $this->translator->trans($fieldDefinition->getTitle(), [], 'admin')
                        ];
                    }
                }
            }

            $descendantClassIds = PimcoreDbRepository::getInstance()->findColumnInSql('SELECT '.Helper::prefixObjectSystemColumn('classId').' FROM objects WHERE '.Helper::prefixObjectSystemColumn('path').' LIKE ? AND '.Helper::prefixObjectSystemColumn('classId').' IS NOT NULL GROUP BY '.Helper::prefixObjectSystemColumn('classId'), [rtrim($object->getFullPath(), '/').'/%']);
            foreach($descendantClassIds as $descendantClassId) {
                $classDefinition = DataObject\ClassDefinition::getById($descendantClassId);
                if($classDefinition instanceof DataObject\ClassDefinition) {
                    foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
                        if ($fieldDefinition instanceof Data\Localizedfields) {
                            foreach ($fieldDefinition->getFieldDefinitions() as $localizedFieldDefinition) {
                                foreach (Tool::getValidLanguages() as $language) {
                                    $fields[] = [
                                        'group' => $classDefinition->getName(),
                                        'name' => $localizedFieldDefinition->getName().'#'.$language,
                                        'title' => $this->translator->trans($localizedFieldDefinition->getTitle(), [], 'admin').' ('.\Locale::getDisplayLanguage($language, $request->getLocale()).')'
                                    ];
                                }
                            }
                        } else {
                            $fields[] = [
                                'group' => $classDefinition->getName(),
                                'name' => $fieldDefinition->getName(),
                                'title' => $this->translator->trans($fieldDefinition->getTitle(), [], 'admin')
                            ];
                        }
                    }
                }
            }
        }

        return new JsonResponse(['success' => true, 'fields' => $fields]);
    }

    /**
     * @Route("/restore-elements/status")
     */
    public function restoreElementsStatusAction(Request $request)
    {
        $statusFilePath = OPENDXP_LOG_DIRECTORY.'/restore-'.$request->get('statusKey').'.html';
        $restoreStatus = '';

        $changedElements = [];
        $doneItems = 0;
        $totalItems = 0;
        $startDate = Carbon::now(new DateTimeZone('UTC'));
        $errors = [];
        if (file_exists($statusFilePath)) {
            $statusFileHandle = fopen($statusFilePath, 'rb');

            $currentElement = null;
            while (!feof($statusFileHandle)) {
                $line = fgets($statusFileHandle);

                if(preg_match('/Trying to revert #(\d+) (.+)/', $line, $match)) {
                    $currentElement = ['elementId' => $match[1], 'fullpath' => $match[2], 'elementType' => 'object'];
                    $changedElements[] = array_merge($currentElement, ['field' => 'All', 'old' => '', 'new' => '', 'diff' => 'Completely restored']);
                    $doneItems++;
                } elseif (preg_match('/Setting "(.+)" for "([a-zA-Z_\x7f-\xff][a-zA-Z0-9_#:.\/]*)" \(old value: "(.+)"\)/', $line, $match)) {
                    foreach($changedElements as $index => $changedElement) {
                        if($changedElement['field'] === 'All' && $changedElement['elementId'] == $currentElement['elementId']) {
                            unset($changedElements[$index]);
                        }
                    }

                    $changedElements[] = array_merge($currentElement, $this->getChangedField($match[2], $match[3], $match[1]));
                } elseif (preg_match('/Start rollback of (\d+) objects \((\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)/', $line, $matches)) {
                    $totalItems = $matches[1];
                    $startDate = new Carbon($matches[2], new DateTimeZone('UTC'));
                } elseif (preg_match('/Current latest version is the same as on/', $line, $match)) {
                    foreach ($changedElements as $index => $changedElement) {
                        if ($changedElement['field'] === 'All' && $changedElement['elementId'] == $currentElement['elementId']) {
                            unset($changedElements[$index]);
                        }
                    }

                    $changedElements[] = array_merge($currentElement, ['field' => 'All', 'old' => '', 'new' => '', 'diff' => 'No change to current version']);
                } elseif(preg_match('/Reverted #/', $line, $matches)) {
                    continue;
                } elseif($line) {
                    $errors[] = array_merge($currentElement, ['message' => $line]);
                }
            }
        }

        $changedElements = array_slice($changedElements, $request->get('start'), $request->get('limit'));

        $now = Carbon::now(new DateTimeZone('UTC'));
        $finishedIn = ($doneItems == 0 || $doneItems == $totalItems) ? null : $now->addMilliseconds($now->diffInMilliseconds($startDate) / $doneItems * ($totalItems - $doneItems));

        $finished = $doneItems > 0 && $totalItems == $doneItems;
        if(count($errors) === 0 && $finished) {
            $errors[] = ['elementId' => '', 'fullpath' => '', 'elementType' => '', 'message' => ''];
        }

        if (count($changedElements) === 0 && $finished) {
            $changedElements[] = [
                'elementId' => '',
                'fullpath' => $this->translator->trans('pim.manual.startimport.summary.changes.none', [], 'admin', Helper::getUser()->getLanguage()),
                'elementType' => '',
                'field' => '',
                'old' => '',
                'new' => '',
                'diff' => $this->translator->trans('pim.manual.startimport.summary.changes.none', [], 'admin', Helper::getUser()->getLanguage())
            ];
        }

        return new JsonResponse(['success' => true, 'changedElements' => $changedElements, 'doneItems' => $doneItems,'totalItems' => $totalItems, 'finishedIn' => $finishedIn ? $finishedIn->diffForHumans(null, true) : null, 'finished' => $finished, 'errors' => $errors]);
    }

    private function getChangedField($field, $old, $new)
    {
        switch (Tool\Admin::getCurrentUser()->getLanguage()) {
            case 'de':
                $language = 'deu';
                break;
            case 'fr':
                $language = 'fra';
                break;
            case 'it':
                $language = 'ita';
                break;
            case 'jp':
                $language = 'jpn';
                break;
            case 'ru':
                $language = 'rus';
                break;
            case 'es':
                $language = 'spa';
                break;
            case 'en':
            default:
                $language = 'eng';
        }

        return [
            'field' => $field,
            'old' => $old,
            'new' => $new,
            'diff' => DiffHelper::calculate($old, $new, 'Combined', [
                // ignore case difference
                'ignoreCase' => false,
                // ignore whitespace difference
                'ignoreWhitespace' => false,
            ], [
                // how detailed the rendered HTML in-line diff is? (none, line, word, char)
                'detailLevel' => (strpos($old, "\n") === false || strpos($new, "\n") === false) ? 'none' : 'word',
                // renderer language: eng, cht, chs, jpn, ...
                // or an array which has the same keys with a language file
                'language' => $language,
                // show line numbers in HTML renderers
                'lineNumbers' => false,
                // show a separator between different diff hunks in HTML renderers
                'separateBlock' => false,
                // show the (table) header
                'showHeader' => false,
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
                'resultForIdenticals' => '<img src="/bundles/opendxpadmin/img/flat-color-icons/checkmark.svg" width="20">',
                // extra HTML classes added to the DOM of the diff container
                'wrapperClasses' => ['diff-wrapper summary'],
            ])
        ];
    }
}
