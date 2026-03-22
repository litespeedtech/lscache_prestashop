<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Core\CacheState;

/**
 * CacheManager — cache tag management, purge logic and HTTP header generation.
 */
use Context;
class CacheManager
{
    const LSHEADER_PURGE        = 'X-Litespeed-Purge2';
    const LSHEADER_CACHE_CONTROL = 'X-Litespeed-Cache-Control';
    const LSHEADER_CACHE_TAG    = 'X-Litespeed-Tag';
    const LSHEADER_DEBUG_TAG    = 'X-LSCACHE-Debug-Tag';
    const LSHEADER_DEBUG_CC     = 'X-LSCACHE-Debug-CC';
    const LSHEADER_DEBUG_PURGE  = 'X-LSCACHE-Debug-Purge';
    const LSHEADER_DEBUG_INFO   = 'X-LSCACHE-Debug-Info';

    private $cacheTags     = [];
    private $purgeTags;
    private $config;
    private $currentTtl    = -1;
    private $specificPrices = [];

    public function __construct(Conf $config)
    {
        $this->config    = $config;
        $this->purgeTags = ['pub' => [], 'priv' => []];
    }

    public function setTTL($ttl): void
    {
        $this->currentTtl = $ttl;
    }

    public function isCacheableRoute(int $controllerType, string $controllerClass): string
    {
        $reason = '';
        $res = false;
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $reason = 'Not GET request';
        } elseif ($controllerType != \DispatcherCore::FC_FRONT) {
            $reason = 'Not FC_FRONT';
        } else {
            $res = $this->config->isControllerCacheable($controllerClass);
            if ($res === false) {
                $reason = 'Not in defined cacheable controllers';
            } elseif ($res['ttl'] == 0) {
                $reason = 'defined TTL is 0';
            } elseif (!$this->inDoNotCacheRules($reason) && !empty($res['tag'])) {
                $this->addCacheTags($res['tag']);
            }
        }

        if ($reason) {
            $reason = 'Route not cacheable: ' . $controllerClass . ' - ' . $reason;
        } else {
            if ($res['ttl'] > 0) {
                $this->setTTL($res['ttl']);
            }
            if (defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_CACHE_ROUTE) {
                LSLog::log('route in defined cacheable controllers ' . $controllerClass, LSLog::LEVEL_CACHE_ROUTE);
            }
        }

        if ($ptags = $this->config->isPurgeController($controllerClass)) {
            if (!empty($ptags['pub'])) {
                $this->purgeByTags($ptags['pub'], false, $controllerClass);
            }
            if (!empty($ptags['priv'])) {
                $this->purgeByTags($ptags['priv'], true, $controllerClass);
            }
        }

