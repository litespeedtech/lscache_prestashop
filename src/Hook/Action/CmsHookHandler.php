<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Hook\Action;

use LiteSpeed\Cache\Core\CacheManager;

/** Handles CMS page action hooks (add, update, delete). */
class CmsHookHandler
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function onCmsAdd(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCmsAddAfter', $params);
    }

    public function onCmsUpdate(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCmsUpdateAfter', $params);
    }

    public function onCmsDelete(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionObjectCmsDeleteAfter', $params);
    }
}
