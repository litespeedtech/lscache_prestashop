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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Helper.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/EsiItem.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/DebugLog.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Config.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/Cache.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/VaryCookie.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/DynamicFragment.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/DynamicFragmentParser.php';

class LiteSpeedCache extends Module
{

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

    private $cache;

    private $config;

    private $esiInjection;

    /**
     * A notification makes the current response potentially private.
     *
     * Do not immediately disable the full-page cache: a compatible theme may
     * expose the whole notifications block through data-ps-fragment and let us
     * isolate it as ESI. The legacy no-cache behavior is applied later, in the
     * output filter, only if that fragment cannot actually be replaced.
     */
    private $hasPrivateNotification = false;

    private static $ccflag = 0; // cache control flag

    private static $no_cache_reason = '';


    public function __construct()
    {
        $this->name = 'litespeedcache'; // self::MODULE_NAME was rejected by validator
        $this->tab = 'administration';
        $this->author = 'LiteSpeedTech';
        $this->version = self::getVersion();
        $this->need_instance = 0;
        $this->module_key = '2a93f81de38cad872010f09589c279ba';

        $this->ps_versions_compliancy = [
            'min' => '1.6', // support both 1.6 and 1.7
            'max' => _PS_VERSION_,
        ];

        $this->controllers = ['esi'];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('LiteSpeed Cache Plugin');
        $this->description = $this->l('Integrates with LiteSpeed Full Page Cache on LiteSpeed Server.');

        $this->config = LiteSpeedCacheConfig::getInstance();
        // instantiate cache even when module not enabled, because may still need purge cache.
        $this->cache = new LiteSpeedCacheCore($this->config);
        $this->esiInjection = ['tracker' => [],
            'marker' => [],
        ];

        self::$ccflag |= $this->config->moduleEnabled();
        if (!defined('_LITESPEED_CACHE_')) {
            define('_LITESPEED_CACHE_', 1);
        }
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', 0);
        }
        if (self::isActiveForUser()) {
            require_once _PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php';

            Hook::exec('actionLiteSpeedCacheInitThirdParty');
        }
    }
    
    public static function getVersion()
    {
        return '1.6.1';
    }

    public static function isActive()
    {
        return (self::$ccflag & (self::CCBM_MOD_ACTIVE | self::CCBM_MOD_ALLOWIP)) != 0;
    }

    public static function isActiveForUser()
    {
        return (self::$ccflag & self::CCBM_MOD_ACTIVE) != 0;
    }

    public static function isRestrictedIP()
    {
        return (self::$ccflag & self::CCBM_MOD_ALLOWIP) != 0;
    }

    public static function isCacheable()
    {
        return ((self::$ccflag & self::CCBM_NOT_CACHEABLE) == 0) && ((self::$ccflag & self::CCBM_CACHEABLE) != 0);
    }

    public static function isEsiRequest()
    {
        return (self::$ccflag & self::CCBM_ESI_REQ) != 0;
    }

    public static function canInjectEsi()
    {
        return (self::$ccflag & self::CCBM_CAN_INJECT_ESI) != 0;
    }

    public static function isFrontController()
    {
        return (self::$ccflag & self::CCBM_FRONT_CONTROLLER) != 0;
    }

    public static function getCCFlag()
    {
        return self::$ccflag;
    }

    public static function getCCFlagDebugInfo()
    {
        $flag = self::$ccflag;
        $info = [];
        if ($flag & self::CCBM_MOD_ALLOWIP) {
            $info[] = 'Allowed IP';
        }
        if ($flag & self::CCBM_FRONT_CONTROLLER) {
            $info[] = 'FrontController';
        }
        if ($flag & self::CCBM_GUEST) {
            $info[] = 'Guest';
        }
        if ($flag & self::CCBM_CACHEABLE) {
            $info[] = 'Cacheable';
        }
        if (self::$no_cache_reason) {
            $info[] = 'NO CACHE reason: ' . self::$no_cache_reason;
        }

        return implode('; ', $info);
    }

    public function setEsiOn()
    {
        self::$ccflag |= self::CCBM_ESI_ON;
    }

    /**
     * Check whether the current LiteSpeed server supports Combined ESI.
     *
     * LiteSpeed exposes the available cache capabilities through X-LSCACHE.
     * Cache the result for the lifetime of the current PHP request so the
     * capability string is inspected only once.
     */
    private function isCombinedEsiSupported()
    {
        static $supported = null;

        if ($supported === null) {
            $supported = isset($_SERVER['X-LSCACHE'])
                && stripos($_SERVER['X-LSCACHE'], 'combine') !== false;
        }

        return $supported;
    }

    private static function myInstance()
    {
        return Module::getInstanceByName(self::MODULE_NAME);
    }

    public function hookActionDispatcher($params)
    {
        if (!self::isActiveForUser()) {
            return;
        }
        $controllerType = $params['controller_type'];
        $controllerClass = $params['controller_class'];

        if (_LITESPEED_DEBUG_ > 0) {
            $notprinted = ['AdminDashboardController', 'AdminGamificationController', 'AdminAjaxFaviconBOController'];
            if (in_array($controllerClass, $notprinted)) {
                LiteSpeedCacheLog::setDebugLevel(0); // disable logging for current request
            }
        }

        $status = $this->checkDispatcher($controllerType, $controllerClass);
        
        LscIntegration::preDispatchAction();

        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_CACHE_ROUTE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . ' type=' . $controllerType . ' controller=' . $controllerClass
                . ' req=' . $_SERVER['REQUEST_URI'] . ' :' . $status, LiteSpeedCacheLog::LEVEL_CACHE_ROUTE);
        }

    }

    public function hookOverrideLayoutTemplate($params)
    {
        if (!self::isCacheable()) {
            return;
        }

        /*
        * Keep the existing notification safety check, but postpone the
        * no-cache decision until callbackOutputFilter().
        *
        * At this point the final HTML is not available yet, so we cannot know
        * whether the active theme exposes data-ps-fragment="notifications".
        * If the fragment is successfully replaced with ESI later, only the
        * notifications block is dynamic and the page itself may stay cacheable.
        * Otherwise the old "Has private notification" behavior is preserved.
        *
        * Hummingbird support:
        * https://github.com/PrestaShop/hummingbird/pull/1101
        */
        if ($this->cache->hasNotification()) {
            $this->hasPrivateNotification = true;
        }

        if ((self::$ccflag & self::CCBM_ESI_REQ) == 0) {
            $this->cache->initCacheTagsByController($params);
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
        return $params;
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
        return $params;
    }

    public function hookFilterCmsCategoryContent($params)
    {
        if (self::isCacheable()) {
            // any cms page update, will purge all cmscategory pages, as the assignment may change,
            // so we do not distinguish by cms category id
            $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_CMS);
        }
        return $params;
    }

    public function hookFilterCmsContent($params)
    {
        if (self::isCacheable() && isset($params['object']['id'])) {
            $this->cache->addCacheTags(LiteSpeedCacheConfig::TAG_PREFIX_CMS . $params['object']['id']);
        }
        return $params;
    }

    // this is catchall function for purge events
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
        if (!self::isActiveForUser()) {
            return $params;
        }
        
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
            if(isset($_SERVER['HTTP_USER_AGENT']) ) {
                $comment = '<!-- LiteSpeed Cache created with user_agent: ' . $_SERVER['HTTP_USER_AGENT'] . ' -->' . PHP_EOL; 
            } else {
                $comment = '<!-- LiteSpeed Cache snapshot generated at ' . gmdate('Y/m/d H:i:s') . ' GMT -->';
            }

            if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_FOOTER_COMMENT) {
                LiteSpeedCacheLog::log(
                    'Add html comments in footer ' . $comment,
                    LiteSpeedCacheLog::LEVEL_FOOTER_COMMENT
                );
            }

            return $comment;
        }
    }

    /**
     * Reuses ProductController::displayAjaxRefresh() to render supported
     * product-page dynamic fragments without duplicating PrestaShop core logic.
     */
    public function hookActionAjaxDieProductControllerdisplayAjaxRefreshBefore($params)
    {
        if (
            (int) Tools::getValue('ajax') !== 1
            || Tools::getValue('action') !== 'refresh'
        ) {
            return;
        }

        if ((int) Tools::getValue('lscache_combined') === 1) {
            $content = $this->getCombinedProductRefreshFragments($params);
        } else {
            $fragment = Tools::getValue('lscache_fragment');

            if (in_array($fragment, [
                LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART_REFRESH,
                LiteSpeedCacheDynamicFragment::PRODUCT_ADDITIONAL_INFO_REFRESH,
            ], true)) {
                $content = $this->getProductRefreshFragment($params, $fragment);
            } elseif ($fragment === LiteSpeedCacheDynamicFragment::NOTIFICATIONS) {
                $content = $this->getProductNotificationsFragment();
            } else {
                return;
            }
        }

        if ($content === null) {
            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        // Since PrestaShop 8.1 the hook value is passed by reference.
        // Older versions need to stop the AJAX response directly.
        if (version_compare(_PS_VERSION_, '8.1.0', '<')) {
            die($content);
        }

        $params['value'] = $content;
    }

    /**
     * Return all ProductController-backed dynamic fragments requested by
     * LiteSpeed Combined ESI from one displayAjaxRefresh() execution.
     */
    private function getCombinedProductRefreshFragments($params)
    {
        if (empty($params['value'])) {
            return null;
        }

        $data = json_decode($params['value'], true);
        if (!is_array($data)) {
            return null;
        }

        $includes = isset($_POST['esi_include']) ? $_POST['esi_include'] : [];
        if (!is_array($includes)) {
            $includes = [$includes];
        }

        if (empty($includes)) {
            return null;
        }

        $content = '';
        $notifications = null;
        $notificationsRendered = false;
        $resolved = [];

        foreach ($includes as $includeUrl) {
            if (!is_string($includeUrl) || $includeUrl === '') {
                continue;
            }

            $include = LiteSpeedCacheDynamicFragment::resolveCombinedInclude(
                $includeUrl,
                isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''
            );

            if ($include === null) {
                $resolved[] = [
                    'url' => $includeUrl,
                    'fragment' => null,
                    'status' => 'invalid_include',
                ];
                continue;
            }

            $fragment = $include['fragment'];

            if (in_array($fragment, [
                LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART_REFRESH,
                LiteSpeedCacheDynamicFragment::PRODUCT_ADDITIONAL_INFO_REFRESH,
            ], true)) {
                $fragmentContent = isset($data[$fragment])
                    ? $data[$fragment]
                    : null;
            } elseif ($fragment === LiteSpeedCacheDynamicFragment::NOTIFICATIONS) {
                if (!$notificationsRendered) {
                    $notifications = $this->getProductNotificationsFragment();
                    $notificationsRendered = true;
                }

                $fragmentContent = $notifications;
            } else {
                $resolved[] = [
                    'url' => $includeUrl,
                    'fragment' => $fragment,
                    'status' => 'unsupported_fragment',
                ];
                continue;
            }

            if ($fragmentContent === null) {
                $resolved[] = [
                    'url' => $includeUrl,
                    'fragment' => $fragment,
                    'status' => 'missing_content',
                ];
                continue;
            }

            $inline = sprintf(
                '<esi:inline name=\'%s\' cache-control=\'no-cache\'>%s</esi:inline>',
                $include['url'],
                trim($fragmentContent)
            );

            $content .= $inline;

            $resolved[] = [
                'url' => $includeUrl,
                'fragment' => $fragment,
                'status' => 'rendered',
                'content_length' => strlen($fragmentContent),
                'inline' => $inline,
            ];
        }

        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
            LiteSpeedCacheLog::log(
                __FUNCTION__ . ' combined product refresh includes=' . count($includes),
                LiteSpeedCacheLog::LEVEL_ESI_INCLUDE
            );
        }

        return $content !== '' ? $content : null;
    }

    private function getProductRefreshFragment($params, $key)
    {
        // displayAjaxRefresh() already renders product-page partials as part of
        // its JSON response. Extract only the requested fragment for the ESI response.
        if (empty($params['value'])) {
            return null;
        }

        $data = json_decode($params['value'], true);

        if (
            !is_array($data)
            || !isset($data[$key])
        ) {
            return null;
        }

        return $data[$key];
    }

    private function getProductNotificationsFragment()
    {
        // ProductController has already populated its notification arrays during
        // initContent(), including product-specific cart quantity notifications.
        $controller = $this->context->controller;

        if (!$controller) {
            return null;
        }

        $notifications = [
            'error' => $controller->errors,
            'warning' => $controller->warning,
            'success' => $controller->success,
            'info' => $controller->info,
        ];

        /*
         * displayAjaxRefresh() is reached from Controller::run()'s AJAX path,
         * which skips FrontController::display() and consequently its protected
         * prepareNotifications(). Merge and consume only redirect notifications
         * here, keeping the normal ProductController notification arrays intact.
         */
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (session_status() == PHP_SESSION_ACTIVE && isset($_SESSION['notifications'])) {
            $notifications = array_merge_recursive(
                $notifications,
                json_decode($_SESSION['notifications'], true)
            );
            unset($_SESSION['notifications']);
        } elseif (isset($_COOKIE['notifications'])) {
            $notifications = array_merge_recursive(
                $notifications,
                json_decode($_COOKIE['notifications'], true)
            );
            unset($_COOKIE['notifications']);
        }

        $this->context->smarty->assign(
            'notifications',
            $notifications
        );

        return $this->context->smarty->fetch(
            '_partials/notifications.tpl'
        );
    }

    // called by Media override addJsDef
    public static function filterJsDef(&$jsDef)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return;
        }

        $conf = LiteSpeedCacheConfig::getInstance();
        // $diffCustomerGroup 0: No; 1: Yes; 2: login_out; 3: out_only
        $diffCustomerGroup = $conf->getDiffCustomerGroup();

		if (self::isCacheable() && isset($jsDef['prestashop'])) {
			// always filter out cart & customer personal info
			unset($jsDef['prestashop']['cart']);

            if($diffCustomerGroup==0){
			    unset($jsDef['prestashop']['customer']);
            }
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

    // return status
    private function checkDispatcher($controllerType, $controllerClass)
    {
        if (!self::isActiveForUser()) { // check for ip restriction
            return 'not active';
        }

        if (!defined('_LITESPEED_CALLBACK_')) {
            define('_LITESPEED_CALLBACK_', 1);
            ob_start('LiteSpeedCache::callbackOutputFilter');
        }

        if ($controllerType == DispatcherCore::FC_FRONT) {
            self::$ccflag |= self::CCBM_FRONT_CONTROLLER;
        }
        if ($controllerClass == 'litespeedcacheesiModuleFrontController') {
            self::$ccflag |= self::CCBM_ESI_REQ;

            return 'esi request';
        }

        if (
            $controllerClass == 'ProductController'
            && (int) Tools::getValue('lscache_combined') === 1
            && (int) Tools::getValue('ajax') === 1
            && Tools::getValue('action') === 'refresh'
        ) {
            /*
             * Combined ESI collector responses contain esi:inline blocks.
             *
             * callbackOutputFilter() always calls setCacheControlHeader(), so the
             * final LiteSpeed header must be driven by the module flags here.
             * This results in:
             *
             * X-Litespeed-Cache-Control: no-cache,esi=on
             */
            self::$ccflag |= self::CCBM_ESI_REQ;
            self::$ccflag |= self::CCBM_ESI_ON;
            self::$ccflag |= self::CCBM_NOT_CACHEABLE;
            self::$no_cache_reason .= 'Combined ESI collector';

            return 'combined product dynamic fragment esi request';
        }

        if (
            $controllerClass == 'ProductController'
            && in_array(
                Tools::getValue('lscache_fragment'),
                [
                    LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART_REFRESH,
                    LiteSpeedCacheDynamicFragment::PRODUCT_ADDITIONAL_INFO_REFRESH,
                    LiteSpeedCacheDynamicFragment::NOTIFICATIONS,
                ],
                true
            )
            && (int) Tools::getValue('ajax') === 1
            && Tools::getValue('action') === 'refresh'
        ) {
            self::$ccflag |= self::CCBM_ESI_REQ;

            return 'product dynamic fragment esi request';
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
        $ttl = $item->getTTL();
        if ($item->onlyCacheEmtpy() && $item->getContent() !== '') {
            $ttl = 0;
        }
        if ($ttl === 0 || $ttl === '0') {
            self::$ccflag |= self::CCBM_NOT_CACHEABLE;
            self::$no_cache_reason .= 'Set by ESIModule ' . $item->getConf()->getModuleName();
        } else {
            $this->cache->addCacheTags($item->getTags());
            self::$ccflag |= self::CCBM_CACHEABLE;
            if ($item->isPrivate()) {
                self::$ccflag |= self::CCBM_PRIVATE;
            }
            if ($ttl > 0) {
                $this->cache->setTTL($ttl);
            }
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

        return (self::$ccflag & self::CCBM_VARY_CHANGED) != 0;
    }

    public static function callbackOutputFilter($buffer)
    {
        $lsc = self::myInstance();
        if ((self::$ccflag & self::CCBM_FRONT_CONTROLLER) > 0) {
            self::setVaryCookie();
        }
        /** retired old logic, when vary cookie change, still cacheable
        * if ((self::$ccflag & self::CCBM_FRONT_CONTROLLER) > 0 && self::setVaryCookie() && self::isCacheable()) {
        *     //condition order is fixed
        *     $lsc->setNotCacheable('Env change');
        * }
        **/

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

        $replacedDynamicFragments = [];

        /*
         * Inspect dynamic fragments whenever the response is still eligible for
         * FPC, even when ESI injection is unavailable. A data-ps-fragment block
         * that cannot be isolated must never be stored as static public HTML.
         */
        if (self::isCacheable()) {
            $buffer = $lsc->replaceDynamicFragments($buffer, $replacedDynamicFragments);
        }

        /*
        * Preserve the previous safety behavior for themes that do not expose
        * notifications as a supported dynamic fragment, or when ESI injection
        * is unavailable/failed.
        *
        * Only a fragment that was actually replaced is considered handled.
        *
        * Hummingbird support:
        * https://github.com/PrestaShop/hummingbird/pull/1101
        */
        if (self::isCacheable()
            && $lsc->hasPrivateNotification
            && empty($replacedDynamicFragments[LiteSpeedCacheDynamicFragment::NOTIFICATIONS])) {
            $lsc->setNotCacheable('Has private notification');
        }

        if (self::canInjectEsi()
            && (count($lsc->esiInjection['marker']) || self::isCacheable())) {
            // if no injection, but cacheable, still need to check token
            $buffer = $lsc->replaceEsiMarker($buffer);
        }

        $lsc->cache->setCacheControlHeader();
        /* for testing
         * //  $tname = tempnam('/tmp/t','A');
         * //  file_put_contents($tname, $buffer);
         */
        return $buffer;
    }

    private function replaceDynamicFragments($buffer, &$replacedFragments)
    {
        $parseErrors = [];
        $fragments = LiteSpeedCacheDynamicFragmentParser::find($buffer, $parseErrors);

        if (!empty($parseErrors)) {
            /*
             * data-ps-fragment explicitly marks content as dynamic. If its HTML
             * boundaries are ambiguous, caching the original block would be less
             * safe than disabling FPC for the current response.
             */
            $this->setNotCacheable('Invalid dynamic fragment markup');

            if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_UNEXPECTED) {
                LiteSpeedCacheLog::log(
                    __FUNCTION__ . ' ' . implode('; ', $parseErrors),
                    LiteSpeedCacheLog::LEVEL_UNEXPECTED
                );
            }

            return $buffer;
        }

        if (empty($fragments)) {
            return $buffer;
        }

        $product = false;
        if ($this->context && $this->context->smarty) {
            $product = $this->context->smarty->getTemplateVars('product');
        }

        if (empty($product)
            && $this->context
            && $this->context->controller
            && method_exists($this->context->controller, 'getProduct')) {
            $product = $this->context->controller->getProduct();
        }

        $replaced = false;

        $canUseProductRefresh = (
            $this->context
            && $this->context->controller
            && method_exists($this->context->controller, 'displayAjaxRefresh')
        );

        /*
         * When more than one ProductController-backed fragment is present and
         * the server advertises Combined ESI support, group them so the backend
         * executes the ProductController lifecycle only once. Otherwise keep
         * the existing separate ESI includes as a backward-compatible fallback.
         */
        $productRefreshFragmentNames = [
            LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART,
            LiteSpeedCacheDynamicFragment::PRODUCT_ADDITIONAL_INFO,
            LiteSpeedCacheDynamicFragment::NOTIFICATIONS,
        ];
        $combinedProductFragments = [];

        if ($canUseProductRefresh && !empty($product)) {
            foreach ($fragments as $candidate) {
                if (in_array($candidate['name'], $productRefreshFragmentNames, true)) {
                    $combinedProductFragments[] = $candidate;
                }
            }
        }

        $useCombinedProductRefresh = count($combinedProductFragments) > 1
            && $this->isCombinedEsiSupported();
        $combinedMainStart = null;
        $combinedMainInclude = null;

        if ($useCombinedProductRefresh) {
            $combinedMainStart = min(array_column($combinedProductFragments, 'start'));
            $combinedMainInclude = $this->buildProductCombinedRefreshEsiInclude($product);

            if ($combinedMainInclude === false || $combinedMainInclude === '') {
                $useCombinedProductRefresh = false;
            }
        }

        // Replace from the end of the document to the beginning so the offsets
        // returned by the parser remain valid after each substr_replace().
        foreach (array_reverse($fragments) as $fragment) {
            if (!LiteSpeedCacheDynamicFragment::isSupported($fragment['name'])) {
                // Unknown data-ps-fragment values are still declared dynamic by
                // the theme. Do not silently store them as public static HTML.
                $this->setNotCacheable('Unsupported dynamic fragment: ' . $fragment['name']);

                if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_UNEXPECTED) {
                    LiteSpeedCacheLog::log(
                        __FUNCTION__ . ' unsupported fragment ' . $fragment['name'],
                        LiteSpeedCacheLog::LEVEL_UNEXPECTED
                    );
                }

                continue;
            }

            if (!self::canInjectEsi()) {
                $this->setNotCacheable('Dynamic fragment requires ESI: ' . $fragment['name']);
                continue;
            }

            // Reuse ProductController rendering for product-related fragments
            // Notifications without product context keep using the ESI fallback.
            $useProductController = $canUseProductRefresh
                && (
                    $fragment['name'] === LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART
                    || $fragment['name'] === LiteSpeedCacheDynamicFragment::PRODUCT_ADDITIONAL_INFO
                    || (
                        $fragment['name'] === LiteSpeedCacheDynamicFragment::NOTIFICATIONS
                        && !empty($product)
                    )
                );

            if ($useProductController) {
                $inlineContent = $fragment['name'] === LiteSpeedCacheDynamicFragment::NOTIFICATIONS
                    ? substr($buffer, $fragment['start'], $fragment['length'])
                    : null;

                $useCombinedSub = $useCombinedProductRefresh
                    && in_array($fragment['name'], $productRefreshFragmentNames, true);

                $esiInclude = $this->buildProductRefreshEsiInclude(
                    $product,
                    $fragment['name'],
                    $inlineContent,
                    $useCombinedSub
                );

                if ($esiInclude === false || $esiInclude === '') {
                    $this->setNotCacheable(
                        'Unable to generate dynamic fragment ESI: ' . $fragment['name']
                    );
                    continue;
                }

                /*
                 * The main collector must appear before the combined sub-includes.
                 * Attach it to the earliest ProductController-backed fragment so
                 * no extra DOM/template change is required for the spike.
                 */
                if ($useCombinedSub && $fragment['start'] === $combinedMainStart) {
                    $esiInclude = $combinedMainInclude . $esiInclude;
                }
            } else {
                $esiParam = LiteSpeedCacheDynamicFragment::buildEsiParam($fragment['name']);
                if ($esiParam == null) {
                    $this->setNotCacheable('Unable to build dynamic fragment: ' . $fragment['name']);
                    continue;
                }

                $conf = $this->config->canInjectEsi(LscDynamicFragment::NAME, $esiParam);
                if ($conf == false) {
                    $this->setNotCacheable('Unable to inject dynamic fragment: ' . $fragment['name']);
                    continue;
                }

                $item = new LiteSpeedCacheEsiItem($esiParam, $conf);

                // Notifications may already contain a controller/module message in
                // the current MISS response. Keep that exact HTML as ESI inline
                // content so converting the block to ESI does not make the current
                // notification disappear.
                if ($fragment['name'] === LiteSpeedCacheDynamicFragment::NOTIFICATIONS) {
                    $originalFragment = substr($buffer, $fragment['start'], $fragment['length']);
                    $item->setContent($originalFragment);
                } else {
                    LiteSpeedCacheHelper::genEsiElements($item);
                }

                $esiInclude = $item->getInclude();
                if ($esiInclude === false || $esiInclude === '') {
                    $this->setNotCacheable('Unable to generate dynamic fragment ESI: ' . $fragment['name']);
                    continue;
                }

                $id = $item->getId();
                if (!isset($this->esiInjection['marker'][$id])) {
                    $this->esiInjection['marker'][$id] = $item;
                }
            }

            $buffer = substr_replace(
                $buffer,
                $esiInclude,
                $fragment['start'],
                $fragment['length']
            );

            // Record only fragments that were really converted to ESI. This is
            // used by the deferred hasNotification() fallback above.
            $replacedFragments[$fragment['name']] = true;
            $replaced = true;

            if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
                LiteSpeedCacheLog::log(
                    __FUNCTION__ . ' replaced fragment ' . $fragment['name'],
                    LiteSpeedCacheLog::LEVEL_ESI_INCLUDE
                );
            }
        }

        if ($replaced) {
            $this->setEsiOn();
        }

        return $buffer;
    }

    /**
    * Build an ESI include targeting ProductController::displayAjaxRefresh().
    * The product combination and fragment parameters are passed through
    * getProductLink() so the generated URL follows PrestaShop routing rules.
    */
    private function buildProductRefreshEsiInclude(
        $product,
        $fragment,
        $inlineContent = null,
        $combinedSub = false
    )
    {
        $params = LiteSpeedCacheDynamicFragment::buildProductRefreshParam(
            $product,
            $fragment
        );

        if ($params == null || empty($params['id_product'])) {
            return false;
        }

        $idProduct = (int) $params['id_product'];
        $idProductAttribute = !empty($params['id_product_attribute'])
            ? (int) $params['id_product_attribute']
            : null;

        unset($params['id_product']);

        if ($idProductAttribute === null) {
            unset($params['id_product_attribute']);
        }

        $url = $this->context->link->getProductLink(
            $idProduct,
            null,
            null,
            null,
            null,
            null,
            $idProductAttribute,
            false,
            false,
            false,
            $params,
            false
        );

        $relativeUrl = LiteSpeedCacheHelper::getRelativeUri($url);

        if ($relativeUrl === false) {
            return false;
        }

        $combineAttribute = $combinedSub ? ' combine=\'sub\'' : '';

        $esiInclude = sprintf(
            '<esi:include src=\'%s\' cache-control=\'no-cache\'%s/>',
            $relativeUrl,
            $combineAttribute
        );

        if ($inlineContent !== null) {
            $esiInclude = sprintf(
                '<esi:inline name=\'%s\' cache-control=\'no-cache\'>%s</esi:inline>%s',
                $relativeUrl,
                trim($inlineContent),
                $esiInclude
            );
        }

        return $esiInclude;
    }

    /**
     * Build the Combined ESI main collector include.
     *
     * LiteSpeed posts the sub-include URLs to this ProductController refresh
     * request in $_POST['esi_include'].
     */
    private function buildProductCombinedRefreshEsiInclude($product)
    {
        $params = LiteSpeedCacheDynamicFragment::buildProductCombinedRefreshParam(
            $product
        );

        if ($params == null || empty($params['id_product'])) {
            return false;
        }

        $idProduct = (int) $params['id_product'];
        $idProductAttribute = !empty($params['id_product_attribute'])
            ? (int) $params['id_product_attribute']
            : null;

        unset($params['id_product']);

        if ($idProductAttribute === null) {
            unset($params['id_product_attribute']);
        }

        $url = $this->context->link->getProductLink(
            $idProduct,
            null,
            null,
            null,
            null,
            null,
            $idProductAttribute,
            false,
            false,
            false,
            $params,
            false
        );

        $relativeUrl = LiteSpeedCacheHelper::getRelativeUri($url);

        if ($relativeUrl === false) {
            return false;
        }

        return sprintf(
            '<esi:include src=\'%s\' cache-control=\'no-cache\' combine=\'main\'/>',
            $relativeUrl
        );
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
                ['/_LSC(ESI)-(.+)-START_(.*)_LSCESIEND_/Usm',
                    '/(\'|\")_LSCESIJS-(.+)-START__LSCESIEND_(\'|\")/Usm', ],
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
                $buf
            );
        } else {
            // log here, shouldn't happen
            $nb = $buf;
        }

        $bufInline = '';
        // Tools::getToken() is not really used for cacheable pages
        // Tools::getToken(false) is used -------------- caninject
        $static_token = Tools::getToken(false);
        $tkparam = ['pt' => LiteSpeedCacheEsiItem::ESI_TOKEN, 'm' => LscToken::NAME, 'd' => 'static'];
        $tkitem = new LiteSpeedCacheEsiItem($tkparam, $this->config->getEsiModuleConf(LscToken::NAME));
        $tkitem->setContent($static_token);
        // we always add to inline
        $this->esiInjection['marker'][$tkitem->getId()] = $tkitem;

        $envparam = ['pt' => LiteSpeedCacheEsiItem::ESI_ENV, 'm' => LscEnv::NAME];
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

        $allPrivateItems = [];
        // last adding esi:inline, which needs to be in front of esi:include
        foreach ($this->esiInjection['marker'] as $item) {
            $inline = $item->getInline();
            if ($inline !== false) {
                // for ajax call, it's possible no inline content
                $bufInline .= $inline;
                if ($item->isPrivate()) {
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

        $esiParam = ['pt' => $pt, 'm' => $m, 'f' => $f];
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
    public static function injectRenderWidget($module, $hook_name, $params=false)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return false;
        }

        $lsc = self::myInstance();
        $m = $module->name;
        $pt = LiteSpeedCacheEsiItem::ESI_RENDERWIDGET;

        $esiParam = ['pt' => $pt, 'm' => $m, 'h' => $hook_name];
        $conf = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf == false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . " $m : $hook_name", LiteSpeedCacheLog::LEVEL_ESI_INCLUDE);
        }

        $mp = self::getModuleParams($params, $conf->getTemplateArgs());

        if(!empty($mp)){
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $lsc->registerEsiMarker($esiParam, $conf);
    }

    // used by override hook
    public static function injectCallHook($module, $method, $params=false)
    {
        if ((self::$ccflag & self::CCBM_CAN_INJECT_ESI) == 0) {
            return false;
        }

        $lsc = self::myInstance();
        $m = $module->name;
        $pt = LiteSpeedCacheEsiItem::ESI_CALLHOOK;

        $esiParam = ['pt' => $pt, 'm' => $m, 'mt' => $method];
        $conf = $lsc->config->canInjectEsi($m, $esiParam);
        if ($conf == false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_ESI_INCLUDE) {
            LiteSpeedCacheLog::log(__FUNCTION__ . " $m : $method", LiteSpeedCacheLog::LEVEL_ESI_INCLUDE);
        }

        $mp = self::getModuleParams($params, $conf->getTemplateArgs());

        if(!empty($mp)){
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $lsc->registerEsiMarker($esiParam, $conf);
    }
    
    private static function getModuleParams($params, $tas){
        if(!$tas || !$params || !isset($params['smarty'])) {
            return false;
        }

        $smarty = $params['smarty'];

        $tas1 = explode(',', $tas);
        $mp = [];
        foreach($tas1 as $mv){
            $mvs = explode('.', trim($mv));
            if( $mvs[0]=='smarty' ){
                $mp1 = $smarty->getTemplateVars($mvs[1]);
                if($mp1 && ($arg = $mvs[2])){
                    $mp[] = $mp1->$arg;
                } else {
                    $mp[] = $mp1;
                }
            } else {
                $mp1 = $params[$mvs[0]];
                if($mp1 && $mvs[1]){
                    $mp[] = $mp1[ $mvs[1]];
                } else {
                    $mp[] = $mp1;
                }
            }
        }

        return $mp;
    }

    public static function forceNotCacheable($reason)
    {
        $lsc = self::myInstance();
        $lsc->setNotCacheable($reason);
    }

    // allow other plugins to set current response not cacheable
    private function setNotCacheable($reason = '')
    {
        if (!self::isActive()) { // not check ip for force nocache
            return;
        }
        self::$ccflag |= self::CCBM_NOT_CACHEABLE;
        self::$no_cache_reason .= $reason;
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
        $definedtabs =  $this->initTabs();
        if ($definedtabs == null) {
            return;
        }
        foreach ($definedtabs as $t) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $t['class_name'];
            $tab->name = [];
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
        $definedtabs = [
            [
                'class_name' => 'AdminLiteSpeedCache',
                'name' => $this->l('LiteSpeed Cache'), // this will use the default admin lang
                'visible' => 1,
                'icon' => 'flash_on',
                'ParentClassName' => $root_node,
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheManage',
                'name' => $this->l('Manage'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheConfig',
                'name' => $this->l('Configuration'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
            [
                'class_name' => 'AdminLiteSpeedCacheCustomize',
                'name' => $this->l('Customization'),
                'visible' => 1,
                'ParentClassName' => 'AdminLiteSpeedCache',
            ],
        ];

        return $definedtabs;
    }
}
