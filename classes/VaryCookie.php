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

use LiteSpeedCacheLog as LSLog;

class LiteSpeedCacheVaryCookie extends CookieCore
{

    const BM_HAS_VARYCOOKIE = 1;

    const BM_VARYCOOKIE_CHANGED = 2;

    const BM_HAS_VARYVALUE = 4;

    const BM_VARYVALUE_CHANGED = 8;

    const BM_IS_GUEST = 16;

    const BM_IS_MOBILEVIEW = 32;

    const BM_UPDATE_FAILED = 128;

    const DEFAULT_VARY_COOKIE_NAME = '_lscache_vary'; // system default
    
    const AMP_VARY_COOKIE_NAME = '_lscache_vary_amp';

    const PRIVATE_SESSION_COOKIE = 'lsc_private';

    private $vd;
    
    private $name;

    private $debug_header;

    private $status = 0;

    public function __construct($name = '', $path = '')
    {
        if ($name == '') {
            $name = self::DEFAULT_VARY_COOKIE_NAME;
        }
        // have to extend CookieCore in order to retrieve the internal variables.
        parent::__construct($name, $path);
        $this->_modified = false; // disallow others to call write
        $this->_allow_writing = false;
        
        $context = Context::getContext();
        $psCookie = $context->cookie;
        $this->_path = $psCookie->_path;
        $this->_domain = $psCookie->_domain;
        $this->_secure = $psCookie->_secure;
        $this->name = $name;
        
        if ($name == self::DEFAULT_VARY_COOKIE_NAME) {
            $this->init($context, $psCookie);
        }
    }

    private function envChanged()
    {
        if ($this->vd['vv']['ov'] !== $this->vd['vv']['nv']) {
            return true;
        }
        if ($this->status & self::BM_IS_GUEST) {
            if (($this->vd['cv']['ov'] === null)
                && (($this->vd['vv']['nv'] === 'guest' && $this->vd['cv']['nv'] === null)
                    || ($this->vd['vv']['nv'] === 'guestm' && $this->vd['cv']['nv'] === 'mobile~1~'))) {
                return false;
            } else {
                return true;
            }
        } else { // non guest
            return $this->vd['cv']['ov'] !== $this->vd['cv']['nv'];
        }
    }

    public static function setVary()
    {
        // this will only be called when all vary value determined
        $vary = new LiteSpeedCacheVaryCookie();
        $vary->writeVary();
        $changed = $vary->envChanged();
        $debug_info = '';

        if ($changed) {
            $debug_info = 'changed ' . json_encode($vary->vd);
            if ( _LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_CHANGE) {
                LSLog::log($debug_info, LSLog::LEVEL_ENVCOOKIE_CHANGE);
            }
        } elseif (($vary->status & (self::BM_HAS_VARYCOOKIE | self::BM_HAS_VARYVALUE)) > 0) {
            $debug_info = 'found & match ' . json_encode($vary->vd);
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_DETAIL) {
                LSLog::log($debug_info, LSLog::LEVEL_ENVCOOKIE_DETAIL);
            }
        }

        if ($debug_info) {
            header("X-LSCACHE-Debug-Vary: $debug_info");
        }

