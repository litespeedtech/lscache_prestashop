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

/** Handles catalog entity hooks (suppliers, manufacturers, stores). */
class CatalogEntitiesHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onSupplierAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSupplierAddAfter', $params);
    }

    public function onSupplierUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSupplierUpdateAfter', $params);
    }

    public function onSupplierDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectSupplierDeleteAfter', $params);
    }

    public function onManufacturerAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectManufacturerAddAfter', $params);
    }

    public function onManufacturerUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectManufacturerUpdateAfter', $params);
    }

    public function onManufacturerDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectManufacturerDeleteAfter', $params);
    }

    public function onStoreUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectStoreUpdateAfter', $params);
    }
}
