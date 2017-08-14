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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Config.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Cache.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/DebugLog.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/VaryCookie.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Helper.php';

use LiteSpeedCacheDebugLog as DebugLog;
use LiteSpeedCacheConfig as Conf;

class LiteSpeedCache extends Module
{
    private $cache;
    private $config;
    private $isDebug;
    private static $ccflag = 0; // cache control flag

    const MODULE_NAME = 'litespeedcache';
    //BITMASK for Cache Control Flag
    const CCBM_CACHEABLE = 1;
    const CCBM_PRIVATE = 2;
    const CCBM_CAN_INJECT_ESI = 4;
    const CCBM_ESI_ON = 8;
    const CCBM_ESI_REQ = 16;
    const CCBM_NOT_CACHEABLE = 128; // for redirect, as first bit is not set, may mean don't know cacheable or not
    const CCBM_MOD_ACTIVE = 256; // module is enabled
    const CCBM_MOD_ALLOWIP = 512; // allow cache for listed IP

    public function __construct()
    {
        $this->name = 'litespeedcache'; // self::MODULE_NAME was rejected by validator
        $this->tab = 'administration';
        $this->author = 'LiteSpeedTech';
        $this->version = '1.0.0'; // validator does not allow const here
        $this->need_instance = 0;

        $this->ps_versions_compliancy = array(
            'min' => '1.7.1.0',
            'max' => _PS_VERSION_,
        );

        $this->controllers = array('esi');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('LiteSpeed Cache Plugin');
        $this->description = $this->l('Integrates with LiteSpeed Full Page Cache on LiteSpeed Server.');

        $this->config = Conf::getInstance();
        // instantiate cache even when module not enabled, because may still need purge cache.
        $this->cache = new LiteSpeedCacheCore($this->config);

        self::$ccflag |= $this->config->moduleEnabled();
        $this->isDebug = $this->config->isDebug();
        if (!defined('_LITESPEED_CACHE_DEBUG_')) {
            define('_LITESPEED_CACHE_DEBUG_', $this->isDebug);
        }
        if (!defined('_LITESPEED_CACHE_')) {
            define('_LITESPEED_CACHE_', 1);
        }
    }

