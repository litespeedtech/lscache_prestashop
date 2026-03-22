<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Integration\Cloudflare;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Helper\ObjectCacheActivator;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Module\TabManager;
use LiteSpeed\Cache\Vary\VaryCookie;

class LiteSpeedCache extends Module
{
    const MODULE_NAME = 'litespeedcache';

    // Bitmask constants kept for backward compatibility with third-party integrations.
    // The authoritative values live in CacheState; these must remain identical.
    const CCBM_CACHEABLE      = CacheState::CACHEABLE;
    const CCBM_PRIVATE        = CacheState::PRIV;
    const CCBM_CAN_INJECT_ESI = CacheState::CAN_INJECT_ESI;
    const CCBM_ESI_ON         = CacheState::ESI_ON;
    const CCBM_ESI_REQ        = CacheState::ESI_REQ;
    const CCBM_GUEST          = CacheState::GUEST;
    const CCBM_ERROR_CODE     = CacheState::ERROR_CODE;
    const CCBM_NOT_CACHEABLE  = CacheState::NOT_CACHEABLE;
    const CCBM_VARY_CHECKED   = CacheState::VARY_CHECKED;
    const CCBM_VARY_CHANGED   = CacheState::VARY_CHANGED;
    const CCBM_FRONT_CONTROLLER = CacheState::FRONT_CTRL;
    const CCBM_MOD_ACTIVE     = CacheState::MOD_ACTIVE;
    const CCBM_MOD_ALLOWIP    = CacheState::MOD_ALLOWIP;

    // ESI output marker — must be a unique string that cannot appear in normal HTML.
    const ESI_MARKER_END = '_LSCESIEND_';

    /** @var CacheManager */
    private $cache;

    /** @var Conf */
    private $config;

    /** @var array{tracker: array, marker: array} */
    private $esiInjection;

    public function __construct()
    {
        $this->name = 'litespeedcache'; // literal required by PS validator
        $this->tab = 'administration';
        $this->author = 'LiteSpeedTech';
        $this->version = self::getVersion();
        $this->need_instance = 0;
        $this->module_key = '2a93f81de38cad872010f09589c279ba';

        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_,
        ];

        $this->controllers = ['esi'];
        $this->bootstrap   = true;

        parent::__construct();

        $this->displayName = $this->trans('LiteSpeed Cache Plugin', [], 'Modules.Litespeedcache.Admin');
        $this->description = $this->trans('Integrates with LiteSpeed Full Page Cache on LiteSpeed Server.', [], 'Modules.Litespeedcache.Admin');

        $this->config       = Conf::getInstance();
        $this->cache        = new CacheManager($this->config);
        $this->esiInjection = ['tracker' => [], 'marker' => []];

        CacheState::set($this->config->moduleEnabled());

