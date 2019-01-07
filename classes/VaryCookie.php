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
    const PRIVATE_SESSION_COOKIE = 'lsc_private';

    private $vd;
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

        $this->vd = array(
            'cv' => array('name' => $name, 'ov' => null, 'nv' => null), // cookieVary
            'vv' => array('ov' => null, 'nv' => null),   // valueVary
            'ps' => array('ov' => null, 'nv' => null),// private session
        );
        $this->init();
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
            return ($this->vd['cv']['ov'] !== $this->vd['cv']['nv']);
        }
    }

    public static function setVary()
    {
        // this will only be called when all vary value determined
        $vary = new LiteSpeedCacheVaryCookie();
        $vary->writeVary();
        $changed = $vary->envChanged();

        if ($changed && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_CHANGE) {
            LSLog::log('vary changed ' . json_encode($vary->vd), LSLog::LEVEL_ENVCOOKIE_CHANGE);
        } elseif (!$changed && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_DETAIL
            && ($vary->status & (self::BM_HAS_VARYCOOKIE | self::BM_HAS_VARYVALUE)) > 0
            ) {
            LSLog::log('vary found & match ' . json_encode($vary->vd), LSLog::LEVEL_ENVCOOKIE_DETAIL);
        }
        return $changed;
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

    private function init()
    {
        $conf = LiteSpeedCacheConfig::getInstance();
        // $diffCustomerGroup 0: No; 1: Yes; 2: login_out
        $diffCustomerGroup = $conf->get(LiteSpeedCacheConfig::CFG_DIFFCUSTGRP);
        // $diffMobile  0: no; 1: yes
        $diffMobile = $conf->get(LiteSpeedCacheConfig::CFG_DIFFMOBILE);
        $context = Context::getContext();
        $psCookie = $context->cookie;
        $isMobile = $diffMobile ? $context->getMobileDevice() : false;
        $this->_path = $psCookie->_path;
        $this->_domain = $psCookie->_domain;
        $this->_secure = $psCookie->_secure;

        // check lscache vary cookie, not default PS cookie, workaround validator
        $cookies = ${'_COOKIE'};
        $data = array();
        if (LiteSpeedCache::isRestrictedIP()) {
            $data['dev'] = 1;
        }
        if (isset($psCookie->iso_code_country)) {
            $data['ctry'] = $psCookie->iso_code_country;
        }
        if (isset($psCookie->id_currency)) {
            $configuration_curr = Configuration::get('PS_CURRENCY_DEFAULT');
            if ($psCookie->id_currency != $configuration_curr) {
                $data['curr'] = $psCookie->id_currency;
            }
        }
        if (isset($psCookie->id_lang) && Language::isMultiLanguageActivated()) {
            $configuration_id_lang = Configuration::get('PS_LANG_DEFAULT');
            if ($psCookie->id_lang != $configuration_id_lang) {
                $data['lang'] = $psCookie->id_lang;
            }
        }
        if ($diffMobile && $isMobile) {
            $data['mobile'] = 1;
            $this->status |= self::BM_IS_MOBILEVIEW;
        }
        if ($diffCustomerGroup != 0 && $context->customer->isLogged()) {
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
