<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Controller;

use Sylphen\DataBridgeBundle\Command\ImportCompleteCommand;
use Sylphen\DataBridgeBundle\Command\ImportPimCommand;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\ObjectWizardParser;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\SplFileInfoSortedFileIterator;
use Sylphen\DataBridgeBundle\model\ApiKeys;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\DataportResource;
use Sylphen\DataBridgeBundle\model\Export;
use Sylphen\DataBridgeBundle\model\ImportStatus;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\model\RawItemField;
use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTimeImmutable;
use Exception;
use OpenDxp;
use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Bundle\AdminBundle\Security\User\TokenStorageUserResolver;
use OpenDxp\Config;
use OpenDxp\Db;
use OpenDxp\Extension\Bundle\OpenDxpBundleManager;
use OpenDxp\Logger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Bundle\SeoBundle\Model\Redirect\Listing;
use OpenDxp\Model\User;
use OpenDxp\Tool;
use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use OpenDxp\Tool\Authentication;
use OpenDxp\Version;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SplFileInfo;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;
use Twig\Lexer;
use Twig\Source;
use Twig\Token;

class RestController extends OpenDxp\Controller\FrontendController
{
    /** @var ItemMoldBuilder */
    private $helper;

    /** @var Environment */
    private $twigEnvironment;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    public function __construct(ItemMoldBuilder $helper, Environment $twigEnvironment, TokenStorageInterface $tokenStorage)
    {
        $this->helper = $helper;
        $this->twigEnvironment = $twigEnvironment;
        $this->tokenStorage = $tokenStorage;
    }

    private function getCredentials(Request $request) {
        $apiKey = $request->get('apikey') ?? $request->headers->get('x_api-key');
        if ($apiKey) {
            return $apiKey;
        }

        // check for existing session user
        if (null !== $pimcoreUser = Authentication::authenticateSession()) {
            return $pimcoreUser;
        }

        throw $this->createAccessDeniedException();
    }

    private function loadUserForApiKey($apiKey)
    {
        $params = [$apiKey, new DateTimeImmutable()];
        $user = PimcoreDbRepository::getInstance()->findOneInSql(
            'SELECT api_keys.users_id 
            FROM '.Installer::TABLE_API_KEYS.' api_keys 
            INNER JOIN users ON api_keys.users_id=users.id 
            WHERE api_keys.api_key = ? AND (api_keys.valid_to >= ? OR api_keys.valid_to IS NULL) AND users.active=1',
            $params
        );

        if ($user) {
            return User::getById($user);
        }

        $legacyApiKeyColumnExists = (bool)PimcoreDbRepository::getInstance()->findOneInSql('SHOW COLUMNS FROM `users` LIKE \'apiKey\'');

        if ($legacyApiKeyColumnExists) {
            $userList = new User\Listing();
            $userList->setCondition('apiKey = ? AND type = ? AND active = 1', [$apiKey, 'user']);
            $userList->setLimit(1);
            $userList->load();

            return $userList->getUsers()[0] ?? null;
        }

        return null;
    }

    protected function getAdminUser()
    {
        if (null === $token = $this->tokenStorage->getToken()) {
            return null;
        }

        if (!is_object($user = $token->getUser())) {
            // e.g. anonymous authentication
            return null;
        }

        return $user->getUser();
    }

    protected function guardUserLoggedIn(Request $request)
    {
        $credentials = $this->getCredentials($request);

        $user = null;
        if(is_string($credentials)) {
            $user = $this->loadUserForApiKey($credentials);
        } elseif($credentials instanceof User) {
            $user = $credentials;
        }

        if ($user) {
            if (!$user->getPassword()) {
                $user->setPassword(md5(uniqid()));
            }

            $userProxy = new \OpenDxp\Security\User\User($user);
            if (Kernel::MAJOR_VERSION > 10) {
                $token = new UsernamePasswordToken($userProxy, 'opendxp_admin', $userProxy->getRoles());
            } elseif (Kernel::MAJOR_VERSION > 5 || (Kernel::MAJOR_VERSION == 5 && Kernel::MINOR_VERSION >= 4)) {
                $token = new UsernamePasswordToken($userProxy, 'admin', $userProxy->getRoles());
            } else {
                $token = new UsernamePasswordToken($userProxy, $user->getPassword(), 'admin', $userProxy->getRoles());
            }
            $this->tokenStorage->setToken($token);
        } else {
            throw new AccessDeniedHttpException(
                'Access denied. Please ensure that you either are logged into Pimcore backend or provide an API key in the request URL.'
            );
        }
    }

