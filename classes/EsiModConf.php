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

use LiteSpeedCacheEsiItem as EsiItem;
use LiteSpeedCacheLog as LSLog;

class LiteSpeedCacheEsiModConf implements JsonSerializable
{
    // avail types
    const TYPE_BUILTIN = 0;

    const TYPE_INTEGRATED = 1;

    const TYPE_CUSTOMIZED = 2;

    // avail fields
    const FLD_PRIV = 'priv';

    const FLD_TAG = 'tag';

    const FLD_TTL = 'ttl';

    // comma separated purge events
    const FLD_PURGE_EVENTS = 'events';

    // comma separate controller classes, with :P for POST only
    const FLD_PURGE_CONTROLLERS = 'ctrl';

    // comma separated list of method, if proceed with "!", meaning not included
    const FLD_HOOK_METHODS = 'methods';

    // *: all, list of allowed hooks, or not-allowed hooks
    const FLD_RENDER_WIDGETS = 'render';

    const FLD_ASVAR = 'asvar';

    const FLD_IGNORE_EMPTY = 'ie';

    const FLD_ONLY_CACHE_EMPTY = 'ce';

    const FLD_TIPURL = 'tipurl';

    private $moduleName;

    private $type;

    private $data;

    private $parsed = [];

    public function __construct($moduleName, $type, $data)
    {
        $this->moduleName = $moduleName;
        $this->type = $type;
        $this->data = [];
        //sanatize data
        $this->data[self::FLD_PRIV] = $data[self::FLD_PRIV] ? 1 : 0;
        if (isset($data[self::FLD_TAG])) {
            $this->data[self::FLD_TAG] = $data[self::FLD_TAG];
        }
        if (isset($data[self::FLD_TTL])) {
            $this->data[self::FLD_TTL] = $data[self::FLD_TTL];
        }
        if (isset($data[self::FLD_PURGE_EVENTS])) {
            $this->data[self::FLD_PURGE_EVENTS] = $data[self::FLD_PURGE_EVENTS];
        }
        if (isset($data[self::FLD_PURGE_CONTROLLERS])) {
            $this->data[self::FLD_PURGE_CONTROLLERS] = $data[self::FLD_PURGE_CONTROLLERS];
        }
        if (isset($data[self::FLD_HOOK_METHODS])) {
            $this->data[self::FLD_HOOK_METHODS] = $data[self::FLD_HOOK_METHODS];
        }
        if (isset($data[self::FLD_RENDER_WIDGETS])) {
            $this->data[self::FLD_RENDER_WIDGETS] = $data[self::FLD_RENDER_WIDGETS];
        }
        if (isset($data[self::FLD_ASVAR])) {
            $this->data[self::FLD_ASVAR] = $data[self::FLD_ASVAR];
        }
        if (isset($data[self::FLD_IGNORE_EMPTY])) {
            $this->data[self::FLD_IGNORE_EMPTY] = $data[self::FLD_IGNORE_EMPTY];
        }
        if (isset($data[self::FLD_ONLY_CACHE_EMPTY])) {
            $this->data[self::FLD_ONLY_CACHE_EMPTY] = $data[self::FLD_ONLY_CACHE_EMPTY];
        }
        if (isset($data[self::FLD_TIPURL])) {
            $this->data[self::FLD_TIPURL] = $data[self::FLD_TIPURL];
        }
        $this->parseList(self::FLD_RENDER_WIDGETS);
        $this->parseList(self::FLD_HOOK_METHODS);
    }

    public function getModuleName()
    {
        return $this->moduleName;
    }

    public function getCustConfArray()
    {
        $cdata = [
            'id' => $this->moduleName,
            'name' => $this->moduleName,
            'priv' => $this->isPrivate(),
            'ttl' => $this->getTTL(),
            'tag' => $this->getTag(),
            'type' => $this->type,
            'events' => $this->getFieldValue(self::FLD_PURGE_EVENTS, false, true),
            'ctrl' => $this->getFieldValue(self::FLD_PURGE_CONTROLLERS, false, true),
            'methods' => $this->getFieldValue(self::FLD_HOOK_METHODS, false, true),
            'render' => $this->getFieldValue(self::FLD_RENDER_WIDGETS, false, true),
            'asvar' => $this->getFieldValue(self::FLD_ASVAR, true),
            'ie' => $this->getFieldValue(self::FLD_IGNORE_EMPTY, true),
            'ce' => $this->getFieldValue(self::FLD_ONLY_CACHE_EMPTY, true),
            'tipurl' => $this->getFieldValue(self::FLD_TIPURL),
        ];
        if ($tmp_instance = Module::getInstanceByName($this->moduleName)) {
            $cdata['name'] = $tmp_instance->displayName;
        }

        return $cdata;
    }

