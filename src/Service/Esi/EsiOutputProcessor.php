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

use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/**
 * EsiOutputProcessor -- output buffer callback for ESI processing.
 *
 * Handles HTTP response code checks, ESI marker replacement delegation,
 * and cache-control header finalisation.
 */
class EsiOutputProcessor
{
    private CacheManager $cache;
    private EsiMarkerManager $markerManager;

    public function __construct(CacheManager $cache, EsiMarkerManager $markerManager)
    {
        $this->cache = $cache;
        $this->markerManager = $markerManager;
    }

    /**
     * Process the output buffer: check response codes, replace ESI markers,
     * and set cache-control headers.
     */
    public function processBuffer(string $buffer): string
    {
        if (CacheState::isFrontController()) {
            \LiteSpeedCache::setVaryCookie();
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
            CacheState::markNotCacheable('Response code is ' . $code);
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
                LSLog::log('setNotCacheable - Response code is ' . $code, LSLog::LEVEL_NOCACHE_REASON);
            }
        }

        if (CacheState::canInjectEsi()
            && ($this->markerManager->hasMarkers() || CacheState::isCacheable())) {
            $buffer = $this->markerManager->replaceMarkers($buffer);
        }

        $this->cache->setCacheControlHeader();

        return $buffer;
    }
}
