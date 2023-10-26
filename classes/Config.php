<?php
/**
 * LiteSpeed Cache for Prestashop.
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
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

require_once 'EsiModConf.php';
use LiteSpeedCacheEsiModConf as EsiConf;
use LiteSpeedCacheLog as LSLog;

class LiteSpeedCacheConfig
{
    // public tag prefix
    const TAG_PREFIX_CMS = 'G';

    const TAG_PREFIX_CATEGORY = 'C';

    const TAG_PREFIX_PRODUCT = 'P';

    const TAG_PREFIX_ESIBLOCK = 'E';

    const TAG_PREFIX_MANUFACTURER = 'M';

    const TAG_PREFIX_SUPPLIER = 'L';

    const TAG_PREFIX_SHOP = 'S';

    const TAG_PREFIX_PCOMMENTS = 'N';

    const TAG_PREFIX_PRIVATE = 'PRIV';

    // public tags
    const TAG_SEARCH = 'SR';

    const TAG_HOME = 'H';

    const TAG_SITEMAP = 'SP';

    const TAG_STORES = 'ST';

    const TAG_404 = 'D404';
    
    // common private tags
    const TAG_CART = 'cart';

    const TAG_SIGNIN = 'signin';

    const TAG_ENV = 'env';

    // config entry
    const ENTRY_ALL = 'LITESPEED_CACHE_GLOBAL';

    const ENTRY_SHOP = 'LITESPEED_CACHE_SHOP';

    const ENTRY_MODULE = 'LITESPEED_CACHE_MODULE';

    // config fields
    const CFG_ENABLED = 'enable';

    const CFG_PUBLIC_TTL = 'ttl';

    const CFG_PRIVATE_TTL = 'privttl';

    const CFG_404_TTL = '404ttl';

    const CFG_HOME_TTL = 'homettl';
    
    const CFG_PCOMMENTS_TTL = 'pcommentsttl';

    const CFG_DIFFMOBILE = 'diff_mobile';

    const CFG_DIFFCUSTGRP = 'diff_customergroup';

    const CFG_FLUSH_PRODCAT = 'flush_prodcat';

    const CFG_FLUSH_HOME = 'flush_home';

    const CFG_FLUSH_HOME_INPUT = 'flush_homeinput';

    const CFG_GUESTMODE = 'guestmode';

    const CFG_NOCACHE_VAR = 'nocache_vars';

    const CFG_NOCACHE_URL = 'nocache_urls';

    const CFG_VARY_BYPASS = 'vary_bypass';

    const CFG_DEBUG = 'debug';

    const CFG_DEBUG_HEADER = 'debug_header';

    const CFG_DEBUG_LEVEL = 'debug_level';

    const CFG_ALLOW_IPS = 'allow_ips';

    const CFG_DEBUG_IPS = 'debug_ips';

    private $esiModConf;

    private $pubController = [];

    private $purgeController = [];

    private $all;

    private $shop;

    private $custMod;

    private $enforceDiffGroup = 0;

    private $isDebug = 0;

    private $flushHomePids = null;

    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new LiteSpeedCacheConfig();
        }

        return self::$instance;
    }

    public function get($configField)
    {
        if ($this->all == null) {
            $this->init();
        }

        switch ($configField) {
            case self::ENTRY_ALL:
                return array_replace($this->getDefaultConfData(self::ENTRY_ALL), $this->all);
            // in global scope
            case self::CFG_ENABLED:
            case self::CFG_DIFFMOBILE:
            case self::CFG_GUESTMODE:
            case self::CFG_NOCACHE_VAR:
            case self::CFG_NOCACHE_URL:
            case self::CFG_VARY_BYPASS:
            case self::CFG_FLUSH_PRODCAT:
            case self::CFG_FLUSH_HOME:
            case self::CFG_FLUSH_HOME_INPUT:
            // in global developer form
            case self::CFG_DEBUG:
            case self::CFG_DEBUG_HEADER:
            case self::CFG_DEBUG_LEVEL:
            case self::CFG_ALLOW_IPS:
            case self::CFG_DEBUG_IPS:
                if (!isset($this->all[$configField])) {
                    $this->all = array_replace($this->getDefaultConfData(self::ENTRY_ALL), $this->all);
                }

                return $this->all[$configField];
            // shop level config
            case self::ENTRY_SHOP:
                return array_replace($this->getDefaultConfData(self::ENTRY_SHOP), $this->shop);
            // in shop scope
            case self::CFG_PUBLIC_TTL:
            case self::CFG_PRIVATE_TTL:
            case self::CFG_HOME_TTL:
            case self::CFG_404_TTL:
            case self::CFG_PCOMMENTS_TTL:
            case self::CFG_DIFFCUSTGRP:
                if (!isset($this->shop[$configField])) {
                    $this->shop = array_replace($this->getDefaultConfData(self::ENTRY_SHOP), $this->shop);
                }

                return $this->shop[$configField];
            // in module customization
            case self::ENTRY_MODULE:
                return $this->esiModConf['mods'];
        }

        return null;
    }

    public function overrideGuestMode()
    {
        // if config has guest mode, change to first page only
        // hardcode behavior here as htaccess already set vary
        if ($this->all[self::CFG_GUESTMODE] == 1) {
            $this->all[self::CFG_GUESTMODE] = 2;
        }
    }

    public function getArray($configField)
    {
        if (($value = $this->get($configField)) != '') {
            return preg_split("/[\s,]+/", $value, null, PREG_SPLIT_NO_EMPTY);
        }

        return [];
    }

    public function getDefaultConfData($key)
    {
        if ($key == self::ENTRY_ALL) {
            return [
                self::CFG_ENABLED => 0,
                self::CFG_DIFFMOBILE => 0,
                self::CFG_GUESTMODE => 1,
                self::CFG_NOCACHE_VAR => '',
                self::CFG_NOCACHE_URL => '',
                self::CFG_VARY_BYPASS => '',
                self::CFG_FLUSH_PRODCAT => 0,
                self::CFG_FLUSH_HOME => 0,
                self::CFG_FLUSH_HOME_INPUT => '',
                self::CFG_DEBUG => 0,
                self::CFG_DEBUG_HEADER => 0,
                self::CFG_DEBUG_LEVEL => 9,
                self::CFG_ALLOW_IPS => '',
                self::CFG_DEBUG_IPS => '',
            ];
        } elseif ($key == self::ENTRY_SHOP) {
            return [
                self::CFG_PUBLIC_TTL => 86400,
                self::CFG_PRIVATE_TTL => 1800,
                self::CFG_HOME_TTL => 86400,
                self::CFG_404_TTL => 86400,
                self::CFG_PCOMMENTS_TTL => 7200,
                self::CFG_DIFFCUSTGRP => 0,
            ];
        }
    }

    public function getAllConfigValues()
    {
        return array_merge($this->get(self::ENTRY_ALL), $this->get(self::ENTRY_SHOP));
    }

    // action is add/edit/delete
    public function saveModConfigValues($currentEntry, $action)
    {
        if (empty($currentEntry['id'])) {
            return false;
        }
        $id = $currentEntry['id'];
        $item = $this->getEsiModuleConf($id);
        if ($item != null && !$item->isCustomized()) {
            // should not come here, do not allow touch default modules
            return false;
        }

        $oldevents = array_keys($this->esiModConf['purge_events']);

        if ($action == 'new' || $action == 'edit') {
            $newitem = new EsiConf($id, EsiConf::TYPE_CUSTOMIZED, $currentEntry);
            $this->esiModConf['mods'][$id] = $newitem;
        } elseif ($action == 'delete') {
            unset($this->esiModConf['mods'][$id]);
        } else {
            // sth wrong
            return false;
        }
        $newMod = [];
        $newevents = [];
        foreach ($this->esiModConf['mods'] as $mi) {
            if (($events = $mi->getPurgeEvents()) != null) {
                $newevents = array_merge($newevents, $events);
            }
            if ($mi->isCustomized()) {
                $newMod[$mi->getModuleName()] = $mi;
            }
        }
        $newevents = array_unique($newevents);

        if (!empty($newMod)) {
            ksort($newMod);
        }
        $newModValue = json_encode($newMod);
        if ($newModValue != $this->custMod) {
            $this->updateConfiguration(self::ENTRY_MODULE, $newModValue);
        }

        $builtin = $this->getReservedHooks();
        $added = array_diff($newevents, $oldevents, $builtin);
        $removed = array_diff($oldevents, $newevents, $builtin);

        if (!empty($added) || !empty($removed)) {
            $mymod = Module::getInstanceByName(LiteSpeedCache::MODULE_NAME);
            foreach ($added as $a) {
                $res = $mymod->registerHook($a);
                if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
                    LSLog::log('in registerHook ' . $a . '=' . $res, LSLog::LEVEL_UPDCONFIG);
                }
            }
            foreach ($removed as $r) {
                $res = $mymod->unregisterHook($r);
                if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
                    LSLog::log('in unregisterHook ' . $r . '=' . $res, LSLog::LEVEL_UPDCONFIG);
                }
            }

            return 2;
        }

        return 1;
    }

    public function updateConfiguration($key, $values)
    {
        if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
            LSLog::log('in updateConfiguration context=' . Shop::getContext()
                    . " key = $key value = " . var_export($values, true), LSLog::LEVEL_UPDCONFIG);
        }
        switch ($key) {
            case self::ENTRY_ALL:
                $this->all = [
                    self::CFG_ENABLED => $values[self::CFG_ENABLED],
                    self::CFG_DIFFMOBILE => $values[self::CFG_DIFFMOBILE],
                    self::CFG_GUESTMODE => $values[self::CFG_GUESTMODE],
                    self::CFG_NOCACHE_VAR => $values[self::CFG_NOCACHE_VAR],
                    self::CFG_NOCACHE_URL => $values[self::CFG_NOCACHE_URL],
                    self::CFG_VARY_BYPASS => $values[self::CFG_VARY_BYPASS],
                    self::CFG_FLUSH_PRODCAT => $values[self::CFG_FLUSH_PRODCAT],
                    self::CFG_FLUSH_HOME => $values[self::CFG_FLUSH_HOME],
                    self::CFG_FLUSH_HOME_INPUT => $values[self::CFG_FLUSH_HOME_INPUT],
                    self::CFG_DEBUG => $values[self::CFG_DEBUG],
                    self::CFG_DEBUG_HEADER => $values[self::CFG_DEBUG_HEADER],
                    self::CFG_DEBUG_LEVEL => $values[self::CFG_DEBUG_LEVEL],
                    self::CFG_ALLOW_IPS => $values[self::CFG_ALLOW_IPS],
                    self::CFG_DEBUG_IPS => $values[self::CFG_DEBUG_IPS],
                ];
                Configuration::updateValue(self::ENTRY_ALL, json_encode($this->all));
                break;
            case self::ENTRY_SHOP:
                $this->shop = [
                    self::CFG_PUBLIC_TTL => $values[self::CFG_PUBLIC_TTL],
                    self::CFG_PRIVATE_TTL => $values[self::CFG_PRIVATE_TTL],
                    self::CFG_HOME_TTL => $values[self::CFG_HOME_TTL],
                    self::CFG_404_TTL => $values[self::CFG_404_TTL],
                    self::CFG_PCOMMENTS_TTL => $values[self::CFG_PCOMMENTS_TTL],
                    self::CFG_DIFFCUSTGRP => $values[self::CFG_DIFFCUSTGRP],
                ];
                Configuration::updateValue(self::ENTRY_SHOP, json_encode($this->shop));
                break;
            case self::ENTRY_MODULE:
                $this->custMod = $values;
                Configuration::updateValue(self::ENTRY_MODULE, $this->custMod);
                break;
            default:
                return false;
        }

        return true;
    }

    public function isDebug($requiredLevel = 0)
    {
        $this->get(self::CFG_DEBUG); // this will initialize if needed
        return ($this->isDebug < $requiredLevel) ? 0 : $this->isDebug;
    }

    private function init()
    {
        $this->all = json_decode(Configuration::get(self::ENTRY_ALL), true);

        if (!$this->all) { // for config not exist, or decode err
            LSLog::log('Config not exist yet or decode err', LSLog::LEVEL_FORCE);
            $this->all = $this->getDefaultConfData(self::ENTRY_ALL);
        }

        if ($this->all[self::CFG_DEBUG]) {
            $ips = $this->getArray(self::CFG_DEBUG_IPS);
            if (empty($ips) || in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                $this->isDebug = $this->all[self::CFG_DEBUG_LEVEL];
            }
        }

        $this->shop = json_decode(Configuration::get(self::ENTRY_SHOP), true);
        if (!$this->shop) {
            $this->shop = $this->getDefaultConfData(self::ENTRY_SHOP);
        }

        $this->addDefaultPurgeControllers();
        
        $this->custMod = Configuration::get(self::ENTRY_MODULE);
        $this->esiModConf = ['mods' => [], 'purge_events' => []];
        $custdata = json_decode($this->custMod, true);
        if ($custdata) {
            foreach ($custdata as $name => $sdata) {
                $esiconf = new EsiConf($name, EsiConf::TYPE_CUSTOMIZED, $sdata);
                $this->registerEsiModule($esiconf);
            }
        }

        $this->addExtraPubControllers([
            'IndexController' => self::TAG_HOME, // controller name - tag linked to it
            'ProductController' => '',
            'CategoryController' => '',
            'prestablogblogModuleFrontController' => '',// permit to cache prestablog module page                          
            'CmsController' => '',
            'ManufacturerController' => '',
            'SupplierController' => '',
            'SearchController' => self::TAG_SEARCH,
            'BestSalesController' => self::TAG_SEARCH,
            'NewProductsController' => self::TAG_SEARCH,
            'PricesDropController' => self::TAG_SEARCH,
            'SitemapController' => self::TAG_SITEMAP,
            'StoresController' => self::TAG_STORES,
            'PageNotFoundController' => self::TAG_404,
            'productcommentsListCommentsModuleFrontController' => self::TAG_PREFIX_PCOMMENTS,
            'productcommentsCommentGradeModuleFrontController' => self::TAG_PREFIX_PCOMMENTS,
        ]);
        
        LSLog::setDebugLevel($this->isDebug);
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', $this->isDebug);
        }
    }
    
    public function addExtraPubControllers($extraPubControllers)
    {
        array_walk($extraPubControllers, function($tag, $key) {
            $this->pubController[strtolower($key)] = $tag;
        });
    }

    public function isControllerCacheable($controllerClass)
    {
        $controllerClass = strtolower($controllerClass);
        if (!isset($this->pubController[$controllerClass])) {
            return false;
        }
        $tag = $this->pubController[$controllerClass];
        $ttl = -1;
        
        if ($controllerClass == 'indexcontroller') {
            $ttl = $this->get(self::CFG_HOME_TTL);
        } elseif ($controllerClass == 'pagenotfoundcontroller') {
            $ttl = $this->get(self::CFG_404_TTL);
        } elseif ($controllerClass == 'productcommentslistcommentsmodulefrontcontroller'
            || $controllerClass == 'productcommentscommentgrademodulefrontcontroller') {
            $ttl = $this->get(self::CFG_PCOMMENTS_TTL);
            $idProduct = Tools::getValue('id_product');
            if ($idProduct) {
                $tag = self::TAG_PREFIX_PCOMMENTS . $idProduct;
            }
        }

        return ['tag' => $tag, 'ttl' => $ttl];
    }

    public function getNoCacheConf()
    {
        $nocache = [self::CFG_NOCACHE_URL => $this->getArray(self::CFG_NOCACHE_URL),
            self::CFG_NOCACHE_VAR => $this->getArray(self::CFG_NOCACHE_VAR), ];

        return $nocache;
    }

    public function getContextBypass()
    {
        return $this->getArray(self::CFG_VARY_BYPASS);
    }

    public function getFlushHomePids()
    {
        if ($this->flushHomePids === null) {
            if ($this->get(self::CFG_FLUSH_HOME) > 0) {
                $this->flushHomePids = $this->getArray(self::CFG_FLUSH_HOME_INPUT);
            } else {
                $this->flushHomePids = false;
            }
        }

        return $this->flushHomePids;
    }

    public function getDiffCustomerGroup()
    {
        // $diffCustomerGroup 0: No; 1: Yes; 2: login_out
        $diffGroup = $this->get(self::CFG_DIFFCUSTGRP);
        // check 2 override case
        if ($this->enforceDiffGroup == 1) {
            return 1;
        } elseif ($this->enforceDiffGroup == 2 && $diffGroup == 0) {
            return 2; // in_out only
        }
        return $diffGroup;
    }

    public function enforceDiffCustomerGroup($enforcedSetting)
    {
        if ($enforcedSetting == 1) { // separate per group
            $this->enforceDiffGroup = 1;
        } elseif ($enforcedSetting == 2 && $this->enforceDiffGroup == 0) {
            $this->enforceDiffGroup = 2; // in_out only
        }
    }

    // if allowed, return conf
    public function canInjectEsi($name, &$params)
    {
        $m = &$this->esiModConf['mods'];
        if (isset($m[$name]) && $m[$name]->canInject($params)) {
            return $m[$name];
        } else {
            return false;
        }
    }

    public function isEsiModule($moduleName)
    {
        return isset($this->esiModConf['mods'][$moduleName]);
    }

    public function getEsiModuleConf($moduleName)
    {
        if (isset($this->esiModConf['mods'][$moduleName])) {
            return $this->esiModConf['mods'][$moduleName];
        }

        return null;
    }

    public function getPurgeTagsByEvent($event)
    {
        if (isset($this->esiModConf['purge_events'][$event])) {
            return $this->esiModConf['purge_events'][$event];
        }

        return null;
    }

    public function registerEsiModule(EsiConf $esiConf)
    {
        $modname = $esiConf->getModuleName();
        $this->esiModConf['mods'][$modname] = $esiConf;
        $type = $esiConf->isPrivate() ? 'priv' : 'pub';
        $tags = $esiConf->getTags();
        if ($pc = $esiConf->getPurgeControllers()) {
            foreach ($pc as $controllerName => $controllerParam) {
                // allow ClassName?param1&param2=value
                // if className?custom_handler, will let module handle it
                $controllerName = Tools::strtolower($controllerName);
                if (!isset($this->purgeController[$controllerName])) {
                    $this->purgeController[$controllerName] = [];
                }
                $detail = &$this->purgeController[$controllerName];
                if (!isset($detail[$controllerParam])) {
                    $detail[$controllerParam] = ['priv' => [], 'pub' => []];
                }
                $detail[$controllerParam][$type] = array_unique(array_merge($detail[$controllerParam][$type], $tags));
            }
        }
        $events = $esiConf->getPurgeEvents();
        if (!empty($events)) {
            foreach ($events as $event) {
                if (!isset($this->esiModConf['purge_events'][$event])) {
                    $this->esiModConf['purge_events'][$event] = ['priv' => [], 'pub' => []];
                }
                $this->esiModConf['purge_events'][$event][$type] = array_unique(array_merge($this->esiModConf['purge_events'][$event][$type], $tags));
            }
        }
    }
    
    private function addDefaultPurgeControllers()
    {
        if (version_compare(_PS_VERSION_, '1.7.1.0', '<')) {
            // older version does not have hook method, 
            $this->purgeController['adminperformancecontroller'] = ['empty_smarty_cache' => ['priv' => ['*'], 'pub' => ['*']]];
        }
    }

    public function isPurgeController($controller_class)
    {
        $c = Tools::strtolower($controller_class);
        if (!isset($this->purgeController[$c])) {
            return false;
        }

        $conf = ['pub' => [], 'priv' => []];
        foreach ($this->purgeController[$c] as $param => $tags) {
            if ($param !== 0) {
                // custom_handler
                if ($param == 'custom_handler') {
                    LscIntegration::checkPurgeController($c, $conf);
                    continue;
                }
                //param1&param2
                $params = explode('&', $param);
                foreach ($params as $paramName) {
                    if (Tools::getValue($paramName) == false) {
                        continue 2;
                    }
                }
            }
            if (!empty($tags['priv'])) {
                $conf['priv'] = array_merge($conf['priv'], $tags['priv']);
            }
            if (!empty($tags['pub'])) {
                $conf['pub'] = array_merge($conf['pub'], $tags['pub']);
            }
        }

        if (empty($conf['priv']) && empty($conf['pub'])) {
            $this->advancedCheckPurgeController($controller_class, $conf);
        }

        if (!empty($conf['priv']) || !empty($conf['pub'])) {
            return $conf;
        }

        return false;
    }

    protected function advancedCheckPurgeController($controller_class, &$conf)
    {
        // not only class name, but other params
        if ($controller_class == 'AdminProductsController') {
            if (Tools::isSubmit('deleteSpecificPrice') || Tools::isSubmit('submitSpecificPricePriorities')) {
                $id_product = Tools::getValue('id_product');
                Hook::exec('litespeedCacheProductUpdate', ['id_product' => $id_product]);
            }
        }
    }

    public function getDefaultPurgeTagsByProduct()
    {
        // maybe configurable later
        $tags = [
            self::TAG_SEARCH,
            self::TAG_SITEMAP,
        ];

        return $tags;
    }

    public function getDefaultPurgeTagsByCategory()
    {
        // maybe configurable later
        $tags = [
            self::TAG_SEARCH,
            self::TAG_SITEMAP,
        ];

        return $tags;
    }

    public function moduleEnabled()
    {
        $flag = 0;
        if (LiteSpeedCacheHelper::licenseEnabled() && $this->get(self::CFG_ENABLED)) {
            $ips = $this->getArray(self::CFG_ALLOW_IPS);
            if (!empty($ips)) {
                // has restricted IP
                $flag = LiteSpeedCache::CCBM_MOD_ALLOWIP;
                if (in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                    // allow for restricted IP
                    $flag |= LiteSpeedCache::CCBM_MOD_ACTIVE;
                }
            } else {
                // allow for everyone
                $flag = LiteSpeedCache::CCBM_MOD_ACTIVE;
            }
        }

        return $flag;
    }

    public function getReservedHooks()
    {
        $hooks = [
            /* global * */
            'actionDispatcher', // check cacheable for route
            'displayFooterAfter', // show debug info
            /* add cache tags * */
            'overrideLayoutTemplate',
            'DisplayOverrideTemplate',
            'filterCategoryContent',
            'filterProductContent',
            'filterCmsContent',
            'filterCmsCategoryContent',
            /* private purge * */
            'actionCustomerLogoutAfter',
            'actionAuthentication',
            'actionCustomerAccountAdd',
            /* specific price check */
            'actionProductSearchAfter',
            /* public purge * */
            // product
            'actionProductAdd',
            'actionProductSave',
            'actionProductUpdate', //array('id_product' => (int)$this->id, 'product' => $this)
            'actionProductDelete',
            'actionProductAttributeDelete', // 'id_product'
            'deleteProductAttribute', // 'id_product'
            'actionObjectSpecificPriceCoreAddAfter',
            'actionObjectSpecificPriceCoreUpdateAfter',
            'actionObjectSpecificPriceCoreDeleteAfter',
            'actionWatermark',
            'displayOrderConfirmation', // from OrderConfirmationController, array('order' => $order)
            // category
            'categoryUpdate', // array('category' => $category)
            'actionCategoryUpdate',
            'actionCategoryAdd', // here do not purge all, as user can manually do that
            'actionCategoryDelete',
            // cms
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            'actionObjectCmsAddAfter',
            // supplier
            'actionObjectSupplierUpdateAfter',
            'actionObjectSupplierDeleteAfter',
            'actionObjectSupplierAddAfter',
            // manufacturer
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectManufacturerAddAfter',
            // stores
            'actionObjectStoreUpdateAfter',
            /* lscache own hooks * */
            'litespeedCachePurge',
            'litespeedCacheProductUpdate',
            'litespeedNotCacheable',
            'litespeedEsiBegin',
            'litespeedEsiEnd',
            // web service
            'addWebserviceResources',
            'updateProduct', // from Product array('id_product' => )
            'actionUpdateQuantity', // from StockAvailable array('id_product' => $id_product,...)
        ];

        if (version_compare(_PS_VERSION_, '1.7.1.0', '>=')) {
            $hooks[] = 'actionClearCompileCache';
            $hooks[] = 'actionClearSf2Cache';
        }

        return $hooks;
    }
}
