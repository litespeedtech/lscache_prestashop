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
 * @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

require_once('EsiModConf.php');
use LiteSpeedCacheLog as LSLog;
use LiteSpeedCacheEsiModConf as EsiConf;

class LiteSpeedCacheConfig
{
    /* public tag prefix */
    const TAG_PREFIX_CMS = 'G';
    const TAG_PREFIX_CATEGORY = 'C';
    const TAG_PREFIX_PRODUCT = 'P';
    const TAG_PREFIX_ESIBLOCK = 'E';
    const TAG_PREFIX_MANUFACTURER = 'M';
    const TAG_PREFIX_SUPPLIER = 'L';
    const TAG_PREFIX_SHOP = 'S';
    const TAG_PREFIX_PRIVATE = 'PRIV';
    /* public tags */
    const TAG_SEARCH = 'SR';
    const TAG_HOME = 'H';
    const TAG_SITEMAP = 'SP';
    const TAG_STORES = 'ST';
    const TAG_404 = 'D404';
    /* common private tags */
    const TAG_CART = 'cart';
    const TAG_SIGNIN = 'signin';
    const TAG_ENV = 'env';

    /* config entry */
    const ENTRY_ALL = 'LITESPEED_CACHE_GLOBAL';
    const ENTRY_SHOP = 'LITESPEED_CACHE_SHOP';
    const ENTRY_MODULE = 'LITESPEED_CACHE_MODULE';

    /* config fields */
    const CFG_ENABLED = 'enable';
    const CFG_PUBLIC_TTL = 'ttl';
    const CFG_PRIVATE_TTL = 'privttl';
    const CFG_404_TTL = '404ttl';
    const CFG_HOME_TTL = 'homettl';
    const CFG_DIFFMOBILE = 'diff_mobile';
    const CFG_DIFFCUSTGRP = 'diff_customergroup';
    const CFG_FLUSH_PRODCAT = 'flush_prodcat' ;
    const CFG_GUESTMODE = 'guestmode';
    const CFG_NOCACHE_VAR = 'nocache_vars';
    const CFG_NOCACHE_URL = 'nocache_urls';
    const CFG_DEBUG = 'debug';
    const CFG_DEBUG_LEVEL = 'debug_level';
    const CFG_ALLOW_IPS = 'allow_ips';
    const CFG_DEBUG_IPS = 'debug_ips';