    /**
     * @Route(
     *     "/api/rest/import/{dataportId}",
     *     name="dataport_import",
     *     methods={"POST","GET","OPTIONS"},
     *     defaults={"dataportId"=0},
     *     options={"expose"=true}
     * )
     *
     * @Route(
     *     "/webservice/{bundle}/rest/import/{dataportId}",
     *     name="dataport_import_legacy",
     *     methods={"POST","GET","OPTIONS"},
     *     defaults={"dataportId"=0, "bundle"="SylphenDataBridge"},
     *     requirements={"bundle"="SylphenDataBridge"}
     * )
     *
     * Start complete import with given raw data
     * Example:
     * POST http://[YOUR-DOMAIN]/webservice/SylphenDataBridge/rest/import/<Dataport-ID>?apikey=<API-KEY>
     *
     * Request body: source data to be imported
     *
     * @param Request $request
     * @param string|int $dataportId ID or name of dataport
     *
     * @return Response
     */
    public function importAction(Request $request, $dataportId, Profiler $profiler = null)
    {
        ignore_user_abort(true);
        if($request->getRealMethod() === 'OPTIONS') {
            $response = new Response();
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST');
            return $response;
        }

        if ($profiler) {
            $profiler->disable();
        }

        $this->guardUserLoggedIn($request);

        Helper::setMemoryLimit();
        @ini_set('max_execution_time', 0);
        @ini_set('max_input_time', 0);
        set_time_limit(0);
        @ignore_user_abort();

        session_write_close();
        try {
            if (!$dataportId) {
                $dataportId = $request->query->get('dataportId');
            }

            $dataports = Dataport::getInstance();
            $dataport = $dataports->get($dataportId);

            if (empty($dataport)) {
                // try to redirect to new name (in case dataport has been renamed), todo: perhaps we can use route conditions or custom route loader so Symfony can handle redirects
                $redirects = new Listing();
                $redirects->addConditionParam('source = ?', $request->getPathInfo());
                $redirects->addConditionParam('active = 1');
                $redirects->setOrderKey('priority');
                $redirects->setOrder('DESC');
                $redirects->setLimit(1);
                $redirects = $redirects->load();
                if ($redirects && preg_match('#/rest/(import|export)/(.+)#', $redirects[0]->getTarget(), $newDataportId)) {
                    $newDataportId = $newDataportId[2];

                    if ($newDataportId !== $dataportId) {
                        $request->server->set('REQUEST_URI', str_replace($dataportId, $newDataportId, $request->getPathInfo()));
                        $request->initialize(
                            $request->query->all(),
                            $request->request->all(),
                            $request->attributes->all(),
                            $request->cookies->all(),
                            $request->files->all(),
                            $request->server->all(),
                            $request->getContent()
                        );

                        // recall controller here instead of simply use the new dataportId because there could be multiple redirects (e.g. name got changed twice abc -> abcd -> abcde, then requests to abc shall be forwarded twice until it lands on current name abcde
                        return $this->importAction($request, $newDataportId);
                    }
                }

                if (!is_numeric($dataportId)) {
                    $errorMessage = 'Dataport not found';
                    $allDataports = $dataports->find();
                    $distances = [];
                    foreach ($allDataports as $dataport) {
                        $distances[$dataport['name']] = levenshtein(urldecode($dataportId), $dataport['name']);
                    }
                    asort($distances);

                    $distances = array_filter($distances, static function ($distance) {
                        return $distance < 5;
                    });

                    if (count($distances) > 0) {
                        $errorMessage .= '. Did you mean one of the following? ';
                        $similarDataportNames = [];
                        foreach (array_keys($distances) as $similarDataportName) {
                            $similarDataportNames[] = urlencode($similarDataportName);
                        }
                        $errorMessage .= implode(', ', $similarDataportNames);
                    }
                }

                return $this->createErrorResponse($errorMessage);
            }

            $dataportId = $dataport['id'];

            $this->guardPermission($dataportId, $request);

            $targetConfig = $dataport['targetconfig'];

            $itemMold = null;

            try {
                if (empty($targetConfig['itemClass'])) {
                    $sourceConfig = $dataport['sourceconfig'];
                    $itemMold = $this->helper->getItemMoldByClassId($sourceConfig['sourceClass'] ?? null);
                } else {
                    $itemMold = $this->helper->getItemMoldByClassId($targetConfig['itemClass']);
                }
            } catch(\Throwable $e) {
            }

            if($dataport['sourcetype'] === 'report' && !Tool\Admin::getCurrentUser()->isAllowed('reports')) {
                throw new AccessDeniedHttpException();
            }

            $targetTypePermission = 'objects';
            if ($itemMold !== null) {
                $targetTypePermission = \OpenDxp\Model\Element\Service::getElementType($itemMold) . 's';
            }

            if (!Tool\Admin::getCurrentUser()->isAllowed($targetTypePermission)) {
                throw new AccessDeniedHttpException();
            }

            $user = Tool\Admin::getCurrentUser();
            $targetClassAllowed = $itemMold === null || $itemMold instanceof Export ||
                ($itemMold instanceof Concrete && $user->isAllowed($itemMold->getClassId(), 'class')) ||
                ($itemMold instanceof PageSnippet && $user->isAllowed($itemMold->getType(), 'docType')) ||
                ($itemMold instanceof Asset && $user->isAllowed($targetTypePermission));

            if(!$targetClassAllowed) {
                throw $this->createAccessDeniedException('User ' . Tool\Admin::getCurrentUser()->getName() . ' attempted to access element of type ' . get_class($itemMold) . ', but has no permission to do so');
            }

            if ($dataport['sourcetype'] === 'pimcore') {
                $conditions = [];
                if($request->get('query')) {
                    $conditions[] = urldecode($request->get('query'));
                }

                $gridExportCsvFile = $request->get('gridExport');
                if($gridExportCsvFile) {
                    $gridExportCsvFileFileHandle = fopen(OPENDXP_SYSTEM_TEMP_DIRECTORY.'/'.$gridExportCsvFile.'.csv', 'rb');
                    if(is_resource($gridExportCsvFileFileHandle)) {
                        $gridExportCsvFileLine = fgetcsv($gridExportCsvFileFileHandle, 0, ';');

                        $sourceElementType = OpenDxp\Model\Element\Service::getElementType($itemMold);

                        foreach($gridExportCsvFileLine as $index => $columnName) {
                            if($columnName === 'id') {
                                $elementIds = [];
                                while ($gridExportCsvFileLine = fgetcsv($gridExportCsvFileFileHandle, 0, ';')) {
                                    $elementIds[] = $gridExportCsvFileLine[$index];
                                }

                                if(count($elementIds) > 100) {
                                    $tempTableName = 'data_bridge_grid_export_'.md5(implode(',', $elementIds));
                                    PimcoreDbRepository::getInstance()->execute('CREATE TEMPORARY TABLE '.$tempTableName.' (`id` int(11) UNSIGNED NOT NULL)');
                                    register_shutdown_function(static function() use ($tempTableName) {
                                        PimcoreDbRepository::getInstance()->execute('DROP TABLE IF EXISTS '.$tempTableName);
                                    });

                                    foreach(array_chunk($elementIds, 100) as $elementIdChunk) {
                                        PimcoreDbRepository::getInstance()->execute('INSERT INTO '.$tempTableName.' (`id`) VALUES '.substr(str_repeat('(?),', count($elementIdChunk)), 0, -1), $elementIdChunk);
                                    }

                                    $conditions[] = ($sourceElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN (SELECT id FROM '.$tempTableName.')';
                                } else {
                                    $conditions[] = ($sourceElementType === 'object' ? Helper::prefixObjectSystemColumn('id') : 'id').' IN ('.implode(',', $elementIds).')';
                                }

                                break;
                            }
                        }
                    }
                }

                if(count($conditions) > 1) {
                    $tmpFileName = '('.implode(') AND (', $conditions).')';
                } else {
                    $tmpFileName = implode(' AND ', $conditions);
                }
            } elseif ($request->getContent()) {
                $dataResource = $request->getContent(true);
                $tmpFileName = \OPENDXP_SYSTEM_TEMP_DIRECTORY . '/import_' . $dataportId . '_' . uniqid();
                $tmpStream = fopen($tmpFileName, 'wb');
                \stream_copy_to_stream($dataResource, $tmpStream);
                fclose($tmpStream);
            } elseif ($request->get('query')) {
                $tmpFileName = $request->get('query');
            }

            $force = $request->get('force') ?? false;

            if ($request->get('async')) {
                $statusKey = uniqid('', true);

                $parameters = [];
                if ($request instanceof Request) {
                    $parameters[] = $request->request->all();
                    $parameters[] = $request->query->all();
                    $parameters[] = $request->attributes->all();
                    $parameters[] = ['domain' => $request->getHost(), 'hostname' => $request->getHost()];
                }
                $parameters = array_replace(...$parameters);

                $localeOption = '';
                if (isset($parameters['locale'])) {
                    $localeOption = ' --locale=' . escapeshellarg($parameters['locale']);
                }

                $cmd = '"' .Cli::getPhpCli() . '" "'.realpath(OPENDXP_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console') . '" data-bridge:complete ' . $dataportId . (isset($tmpFileName) ? ' "'.str_replace('"', '\\"', $tmpFileName) . '"' : '').($request->get('rm') || $request->get('clear-file-after-import') ? ' --rm': '').($parameters ? ' --parameters='.escapeshellarg(json_encode($parameters)):'').' --user='.Tool\Admin::getCurrentUser()->getId().' --status-key='.$statusKey.($force ? ' -f' : '').($request->get('dry-run') ? ' --dry-run' : '').(($request->get('offset') || $request->get('limit')) ? ' --limit='.($request->get('offset') ?? 0).','.($request->get('limit') ?? 'INF') : '') . $localeOption;
                Cli::execInBackground($cmd);

                $statusUrl = Helper::generateAbsoluteUrl('import_status', ['statusKey' => $statusKey, 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key')]);
                return new JsonResponse(['status_url' => $statusUrl, 'statusKey' => $statusKey, 'done' => 0, 'total' => 'n/a','msg' => 'Request processing will take some time. Please call '.$statusUrl.' to get the result.'], JsonResponse::HTTP_ACCEPTED, ['Location' => $statusUrl]);
            }

            $limit = $request->get('offset', 0).','.$request->get('limit', PHP_INT_MAX);

            $locale = $request->get('locale');
            if (!$locale) {
                $user = Tool\Admin::getCurrentUser();
                if ($user instanceof OpenDxp\Model\User) {
                    $locale = $user->getLanguage();
                }
            }

            if (!Tool::isValidLanguage($locale)) {
                foreach (Tool::getValidLanguages() as $language) {
                    if (strpos($language, $locale) === 0) {
                        $locale = $language;
                        break;
                    }
                }
            }

            \OpenDxp::getContainer()->get(LocaleServiceInterface::class)->setLocale($locale);

            $response = ImportCompleteCommand::import($dataportId, $tmpFileName ?? null, $request->get('rm') || $request->get('clear-file-after-import'), (bool)$force, $locale, null, $limit, null, null, null, (bool)$request->get('dry-run', false));
        } catch(Throwable $e) {
            return new JsonResponse((OpenDxp::inDebugMode()?(string)$e:$e->getMessage()), JsonResponse::HTTP_BAD_REQUEST);
        }

        return $response;
    }

    /**
     * @Route(
     *     "/api/rest/export/{dataportId}",
     *     name="dataport_export",
     *     methods={"GET","OPTIONS"},
     *     defaults={"dataportId"=0},
     *     options={"expose"=true}
     * )
     *
     * @Route(
     *     "/webservice/{bundle}/rest/export/{dataportId}",
     *     name="dataport_export_legacy",
     *     methods={"GET","OPTIONS"},
     *     defaults={"dataportId"=0, "bundle"="SylphenDataBridge"},
     *     requirements={"bundle"="SylphenDataBridge"}
     * )
     *
     * Start complete import with given raw data
     * Example:
     * GET http://[YOUR-DOMAIN]/rest/export/<Dataport-ID>?apikey=<API-KEY>[&locale=<locale code>]
     * GET http://[YOUR-DOMAIN]/webservice/SylphenDataBridge/rest/export/<Dataport-ID>?apikey=<API-KEY>[&locale=<locale code>]
     *
     * @param Request                $request
     * @param string|int $dataportId ID or name of dataport
     *
     * @return Response
     */
    public function exportAction(Request $request, $dataportId)
    {
        $this->guardUserLoggedIn($request);

        return $this->importAction($request, $dataportId);
    }

    /**
     * @Route(
     *     "/api/rest/async",
     *     name="import_status",
     *     methods={"GET"},
     *     options={"expose"=true}
     * )
     *
     * @Route(
     *     "/api/rest/status",
     *     name="import_status_legacy2",
     *     methods={"GET"}
     * )
     *
     * @Route(
     *     "/webservice/{bundle}/rest/status",
     *     name="import_status_legacy",
     *     methods={"GET"},
     *     defaults={"bundle"="SylphenDataBridge"},
     *     requirements={"bundle"="SylphenDataBridge"}
     * )
     *
     * @param Request $request
     *
     * @return Response
     */
    public function statusAction(Request $request) {
        session_write_close();
        $this->guardUserLoggedIn($request);

        $statusKey = $request->get('statusKey');
        if(!$statusKey) {
            return $this->createErrorResponse('Please provide status key via parameter "statusKey"');
        }


        $status = PimcoreDbRepository::getInstance()->findRowInSql('SELECT dataport_id, doneItems, totalItems, `status`, `key`
            FROM '.Installer::TABLE_IMPORTSTATUS.' status 
            WHERE status.key LIKE ?
            ORDER BY status.key DESC
            LIMIT 1',
            [explode('-', $statusKey)[0].'%']
        );
        if(empty($status)) {
            $status = [
                'status' => ImportStatus::STATUS_RUNNING,
                'doneItems' => 0,
                'totalItems' => 'n/a'
            ];
        } else {
            $statusKey = $status['key'];
        }

        if($status['status'] == ImportStatus::STATUS_RUNNING) {
            if ($request->get('autoRefresh')) {
                $statusUrl = Helper::generateAbsoluteUrl('import_status', ['autoRefresh' => 1, 'statusKey' => $statusKey, 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key'), 'n' => time()]);
                return new Response(
                    '<!DOCTYPE html>
<html lang="en">   
  <head>      
    <title>Processing ...</title>      
    <style>
        body {
        font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif
        }
    </style>
    <script>
        setTimeout(function() {
            window.location.href = \''.$statusUrl.'\';
        }, 1000);
    </script>
    <noscript>
        <meta http-equiv="refresh" content="1;URL=\''.$statusUrl.'\'" />
    </noscript>
  </head>    
  <body style="font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif"> 
    <p>The job is still being processed, this page will automatically reload and provide the result document when it is finished (or got aborted).</p> 
    <div style="width:500px;background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);'.(!$request->get('hideProgressBar') ? 'display:none;' : '').'">
        <span class="progress-bar-fill" style="width:'.(($status['totalItems'] === 'n/a' || !$status['totalItems']) ? 0 : round($status['doneItems'] / $status['totalItems'] * 100)).'%;display:block;height:22px;background-color:#659cef;border-radius:3px;transition:width 500ms ease-in-out;"></span>
    </div>
  </body>  
</html>', Response::HTTP_ACCEPTED
                );
            }

            $autoRefreshUrl = Helper::generateAbsoluteUrl('import_status', ['autoRefresh' => 1, 'statusKey' => $statusKey, 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key'), 'n' => time()]);

            return new JsonResponse(
                [
                    'status_url' => $request->getUri(),
                    'done' => $status['doneItems'],
                    'total' => $status['totalItems'],
                    'msg' => 'Request "'.$statusKey.'" is still being processed. If you want to automatically get the response document when it is ready, please call '.$autoRefreshUrl
                ],
                JsonResponse::HTTP_ACCEPTED,
                ['Location' => $request->getUri()]
            );
        }

        if ($status['status'] == ImportStatus::STATUS_ABORTED) {
            return new JsonResponse(
                [
                    'status_url' => $request->getUri(),
                    'done' => $status['doneItems'],
                    'total' => $status['totalItems'],
                    'msg' => 'Dataport run was aborted. Please see the error logs in '.Helper::getHostUrl('https').'/admin/log/show-file-object?filePath='.preg_replace('/^'.preg_quote(\OPENDXP_PROJECT_ROOT, '/').'/', '', ((defined('OPENDXP_LOG_FILEOBJECT_DIRECTORY'))?OPENDXP_LOG_FILEOBJECT_DIRECTORY.'/':'').$status['dataport_id'].'/'.$status['key'])
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                ['Location' => $request->getUri()]
            );
        }

        if (empty($status['dataport_id'])) {
            return $this->createErrorResponse('Could not find dataport run with status key "'.$statusKey.'"', JsonResponse::HTTP_NOT_FOUND);
        }

        $responseFile = Installer::getResultDocumentPath().'/result_'.$status['dataport_id'].'_'.$statusKey.'-2';
        if (!file_exists($responseFile)) {
            $responseFile = Installer::getResultDocumentPath().'/result_'.$status['dataport_id'].'_'.$statusKey;
        }
        if(!file_exists($responseFile)) {
            sleep(1);

            if ($request->get('autoRefresh') < 30 && Dataport::hasResultCallbackWithOutput($status['dataport_id'])) {
                $statusUrl = Helper::generateAbsoluteUrl('import_status', ['autoRefresh' => $request->get('autoRefresh', 1) + 1, 'statusKey' => $statusKey, 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key'), 'n' => time()]);
                return new Response(
                    '<!DOCTYPE html>
<html lang="en">   
  <head>      
    <title>Processing ...</title>
    <script>
        setTimeout(function() {
            window.location.href = \''.$statusUrl.'\';
        }, 1000);
    </script>
    <noscript>
        <meta http-equiv="refresh" content="1;URL=\''.$statusUrl.'\'" />
    </noscript>
  </head>    
  <body style="font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif"> 
    <p>The job is still being processed, this page will automatically reload and provide the result document when it is finished (or got aborted).</p> 
    <div style="width:500px;background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);'.(!$request->get('hideProgressBar') ? 'display:none;' : '').'">
        <span class="progress-bar-fill" style="width:'.(($status['totalItems'] === 'n/a' || !$status['totalItems']) ? 0 : round($status['doneItems'] / $status['totalItems'] * 100)).'%;display:block;height:22px;background-color:#659cef;border-radius:3px;transition:width 500ms ease-in-out;"></span>
    </div>
  </body>  
</html>', Response::HTTP_ACCEPTED
                );
            }

            return new Response(
                '<!DOCTYPE html>
<html lang="en">   
    <head>      
        <title>Processing finished</title>
    </head>    
        <body style="font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif"> 
            <p>The job is finished.</p>
        </body>  
</html>', Response::HTTP_OK
            );
        }

        flock(fopen($responseFile, 'rb'), LOCK_SH);

        if ($request->get('autoRefresh') && !$request->get('finished')) {
            $statusUrl = Helper::generateAbsoluteUrl(
                'import_status',
                ['autoRefresh' => 1, 'finished' => 1, 'statusKey' => $statusKey, 'apikey' => $request->get('apikey') ?? $request->headers->get('x_api-key'), 'n' => time()]
            );

            $response = \unserialize(\file_get_contents($responseFile));

            if($response->headers->get('Content-Type') === 'application/json') {
                $content = json_decode($response->getContent(), true);
                if(!empty($content['dependentDataportId'])) {
                    $dependentDataport = Dataport::getInstance()->get($content['dependentDataportId']);
                    if(($dependentDataport['sourcetype'] ?? null) === 'object-wizard') {
                        $parser = Dataport::getInstance()->getParser($content['dependentDataportId']);
                        if($parser instanceof ObjectWizardParser) {
                            $content['dependentDataportParameters'] = $parser->getDefaultValues($content['dependentDataportParameters']);
                        }

                        return new Response(
                            '<!DOCTYPE html>
<html lang="en">
  <head>
    <title>Processing ...</title>
    <style>
        body {
        font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif
        }
    </style>
    <script>
        parent.opendxp.plugin.Pim.plugin.startDataport('.$content['dependentDataportId'].', '.json_encode((array)$content['dependentDataportParameters']).');
        parent.opendxp.plugin.Pim.plugin.closeStartWindow('.$status['dataport_id'].');
    </script>
    <noscript>
        <meta http-equiv="refresh" content="1;URL=\''.$statusUrl.'\'" />
    </noscript>
  </head>
  <body>
    <p>Job is finished. You either got the response document in your browser downloads or you will get redirected to the response document within seconds...</p>
    <p>If this does not work, you can <a href="'.$statusUrl.'">download the file</a></p>
    <div style="width:500px;background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);'.(!$request->get('hideProgressBar') ? 'display:none;' : '').'">
        <span class="progress-bar-fill" style="width:'.(($status['totalItems'] === 'n/a' || !$status['totalItems']) ? 0 : round($status['doneItems'] / $status['totalItems'] * 100)).'%;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;"></span>
    </div>
  </body>
</html>', Response::HTTP_ACCEPTED
                        );
                    }
                }
            }

            return new Response(
                '<!DOCTYPE html>
<html lang="en">    
  <head>      
    <title>Processing ...</title>      
    <style>
        body {
        font-family: \'Open Sans\', \'Helvetica Neue\', helvetica, arial, verdana, sans-serif
        }
    </style>
    <script>
        setTimeout(function() {
            window.location.href = \''.$statusUrl.'\';
        }, 1000);
    </script>
    <noscript>
        <meta http-equiv="refresh" content="1;URL=\''.$statusUrl.'\'" />
    </noscript>
  </head>    
  <body> 
    <p>Job is finished. You either got the response document in your browser downloads or you will get redirected to the response document within seconds...</p> 
    <p>If this does not work, you can <a href="'.$statusUrl.'">download the file</a></p> 
    <div style="width:500px;background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);'.(!$request->get('hideProgressBar') ? 'display:none;':'').'">
        <span class="progress-bar-fill" style="width:'.(($status['totalItems'] === 'n/a' || !$status['totalItems']) ? 0 : round($status['doneItems'] / $status['totalItems'] * 100)).'%;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;"></span>
    </div>
  </body>  
</html>', Response::HTTP_ACCEPTED
            );
        }

        return \unserialize(\file_get_contents($responseFile));
    }

    /**
     * @Route(
     *     "/api/rest/documentation/{dataportId}",
     *     methods={"GET"},
     *     defaults={"dataportId"=null},
     *     requirements={"dataportId"="\d+"}
     * )
     *
     * @Route(
     *      "/admin/{bundle}/rest/documentation/{dataportId}",
     *      methods={"GET"},
     *      defaults={"dataportId"=null, "bundle"="SylphenDataBridge"},
     *      requirements={"dataportId"="\d+", "bundle"="SylphenDataBridge"}
     *  )
     */
    public function indexAction($dataportId, Request $request) {
        $this->guardUserLoggedIn($request);
        return $this->render('@SylphenDataBridge/rest/index.html.twig', ['dataportId' => $dataportId]);
    }

    /**
     * @Route(
     *      "/api/rest/documentation/open-api/{dataportId}",
     *      methods={"GET"},
     *      name="pim_generate_open_api",
     *      defaults={"dataportId"=null}
     *  )
     *
     * @Route(
     *     "/admin/{bundle}/rest/documentation/open-api/{dataportId}",
     *     methods={"GET"},
     *     defaults={"dataportId"=null, "bundle"="SylphenDataBridge"},
     *     requirements={"bundle"="SylphenDataBridge"}
     * )
     */
    public function generateOpenApiJsonAction($dataportId, Request $request) {
        $this->guardUserLoggedIn($request);

        $dataports = Dataport::getInstance();
        if ($dataportId) {
            $dataports = [$dataports->get($dataportId)];
        } else {
            $dataports = $dataports->find([], 'name');
        }

        $specification = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $dataportId ? 'OpenAPI specification for dataport ' . $dataports[0]['name']:'Data Bridge REST API',
                'description' => $dataportId?$dataports[0]['description']:'OpenAPI specification for import and export dataports defined by Sylphen Data Bridge',
                'version' => '1.0.0'
            ]
        ];

        foreach($dataports as $dataport) {
            $sourceConfig = $dataport['sourceconfig'];
            $targetConfig = $dataport['targetconfig'];

            $requestBodyMimeType = 'text';
            $schema = [
                'type' => 'string',
            ];

            $example = null;
            switch ($dataport['sourcetype']) {
                case 'xml':
                    $requestBodyMimeType = 'application/xml';
                    break;
                case 'csv':
                    $requestBodyMimeType = 'text/csv';

                    $exampleData = [];
                    if($sourceConfig['hasHeader']) {
                        foreach($sourceConfig['fields'] ?? [] as $rawItemField) {
                            $exampleData[] = $rawItemField['column'];
                        }
                        $example .= $sourceConfig['quote'].implode($sourceConfig['quote'].$sourceConfig['separator'].$sourceConfig['quote'], $exampleData).$sourceConfig['quote']."\n";
                    }

                    break;
                case 'excel':
                    if($dataportId) {
                        throw new Exception('Excel imports currently do not support REST API');
                    }
                    continue 2;
                case 'json':
                    $requestBodyMimeType = 'application/json';
                    break;
                case 'pimcore':
                    $requestBodyMimeType = 'application/sql';
                    break;
            }

            $apiKey = null;
            $user = Tool\Admin::getCurrentUser();
            $apiKey = PimcoreDbRepository::getInstance()->findOneInSql('SELECT api_key FROM '.Installer::TABLE_API_KEYS.' api_keys WHERE api_keys.users_id=? AND api_keys.api_key!=\'\' AND api_keys.api_key IS NOT NULL AND (api_keys.valid_to IS NULL OR api_keys.valid_to >= NOW())', [$user->getId()]);
            if(!$apiKey && method_exists($user, 'getApiKey')) {
                $apiKey = $user->getApiKey();
            }

            $variables = Dataport::getUnmappedVirtualFields($dataportId);

            $redirects = new Listing();
            $redirects->addConditionParam('target = ?', [\OpenDxp::getContainer()->get('router')->generate('dataport_import', ['dataportId' => urlencode($dataport['name'])])]);
            $redirects->addConditionParam('active = 1');
            $redirects->setOrderKey('priority');
            $redirects->setOrder('DESC');
            $redirectedEndpoints = [];
            foreach($redirects->load() as $redirect) {
                $redirectedEndpoints[] = $redirect->getSource();
            }

            if($redirectedEndpoints) {
                $dataport['description'] .= ($dataport['description']?'<br><br>':'').'Due to renaming of the dataport the following old endpoint URLs get redirected to above one: <ul><li>'.implode('</li><li>', $redirectedEndpoints).'</li></ul>You can clean up the redirects under Tools > Redirects in the Pimcore main menu.';
            }

            if ($targetConfig['itemClass'] !== '0') {
                $parameters = [
                    [
                        'name' => 'apikey',
                        'in' => 'query',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'default' => $apiKey
                        ],
                        'description' => 'Rest API key (see \'Permissions & API Keys\' button below dataport tree)'
                    ],
                    [
                        'name' => 'force',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'int',
                            'default' => '0'
                        ],
                        'description' => 'If set to 1: Bypass check if raw data item already got imported for the found object. Normally you should only use this for testing purposes.'
                    ],
                    [
                        'name' => 'async',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'int'
                        ],
                        'description' => 'If set to 1: Immediately return. Import is being processed in the background. In the response you will get a URL to retrieve import status and response document'
                    ]
                ];
                foreach ($variables as $variable) {
                    $parameters[] = [
                        'name' => $variable,
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'string'
                        ],
                        'description' => 'Variable in import resource / callback function'
                    ];
                }

                // import
                $specification['paths'][\OpenDxp::getContainer()->get('router')->generate(
                    'dataport_import',
                    ['dataportId' => urlencode($dataport['name'])]
                )]['post'] = [
                    'summary' => 'Execute import "' . $dataport['name'] . '"',
                    'description' => $dataport['description'],
                    'parameters' => $parameters,
                    'requestBody' => [
                        'description' => ($dataport['sourcetype'] !== 'pimcore') ? 'Document to be imported ('
                            . $dataport['sourcetype'] . '). Leave empty to use dataport\'s default import resource' : 'SQL condition to define which objects to use. The dataport\'s configured SQL condition will be extended, not replaced.',
                        'required' => false,
                        'content' => [
                            $requestBodyMimeType => [
                                'schema' => $schema,
                                'example' => $example
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Import run successfully. Response document gets returned, if result callback function creates one.',
                        ],
                        '202' => [
                            'description' => 'Import is being processed in the background. Call the "status_url" which you receive when you set async=1 to get import status and retrieve response document',
                        ],
                        '403' => [
                            'description' => 'Login not possible. Please configure dataport permissions and activate API key for the user which shall execute the import',
                        ],
                        '500' => [
                            'description' => 'Import got aborted',
                        ]
                    ]
                ];
            } else {
                $locale = Tool\Admin::getCurrentUser()->getLanguage();
                if (!Tool::isValidLanguage($locale)) {
                    foreach (Tool::getValidLanguages() as $language) {
                        if (strpos($language, $locale) === 0) {
                            $locale = $language;
                            break;
                        }
                    }
                }

                $parameters = [
                    [
                        'name' => 'apikey',
                        'in' => 'query',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'default' => $apiKey
                        ],
                        'description' => 'Rest API key (see \'Permissions & API Keys\' button below dataport tree)'
                    ],
                    [
                        'name' => 'locale',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'string',
                            'default' => $locale
                        ],
                        'description' => 'Locale to be used for localized fields, if omitted the API key\'s user language gets used. <br>Valid values: '.implode(
                                ', ',
                                Tool::getValidLanguages()
                            )
                    ],
                    [
                        'name' => 'query',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'string'
                        ],
                        'description' => ($dataport['sourcetype'] !== 'pimcore') ? 'Document to be imported ('.$dataport['sourcetype'].')' : 'SQL condition to define which objects to use (this gets added to the configured dataport SQL condition) - add database index to used fields in class definition to enhance performance'
                    ],
                    [
                        'name' => 'limit',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'int'
                        ],
                        'description' => 'Max. Number of items to be returned'
                    ],
                    [
                        'name' => 'offset',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'int'
                        ],
                        'description' => 'Index of first item to be returned'
                    ],
                    [
                        'name' => 'async',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'int',
                        ],
                        'description' => 'If set to 1: Immediately return. Export is being processed in the background. In the response you will get a URL to retrieve export status and response document'
                    ]
                ];
                foreach ($variables as $variable) {
                    $parameters[] = [
                        'name' => $variable,
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Variable in import resource'
                    ];
                }

                // export
                $specification['paths'][\OpenDxp::getContainer()->get('router')->generate(
                    'dataport_export',
                    ['dataportId' => urlencode($dataport['name'])]
                )]['get'] = [
                    'summary' => 'Execute export "' . $dataport['name'] . '"',
                    'description' => $dataport['description'],
                    'parameters' => $parameters,
                    'responses' => [
                        '200' => [
                            'description' => 'Exported data which got created from result callback function',
                        ],
                        '202' => [
                            'description' => 'Export is being processed in the background. Call the "status_url" which you receive when you set async=1 to get export status and retrieve response document',
                        ],
                        '403' => [
                            'description' => 'Login not possible. Please configure dataport permissions and activate API key for the user which shall execute the export',
                        ],
                        '500' => [
                            'description' => 'Export got aborted',
                        ]
                    ]
                ];
            }
        }

        $response = new Response(Yaml::dump($specification, 100));
        $response->headers->set('Content-Type', 'text/yaml');
        return $response;
    }

