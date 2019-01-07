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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/Helper.php');
require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/EsiItem.php');
require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/DebugLog.php');
require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/Config.php');
require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/Cache.php');
require_once(_PS_MODULE_DIR_ . 'litespeedcache/classes/VaryCookie.php');

class LiteSpeedCache extends Module
{
    private $cache;
    private $config;
    private $esiInjection;
    private static $ccflag = 0; // cache control flag

    const MODULE_NAME = 'litespeedcache';
    //BITMASK for Cache Control Flag
    const CCBM_CACHEABLE = 1;
    const CCBM_PRIVATE = 2;
    const CCBM_CAN_INJECT_ESI = 4;
    const CCBM_ESI_ON = 8;
    const CCBM_ESI_REQ = 16;
    const CCBM_GUEST = 32;
    const CCBM_ERROR_CODE = 64; // response code is not 200
    const CCBM_NOT_CACHEABLE = 128; // for redirect, as first bit is not set, may mean don't know cacheable or not
    const CCBM_VARY_CHECKED = 256;
    const CCBM_VARY_CHANGED = 512;
    const CCBM_FRONT_CONTROLLER = 1024;
    const CCBM_MOD_ACTIVE = 2048; // module is enabled
    const CCBM_MOD_ALLOWIP = 4096; // allow cache for listed IP
    // ESI MARKER
    const ESI_MARKER_END = '_LSCESIEND_';

    public function __construct()
    {
        $this->name = 'litespeedcache'; // self::MODULE_NAME was rejected by validator
        $this->tab = 'administration';
        $this->author = 'LiteSpeedTech';
        $this->version = '1.2.5'; // validator does not allow const here
        $this->need_instance = 0;
        $this->module_key = '2a93f81de38cad872010f09589c279ba';

        $this->ps_versions_compliancy = array(
            'min' => '1.6', // support both 1.6 and 1.7
            'max' => _PS_VERSION_,
        );

        $this->controllers = array('esi');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('LiteSpeed Cache Plugin');
        $this->description = $this->l('Integrates with LiteSpeed Full Page Cache on LiteSpeed Server.');

        $this->config = LiteSpeedCacheConfig::getInstance();
        // instantiate cache even when module not enabled, because may still need purge cache.
        $this->cache = new LiteSpeedCacheCore($this->config);
        $this->esiInjection = array('tracker' => array(),
            'marker' => array());

        self::$ccflag |= $this->config->moduleEnabled();
        if (!defined('_LITESPEED_CACHE_')) {
            define('_LITESPEED_CACHE_', 1);
        }
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', 0);
        }
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

    public static function getCCFlag()
    {
        return self::$ccflag;
    }

    public function setEsiOn()
    {
        self::$ccflag |= self::CCBM_ESI_ON;
    }

    private static function myInstance()
    {
        return Module::getInstanceByName(self::MODULE_NAME);
    }

    public function hookActionDispatcher($params)
    {
        $controllerType = $params['controller_type'];
        $controllerClass = $params['controller_class'];

        if (_LITESPEED_DEBUG_ > 0) {
            $notprinted = array('AdminDashboardController', 'AdminGamificationController');
            if (in_array($controllerClass, $notprinted)) {
                LiteSpeedCacheLog::setDebugLevel(0); // disable logging for current request
            }
        }

        $status = $this->checkDispatcher($controllerType, $controllerClass);

        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_CACHE_ROUTE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . ' type=' . $controllerType . ' controller=' . $controllerClass
                . ' req=' . $_SERVER['REQUEST_URI'] . ' :' . $status, LiteSpeedCacheLog::LEVEL_CACHE_ROUTE);
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

    public function hookActionProductSearchAfter($params)
    {
        // Hook::exec('actionProductSearchAfter', $searchVariables);
        if (self::isCacheable() && isset($params['products'])) {
            foreach ($params['products'] as $p) {
                if (!empty($p['specific_prices'])) {
                    $this->cache->checkSpecificPrices($p['specific_prices']);
                }
            }
        }
    }

