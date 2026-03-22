<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Hook\Action;

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/** Handles the actionDispatcher hook and route cacheability checks. */
class DispatcherHookHandler
{
    private CacheManager $cache;
    private CacheConfig $config;

    public function __construct(CacheManager $cache, CacheConfig $config)
    {
        $this->cache  = $cache;
        $this->config = $config;
    }

    public function onDispatcher(array $params): void
    {
        if (!CacheState::isActiveForUser()) {
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
        \LscIntegration::preDispatchAction();

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CACHE_ROUTE) {
            LSLog::log(
                'hookActionDispatcher type=' . $controllerType . ' controller=' . $controllerClass
                . ' req=' . $_SERVER['REQUEST_URI'] . ' :' . $status,
                LSLog::LEVEL_CACHE_ROUTE
            );
        }
    }

    private function checkDispatcher(int $controllerType, string $controllerClass): string
    {
        if (!CacheState::isActiveForUser()) {
            return 'not active';
        }

        if (!defined('_LITESPEED_CALLBACK_')) {
            define('_LITESPEED_CALLBACK_', 1);
            ob_start('LiteSpeedCache::callbackOutputFilter');
        }

        if ($controllerType === \DispatcherCore::FC_FRONT) {
            CacheState::set(CacheState::FRONT_CTRL);
        }

        if ($controllerClass === 'litespeedcacheesiModuleFrontController') {
            CacheState::set(CacheState::ESI_REQ);
            return 'esi request';
        }

        if (($reason = $this->cache->isCacheableRoute($controllerType, $controllerClass)) !== '') {
            CacheState::markNotCacheable($reason);
            if ($reason && _LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
                LSLog::log('setNotCacheable - ' . $reason, LSLog::LEVEL_NOCACHE_REASON);
            }
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
}
