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

use LiteSpeedCacheConfig as Conf;
use LiteSpeedCacheLog as LSLog;

class LiteSpeedCacheHelper
{

    private static $internal = array();

    private static function initInternals()
    {
        $ctx = Context::getContext();
        $cookie = $ctx->cookie;
        $config = Conf::getInstance();

        $defaultParam = array('s' => $ctx->shop->id);
        if (isset($cookie->iso_code_country)) {
            $defaultParam['ct'] = $cookie->iso_code_country;
        }
        if (isset($cookie->id_currency)) {
            $defaultParam['c'] = $cookie->id_currency;
        }
        if (isset($cookie->id_lang)) {
            $defaultParam['l'] = $cookie->id_lang;
        }
        if ($config->get(Conf::CFG_DIFFMOBILE)) {
            $defaultParam['mobi'] = $ctx->getMobileDevice() ? 1:0;
        }
        $esiurl = $ctx->link->getModuleLink(LiteSpeedCache::MODULE_NAME, 'esi', $defaultParam);
        $esiurl0 = $ctx->link->getModuleLink(LiteSpeedCache::MODULE_NAME, 'esi');
        self::$internal['esi_base_url'] = self::getRelativeUri($esiurl);
        self::$internal['esi_base_url_raw'] = self::getRelativeUri($esiurl0);

        self::$internal['pub_ttl'] = $config->get(Conf::CFG_PUBLIC_TTL);
        self::$internal['priv_ttl'] = $config->get(Conf::CFG_PRIVATE_TTL);

        $unique = str_split(md5(_PS_ROOT_DIR_));
        $prefix = 'PS' . implode('', array_slice($unique, 0, 5)); // take 5 char
        self::$internal['tag_prefix'] = $prefix;
        self::$internal['cache_entry'] = $prefix . md5(self::$internal['esi_base_url']) . '.data';
        self::$internal['cache_dir'] = _PS_CACHE_DIR_ . LiteSpeedCache::MODULE_NAME;

        $tag0 = $prefix; // for purge all PS cache
        $tag1 = $prefix . '_' . Conf::TAG_PREFIX_SHOP . $defaultParam['s']; // for purge one shop
        self::$internal['tag_shared_pub'] = $tag0 . ',' . $tag1;
        self::$internal['tag_shared_priv'] = 'public:' . $prefix . '_PRIV'; // in private cache, use public:prefix_PRIV
    }

    public static function getRelativeUri($url)
    {
        if (($pos0 = strpos($url, '://')) !== false) {
            $pos0 += 4;
            if ($pos1 = strpos($url, '/', $pos0)) {
                $newurl = Tools::substr($url, $pos1);
                return $newurl;
            }
        }
        return false;
    }

    public static function getCacheFilePath(&$dir)
    {
        if (!isset(self::$internal['esi_base_url'])) {
            self::initInternals();
        }
        $dir = self::$internal['cache_dir'] ;
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        return $dir . '/' . self::$internal['cache_entry'];
    }

    public static function clearInternalCache()
    {
        $count = 0;
        $dir = '';
        self::getCacheFilePath($dir);
        foreach (scandir($dir) as $entry) {
            if (preg_match('/\.data$/', $entry)) {
                @unlink($dir . '/' . $entry);
                $count ++;
            }
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log(__FUNCTION__ . "=$count", LSLog::LEVEL_PURGE_EVENT);
        }
    }

