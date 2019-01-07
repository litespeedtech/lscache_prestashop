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

use LiteSpeedCacheHelper as LSHelper;

class LiteSpeedCacheEsiItem implements JsonSerializable
{
    const ESI_RENDERWIDGET = 'rw';
    const ESI_CALLHOOK = 'ch';
    const ESI_JSDEF = 'js';
    const ESI_SMARTYFIELD = 'mf';
    const ESI_TOKEN = 'tk';
    const ESI_ENV = 'env';
    const RES_FAILED = '__LSC_RES_FAILED__';

    private $sdata;
    private $cdata; // current

    private $conf;
    private $esiInline = false;
    private $content;
    private $err;

    public function __construct($param, LiteSpeedCacheEsiModConf $conf)
    {
        $this->conf= $conf;

        $this->sdata = array(
            'id'          => json_encode($param),
            'param'       => $param,
            'url'         => '',
            'inc'         => false,
            'inlStart' => false,
            'shared'      => null,
            'tag'         => $conf->getTag(),
        );
        $this->cdata = array(
            'inlStart' => false,
        );
    }

    public function jsonSerialize()
    {
        return $this->sdata;
    }

    public function getInfoLog($simple = false)
    {
        $params = implode('|', $this->sdata['param']);
        if ($simple) {
            return $params;
        } else {
            return $this->sdata['id'] . ': ' . $params . ' ' . $this->err;
        }
    }

    public static function newFromSavedData($sdata)
    {
        $conf = LiteSpeedCacheConfig::getInstance()->getEsiModuleConf($sdata['param']['m']);
        if ($conf == null) {
            return null;
        }
        $item = new self($sdata['param'], $conf);
        $item->sdata = $sdata;
        $item->cdata['inlStart'] = $sdata['inlStart'];
        return $item;
    }

    public function getId()
    {
        return $this->sdata['id'];
    }

    public function getInclude()
    {
        return $this->sdata['inc'];
    }

    public function getInline()
    {
        return $this->esiInline;
    }

    public function setIncludeInlineTag($esiInclude, $inlineStart, $url, $ttl)
    {
        $this->cdata['inlStart'] = $inlineStart;
        if ($ttl > 0) {
            $this->sdata['inlStart'] = $inlineStart;
        }
        $this->sdata['inc'] = $esiInclude;
        $this->sdata['url'] = $url;
    }

    public function getConf()
    {
        return $this->conf;
    }

    public function getTag()
    {
        return $this->conf->getTag();
    }

    public function getParam($key = '')
    {
        if ($key == '') {
            return $this->sdata['param'];
        } elseif (!isset($this->sdata['param'][$key])) {
            return null;
        } else {
            return $this->sdata['param'][$key];
        }
    }

    public function getContent()
    {
        return $this->content;
    }

    public function setContent($content)
    {
        $this->content = trim($content);

        if ($this->sdata['inlStart'] == false
                || $this->conf->onlyCacheEmtpy()) { // can vary, always regenerate
            if ($this->content === '' && $this->conf->ignoreEmptyContent()) {
                $this->sdata['inc'] = '';
                return;
            }
            LSHelper::genEsiElements($this);
        }
        $this->esiInline = $this->cdata['inlStart'] . $this->content . '</esi:inline>';
    }

    public function setFailed($err = '')
    {
        $this->content = self::RES_FAILED;
        $this->err = $err;
    }

    public static function decodeEsiUrl()
    {
        $err = $pd = $param = $conf = null;

        if (($pd = Tools::getValue('pd')) == null) {
            $err = 'missing pd';
        } elseif (($param = json_decode($pd, true)) == null) {
            $err = 'invalid format in pd';
        } elseif (!isset($param['pt'])) {
            $err = 'missing pt';
        } elseif (!isset($param['m'])) {
            $err = 'missing m';
        } elseif (($conf = LiteSpeedCacheConfig::getInstance()->getEsiModuleConf($param['m'])) == null) {
            $err = $param['m'] . ' is not found';
        } else {
            switch ($param['pt']) {
                case self::ESI_TOKEN:
                case self::ESI_ENV:
                    break;
                case self::ESI_JSDEF:
                    if (!isset($param['jsk'])) {
                        $err = 'missing jsk';
                    }
                    break;
                case self::ESI_RENDERWIDGET:
                    if (!isset($param['h'])) {
                        $err = 'missing h';
                    }
                    break;
                case self::ESI_CALLHOOK:
                    if (!isset($param['mt'])) {
                        $err = 'missing mt';
                    }
                    break;
                case self::ESI_SMARTYFIELD:
                    if (!isset($param['f']) || $param['f'] == '') {
                        $err = 'missing f';
                    } elseif ($param['f'] == 'widget_block' && !isset($param['t'])) {
                        $err = 'missing t';
                    }
                    break;
                default:
                    $err = 'pt invalid ' . $param['pt'];
            }
        }

        if ($err) {
            return $err . ' : ' . $pd;
        }
        return new self($param, $conf);
    }
}
