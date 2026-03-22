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
use LiteSpeed\Cache\Core\CacheState;

/** Handles product-related action hooks (add, save, update, delete, search). */
class ProductHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onProductAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionProductAdd', $params);
    }

    public function onProductSave(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionProductSave', $params);
    }

    public function onProductUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionProductUpdate', $params);
    }

    public function onProductDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionProductDelete', $params);
    }

    public function onProductAttributeDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionProductAttributeDelete', $params);
    }

    public function onDeleteProductAttribute(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookDeleteProductAttribute', $params);
    }

    public function onUpdateProduct(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookUpdateProduct', $params);
    }

    public function onUpdateQuantity(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionUpdateQuantity', $params);
    }

    public function onCacheProductUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookLitespeedCacheProductUpdate', $params);
    }

    public function onProductSearchAfter(array $params): void
    {
        if (CacheState::isCacheable() && isset($params['products'])) {
            foreach ($params['products'] as $p) {
                if (!empty($p['specific_prices'])) {
                    $this->cache->checkSpecificPrices($p['specific_prices']);
                }
            }
        }
    }
}