    protected function guardPermission($dataportId, Request $request) {
        $user = Tool\Admin::getCurrentUser();

        if(!$user instanceof OpenDxp\Model\User) {
            throw new AccessDeniedHttpException(
                'Access denied. Please ensure that you either are logged into Pimcore backend or provide an API key in the request URL.'
            );
        }

        if(!$user->isAllowed(Dataport::getExecutionPermissionName($dataportId))) {
            throw new AccessDeniedHttpException('Access denied. User is missing "run" permission for the requested dataport.');
        }
    }

    /**
     * @param array|string $data
     * @param int|null $status
     *
     * @return JsonResponse
     */
    protected function createErrorResponse($data = null, $status = Response::HTTP_BAD_REQUEST)
    {
        return new JsonResponse(
            $this->createErrorData($data),
            $status
        );
    }

    /**
     * @param array|string $data
     *
     * @return array
     */
    protected function createErrorData($data = null)
    {
        return array_merge(['success' => false], $this->normalizeResponseData($data));
    }

    /**
     * @param array|string $data
     *
     * @return array
     */
    protected function normalizeResponseData($data = null)
    {
        if (null === $data) {
            $data = [];
        } elseif (is_string($data)) {
            $data = ['msg' => $data];
        }

        return $data;
    }

    /**
     * Check user permission
     *
     * @param string $permission
     *
     * @throws AccessDeniedHttpException
     */
    protected function checkPermission($permission)
    {
        if (!Tool\Admin::getCurrentUser() || !Tool\Admin::getCurrentUser()->isAllowed($permission)) {
            Logger::error(
                'User {user} attempted to access {permission}, but has no permission to do so',
                [
                    'user' => Tool\Admin::getCurrentUser() ? Tool\Admin::getCurrentUser()->getName() : null,
                    'permission' => $permission,
                ]
            );

            throw new AccessDeniedHttpException('Access denied. User has no permission to access "'.$permission.'".');
        }
    }

