<?php
/**
 * LiteSpeed Cache for Prestashop
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
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheDebugLog as DebugLog;
use LiteSpeedCacheConfig as Conf;
use LiteSpeedCache as LSC;

class LiteSpeedCacheCore
{
    const LSHEADER_PURGE = 'X-Litespeed-Purge';
    const LSHEADER_CACHE_CONTROL = 'X-Litespeed-Cache-Control';
    const LSHEADER_CACHE_TAG = 'X-Litespeed-Tag';
    const LSHEADER_CACHE_VARY = 'X-Litespeed-Vary';

    private $cacheTags = array();
    private $purgeTags;
    private $config;
    private $isDebug;
    private $esiUrl = '';
    private $esiTtl;

    public function __construct(LiteSpeedCacheConfig $config)
    {
        $this->config = $config;
        $this->isDebug = $config->isDebug();
        $this->purgeTags = array('pub' => array(), 'priv' => array());
    }

    private function getEsiUrl($moduleName, $hookName)
    {
        if ($this->esiUrl == '') {
            $context = Context::getContext();
            $cookie = $context->cookie;
            $esiparams = array('s' => $context->shop->id, 'm' => '_MODULE_', 'h' => '_HOOK_');
            if (isset($cookie->iso_code_country)) {
                $esiparams['ct'] = $cookie->iso_code_country;
            }
            if (isset($cookie->id_currency)) {
                $esiparams['c'] = $cookie->id_currency;
            }
            if (isset($cookie->id_lang)) {
                $esiparams['l'] = $cookie->id_lang;
            }
            $esiurl = $context->link->getModuleLink(LiteSpeedCache::MODULE_NAME, 'esi', $esiparams);
            $baselink = $context->link->getBaseLink();

            $this->esiUrl = str_replace($baselink, '/', $esiurl);
        }
        $url = str_replace(array('_MODULE_', '_HOOK_'), array($moduleName, $hookName), $this->esiUrl);
        return $url;
    }

    public function getEsiInclude($module, $hookName)
    {
        $moduleName = $module->name;
        $esiurl = $this->getEsiUrl($moduleName, $hookName);
        $conf = $this->config->getEsiModuleConf($moduleName);
        $tag = $conf['tag'];
        $ptype = ($conf['priv'] == 1) ? 'private' : 'public';
        $format = '<esi:include src=\'%s\' cache-control=\'no-vary,%s\' cache-tag=\'%s\'/>';
        $esiInclude = sprintf($format, $esiurl, $ptype, $tag);
        return $esiInclude;
    }

    public function setEsiTtl($ttl)
    {
        $this->esiTtl = $ttl;
    }

    public function isCacheableRoute($controllerType, $controllerClass)
    {
        $reason = '';
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $reason = 'Not GET request';
        } elseif ($controllerType != DispatcherCore::FC_FRONT) {
            $reason = 'Not FC_FRONT';
        } else {
            $tag = $this->config->isControllerCacheable($controllerClass);
            if ($tag === false) {
                $reason = 'Not in defined cacheable controllers';
            } elseif (!$this->inDoNotCacheRules($reason) && $tag) {
                $this->addCacheTags($tag);
            }
        }
        if ($reason) {
            $reason = 'Route not cacheable: ' . $controllerClass . ' - ' . $reason;
        } elseif ($this->isDebug >= DebugLog::LEVEL_CACHE_ROUTE) {
            DebugLog::log('route in defined cacheable controllers ' . $controllerClass, DebugLog::LEVEL_CACHE_ROUTE);
        }
        return $reason;
    }

    public function inDoNotCacheRules(&$reason)
    {
        $nocache = $this->config->getNoCacheConf();
        $requrl = $_SERVER['REQUEST_URI'];
        foreach ($nocache[Conf::CFG_NOCACHE_URL] as $url) {
            $url1 = rtrim($url, '*');
            if ($url1 !== $url) { // contains *
                if (strpos($requrl, $url1) !== false) {
                    $reason = 'disabled url (partial match) ' . $url;
                    return true;
                }
            } elseif ($url == $requrl) {
                $reason = 'disabled url (exact match) ' . $url;
                return true;
            }
        }
        foreach ($nocache[Conf::CFG_NOCACHE_VAR] as $var) {
            if (isset($_REQUEST[$var])) {
                $reason = 'contains param ' . $var;
                return true;
            }
        }
        return false;
    }

    public function hasNotification()
    {
        if (($smarty = Context::getContext()->smarty) !== null) {
            $notification = $smarty->getTemplateVars('notifications');
            if (is_array($notification)) {
                if (!empty($notification['error'])
                        || !empty($notification['warning'])
                        || !empty($notification['success'])
                        || !empty($notification['info'])) {
                    return true;
                }
            }
        }
        return false;
    }

    public function initCacheTagsByController($params)
    {
        if (!empty($this->cacheTags)) {
            return; // already initialized
        }
        if (!isset($params['controller'])) {
            if ($this->isDebug >= DebugLog::LEVEL_UNEXPECTED) {
                DebugLog::log('initCacheTagsByController - no controller in param', DebugLog::LEVEL_UNEXPECTED);
            }
            return;
        }
        $controller = $params['controller'];
        $tag = null;
        if (isset($params['entity'])) {
            switch ($params['entity']) {
                case 'product':
                    if (method_exists($controller, 'getProduct')) {
                        $tag = Conf::TAG_PREFIX_PRODUCT . $controller->getProduct()->id;
                    }
                    break;
                case 'category':
                    if (method_exists($controller, 'getCategory')) {
                        $tag = Conf::TAG_PREFIX_CATEGORY . $controller->getCategory()->id;
                    }
                    break;
            }
        }

        if (!$tag && isset($params['id'])) {
            $id = $params['id'];
            switch ($controller->php_self) {
                case 'product':
                    $tag = Conf::TAG_PREFIX_PRODUCT . $id;
                    break;
                case 'category':
                    $tag = Conf::TAG_PREFIX_CATEGORY . $id;
                    break;
                case 'manufacturer':
                    $tag = Conf::TAG_PREFIX_MANUFACTURER . $id;
                    break;
                case 'supplier':
                    $tag = Conf::TAG_PREFIX_SUPPLIER . $id;
                    break;
                case 'cms':
                    $tag = Conf::TAG_PREFIX_CMS . $id;
                    break;
            }
        }

        if ($tag) {
            $this->addCacheTags($tag);
        } elseif ($this->isDebug >= DebugLog::LEVEL_UNEXPECTED) {
            DebugLog::log('check what we have here - initCacheTagsByController', DebugLog::LEVEL_UNEXPECTED);
        }
    }

    public function initCacheTagsByEntityId($entity, $id)
    {
        if (!empty($this->cacheTags)) {
            return; // already initialized
        }

        $tag = null;
        switch ($entity) {
            case 'manufacturer':
                $tag = Conf::TAG_PREFIX_MANUFACTURER . $id;
                break;
            case 'supplier':
                $tag = Conf::TAG_PREFIX_SUPPLIER . $id;
                break;
        }

        if ($tag) {
            $this->addCacheTags($tag);
        } elseif ($this->isDebug >= DebugLog::LEVEL_UNEXPECTED) {
            DebugLog::log('check what we have here - initCacheTagsByEntityId' . $entity, DebugLog::LEVEL_UNEXPECTED);
        }
    }

    public function addCacheTags($tag)
    {
        if (is_array($tag)) {
            $this->cacheTags = array_unique(array_merge($this->cacheTags, $tag));
        } elseif (!in_array($tag, $this->cacheTags)) {
            $this->cacheTags[] = $tag;
        }
    }

    // return 1: added, 0: already exists, 2: already has purgeall
    public function addPurgeTags($tag, $isPrivate)
    {
        $returnCode = 0;
        $type = $isPrivate ? 'priv' : 'pub';
        if (in_array('*', $this->purgeTags[$type])) {
            return 2;
        }

        if (is_array($tag)) {
            $oldcount = count($this->purgeTags[$type]);
            $this->purgeTags[$type] = array_unique(array_merge($this->purgeTags[$type], $tag));
            if (count($this->purgeTags[$type]) > $oldcount) {
                $returnCode = 1;
            }
        } elseif (!in_array($tag, $this->purgeTags[$type])) {
            $this->purgeTags[$type][] = $tag;
            $returnCode = 1;
        }

        if (in_array('*', $this->purgeTags[$type])) {
            $this->purgeTags[$type] = array('*'); // purge all
        }
        return $returnCode;
    }

    public function purgeByEvent($event)
    {
        if ($this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByEvent ' . $event, DebugLog::LEVEL_PURGE_EVENT);
        }

        $tags = $this->config->getPurgeTagsByEvent($event);
        $added = false;
        if (!empty($tags['pub']) && ($this->addPurgeTags($tags['pub'], false) == 1)) {
            $added = true;
        }
        if (!empty($tags['priv']) && ($this->addPurgeTags($tags['priv'], true) == 1)) {
            $added = true;
        }
        if ($added) {
            $this->setPurgeHeader();
        }
        return $added;
    }

    public function purgeByTags($tags, $isPrivate, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByTags from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }

        if ($this->addPurgeTags($tags, $isPrivate) == 1) {
            $this->setPurgeHeader();
        }
    }

    public function purgeByProduct($id_product, $product, $isupdate, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByProduct id=' . $id_product . ' from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }

        $added = $this->addPurgeTags(Conf::TAG_PREFIX_PRODUCT . $id_product, false);
        if ($added != 1) {
            return; // has purge all or already added
        }
        $tags = $this->config->getDefaultPurgeTagsByProduct();
        if ($product === null) {
            // populate product
            $product = new Product((int) $id_product);
        }

        $tags[] = Conf::TAG_PREFIX_MANUFACTURER . $product->id_manufacturer;
        $tags[] = Conf::TAG_PREFIX_SUPPLIER . $product->id_supplier;
        if (!$isupdate) {
            // new or delete, also purge all suppliers and manufacturers list, as it shows # of products in it
            $tags[] = Conf::TAG_PREFIX_MANUFACTURER;
            $tags[] = Conf::TAG_PREFIX_SUPPLIER;
        }
        $cats = $product->getCategories();
        if (!empty($cats)) {
            foreach ($cats as $catid) {
                $tags[] = Conf::TAG_PREFIX_CATEGORY . $catid;
            }
        }
        $this->addPurgeTags($tags, false);
        $this->setPurgeHeader();
    }

    public function purgeByCategory($category, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByCategory from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }
        $added = $this->addPurgeTags(Conf::TAG_PREFIX_CATEGORY . $category->id_category, false);
        if ($added != 1) {
            return; // has purge all or already added
        }

        $tags = $this->config->getDefaultPurgeTagsByCategory();
        if (!$category->is_root_category) {
            $cats = $category->getParentsCategories();
            if (!empty($cats)) {
                foreach ($cats as $catid) {
                    $tags[] = Conf::TAG_PREFIX_CATEGORY . $catid;
                }
            }
        }

        $this->addPurgeTags($tags, false);
        $this->setPurgeHeader();
    }

    public function purgeByCms($cms, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByCms from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }

        $tags = array(Conf::TAG_PREFIX_CMS . $cms->id,
            Conf::TAG_PREFIX_CMS, // cmscategory
            Conf::TAG_SITEMAP);
        if ($this->addPurgeTags($tags, false) == 1) {
            $this->setPurgeHeader();
        }
    }

    public function purgeByManufacturer($manufacturer, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeByManufacturer from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }

        $tags = array(Conf::TAG_PREFIX_MANUFACTURER . $manufacturer->id,
            Conf::TAG_PREFIX_MANUFACTURER,
            Conf::TAG_SITEMAP); // allbrands
        if ($this->addPurgeTags($tags, false) == 1) {
            $this->setPurgeHeader();
        }
    }

    public function purgeBySupplier($supplier, $from)
    {
        if ($from && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('purgeBySupplier from ' . $from, DebugLog::LEVEL_PURGE_EVENT);
        }

        $tags = array(Conf::TAG_PREFIX_SUPPLIER . $supplier->id,
            Conf::TAG_PREFIX_SUPPLIER, // all supplier
            Conf::TAG_SITEMAP);
        if ($this->addPurgeTags($tags, false) == 1) {
            $this->setPurgeHeader();
        }
    }

    private function setPurgeHeader()
    {
        $purgeHeader = '';
        $pre = 'tag=' . LiteSpeedCacheHelper::getTagPrefix();

        if (count($this->purgeTags['pub'])) {
            if (in_array('*', $this->purgeTags['pub'])) {
                $purgeHeader .= $pre; // when purge all public, also purge all private block
                $purgeHeader .= ',' . $pre . '_PRIV';
            } else {
                $pre .= '_';
                $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['pub']);
            }
        } elseif (count($this->purgeTags['priv'])) { // public & private will not coexist
            $purgeHeader .= 'private,';
            if (in_array('*', $this->purgeTags['priv'])) {
                $purgeHeader .= '*';
            } else {
                $pre .= '_';
                $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['priv']);
            }
        }

        if ($purgeHeader) {
            $purgeHeader = self::LSHEADER_PURGE . ': ' . $purgeHeader;
            header($purgeHeader);   // due to ajax call, always set header on the event
            if ($this->isDebug >= DebugLog::LEVEL_SETHEADER) {
                DebugLog::log('Set header ' . $purgeHeader, DebugLog::LEVEL_SETHEADER);
            }
        }
    }

    public function purgeEntireStorage($from)
    {
        $purgeHeader = self::LSHEADER_PURGE . ': *';
        header($purgeHeader);
        DebugLog::log('Set header ' . $purgeHeader . ' (' . $from . ')', DebugLog::LEVEL_FORCE);
    }

    public function setCacheControlHeader($from)
    {
        $this->setPurgeHeader();

        $cacheControlHeader = '';
        $ccflag = LSC::getCCFlag();
        $dbgMesg = '';

        if ((($ccflag & LSC::CCBM_NOT_CACHEABLE) == 0) && (($ccflag & LSC::CCBM_CACHEABLE) != 0)) {
            $prefix = LiteSpeedCacheHelper::getTagPrefix();
            $tags = array();
            if (($ccflag & LSC::CCBM_ESI_REQ) != 0) {
                $ttl = $this->esiTtl;
            } elseif (($ccflag & LSC::CCBM_PRIVATE) != 0) {
                $ttl = $this->config->get(Conf::CFG_PRIVATE_TTL);
            } else {
                $ttl = $this->config->get(Conf::CFG_PUBLIC_TTL);
            }

            if (($ccflag & LSC::CCBM_PRIVATE) == 0) {
                $cacheControlHeader .= 'public,max-age=' . $ttl;
                $tags[] = $prefix; // for purge all PS cache
                $shopId = Context::getContext()->shop->id; // todo: should have in env
                $tags[] = $prefix . '_' . Conf::TAG_PREFIX_SHOP . $shopId; // for purge one shop
            } else {
                $cacheControlHeader .= 'private,no-vary,max-age=' . $ttl;
                $tags[] = 'public:' . $prefix . '_PRIV'; // in private cache, use public:tag_name_PRIV
            }

            foreach ($this->cacheTags as $tag) {
                $tags[] = $prefix . '_' . $tag;
            }

            $cacheTagHeader = self::LSHEADER_CACHE_TAG . ': ' . implode(',', $tags);
            header($cacheTagHeader);

            $dbgMesg .= 'Set header ' . $cacheTagHeader . "\n";
        }
        if (($ccflag & LSC::CCBM_ESI_ON) != 0) {
            if ($cacheControlHeader) {
                $cacheControlHeader .= ',';
            }
            $cacheControlHeader .= 'esi=on';
        }
        if ($cacheControlHeader) {
            $cacheControlHeader = self::LSHEADER_CACHE_CONTROL . ': ' . $cacheControlHeader;
            header($cacheControlHeader);
            $dbgMesg .= 'Set header ' . $cacheControlHeader;
        } else {
            $dbgMesg .= 'No cache control header set';
        }
        if ($this->isDebug >= DebugLog::LEVEL_SETHEADER) {
            DebugLog::log($dbgMesg . ' from ' . $from, DebugLog::LEVEL_SETHEADER);
        }
    }
}
