<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Module;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * TabManager — installs and removes PrestaShop admin tabs for LiteSpeedCache.
 *
 * Extracted from the main LiteSpeedCache module class to keep it focused on
 * hooks and the module lifecycle.  Requires PrestaShop ≥ 1.7.0.
 */
class TabManager
{
    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /**
     * Creates all admin tabs.  Called from LiteSpeedCache::install().
     */
    public function install(): void
    {
        foreach ($this->tabs() as $t) {
            if (self::getTabId($t['class_name'])) {
                continue;
            }
            $this->createTab($t);
        }
    }

    /**
     * Checks for missing tabs and creates them automatically.
     * Safe to call on every page load (fast DB lookup per tab).
     */
    public function sync(): void
    {
        foreach ($this->tabs() as $t) {
            if (self::getTabId($t['class_name'])) {
                continue;
            }
            $this->createTab($t);
        }
    }

    private function createTab(array $t): void
    {
        $tab = new \Tab();
        $tab->active = true;
        $tab->class_name = $t['class_name'];
        if (!empty($t['route_name'])) {
            $tab->route_name = $t['route_name'];
        }
        if (!empty($t['icon'])) {
            $tab->icon = $t['icon'];
        }
        if (!empty($t['wording'])) {
            $tab->wording = $t['wording'];
        }
        if (!empty($t['wording_domain'])) {
            $tab->wording_domain = $t['wording_domain'];
        }
        $tab->name = [];
        foreach (\Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $t['name'];
        }
        $tab->id_parent = is_int($t['ParentClassName'])
            ? $t['ParentClassName']
            : self::getTabId($t['ParentClassName']);
        $tab->module = $this->module->name;
        $tab->add();
    }

    /**
     * Removes admin tabs.  Called from LiteSpeedCache::uninstall().
     */
    public function uninstall(): void
    {
        foreach ($this->tabs() as $t) {
            if ($id_tab = self::getTabId($t['class_name'])) {
                (new \Tab($id_tab))->delete();
            }
        }
    }

    /**
     * Returns the tab ID for a given class name using a direct DB query.
     * Replaces deprecated Tab::getIdFromClassName().
     */
    private static function getTabId(string $className): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT id_tab FROM `' . _DB_PREFIX_ . 'tab` WHERE class_name = \'' . pSQL($className) . '\''
        );
    }

    /**
     * Returns the tab definition array consumed by both install() and uninstall().
     */
    private const DOMAIN = 'Modules.Litespeedcache.Admin';

    public function tabs(): array
    {
        return [
            [
                'class_name' => 'AdminLiteSpeedCache',
                'name' => 'LiteSpeed Cache',
                'wording' => 'LiteSpeed Cache',
                'wording_domain' => self::DOMAIN,
                'icon' => 'bolt',
                'visible' => 1,
                'ParentClassName' => 'CONFIGURE',
            ],
            [
                'class_name' => 'AdminLiteSpeedCachePresets',
                'route_name' => 'admin_litespeedcache_presets',
                'name' => 'Presets',
                'wording' => 'Presets',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheCache',
                'route_name' => 'admin_litespeedcache_cache',
                'name' => 'Cache',
                'wording' => 'Cache',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheCacheSettings',
                'route_name' => 'admin_litespeedcache_cache_settings',
                'name' => 'Page Cache',
                'wording' => 'Page Cache',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheTtl',
                'route_name' => 'admin_litespeedcache_ttl',
                'name' => 'TTL',
                'wording' => 'TTL',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheExclusions',
                'route_name' => 'admin_litespeedcache_exclusions',
                'name' => 'Exclusions',
                'wording' => 'Exclusions',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheEsi',
                'route_name' => 'admin_litespeedcache_esi',
                'name' => 'ESI',
                'wording' => 'ESI',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheObjectCache',
                'route_name' => 'admin_litespeedcache_objectcache',
                'name' => 'Object',
                'wording' => 'Object',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheAdvanced',
                'route_name' => 'admin_litespeedcache_advanced',
                'name' => 'Advanced',
                'wording' => 'Advanced',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheCdn',
                'route_name' => 'admin_litespeedcache_cdn',
                'name' => 'CDN',
                'wording' => 'CDN',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheWarmup',
                'route_name' => 'admin_litespeedcache_warmup',
                'name' => 'Warm up Cache',
                'wording' => 'Warm up Cache',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheStats',
                'route_name' => 'admin_litespeedcache_stats',
                'name' => 'Statistics',
                'wording' => 'Statistics',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheDatabase',
                'route_name' => 'admin_litespeedcache_database',
                'name' => 'Database',
                'wording' => 'Database',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheTools',
                'route_name' => 'admin_litespeedcache_tools',
                'name' => 'Tools',
                'wording' => 'Tools',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsPurge',
                'route_name' => 'admin_litespeedcache_tools_purge',
                'name' => 'Purge',
                'wording' => 'Purge',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsImportExport',
                'route_name' => 'admin_litespeedcache_tools_import_export',
                'name' => 'Import and Export',
                'wording' => 'Import and Export',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsHtaccess',
                'route_name' => 'admin_litespeedcache_tools_htaccess',
                'name' => 'Show .htaccess',
                'wording' => 'Show .htaccess',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsReport',
                'route_name' => 'admin_litespeedcache_tools_report',
                'name' => 'Report',
                'wording' => 'Report',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsDebug',
                'route_name' => 'admin_litespeedcache_tools_debug',
                'name' => 'Debug settings',
                'wording' => 'Debug settings',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsLogs',
                'route_name' => 'admin_litespeedcache_tools_logs',
                'name' => 'Logs',
                'wording' => 'Logs',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheToolsUpdates',
                'route_name' => 'admin_litespeedcache_tools_updates',
                'name' => 'Updates',
                'wording' => 'Updates',
                'wording_domain' => self::DOMAIN,
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCacheTools',
            ],
        ];
    }
}