    public static function genEsiElements(LiteSpeedCacheEsiItem $item)
    {
        if (!isset(self::$internal['esi_base_url'])) {
            self::initInternals();
        }

        // example
        // <esi:inline name="/litemage/esi/.../" cache-control="private,max-age=1800,no-vary"
        // cache-tag="E.welcome">Welcome</esi:inline>
        // <esi:include src='/litemage/esi/.../'
        //   cache-tag='E.footer' as-var='1' cache-control='no-vary,public'/>

        // id is base64_encode(json_encode($params))
        $url = self::$internal['esi_base_url'] . '&pd=' . urlencode($item->getId());

        $tagInline = '';
        $tagInclude = '';
        $esiconf = $item->getConf();
        $ttl = $esiconf->getTTL();
        if ($ttl === 0 || $ttl === '0') {
            $ccInclude = $ccInline = 'no-cache';
        } else {
            $isPrivate = $esiconf->isPrivate();
            if ($ttl === '') {
                $ttl = $isPrivate ? self::$internal['priv_ttl'] : self::$internal['pub_ttl'];
            }
            $ccInclude = $isPrivate ? 'private' : 'public';
            $ccInclude .= ',no-vary';
            $ccInline = $ccInclude . ',max-age=' . $ttl;

            $tagInline = ' cache-tag=\''; // need space in front
            $tagInline .= $isPrivate ?
                    self::$internal['tag_shared_priv'] : self::$internal['tag_shared_pub'];
            if ($tag = $esiconf->getTag()) {
                $tagInline .= ',' . self::$internal['tag_prefix'] . '_' . $tag;
            }
            $tagInline .= '\'';
            if ($esiconf->asVar()) { // only asvar need to show tag
                $tagInclude = $tagInline . ' as-var=\'1\'';
            }
        }

        $esiInclude = sprintf('<esi:include src=\'%s\' cache-control=\'%s\'%s/>', $url, $ccInclude, $tagInclude);
        $inlineStart = sprintf('<esi:inline name=\'%s\' cache-control=\'%s\'%s>', $url, $ccInline, $tagInline);
        $item->setIncludeInlineTag($esiInclude, $inlineStart, $url);
    }
    /* unique prefix for this PS installation, avoid conflict of multiple installations within same cache root */

    public static function getTagPrefix()
    {
        if (!isset(self::$internal['tag_prefix'])) {
            self::initInternals();
        }
        return self::$internal['tag_prefix'];
    }

    protected static function getFileContent($filepath)
    {
        $contents = '';
        $len = @filesize($filepath);
        if ($len) {
            $h = @fopen($filepath, 'r');
            $contents = @fread($h, $len);
            @fclose($h);
        }
        return $contents;
    }

    public static function getRelatedItems($id)
    {
        $items = array();
        $dir = '';
        $cacheFile = self::getCacheFilePath($dir);
        $snapshot = self::getFileContent($cacheFile);
        $saved = json_decode($snapshot, true);
        if (!is_array($saved)
                || json_last_error() !== JSON_ERROR_NONE
                || !isset($saved['data'][$id])) {
            return $items;
        }

        $related = array();
        if (isset($saved['first'][$id])) {
            $related = array_keys($saved['data']);
        } else {
            $tag = $saved['data'][$id]['tag'];
            if (isset($saved['tags'][$tag])) {
                $related = $saved['tags'][$tag];
            }
        }
        foreach ($related as $rid) {
            if ($rid != $id) {
                $ri = LiteSpeedCacheEsiItem::newFromSavedData($saved['data'][$rid]);
                if ($ri != null) {
                    $items[] = $ri;
                }
            }
        }
        return $items;
    }

    public static function syncItemCache($itemList)
    {
        $dir = '';
        $cacheFile = self::getCacheFilePath($dir);
        $snapshot = self::getFileContent($cacheFile);
        $saved = json_decode($snapshot, true);

        if (!is_array($saved) || json_last_error() !== JSON_ERROR_NONE) {
            $saved = array('data' => array(), 'tags' => array(), 'first' => array());
        }

        $setFirst = false;
        foreach ($itemList as $item) {
            $id = $item->getId();
            $descr = $item->getInfoLog(true);
            $saved['data'][$id] = $item;
            if (!$setFirst) {
                $saved['first'][$id] = $descr;
                $setFirst = true;
            } else {
                $tag = $item->getTag();
                if (!isset($saved['tags'][$tag])) {
                    $saved['tags'][$tag] = array($id => $descr);
                } elseif (!isset($saved['tags'][$tag][$id])) {
                    $saved['tags'][$tag][$id] = $descr;
                }
            }
        }
        ksort($saved['data']);
        ksort($saved['tags']);
        ksort($saved['first']);
        $newsnapshot = json_encode($saved, JSON_UNESCAPED_SLASHES);
        if ($snapshot != $newsnapshot) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_SAVED_DATA) {
                LSLog::log(__FUNCTION__ . ' updated data ' . var_export($saved, true), LSLog::LEVEL_SAVED_DATA);
            }
            file_put_contents($cacheFile, $newsnapshot);
        }
    }

    public static function isStaticResource($url)
    {
        $pattern = '/(js|css|jpg|png|svg|gif|woff|woff2)$/';
        return preg_match($pattern, $url);
    }

    public static function licenseEnabled()
    {
        // possible string "on,crawler,esi", will enforce checking in future
        return ( (isset($_SERVER['X-LSCACHE']) && $_SERVER['X-LSCACHE']) // for lsws
                || (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']));  // lslb
    }
}
