<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Hook\Action;

use LiteSpeed\Cache\Core\CacheManager;

/** Handles authentication action hooks (login, logout, account creation). */
class AuthHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onAuthentication(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionAuthentication', $params);
    }

    public function onCustomerLogout(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionCustomerLogoutAfter', $params);
    }

    public function onCustomerAccountAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionCustomerAccountAdd', $params);
    }
}