        return $reason;
    }

    public function inDoNotCacheRules(string &$reason): bool
    {
        $nocache = $this->config->getNoCacheConf();
        $requrl  = $_SERVER['REQUEST_URI'];

        foreach ($nocache[Conf::CFG_NOCACHE_URL] as $url) {
            if ($url[0] !== '/') {
                if ((strpos($url, '/') !== false) && (strpos($url, '\/') === false)) {
                    $url = str_replace('/', '\/', $url);
                }
                if (preg_match('/' . $url . '/is', $requrl)) {
                    $reason = 'disabled url (regex match) ' . $url;
                    return true;
                } else {
                    continue;
                }
            }
            $url1 = rtrim($url, '*');
            if ($url1 !== $url) {
                if (strpos($requrl, $url1) !== false) {
                    $reason = 'disabled url (partial match) ' . $url;
                    return true;
                }
            } elseif ($url === $requrl) {
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

        // Cookies exclusion
        foreach (ExclusionsConfig::getNoCacheCookies() as $cookieName) {
            if (isset($_COOKIE[$cookieName])) {
                $reason = 'excluded cookie ' . $cookieName;
                return true;
            }
        }

        // User agent exclusion
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua !== '') {
            foreach (ExclusionsConfig::getNoCacheUAs() as $pattern) {
                if (stripos($ua, $pattern) !== false) {
                    $reason = 'excluded user agent ' . $pattern;
                    return true;
                }
            }
        }

        // Customer group (role) exclusion
        $noRoles = ExclusionsConfig::getNoCacheRoles();
        if (!empty($noRoles)) {
            $ctx = Context::getContext();
            if ($ctx->customer && $ctx->customer->id) {
                $customerGroups = \Customer::getGroupsStatic((int) $ctx->customer->id);
                foreach ($noRoles as $roleId) {
                    if (in_array($roleId, $customerGroups, true)) {
                        $reason = 'excluded customer group ' . $roleId;
                        return true;
                    }
                }
            }
        }

        // Category exclusion
        $noCats = ExclusionsConfig::getNoCacheCatIds();
        if (!empty($noCats)) {
            $ctx = Context::getContext();
            $catId = null;
            if (!empty($ctx->controller)) {
                $ctrl = $ctx->controller;
                if (method_exists($ctrl, 'getCategory') && ($cat = $ctrl->getCategory()) !== null) {
                    $catId = (int) $cat->id;
                } elseif (isset($ctrl->category) && $ctrl->category instanceof \Category) {
                    $catId = (int) $ctrl->category->id;
                }
            }
            if ($catId !== null && in_array($catId, $noCats, true)) {
                $reason = 'excluded category ' . $catId;
                return true;
            }
        }

        return false;
    }

    public function hasNotification(): bool
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

    public function initCacheTagsByController(array $params): void
    {
        if (!empty($this->cacheTags)) {
            return;
        }
        if (!isset($params['controller'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                LSLog::log('initCacheTagsByController - no controller in param', LSLog::LEVEL_UNEXPECTED);
            }
            return;
        }
        $controller = $params['controller'];
        $tag        = null;
        $entity     = isset($params['entity']) ? $params['entity'] : $controller->php_self;

        switch ($entity) {
            case 'product':
                if (method_exists($controller, 'getProduct')
                    && ($p = $controller->getProduct()) !== null) {
                    $tag = Conf::TAG_PREFIX_PRODUCT . $p->id;
                }
                break;
            case 'category':
                if (method_exists($controller, 'getCategory')
                    && ($c = $controller->getCategory()) !== null) {
                    $tag = Conf::TAG_PREFIX_CATEGORY . $c->id;
                }
                break;
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

        if (!$tag) {
            $tag = \LscIntegration::initCacheTagAction($params);
        }

        if ($tag) {
            $this->addCacheTags($tag);
        } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
            LSLog::log('check what we have here - initCacheTagsByController', LSLog::LEVEL_UNEXPECTED);
        }
    }

    public function addCacheTags($tags): bool
    {
        $added = false;
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if ($this->addCacheTag($tag)) {
                    $added = true;
                }
            }
        } else {
            $added = $this->addCacheTag($tags);
        }
        return $added;
    }

    private static $tagGroupSet = null;

    private function addCacheTag(string $tag): bool
    {
        if (isset($this->cacheTags[$tag])) {
            return false;
        }
        $this->cacheTags[$tag] = true;

        $t = preg_replace('/\d+/', '', $tag);
        if ($t !== $tag && !isset($this->cacheTags[$t])) {
            if (self::$tagGroupSet === null) {
                self::$tagGroupSet = array_flip([
                    Conf::TAG_PREFIX_CATEGORY, Conf::TAG_PREFIX_PRODUCT, Conf::TAG_PREFIX_CMS,
                    Conf::TAG_PREFIX_MANUFACTURER, Conf::TAG_PREFIX_SUPPLIER, Conf::TAG_PREFIX_PCOMMENTS,
                ]);
            }
            if (isset(self::$tagGroupSet[$t])) {
                $this->cacheTags[$t] = true;
            }
        }
        return true;
    }

    public function addPurgeTags($tag, bool $isPrivate): int
    {
        $returnCode = 0;
        $type       = $isPrivate ? 'priv' : 'pub';

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
            $returnCode               = 1;
        }

        if (in_array('*', $this->purgeTags[$type])) {
            $this->purgeTags[$type] = ['*'];
        }

        return $returnCode;
    }

    private function isNewPurgeTag(string $tag, bool $isPrivate): bool
    {
        $type = $isPrivate ? 'priv' : 'pub';
        if (in_array('*', $this->purgeTags['pub'])
            || in_array($tag, $this->purgeTags[$type])
            || ($isPrivate && in_array('*', $this->purgeTags[$type]))) {
            return false;
        }
        return true;
    }

    public function purgeByTags($tags, bool $isPrivate, string $from): void
    {
        if ($from && _LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log('purgeByTags from ' . $from, LSLog::LEVEL_PURGE_EVENT);
        }
        $this->addPurgeTags($tags, $isPrivate);
    }

    private function getPurgeTagsByProduct(int $id_product, $product, bool $isupdate): array
    {
        $tags = [];
        $pid  = Conf::TAG_PREFIX_PRODUCT . $id_product;
        if (!$this->isNewPurgeTag($pid, false)) {
            return $tags;
        }

        $tags['pub']   = $this->config->getDefaultPurgeTagsByProduct();
        $tags['pub'][] = $pid;

        if ($product === null) {
            $product = new \Product((int) $id_product);
        }

        $tags['pub'][] = Conf::TAG_PREFIX_MANUFACTURER . $product->id_manufacturer;
        $tags['pub'][] = Conf::TAG_PREFIX_SUPPLIER . $product->id_supplier;

        if (!$isupdate) {
            $tags['pub'][] = Conf::TAG_PREFIX_MANUFACTURER;
            $tags['pub'][] = Conf::TAG_PREFIX_SUPPLIER;
        }

        $cats = $product->getCategories();
        if (!empty($cats)) {
            foreach ($cats as $catid) {
                $tags['pub'][] = Conf::TAG_PREFIX_CATEGORY . $catid;
            }
        }

        $flushHomePids = $this->config->getFlushHomePids();
        if ($flushHomePids && in_array($id_product, $flushHomePids)) {
            $tags['pub'][] = Conf::TAG_HOME;
        }

        return $tags;
    }

    private function getPurgeTagsByProductOrder(int $id_product, bool $includeCategory): array
    {
        $tags = [];
        $pid  = Conf::TAG_PREFIX_PRODUCT . $id_product;
        if (!$this->isNewPurgeTag($pid, false)) {
            return $tags;
        }

        $tags[] = $pid;
        if (!$includeCategory) {
            return $tags;
        }

        $product = new \Product((int) $id_product);
        $tags[]  = Conf::TAG_PREFIX_MANUFACTURER . $product->id_manufacturer;
        $tags[]  = Conf::TAG_PREFIX_SUPPLIER . $product->id_supplier;

        $cats = $product->getCategories();
        if (!empty($cats)) {
            foreach ($cats as $catid) {
                $tags[] = Conf::TAG_PREFIX_CATEGORY . $catid;
            }
        }

        return $tags;
    }

    private function getPurgeTagsByWebService(array $args): ?array
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET' || $method === 'HEAD') {
            return null;
        }

        LSLog::log("WebService Purge - $method url " . $_REQUEST['url'] . print_r($args, true), LSLog::LEVEL_WEBSERVICE_DETAIL);
        $resources = explode('/', $_REQUEST['url']);
        if (empty($resources) || count($resources) !== 2 || !ctype_digit($resources[1])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_WEBSERVICE_DETAIL) {
                LSLog::log("WebService Purge - Ignored $method " . print_r($resources, true), LSLog::LEVEL_WEBSERVICE_DETAIL);
            }
            return null;
        }

        $pubtags = [];
        $restype = $resources[0];
        $resid   = $resources[1];

        switch ($restype) {
            case 'categories':
                $pubtags[] = Conf::TAG_PREFIX_CATEGORY . $resid;
                break;
            case 'stock_availables':
                if ($prod_id = $this->getProdidByStockAvailId($resid)) {
                    $pubtags[] = Conf::TAG_PREFIX_PRODUCT . $prod_id;
                }
                break;
            case 'specific_prices':
                LSLog::log("WebService found specific_prices", LSLog::LEVEL_WEBSERVICE_DETAIL);
                break;
            default:
                return null;
        }

        return ['pub' => $pubtags];
    }

    private function getProdidByStockAvailId(int $stockavail_id): int
    {
        if ($stockavail_id <= 0) {
            return 0;
        }
        $query = new \DbQuery();
        $query->select('id_product');
        $query->from('stock_available');
        $query->where('id_stock_available = ' . (int) $stockavail_id);

        return (int) \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }

    private function getPurgeTagsByOrder($order): ?array
    {
        if ($order === null) {
            return null;
        }

        $tags                  = [];
        $pubtags               = [];
        $hasStockStatusChange  = false;
        $hasHome               = false;

        $flushOption  = $this->config->get(Conf::CFG_FLUSH_PRODCAT);
        $flushHome    = $this->config->get(Conf::CFG_FLUSH_HOME);
        $flushHomePids = $this->config->getArray(Conf::CFG_FLUSH_HOME_INPUT);
        $list         = $order->getOrderDetailList();

        foreach ($list as $product) {
            $includeProd = $includeCats = false;
            $outstock    = ((int) $product['product_quantity_in_stock'] <= 0);
            if ($outstock) {
                $hasStockStatusChange = true;
            }

            switch ($flushOption) {
                case 0:
                    $includeProd = true;
                    $includeCats = $outstock;
                    break;
                case 1:
                    $includeProd = $outstock;
                    $includeCats = $outstock;
                    break;
                case 2:
                    $includeProd = $outstock;
                    $includeCats = false;
                    break;
                case 3:
                    $includeProd = true;
                    $includeCats = true;
                    break;
                case 4:
                    $includeProd = false;
                    $includeCats = false;
                    break;
            }

            if ($flushHome == 1 && $outstock
                && (empty($flushHomePids) || in_array($product['product_id'], $flushHomePids))) {
                $hasHome = true;
            } elseif ($flushHome == 2
                && (empty($flushHomePids) || in_array($product['product_id'], $flushHomePids))) {
                $hasHome = true;
            }

            if ($includeProd) {
                $ptags = $this->getPurgeTagsByProductOrder($product['product_id'], $includeCats);
                if (!empty($ptags)) {
                    $pubtags = array_merge($pubtags, $ptags);
                }
            }
        }

        if (!empty($pubtags)) {
            if ($hasStockStatusChange) {
                $pubtags = array_merge($pubtags, $this->config->getDefaultPurgeTagsByProduct());
            }
            if ($hasHome) {
                $pubtags[] = Conf::TAG_HOME;
            }
            $tags['pub'] = array_unique($pubtags);
        }

        return $tags;
    }

    private function getPurgeTagsByCategory($category): array
    {
        $tags = [];
        if ($category === null) {
            return $tags;
        }
        $cid = Conf::TAG_PREFIX_CATEGORY . $category->id_category;
        if (!$this->isNewPurgeTag($cid, false)) {
            return $tags;
        }

        $tags['pub']   = $this->config->getDefaultPurgeTagsByCategory();
        $tags['pub'][] = $cid;

        if (!$category->is_root_category) {
            $cats = $category->getParentsCategories();
            if (!empty($cats)) {
                foreach ($cats as $cat) {
                    $tags['pub'][] = Conf::TAG_PREFIX_CATEGORY . $cat['id_category'];
                }
            }
        }

        return $tags;
    }

    public function purgeByCatchAllMethod(string $method, array $args): void
    {
        $tags = $this->getPurgeTagsByHookMethod($method, $args);
        if (empty($tags)) {
            if (($tags === null)
                && (strcasecmp($method, 'hookaddWebserviceResources') != 0)
                && (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED)) {
                LSLog::log('Unexpected hook function called' . $method, LSLog::LEVEL_UNEXPECTED);
            }
            return;
        }

        if (!defined('_LITESPEED_CALLBACK_')) {
            define('_LITESPEED_CALLBACK_', 1);
            ob_start('LiteSpeedCache::callbackOutputFilter');
        }

        $res = 0;
        if (!empty($tags['pub'])) {
            $res |= $this->addPurgeTags($tags['pub'], false);
        }
        if (!empty($tags['priv'])) {
            $res |= $this->addPurgeTags($tags['priv'], true);
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log('purgeByCatchAll ' . $method . ' res=' . $res, LSLog::LEVEL_PURGE_EVENT);
        }
    }

    private function getPurgeTagsByHookMethod(string $method, array $args): ?array
    {
        if (strncmp($method, 'hook', 4) != 0) {
            return null;
        }
        $event = \Tools::strtolower(\Tools::substr($method, 4));
        $tags  = [];

        switch ($event) {
            case 'actioncustomerlogoutafter':
            case 'actionauthentication':
            case 'actioncustomeraccountadd':
                $tags['priv'] = ['*'];
                break;

            case 'actionclearcompilecache':
            case 'actionclearsf2cache':
                $flushAll = $this->config->get(Conf::CFG_FLUSH_ALL);
                if ($flushAll && !CacheState::isFrontController()) {
                    $tags['pub'] = ['*'];
                }
                break;

            case 'actionproductadd':
            case 'actionproductdelete':
                if (isset($args['id_product']) && isset($args['product'])) {
                    return $this->getPurgeTagsByProduct($args['id_product'], $args['product'], false);
                }
                break;

            case 'actionproductsave':
            case 'actionproductupdate':
                if (isset($args['id_product']) && isset($args['product'])) {
                    return $this->getPurgeTagsByProduct($args['id_product'], $args['product'], true);
                }
                break;

            case 'actionobjectspecificpriceaddafter':
            case 'actionobjectspecificpricedeleteafter':
            case 'actionobjectspecificpriceupdateafter':
                if (isset($args['object']->id_product)) {
                    return $this->getPurgeTagsByProduct($args['object']->id_product, null, true);
                }
                break;

            case 'actionwatermark':
            case 'updateproduct':
            case 'actionupdatequantity':
            case 'actionproductattributedelete':
            case 'deleteproductattribute':
            case 'litespeedcacheproductupdate':
                if (isset($args['id_product'])) {
                    return $this->getPurgeTagsByProduct($args['id_product'], null, true);
                }
                break;

            case 'displayorderconfirmation':
                if (isset($args['order'])) {
                    return $this->getPurgeTagsByOrder($args['order']);
                }
                break;

            case 'categoryupdate':
            case 'actioncategoryupdate':
            case 'actioncategoryadd':
            case 'actioncategorydelete':
                if (isset($args['category'])) {
                    return $this->getPurgeTagsByCategory($args['category']);
                }
                break;

            case 'actionobjectcmsupdateafter':
            case 'actionobjectcmsdeleteafter':
            case 'actionobjectcmsaddafter':
                $tags['pub'] = [
                    Conf::TAG_PREFIX_CMS . $args['object']->id,
                    Conf::TAG_PREFIX_CMS,
                    Conf::TAG_SITEMAP,
                ];
                break;

            case 'actionobjectsupplierupdateafter':
            case 'actionobjectsupplierdeleteafter':
            case 'actionobjectsupplieraddafter':
                $tags['pub'] = [
                    Conf::TAG_PREFIX_SUPPLIER . $args['object']->id,
                    Conf::TAG_PREFIX_SUPPLIER,
                    Conf::TAG_SITEMAP,
                ];
                break;

            case 'actionobjectmanufacturerupdateafter':
            case 'actionobjectmanufacturerdeleteafter':
            case 'actionobjectmanufactureraddafter':
                $tags['pub'] = [
                    Conf::TAG_PREFIX_MANUFACTURER . $args['object']->id,
                    Conf::TAG_PREFIX_MANUFACTURER,
                    Conf::TAG_SITEMAP,
                ];
                break;

            case 'actionobjectstoreupdateafter':
                $tags['pub'] = [Conf::TAG_STORES];
                break;

            case 'actionobjectcartruleaddafter':
            case 'actionobjectcartruleupdateafter':
            case 'actionobjectcartruledeleteafter':
            case 'actionobjectspecificpriceruleaddafter':
            case 'actionobjectspecificpriceruleupdateafter':
            case 'actionobjectspecificpriceruledeleteafter':
                $tags['pub'] = [Conf::TAG_HOME, Conf::TAG_SEARCH, Conf::TAG_PREFIX_PRODUCT, Conf::TAG_PREFIX_CATEGORY];
                break;

            case 'addwebserviceresources':
                return $this->getPurgeTagsByWebService($args);

            default:
                return $this->config->getPurgeTagsByEvent($event);
        }

        return $tags;
    }

    private function getPurgeHeader(): string
    {
        $hasPub  = !empty($this->purgeTags['pub']);
        $hasPriv = !empty($this->purgeTags['priv']);
        if (!$hasPub && !$hasPriv) {
            return '';
        }

        $purgeHeader = '';
        $pre         = 'tag=' . CacheHelper::getTagPrefix();

        if (in_array('*', $this->purgeTags['pub'])) {
            $purgeHeader .= $pre . ',' . $pre . '_PRIV';
            CacheHelper::clearInternalCache();
        } else {
            $pre .= '_';
            if ($hasPub) {
                $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['pub']);
            }
            if ($hasPriv) {
                if ($purgeHeader) {
                    $purgeHeader .= ';';
                }
                $purgeHeader .= 'private,';
                if (in_array('*', $this->purgeTags['priv'])) {
                    $purgeHeader .= '*';
                } else {
                    $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['priv']);
                }
            }
        }

        if ($purgeHeader) {
            $purgeHeader = self::LSHEADER_PURGE . ': ' . $purgeHeader;
        }

        return $purgeHeader;
    }

    public function purgeEntireStorage(string $from): void
    {
        $purgeHeader = self::LSHEADER_PURGE . ': *';
        header($purgeHeader);
        CacheHelper::clearInternalCache();
        LSLog::log('Set header ' . $purgeHeader . ' (' . $from . ')', LSLog::LEVEL_FORCE);
    }

    public function checkSpecificPrices(array $specific_prices): void
    {
        if ($specific_prices['from'] === $specific_prices['to'] && $specific_prices['to'] === '0000-00-00 00:00:00') {
            return;
        }
        if (isset($specific_prices['to'])) {
            $this->specificPrices[] = ['from' => $specific_prices['from'], 'to' => $specific_prices['to']];
        }
    }

    private function adjustTtlBySpecificPrices(int $ttl): int
    {
        if (empty($this->specificPrices)) {
            return $ttl;
        }
        $ttl0 = $ttl;
        $now  = time();

        foreach ($this->specificPrices as $sp) {
            if ($sp['from'] !== '0000-00-00 00:00:00') {
                $from = strtotime($sp['from']);
                if ($from > $now) {
                    if (($from - $now) < $ttl) {
                        $ttl = $from - $now;
                    }
                    continue;
                }
            }
            if ($sp['to'] !== '0000-00-00 00:00:00') {
                $to = strtotime($sp['to']);
                if (($to > $now) && (($to - $now) < $ttl)) {
                    $ttl = $to - $now;
                }
            }
        }

        if (defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_SPECIFIC_PRICE) {
            if ($ttl0 !== $ttl) {
                LSLog::log("TTL adjusted due to specific prices from $ttl0 to $ttl", LSLog::LEVEL_SETHEADER);
            } else {
                LSLog::log('Detected specific prices, but no TTL adjustment', LSLog::LEVEL_SPECIFIC_PRICE);
            }
        }

        return $ttl;
    }

    private function getCurrentTtl(int $ccflag): int
    {
        if ($this->currentTtl !== -1) {
            return $this->currentTtl;
        }
        $ttl = (($ccflag & CacheState::PRIV) === 0)
            ? $this->config->get(Conf::CFG_PUBLIC_TTL)
            : $this->config->get(Conf::CFG_PRIVATE_TTL);
        return $this->adjustTtlBySpecificPrices($ttl);
    }

    public function setCacheControlHeader(): void
    {
        if (headers_sent()) {
            return;
        }

        $headers       = [];
        $debug_headers = [];

        if (($purgeHeader = $this->getPurgeHeader()) !== '') {
            $headers[]       = $purgeHeader;
            $debug_headers[] = self::LSHEADER_DEBUG_PURGE . ': ' . $purgeHeader;
        }

        $cacheControlHeader = '';
        $ccflag             = CacheState::flag();

        if (CacheState::isCacheable()) {
            $prefix = CacheHelper::getTagPrefix();
            $tags   = [];
            $ttl    = $this->getCurrentTtl($ccflag);

            if (($ccflag & CacheState::PRIV) === 0) {
                $cacheControlHeader .= 'public,max-age=' . $ttl;
                $tags[] = $prefix;
                $shopId = Context::getContext()->shop->id;
                $tags[] = $prefix . '_' . Conf::TAG_PREFIX_SHOP . $shopId;
            } else {
                $cacheControlHeader .= 'private,no-vary,max-age=' . $ttl;
                $tags[] = 'public:' . $prefix . '_PRIV';
            }

            foreach (array_keys($this->cacheTags) as $tag) {
                $tags[] = $prefix . '_' . $tag;
            }

            $headers[]       = self::LSHEADER_CACHE_TAG . ': ' . implode(',', $tags);
            $debug_headers[] = self::LSHEADER_DEBUG_TAG . ': ' . implode(',', $tags);

            if (($ccflag & CacheState::GUEST) !== 0) {
                $guestmode = $this->config->get(Conf::CFG_GUESTMODE);
                if ($guestmode == 0 || $guestmode == 2) {
                    $headers[] = 'LSC-Cookie: PrestaShop-lsc=guest';
                }
            }
        } else {
            $cacheControlHeader = 'no-cache';
        }

        if (($ccflag & CacheState::ESI_ON) !== 0) {
            $cacheControlHeader .= ',esi=on';
        }
        if (($ccflag & CacheState::VARY_CHANGED) && ($ccflag & CacheState::ESI_REQ)) {
            $cacheControlHeader .= ',reload-after';
        }

        $headers[] = self::LSHEADER_CACHE_CONTROL . ': ' . $cacheControlHeader;

        foreach ($headers as $header) {
            header($header);
        }

        if (defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_SETHEADER) {
            LSLog::log('SetHeader ' . implode("\n", $headers), LSLog::LEVEL_SETHEADER);
        }
        if ($this->config->get(Conf::CFG_DEBUG_HEADER)) {
            $debug_headers[] = self::LSHEADER_DEBUG_CC . ': ' . $cacheControlHeader;
            $info            = htmlspecialchars(str_replace("\n", ' ', CacheState::debugInfo()));
            $debug_headers[] = self::LSHEADER_DEBUG_INFO . ': ' . substr($info, 0, 256);
            foreach ($debug_headers as $header) {
                header($header);
            }
        }
    }
}