    private $esiModConf = null;
    private $pubController = null;
    private $purgeController = array();
    private $all = null;
    private $shop = null;
    private $custMod = null;
    private $isDebug = 0;
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
            case self::CFG_FLUSH_PRODCAT:
            // in global developer form
            case self::CFG_DEBUG:
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
        return array();
    }

    public function getDefaultConfData($key)
    {
        if ($key == self::ENTRY_ALL) {
            return array(
                self::CFG_ENABLED => 0,
                self::CFG_DIFFMOBILE => 0,
                self::CFG_GUESTMODE => 1,
                self::CFG_NOCACHE_VAR => '',
                self::CFG_NOCACHE_URL => '',
                self::CFG_FLUSH_PRODCAT => 0,
                self::CFG_DEBUG => 0,
                self::CFG_DEBUG_LEVEL => 9,
                self::CFG_ALLOW_IPS => '',
                self::CFG_DEBUG_IPS => '',
            );
        } elseif ($key == self::ENTRY_SHOP) {
            return array(
                self::CFG_PUBLIC_TTL => 86400,
                self::CFG_PRIVATE_TTL => 1800,
                self::CFG_HOME_TTL => 86400,
                self::CFG_404_TTL => 86400,
                self::CFG_DIFFCUSTGRP => 0,
            );
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
        $newMod = array();
        $newevents = array();
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
                $res = Hook::registerHook($mymod, $a);
                if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
                    LSLog::log('in registerHook ' . $a . '=' . $res, LSLog::LEVEL_UPDCONFIG);
                }
            }
            foreach ($removed as $r) {
                $res = Hook::unregisterHook($mymod, $r);
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
                $this->all = array(
                    self::CFG_ENABLED => $values[self::CFG_ENABLED],
                    self::CFG_DIFFMOBILE => $values[self::CFG_DIFFMOBILE],
                    self::CFG_GUESTMODE => $values[self::CFG_GUESTMODE],
                    self::CFG_NOCACHE_VAR => $values[self::CFG_NOCACHE_VAR],
                    self::CFG_NOCACHE_URL => $values[self::CFG_NOCACHE_URL],
                    self::CFG_FLUSH_PRODCAT => $values[self::CFG_FLUSH_PRODCAT],
                    self::CFG_DEBUG => $values[self::CFG_DEBUG],
                    self::CFG_DEBUG_LEVEL => $values[self::CFG_DEBUG_LEVEL],
                    self::CFG_ALLOW_IPS => $values[self::CFG_ALLOW_IPS],
                    self::CFG_DEBUG_IPS => $values[self::CFG_DEBUG_IPS],
                );
                Configuration::updateValue(self::ENTRY_ALL, json_encode($this->all));
                break;
            case self::ENTRY_SHOP:
                $this->shop = array(
                    self::CFG_PUBLIC_TTL => $values[self::CFG_PUBLIC_TTL],
                    self::CFG_PRIVATE_TTL => $values[self::CFG_PRIVATE_TTL],
                    self::CFG_HOME_TTL => $values[self::CFG_HOME_TTL],
                    self::CFG_404_TTL => $values[self::CFG_404_TTL],
                    self::CFG_DIFFCUSTGRP => $values[self::CFG_DIFFCUSTGRP],
                );
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

        $this->custMod = Configuration::get(self::ENTRY_MODULE);
        $this->esiModConf = array('mods' => array(), 'purge_events' => array());
        $custdata = json_decode($this->custMod, true);
        if ($custdata) {
            foreach ($custdata as $name => $sdata) {
                $esiconf = new EsiConf($name, EsiConf::TYPE_CUSTOMIZED, $sdata);
                $this->registerEsiModule($esiconf);
            }
        }

        $this->pubController = array(
            'IndexController' => self::TAG_HOME, // controller name - tag linked to it
            'ProductController' => '',
            'CategoryController' => '',
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
        );

        LSLog::setDebugLevel($this->isDebug);
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', $this->isDebug);
        }
    }

    public function isControllerCacheable($controllerClass)
    {
        if (!isset($this->pubController[$controllerClass])) {
            return false;
        }
        if ($controllerClass == 'PageNotFoundController') {
            if ($this->get(self::CFG_404_TTL) == 0) {
                return false;
            }
        }
        return $this->pubController[$controllerClass];
    }

    public function getNoCacheConf()
    {
        $nocache = array(self::CFG_NOCACHE_URL => $this->getArray(self::CFG_NOCACHE_URL),
            self::CFG_NOCACHE_VAR => $this->getArray(self::CFG_NOCACHE_VAR));
        return $nocache;
    }

    // if allowed, return conf
    public function canInjectEsi($name, $params)
    {
        $m = &$this->esiModConf['mods'];
        if (isset($m[$name]) && $m[$name]->canInject($params)) {
            return $m[$name];
        } else {
            return false;
        }
    }

    public function esiInjectRenderWidget($mName, $hName)
    {
        $m = &$this->esiModConf['mods'];
        if (isset($m[$mName]) && $m[$mName]->injectRenderWidget($hName)) {
            return $m[$mName];
        }
        return false;
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
        $isPriv = $esiConf->isPrivate();
        $type = $isPriv ? 'priv' : 'pub';
        $tag = $esiConf->getTag();
        if ($pc = $esiConf->getPurgeControllers()) {
            foreach ($pc as $cname => $cparam) {
                // allow ClassName:param1&param2=value
                $cname = Tools::strtolower($cname);
                if (!isset($this->purgeController[$cname])) {
                    $this->purgeController[$cname] = array();
                }
                $detail = &$this->purgeController[$cname];
                if (!isset($detail[$cparam])) {
                    $detail[$cparam] = array('priv' => array(), 'pub' => array());
                }
                if (!in_array($tag, $detail[$cparam][$type])) {
                    $detail[$cparam][$type][] = $tag;
                }
            }
        }
        $events = $esiConf->getPurgeEvents();
        if (!empty($events)) {
            foreach ($events as $event) {
                if (!isset($this->esiModConf['purge_events'][$event])) {
                    $this->esiModConf['purge_events'][$event] = array('priv' => array(), 'pub' => array());
                }
                if (!in_array($tag, $this->esiModConf['purge_events'][$event][$type])) {
                    $this->esiModConf['purge_events'][$event][$type][] = $tag;
                }
            }
        }
    }

    public function isPurgeController($controller_class)
    {
        $c = Tools::strtolower($controller_class);
        if (!isset($this->purgeController[$c])) {
            return false;
        }

        $conf = array('pub' => array(), 'priv' => array());
        foreach ($this->purgeController[$c] as $param => $tags) {
            if ($param !== 0) {
                //param1&param2
                $params = explode('&', $param);
                foreach ($params as $paramName) {
                    if (Tools::getValue($paramName) == false) {
                        continue 2;
                    }
                }
            }
            if (!empty($tags['priv'])) {
                $conf['priv'] = $tags['priv'];
            }
            if (!empty($tags['pub'])) {
                $conf['pub'] = $tags['pub'];
            }
        }

        if (!empty($conf['priv']) || !empty($conf['pub'])) {
            return $conf;
        }
        return false;
    }

    public function getDefaultPurgeTagsByProduct()
    {
        // maybe configurable later
        $tags = array(
            self::TAG_SEARCH,
            self::TAG_HOME,
            self::TAG_SITEMAP
        );
        return $tags;
    }

    public function getDefaultPurgeTagsByCategory()
    {
        // maybe configurable later
        $tags = array(
            self::TAG_SEARCH,
            self::TAG_HOME,
            self::TAG_SITEMAP
        );
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
        $hooks = array(
            /** global * */
            'actionDispatcher', // check cacheable for route
            'displayFooterAfter', // show debug info
            /** add cache tags * */
            'overrideLayoutTemplate',
            'DisplayOverrideTemplate',
            'filterCategoryContent',
            'filterProductContent',
            'filterCmsContent',
            'filterCmsCategoryContent',
            /** private purge * */
            'actionCustomerLogoutAfter',
            'actionAuthentication',
            'actionCustomerAccountAdd',
            /** specific price check **/
            'actionProductSearchAfter',
            /** public purge * */
            /***** product *****/
            'actionProductAdd',
            'actionProductSave',
            'actionProductUpdate', //array('id_product' => (int)$this->id, 'product' => $this)
            'actionProductDelete',
            'actionObjectSpecificPriceCoreAddAfter',
            'actionObjectSpecificPriceCoreDeleteAfter',
            'actionWatermark',
            'displayOrderConfirmation', // from OrderConfirmationController, array('order' => $order)
            /***** category *****/
            'categoryUpdate', // array('category' => $category)
            'actionCategoryUpdate',
            'actionCategoryAdd', // here do not purge all, as user can manually do that
            'actionCategoryDelete',
            /***** cms *****/
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            'actionObjectCmsAddAfter',
            /***** supplier *****/
            'actionObjectSupplierUpdateAfter',
            'actionObjectSupplierDeleteAfter',
            'actionObjectSupplierAddAfter',
            /***** manufacturer *****/
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectManufacturerAddAfter',
            /***** stores *****/
            'actionObjectStoreUpdateAfter',
            /** lscache own hooks * */
            'litespeedCachePurge',
            'litespeedNotCacheable',
            'litespeedEsiBegin',
            'litespeedEsiEnd',
        );

        return $hooks;
    }
}
