<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Helper\ObjectCacheActivator;
use LiteSpeed\Cache\Module\TabManager;
use LiteSpeed\Cache\Update\ModuleUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ToolsController extends AbstractController
{
    use NavPillsTrait;

    public function redirectAction(): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_tools_purge');
    }

    public function indexAction(Request $request): Response
    {
        // Handle POST actions
        if ($request->isMethod('POST')) {
            if ($request->request->has('submitPurgeSelection')) {
                $this->handlePurgeSelection($request);
                return $this->redirectToRoute('admin_litespeedcache_tools_purge');
            }
            if ($request->request->has('submitPurgeId')) {
                $this->handlePurgeIds($request);
                return $this->redirectToRoute('admin_litespeedcache_tools_purge');
            }
            if ($request->request->has('submitSupport')) {
                $this->handleSupportRequest($request);
                return $this->redirectToRoute('admin_litespeedcache_tools_report');
            }
            if ($request->request->has('clear_access_log')) {
                $paths = [_PS_ROOT_DIR_ . '/var/logs/lscache.log', _PS_ROOT_DIR_ . '/app/logs/lscache.log'];
                foreach ($paths as $path) {
                    if (is_file($path) && is_writable($path)) {
                        file_put_contents($path, '');
                    }
                }
                $this->addFlash('success', $this->trans('Access log cleared.', 'Modules.Litespeedcache.Admin'));
                \PrestaShopLogger::addLog('Access log cleared by admin', 1, null, 'LiteSpeedCache', 0, true);
                return $this->redirectToRoute('admin_litespeedcache_tools_logs');
            }
            if ($request->request->has('submitDebug')) {
                $level = max(0, min(10, (float) $request->request->get('debug_level', 0)));
                $ips = trim($request->request->get('debug_ips', ''));
                $bypass = (bool) $request->request->get('cache_bypass', 0);

                $config = Conf::getInstance();
                $all = $config->get(Conf::ENTRY_ALL);
                $all[Conf::CFG_ALLOW_IPS] = $ips;
                $all[Conf::CFG_DEBUG_HEADER] = (int) $request->request->get('debug_header', 0);
                $all[Conf::CFG_DEBUG] = (int) $request->request->get('debug_log', 0);
                $all[Conf::CFG_DEBUG_LEVEL] = $level;
                $all[Conf::CFG_DEBUG_IPS] = $ips;
                $all[Conf::CFG_DEBUG_URI_INC] = trim($request->request->get('debug_uri_inc', ''));
                $all[Conf::CFG_DEBUG_URI_EXC] = trim($request->request->get('debug_uri_exc', ''));
                $all[Conf::CFG_DEBUG_STR_EXC] = trim($request->request->get('debug_str_exc', ''));
                $config->updateConfiguration(Conf::ENTRY_ALL, $all);

                $wasBypassed = Conf::isBypassed();
                Conf::setBypass($bypass);
                if ($bypass && !$wasBypassed && !headers_sent()) {
                    header('X-LiteSpeed-Purge: *');
                }

                $this->addFlash('success', $this->trans('Debug settings saved.', 'Modules.Litespeedcache.Admin'));
                \PrestaShopLogger::addLog('Debug settings updated. Level: ' . $level . ', Bypass: ' . ($bypass ? 'on' : 'off'), 1, null, 'LiteSpeedCache', 0, true);
                return $this->redirectToRoute('admin_litespeedcache_tools_debug');
            }
        }

        // Handle GET purge actions
        $purgeAction = $request->query->get('purge_action');
        if ($purgeAction) {
            $this->handlePurgeAction($purgeAction);
            return $this->redirectToRoute('admin_litespeedcache_tools_purge');
        }

        // Import/Export actions
        $ieAction = $request->query->get('action');
        if ($ieAction === 'export') {
            return $this->handleExport();
        }
        if ($ieAction === 'reset') {
            $this->handleReset();
            return $this->redirectToRoute('admin_litespeedcache_tools_import_export');
        }

        // Import (POST via Symfony Form)
        $importForm = $this->createForm(\LiteSpeed\Cache\Form\ImportSettingsType::class);
        $importForm->handleRequest($request);
        if ($importForm->isSubmitted() && $importForm->isValid()) {
            $this->handleImport($importForm->get('import_file')->getData());
            return $this->redirectToRoute('admin_litespeedcache_tools_import_export');
        }

        if ($request->query->has('clear_events')) {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'log` WHERE `object_type` = \'LiteSpeedCache\''
            );
            $this->addFlash('success', $this->trans('Module events cleared.', 'Modules.Litespeedcache.Admin'));
            \PrestaShopLogger::addLog('Event log cleared by admin', 1, null, 'LiteSpeedCache', 0, true);
            return $this->redirectToRoute('admin_litespeedcache_tools_logs');
        }

        if ($request->query->has('reinstall_tabs')) {
            $module = \Module::getInstanceByName('litespeedcache');
            if ($module) {
                $tm = new TabManager($module);
                $tm->uninstall();
                $tm->install();
                \Tools::clearSf2Cache();
                $this->addFlash('success', $this->trans('Tabs reinstalled successfully.', 'Modules.Litespeedcache.Admin'));
            }
            return $this->redirectToRoute('admin_litespeedcache_tools_updates');
        }

        $activeTab = $request->attributes->get('tab', $request->query->get('tab', 'purge'));

        // Common lightweight data
        $params = [
            'activeTab'  => $activeTab,
            'accessLogUrl' => $this->generateUrl('admin_litespeedcache_tools_access_log'),
        ];

        // Lazy-load: only gather data for the active tab
        switch ($activeTab) {
            case 'purge':
                if (\Shop::isFeatureActive()) {
                    $shopLevel = (\Shop::getContext() === \Shop::CONTEXT_ALL) ? 0 : 1;
                } else {
                    $shopLevel = -1;
                }
                $params += [
                    'shopLevel'          => $shopLevel,
                    'purgeUrl'           => $this->generateUrl('admin_litespeedcache_tools'),
                    'purgeSelectionValues' => ['purgeby' => '', 'purgeids' => ''],
                    'objEnabled'         => !empty(ObjConfig::getAll()[ObjConfig::OBJ_ENABLE]),
                ];
                break;

            case 'logs':
                $page    = max(1, (int) $request->query->get('page', 1));
                $perPage = 50;
                $params += [
                    'events'         => $this->getEvents($page, $perPage),
                    'totalEvents'    => $this->countEvents(),
                    'page'           => $page,
                    'perPage'        => $perPage,
                    'totalPages'     => max(1, (int) ceil($this->countEvents() / $perPage)),
                    'accessLog'      => $this->getAccessLog(200),
                    'clearEventsUrl' => $this->generateUrl('admin_litespeedcache_tools', ['clear_events' => 1]),
                ];
                break;

            case 'report':
                $config = Conf::getInstance();
                $objCfg = ObjConfig::getAll();
                $cdnCfg = CdnConfig::getAll();
                $employee = $this->getContext()->employee;
                $params += [
                    'report' => [
                        'Module version'    => \Module::getInstanceByName('litespeedcache')->version ?? '-',
                        'LiteSpeed license' => CacheHelper::licenseEnabled() ? 'Active' : 'Not detected',
                        'Cache enabled'     => $config->get(Conf::CFG_ENABLED) ? 'Yes' : 'No',
                        'Guest mode'        => $config->get(Conf::CFG_GUESTMODE) ? 'Yes' : 'No',
                        'Object cache'      => $objCfg[ObjConfig::OBJ_ENABLE] ? $objCfg[ObjConfig::OBJ_METHOD] . ' (' . $objCfg[ObjConfig::OBJ_HOST] . ':' . $objCfg[ObjConfig::OBJ_PORT] . ')' : 'Disabled',
                        'Redis extension'   => extension_loaded('redis') ? 'Loaded' : 'Not loaded',
                        'CDN (Cloudflare)'  => $cdnCfg[CdnConfig::CF_ENABLE] ? 'Enabled' : 'Disabled',
                        'Multishop'         => \Shop::isFeatureActive() ? 'Yes' : 'No',
                        'Debug mode'        => defined('_LITESPEED_DEBUG_') ? (string) _LITESPEED_DEBUG_ : '0',
                    ],
                    'installedModules' => $this->getInstalledModulesList(),
                    'adminData' => [
                        'name'  => $employee->firstname . ' ' . $employee->lastname,
                        'email' => $employee->email,
                        'url'   => \Tools::getShopDomainSsl(true),
                    ],
                    'phpInfo' => [
                        'Web Server'          => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
                        'Server OS'           => PHP_OS . ' ' . php_uname('r'),
                        'Server Architecture' => php_uname('m'),
                        'PHP Version'         => PHP_VERSION,
                        'PHP SAPI'            => PHP_SAPI,
                        'Memory Limit'        => ini_get('memory_limit'),
                        'Max Execution Time'  => ini_get('max_execution_time') . 's',
                        'Max Input Vars'      => ini_get('max_input_vars'),
                        'Upload Max Size'     => ini_get('upload_max_filesize'),
                        'Post Max Size'       => ini_get('post_max_size'),
                        'OPcache'             => function_exists('opcache_get_status') && !empty(@opcache_get_status(false)) ? 'Enabled' : 'Disabled',
                        'MySQL Version'       => \Db::getInstance()->getValue('SELECT VERSION()') ?: '-',
                        'MySQL Engine'        => \Db::getInstance()->getValue("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") ?: '-',
                        'SSL'                 => !empty($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'Yes' : 'No',
                        'Document Root'       => $_SERVER['DOCUMENT_ROOT'] ?? '-',
                        'Temp Directory'      => sys_get_temp_dir(),
                        'Disk Free Space'     => function_exists('disk_free_space') ? round(@disk_free_space('/') / 1073741824, 2) . ' GB' : '-',
                        'PHP Extensions'      => implode(', ', get_loaded_extensions()),
                    ],
                ];
                break;

            case 'htaccess':
                $htaccessFrontPath = _PS_ROOT_DIR_ . '/.htaccess';
                $htaccessAdminPath = _PS_ADMIN_DIR_ . '/.htaccess';
                $params += [
                    'htaccessFrontPath'    => $htaccessFrontPath,
                    'htaccessFrontContent' => is_file($htaccessFrontPath) && is_readable($htaccessFrontPath) ? file_get_contents($htaccessFrontPath) : 'File not found.',
                    'htaccessAdminPath'    => $htaccessAdminPath,
                    'htaccessAdminContent' => is_file($htaccessAdminPath) && is_readable($htaccessAdminPath) ? file_get_contents($htaccessAdminPath) : 'File not found.',
                ];
                break;

            case 'debug':
                $config = Conf::getInstance();
                $params += [
                    'cacheBypassed' => Conf::isBypassed(),
                    'debugHeader'   => (int) $config->get(Conf::CFG_DEBUG_HEADER),
                    'debugLog'      => (int) $config->get(Conf::CFG_DEBUG),
                    'debugLevel'    => (float) $config->get(Conf::CFG_DEBUG_LEVEL),
                    'debugIps'      => $config->get(Conf::CFG_DEBUG_IPS) ?: '',
                    'debugUriInc'   => $config->get(Conf::CFG_DEBUG_URI_INC) ?: '',
                    'debugUriExc'   => $config->get(Conf::CFG_DEBUG_URI_EXC) ?: '',
                    'debugStrExc'   => $config->get(Conf::CFG_DEBUG_STR_EXC) ?: '',
                    'logPath'       => $this->getLogPath(),
                    'reinstallTabsUrl' => $this->generateUrl('admin_litespeedcache_tools', ['reinstall_tabs' => 1]),
                ];
                break;

            case 'import-export':
                $params += [
                    'importForm' => $importForm->createView(),
                    'lastExport' => $this->getLastAction('export'),
                    'lastImport' => $this->getLastAction('import'),
                ];
                break;

            case 'updates':
                $params += [
                    'currentVersion'  => \Module::getInstanceByName('litespeedcache')->version ?? '-',
                    'releases'        => $this->getReleasesData(),
                    'backups'         => $this->getModuleUpdater()->getBackups(),
                    'updateActionUrl' => $this->generateUrl('admin_litespeedcache_tools_update_apply'),
                    'rollbackActionUrl' => $this->generateUrl('admin_litespeedcache_tools_update_rollback'),
                    'deleteBackupUrl' => $this->generateUrl('admin_litespeedcache_tools_update_delete_backup'),
                ];
                break;
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/tools.html.twig', $params, $request);
    }

    public function accessLogAction(): JsonResponse
    {
        return new JsonResponse(['log' => $this->getAccessLog(200)]);
    }

    // ---- Updates ----------------------------------------------------------------

    public function updateAction(Request $request): Response
    {
        $updater = $this->getModuleUpdater();
        $currentVersion = \Module::getInstanceByName('litespeedcache')->version ?? '0';
        $d = 'Modules.Litespeedcache.Admin';

        // Handle update/downgrade
        if ($request->request->has('release_tag')) {
            $tag = $request->request->get('release_tag');
            $releases = $updater->getAvailableReleases();
            $release = null;
            foreach ($releases as $r) {
                if ($r['tag_name'] === $tag) {
                    $release = $r;
                    break;
                }
            }

            if (!$release) {
                $this->addFlash('error', $this->trans('Release not found.', $d));
            } else {
                $result = $updater->updateToRelease($release, $currentVersion);
                $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);
                if ($result['success']) {
                    \PrestaShopLogger::addLog('Module updated to ' . $tag . ' from ' . $currentVersion, 1, null, 'LiteSpeedCache', 0, true);
                }
            }

            return $this->redirectToRoute('admin_litespeedcache_tools_updates');
        }

        // Handle manual backup
        if ($request->request->has('create_backup')) {
            try {
                $backupFile = $updater->createBackup($currentVersion);
                $this->addFlash('success', $this->trans('Backup created: %s', $d, [$backupFile]));
                \PrestaShopLogger::addLog('Manual backup created: ' . $backupFile, 1, null, 'LiteSpeedCache', 0, true);
            } catch (\Throwable $e) {
                $this->addFlash('error', $this->trans('Failed to create backup: %s', $d, [$e->getMessage()]));
            }

            return $this->redirectToRoute('admin_litespeedcache_tools_updates');
        }

        // Handle rollback
        if ($request->request->has('backup_file')) {
            $file = basename($request->request->get('backup_file'));
            $result = $updater->rollbackFromBackup($file);
            $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);
            if ($result['success']) {
                \PrestaShopLogger::addLog('Module rolled back from backup: ' . $file, 2, null, 'LiteSpeedCache', 0, true);
            }

            return $this->redirectToRoute('admin_litespeedcache_tools_updates');
        }

        // Handle delete backup
        if ($request->request->has('delete_backup')) {
            $file = basename($request->request->get('delete_backup'));
            $updater->deleteBackup($file);
            $this->addFlash('success', $this->trans('Backup deleted.', $d));

            return $this->redirectToRoute('admin_litespeedcache_tools_updates');
        }

        return $this->redirectToRoute('admin_litespeedcache_tools_updates');
    }

    private function getModuleUpdater(): ModuleUpdater
    {
        return new ModuleUpdater(_PS_MODULE_DIR_ . 'litespeedcache/');
    }

    private function getReleasesData(): array
    {
        $updater = $this->getModuleUpdater();
        $currentVersion = \Module::getInstanceByName('litespeedcache')->version ?? '0';
        $releases = $updater->getAvailableReleases();

        foreach ($releases as &$release) {
            $release['status'] = $updater->classifyRelease($release['tag_name'], $currentVersion);
            $release['clean_version'] = ModuleUpdater::cleanTag($release['tag_name']);
        }

        return $releases;
    }

    private function getInstalledModulesList(): array
    {
        $installedModules = \Module::getModulesInstalled();
        $list = [];
        foreach ($installedModules as $mod) {
            $list[] = [
                'name'    => $mod['name'],
                'version' => $mod['version'] ?? '-',
                'active'  => !empty($mod['active']),
            ];
        }
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    // ---- Support ----------------------------------------------------------------

    private function handleSupportRequest(Request $request): void
    {
        $name     = trim($request->request->get('support_name', ''));
        $email    = trim($request->request->get('support_email', ''));
        $url      = trim($request->request->get('support_url', ''));
        $comments = trim($request->request->get('support_comments', ''));

        if (!$name || !$email || !$comments) {
            $this->addFlash('error', $this->trans('Please fill in all required fields (Name, Email, Comments).', 'Modules.Litespeedcache.Admin'));
            return;
        }

        $subject = '⚡ LiteSpeed Cache Support — ' . $url;

        // Gather all report data (same as displayed on screen)
        $config = Conf::getInstance();
        $objCfg = ObjConfig::getAll();
        $cdnCfg = CdnConfig::getAll();

        $report = [
            'Module version'      => \Module::getInstanceByName('litespeedcache')->version ?? '-',
            'LiteSpeed license'   => CacheHelper::licenseEnabled() ? 'Active' : 'Not detected',
            'Cache enabled'       => $config->get(Conf::CFG_ENABLED) ? 'Yes' : 'No',
            'Guest mode'          => $config->get(Conf::CFG_GUESTMODE) ? 'Yes' : 'No',
            'Object cache'        => $objCfg[ObjConfig::OBJ_ENABLE] ? $objCfg[ObjConfig::OBJ_METHOD] . ' (' . $objCfg[ObjConfig::OBJ_HOST] . ':' . $objCfg[ObjConfig::OBJ_PORT] . ')' : 'Disabled',
            'Redis extension'     => extension_loaded('redis') ? 'Loaded' : 'Not loaded',
            'CDN (Cloudflare)'    => $cdnCfg[CdnConfig::CF_ENABLE] ? 'Enabled' : 'Disabled',
            'Multishop'           => \Shop::isFeatureActive() ? 'Yes' : 'No',
            'Debug mode'          => defined('_LITESPEED_DEBUG_') ? (string) _LITESPEED_DEBUG_ : '0',
        ];

        $phpInfo = [
            'Web Server'          => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
            'Server OS'           => PHP_OS . ' ' . php_uname('r'),
            'Server Architecture' => php_uname('m'),
            'PHP Version'         => PHP_VERSION,
            'PHP SAPI'            => PHP_SAPI,
            'Memory Limit'        => ini_get('memory_limit'),
            'Max Execution Time'  => ini_get('max_execution_time') . 's',
            'Max Input Vars'      => ini_get('max_input_vars'),
            'Upload Max Size'     => ini_get('upload_max_filesize'),
            'Post Max Size'       => ini_get('post_max_size'),
            'OPcache'             => function_exists('opcache_get_status') && !empty(@opcache_get_status(false)) ? 'Enabled' : 'Disabled',
            'MySQL Version'       => \Db::getInstance()->getValue('SELECT VERSION()') ?: '-',
            'MySQL Engine'        => \Db::getInstance()->getValue("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") ?: '-',
            'SSL'                 => !empty($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'Yes' : 'No',
            'Document Root'       => $_SERVER['DOCUMENT_ROOT'] ?? '-',
            'Temp Directory'      => sys_get_temp_dir(),
            'Disk Free Space'     => function_exists('disk_free_space') ? round(@disk_free_space('/') / 1073741824, 2) . ' GB' : '-',
            'PHP Extensions'      => implode(', ', get_loaded_extensions()),
        ];

        $installedModules = \Module::getModulesInstalled();
        $moduleList = [];
        foreach ($installedModules as $mod) {
            $moduleList[] = [
                'name'    => $mod['name'],
                'version' => $mod['version'] ?? '-',
                'active'  => !empty($mod['active']),
            ];
        }
        usort($moduleList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        // Build HTML body with tables
        $body = $this->buildSupportEmailHtml($name, $email, $url, $comments, $report, $phpInfo, $moduleList);

        // Build plain-text version
        $bodyTxt = "Name: {$name}\nEmail: {$email}\nURL: {$url}\nPrestaShop: " . _PS_VERSION_ . "\n\nComments:\n{$comments}\n\n";
        $bodyTxt .= "=== MODULE CONFIG ===\n";
        foreach ($report as $k => $v) {
            $bodyTxt .= "{$k}: {$v}\n";
        }
        $bodyTxt .= "\n=== SERVER INFO ===\n";
        foreach ($phpInfo as $k => $v) {
            $bodyTxt .= "{$k}: {$v}\n";
        }
        $bodyTxt .= "\n=== INSTALLED MODULES (" . count($moduleList) . ") ===\n";
        foreach ($moduleList as $mod) {
            $bodyTxt .= $mod['name'] . ' v' . $mod['version'] . ' (' . ($mod['active'] ? 'active' : 'inactive') . ")\n";
        }

        $templatePath = _PS_MODULE_DIR_ . 'litespeedcache/mails/';
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $iso = \Language::getIsoById($idLang);

        if (!is_dir($templatePath . $iso)) {
            $enLang = \Language::getIdByIso('en');
            if ($enLang) {
                $idLang = (int) $enLang;
            }
        }

        // Temporarily disable shop name prefix in subject for this email only
        $prefixBackup = \Configuration::get('PS_MAIL_SUBJECT_PREFIX');
        \Configuration::updateValue('PS_MAIL_SUBJECT_PREFIX', 0);

        $sent = \Mail::send(
            $idLang,
            'litespeedcache_support',
            $subject,
            ['{body}' => $body, '{body_txt}' => $bodyTxt],
            'info@ecomlabs.es',
            'EcomLabs Support',
            $email,
            $name,
            null,
            null,
            $templatePath
        );

        \Configuration::updateValue('PS_MAIL_SUBJECT_PREFIX', $prefixBackup);

        if ($sent) {
            $this->addFlash('success', $this->trans('Support request sent successfully.', 'Modules.Litespeedcache.Admin'));
            \PrestaShopLogger::addLog('Support request sent by ' . $name, 1, null, 'LiteSpeedCache', 0, true);
        } else {
            $this->addFlash('error', $this->trans('Failed to send support request. Please check your email configuration.', 'Modules.Litespeedcache.Admin'));
        }
    }

    private function buildSupportEmailHtml(
        string $name,
        string $email,
        string $url,
        string $comments,
        array $report,
        array $phpInfo,
        array $moduleList
    ): string {
        $moduleVersion = \Module::getInstanceByName('litespeedcache')->version ?? '-';
        $date = date('d M Y, H:i');
        $rowIdx = 0;

        $html = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f5; padding:30px 0;">';
        $html .= '<tr><td align="center">';
        $html .= '<table width="700" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';

        // Header
        $html .= '<tr><td style="background:linear-gradient(135deg,#1b1464 0%,#25b9d7 100%); padding:32px 40px;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
        $html .= '<td><span style="font-size:28px; font-weight:700; color:#ffffff; letter-spacing:-0.5px;">&#9889; LiteSpeed Cache</span>';
        $html .= '<br><span style="font-size:13px; color:rgba(255,255,255,0.75); letter-spacing:0.5px;">SUPPORT REQUEST</span></td>';
        $html .= '<td align="right" style="vertical-align:top;"><span style="font-size:12px; color:rgba(255,255,255,0.6);">' . $date . '</span>';
        $html .= '<br><span style="display:inline-block; margin-top:6px; background:rgba(255,255,255,0.15); color:#fff; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;">v' . htmlspecialchars($moduleVersion) . '</span></td>';
        $html .= '</tr></table></td></tr>';

        // Body padding wrapper
        $html .= '<tr><td style="padding:0 40px 40px;">';

        // --- Support Request ---
        $html .= $this->emailSectionHeader('Contact Details');
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:28px;">';
        $rowIdx = 0;
        foreach ([
            'Name' => htmlspecialchars($name),
            'Email' => '<a href="mailto:' . htmlspecialchars($email) . '" style="color:#25b9d7; text-decoration:none;">' . htmlspecialchars($email) . '</a>',
            'URL' => '<a href="' . htmlspecialchars($url) . '" style="color:#25b9d7; text-decoration:none;">' . htmlspecialchars($url) . '</a>',
            'PrestaShop' => _PS_VERSION_,
        ] as $label => $value) {
            $html .= $this->emailRow($label, $value, $rowIdx++);
        }
        $html .= '</table>';

        // Comments block
        $html .= '<div style="background:#f8f9fa; border-left:4px solid #25b9d7; border-radius:0 8px 8px 0; padding:16px 20px; margin-bottom:28px;">';
        $html .= '<div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#868e96; margin-bottom:8px; font-weight:600;">Comments</div>';
        $html .= '<div style="font-size:14px; color:#343a40; line-height:1.6;">' . nl2br(htmlspecialchars($comments)) . '</div>';
        $html .= '</div>';

        // --- Module Config ---
        $html .= $this->emailSectionHeader('Module Config');
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:28px;">';
        $rowIdx = 0;
        foreach ($report as $label => $value) {
            $styledValue = htmlspecialchars($value);
            if ($value === 'Yes' || $value === 'Active' || $value === 'Enabled' || $value === 'Loaded') {
                $styledValue = '<span style="display:inline-block; background:#d3f9d8; color:#2b8a3e; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:600;">' . $styledValue . '</span>';
            } elseif ($value === 'No' || $value === 'Disabled' || $value === 'Not detected' || $value === 'Not loaded') {
                $styledValue = '<span style="display:inline-block; background:#ffe3e3; color:#c92a2a; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:600;">' . $styledValue . '</span>';
            }
            $html .= $this->emailRow($label, $styledValue, $rowIdx++);
        }
        $html .= '</table>';

        // --- Installed Modules ---
        $html .= $this->emailSectionHeader('Installed Modules (' . count($moduleList) . ')');
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:28px;">';
        $html .= '<tr>'
            . '<td style="padding:10px 16px; background:#343a40; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600;">Module</td>'
            . '<td style="padding:10px 16px; background:#343a40; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600; width:80px;">Version</td>'
            . '<td style="padding:10px 16px; background:#343a40; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600; width:90px; text-align:center;">Status</td>'
            . '</tr>';
        foreach ($moduleList as $i => $mod) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $badge = $mod['active']
                ? '<span style="display:inline-block; background:#d3f9d8; color:#2b8a3e; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;">Active</span>'
                : '<span style="display:inline-block; background:#e9ecef; color:#868e96; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;">Disabled</span>';
            $html .= '<tr style="background:' . $bg . ';">'
                . '<td style="padding:8px 16px; font-size:13px; color:#343a40; border-top:1px solid #f1f3f5;">' . htmlspecialchars($mod['name']) . '</td>'
                . '<td style="padding:8px 16px; font-size:13px; color:#868e96; border-top:1px solid #f1f3f5;">' . htmlspecialchars($mod['version']) . '</td>'
                . '<td style="padding:8px 16px; border-top:1px solid #f1f3f5; text-align:center;">' . $badge . '</td>'
                . '</tr>';
        }
        $html .= '</table>';

        // --- Server Info ---
        $html .= $this->emailSectionHeader('Server Info');
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:28px;">';
        $rowIdx = 0;
        foreach ($phpInfo as $label => $value) {
            $html .= $this->emailRow($label, htmlspecialchars($value), $rowIdx++);
        }
        $html .= '</table>';

        // Close body padding
        $html .= '</td></tr>';

        // Footer
        $html .= '<tr><td style="background:#f8f9fa; border-top:1px solid #e9ecef; padding:20px 40px; text-align:center;">';
        $html .= '<span style="font-size:12px; color:#adb5bd;">Sent automatically from LiteSpeed Cache module for PrestaShop</span>';
        $html .= '</td></tr>';

        $html .= '</table>';
        $html .= '</td></tr></table>';

        return $html;
    }

    private function emailSectionHeader(string $title): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 12px;"><tr>'
            . '<td style="font-size:15px; font-weight:700; color:#343a40; letter-spacing:-0.3px; padding-bottom:8px; border-bottom:2px solid #25b9d7;">' . htmlspecialchars($title) . '</td>'
            . '</tr></table>';
    }

    private function emailRow(string $label, string $value, int $index): string
    {
        $bg = ($index % 2 === 0) ? '#ffffff' : '#f8f9fa';
        return '<tr style="background:' . $bg . ';">'
            . '<td style="padding:10px 16px; font-size:13px; font-weight:600; color:#495057; width:200px; border-top:1px solid #f1f3f5;">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:10px 16px; font-size:13px; color:#343a40; border-top:1px solid #f1f3f5;">' . $value . '</td>'
            . '</tr>';
    }

    // ---- Import/Export helpers -------------------------------------------------

    private function handleExport(): Response
    {
        $data = [
            'module'     => 'litespeedcache',
            'version'    => \Module::getInstanceByName('litespeedcache')->version ?? '0',
            'date'       => date('Y-m-d H:i:s'),
            'global'     => json_decode(\Configuration::getGlobalValue(Conf::ENTRY_ALL) ?: '{}', true),
            'shop'       => json_decode(\Configuration::get(Conf::ENTRY_SHOP) ?: '{}', true),
            'module_cfg' => json_decode(\Configuration::get(Conf::ENTRY_MODULE) ?: '{}', true),
            'cdn'        => json_decode(\Configuration::getGlobalValue(CdnConfig::ENTRY) ?: '{}', true),
            'object'     => json_decode(\Configuration::getGlobalValue(ObjConfig::ENTRY) ?: '{}', true),
            'exclusions' => json_decode(\Configuration::getGlobalValue(ExclusionsConfig::ENTRY) ?: '{}', true),
            'advanced'   => json_decode(\Configuration::getGlobalValue('LITESPEED_CACHE_ADVANCED') ?: '{}', true),
            'bypass'     => (int) \Configuration::getGlobalValue('LITESPEED_CACHE_BYPASS'),
        ];

        $domain = \Configuration::get('PS_SHOP_DOMAIN') ?: 'localhost';
        $filename = 'LSC_cfg-' . $domain . '-' . date('Ymd_His') . '.json';

        \Configuration::updateGlobalValue('LITESPEED_LAST_EXPORT', json_encode([
            'file' => $filename,
            'date' => date('Y-m-d H:i:s'),
        ]));

        \PrestaShopLogger::addLog('Settings exported: ' . $filename, 1, null, 'LiteSpeedCache', 0, true);

        $response = new JsonResponse($data);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    private function handleImport($file): void
    {
        $d = 'Modules.Litespeedcache.Admin';

        if (!$file || !$file->isValid()) {
            $this->addFlash('error', $this->trans('Invalid file.', $d));
            return;
        }

        $content = file_get_contents($file->getPathname());
        $data = json_decode($content, true);

        if (!is_array($data) || empty($data['module']) || $data['module'] !== 'litespeedcache') {
            $this->addFlash('error', $this->trans('Invalid configuration file.', $d));
            return;
        }

        if (isset($data['global'])) {
            \Configuration::updateGlobalValue(Conf::ENTRY_ALL, json_encode($data['global']));
        }
        if (isset($data['shop'])) {
            \Configuration::updateValue(Conf::ENTRY_SHOP, json_encode($data['shop']));
        }
        if (isset($data['module_cfg'])) {
            \Configuration::updateValue(Conf::ENTRY_MODULE, json_encode($data['module_cfg']));
        }
        if (isset($data['cdn'])) {
            \Configuration::updateGlobalValue(CdnConfig::ENTRY, json_encode($data['cdn']));
        }
        if (isset($data['object'])) {
            \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($data['object']));
        }
        if (isset($data['exclusions'])) {
            \Configuration::updateGlobalValue(ExclusionsConfig::ENTRY, json_encode($data['exclusions']));
        }
        if (isset($data['advanced'])) {
            \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode($data['advanced']));
        }
        if (isset($data['bypass'])) {
            \Configuration::updateGlobalValue('LITESPEED_CACHE_BYPASS', (int) $data['bypass']);
        }

        // Reset singletons so they pick up imported values
        CdnConfig::reset();
        ObjConfig::reset();
        ExclusionsConfig::reset();

        // Regenerate .htaccess if cache settings changed
        if (isset($data['global'])) {
            $global = $data['global'];
            CacheHelper::htAccessUpdate(
                (bool) ($global[Conf::CFG_ENABLED] ?? false),
                (($global[Conf::CFG_GUESTMODE] ?? 0) == 1),
                (bool) ($global[Conf::CFG_DIFFMOBILE] ?? false)
            );
        }

        // Regenerate Redis connection config if object cache settings changed
        if (isset($data['object'])) {
            $obj = $data['object'];
            if (!empty($obj[ObjConfig::OBJ_ENABLE])) {
                ObjectCacheActivator::enable($obj);
            } else {
                ObjectCacheActivator::disable();
            }
        }

        \Configuration::updateGlobalValue('LITESPEED_LAST_IMPORT', json_encode([
            'file' => $file->getClientOriginalName(),
            'date' => date('Y-m-d H:i:s'),
        ]));

        \PrestaShopLogger::addLog('Settings imported from: ' . $file->getClientOriginalName(), 1, null, 'LiteSpeedCache', 0, true);
        $this->addFlash('success', $this->trans('Settings imported successfully.', $d));
    }

    private function handleReset(): void
    {
        $config = Conf::getInstance();

        \Configuration::updateGlobalValue(Conf::ENTRY_ALL, json_encode($config->getDefaultConfData(Conf::ENTRY_ALL)));
        \Configuration::updateValue(Conf::ENTRY_SHOP, json_encode($config->getDefaultConfData(Conf::ENTRY_SHOP)));
        \Configuration::updateValue(Conf::ENTRY_MODULE, json_encode($config->getDefaultConfData(Conf::ENTRY_MODULE)));
        \Configuration::updateGlobalValue(CdnConfig::ENTRY, json_encode(CdnConfig::getDefaults()));
        \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode(ObjConfig::getDefaults()));
        \Configuration::updateGlobalValue(ExclusionsConfig::ENTRY, json_encode(ExclusionsConfig::getDefaults()));
        \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
            'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 0,
        ]));
        \Configuration::updateGlobalValue('LITESPEED_CACHE_BYPASS', 0);

        CdnConfig::reset();
        ObjConfig::reset();
        ExclusionsConfig::reset();

        \PrestaShopLogger::addLog('All settings reset to defaults', 2, null, 'LiteSpeedCache', 0, true);
        $this->addFlash('success', $this->trans('All settings have been reset to defaults.', 'Modules.Litespeedcache.Admin'));
    }

    private function getLastAction(string $type): ?array
    {
        $key = $type === 'export' ? 'LITESPEED_LAST_EXPORT' : 'LITESPEED_LAST_IMPORT';
        $raw = \Configuration::getGlobalValue($key);
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['file'])) {
                return $data;
            }
        }
        return null;
    }

    // ---- Purge helpers --------------------------------------------------------


    private function handlePurgeAction(string $action): void
    {
        $d = 'Modules.Litespeedcache.Admin';

        switch ($action) {
            case 'home':
                if ($this->doPurge(['H'])) {
                    $this->addFlash('success', $this->trans('Home page cache purged.', $d));
                }
                break;

            case 'pages':
                if ($this->doPurge('*')) {
                    $this->addFlash('success', $this->trans('All pages cache purged.', $d));
                }
                break;

            case '404':
                if ($this->doPurge(['D404'])) {
                    $this->addFlash('success', $this->trans('404 error pages cache purged.', $d));
                }
                break;

            case 'lscache':
                if ($this->doPurge('*')) {
                    $this->addFlash('success', $this->trans('All LSCache entries purged.', $d));
                }
                break;

            case 'cssjs':
                if (\Tools::clearSf2Cache()) {
                    // Also clear Smarty cache for CSS/JS
                    \Tools::clearCache();
                }
                $this->addFlash('success', $this->trans('CSS/JS cache purged.', $d));
                break;

            case 'object':
                $objCfg = ObjConfig::getAll();
                if (!empty($objCfg[ObjConfig::OBJ_ENABLE])) {
                    try {
                        $host = $objCfg[ObjConfig::OBJ_HOST] ?? 'localhost';
                        $port = (int) ($objCfg[ObjConfig::OBJ_PORT] ?? 6379);
                        $redis = new \Redis();
                        if ($redis->connect($host, $port, 2.0)) {
                            if (!empty($objCfg[ObjConfig::OBJ_PASSWORD])) {
                                $redis->auth($objCfg[ObjConfig::OBJ_PASSWORD]);
                            }
                            $db = (int) ($objCfg[ObjConfig::OBJ_REDIS_DB] ?? 0);
                            if ($db > 0) {
                                $redis->select($db);
                            }
                            $redis->flushDB();
                            $redis->close();
                            $this->addFlash('success', $this->trans('Object cache (Redis) flushed.', $d));
                        } else {
                            $this->addFlash('error', $this->trans('Could not connect to Redis.', $d));
                        }
                    } catch (\Throwable $e) {
                        $this->addFlash('error', $this->trans('Object cache flush failed: %s', $d,[$e->getMessage()]));
                    }
                } else {
                    $this->addFlash('warning', $this->trans('Object cache is not enabled.', $d));
                }
                break;

            case 'purge_all':
                if ($this->doPurge('*')) {
                    $this->addFlash('success', $this->trans('All cache entries purged.', $d));
                }
                break;

            case 'flush_all':
                if ($this->doPurge(1, 'ALL')) {
                    $this->addFlash('success', $this->trans('Entire cache storage flushed.', $d));
                }
                break;
        }

        \PrestaShopLogger::addLog('Purge action: ' . $action, 1, null, 'LiteSpeedCache', 0, true);
    }

    private function handlePurgeSelection(Request $request): void
    {
        $tags = [];
        $info = [];
        $map = [
            'cbPurge_home'     => ['H',    'Home Page'],
            'cbPurge_404'      => ['D404', 'All 404 Pages'],
            'cbPurge_brand'    => ['M',    'All Brands Pages'],
            'cbPurge_supplier' => ['L',    'All Suppliers Pages'],
            'cbPurge_sitemap'  => ['SP',   'Site Map'],
            'cbPurge_cms'      => ['G',    'All CMS Pages'],
            'cbPurge_pc'       => ['N',    'All Product Comments'],
            'cbPurge_priv'     => ['PRIV', 'All Private ESI Blocks'],
        ];
        foreach ($map as $cb => [$tag, $label]) {
            if ($request->request->get($cb)) {
                $tags[] = $tag;
                $info[] = $label;
                if ($cb === 'cbPurge_priv') {
                    CacheHelper::clearInternalCache();
                }
            }
        }
        if ($request->request->get('cbPurge_search')) {
            $tags[] = 'SR'; $tags[] = 'C'; $tags[] = 'P';
            $info[] = 'All Categories and Products Pages';
        }
        if ($cid = $request->request->get('rcats')) {
            $tags[] = 'C' . $cid;
            $info[] = 'Category with ID ' . $cid;
        }
        if ($tags) {
            if ($this->doPurge($tags)) {
                $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush cached pages: %s', 'Modules.Litespeedcache.Admin', [implode(', ', $info)]));
            }
        } else {
            $this->addFlash('warning', $this->trans('Nothing selected. No action taken.', 'Modules.Litespeedcache.Admin'));
        }
    }

    private function handlePurgeIds(Request $request): void
    {
        $by = $request->request->get('purgeby');
        $id = $request->request->get('purgeids', '');
        $preMap = [
            'prod' => 'P', 'cat' => 'C', 'brand' => 'M', 'supplier' => 'L',
            'cms' => 'G', 'pc' => 'N', 'shop' => 'S',
        ];
        if (!isset($preMap[$by])) {
            $this->addFlash('error', $this->trans('Illegal entrance', 'Modules.Litespeedcache.Admin'));
            return;
        }
        $pre  = $preMap[$by];
        $ids  = preg_split('/[\s,]+/', $id, -1, PREG_SPLIT_NO_EMPTY);
        $tags = [];
        $ok   = !empty($ids);
        foreach ($ids as $i) {
            if (((string)((int)$i) === (string)$i) && ((int)$i > 0)) {
                $tags[] = $pre . $i;
            } else {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            $this->addFlash('error', $this->trans('Please enter valid IDs', 'Modules.Litespeedcache.Admin'));
            return;
        }
        if ($this->doPurge($tags)) {
            $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush cached pages: %s %s', 'Modules.Litespeedcache.Admin', [$by, implode(', ', $ids)]));
        }
    }

    private function doPurge($tags, string $key = 'public'): bool
    {
        $enabled = Conf::getInstance()->get(Conf::CFG_ENABLED);
        if ($enabled || $tags === '*' || $tags === 1) {
            \Hook::exec('litespeedCachePurge', ['from' => 'AdminLiteSpeedCacheTools', $key => $tags]);
            if (!headers_sent() && ($tags === '*' || $tags === 1)) {
                header('X-LiteSpeed-Purge: *');
            }
            return true;
        }
        $this->addFlash('warning', $this->trans('No action taken. This Module is not enabled.', 'Modules.Litespeedcache.Admin'));
        return false;
    }

    // ---- Log helpers ----------------------------------------------------------

    private function getEvents(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $sql = new \DbQuery();
        $sql->select('l.severity, l.message, l.date_add, e.firstname, e.lastname');
        $sql->from('log', 'l');
        $sql->leftJoin('employee', 'e', 'e.id_employee = l.id_employee');
        $sql->where('l.object_type = \'LiteSpeedCache\'');
        $sql->orderBy('l.date_add DESC');
        $sql->limit($perPage, $offset);
        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];
    }

    private function countEvents(): int
    {
        return (int) \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE `object_type` = \'LiteSpeedCache\''
        );
    }

    private function getAccessLog(int $lines): string
    {
        $paths = [_PS_ROOT_DIR_ . '/var/logs/lscache.log', _PS_ROOT_DIR_ . '/app/logs/lscache.log'];
        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                return $this->tailFile($path, $lines);
            }
        }
        return 'No access log found (lscache.log).';
    }

    private function tailFile(string $path, int $lines): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $start = max(0, $file->key() - $lines);
        $file->seek($start);
        $output = '';
        while (!$file->eof()) {
            $output .= $file->current();
            $file->next();
        }
        return $output;
    }

    private function getLogPath(): string
    {
        $paths = [_PS_ROOT_DIR_ . '/var/logs/lscache.log', _PS_ROOT_DIR_ . '/app/logs/lscache.log'];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return _PS_ROOT_DIR_ . '/var/logs/lscache.log';
    }
}
