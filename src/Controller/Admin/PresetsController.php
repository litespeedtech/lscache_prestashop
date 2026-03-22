<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Helper\CacheHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PresetsController extends AbstractController
{
    use NavPillsTrait;

    private const HISTORY_KEY = 'LITESPEED_PRESETS_HISTORY';
    private const MAX_BACKUPS = 10;

    public function indexAction(Request $request): Response
    {
        $d = 'Modules.Litespeedcache.Admin';

        // Apply preset
        if ($request->isMethod('POST') && $request->request->has('apply_preset')) {
            $preset = $request->request->get('apply_preset');
            $this->applyPreset($preset);

            return $this->redirectToRoute('admin_litespeedcache_presets');
        }

        // Restore backup
        if ($request->isMethod('POST') && $request->request->has('restore_backup')) {
            $index = (int) $request->request->get('restore_backup');
            $this->restoreBackup($index);

            return $this->redirectToRoute('admin_litespeedcache_presets');
        }

        $presets = $this->getPresetDefinitions();
        $history = $this->getHistory();
        $currentPreset = \Configuration::getGlobalValue('LITESPEED_ACTIVE_PRESET') ?: null;

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/presets.html.twig', [
            'presets' => $presets,
            'history' => $history,
            'currentPreset' => $currentPreset,
        ], $request);
    }

    private function getPresetDefinitions(): array
    {
        return [
            'essentials' => [
                'name' => 'Essentials',
                'recommended' => false,
                'features' => [
                    'Default cache enabled',
                    'Higher TTL (1 week)',
                    'Guest mode enabled',
                ],
                'description' => 'This risk-free preset is appropriate for all sites. Good for new users, simple sites, or cache-oriented development.',
                'note' => 'Only basic caching features are enabled. No additional server requirements.',
            ],
            'basic' => [
                'name' => 'Basic',
                'recommended' => false,
                'features' => [
                    'Everything in Essentials, plus',
                    'Separate mobile cache',
                    'Cache per customer group (2 views)',
                    '404 page caching',
                ],
                'description' => 'This low-risk preset introduces basic speed optimizations and better user segmentation. Suitable for enthusiastic beginners.',
                'note' => 'Includes optimizations known to improve page speed scores.',
            ],
            'advanced' => [
                'name' => 'Advanced (Recommended)',
                'recommended' => true,
                'features' => [
                    'Everything in Basic, plus',
                    'Guest mode optimized',
                    'Product & category flush on order',
                    'Flush all on cache clear',
                    'ESI for private blocks',
                    'Object cache (if Redis available)',
                ],
                'description' => 'This preset is good for most sites, and rarely causes conflicts. Any caching issues can be resolved with the Exclusions settings.',
                'note' => 'Includes many optimizations known to improve page speed. Redis recommended for object cache.',
            ],
            'aggressive' => [
                'name' => 'Aggressive',
                'recommended' => false,
                'features' => [
                    'Everything in Advanced, plus',
                    'Shorter private TTL (15 min)',
                    'Instant Click enabled',
                    'All vary bypass disabled',
                    'Maximum flush on order',
                ],
                'description' => 'This preset may work out of the box on some sites, but make sure to test! Some exclusions may be needed.',
                'note' => 'Enables maximum caching aggressiveness. Monitor your site closely after applying.',
            ],
            'extreme' => [
                'name' => 'Extreme',
                'recommended' => false,
                'features' => [
                    'Everything in Aggressive, plus',
                    'Very high TTL (2 weeks)',
                    'Minimal private TTL (10 min)',
                    'Debug headers enabled',
                    'Home page flush on any order',
                ],
                'description' => 'This preset will almost certainly require testing and exclusions. Pay special attention to dynamic content like cart widgets and user-specific blocks.',
                'note' => 'Enables the maximum level of optimizations. For expert users only.',
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

        switch ($presetKey) {
            case 'essentials':
                $all[Conf::CFG_ENABLED] = 1;
                $all[Conf::CFG_GUESTMODE] = 1;
                $all[Conf::CFG_DIFFMOBILE] = 0;
                $all[Conf::CFG_FLUSH_ALL] = 0;
                $all[Conf::CFG_FLUSH_PRODCAT] = 0;
                $all[Conf::CFG_FLUSH_HOME] = 0;
                $shop[Conf::CFG_PUBLIC_TTL] = 604800;
                $shop[Conf::CFG_PRIVATE_TTL] = 1800;
                $shop[Conf::CFG_HOME_TTL] = 604800;
                $shop[Conf::CFG_404_TTL] = 3600;
                $shop[Conf::CFG_DIFFCUSTGRP] = 0;
                \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
                    'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 0,
                ]));
                break;

            case 'basic':
                $all[Conf::CFG_ENABLED] = 1;
                $all[Conf::CFG_GUESTMODE] = 1;
                $all[Conf::CFG_DIFFMOBILE] = 1;
                $all[Conf::CFG_FLUSH_ALL] = 0;
                $all[Conf::CFG_FLUSH_PRODCAT] = 0;
                $all[Conf::CFG_FLUSH_HOME] = 0;
                $shop[Conf::CFG_PUBLIC_TTL] = 604800;
                $shop[Conf::CFG_PRIVATE_TTL] = 1800;
                $shop[Conf::CFG_HOME_TTL] = 604800;
                $shop[Conf::CFG_404_TTL] = 86400;
                $shop[Conf::CFG_DIFFCUSTGRP] = 2;
                \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
                    'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 0,
                ]));
                break;

            case 'advanced':
                $all[Conf::CFG_ENABLED] = 1;
                $all[Conf::CFG_GUESTMODE] = 1;
                $all[Conf::CFG_DIFFMOBILE] = 1;
                $all[Conf::CFG_FLUSH_ALL] = 1;
                $all[Conf::CFG_FLUSH_PRODCAT] = 3;
                $all[Conf::CFG_FLUSH_HOME] = 1;
                $shop[Conf::CFG_PUBLIC_TTL] = 604800;
                $shop[Conf::CFG_PRIVATE_TTL] = 1800;
                $shop[Conf::CFG_HOME_TTL] = 604800;
                $shop[Conf::CFG_404_TTL] = 86400;
                $shop[Conf::CFG_PCOMMENTS_TTL] = 7200;
                $shop[Conf::CFG_DIFFCUSTGRP] = 2;
                // Enable object cache if Redis is available
                if (extension_loaded('redis')) {
                    $objCfg = ObjConfig::getAll();
                    $objCfg[ObjConfig::OBJ_ENABLE] = 1;
                    \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
                    ObjConfig::reset();
                }
                \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
                    'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 0,
                ]));
                break;

            case 'aggressive':
                $all[Conf::CFG_ENABLED] = 1;
                $all[Conf::CFG_GUESTMODE] = 1;
                $all[Conf::CFG_DIFFMOBILE] = 1;
                $all[Conf::CFG_FLUSH_ALL] = 1;
                $all[Conf::CFG_FLUSH_PRODCAT] = 3;
                $all[Conf::CFG_FLUSH_HOME] = 2;
                $all[Conf::CFG_VARY_BYPASS] = '';
                $shop[Conf::CFG_PUBLIC_TTL] = 604800;
                $shop[Conf::CFG_PRIVATE_TTL] = 900;
                $shop[Conf::CFG_HOME_TTL] = 604800;
                $shop[Conf::CFG_404_TTL] = 86400;
                $shop[Conf::CFG_PCOMMENTS_TTL] = 7200;
                $shop[Conf::CFG_DIFFCUSTGRP] = 2;
                if (extension_loaded('redis')) {
                    $objCfg = ObjConfig::getAll();
                    $objCfg[ObjConfig::OBJ_ENABLE] = 1;
                    \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
                    ObjConfig::reset();
                }
                \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
                    'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 1,
                ]));
                break;

            case 'extreme':
                $all[Conf::CFG_ENABLED] = 1;
                $all[Conf::CFG_GUESTMODE] = 1;
                $all[Conf::CFG_DIFFMOBILE] = 1;
                $all[Conf::CFG_FLUSH_ALL] = 1;
                $all[Conf::CFG_FLUSH_PRODCAT] = 3;
                $all[Conf::CFG_FLUSH_HOME] = 2;
                $all[Conf::CFG_VARY_BYPASS] = '';
                $all[Conf::CFG_DEBUG_HEADER] = 1;
                $shop[Conf::CFG_PUBLIC_TTL] = 1209600;
                $shop[Conf::CFG_PRIVATE_TTL] = 600;
                $shop[Conf::CFG_HOME_TTL] = 1209600;
                $shop[Conf::CFG_404_TTL] = 604800;
                $shop[Conf::CFG_PCOMMENTS_TTL] = 86400;
                $shop[Conf::CFG_DIFFCUSTGRP] = 1;
                if (extension_loaded('redis')) {
                    $objCfg = ObjConfig::getAll();
                    $objCfg[ObjConfig::OBJ_ENABLE] = 1;
                    \Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode($objCfg));
                    ObjConfig::reset();
                }
                \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode([
                    'login_cookie' => '_lscache_vary', 'vary_cookies' => '', 'instant_click' => 1,
                ]));
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
            'advanced' => json_decode(\Configuration::getGlobalValue('LITESPEED_CACHE_ADVANCED') ?: '{}', true),
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
        if (!empty($backup['advanced'])) {
            \Configuration::updateGlobalValue('LITESPEED_CACHE_ADVANCED', json_encode($backup['advanced']));
        }

        CdnConfig::reset();
        \Configuration::updateGlobalValue('LITESPEED_ACTIVE_PRESET', null);

        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }

        \PrestaShopLogger::addLog('Settings restored from backup: ' . $backup['date'], 2, null, 'LiteSpeedCache', 0, true);
        $this->addFlash('success', $this->trans('Settings restored from backup created on %s.', 'Modules.Litespeedcache.Admin', [$backup['date']]));
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