        return $changed;
    }
    
    public static function setAmpVary($value)
    {
        // this will be called by Amp module from third-party integration
        $amp = new LiteSpeedCacheVaryCookie(self::AMP_VARY_COOKIE_NAME);
        $amp->writeAmpVary($value);
    }
    
    private function writeAmpVary($value)
    {
        if (headers_sent()) {
            $this->status |= self::BM_UPDATE_FAILED;

            return;
        }
        
        // check lscache vary cookie, not default PS cookie, workaround validator
        $cookies = ${'_COOKIE'};
        $ov = null;
        
        if (isset($cookies[self::AMP_VARY_COOKIE_NAME])) {
            $ov = $cookies[self::AMP_VARY_COOKIE_NAME];
        }
        if ($ov != $value) {
            setcookie(self::AMP_VARY_COOKIE_NAME, $value, 0, $this->_path, $this->_domain, $this->_secure, true);
            //LiteSpeedCache::forceNotCacheable('Amp vary change'); not needed any more, lscache engine can save with proper key.
        }
    }

    private function getPrivateId()
    {
        $len = 32;
        if (function_exists('random_bytes')) {
            $id = bin2hex(random_bytes($len));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $id = bin2hex(openssl_random_pseudo_bytes($len));
        } else {
            $id = uniqid();
        }
        $val = $_SERVER['REMOTE_ADDR'] . $_SERVER['REMOTE_PORT'] . microtime() . $id;

        return md5($val);
    }

    private function writeVary()
    {
        if (headers_sent()) {
            $this->status |= self::BM_UPDATE_FAILED;

            return;
        }
        if (($this->status & self::BM_IS_GUEST) > 0
            && ($this->status & self::BM_VARYVALUE_CHANGED) == 0
            && LiteSpeedCache::isCacheable()) {
            // no cookie set for guest mode and only if for cacheable response.
            // for non-cacheable route, like ajax request, can set vary cookie
            return;
        }

        // always check private session cookie
        if ($this->vd['ps']['ov'] == null) {
            $privateId = $this->getPrivateId();
            $this->vd['ps']['nv'] = $privateId;
            setcookie(self::PRIVATE_SESSION_COOKIE, $privateId, 0, $this->_path, $this->_domain, $this->_secure, true);
        }

        if ($this->status & self::BM_VARYCOOKIE_CHANGED) {
            $val = $this->vd['cv']['nv'];
            $time = 0; // end of session expire
            if ($val === null) { // delete cookie
                $val = '';
                $time = 1000;
            }

            if (!setcookie($this->vd['cv']['name'], $val, $time, $this->_path, $this->_domain, $this->_secure, true)) {
                $this->status |= self::BM_UPDATE_FAILED;
            }
        }

        if ($this->status & self::BM_VARYVALUE_CHANGED) {
            header('X-LiteSpeed-Vary: value=' . $this->vd['vv']['nv']);
        }
    }

    private function init($context, $psCookie)
    {
        $this->vd = [
            'cv' => ['name' => $this->name, 'ov' => null, 'nv' => null], // cookieVary
            'vv' => ['ov' => null, 'nv' => null],   // valueVary
            'ps' => ['ov' => null, 'nv' => null], // private session
        ];
        
        $conf = LiteSpeedCacheConfig::getInstance();
        // $diffCustomerGroup 0: No; 1: Yes; 2: login_out
        $diffCustomerGroup = $conf->getDiffCustomerGroup();
        // $diffMobile  0: no; 1: yes
        $diffMobile = $conf->get(LiteSpeedCacheConfig::CFG_DIFFMOBILE);
        $isMobile = $diffMobile ? $context->getMobileDevice() : false;
        $bypass = $conf->getContextBypass();
        $this->debug_header = $conf->get(LiteSpeedCacheConfig::CFG_DEBUG_HEADER);

        // check lscache vary cookie, not default PS cookie, workaround validator
        $cookies = ${'_COOKIE'};
        $data = [];
        if (LiteSpeedCache::isRestrictedIP()) {
            $data['dev'] = 1;
        }
        if (!in_array('ctry', $bypass) && isset($psCookie->iso_code_country)) {
            $iso = $psCookie->iso_code_country;
            $id_country = (int)Configuration::get('PS_COUNTRY_DEFAULT');
            $default_iso = Country::getIsoById($id_country);
            if ($iso != $default_iso) {
                $data['ctry'] = $iso;
            }
        }
        if (!in_array('curr', $bypass) && isset($psCookie->id_currency)) {
            $configuration_curr = Configuration::get('PS_CURRENCY_DEFAULT');
            if ($psCookie->id_currency != $configuration_curr) {
                $data['curr'] = $psCookie->id_currency;
            }
        }
        if (!in_array('lang', $bypass) && isset($psCookie->id_lang) && Language::isMultiLanguageActivated()) {
            $configuration_id_lang = Configuration::get('PS_LANG_DEFAULT');
            if ($psCookie->id_lang != $configuration_id_lang) {
                $data['lang'] = $psCookie->id_lang;
            }
        }
        if ($diffMobile && $isMobile) {
            $data['mobile'] = 1;
            $this->status |= self::BM_IS_MOBILEVIEW;
        }
        // customer maybe null
        if (($diffCustomerGroup != 0) && ($context->customer != null) && $context->customer->isLogged()) {
            // 1: every group, 2: inout
            if ($diffCustomerGroup == 1) {
                $data['cg'] = $context->customer->getGroups()[0];
            } else {
                $data['cg'] = 1;
            }
        }
        if (!empty($data)) {
            ksort($data); // data is array, key sorted
            $newVal = '';
            foreach ($data as $k => $v) {
                $newVal .= $k . '~' . $v . '~';
            }
            $this->vd['cv']['nv'] = $newVal;
            $this->vd['cv']['data'] = $data;
            $this->status |= self::BM_HAS_VARYCOOKIE;
        }

        if (isset($cookies[$this->vd['cv']['name']])) {
            $oldVal = $cookies[$this->vd['cv']['name']];
            if ($oldVal == 'deleted') {
                $oldVal = null;
            }
            $this->vd['cv']['ov'] = $oldVal;
        }
        if ($this->vd['cv']['ov'] !== $this->vd['cv']['nv']) {
            $this->status |= self::BM_VARYCOOKIE_CHANGED;
        }

        // check vary value
        if (isset($_SERVER['LSCACHE_VARY_VALUE'])) {
            $ov = $_SERVER['LSCACHE_VARY_VALUE'];
            $this->vd['vv']['ov'] = $this->vd['vv']['nv'] = $ov;
            $this->status |= self::BM_HAS_VARYVALUE;
            if ($diffMobile) { // check if mismatch
                if ($ov == 'guest' && $isMobile) {
                    $this->vd['vv']['nv'] = 'guestm';
                } elseif ($ov == 'guestm' && !$isMobile) {
                    $this->vd['vv']['nv'] = 'guest';
                }
                if ($this->vd['vv']['ov'] !== $this->vd['vv']['nv']) {
                    $this->status |= self::BM_VARYVALUE_CHANGED;
                }
            }
            if ($ov == 'guest' || $ov == 'guestm') {
                $this->status |= self::BM_IS_GUEST;
            }
        }

        if (isset($cookies[self::PRIVATE_SESSION_COOKIE])) {
            $this->vd['ps']['ov'] = $cookies[self::PRIVATE_SESSION_COOKIE];
        }
    }
    
    
}
