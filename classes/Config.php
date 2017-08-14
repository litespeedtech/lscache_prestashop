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
    const CFG_DIFFCUSTGRP = 'diff_customergroup';
    const CFG_NOCACHE_VAR = 'nocache_vars';
    const CFG_NOCACHE_URL = 'nocache_urls';
    const CFG_DEBUG = 'debug';
    const CFG_DEBUG_LEVEL = 'debug_level';
    const CFG_ALLOW_IPS = 'allow_ips';
    const CFG_DEBUG_IPS = 'debug_ips';

    private $esiModConf = null;
    private $pubController = null;
    private $all = null;
    private $shop = null;
    private $mod = null;
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
            case self::CFG_NOCACHE_VAR:
            case self::CFG_NOCACHE_URL:
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
                return $this->mod;
        }
        return null;
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
                self::CFG_NOCACHE_VAR => '',
                self::CFG_NOCACHE_URL => '',
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
        } elseif ($key == self::ENTRY_MODULE) {
            return array(
                'ps_customersignin' => array(
                    'priv' => 1,
                    'ttl' => '',
                    'tag' => 'signin',
                    'events' => 'actionCustomerLogoutAfter, actionAuthentication',
                ),
                'ps_shoppingcart' => array(
                    'priv' => 1,
                    'ttl' => '',
                    'tag' => 'cart',
                    'events' => 'actionAjaxDieCartControllerdisplayAjaxUpdateBefore',
                ),
            );
        }
    }

    public function getAllConfigValues()
    {
        return array_merge($this->get(self::ENTRY_ALL), $this->get(self::ENTRY_SHOP));
    }

    public function getModConfigValues()
    {
        $data = $this->get(self::ENTRY_MODULE);
        foreach ($data as $idname => &$conf) {
            $conf['id'] = $idname;
            if ($tmp_instance = Module::getInstanceByName($idname)) {
                $conf['name'] = $tmp_instance->displayName;
            } else {
                $conf['name'] = $this->l('Invalid module - should be removed');
            }
        }
        return $data;
    }

    // action is add/edit/delete
    public function saveModConfigValues($currentEntry, $action)
    {
        if (empty($currentEntry['id'])) {
            return false;
        }
        $id = $currentEntry['id'];
        $defaults = $this->getDefaultConfData(self::ENTRY_MODULE);
        if (isset($defaults[$id])) {
            // should not come here, do not allow touch default modules
            return false;
        }
        $old = $this->mod;
        $oldevents = $this->getModuleEvents($old);
        if ($action == 'new' || $action == 'edit') {
            $this->mod[$id] = array(
                'priv' => $currentEntry['priv'],
                'ttl' => $currentEntry['ttl'],
                'tag' => $currentEntry['tag'],
                'events' => $currentEntry['events']
            );
        } elseif ($action == 'delete') {
            unset($this->mod[$id]);
        } else {
            // sth wrong
            return false;
        }
        $this->updateConfiguration(self::ENTRY_MODULE, $this->mod);
        $newevents = $this->getModuleEvents($this->mod);
        $builtin = $this->getReservedHooks();
        $added = array_diff($newevents, $oldevents, $builtin);
        $removed = array_diff($oldevents, $newevents, $builtin);
        if (!empty($added) || !empty($removed)) {
            $mymod = Module::getInstanceByName(LiteSpeedCache::MODULE_NAME);
            foreach ($added as $a) {
                $res = Hook::registerHook($mymod, $a);
                if ($this->isDebug >= DebugLog::LEVEL_UPDCONFIG) {
                    DebugLog::log('in registerHook ' . $a . '=' . $res, DebugLog::LEVEL_UPDCONFIG);
                }
            }
            foreach ($removed as $r) {
                $res = Hook::unregisterHook($mymod, $r);
                if ($this->isDebug >= DebugLog::LEVEL_UPDCONFIG) {
                    DebugLog::log('in unregisterHook ' . $r . '=' . $res, DebugLog::LEVEL_UPDCONFIG);
                }
            }
            return 2;
        }
        return 1;
    }

    private function getModuleEvents($modData)
    {
        $events = array();
        foreach ($modData as $d) {
            if (!empty($d['events'])) {
                $de = preg_split("/[\s,]+/", $d['events']);
                $events = array_merge($events, $de);
            }
        }
        return array_unique($events);
    }

    public function updateConfiguration($key, $values)
    {
        if ($this->isDebug >= DebugLog::LEVEL_UPDCONFIG) {
            DebugLog::log('in updateConfiguration context=' . Shop::getContext()
                    . " key = $key value = " . print_r($values, true), DebugLog::LEVEL_UPDCONFIG);
        }
        switch ($key) {
            case self::ENTRY_ALL:
                $this->all = array(
                    self::CFG_ENABLED => $values[self::CFG_ENABLED],
                    self::CFG_NOCACHE_VAR => $values[self::CFG_NOCACHE_VAR],
                    self::CFG_NOCACHE_URL => $values[self::CFG_NOCACHE_URL],
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
                $this->mod = $values;
                Configuration::updateValue(self::ENTRY_MODULE, json_encode($this->mod));
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
            DebugLog::log('Config not exist yet or decode err', DebugLog::LEVEL_FORCE);
            $this->all = $this->getDefaultConfData(self::ENTRY_ALL);
        }

        if ($this->all[self::CFG_DEBUG]) {
            $ips = $this->getArray(self::CFG_DEBUG_IPS);
            if (empty($ips) || in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                $this->isDebug = $this->all[self::CFG_DEBUG_LEVEL];
                DebugLog::setDebugLevel($this->isDebug);
            }
        }

        $this->shop = json_decode(Configuration::get(self::ENTRY_SHOP), true);
        if (!$this->shop) {
            $this->shop = $this->getDefaultConfData(self::ENTRY_SHOP);
        }

        $this->mod = json_decode(Configuration::get(self::ENTRY_MODULE), true);
        $default_mod = $this->getDefaultConfData(self::ENTRY_MODULE);
        if (is_array($this->mod)) {
            $this->mod = array_merge($this->mod, $default_mod);
        } else {
            $this->mod = $default_mod;
        }

        if (!$this->mod ||
                !isset($this->mod['ps_customersignin']) ||
                !isset($this->mod['ps_shoppingcart'])) {
            $this->mod = $this->getDefaultConfData(self::ENTRY_MODULE);
        }

        $this->esiModConf = array('mods' => array(), 'purge_events' =>array());
        // mods - priv, ttl, tag
        foreach ($this->mod as $name => $conf) {
            if (empty($conf['tag'])) {
                $conf['tag'] = $name;
            }
            if ($conf['ttl'] === '') {
                $conf['ttl'] = ($conf['priv'] == 1) ?
                    $this->shop[self::CFG_PRIVATE_TTL] : $this->shop[self::CFG_PUBLIC_TTL];
            }
            $this->esiModConf['mods'][$name] = $conf;
            $tag = $conf['tag'];
            if ($conf['priv'] == 1) {
                $tag = '!' . $tag;
            }
            $events = preg_split("/[\s,]+/", $conf['events'], null, PREG_SPLIT_NO_EMPTY);
            foreach ($events as $event) {
                if (!isset($this->esiModConf['purge_events'][$event])) {
                    $this->esiModConf['purge_events'][$event] = array($tag);
                } else {
                    $this->esiModConf['purge_events'][$event][] = $tag;
                }
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
        $tags = array('priv' => array(), 'pub' => array());
        if (isset($this->esiModConf['purge_events'][$event])) {
            foreach ($this->esiModConf['purge_events'][$event] as $tag) {
                if ($tag{0} == '!') {
                    $tags['priv'][] = ltrim($tag, '!');
                } else {
                    $tags['pub'][] = $tag;
                }
            }
        }
        return $tags;
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
            'actionOutputHTMLBefore', // This hook is used to filter the whole HTML page before rendered (only front)
            'actionAjaxDieCategoryControllerdoProductSearchBefore',
            'displayHeader', // set vary
            'displayFooterAfter', // show debug info
            /** add cache tags * */
            'overrideLayoutTemplate',
            'DisplayOverrideTemplate',
            'filterCategoryContent',
            'filterProductContent',
            'filterCmsContent',
            'filterCmsCategoryContent',
            /** private purge * */
            'actionAjaxDieCartControllerdisplayAjaxUpdateBefore',
            'actionCustomerLogoutAfter',
            'actionAuthentication',
            /** public purge * */
            /*             * *  product ** */
            'actionProductAdd',
            'actionProductSave',
            'actionProductUpdate', //array('id_product' => (int)$this->id, 'product' => $this)
            'actionProductDelete',
            'actionObjectSpecificPriceCoreAddAfter',
            'actionObjectSpecificPriceCoreDeleteAfter',
            'actionWatermark',
            /*             * * category ** */
            'categoryUpdate', // array('category' => $category)
            'actionCategoryUpdate',
            'actionCategoryAdd', // here do not purge all, as user can manually do that
            'actionCategoryDelete',
            /*             * * cms ** */
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            'actionObjectCmsAddAfter',
            /*             * * supplier ** */
            'actionObjectSupplierUpdateAfter',
            'actionObjectSupplierDeleteAfter',
            'actionObjectSupplierAddAfter',
            /*             * * manufacturer ** */
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectManufacturerAddAfter',
            /*             * * stores ** */
            'actionObjectStoreUpdateAfter',
            /** lscache own hooks * */
            'litespeedCachePurge',
            'litespeedCacheNotCacheable',
        );

        return $hooks;
    }
}