    /**
     * @Route("/api/dataports", name="sylphen_databridge_api_dataport_list", methods={"GET"})
     * @param Request $request
     * @return Response
     */
    public function dataportListAction(Request $request): Response
    {
        $this->guardUserLoggedIn($request);

        $directoryIterator = new \FilesystemIterator(Installer::getConfigPath());
        $fileIterator = new \CallbackFilterIterator(
            new \IteratorIterator($directoryIterator), static function (\SplFileInfo $fileInfo) {
                if (!$fileInfo->isFile()) {
                    return false;
                }

                if (!preg_match('/dataport_(\d+).json$/', $fileInfo->getFilename())) {
                    return false;
                }

                return true;
            }
        );

        $user = Tool\Admin::getCurrentUser();
        $output = [];
        /** @var SplFileInfo $fileInfo */
        foreach ($fileIterator as $fileInfo) {
            $data = json_decode(file_get_contents($fileInfo->getRealPath()), true);

            if (!Dataport::canDataportBeConfiguredBy($data[Installer::TABLE_DATAPORT]['id'], $user)) {
                continue;
            }

            $output[$data[Installer::TABLE_DATAPORT]['id']] = [
                'name' => $data[Installer::TABLE_DATAPORT]['name'],
                'lastModified' => $fileInfo->getMTime()
            ];
        }

        return new JsonResponse($output);
    }

