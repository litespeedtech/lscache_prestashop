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
 * @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheLog as LSLog;

abstract class LscIntegration
{
    protected static $integrated = array();
    protected $moduleName;
    protected $esiConf;

    // do not allow public constructor
    protected function __construct()
    {
    }

    public static function isUsed($name)
    {
        return Module::isInstalled($name);
    }

    public static function register()
    {
        $className = get_called_class();
        $name = $className::NAME;
        if ($className::isUsed($name)) {
            $instance = new $className();
            if ($instance->init()) {
                self::$integrated[$name] = array('class' => $instance);
            }
        }
    }

    protected function addJsDef($jsk, $proc)
    {
        if (!isset(self::$integrated['jsdef'])) {
            self::$integrated['jsdef'] = array();
            self::$integrated['jsloc'] = array();
        }
        self::$integrated['jsdef'][$jsk] = array('proc' => $proc);
        $locator = explode(':', $jsk);
        $cur = &self::$integrated['jsloc'];
        while ($key = array_shift($locator)) {
            if (!empty($locator)) {
                $cur[$key] = array();
                $cur = &$cur[$key];
            } else {
                $cur[$key] = $jsk;
            }
        }
    }

    protected function registerEsiModule()
    {
        if ($this->esiConf && ($this->esiConf instanceof LiteSpeedCacheEsiModConf)) {
            LiteSpeedCacheConfig::getInstance()->registerEsiModule($this->esiConf);
            return true;
        } else {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_NOTICE) {
                LSLog::log(__FUNCTION__ . 'something wrong', LSLog::LEVEL_NOTICE);
            }
            return false;
        }
    }

    public static function filterJSDef0(&$jsDef, &$injected)
    {
        if (!isset(self::$integrated['jsloc']) || !is_array($jsDef)) {
            return false;
        }

        $replaced = false;
        $log = '';

        foreach ($jsDef as $key => &$js) {
            $curkey = $key;
            if (is_array($js)) {
                foreach ($js as $key2 => &$js2) {
                    $curkey = $key . ':' . $key2;
                    if (self::filterCurrentJSKeyVal($curkey, $js2, $injected, $log)) {
                        $replaced = true;
                    }
                }
            } else {
                if (self::filterCurrentJSKeyVal($curkey, $js, $injected, $log)) {
                    $replaced = true;
                }
            }
        }
        if ($log && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log('filter JSDef = ' . $log, LSLog::LEVEL_ESI_INCLUDE);
        }
        return $replaced;
    }

    protected static function filterCurrentJSKeyVal($key, &$val, &$injected, &$log)
    {
        $def = &self::$integrated['jsdef'];
        if (!isset($def[$key])) {
            return false;
        }
        if (!isset($def[$key]['replace'])) {
            $proc = $def[$key]['proc'];
            $esiParam = array('pt' => LiteSpeedCacheEsiItem::ESI_JSDEF,
                'm' => $proc::NAME,
                'jsk' => $key, );
            $log .= $proc::NAME . ':' . $key . ' ';

            $item = new LiteSpeedCacheEsiItem($esiParam, $proc->esiConf);
            $id = $item->getId();
            $injected[$id] = $item;

            // replacement, hardcoded
            $def[$key]['replace'] = '_LSCESIJS-' . $id . '-START__LSCESIEND_';
            $def[$key]['value'] = json_encode($val); // original
        }
        $val = $def[$key]['replace'];
        return true;
    }

    private static function locateJSKey($key, &$js, &$loc, &$injected, &$log)
    {
        if (!isset($loc[$key])) {
            return null;
        }
        if (is_array($loc[$key]) && is_array($js)) {
            $loc = &$loc[$key];
            foreach ($js as $key2 => &$js2) {
                self::locateJSKey($key2, $js2, $loc, $injected, $log);
            }
        } else {
            $curkey = $loc[$key];
            self::filterCurrentJSKeyVal($curkey, $js, $injected, $log);
        }
    }

    public static function filterJSDef(&$jsDef)
    {
        if (!isset(self::$integrated['jsloc']) || !is_array($jsDef)) {
            return null;
        }

        $injected = array();
        $log = '';

        foreach ($jsDef as $key => &$js) {
            $loc = &self::$integrated['jsloc'];
            self::locateJSKey($key, $js, $loc, $injected, $log);
        }
        if ($log && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log('filter JSDef = ' . $log, LSLog::LEVEL_ESI_INCLUDE);
        }
        return $injected;
    }

    public static function processJsDef($item)
    {
        $name = $item->getParam('m');
        if (isset(self::$integrated[$name])) {
            $key = $item->getParam('jsk');
            if (isset(self::$integrated['jsdef'][$key]['value'])) {
                $item->setContent(self::$integrated['jsdef'][$key]['value']);
                return;
            }
            $proc = self::$integrated[$name]['class'];
            if (method_exists($proc, 'jsKeyProcess')) {
                $item->setContent($proc->jsKeyProcess($key));
                return;
            }
        }
        $item->setFailed();
    }

    public static function processModField($item)
    {
        $name = $item->getParam('m');
        if (isset(self::$integrated[$name])) {
            $proc = self::$integrated[$name]['class'];
            if (method_exists($proc, 'moduleFieldProcess')) {
                $content = $proc->moduleFieldProcess($item->getParam());
                $item->setContent($content);
                return;
            }
        }
        $item->setFailed();
    }

    abstract protected function init();
}
