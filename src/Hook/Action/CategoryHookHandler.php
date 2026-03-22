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

/** Handles category-related action hooks (add, update, delete). */
class CategoryHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onCategoryUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookCategoryUpdate', $params);
    }

    public function onActionCategoryUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionCategoryUpdate', $params);
    }

    public function onCategoryAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionCategoryAdd', $params);
    }

    public function onCategoryDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionCategoryDelete', $params);
    }
}