    /**
     * @Route("/api/dataports/{dataportId}", name="sylphen_databridge_api_dataport_configuration", methods={"GET"})
     * @param Request $request
     * @return Response
     */
    public function exportConfigurationAction($dataportId, Request $request): Response
    {
        $this->guardUserLoggedIn($request);

        $dataports = Dataport::getInstance();
        $dataport = $dataports->get($dataportId);

        if (!$dataport) {
            $message = 'Dataport '.$dataportId.' not found'.PHP_EOL;

            if (!is_numeric($dataportId)) {
                $allDataports = $dataports->find();
                $distances = [];
                foreach ($allDataports as $dataport) {
                    $distances[$dataport['name']] = levenshtein($dataportId, $dataport['name']);
                }
                asort($distances);

                $distances = array_filter($distances, static function ($distance) {
                    return $distance < 5;
                });

                if (count($distances) > 0) {
                    $message .= 'Did you mean one of the following?'.PHP_EOL;
                    foreach (array_keys($distances) as $similarDataportName) {
                        $message .= '* '.$similarDataportName.PHP_EOL;
                    }
                }
            }

            return new JsonResponse(['error' => $message], JsonResponse::HTTP_NOT_FOUND);
        }

        $user = Tool\Admin::getCurrentUser();
        if (!Dataport::canDataportBeConfiguredBy($dataport['id'], $user)) {
            return new JsonResponse(['error' => 'You are not allowed to load the dataport configuration'], JsonResponse::HTTP_FORBIDDEN);
        }

        $dataportDefinitionFile = Installer::getConfigPath().'/dataport_'.$dataport['id'].'.json';

        if (!file_exists($dataportDefinitionFile)) {
            $dataportData = $dataports->exportDataport($dataport['id']);
        } else {
            $dataportData = file_get_contents($dataportDefinitionFile);
        }

        return new JsonResponse($dataportData, Response::HTTP_OK, [], is_string($dataportData));
    }

