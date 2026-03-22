<?php

declare(strict_types=1);

/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Service\Esi;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Esi\EsiItem;

/**
 * EsiResponseConfigurator -- configures cache response behaviour for ESI blocks.
 *
 * Handles JS definition filtering for cacheable pages and sets cache-control
 * parameters when rendering individual ESI module responses.
 */
class EsiResponseConfigurator
{
    private CacheConfig $config;
    private CacheManager $cache;
    private EsiMarkerManager $markerManager;

    public function __construct(CacheConfig $config, CacheManager $cache, EsiMarkerManager $markerManager)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->markerManager = $markerManager;
    }

    /**
     * Filter JavaScript definitions for cacheable pages.
     *
     * Removes cart/customer data from prestashop JS object when the page is
     * cacheable, and delegates JS definition injection to LscIntegration.
     */
    public function filterJsDef(array &$jsDef): void
    {
        if (!CacheState::canInjectEsi()) {
            return;
        }
        $diffCustomerGroup = $this->config->getDiffCustomerGroup();
        if (CacheState::isCacheable() && isset($jsDef['prestashop'])) {
            unset($jsDef['prestashop']['cart']);
            if ($diffCustomerGroup === 0) {
                unset($jsDef['prestashop']['customer']);
            }
        }
        $injected = \LscIntegration::filterJSDef($jsDef);
        if (!empty($injected)) {
            foreach ($injected as $id => $item) {
                $this->markerManager->setMarker($id, $item);
            }
        }
    }

    /**
     * Set cache-control headers for an individual ESI module response.
     *
     * Determines cacheability, TTL, tags, and private flag based on the
     * ESI item configuration.
     */
    public function addCacheControlByEsiModule(EsiItem $item): void
    {
        if (!CacheState::isActive()) {
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
}
