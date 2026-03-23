<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Helper\CacheHelper;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PresetsController extends FrameworkBundleAdminController
{
    use NavPillsTrait;

    private const HISTORY_KEY = 'LITESPEED_PRESETS_HISTORY';
    private const MAX_BACKUPS = 10;

    public function indexAction(Request $request): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_wizard');
    }

    private function getPresetDefinitions(): array
    {
        return [
            'standard' => [
                'name' => 'Standard',
                'recommended' => false,
                'features' => [
                    'Cache enabled with guest mode',
                    'Public TTL: 1 day',
                    'Separate mobile cache',
                    'Cache per customer group (2 views)',
                    'Auto-purge product, category & home on order',
                    'Flush all on cache clear',
                ],
                'description' => 'Safe defaults for any store. Stock and prices update automatically when orders are placed. No additional server requirements.',
            ],
            'optimized' => [
                'name' => 'Optimized',
                'recommended' => true,
                'features' => [
                    'Everything in Standard, plus',
                    'Public TTL: 3 days',
                    'Object cache (if Redis available)',
                ],
                'description' => 'Recommended for most stores. Longer cache TTL with automatic purge on content changes. Redis object cache activated if available.',
            ],
            'maximum' => [
                'name' => 'Maximum',
                'recommended' => false,
                'features' => [
                    'Everything in Optimized, plus',
                    'Public TTL: 1 week',
                    'Shorter private TTL (15 min)',
                    'Aggressive home flush on order',
                ],
                'description' => 'Maximum caching performance for high-traffic stores with mostly static catalogs. Test thoroughly — some exclusions may be needed for dynamic content.',
            ],
        ];
    }

    private function applyPreset(string $presetKey): void
    {
        $presets = $this->getPresetDefinitions();
        if (!isset($presets[$presetKey])) {
            $this->addFlash('error', 'Unknown preset.');

            return;
        }

        // Create backup before applying
        $this->createBackup($presets[$presetKey]['name']);

        $config = Conf::getInstance();
        $all = $config->get(Conf::ENTRY_ALL);
        $shop = $config->get(Conf::ENTRY_SHOP);

        // Common base for all presets
        $all[Conf::CFG_ENABLED] = 1;
        $all[Conf::CFG_GUESTMODE] = 1;
        $all[Conf::CFG_DIFFMOBILE] = 1;
        $all[Conf::CFG_FLUSH_ALL] = 1;
        $all[Conf::CFG_FLUSH_PRODCAT] = 3;
        $all[Conf::CFG_FLUSH_HOME] = 1;
        $shop[Conf::CFG_DIFFCUSTGRP] = 2;

        // Auto-detect vary bypass contexts
        $all[Conf::CFG_VARY_BYPASS] = $this->detectVaryBypass();

        switch ($presetKey) {
            case 'standard':
                $shop[Conf::CFG_PUBLIC_TTL] = 86400;      // 1 day
                $shop[Conf::CFG_PRIVATE_TTL] = 1800;      // 30 min
                $shop[Conf::CFG_HOME_TTL] = 86400;        // 1 day
                $shop[Conf::CFG_404_TTL] = 3600;          // 1 hour
                $shop[Conf::CFG_PCOMMENTS_TTL] = 3600;    // 1 hour
                break;

            case 'optimized':
                $shop[Conf::CFG_PUBLIC_TTL] = 259200;     // 3 days
                $shop[Conf::CFG_PRIVATE_TTL] = 1800;      // 30 min
                $shop[Conf::CFG_HOME_TTL] = 259200;       // 3 days
                $shop[Conf::CFG_404_TTL] = 86400;         // 1 day
                $shop[Conf::CFG_PCOMMENTS_TTL] = 7200;    // 2 hours
                // Enable Redis object cache if available and connectable
                if (extension_loaded('redis')) {
                    $objCfg = ObjConfig::getAll();
                    if ($this->testRedisConnection($objCfg)) {
                        $objCfg[ObjConfig::OBJ_ENABLE] = 1;
                        \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
                        ObjConfig::reset();
                    }
                }
                break;

            case 'maximum':
                $all[Conf::CFG_FLUSH_HOME] = 2;
                $shop[Conf::CFG_PUBLIC_TTL] = 604800;     // 1 week
                $shop[Conf::CFG_PRIVATE_TTL] = 900;       // 15 min
                $shop[Conf::CFG_HOME_TTL] = 604800;       // 1 week
                $shop[Conf::CFG_404_TTL] = 86400;         // 1 day
                $shop[Conf::CFG_PCOMMENTS_TTL] = 7200;    // 2 hours
                if (extension_loaded('redis')) {
                    $objCfg = ObjConfig::getAll();
                    if ($this->testRedisConnection($objCfg)) {
                        $objCfg[ObjConfig::OBJ_ENABLE] = 1;
                        \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
                        ObjConfig::reset();
                    }
                }
                break;
        }

        $config->updateConfiguration(Conf::ENTRY_ALL, $all);
        $config->updateConfiguration(Conf::ENTRY_SHOP, $shop);

        // Update .htaccess
        CacheHelper::htAccessUpdate($all[Conf::CFG_ENABLED], $all[Conf::CFG_GUESTMODE] == 1, $all[Conf::CFG_DIFFMOBILE]);

        // Purge all cache
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }

        \Configuration::updateGlobalValue('LITESPEED_ACTIVE_PRESET', $presetKey);
        \PrestaShopLogger::addLog('Preset applied: ' . $presets[$presetKey]['name'], 1, null, 'LiteSpeedCache', 0, true);
        $this->addFlash('success', $this->trans('Preset "%s" applied. All cached pages have been purged.', 'Modules.Litespeedcache.Admin', [$presets[$presetKey]['name']]));
    }

    private function createBackup(string $presetName): void
    {
        $backup = [
            'date' => date('Y-m-d H:i:s'),
            'preset' => $presetName,
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

    private function restoreBackup(int $index): void
    {
        $history = $this->getHistory();
        if (!isset($history[$index])) {
            $this->addFlash('error', 'Backup not found.');

            return;
        }

        $backup = $history[$index];

        if (!empty($backup['global'])) {
            \Configuration::updateGlobalValue(Conf::ENTRY_ALL, json_encode($backup['global']));
        }
        if (!empty($backup['shop'])) {
            \Configuration::updateValue(Conf::ENTRY_SHOP, json_encode($backup['shop']));
        }
        if (!empty($backup['cdn'])) {
            \Configuration::updateGlobalValue(CdnConfig::ENTRY, json_encode($backup['cdn']));
        }
        if (!empty($backup['object'])) {
            \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($backup['object']));
            ObjConfig::reset();
        }
        if (!empty($backup['exclusions'])) {
            \Configuration::updateGlobalValue(ExclusionsConfig::ENTRY, json_encode($backup['exclusions']));
            ExclusionsConfig::reset();
        }
        CdnConfig::reset();
        \Configuration::updateGlobalValue('LITESPEED_ACTIVE_PRESET', null);

        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }

        \PrestaShopLogger::addLog('Settings restored from backup: ' . $backup['date'], 2, null, 'LiteSpeedCache', 0, true);
        $this->addFlash('success', $this->trans('Settings restored from backup created on %s.', 'Modules.Litespeedcache.Admin', [$backup['date']]));
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

    private function testRedisConnection(array $objCfg): bool
    {
        try {
            $redis = new \Redis();
            $host = $objCfg[ObjConfig::OBJ_HOST] ?? 'localhost';
            $port = (int) ($objCfg[ObjConfig::OBJ_PORT] ?? 6379);
            if (!$redis->connect($host, $port, 2.0)) {
                return false;
            }
            $pass = $objCfg[ObjConfig::OBJ_PASSWORD] ?? '';
            if ($pass !== '') {
                $redis->auth($pass);
            }
            $redis->ping();
            $redis->close();

            return true;
        } catch (\Exception $e) {
            return false;
        }
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
}