    public static function getApiKeyForCurrentUser() {
        $user = Tool\Admin::getCurrentUser();
        $apiKey = PimcoreDbRepository::getInstance()->findOneInSql('SELECT api_key FROM '.Installer::TABLE_API_KEYS.' api_keys WHERE api_keys.users_id=? AND api_keys.api_key!=\'\' AND api_keys.api_key IS NOT NULL AND (api_keys.valid_to IS NULL OR api_keys.valid_to >= NOW())', [$user->getId()]);
        if (!$apiKey && method_exists($user, 'getApiKey')) {
            $apiKey = $user->getApiKey();
        }

        if(!$apiKey) {
            $existingApiKey = ApiKeys::getInstance()->findOne(['users_id = ?' => $user->getId()]);
            if (!$existingApiKey['api_key']) {
                ApiKeys::getInstance()->createOrUpdate(
                    [
                        'users_id' => $user->getId(),
                        'api_key' => md5(uniqid()),
                        'valid_to' => null
                    ]
                );

                $apiKey = PimcoreDbRepository::getInstance()->findOneInSql(
                    'SELECT api_key FROM '.Installer::TABLE_API_KEYS.' api_keys WHERE api_keys.users_id=? AND api_keys.api_key!=\'\' AND api_keys.api_key IS NOT NULL AND (api_keys.valid_to IS NULL OR api_keys.valid_to >= NOW())',
                    [$user->getId()]
                );
            }
        }

        return $apiKey;
    }
}