<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Sylphen\DataBridgeBundle\Controller {

    use OpenDxp\Controller\KernelControllerEventInterface;
    use OpenDxp\Db;
    use OpenDxp\Logger;
    use OpenDxp\Tool\Admin;
    use OpenDxp\Tool\Session;
    use Symfony\Component\HttpFoundation\RedirectResponse;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpFoundation\StreamedResponse;
    use Symfony\Component\HttpKernel\Event\ControllerEvent;
    use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
    use Symfony\Component\HttpKernel\Profiler\Profiler;
    use Symfony\Component\Routing\Annotation\Route;
    use OpenDxp\Helper\Mail as MailHelper;

    /**
     * @internal
     */
    class AdminerController
    {
        /**
         * @var string
         */
        protected $adminerHome = '';

        /**
         * @Route("/admin/SylphenDataBridgeBundle/adminer", name="data_bridge_adminer")
         *
         * @return Response
         */
        public function adminerAction(?Profiler $profiler, Request $request)
        {
            $this->prepare();

            if ($profiler) {
                $profiler->disable();
            }

            set_error_handler(function () {});

            chdir($this->adminerHome.'adminer');
            ob_start(static function ($html) {
                $html = str_replace('../adminer/static/', '/admin/SylphenDataBridgeBundle/adminer/static/', $html);
                $html = str_replace('static/editing.js', '/admin/SylphenDataBridgeBundle/adminer/static/editing.js', $html);
                $html = str_replace('<link rel="stylesheet" type="text/css" href="../externals/jush/jush.css">', '', $html);
                return $html;
            });
            include($this->adminerHome.'adminer/index.php');

            @ob_get_flush();

            $response = new Response();

            return $this->mergeAdminerHeaders($response);
        }

        /**
         * @Route("/admin/SylphenDataBridgeBundle/adminer/static/{path}", requirements={"path"=".*"})
         * @Route("/admin/SylphenDataBridgeBundle/externals/{path}", requirements={"path"=".*"}, defaults={"type": "external"})
         *
         * @param Request $request
         *
         * @return Response
         */
        public function proxyAction(Request $request)
        {
            $this->prepare();

            $response = new Response();
            $content = '';

            // proxy for resources
            $path = $request->get('path');

            if (preg_match('@\.(css|js|ico|png|jpg|gif)$@', $path)) {
                if ($request->get('type') === 'external') {
                    $path = '../'.$path;
                }

                if (strpos($path, 'static/') === 0) {
                    $path = 'adminer/'.$path;
                }

                $filePath = $this->adminerHome.'/'.$path;
                if (!file_exists($filePath)) {
                    $filePath = $this->adminerHome.'adminer/static/'.$path;
                }
                // it seems that css files need the right content-type (Chrome)
                if (preg_match('@.css$@', $path)) {
                    $response->headers->set('Content-Type', 'text/css');
                } elseif (preg_match('@.js$@', $path)) {
                    $response->headers->set('Content-Type', 'text/javascript');
                }

                if (file_exists($filePath)) {
                    $content = file_get_contents($filePath);

                    if (preg_match('@default.css$@', $path)) {
                        // append custom styles, because in Adminer everything is hardcoded
                        $content .= file_get_contents($this->adminerHome.'designs/konya/adminer.css');
                        $content .= file_get_contents(__DIR__.'/../Resources/public/css/adminer-modifications.css');
                    }
                }
            }

            $response->setContent($content);

            return $this->mergeAdminerHeaders($response);
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
            $user = Admin::getCurrentUser();

            if (!$user || !$user->isAllowed($permission)) {
                Logger::error(
                    'User {user} attempted to access {permission}, but has no permission to do so',
                    [
                        'user' => $user ? $user->getName() : null,
                        'permission' => $permission,
                    ]
                );

                throw new AccessDeniedHttpException('Access denied. User has no permission to access "'.$permission.'".');
            }
        }

        /**
         * @param ControllerEvent $event
         * @return void
         */
        public function prepare()
        {
            // PHP 7.0 compatibility of adminer (throws some warnings)
            ini_set('display_errors', 0);

            $this->checkPermission('plugin_sylphen_data_bridge');

            // call this to keep the session 'open' so that Adminer can write to it
            if(method_exists(Session::class, 'get')) {
                $session = Session::get();
            }

            $this->adminerHome = OPENDXP_COMPOSER_PATH.'/vrana/adminer/';
        }

        /**
         * Merges http-headers set from Adminer via headers function
         * to the Symfony Response Object
         *
         * @param Response $response
         *
         * @return Response
         */
        protected function mergeAdminerHeaders(Response $response)
        {
            if (!headers_sent()) {
                $headersRaw = headers_list();

                foreach ($headersRaw as $header) {
                    $header = explode(':', $header, 2);
                    [$headerKey, $headerValue] = $header;

                    if ($headerKey && $headerValue) {
                        $response->headers->set($headerKey, $headerValue);
                    }
                }

                header_remove();
            }

            return $response;
        }
    }
}

