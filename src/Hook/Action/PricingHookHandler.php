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

/** Handles pricing-related action hooks (specific prices, cart rules, price rules). */
class PricingHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onSpecificPriceAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceAddAfter', $params);
    }

    public function onSpecificPriceUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceUpdateAfter', $params);
    }

    public function onSpecificPriceDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceDeleteAfter', $params);
    }

    public function onCartRuleAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCartRuleAddAfter', $params);
    }

    public function onCartRuleUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCartRuleUpdateAfter', $params);
    }

    public function onCartRuleDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCartRuleDeleteAfter', $params);
    }

    public function onSpecificPriceRuleAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceRuleAddAfter', $params);
    }

    public function onSpecificPriceRuleUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceRuleUpdateAfter', $params);
    }

    public function onSpecificPriceRuleDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSpecificPriceRuleDeleteAfter', $params);
    }
}