    public function jsonSerialize()
    {
        $sdata = $this->data;
        $sdata['id'] = $this->moduleName;

        return $sdata;
    }

    public function isPrivate()
    {
        return $this->data[self::FLD_PRIV] != null;
    }

    private function getFieldValue($field, $isbool = false, $splitClean = false)
    {
        $value = (isset($this->data[$field])) ? $this->data[$field] : '';
        if ($isbool) {
            $value = ($value) ? true : false;
        }
        if ($splitClean && $value) {
            $dv = preg_split("/[\s,]+/", $value, null, PREG_SPLIT_NO_EMPTY);
            $value = implode(', ', $dv);
        }

        return $value;
    }

    public function getTTL()
    {
        return isset($this->data[self::FLD_TTL]) ?
            $this->data[self::FLD_TTL] : '';
    }

    public function getTag()
    {
        if (!empty($this->data[self::FLD_TAG])) {
            return $this->data[self::FLD_TAG];
        } else {
            return $this->moduleName;
        }
    }

    public function asVar()
    {
        return isset($this->data[self::FLD_ASVAR]) && $this->data[self::FLD_ASVAR];
    }

    public function onlyCacheEmtpy()
    {
        return isset($this->data[self::FLD_ONLY_CACHE_EMPTY]) && $this->data[self::FLD_ONLY_CACHE_EMPTY];
    }

    public function ignoreEmptyContent()
    {
        return isset($this->data[self::FLD_IGNORE_EMPTY]) && $this->data[self::FLD_IGNORE_EMPTY];
    }

    // return array( lowercased classname => 0, 1 )
    public function getPurgeControllers()
    {
        if (empty($this->data[self::FLD_PURGE_CONTROLLERS])) {
            return null;
        }
        $controllers = [];
        $list = preg_split("/[\s,]+/", $this->data[self::FLD_PURGE_CONTROLLERS], null, PREG_SPLIT_NO_EMPTY);
        foreach ($list as $item) {
            // allow ClassName?param1&param2
            $ct = explode('?', $item);
            if (count($ct) == 1) {
                $controllers[$item] = 0;
            } else {
                $controllers[$ct[0]] = $ct[1];
            }
        }

        return $controllers;
    }

    public function getPurgeEvents()
    {
        if (isset($this->data[self::FLD_PURGE_EVENTS])) {
            return preg_split("/[\s,]+/", $this->data[self::FLD_PURGE_EVENTS], null, PREG_SPLIT_NO_EMPTY);
        }

        return null;
    }

    public function canInject($params)
    {
        if (empty($params['pt'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                LSLog::log(__FUNCTION__ . ' missing pt', LSLog::LEVEL_UNEXPECTED);
            }

            return false;
        }
        switch ($params['pt']) {
            case EsiItem::ESI_RENDERWIDGET:
                return $this->checkInjection(self::FLD_RENDER_WIDGETS, $params['h']);

            case EsiItem::ESI_CALLHOOK:
                return $this->checkInjection(self::FLD_HOOK_METHODS, $params['mt']);

            case EsiItem::ESI_SMARTYFIELD:
            default:
                return true;
        }
    }

    public function isCustomized()
    {
        return $this->type == self::TYPE_CUSTOMIZED;
    }

    private function checkInjection($field, $value)
    {
        $res = $this->parsed[$field];
        $type = $res[0];
        if ($type == 9) { // allow all
            return true;
        }
        $value = Tools::strtolower($value);
        if ($type == 1) {
            return in_array($value, $res[1]); // include
        } elseif ($type == 2) {
            return !in_array($value, $res[2]); // exclude
        } else {
            return false;
        }
    }

    // stringData is comma separated list, * for all, ! is not include
    private function parseList($field)
    {
        // $res[0] = -1: none; 9: all; 1: included, 2: excluded
        $res = [];
        if (!isset($this->data[$field])) {
            $res[0] = -1; // none
        } elseif ($this->data[$field] == '*') {
            $res[0] = 9; // all
        } else {
            $list = preg_split("/[\s,]+/", $this->data[$field], null, PREG_SPLIT_NO_EMPTY);
            $isInclude = 0; // included is 1, excluded is 2
            foreach ($list as $d) {
                $d = Tools::strtolower($d);
                if ($d[0] == '!') {
                    $isInclude |= 2;
                    if (!isset($res[2])) {
                        $res[2] = [];
                    }
                    $res[2][] = ltrim($d, '!');
                } else {
                    $isInclude |= 1;
                    if (!isset($res[1])) {
                        $res[1] = [];
                    }
                    $res[1][] = $d;
                }
            }
            if (($isInclude & 1) == 1) {
                $res[0] = 1; // if contains included, will only check included
            } elseif (($isInclude & 2) == 2) {
                $res[0] = 2;
            } else {
                $res[0] = -1;
            }
        }
        $this->parsed[$field] = $res;
    }
}