namespace {

    use Adminer\Adminer;
    use Sylphen\DataBridgeBundle\lib\Pim\AdminerPlugins;
    use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
    use OpenDxp\Cache;
    use OpenDxp\Tool\Session;
    use function Adminer\nonce;

    if (!function_exists('adminer_object')) {
        // adminer plugin
        /**
         * @return AdminerPimcore
         */
        function adminer_object()
        {
            $pluginDir = OPENDXP_COMPOSER_PATH.'/vrana/adminer/plugins';

            // required to run any plugin
            include_once $pluginDir.'/plugin.php';

            // autoloader
            foreach (glob($pluginDir.'/*.php') as $filename) {
                include_once $filename;
            }

            $plugins = [
                new \Sylphen\DataBridgeBundle\lib\Pim\AdminerPlugins(),
                new \AdminerFrames(),
                new \AdminerDumpDate,
                new \AdminerDumpJson,
                new \AdminerDumpBz2,
                new \AdminerDumpZip,
                new \AdminerDumpXml,
                new \AdminerDumpAlter
            ];

            // support for SSL (at least for PDO)
            $driverOptions = \OpenDxp\Db::get()->getParams()['driverOptions'] ?? [];
            $ssl = [
                'key' => $driverOptions[\PDO::MYSQL_ATTR_SSL_KEY] ?? null,
                'cert' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CERT] ?? null,
                'ca' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CA] ?? null,
            ];
            if ($ssl['key'] !== null || $ssl['cert'] !== null || $ssl['ca'] !== null) {
                $plugins[] = new \AdminerLoginSsl($ssl);
            }

            if (class_exists(Adminer::class)) {
                class AdminerPimcore extends Adminer
                {
                    /**
                     * @return string
                     */
                    public function name(): string
                    {
                        return '';
                    }

                    public function loginForm(): void {
                        parent::loginForm();
                        echo '<script'.nonce().">document.querySelector('input[name=auth\\\\[db\\\\]]').value='".$this->database()."'; document.querySelector('form').submit()</script>";
                    }

                    /**
                     * @param bool $create
                     *
                     * @return string
                     */
                    public function permanentLogin($create = false): string {
                        if(method_exists(Session::class, 'getSessionId')) {
                            return Session::getSessionId();
                        }
                        return '';
                    }

                    /**
                     * @param string $login
                     * @param string $password
                     *
                     * @return bool
                     */
                    public function login($login, $password): bool {
                        return true;
                    }

                    /**
                     * @return array
                     */
                    public function credentials(): array {
                        $params = \OpenDxp\Db::get()->getParams();

                        $host = $params['host'] ?? null;
                        if ($port = $params['port'] ?? null) {
                            $host .= ':'.$port;
                        }

                        // server, username and password for connecting to database
                        $result = [
                            $host,
                            $params['user'] ?? null,
                            $params['password'] ?? null,
                        ];

                        return $result;
                    }

                    /**
                     * @return string
                     */
                    public function database(): string {
                        $db = \OpenDxp\Db::get();
                        // database name, will be escaped by Adminer
                        return $db->getDatabase();
                    }

                    public function databases($flush = true): array
                    {
                        $cacheKey = 'opendxp_adminer_databases';

                        if (!$return = Cache::load($cacheKey)) {
                            $return = PimcoreDbRepository::getInstance()->findInSql('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');

                            foreach ($return as &$ret) {
                                $ret = $ret['SCHEMA_NAME'];
                            }

                            Cache::save($return, $cacheKey);
                        }

                        return $return;
                    }

                    public function headers(): void {
                        parent::headers();
                        header_remove("X-Frame-Options");
                    }
                }
            }

            return new AdminerPimcore($plugins);
        }
    }
}
