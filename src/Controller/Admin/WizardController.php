<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Config\WarmupConfig;
use LiteSpeed\Cache\Helper\CacheHelper;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WizardController extends FrameworkBundleAdminController
{
    use NavPillsTrait;

    private const HISTORY_KEY = 'LITESPEED_PRESETS_HISTORY';
    private const MAX_BACKUPS = 10;

    public function indexAction(Request $request): Response
    {
        // If wizard was already applied and user is not requesting a re-run, show status page
        $wizardApplied = \Configuration::getGlobalValue('LITESPEED_ACTIVE_PRESET') === 'wizard';
        $rerun = $request->query->get('rerun');

        if ($wizardApplied && !$rerun) {
            return $this->renderStatusPage($request);
        }

        return $this->renderWizard($request);
    }

    private function renderStatusPage(Request $request): Response
    {
        $config = Conf::getInstance();
        $allValues = $config->getAllConfigValues();
        $objCfg = ObjConfig::getAll();
        $cdnCfg = CdnConfig::getAll();
        $warmupCfg = WarmupConfig::getAll();

        $ttl = (int) ($allValues['ttl'] ?? 0);
        if ($ttl >= 1209600) {
            $ttlLabel = 'Rarely (2 weeks)';
        } elseif ($ttl >= 604800) {
            $ttlLabel = 'Weekly (1 week)';
        } else {
            $ttlLabel = 'Daily (1 day)';
        }

        $summary = [
            'Cache' => !empty($allValues['enable']) ? 'Enabled' : 'Disabled',
            'Guest mode' => !empty($allValues['guestmode']) ? 'Yes' : 'No',
            'Separate mobile cache' => !empty($allValues['diff_mobile']) ? 'Yes' : 'No',
            'Customer group pricing' => ($allValues['diff_customergroup'] ?? 0) >= 2 ? 'Yes' : 'No',
            'Cache TTL' => $ttlLabel,
            'Flush all on cache clear' => !empty($allValues['flush_all']) ? 'Yes' : 'No',
            'Flush on order' => ($allValues['flush_prodcat'] ?? 0) == 3 ? 'Product + categories' : 'Custom',
            'Flush home on order' => ($allValues['flush_home'] ?? 0) >= 1 ? 'Yes' : 'No',
            'Redis object cache' => !empty($objCfg[ObjConfig::OBJ_ENABLE]) ? 'Enabled (' . ($objCfg[ObjConfig::OBJ_HOST] ?? 'localhost') . ')' : 'Disabled',
            'Cloudflare CDN' => !empty($cdnCfg[CdnConfig::CF_ENABLE]) ? 'Enabled (' . ($cdnCfg[CdnConfig::CF_DOMAIN] ?? '') . ')' : 'Disabled',
            'Crawler profile' => ucfirst($warmupCfg[WarmupConfig::PROFILE] ?? 'medium'),
            'Vary bypass' => $allValues['vary_bypass'] ?: 'None',
        ];

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/wizard_status.html.twig', [
            'summary' => $summary,
            'rerunUrl' => $this->generateUrl('admin_litespeedcache_wizard', ['rerun' => 1]),
        ], $request);
    }

    private function renderWizard(Request $request): Response
    {
        $detected = [
            'languages' => count(\Language::getLanguages(true)),
            'currencies' => count(\Currency::getCurrencies(false, true)),
            'multistore' => \Shop::isFeatureActive(),
            'geolocation' => (bool) \Configuration::get('PS_GEOLOCATION_ENABLED'),
            'redisAvailable' => extension_loaded('redis'),
            'redisConnectable' => false,
            'redisHost' => 'localhost',
            'redisPort' => 6379,
        ];

        if ($detected['redisAvailable']) {
            $objCfg = ObjConfig::getAll();
            $configuredHost = $objCfg[ObjConfig::OBJ_HOST] ?? 'localhost';
            $port = (int) ($objCfg[ObjConfig::OBJ_PORT] ?? 6379);
            $pass = $objCfg[ObjConfig::OBJ_PASSWORD] ?? '';

            // Try configured host first, then common alternatives
            $hostsToTry = array_unique([$configuredHost, 'redis', 'localhost', '127.0.0.1']);

            foreach ($hostsToTry as $host) {
                try {
                    $r = new \Redis();
                    if ($r->connect($host, $port, 2.0)) {
                        if ($pass !== '') {
                            $r->auth($pass);
                        }
                        $r->ping();
                        $detected['redisConnectable'] = true;
                        $detected['redisHost'] = $host;
                        $detected['redisPort'] = $port;
                        $r->close();
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!$detected['redisConnectable']) {
                $detected['redisHost'] = $configuredHost;
                $detected['redisPort'] = $port;
            }
        }

        $cdnCfg = CdnConfig::getAll();
        $config = Conf::getInstance();

        $shopDomain = \Configuration::get('PS_SHOP_DOMAIN') ?: 'localhost';

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/wizard.html.twig', [
            'detected' => $detected,
            'cdnCfg' => $cdnCfg,
            'shopDomain' => $shopDomain,
            'applyUrl' => $this->generateUrl('admin_litespeedcache_wizard_apply'),
            'currentConfig' => $config->getAllConfigValues(),
        ], $request);
    }

    public function applyAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request data.'], 400);
        }

        // Create backup before applying
        $this->createBackup('Wizard');

        $config = Conf::getInstance();
        $all = $config->get(Conf::ENTRY_ALL);
        $shop = $config->get(Conf::ENTRY_SHOP);

        // Enable cache with guest mode
        $all[Conf::CFG_ENABLED] = 1;
        $all[Conf::CFG_GUESTMODE] = 1;

        // Step 1: Your Store
        $hosting = $data['hosting'] ?? 'vps';
        $diffMobile = !empty($data['diff_mobile']) ? 1 : 0;
        $diffCustomerGroup = !empty($data['diff_customergroup']) ? 2 : 0;
        $catalogFrequency = $data['catalog_frequency'] ?? 'weekly';

        $all[Conf::CFG_DIFFMOBILE] = $diffMobile;
        $shop[Conf::CFG_DIFFCUSTGRP] = $diffCustomerGroup;

        // TTLs based on catalog frequency
        $ttlMap = [
            'daily' => [
                Conf::CFG_PUBLIC_TTL => 86400,
                Conf::CFG_HOME_TTL => 86400,
                Conf::CFG_PRIVATE_TTL => 1800,
                Conf::CFG_404_TTL => 3600,
                Conf::CFG_PCOMMENTS_TTL => 3600,
            ],
            'weekly' => [
                Conf::CFG_PUBLIC_TTL => 604800,
                Conf::CFG_HOME_TTL => 604800,
                Conf::CFG_PRIVATE_TTL => 1800,
                Conf::CFG_404_TTL => 86400,
                Conf::CFG_PCOMMENTS_TTL => 7200,
            ],
            'rarely' => [
                Conf::CFG_PUBLIC_TTL => 1209600,
                Conf::CFG_HOME_TTL => 1209600,
                Conf::CFG_PRIVATE_TTL => 1800,
                Conf::CFG_404_TTL => 86400,
                Conf::CFG_PCOMMENTS_TTL => 86400,
            ],
        ];

        $ttls = $ttlMap[$catalogFrequency] ?? $ttlMap['weekly'];
        foreach ($ttls as $key => $value) {
            $shop[$key] = $value;
        }

        // Auto-set vary_bypass based on detected store context
        $all[Conf::CFG_VARY_BYPASS] = $this->detectVaryBypass();

        // Step 2: Purge settings
        $all[Conf::CFG_FLUSH_ALL] = !empty($data['flush_all']) ? 1 : 0;
        $all[Conf::CFG_FLUSH_PRODCAT] = (int) ($data['flush_prodcat'] ?? 3);
        $all[Conf::CFG_FLUSH_HOME] = (int) ($data['flush_home'] ?? 2);

        // Save main config
        $config->updateConfiguration(Conf::ENTRY_ALL, $all);
        $config->updateConfiguration(Conf::ENTRY_SHOP, $shop);

        // Update .htaccess
        CacheHelper::htAccessUpdate(
            (bool) $all[Conf::CFG_ENABLED],
            $all[Conf::CFG_GUESTMODE] == 1,
            (bool) $all[Conf::CFG_DIFFMOBILE]
        );

        // Step 3: Object Cache (Redis)
        if (!empty($data['redis_enable']) && extension_loaded('redis')) {
            $objCfg = ObjConfig::getAll();
            $objCfg[ObjConfig::OBJ_ENABLE] = 1;
            // Save the detected host (may differ from default, e.g. 'redis' in Docker)
            if (!empty($data['redis_host'])) {
                $objCfg[ObjConfig::OBJ_HOST] = $data['redis_host'];
            }
            \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
            ObjConfig::reset();
        } elseif (isset($data['redis_enable']) && !$data['redis_enable']) {
            $objCfg = ObjConfig::getAll();
            $objCfg[ObjConfig::OBJ_ENABLE] = 0;
            \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
            ObjConfig::reset();
        }

        // Step 4: CDN (Cloudflare)
        if (!empty($data['cf_enable'])) {
            $cdnCfg = CdnConfig::getAll();
            $cdnCfg[CdnConfig::CF_ENABLE] = 1;
            $cdnCfg[CdnConfig::CF_EMAIL] = trim($data['cf_email'] ?? '');
            $cdnCfg[CdnConfig::CF_KEY] = trim($data['cf_api_key'] ?? '');
            $cdnCfg[CdnConfig::CF_DOMAIN] = trim($data['cf_domain'] ?? '');
            CdnConfig::saveAll($cdnCfg);
        } else {
            $cdnCfg = CdnConfig::getAll();
            $cdnCfg[CdnConfig::CF_ENABLE] = 0;
            CdnConfig::saveAll($cdnCfg);
        }

        // Crawler profile based on hosting type
        $profileMap = [
            'shared' => WarmupConfig::PROFILE_LOW,
            'vps' => WarmupConfig::PROFILE_MEDIUM,
            'dedicated' => WarmupConfig::PROFILE_HIGH,
        ];
        $profile = $profileMap[$hosting] ?? WarmupConfig::PROFILE_MEDIUM;
        $warmupCfg = WarmupConfig::getAll();
        $warmupCfg[WarmupConfig::PROFILE] = $profile;
        $profileSettings = WarmupConfig::getProfileSettings($profile);
        if ($profileSettings) {
            $warmupCfg[WarmupConfig::CONCURRENT_REQUESTS] = $profileSettings[WarmupConfig::CONCURRENT_REQUESTS];
            $warmupCfg[WarmupConfig::CRAWL_DELAY] = $profileSettings[WarmupConfig::CRAWL_DELAY];
            $warmupCfg[WarmupConfig::CRAWL_TIMEOUT] = $profileSettings[WarmupConfig::CRAWL_TIMEOUT];
            $warmupCfg[WarmupConfig::SERVER_LOAD_LIMIT] = $profileSettings[WarmupConfig::SERVER_LOAD_LIMIT];
        }
        WarmupConfig::saveAll($warmupCfg);

        // Purge all cache
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }

        \Configuration::updateGlobalValue('LITESPEED_ACTIVE_PRESET', 'wizard');
        \PrestaShopLogger::addLog('Wizard configuration applied', 1, null, 'LiteSpeedCache', 0, true);

        return new JsonResponse(['success' => true, 'message' => 'Configuration applied successfully. All cached pages have been purged.']);
    }

    private function createBackup(string $label): void
    {
        $backup = [
            'date' => date('Y-m-d H:i:s'),
            'preset' => $label,
            'global' => json_decode(\Configuration::getGlobalValue(Conf::ENTRY_ALL) ?: '{}', true),
            'shop' => json_decode(\Configuration::get(Conf::ENTRY_SHOP) ?: '{}', true),
            'cdn' => json_decode(\Configuration::getGlobalValue(CdnConfig::ENTRY) ?: '{}', true),
            'object' => json_decode(\Configuration::getGlobalValue(ObjConfig::ENTRY) ?: '{}', true),
            'exclusions' => json_decode(\Configuration::getGlobalValue(ExclusionsConfig::ENTRY) ?: '{}', true),
        ];

        $history = $this->getHistory();
        array_unshift($history, $backup);
        $history = array_slice($history, 0, self::MAX_BACKUPS);
        \Configuration::updateGlobalValue(self::HISTORY_KEY, json_encode($history));
    }

    private function getHistory(): array
    {
        $raw = \Configuration::getGlobalValue(self::HISTORY_KEY);
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function detectVaryBypass(): string
    {
        $contexts = [];

        if (count(\Language::getLanguages(true)) > 1) {
            $contexts[] = 'lang';
        }
        if (count(\Currency::getCurrencies(false, true)) > 1) {
            $contexts[] = 'curr';
        }
        if (\Shop::isFeatureActive() || \Configuration::get('PS_GEOLOCATION_ENABLED')) {
            $contexts[] = 'ctry';
        }

        return implode(', ', $contexts);
    }
}
