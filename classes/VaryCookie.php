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
    private $defaultEnvVaryCookie = '_lscache_vary'; // system default
    private $cacheVary;
    private $diffCustomerGroup; // 0: No; 1: Yes; 2: login_out

    // to extend CookieCore is to retrieve the internal variables.

    public function __construct($name = '', $path = '')
    {
        if ($name != '' && $name != $this->defaultEnvVaryCookie) {
            $this->defaultEnvVaryCookie = $name;
        } else {
            $name = $this->defaultEnvVaryCookie;
        }
        parent::__construct($name, $path);
        $this->cacheVary = array($name => array());
        $conf = LiteSpeedCacheConfig::getInstance();
        $this->diffCustomerGroup = $conf->get(LiteSpeedCacheConfig::CFG_DIFFCUSTGRP);
    }

    // can split to 2 functions later
    public static function setCookieVary()
    {
        $vary = new LiteSpeedCacheVaryCookie();
        return $vary->write();
    }

    // not used for now
    public function addEnvVary($cookieName = '', $key = '', $val = '')
    {
        if ($cookieName == '') {
            $cookieName = $this->defaultEnvVaryCookie;
        }

        if (!isset($this->cacheVary[$cookieName])) {
            $this->cacheVary[$cookieName] = array();
        }
        if ($key) {
            $this->cacheVary[$cookieName][$key] = $val;
        }
    }

    public function __destruct()
    {
        $this->_allow_writing = false;
    }

    protected function setDefaultEnvVary()
    {
        $context = Context::getContext();
        $myCookie = $context->cookie;
        $data = array();
        if (LiteSpeedCache::isRestrictedIP()) {
            $data['dev'] = 1;
        }
        if (isset($myCookie->iso_code_country)) {
            $data['ctry'] = $myCookie->iso_code_country;
        }
        if (isset($myCookie->id_currency)) {
            $configuration_curr = Configuration::get('PS_CURRENCY_DEFAULT');
            if ($myCookie->id_currency != $configuration_curr) {
                $data['curr'] = $myCookie->id_currency;
            }
        }
        if (isset($myCookie->id_lang) && Language::isMultiLanguageActivated()) {
            $configuration_id_lang = Configuration::get('PS_LANG_DEFAULT');
            if ($myCookie->id_lang != $configuration_id_lang) {
                $data['lang'] = $myCookie->id_lang;
            }
        }
        if ($this->diffCustomerGroup != 0 && $context->customer->isLogged()) {
            // 1: every group, 2: inout
            if ($this->diffCustomerGroup == 1) {
                $data['cg'] = $context->customer->getGroups()[0];
            } else {
                $data['cg'] = 1;
            }
        }
        $this->cacheVary[$this->defaultEnvVaryCookie] = $data;
    }

    public function write()
    {
        if (headers_sent()) {
            return false;
        }

        if (empty($this->cacheVary[$this->defaultEnvVaryCookie])) {
            $this->setDefaultEnvVary();
        }

        $write = array();
        // check lscache vary cookie, not default PS cookie, workaround validator
        $cookies = ${'_COOKIE'};
        foreach ($this->cacheVary as $cookieName => $data) {
            $oldVal = '';
            $newVal = '';
            $changed = false;
            // we new use cipherTool later
            if (!empty($data)) {
                ksort($data); // data is array, key sorted
                foreach ($data as $k => $v) {
                    $newVal .= $k . '~' . $v . '~';
                }
            }

            if (isset($cookies[$cookieName])) {
                $oldVal = trim($cookies[$cookieName]);
                if ($oldVal == 'deleted') {
                    $oldVal = '';
                }
            }
            if ($oldVal != $newVal) {
                $changed = true;
            }
            if ($changed) {
                $write[$cookieName] = $newVal;
                if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_CHANGE) {
                    $mesg = 'env cookie changed ' . $cookieName . ' ov=' . $oldVal . ' nv=' . $newVal;
                    LSLog::log($mesg, LSLog::LEVEL_ENVCOOKIE_CHANGE);
                }
            } elseif ($newVal && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_DETAIL) {
                $mesg = 'env cookie found and match ' . $cookieName . ' = ' . $newVal;
                LSLog::log($mesg, LSLog::LEVEL_ENVCOOKIE_DETAIL);
            }
        }

        if (!empty($write)) {
            $myCookie = Context::getContext()->cookie;
            $path = $myCookie->_path;
            $domain = $myCookie->_domain;
            $secure = $myCookie->_secure;
            foreach ($write as $name => $val) {
                setcookie($name, $val, 0, $path, $domain, $secure, true);
            }
            return true;
        }
        return false;
    }
}
