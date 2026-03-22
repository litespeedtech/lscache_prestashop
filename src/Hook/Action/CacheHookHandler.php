<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Hook\Action;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Integration\Cloudflare;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/** Handles cache lifecycle hooks (purge API, cache clear, htaccess, watermark). */
class CacheHookHandler
{
    private CacheManager $cache;
    private Conf $config;

    public function __construct(CacheManager $cache, Conf $config)
    {
        $this->cache  = $cache;
        $this->config = $config;
    }

    /**
     * Public purge API for third-party modules.
     *
     * Required: $params['from'] (caller identifier).
     * One of: $params['public'] (tags), $params['private'] (tags), $params['ALL'].
     */
    public function onCachePurge(array $params): void
    {
        if (!isset($params['from'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
                LSLog::log(__METHOD__ . ' Illegal entrance - missing from', LSLog::LEVEL_PURGE_EVENT);
            }
            return;
        }

        $msg = 'hookLitespeedCachePurge from ' . $params['from'];

        if (!CacheState::isActive()) {
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

    public function onNotCacheable(array $params): void
    {
        if (!CacheState::isActiveForUser()) {
            return;
        }
        $reason = ($params['reason'] ?? '') . (isset($params['from']) ? ' from ' . $params['from'] : '');
        CacheState::markNotCacheable($reason);
        if ($reason && _LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
            LSLog::log('setNotCacheable - ' . $reason, LSLog::LEVEL_NOCACHE_REASON);
        }
    }

    public function onClearCompileCache(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionClearCompileCache', $params);
        $this->flushExternalCaches();
    }

    public function onClearSf2Cache(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionClearSf2Cache', $params);
        $this->flushExternalCaches();
    }

    public function onHtaccessCreate(array $params): void
    {
        $enable = (bool) $this->config->get(Conf::CFG_ENABLED);
        $guest  = ($this->config->get(Conf::CFG_GUESTMODE) == 1);
        $mobile = (bool) $this->config->get(Conf::CFG_DIFFMOBILE);
        CacheHelper::htAccessUpdate($enable, $guest, $mobile);
    }

    public function onWatermark(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionWatermark', $params);
    }

    public function onWebserviceResources(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookAddWebserviceResources', $params);
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
}