    public function hookFilterCategoryContent($params)
    {
        if (self::isCacheable()) {
            if (isset($params['object']['id'])) {
                $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_CATEGORY . $params['object']['id']);
            }
        }
    }

    public function hookFilterProductContent($params)
    {
        if (self::isCacheable()) {
            if (isset($params['object']['id'])) {
                $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_PRODUCT . $params['object']['id']);
            }
            if (!empty($params['object']['specific_prices'])) {
                $this->cache->checkSpecificPrices($params['object']['specific_prices']);
            }
        }
    }

    public function hookFilterCmsCategoryContent($params)
    {
        if (self::isCacheable()) {
            // any cms page update, will purge all cmscategory pages, as the assignment may change,
            // so we do not distinguish by cms category id
            $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_CMS);
        }
    }

    public function hookFilterCmsContent($params)
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_CMS . $params['object']['id']);
        }
    }

    /* this is catchall function for purge events */
    public function __call($method, $args)
    {
        if (self::isActive()) {
            $keys = array_keys($args);
            if (count($keys) == 1 && $keys[0] == 0) {
                $args = $args[0];
            }
            $this->cache->purgeByCatchAllMethod($method, $args);
        }
    }
    /* our own hook
     * Required field $params['from']
     * $params['public'] & $params['private'] one has to exist, array of tags
     * $params['ALL'] - entire cache storage
     */
    public function hookLitespeedCachePurge($params)
    {
        $msg = __FUNCTION__ . ' ';
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

        if ($err && _LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_PURGE_EVENT) {
            LiteSpeedCacheLog::log($err, LiteSpeedCacheLog::LEVEL_PURGE_EVENT);
        }
    }

    // allow other modules to set
    public function hookLitespeedNotCacheable($params)
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
        if (self::isCacheable() && _LITESPEED_DEBUG_) {
            $comment = '<!-- LiteSpeed Cache snapshot generated at ' . gmdate("Y/m/d H:i:s") . ' GMT -->';
            if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_FOOTER_COMMENT) {
                LiteSpeedCacheLog::log(
                    'Add html comments in footer ' . $comment,
                    LiteSpeedCacheLog::LEVEL_FOOTER_COMMENT
                );
            }
            return $comment;
        }
    }

    // called by Media override addJsDef
    public static function filterJsDef(&$jsDef)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return;
        }
        $injected = LscIntegration::filterJSDef($jsDef);
        if (!empty($injected)) {
            $lsc = self::myInstance();
            foreach ($injected as $id => $item) {
                if (!isset($lsc->esiInjection['marker'][$id])) {
                    $lsc->esiInjection['marker'][$id] = $item;
                }
            }
        }
    }

    /* return status */
    private function checkDispatcher($controllerType, $controllerClass)
    {
        if (!self::isActiveForUser()) { // check for ip restriction
            return 'not active';
        }

        if (!defined('_LITESPEED_CALLBACK_')) {
            define('_LITESPEED_CALLBACK_', 1);
            ob_start('LiteSpeedCache::callbackOutputFilter');
        }

        // 3rd party integration init needs to be before checkRoute
        include_once(_PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php');

        if ($controllerType == DispatcherCore::FC_FRONT) {
            self::$ccflag |= self::CCBM_FRONT_CONTROLLER;
        }
        if ($controllerClass == 'litespeedcacheesiModuleFrontController') {
            self::$ccflag |= self::CCBM_ESI_REQ;
            return 'esi request';
        }

        // here also check purge controller
        if (($reason = $this->cache->isCacheableRoute($controllerType, $controllerClass)) != '') {
            $this->setNotCacheable($reason);
            return $reason;
        }

        if (isset($_SERVER['LSCACHE_VARY_VALUE'])
            && ($_SERVER['LSCACHE_VARY_VALUE'] == 'guest' || $_SERVER['LSCACHE_VARY_VALUE'] == 'guestm')) {
            self::$ccflag |= self::CCBM_CACHEABLE | self::CCBM_GUEST; // no ESI allowed
            return 'cacheable guest';
        }

        self::$ccflag |= (self::CCBM_CACHEABLE | self::CCBM_CAN_INJECT_ESI);
        return 'cacheable & allow esiInject';
    }


    public function addCacheControlByEsiModule($item)
    {
        if (!self::isActive()) {
            $this->cache->purgeByTags('*', false, 'request esi while module is not active');
            return;
        }
        $moduleConf = $item->getConf();
        $ttl = $moduleConf->getTTL();
        if ($moduleConf->onlyCacheEmtpy() && $item->getContent() !== '') {
            $ttl = 0;
        }
        if ($ttl === 0 || $ttl === '0') {
            self::$ccflag |= self::CCBM_NOT_CACHEABLE;
        } else {
            $this->cache->addCacheTags($moduleConf->getTag());
            self::$ccflag |= self::CCBM_CACHEABLE;
            if ($moduleConf->isPrivate()) {
                self::$ccflag |= self::CCBM_PRIVATE;
            }
            $this->cache->setEsiTtl($ttl);
        }
    }

    // return changed
    public static function setVaryCookie()
    {
        if ((self::$ccflag & self::CCBM_VARY_CHECKED) == 0) {
            if (LiteSpeedCacheVaryCookie::setVary()) {
                self::$ccflag |= self::CCBM_VARY_CHANGED;
            }
            self::$ccflag |= self::CCBM_VARY_CHECKED;
        }
        return ((self::$ccflag & self::CCBM_VARY_CHANGED) != 0);
    }

    public static function callbackOutputFilter($buffer)
    {
        $lsc = self::myInstance();
        if ((self::$ccflag & self::CCBM_FRONT_CONTROLLER) > 0 && self::setVaryCookie() && self::isCacheable()) {
            //condition order is fixed
            $lsc->setNotCacheable('Env change');
        }

        $code = http_response_code();
        if ($code == 404) {
            self::$ccflag |= self::CCBM_ERROR_CODE;
            if (LiteSpeedCacheHelper::isStaticResource($_SERVER['REQUEST_URI'])) {
                $buffer = '<!-- 404 not found -->';
                self::$ccflag &= ~self::CCBM_CAN_INJECT_ESI;
            }
        } elseif ($code != 200) {
            self::$ccflag |= self::CCBM_ERROR_CODE;
            $lsc->setNotCacheable('Response code is ' . $code);
        }

        if (self::canInjectEsi()
            && (count($lsc->esiInjection['marker']) || self::isCacheable())) {
            // if no injection, but cacheable, still need to check token
            $buffer = $lsc->replaceEsiMarker($buffer);
        }

        $lsc->cache->setCacheControlHeader();
        /** for testing
        //  $tname = tempnam('/tmp/t','A');
        //  file_put_contents($tname, $buffer);
         */
        return $buffer;
    }

    private function registerEsiMarker($params, $conf)
    {
        $item = new LiteSpeedCacheEsiItem($params, $conf);
        $id = $item->getId();
        if (!isset($this->esiInjection['marker'][$id])) {
            $this->esiInjection['marker'][$id] = $item;
        }
        return '_LSCESI-' . $id . '-START_';
    }

    private function replaceEsiMarker($buf)
    {
        if (count($this->esiInjection['marker'])) {
            // U :ungreedy s: dotall m: multiline
            $nb = preg_replace_callback(
                array('/_LSC(ESI)-(.+)-START_(.*)_LSCESIEND_/Usm',
                '/(\'|\")_LSCESIJS-(.+)-START__LSCESIEND_(\'|\")/Usm'),
                function ($m) {
                    // inject ESI even it's not cacheable
                    $id = $m[2];
                    $lsc = self::myInstance();
                    if (!isset($lsc->esiInjection['marker'][$id])) {
                        $id = stripslashes($id);
                    }
                    if (!isset($lsc->esiInjection['marker'][$id])) {
                        // should not happen
                        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_UNEXPECTED) {
                            LiteSpeedCacheLog::log('Lost Injection ' . $id, LiteSpeedCacheLog::LEVEL_UNEXPECTED);
                        }
                        return '';
                    }
                    $item = $lsc->esiInjection['marker'][$id];
                    $esiInclude = $item->getInclude();
                    if ($esiInclude === false) {
                        if ($item->getParam('pt') == $item::ESI_JSDEF) {
                            LscIntegration::processJsDef($item); // content set inside
                        } else {
                            $item->setContent($m[3]);
                        }
                        $esiInclude = $item->getInclude();
                    }
                    return $esiInclude;
                },
                $buf,
                -1,
                $count
            );
        } else {
            // log here, shouldn't happen
            $nb = $buf;
        }

        $bufInline = '';
        // Tools::getToken() is not really used for cacheable pages
        // Tools::getToken(false) is used -------------- caninject
        $static_token = Tools::getToken(false);
        $tkparam = array('pt' => LiteSpeedCacheEsiItem::ESI_TOKEN, 'm' => LscToken::NAME, 'd' => 'static');
        $tkitem = new LiteSpeedCacheEsiItem($tkparam, $this->config->getEsiModuleConf(LscToken::NAME));
        $tkitem->setContent($static_token);
        // we always add to inline
        $this->esiInjection['marker'][$tkitem->getId()] = $tkitem;

        $envparam = array('pt' => LiteSpeedCacheEsiItem::ESI_ENV, 'm' => LscEnv::NAME);
        $envitem = new LiteSpeedCacheEsiItem($envparam, $this->config->getEsiModuleConf(LscEnv::NAME));
        $envitem->setContent('');
        $this->esiInjection['marker'][$envitem->getId()] = $envitem;

        if (self::isCacheable()) { // only if cacheable, do global replacement
            if (strpos($nb, $static_token)) {
                $tokenInc = $tkitem->getInclude();
                $nb = str_replace($static_token, $tokenInc, $nb);
            }
            $nb = $envitem->getInclude() . $nb; // must be first one
        }

        $allPrivateItems = array();
        // last adding esi:inline, which needs to be in front of esi:include
        foreach ($this->esiInjection['marker'] as $item) {
            $inline = $item->getInline();
            if ($inline !== false) {
                // for ajax call, it's possible no inline content
                $bufInline .= $inline;
                if ($item->getConf()->isPrivate()) {
                    $allPrivateItems[] = $item;
                }
            }
        }
        if ($bufInline) {
            if (!empty($allPrivateItems)) {
                LiteSpeedCacheHelper::syncItemCache($allPrivateItems);
            }
            self::$ccflag |= self::CCBM_ESI_ON;
        }
        if ($bufInline && _LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_OUTPUT) {
            LiteSpeedCacheLog::log('ESI inline output ' . $bufInline, LiteSpeedCacheLog::LEVEL_ESI_OUTPUT);
        }
        return $bufInline . $nb;
    }

    public function hookLitespeedEsiBegin($params)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return '';
        }
        $err = 0;
        $err_field = '';
        $m = $f = 'NA';
        $pt = LiteSpeedCacheEsiItem::ESI_SMARTYFIELD;

        if (isset($params['m'])) {
            $m = $params['m'];
        } else {
            $err |= 1;
            $err_field .= 'm ';
        }
        if (isset($params['field'])) {
            $f = $params['field'];
        } else {
            $err |= 1;
            $err_field .= 'field ';
        }
        if (count($this->esiInjection['tracker']) > 0) {
            $err |= 2;
        }

        $esiParam = array('pt' => $pt, 'm' => $m, 'f' => $f);
        if ($f == 'widget' && isset($params['hook'])) {
            $esiParam['h'] = $params['hook'];
        } elseif ($f == 'widget_block') {
            if (isset($params['tpl'])) {
                $esiParam['t'] = $params['tpl'];
            } else {
                $err |= 1;
                $err_field .= 'tpl ';
            }
        }

        $conf = $this->config->canInjectEsi($m, $esiParam);
        if ($conf == false) {
            $err |= 4;
        }

        array_push($this->esiInjection['tracker'], $err);
        // check here for template name
        if ($err) {
            if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_CUST_SMARTY) {
                $msg = '';
                if ($err & 1) {
                    $msg .= 'Missing hookLitespeedEsiBegin param (' .
                        $err_field . '). ';
                }
                if ($err & 2) {
                    $msg .= 'Ignore due to nested hookLitespeedEsiBegin. ';
                }
                if ($err & 4) {
                    $msg .= 'Cannot inject ESI for ' . $m;
                }
                LiteSpeedCacheLog::log(__FUNCTION__ . ' ' . $msg, LiteSpeedCacheLog::LEVEL_CUST_SMARTY);
            }
            return '';
        }
        return $this->registerEsiMarker($esiParam, $conf);
    }

    public function hookLitespeedEsiEnd($params)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return '';
        }
        $res = array_pop($this->esiInjection['tracker']);
        if ($res === 0) { // begin has no error, output end marker
            // here simply output marker
            return self::ESI_MARKER_END;
        }
        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_CUST_SMARTY) {
            // check here for template name
            $err = ($res === null) ? ' Mismatched hookLitespeedEsiEnd detected' :
                ' Ignored hookLitespeedEsiEnd due to error  in hookLitespeedEsiBegin';
            LiteSpeedCacheLog::log(__FUNCTION__ . $err, LiteSpeedCacheLog::LEVEL_CUST_SMARTY);
        }
        return '';
    }

    // used by override hook, return false or marker
    public static function injectRenderWidget($module, $hook_name)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return false;
        }

        $lsc = self::myInstance();
        $m = $module->name;
        $pt = LiteSpeedCacheEsiItem::ESI_RENDERWIDGET;

        $esiParam = array('pt' => $pt, 'm' => $m, 'h' => $hook_name);
        $conf = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf == false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . " $m : $hook_name", LiteSpeedCacheLog::LEVEL_ESI_INCLUDE);
        }
        return $lsc->registerEsiMarker($esiParam, $conf);
    }

    // used by override hook
    public static function injectCallHook($module, $method)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return false;
        }

        $lsc = self::myInstance();
        $m = $module->name;
        $pt = LiteSpeedCacheEsiItem::ESI_CALLHOOK;

        $esiParam = array('pt' => $pt, 'm' => $m, 'mt' => $method);
        $conf = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf == false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . " $m : $method", LiteSpeedCacheLog::LEVEL_ESI_INCLUDE);
        }
        return $lsc->registerEsiMarker($esiParam, $conf);
    }

    // allow other plugins to set current response not cacheable
    private function setNotCacheable($reason = '')
    {
        if (!self::isActive()) { // not check ip for force nocache
            return;
        }
        self::$ccflag |= self::CCBM_NOT_CACHEABLE;
        if ($reason && _LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_NOCACHE_REASON) {
            LiteSpeedCacheLog::log(__FUNCTION__ . ' - ' . $reason, LiteSpeedCacheLog::LEVEL_NOCACHE_REASON);
        }
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminLiteSpeedCacheConfig'));
    }

    public function install()
    {
        $this->installTab();
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (parent::install()) {
            $all = json_encode($this->config->getDefaultConfData(LiteSpeedCacheConfig::ENTRY_ALL));
            $shop = json_encode($this->config->getDefaultConfData(LiteSpeedCacheConfig::ENTRY_SHOP));
            $mod = json_encode($this->config->getDefaultConfData(LiteSpeedCacheConfig::ENTRY_MODULE));
            Configuration::updateValue(LiteSpeedCacheConfig::ENTRY_ALL, $all);
            Configuration::updateValue(LiteSpeedCacheConfig::ENTRY_SHOP, $shop);
            Configuration::updateValue(LiteSpeedCacheConfig::ENTRY_MODULE, $mod);
            LiteSpeedCacheHelper::htAccessBackup('b4lsc');
            return $this->installHooks();
        } else {
            return false;
        }
    }

    public function uninstall()
    {
        $this->uninstallTab();
        LiteSpeedCacheHelper::htAccessUpdate(0, 0, 0);
        $this->cache->purgeByTags('*', false, 'from uninstall');
        Configuration::deleteByName(LiteSpeedCacheConfig::ENTRY_ALL);
        Configuration::deleteByName(LiteSpeedCacheConfig::ENTRY_SHOP);
        Configuration::deleteByName(LiteSpeedCacheConfig::ENTRY_MODULE);

        return parent::uninstall();
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

    private function uninstallTab()
    {
        $definedtabs = $this->initTabs();
        if (version_compare(_PS_VERSION_, '1.7.1.0', '>=')) {
            $this->tabs = $definedtabs;
            return null;
        }
        foreach ($definedtabs as $t) {
            if ($id_tab = (int) Tab::getIdFromClassName($t['class_name'])) {
                $tab = new Tab($id_tab);
                $tab->delete();
            }
        }
        return $definedtabs;
    }

    private function installTab()
    {
        $definedtabs = $this->uninstallTab();
        if ($definedtabs == null) {
            return;
        }
        foreach ($definedtabs as $t) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $t['class_name'];
            $tab->name = array();
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = $t['name'];
            }

            $tab->id_parent = is_int($t['ParentClassName']) ?
                $t['ParentClassName'] : (int) Tab::getIdFromClassName($t['ParentClassName']);
            $tab->module = $this->name;
            $tab->add();
        }
    }

    private function initTabs()
    {
        $root_node = version_compare(_PS_VERSION_, '1.7.1.0', '>=') ? 'AdminAdvancedParameters' : 0;
        $definedtabs = array(
            array(
                'class_name' => 'AdminLiteSpeedCache',
                'name' => $this->l('LiteSpeed Cache'), // this will use the default admin lang
                'visible' => 1,
                'icon' => 'flash_on',
                'ParentClassName' => $root_node,
            ),
            array(
                'class_name' => 'AdminLiteSpeedCacheManage',
                'name' => $this->l('Manage'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ),
            array(
                'class_name' => 'AdminLiteSpeedCacheConfig',
                'name' => $this->l('Configuration'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ),
            array(
                'class_name' => 'AdminLiteSpeedCacheCustomize',
                'name' => $this->l('Customization'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ),
        );
        return $definedtabs;
    }
}
