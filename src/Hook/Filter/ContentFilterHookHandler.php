<?php
/**
 * LiteSpeed Cache for PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license  https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Hook\Filter;

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;

class ContentFilterHookHandler
{
    /** @var CacheManager */
    private $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onFilterCategoryContent(array $params): array
    {
        if (CacheState::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(CacheConfig::TAG_PREFIX_CATEGORY . $params['object']['id']);
        }
        return $params;
    }

    public function onFilterProductContent(array $params): array
    {
        if (CacheState::isCacheable()) {
            if (isset($params['object']['id'])) {
                $this->cache->addCacheTags(CacheConfig::TAG_PREFIX_PRODUCT . $params['object']['id']);
            }
            if (!empty($params['object']['specific_prices'])) {
                $this->cache->checkSpecificPrices($params['object']['specific_prices']);
            }
        }
        return $params;
    }

    public function onFilterCmsCategoryContent(array $params): array
    {
        if (CacheState::isCacheable()) {
            $this->cache->addCacheTags(CacheConfig::TAG_PREFIX_CMS);
        }
        return $params;
    }

    public function onFilterCmsContent(array $params): array
    {
        if (CacheState::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(CacheConfig::TAG_PREFIX_CMS . $params['object']['id']);
        }
        return $params;
    }
}
