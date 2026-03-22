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

use LiteSpeed\Cache\Core\CacheManager;

/** Handles order-related action hooks (order confirmation). */
class OrderHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onOrderConfirmation(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookDisplayOrderConfirmation', $params);
    }
}