    private function installHooks()
    {
        $hooks = $this->config->getReservedHooks();
        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    public static function isActive()
    {
        return ((self::$ccflag & (self::CCBM_MOD_ACTIVE | self::CCBM_MOD_ALLOWIP)) != 0);
    }

    public static function isActiveForUser()
    {
        return ((self::$ccflag & self::CCBM_MOD_ACTIVE) != 0);
    }

    public static function isRestrictedIP()
    {
        return ((self::$ccflag & self::CCBM_MOD_ALLOWIP) != 0);
    }

    public static function isCacheable()
    {
        return (((self::$ccflag & self::CCBM_NOT_CACHEABLE) == 0) && ((self::$ccflag & self::CCBM_CACHEABLE) != 0));
    }

    public static function canInjectEsi()
    {
        return ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) != 0);
    }

    public static function setEsiReq()
    {
        if (self::isActive()) { // not check ip for esi
            self::$ccflag |= self::CCBM_ESI_REQ;
            self::$ccflag &= ~self::CCBM_NOT_CACHEABLE;
        }
    }

    public static function getCCFlag()
    {
        return self::$ccflag;
    }

    // allow other plugins to set current response not cacheable
    private function setNotCacheable($reason = '')
    {
        if (self::isActive()) { // not check ip for force nocache
            self::$ccflag |= self::CCBM_NOT_CACHEABLE;
            if ($reason && $this->isDebug >= DebugLog::LEVEL_NOCACHE_REASON) {
                DebugLog::log('SetNotCacheable - ' . $reason, DebugLog::LEVEL_NOCACHE_REASON);
            }
        }
    }

    // used by override hook
    public static function overrideRenderWidget($module, $hook_name)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return false;
        }
        $lsc = Module::getInstanceByName(self::MODULE_NAME);
        if ($lsc->config->isEsiModule($module->name)) {
            $esiInclude = $lsc->cache->getEsiInclude($module, $hook_name);
            self::$ccflag |= self::CCBM_ESI_ON;
            if ($lsc->isDebug >= DebugLog::LEVEL_ESI_INCLUDE) {
                DebugLog::log($esiInclude, DebugLog::LEVEL_ESI_INCLUDE);
            }
            return $esiInclude;
        }
        return false;
    }

    public function addCacheControlByEsiModule($moduleName)
    {
        if (self::isActive()
            && (($conf = $this->config->getEsiModuleConf($moduleName)) != null)) {
            $this->cache->addCacheTags($conf['tag']);
            if ($conf['ttl'] > 0) {
                $this->cache->setEsiTtl($conf['ttl']);
                self::$ccflag |= self::CCBM_CACHEABLE;
                if ($conf['priv'] == 1) {
                    self::$ccflag |= self::CCBM_PRIVATE;
                }
            } else {
                self::$ccflag |= self::CCBM_NOT_CACHEABLE;
            }
        }
    }

    public function addPurgeTags($tag, $isPrivate = false)
    {
        if (self::isActive()) {
            $this->cache->addPurgeTags($tag, $isPrivate);
        }
    }

    public function hookActionCustomerLogoutAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByTags('*', true, 'actionCustomerLogoutAfter');
        }
    }

    public function hookActionAuthentication($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByTags('*', true, 'actionAuthentication');
        }
    }

    /* this is catchall function for purge events */
    public function __call($method, $args)
    {
        if (!self::isActive()) {
            return;
        }
        if (strpos($method, 'hook') === 0) {
            $event = Tools::substr($method, 4);
            $res = $this->cache->purgeByEvent($event);
        }
        if ($this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log('CATCH_ALL ' . $method . ' argc=' . count($args)
                . ' purgeByEvent=' . $res, DebugLog::LEVEL_PURGE_EVENT);
        }
    }

    public function hookActionOutputHTMLBefore($params)
    {
        if (self::isActive()) {
            $this->cache->setCacheControlHeader('actionOutputHTMLBefore');
        }
    }

    public function hookActionAjaxDieCategoryControllerdoProductSearchBefore($params)
    {
        if (self::isActive()) {
            $this->cache->setCacheControlHeader('actionAjaxDieCategoryControllerdoProductSearchBefore');
        }
    }

    public function hookActionDispatcher($params)
    {
        if (!self::isActiveForUser()) { // check for ip restriction
            return;
        }
        $reason = $this->cache->isCacheableRoute($params['controller_type'], $params['controller_class']);
        if ($reason) {
            $this->setNotCacheable($reason);
        } else {
            self::$ccflag |= (self::CCBM_CACHEABLE | self::CCBM_CAN_INJECT_ESI);
        }
    }

    public function hookDisplayHeader($params)
    {
        if (self::isActiveForUser() // check for ip restriction
            && LiteSpeedCacheVaryCookie::setCookieVary()) {
            $this->setNotCacheable('Env cookie change');
            $this->cache->purgeByTags('*', true, 'Env cookie change');
        }
    }

    public function hookOverrideLayoutTemplate($params)
    {
        if (self::isCacheable()) {
            if ($this->cache->hasNotification()) {
                $this->setNotCacheable('Has private notification');
            } elseif ((self::$ccflag & self::CCBM_ESI_REQ) == 0) {
                $this->cache->initCacheTagsByController($params);
            }
        }
    }

    public function hookDisplayOverrideTemplate($params)
    {
        //if (self::isCacheable() && isset($params['entity']) && isset($params['id'])) {
        if (!self::isCacheable()) {
            return;
        }
        $this->cache->initCacheTagsByController($params);
    }

    public function hookFilterCategoryContent($params)
    {
        if (self::isCacheable()) {
            if (isset($params['object']['id'])) {
                $this->cache->addCacheTags(Conf::TAG_PREFIX_CATEGORY . $params['object']['id']);
            }
        }
    }

    public function hookFilterProductContent($params)
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(Conf::TAG_PREFIX_PRODUCT . $params['object']['id']);
        }
    }

    public function hookFilterCmsCategoryContent($params)
    {
        if (self::isCacheable()) {
            // any cms page update, will purge all cmscategory pages, as the assignment may change,
            // so we do not distinguish by cms category id
            $this->cache->addCacheTags(Conf::TAG_PREFIX_CMS);
        }
    }

    public function hookFilterCmsContent($params)
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(Conf::TAG_PREFIX_CMS . $params['object']['id']);
        }
    }

    public function hookActionProductAdd($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['id_product'], $params['product'], false, 'actionProductAdd');
        }
    }

    public function hookActionProductSave($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['id_product'], $params['product'], true, 'actionProductSave');
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['id_product'], $params['product'], true, 'actionProductUpdate');
        }
    }

    public function hookActionProductDelete($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['id_product'], $params['product'], false, 'actionProductDelete');
        }
    }

    public function hookActionObjectSpecificPriceCoreAddAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['object']->id_product, null, true, 'SpecificPriceCoreAddAfter');
        }
    }

    public function hookActionObjectSpecificPriceCoreDeleteAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['object']->id_product, null, true, 'SpecificPriceCoreDeleteAfter');
        }
    }

    public function hookActionWatermark($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByProduct($params['id_product'], null, true, 'actionWatermark');
        }
    }

    public function hookActionCategoryUpdate($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCategory($params['category'], 'actionCategoryUpdate');
        }
    }

    public function hookCategoryUpdate($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCategory($params['category'], 'hookCategoryUpdate');
        }
    }

    public function hookActionCategoryAdd($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCategory($params['category'], 'actionCategoryAdd');
        }
    }

    public function hookActionCategoryDelete($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCategory($params['category'], 'actionCategoryDelete');
        }
    }

    public function hookActionObjectCmsUpdateAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCms($params['object'], 'actionObjectCmsUpdateAfter');
        }
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCms($params['object'], 'actionObjectCmsDeleteAfter');
        }
    }

    public function hookActionObjectCmsAddAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByCms($params['object'], 'actionObjectCmsAddAfter');
        }
    }

    public function hookActionObjectSupplierUpdateAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeBySupplier($params['object'], 'actionObjectSupplierUpdateAfter');
        }
    }

    public function hookActionObjectSupplierDeleteAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeBySupplier($params['object'], 'actionObjectSupplierDeleteAfter');
        }
    }

    public function hookActionObjectSupplierAddAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeBySupplier($params['object'], 'actionObjectSupplierAddAfter');
        }
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByManufacturer($params['object'], 'actionObjectManufacturerUpdateAfter');
        }
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByManufacturer($params['object'], 'actionObjectManufacturerDeleteAfter');
        }
    }

    public function hookActionObjectManufacturerAddAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByManufacturer($params['object'], 'actionObjectManufacturerAddAfter');
        }
    }

    public function hookActionObjectStoreUpdateAfter($params)
    {
        if (self::isActive()) {
            $this->cache->purgeByTags(Conf::TAG_STORES, false, 'actionObjectStoreUpdateAfter');
        }
    }
    /* our own hook
     * Required field $params['from']
     * $params['public'] & $params['private'] one has to exist, array of tags
     * $params['ALL'] - entire cache storage
     */

    public function hookLitespeedCachePurge($params)
    {
        $msg = 'hookLitespeedCachePurge: ';
        $err = '';

        if (!isset($params['from'])) {
            $err = $msg . 'Illegal entrance - missing from';
        } else {
            $msg .= 'from ' . $params['from'];
            if (self::isActive()) {
                if (isset($params['public'])) {
                    $this->cache->purgeByTags($params['public'], false, $msg);
                } elseif (isset($params['private'])) {
                    $this->cache->purgeByTags($params['private'], true, $msg);
                } elseif (isset($params['ALL'])) {
                    $this->cache->purgeEntireStorage($msg);
                } else {
                    $err = $msg . 'Illegal - missing public or private';
                }
            } else {
                // only allow purge all PS data if not active
                if (isset($params['public']) && $params['public'] == '*') {
                    $this->cache->purgeByTags('*', false, $msg);
                } else {
                    $err = $msg . 'Illegal tags - module not activated, can only take *';
                }
            }
        }

        if ($err && $this->isDebug >= DebugLog::LEVEL_PURGE_EVENT) {
            DebugLog::log($err, DebugLog::LEVEL_PURGE_EVENT);
        }
    }

    // allow other modules to set
    public function hookLitespeedCacheNotCacheable($params)
    {
        $reason = '';
        if (isset($params['reason'])) {
            $reason = $params['reason'];
        }
        if (isset($params['from'])) {
            $reason .= ' from ' . $params['from'];
        }
        $this->setNotCacheable($reason);
    }

    // if debug enabled, show generation timestamp in comments
    public function hookDisplayFooterAfter($params)
    {
        if (self::isCacheable() && $this->isDebug) {
            $comment = '<!-- LiteSpeed Cache snapshot generated at ' . gmdate("Y/m/d H:i:s") . ' GMT -->';
            DebugLog::log('Add html comments in footer ' . $comment, DebugLog::LEVEL_FOOTER_COMMENT);
            return $comment;
        }
    }

    public function install()
    {
        $this->initTabs();
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (parent::install()) {
            $all = json_encode($this->config->getDefaultConfData(Conf::ENTRY_ALL));
            $shop = json_encode($this->config->getDefaultConfData(Conf::ENTRY_SHOP));
            $mod = json_encode($this->config->getDefaultConfData(Conf::ENTRY_MODULE));
            Configuration::updateValue(Conf::ENTRY_ALL, $all);
            Configuration::updateValue(Conf::ENTRY_SHOP, $shop);
            Configuration::updateValue(Conf::ENTRY_MODULE, $mod);
            return $this->installHooks();
        } else {
            return false;
        }
    }

    public function uninstall()
    {
        $this->initTabs();
        $this->cache->purgeByTags('*', false, 'from uninstall');
        Configuration::deleteByName(Conf::ENTRY_ALL);
        Configuration::deleteByName(Conf::ENTRY_SHOP);
        Configuration::deleteByName(Conf::ENTRY_MODULE);

        return parent::uninstall();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminLiteSpeedCacheConfig'));
    }

    private function initTabs()
    {
        $this->tabs = array();
        $this->tabs[] = array(
            'class_name' => 'AdminLiteSpeedCache',
            'name' => 'LiteSpeed Cache',
            'visible' => true,
            'icon' => 'flash_on',
            'ParentClassName' => 'DEFAULT',
        );
        $this->tabs[] = array(
            'class_name' => 'AdminLiteSpeedCacheConfigParent',
            'name' => 'Settings',
            'visible' => true,
            'ParentClassName' => 'AdminLiteSpeedCache',
        );
        $this->tabs[] = array(
            'class_name' => 'AdminLiteSpeedCacheConfig',
            'name' => 'Configuration',
            'visible' => true,
            'ParentClassName' => 'AdminLiteSpeedCacheConfigParent',
        );
        $this->tabs[] = array(
            'class_name' => 'AdminLiteSpeedCacheCustomize',
            'name' => 'Customization',
            'visible' => true,
            'ParentClassName' => 'AdminLiteSpeedCacheConfigParent',
        );
        $this->tabs[] = array(
            'class_name' => 'AdminLiteSpeedCacheManage',
            'name' => 'Manage',
            'visible' => true,
            'ParentClassName' => 'AdminLiteSpeedCache',
        );
    }
}