        if (!defined('_LITESPEED_CACHE_')) {
            define('_LITESPEED_CACHE_', 1);
        }
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', 0);
        }

        // Auto-register backoffice header hook if missing (after module update)
        if ($this->active && $this->id) {
            try {
                $hookId = (int) \Hook::getIdByName('displayBackOfficeHeader');
                if ($hookId) {
                    $registered = \Db::getInstance()->getValue(
                        'SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_module = ' . (int) $this->id . ' AND id_hook = ' . $hookId
                    );
                    if (!$registered) {
                        $this->registerHook('displayBackOfficeHeader');
                    }
                }
            } catch (\Throwable $e) {
                // silently skip
            }
        }

        if (self::isActiveForUser()) {
            require_once _PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php';
            Hook::exec('actionLiteSpeedCacheInitThirdParty');
        }

    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    // ---- Version ----------------------------------------------------------------

    public static function getVersion(): string
    {
        return '2.0.1';
    }

    // ---- State accessors (delegate to CacheState) --------------------------------

    public static function isActive(): bool
    {
        return CacheState::isActive();
    }

    public static function isActiveForUser(): bool
    {
        return CacheState::isActiveForUser();
    }

    public static function isRestrictedIP(): bool
    {
        return CacheState::isRestrictedIP();
    }

    public static function isCacheable(): bool
    {
        return CacheState::isCacheable();
    }

    public static function isEsiRequest(): bool
    {
        return CacheState::isEsiRequest();
    }

    public static function canInjectEsi(): bool
    {
        return CacheState::canInjectEsi();
    }

    public static function isFrontController(): bool
    {
        return CacheState::isFrontController();
    }

    /** Returns the raw bitmask (used by CacheManager and debug output). */
    public static function getCCFlag(): int
    {
        return CacheState::flag();
    }

    public static function getCCFlagDebugInfo(): string
    {
        return CacheState::debugInfo();
    }

    public function setEsiOn(): void
    {
        CacheState::set(CacheState::ESI_ON);
    }

    // ---- Hooks ------------------------------------------------------------------

    public function hookActionDispatcher(array $params): void
    {
        if (!self::isActiveForUser()) {
            return;
        }

        $controllerType  = $params['controller_type'];
        $controllerClass = $params['controller_class'];

        if (_LITESPEED_DEBUG_ > 0) {
            $silent = ['AdminDashboardController', 'AdminGamificationController', 'AdminAjaxFaviconBOController'];
            if (in_array($controllerClass, $silent)) {
                LSLog::setDebugLevel(0);
            }
        }

        $status = $this->checkDispatcher($controllerType, $controllerClass);
        LscIntegration::preDispatchAction();

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CACHE_ROUTE) {
            LSLog::log(
                __FUNCTION__ . ' type=' . $controllerType . ' controller=' . $controllerClass
                . ' req=' . $_SERVER['REQUEST_URI'] . ' :' . $status,
                LSLog::LEVEL_CACHE_ROUTE
            );
        }
    }

    public function hookOverrideLayoutTemplate(array $params): void
    {
        if (!self::isCacheable()) {
            return;
        }
        if ($this->cache->hasNotification()) {
            $this->setNotCacheable('Has private notification');
        } elseif (!self::isEsiRequest()) {
            $this->cache->initCacheTagsByController($params);
        }
    }

    public function hookDisplayOverrideTemplate(array $params): void
    {
        if (!self::isCacheable()) {
            return;
        }
        $this->cache->initCacheTagsByController($params);
    }

    public function hookActionProductSearchAfter(array $params): void
    {
        if (self::isCacheable() && isset($params['products'])) {
            foreach ($params['products'] as $p) {
                if (!empty($p['specific_prices'])) {
                    $this->cache->checkSpecificPrices($p['specific_prices']);
                }
            }
        }
    }

    public function hookFilterCategoryContent(array $params): array
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(Conf::TAG_PREFIX_CATEGORY . $params['object']['id']);
        }
        return $params;
    }

    public function hookFilterProductContent(array $params): array
    {
        if (self::isCacheable()) {
            if (isset($params['object']['id'])) {
                $this->cache->addCacheTags(Conf::TAG_PREFIX_PRODUCT . $params['object']['id']);
            }
            if (!empty($params['object']['specific_prices'])) {
                $this->cache->checkSpecificPrices($params['object']['specific_prices']);
            }
        }
        return $params;
    }

    public function hookFilterCmsCategoryContent(array $params): array
    {
        if (self::isCacheable()) {
            $this->cache->addCacheTags(Conf::TAG_PREFIX_CMS);
        }
        return $params;
    }

    public function hookFilterCmsContent(array $params): array
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(Conf::TAG_PREFIX_CMS . $params['object']['id']);
        }
        return $params;
    }

    /**
     * Catchall hook handler for purge events.
     *
     * PS calls methods dynamically via __call() for any registered hook that
     * does not have an explicit hook{HookName}() method.  We route all such
     * calls through CacheManager::purgeByCatchAllMethod().
     */
    public function __call(string $method, array $args)
    {
        // PS may wrap args in a single-element numeric array; unwrap it.
        if (count($args) === 1 && array_keys($args) === [0]) {
            $args = $args[0];
        }

        $event = \Tools::strtolower(\Tools::substr($method, 4));

        // Cache clear hooks must always run (even when module is disabled)
        // so that LS purge + Redis flush + CF purge happen correctly.
        if ($event === 'actionclearcompilecache' || $event === 'actionclearsf2cache') {
            $this->cache->purgeByCatchAllMethod($method, $args);
            $this->flushExternalCaches();
            return;
        }

        if (!self::isActive()) {
            return;
        }
        $this->cache->purgeByCatchAllMethod($method, $args);
    }

    /**
     * Public hook for third-party modules to request a cache purge.
     *
     * Required param: $params['from'] (caller identifier string)
     * One of: $params['public'] (array of tags), $params['private'] (array of tags),
     *         $params['ALL'] (purge entire storage)
     */
    public function hookLitespeedCachePurge(array $params): void
    {
        if (!isset($params['from'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
                LSLog::log(__FUNCTION__ . ' Illegal entrance - missing from', LSLog::LEVEL_PURGE_EVENT);
            }
            return;
        }

        $msg = __FUNCTION__ . ' from ' . $params['from'];

        if (!self::isActive()) {
            // When module is inactive only allow a full public purge.
            if (isset($params['public']) && $params['public'] === '*') {
                $this->cache->purgeByTags('*', false, $msg);
            } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
                LSLog::log($msg . ' Illegal tags - module not activated', LSLog::LEVEL_PURGE_EVENT);
            }
            return;
        }

        if (isset($params['public'])) {
            $this->cache->purgeByTags($params['public'], false, $msg);
            if ($params['public'] === '*') {
                $this->purgeCloudflare();
            }
        } elseif (isset($params['private'])) {
            $this->cache->purgeByTags($params['private'], true, $msg);
        } elseif (isset($params['ALL'])) {
            $this->cache->purgeEntireStorage($msg);
            $this->purgeCloudflare();
        } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log($msg . ' Illegal - missing public/private/ALL', LSLog::LEVEL_PURGE_EVENT);
        }

        \Configuration::updateGlobalValue('LITESPEED_STAT_PURGE_COUNT',
            (int) \Configuration::getGlobalValue('LITESPEED_STAT_PURGE_COUNT') + 1
        );
        \Configuration::updateGlobalValue('LITESPEED_STAT_LAST_PURGE', time());

        // Log purge event
        $logDetail = 'Cache purge from ' . $params['from'];
        if (isset($params['public'])) {
            $tags = is_array($params['public']) ? implode(', ', $params['public']) : $params['public'];
            $logDetail .= ' — public tags: ' . $tags;
        } elseif (isset($params['private'])) {
            $tags = is_array($params['private']) ? implode(', ', $params['private']) : $params['private'];
            $logDetail .= ' — private tags: ' . $tags;
        } elseif (isset($params['ALL'])) {
            $logDetail .= ' — entire storage';
        }
        \PrestaShopLogger::addLog($logDetail, 1, null, 'LiteSpeedCache', 0, true);
    }

    private function purgeCloudflare(): void
    {
        $cdn    = CdnConfig::getAll();
        $zoneId = $cdn[CdnConfig::CF_ZONE_ID] ?? '';

        if (!$cdn[CdnConfig::CF_ENABLE] || !$cdn[CdnConfig::CF_PURGE] || !$zoneId || !$cdn[CdnConfig::CF_KEY]) {
            return;
        }

        (new Cloudflare($cdn[CdnConfig::CF_KEY], $cdn[CdnConfig::CF_EMAIL]))->purgeAll($zoneId);
        \PrestaShopLogger::addLog('CDN purge — Cloudflare zone ' . $zoneId, 1, null, 'LiteSpeedCache', 0, true);
    }

    /** Hook allowing other modules to mark the current response as non-cacheable. */
    public function hookLitespeedNotCacheable(array $params): void
    {
        if (!self::isActiveForUser()) {
            return;
        }
        $reason = ($params['reason'] ?? '') . (isset($params['from']) ? ' from ' . $params['from'] : '');
        $this->setNotCacheable($reason);
    }

    /**
     * Re-injects the LiteSpeed Cache .htaccess block every time PrestaShop
     * regenerates the .htaccess file (e.g. from the Performance settings page).
     */
    /**
     * Injects CSS for the LiteSpeed icon in the admin sidebar menu.
     * Runs on every backoffice page so the icon is always visible.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        return '<style>'
            . '#subtab-AdminLiteSpeedCache > a .material-icons.mi-bolt {'
            . '  font-size: 0 !important;'
            . '  line-height: 20px !important;'
            . '  width: 20px !important;'
            . '  height: 20px !important;'
            . '  background: url(' . $this->getPathUri() . 'views/img/litespeed-icon.svg) no-repeat center center !important;'
            . '  background-size: contain !important;'
            . '}'
            . '</style>';
    }

    public function hookActionHtaccessCreate(array $params): void
    {
        $enable = (bool) $this->config->get(Conf::CFG_ENABLED);
        $guest  = ($this->config->get(Conf::CFG_GUESTMODE) == 1);
        $mobile = (bool) $this->config->get(Conf::CFG_DIFFMOBILE);
        CacheHelper::htAccessUpdate($enable, $guest, $mobile);
    }

    /**
     * Flush Redis object cache when PS clears its compile/Symfony cache so
     * stale SQL query results don't survive a cache-clear operation.
     */
    /**
     * Flush Redis + Cloudflare when PS clears cache.
     * LiteSpeed purge is handled by __call → purgeByCatchAllMethod which
     * registers ob_start and sends X-LiteSpeed-Purge via the output buffer
     * callback — the same pattern as the original litespeedcache module.
     */
    private function flushExternalCaches(): void
    {
        // Flush Redis object cache if active
        if (
            defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis'
        ) {
            $instance = \Cache::getInstance();
            if ($instance instanceof \LiteSpeed\Cache\Cache\CacheRedis) {
                $instance->flush();
            }
        }

        // Purge Cloudflare if CDN purge is enabled
        if ((bool) $this->config->get(Conf::CFG_FLUSH_ALL)) {
            $cdnCfg = CdnConfig::getAll();
            if (
                (bool) $cdnCfg[CdnConfig::CF_PURGE]
                && (bool) $cdnCfg[CdnConfig::CF_ENABLE]
                && !empty($cdnCfg[CdnConfig::CF_ZONE_ID])
            ) {
                try {
                    $cf = new Cloudflare($cdnCfg[CdnConfig::CF_KEY], $cdnCfg[CdnConfig::CF_EMAIL]);
                    $cf->purgeAll($cdnCfg[CdnConfig::CF_ZONE_ID]);
                } catch (\Exception $e) {}
            }
        }
    }


    /** Appends a cache generation comment to the page footer (debug mode only). */
    /** @var bool|null Cached instant click setting */
    private static $instantClick = null;

    public function hookDisplayFooterAfter(array $params): string
    {
        $output = '';

        // Instant Click — cache the config read
        if (self::$instantClick === null) {
            $advanced = json_decode(\Configuration::getGlobalValue('LITESPEED_CACHE_ADVANCED') ?: '{}', true);
            self::$instantClick = !empty($advanced['instant_click']);
        }
        if (self::$instantClick) {
            $output .= '<script src="//instant.page/5.2.0" type="module" integrity="sha384-jnZyxPjiipYXnSU0bbe99Xk6eL+HkjrHXb0TtY1l6eVPGs40IN/bDPd26Ua0NR6"></script>' . PHP_EOL;
        }

        // Cache debug panel — shown when debug headers are enabled
        if ($this->config->get(Conf::CFG_DEBUG_HEADER)) {
            $output .= $this->renderCacheDebugPanel();
        }

        if (!self::isCacheable() || !_LITESPEED_DEBUG_) {
            return $output;
        }
        $comment = isset($_SERVER['HTTP_USER_AGENT'])
            ? '<!-- LiteSpeed Cache created with user_agent: ' . $_SERVER['HTTP_USER_AGENT'] . ' -->' . PHP_EOL
            : '<!-- LiteSpeed Cache snapshot generated at ' . gmdate('Y/m/d H:i:s') . ' GMT -->';

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_FOOTER_COMMENT) {
            LSLog::log('Add html comments in footer ' . $comment, LSLog::LEVEL_FOOTER_COMMENT);
        }
        return $output . $comment;
    }

    /**
     * Renders a debug panel that updates via AJAX on every page visit.
     * Works even when LiteSpeed serves the page from cache (hit).
     */
    private function renderCacheDebugPanel(): string
    {
        $entity = $this->detectEntity();
        $entityLabel = $entity['type'] . ($entity['id'] ? ' #' . $entity['id'] : '');

        // Read response headers already set by the module
        $debugHeaders = [];
        foreach (headers_list() as $h) {
            if (stripos($h, 'X-LiteSpeed') === 0 || stripos($h, 'X-LSCACHE') === 0) {
                [$name, $value] = explode(':', $h, 2);
                $debugHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        // Status items
        $items = [];
        if ($entityLabel) {
            $items[] = $this->debugRow('Entity', $entityLabel, '#fff');
        }

        // LiteSpeed
        $lsStatus = self::isCacheable() ? 'CACHEABLE' : 'NO-CACHE';
        $lsColor = self::isCacheable() ? '#70b580' : '#e84e6a';
        if (Conf::isBypassed()) {
            $lsStatus = 'BYPASS';
            $lsColor = '#e84e6a';
        }
        $items[] = $this->debugRow('LiteSpeed', $lsStatus, $lsColor);

        // Redis
        $redisStatus = 'OFF';
        $redisColor = '#6c757d';
        if (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis') {
            $cache = \Cache::getInstance();
            $redisStatus = ($cache && method_exists($cache, 'isConnected') && $cache->isConnected()) ? 'HIT' : 'ERR';
            $redisColor = $redisStatus === 'HIT' ? '#70b580' : '#e84e6a';
        }
        $items[] = $this->debugRow('Redis', $redisStatus, $redisColor);

        // CDN
        $cdnCfg = CdnConfig::getAll();
        $cdnStatus = !empty($cdnCfg[CdnConfig::CF_ENABLE]) ? 'ON' : 'OFF';
        $items[] = $this->debugRow('CDN', $cdnStatus, $cdnStatus === 'ON' ? '#25b9d7' : '#6c757d');

        // ESI
        $esiOn = CacheState::canInjectEsi();
        $items[] = $this->debugRow('ESI', $esiOn ? 'ON' : 'OFF', $esiOn ? '#70b580' : '#6c757d');

        // Guest
        $guestOn = (bool) $this->config->get(Conf::CFG_GUESTMODE);
        $items[] = $this->debugRow('Guest', $guestOn ? 'ON' : 'OFF', $guestOn ? '#70b580' : '#6c757d');

        // TTL
        $ttl = (int) ($this->config->get(Conf::CFG_PUBLIC_TTL) ?: 0);
        $items[] = $this->debugRow('TTL', $ttl > 0 ? $this->debugFormatTtl($ttl) : 'OFF', $ttl > 0 ? '#70b580' : '#6c757d');

        // Build debug headers section
        $debugSection = '';
        if (!empty($debugHeaders)) {
            $debugSection .= '<div style="border-top:1px solid #444;margin-top:4px;padding-top:4px">'
                . '<span style="color:#25b9d7;font-weight:bold;font-size:10px">LiteSpeed Debug</span></div>';

            foreach ($debugHeaders as $name => $value) {
                $formatted = $this->debugFormatHeaderValue($name, $value);
                $debugSection .= '<div style="margin-bottom:3px">'
                    . '<span style="color:#6c757d">' . htmlspecialchars($name) . '</span><br>'
                    . $formatted . '</div>';
            }
        }

        $html = '<div id="lsc-debug-panel" style="'
            . 'position:fixed;top:50%;right:0;z-index:99999;'
            . 'transform:translateY(-50%);'
            . 'width:240px;max-height:90vh;overflow-y:auto;'
            . 'background:rgba(30,30,30,.92);color:#ccc;'
            . 'font-size:11px;padding:8px 10px;'
            . 'display:flex;flex-direction:column;gap:3px;'
            . 'font-family:monospace;'
            . 'border-left:2px solid #25b9d7;'
            . 'border-radius:6px 0 0 6px;">'
            . '<span style="color:#25b9d7;font-weight:bold;text-align:center;margin-bottom:4px;font-size:12px;">&#9889; CACHE STATUS</span>'
            . implode('', $items)
            . $debugSection
            . '<div id="lsc-debug-lsheaders"></div>'
            . '<span style="color:#6c757d;font-size:9px;text-align:center;margin-top:2px;cursor:pointer" '
            . 'onclick="this.parentElement.style.display=\'none\'">click to dismiss</span>'
            . '</div>'
            . '<script>'
            . 'var x=new XMLHttpRequest();x.open("HEAD",location.href,true);'
            . 'x.onload=function(){'
            .   'var el=document.getElementById("lsc-debug-lsheaders");if(!el)return;'
            .   'var hn=["x-litespeed-cache","x-lscache-debug-cc","x-lscache-debug-info","x-lscache-debug-tag","x-lscache-debug-vary"];'
            .   'var o="";'
            .   'function fmtV(v){'
            .     'try{'
            .       'var p=v.indexOf("{");if(p<0)return v;'
            .       'var s=v.substring(0,p).trim();var j=JSON.parse(v.substring(p));'
            .       'var b="margin:2px 0;padding:3px 5px;background:rgba(255,255,255,.07);border-radius:3px;font-size:10px";'
            .       'var r="<span style=\"color:#70b580;font-weight:bold\">"+s+"</span>";'
            .       'var cv=j.cv||{};'
            .       'r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">Cookie Vary</div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">name:</span> "+(cv.name||"-")+"</div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">value:</span> "+(cv.nv||cv.ov||"-")+"</div>";'
            .       'if(cv.data){var dk=Object.keys(cv.data);for(var k=0;k<dk.length;k++){r+="<span style=\"display:inline-block;background:#25b9d7;color:#000;padding:0 3px;border-radius:2px;margin:1px;font-size:9px\">"+dk[k]+"="+cv.data[dk[k]]+"</span>";}}'
            .       'r+="</div>";'
            .       'var vv=j.vv||{};r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">Vary Value</div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">original:</span> "+(vv.ov||"null")+"</div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">new:</span> "+(vv.nv||"null")+"</div></div>";'
            .       'var ps=j.ps||{};r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">PS Session</div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">original:</span> <span style=\"word-break:break-all\">"+(ps.ov||"null")+"</span></div>";'
            .       'r+="<div><span style=\"color:#adb5bd\">new:</span> <span style=\"word-break:break-all\">"+(ps.nv||"null")+"</span></div></div>";'
            .       'return r;'
            .     '}catch(e){return v;}'
            .   '}'
            .   'function fmtT(v){var t=v.split(",");var r="";for(var i=0;i<t.length;i++){r+="<span style=\"display:inline-block;background:#25b9d7;color:#000;padding:0 4px;border-radius:3px;margin:1px;font-size:10px\">"+t[i].trim()+"</span>";}return r;}'
            .   'function fmtC(v){var c=v.toLowerCase()==="hit"?"#70b580":v.toLowerCase()==="miss"?"#f0ad4e":"#e84e6a";return "<span style=\"color:"+c+";font-weight:bold\">"+v.toUpperCase()+"</span>";}'
            .   'for(var i=0;i<hn.length;i++){'
            .     'var v=x.getResponseHeader(hn[i]);'
            .     'if(!v)continue;'
            .     'var fv=v;'
            .     'if(hn[i].indexOf("vary")>-1)fv=fmtV(v);'
            .     'else if(hn[i].indexOf("tag")>-1)fv=fmtT(v);'
            .     'else if(hn[i]==="x-litespeed-cache")fv=fmtC(v);'
            .     'o+="<div style=\"margin-bottom:3px\"><span style=\"color:#6c757d\">"+hn[i]+"</span><br>"+fv+"</div>";'
            .   '}'
            .   'if(o)el.innerHTML="<div style=\"border-top:1px solid #444;margin-top:4px;padding-top:4px\"><span style=\"color:#25b9d7;font-weight:bold;font-size:10px\">LiteSpeed Headers</span></div>"+o;'
            . '};x.send();'
            . '</script>';

        return $html;
    }

    private function debugRow(string $label, string $value, string $color): string
    {
        return '<div style="display:flex;justify-content:space-between;gap:8px">'
            . '<span>' . $label . '</span>'
            . '<strong style="color:' . $color . '">' . $value . '</strong></div>';
    }

    private function debugFormatTtl(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }

    private function debugFormatHeaderValue(string $name, string $value): string
    {
        // Tags — show as pills
        if (str_contains($name, 'tag')) {
            $tags = explode(',', $value);
            $pills = '';
            foreach ($tags as $t) {
                $pills .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 4px;border-radius:3px;margin:1px;font-size:10px">'
                    . htmlspecialchars(trim($t)) . '</span>';
            }
            return $pills;
        }

        // Vary — parse JSON and format nicely
        if (str_contains($name, 'vary')) {
            $jsonPos = strpos($value, '{');
            if ($jsonPos === false) {
                return '<span style="color:#fff">' . htmlspecialchars($value) . '</span>';
            }

            $status = trim(substr($value, 0, $jsonPos));
            $json = json_decode(substr($value, $jsonPos), true);
            if (!is_array($json)) {
                return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
            }

            $box = 'style="margin:2px 0;padding:3px 5px;background:rgba(255,255,255,.07);border-radius:3px;font-size:10px"';
            $out = '<span style="color:#70b580;font-weight:bold">' . htmlspecialchars($status) . '</span>';

            // Cookie Vary
            $cv = $json['cv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Cookie Vary</div>';
            $out .= '<div><span style="color:#adb5bd">name:</span> <span style="color:#fff">' . htmlspecialchars($cv['name'] ?? '—') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($cv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($cv['nv'] ?? 'null') . '</span></div>';
            if (!empty($cv['data']) && is_array($cv['data'])) {
                $out .= '<div><span style="color:#adb5bd">data:</span> ';
                foreach ($cv['data'] as $k => $v) {
                    $out .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 3px;border-radius:2px;margin:1px;font-size:9px">'
                        . htmlspecialchars($k) . '=' . htmlspecialchars((string) $v) . '</span>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';

            // Vary Value
            $vv = $json['vv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Vary Value</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($vv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($vv['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            // PS Session
            $ps = $json['ps'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">PS Session</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            return $out;
        }

        // Cache status — colored
        if (str_contains($name, 'cache') && strlen($value) < 10) {
            $v = strtoupper($value);
            if ($v === 'HIT') $color = '#70b580';
            elseif ($v === 'MISS') $color = '#f0ad4e';
            else $color = '#e84e6a';
            return '<span style="color:' . $color . ';font-weight:bold">' . htmlspecialchars($v) . '</span>';
        }

        return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
    }

    /**
     * Detects the current entity type and ID from the controller context.
     */
    private function detectEntity(): array
    {
        $result = ['type' => '', 'id' => 0];

        try {
            $controller = $this->context->controller ?? null;
            if (!$controller) return $result;

            $class = get_class($controller);

            if (isset($controller->php_self)) {
                $page = $controller->php_self;
            } elseif (method_exists($controller, 'getPageName')) {
                $page = $controller->getPageName();
            } else {
                $page = '';
            }

            switch ($page) {
                case 'product':
                    $result['type'] = 'Product';
                    $result['id'] = (int) \Tools::getValue('id_product');
                    break;
                case 'category':
                    $result['type'] = 'Category';
                    $result['id'] = (int) \Tools::getValue('id_category');
                    break;
                case 'cms':
                    $result['type'] = 'CMS';
                    $result['id'] = (int) \Tools::getValue('id_cms');
                    break;
                case 'manufacturer':
                    $result['type'] = 'Brand';
                    $result['id'] = (int) \Tools::getValue('id_manufacturer');
                    break;
                case 'supplier':
                    $result['type'] = 'Supplier';
                    $result['id'] = (int) \Tools::getValue('id_supplier');
                    break;
                case 'index':
                    $result['type'] = 'Home';
                    break;
                case 'search':
                    $result['type'] = 'Search';
                    break;
                case 'cart':
                    $result['type'] = 'Cart';
                    break;
                case 'order':
                case 'checkout':
                    $result['type'] = 'Checkout';
                    break;
                case 'my-account':
                    $result['type'] = 'Account';
                    break;
                case 'pagenotfound':
                    $result['type'] = '404';
                    break;
                case 'best-sales':
                    $result['type'] = 'Best Sales';
                    break;
                case 'new-products':
                    $result['type'] = 'New Products';
                    break;
                case 'prices-drop':
                    $result['type'] = 'Prices Drop';
                    break;
                default:
                    if ($page) {
                        $result['type'] = $page;
                    } else {
                        $result['type'] = str_replace('Controller', '', $class);
                    }
            }
        } catch (\Throwable $e) {
            $result['type'] = 'Unknown';
        }

        return $result;
    }

    /** Called by Media override: filters private JS variables for ESI replacement. */
    public static function filterJsDef(array &$jsDef): void
    {
        if (!CacheState::canInjectEsi()) {
            return;
        }

        $conf             = Conf::getInstance();
        $diffCustomerGroup = $conf->getDiffCustomerGroup();

        if (self::isCacheable() && isset($jsDef['prestashop'])) {
            unset($jsDef['prestashop']['cart']);
            if ($diffCustomerGroup === 0) {
                unset($jsDef['prestashop']['customer']);
            }
        }

        $injected = LscIntegration::filterJSDef($jsDef);
        if (!empty($injected)) {
            $lsc = self::myInstance();
            foreach ($injected as $id => $item) {
                if (!isset($lsc->esiInjection['marker'][$id])) {
                    $lsc->esiInjection['marker'][$id] = $item;
                }
            }
        }
    }

    /** Marks the start of a Smarty-driven ESI block. */
    public function hookLitespeedEsiBegin(array $params): string
    {
        if (!CacheState::canInjectEsi()) {
            return '';
        }

        $err    = 0;
        $errFld = '';
        $m      = $params['m']     ?? null;
        $f      = $params['field'] ?? null;

        if ($m === null) { $err |= 1; $errFld .= 'm '; }
        if ($f === null) { $err |= 1; $errFld .= 'field '; }
        if (!empty($this->esiInjection['tracker'])) { $err |= 2; }

        $esiParam = ['pt' => EsiItem::ESI_SMARTYFIELD, 'm' => $m, 'f' => $f];
        if ($f === 'widget' && isset($params['hook'])) {
            $esiParam['h'] = $params['hook'];
        } elseif ($f === 'widget_block') {
            if (isset($params['tpl'])) {
                $esiParam['t'] = $params['tpl'];
            } else {
                $err |= 1;
                $errFld .= 'tpl ';
            }
        }

        $conf = $this->config->canInjectEsi($m, $esiParam);
        if ($conf === false) { $err |= 4; }

        array_push($this->esiInjection['tracker'], $err);

        if ($err) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CUST_SMARTY) {
                $msg = '';
                if ($err & 1) { $msg .= 'Missing param (' . $errFld . '). '; }
                if ($err & 2) { $msg .= 'Nested hookLitespeedEsiBegin ignored. '; }
                if ($err & 4) { $msg .= 'Cannot inject ESI for ' . $m; }
                LSLog::log(__FUNCTION__ . ' ' . $msg, LSLog::LEVEL_CUST_SMARTY);
            }
            return '';
        }

        return $this->registerEsiMarker($esiParam, $conf);
    }

    /** Marks the end of a Smarty-driven ESI block. */
    public function hookLitespeedEsiEnd(array $params): string
    {
        if (!CacheState::canInjectEsi()) {
            return '';
        }
        $res = array_pop($this->esiInjection['tracker']);
        if ($res === 0) {
            return self::ESI_MARKER_END;
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CUST_SMARTY) {
            $err = ($res === null)
                ? ' Mismatched hookLitespeedEsiEnd detected'
                : ' Ignored hookLitespeedEsiEnd due to error in hookLitespeedEsiBegin';
            LSLog::log(__FUNCTION__ . $err, LSLog::LEVEL_CUST_SMARTY);
        }
        return '';
    }

    // ---- ESI injection (called from Hook override) --------------------------------

    /**
     * Called by override/classes/Hook.php::coreRenderWidget().
     * Returns the ESI start marker or false when injection is not applicable.
     *
     * @return string|false
     */
    public static function injectRenderWidget($module, string $hook_name, $params = false)
    {
        if (!CacheState::canInjectEsi()) {
            return false;
        }
        $lsc = self::myInstance();
        $m   = $module->name;

        $esiParam = ['pt' => EsiItem::ESI_RENDERWIDGET, 'm' => $m, 'h' => $hook_name];
        $conf     = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf === false) {
            return false;
        }

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log(__FUNCTION__ . " $m : $hook_name", LSLog::LEVEL_ESI_INCLUDE);
        }

        $mp = self::getModuleParams($params, $conf->getTemplateArgs());
        if (!empty($mp)) {
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $lsc->registerEsiMarker($esiParam, $conf);
    }

    /**
     * Called by override/classes/Hook.php::coreCallHook().
     * Returns the ESI start marker or false when injection is not applicable.
     *
     * @return string|false
     */
    public static function injectCallHook($module, string $method, $params = false)
    {
        if (!CacheState::canInjectEsi()) {
            return false;
        }
        $lsc = self::myInstance();
        $m   = $module->name;

        $esiParam = ['pt' => EsiItem::ESI_CALLHOOK, 'm' => $m, 'mt' => $method];
        $conf     = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf === false) {
            return false;
        }

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log(__FUNCTION__ . " $m : $method", LSLog::LEVEL_ESI_INCLUDE);
        }

        $mp = self::getModuleParams($params, $conf->getTemplateArgs());
        if (!empty($mp)) {
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $lsc->registerEsiMarker($esiParam, $conf);
    }

    /** Allows third-party code to force the current response non-cacheable. */
    public static function forceNotCacheable(string $reason): void
    {
        self::myInstance()->setNotCacheable($reason);
    }

    // ---- ESI module cache control -----------------------------------------------

    public function addCacheControlByEsiModule(EsiItem $item): void
    {
        if (!self::isActive()) {
            $this->cache->purgeByTags('*', false, 'request esi while module is not active');
            return;
        }

        $ttl = $item->getTTL();
        if ($item->onlyCacheEmpty() && $item->getContent() !== '') {
            $ttl = 0;
        }

        if ($ttl === 0 || $ttl === '0') {
            CacheState::set(CacheState::NOT_CACHEABLE);
            CacheState::appendNoCacheReason('Set by ESIModule ' . $item->getConf()->getModuleName());
        } else {
            $this->cache->addCacheTags($item->getTags());
            CacheState::set(CacheState::CACHEABLE);
            if ($item->isPrivate()) {
                CacheState::set(CacheState::PRIV);
            }
            if ($ttl > 0) {
                $this->cache->setTTL($ttl);
            }
        }
    }

    // ---- Vary cookie ------------------------------------------------------------

    /** Checks vary cookie once per request; returns true if the vary state changed. */
    public static function setVaryCookie(): bool
    {
        if (!CacheState::has(CacheState::VARY_CHECKED)) {
            if (VaryCookie::setVary()) {
                CacheState::set(CacheState::VARY_CHANGED);
            }
            CacheState::set(CacheState::VARY_CHECKED);
        }
        return CacheState::has(CacheState::VARY_CHANGED);
    }

    // ---- Output filter ----------------------------------------------------------

    /** ob_start() callback — processes the full-page buffer before it is sent. */
    public static function callbackOutputFilter(string $buffer): string
    {
        $lsc = self::myInstance();

        if (CacheState::isFrontController()) {
            self::setVaryCookie();
        }

        $code = http_response_code();
        if ($code === 404) {
            CacheState::set(CacheState::ERROR_CODE);
            if (CacheHelper::isStaticResource($_SERVER['REQUEST_URI'])) {
                $buffer = '<!-- 404 not found -->';
                CacheState::clear(CacheState::CAN_INJECT_ESI);
            }
        } elseif ($code !== 200) {
            CacheState::set(CacheState::ERROR_CODE);
            $lsc->setNotCacheable('Response code is ' . $code);
        }

        if (CacheState::canInjectEsi()
            && (count($lsc->esiInjection['marker']) || self::isCacheable())) {
            $buffer = $lsc->replaceEsiMarker($buffer);
        }

        $lsc->cache->setCacheControlHeader();

        return $buffer;
    }

    // ---- Module lifecycle -------------------------------------------------------

    public function getContent(): void
    {
        $url = $this->context->link->getAdminLink('AdminLiteSpeedCacheConfig');
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        Tools::redirectAdmin($url);
    }

    public function install(): bool
    {
        (new TabManager($this))->install();

        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        Configuration::updateGlobalValue(
            Conf::ENTRY_ALL,
            json_encode($this->config->getDefaultConfData(Conf::ENTRY_ALL))
        );
        Configuration::updateValue(
            Conf::ENTRY_SHOP,
            json_encode($this->config->getDefaultConfData(Conf::ENTRY_SHOP))
        );
        Configuration::updateValue(
            Conf::ENTRY_MODULE,
            json_encode($this->config->getDefaultConfData(Conf::ENTRY_MODULE))
        );
        Configuration::updateGlobalValue(
            CdnConfig::ENTRY,
            json_encode(CdnConfig::getDefaults())
        );
        Configuration::updateGlobalValue(
            ObjConfig::ENTRY,
            json_encode(ObjConfig::getDefaults())
        );
        Configuration::updateGlobalValue(
            ExclusionsConfig::ENTRY,
            json_encode(ExclusionsConfig::getDefaults())
        );
        CacheHelper::htAccessBackup('b4lsc');
        $defaults = $this->config->getDefaultConfData(Conf::ENTRY_ALL);
        CacheHelper::htAccessUpdate(
            (bool) $defaults[Conf::CFG_ENABLED],
            ($defaults[Conf::CFG_GUESTMODE] == 1),
            (bool) $defaults[Conf::CFG_DIFFMOBILE]
        );

        \PrestaShopLogger::addLog('LiteSpeed Cache module installed', 1, null, 'LiteSpeedCache', 0, true);

        return $this->installHooks();
    }

    public function disable($force_all = false)
    {
        // Disable PrestaShop cache before removing overrides to prevent crash
        $this->disablePsCache();

        return parent::disable($force_all);
    }

    public function uninstall(): bool
    {
        // Disable PrestaShop cache before removing overrides
        $this->disablePsCache();

        \PrestaShopLogger::addLog('LiteSpeed Cache module uninstalled', 2, null, 'LiteSpeedCache', 0, true);
        (new TabManager($this))->uninstall();
        CacheHelper::htAccessUpdate(0, 0, 0);
        Configuration::deleteByName(Conf::ENTRY_ALL);
        Configuration::deleteByName(Conf::ENTRY_SHOP);
        Configuration::deleteByName(Conf::ENTRY_MODULE);
        Configuration::deleteByName(CdnConfig::ENTRY);
        Configuration::deleteByName(ObjConfig::ENTRY);
        Configuration::deleteByName(ExclusionsConfig::ENTRY);

        return parent::uninstall();
    }

    /**
     * Disables PrestaShop's built-in cache if it's using CacheRedis (provided by this module).
     * Prevents fatal errors when the module's override of Cache.php is removed.
     */
    /**
     * Flushes all caches and disables caching before module removal.
     * Ensures no cache system remains active after disable/uninstall.
     */
    private function disablePsCache(): void
    {
        try {
            // Purge LiteSpeed full-page cache
            $this->cache->purgeByTags('*', false, 'module disable/uninstall');
            if (!headers_sent()) {
                header('X-LiteSpeed-Purge: *');
            }

            // Disable Redis object cache and revert PS caching to default
            ObjectCacheActivator::disableIfActive();

            // Purge Cloudflare CDN
            $this->purgeCloudflare();

            // Clear Symfony cache — removes cached service definitions (CachingTypeExtension, etc.)
            \Tools::clearSf2Cache();

            \PrestaShopLogger::addLog('All caches flushed and disabled (module disable/uninstall)', 2, null, 'LiteSpeedCache', 0, true);
        } catch (\Throwable $e) {
            // Silently skip if DB not available
        }
    }

    // ---- Private helpers --------------------------------------------------------

    /** @var self|null */
    private static $cachedInstance = null;

    private static function myInstance(): self
    {
        if (self::$cachedInstance === null) {
            self::$cachedInstance = Module::getInstanceByName(self::MODULE_NAME);
        }
        return self::$cachedInstance;
    }

    /**
     * Determines cacheability of the current route and starts the output buffer
     * if applicable.
     */
    private function checkDispatcher(int $controllerType, string $controllerClass): string
    {
        if (!self::isActiveForUser()) {
            return 'not active';
        }

        if (!defined('_LITESPEED_CALLBACK_')) {
            define('_LITESPEED_CALLBACK_', 1);
            ob_start('LiteSpeedCache::callbackOutputFilter');
        }

        if ($controllerType === DispatcherCore::FC_FRONT) {
            CacheState::set(CacheState::FRONT_CTRL);
        }

        if ($controllerClass === 'litespeedcacheesiModuleFrontController') {
            CacheState::set(CacheState::ESI_REQ);
            return 'esi request';
        }

        if (($reason = $this->cache->isCacheableRoute($controllerType, $controllerClass)) !== '') {
            $this->setNotCacheable($reason);
            return $reason;
        }

        if (isset($_SERVER['LSCACHE_VARY_VALUE'])
            && in_array($_SERVER['LSCACHE_VARY_VALUE'], ['guest', 'guestm'], true)) {
            CacheState::set(CacheState::CACHEABLE | CacheState::GUEST | CacheState::CAN_INJECT_ESI);
            return 'cacheable guest & allow esiInject';
        }

        CacheState::set(CacheState::CACHEABLE | CacheState::CAN_INJECT_ESI);
        return 'cacheable & allow esiInject';
    }

    private function setNotCacheable(string $reason = ''): void
    {
        if (!self::isActive()) {
            return;
        }
        CacheState::markNotCacheable($reason);
        if ($reason && _LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
            LSLog::log(__FUNCTION__ . ' - ' . $reason, LSLog::LEVEL_NOCACHE_REASON);
        }
    }

    private function registerEsiMarker(array $params, $conf): string
    {
        $item = new EsiItem($params, $conf);
        $id   = $item->getId();
        if (!isset($this->esiInjection['marker'][$id])) {
            $this->esiInjection['marker'][$id] = $item;
        }
        return '_LSCESI-' . $id . '-START_';
    }

    private function replaceEsiMarker(string $buf): string
    {
        if (!empty($this->esiInjection['marker'])) {
            $buf = preg_replace_callback(
                [
                    '/_LSC(ESI)-(.+)-START_(.*)_LSCESIEND_/Usm',
                    '/(\'|\")_LSCESIJS-(.+)-START__LSCESIEND_(\'|\")/Usm',
                ],
                function (array $m): string {
                    $id  = $m[2];
                    $lsc = self::myInstance();
                    if (!isset($lsc->esiInjection['marker'][$id])) {
                        $id = stripslashes($id);
                    }
                    if (!isset($lsc->esiInjection['marker'][$id])) {
                        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                            LSLog::log('Lost Injection ' . $id, LSLog::LEVEL_UNEXPECTED);
                        }
                        return '';
                    }
                    $item       = $lsc->esiInjection['marker'][$id];
                    $esiInclude = $item->getInclude();
                    if ($esiInclude === false) {
                        if ($item->getParam('pt') === EsiItem::ESI_JSDEF) {
                            LscIntegration::processJsDef($item);
                        } else {
                            $item->setContent($m[3]);
                        }
                        $esiInclude = $item->getInclude();
                    }
                    return $esiInclude;
                },
                $buf
            );
        }

        // Always inject the static token and env ESI blocks.
        $staticToken = Tools::getToken(false);
        $tkItem = new EsiItem(
            ['pt' => EsiItem::ESI_TOKEN, 'm' => LscToken::NAME, 'd' => 'static'],
            $this->config->getEsiModuleConf(LscToken::NAME)
        );
        $tkItem->setContent($staticToken);
        $this->esiInjection['marker'][$tkItem->getId()] = $tkItem;

        $envItem = new EsiItem(
            ['pt' => EsiItem::ESI_ENV, 'm' => LscEnv::NAME],
            $this->config->getEsiModuleConf(LscEnv::NAME)
        );
        $envItem->setContent('');
        $this->esiInjection['marker'][$envItem->getId()] = $envItem;

        if (self::isCacheable()) {
            if (strpos($buf, $staticToken) !== false) {
                $buf = str_replace($staticToken, $tkItem->getInclude(), $buf);
            }
            $buf = $envItem->getInclude() . $buf;
        }

        $bufInline       = '';
        $allPrivateItems = [];
        foreach ($this->esiInjection['marker'] as $item) {
            $inline = $item->getInline();
            if ($inline !== false) {
                $bufInline .= $inline;
                if ($item->isPrivate()) {
                    $allPrivateItems[] = $item;
                }
            }
        }

        if ($bufInline) {
            if (!empty($allPrivateItems)) {
                CacheHelper::syncItemCache($allPrivateItems);
            }
            CacheState::set(CacheState::ESI_ON);
        }

        if ($bufInline && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_OUTPUT) {
            LSLog::log('ESI inline output ' . $bufInline, LSLog::LEVEL_ESI_OUTPUT);
        }

        return $bufInline . $buf;
    }

    private function installHooks(): bool
    {
        foreach ($this->config->getReservedHooks() as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extracts module parameters from hook $params using a template-args spec.
     *
     * @param array|false $params Hook params array or false
     * @param string|null $tas    Comma-separated template-arg spec (e.g. "smarty.product.id")
     *
     * @return array|false
     */
    private static function getModuleParams($params, ?string $tas)
    {
        if (!$tas || !$params) {
            return false;
        }
        $smarty = $params['smarty'];
        $mp     = [];
        foreach (explode(',', $tas) as $mv) {
            $parts = explode('.', trim($mv));
            if ($parts[0] === 'smarty') {
                $val = $smarty->getTemplateVars($parts[1]);
                $mp[] = (isset($parts[2]) && $val) ? $val->{$parts[2]} : $val;
            } else {
                $val  = $params[$parts[0]];
                $mp[] = (isset($parts[1]) && $val) ? $val[$parts[1]] : $val;
            }
        }
        return $mp;
    }
}
