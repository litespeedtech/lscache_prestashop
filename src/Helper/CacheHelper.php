<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Helper;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/*
 * CacheHelper — static utility class for cache directory, ESI URL generation, .htaccess management.
 */
class CacheHelper
{
    private static $internal = [];

    private static function initInternals(): void
    {
        $ctx = \Context::getContext();
        $cookie = $ctx->cookie;
        $config = Conf::getInstance();

        $defaultParam = ['s' => $ctx->shop->id];
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
            $defaultParam['mobi'] = $ctx->getMobileDevice() ? 1 : 0;
        }

        self::$internal['pub_ttl'] = $config->get(Conf::CFG_PUBLIC_TTL);
        self::$internal['priv_ttl'] = $config->get(Conf::CFG_PRIVATE_TTL);

        $unique = str_split(md5(_PS_ROOT_DIR_));
        $prefix = 'PS' . implode('', array_slice($unique, 0, 5));
        self::$internal['tag_prefix'] = $prefix;
        self::$internal['cache_dir'] = _PS_CACHE_DIR_ . \LiteSpeedCache::MODULE_NAME;

        $tag0 = $prefix;
        $tag1 = $prefix . '_' . Conf::TAG_PREFIX_SHOP . $defaultParam['s'];
        self::$internal['tag_shared_pub'] = $tag0 . ',' . $tag1;
        self::$internal['tag_shared_priv'] = 'public:' . $prefix . '_PRIV';

        if (\LiteSpeedCache::canInjectEsi() || \LiteSpeedCache::isCacheable() || \LiteSpeedCache::isEsiRequest()) {
            $esiurl = $ctx->link->getModuleLink(\LiteSpeedCache::MODULE_NAME, 'esi', $defaultParam);
            self::$internal['esi_base_url'] = self::getRelativeUri($esiurl);
            self::$internal['cache_entry'] = $prefix . md5(self::$internal['esi_base_url']);
        }
    }

    public static function getRelativeUri(string $url): string|false
    {
        if (($pos0 = strpos($url, '://')) !== false) {
            $pos0 += 4;
            if ($pos1 = strpos($url, '/', $pos0)) {
                return \Tools::substr($url, $pos1);
            }
        }

        return false;
    }

    public static function getCacheFilePath(string &$dir): string
    {
        $dir = self::getCacheDir();

        return $dir . '/' . self::$internal['cache_entry'] . '.data';
    }

    public static function getCacheDir(): string
    {
        if (!isset(self::$internal['cache_dir'])) {
            self::initInternals();
        }
        $dir = self::$internal['cache_dir'];
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        return $dir;
    }

    public static function clearInternalCache(): void
    {
        $count = 0;
        $dir = self::getCacheDir();
        foreach (scandir($dir) as $entry) {
            if (preg_match('/\.data$/', $entry)) {
                @unlink($dir . '/' . $entry);
                ++$count;
            }
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log(__FUNCTION__ . "=$count", LSLog::LEVEL_PURGE_EVENT);
        }
    }

    public static function genEsiElements(EsiItem $item): void
    {
        if (!isset(self::$internal['esi_base_url'])) {
            self::initInternals();
        }

        $url = self::$internal['esi_base_url'] . '&pd=' . urlencode($item->getId());
        $tagInline = '';
        $tagInclude = '';
        $ttl = $item->getTTL();

        if ($ttl === 0 || $ttl === '0') {
            $ccInclude = $ccInline = 'no-cache';
        } else {
            $isPrivate = $item->isPrivate();
            if ($ttl === '') {
                $ttl = $isPrivate ? self::$internal['priv_ttl'] : self::$internal['pub_ttl'];
            }
            $ccInclude = $isPrivate ? 'private' : 'public';
            $ccInclude .= ',no-vary';

            if ($item->onlyCacheEmpty() && $item->getContent() !== '') {
                $ccInline = 'no-cache';
                $ttl = 0;
            } else {
                $ccInline = $ccInclude . ',max-age=' . $ttl;
            }

            $tagInline = ' cache-tag=\'';
            $tagInline .= $isPrivate
                ? self::$internal['tag_shared_priv']
                : self::$internal['tag_shared_pub'];
            if ($tags = $item->getTagString(self::$internal['tag_prefix'] . '_')) {
                $tagInline .= ',' . $tags;
            }
            $tagInline .= '\'';
            if ($item->asVar()) {
                $tagInclude = $tagInline . ' as-var=\'1\'';
            }
        }

        $esiInclude = sprintf('<esi:include src=\'%s\' cache-control=\'%s\'%s/>', $url, $ccInclude, $tagInclude);
        $inlineStart = sprintf('<esi:inline name=\'%s\' cache-control=\'%s\'%s>', $url, $ccInline, $tagInline);
        $item->setIncludeInlineTag($esiInclude, $inlineStart, $url, $ttl);
    }

    public static function getTagPrefix(): string
    {
        return self::getInternalValue('tag_prefix');
    }

    public static function getCacheEntry(): string
    {
        return self::getInternalValue('cache_entry');
    }

    private static function getInternalValue(string $field): mixed
    {
        if (!isset(self::$internal[$field])) {
            self::initInternals();
        }

        return self::$internal[$field];
    }

    protected static function getFileContent(string $filepath): string
    {
        $contents = '';
        $len = @filesize($filepath);
        if ($len) {
            $h = @fopen($filepath, 'rb');
            $contents = @fread($h, $len);
            @fclose($h);
        }

        return $contents;
    }

    private static function genHtAccessContent(bool $guestMode, bool $mobileView): string
    {
        $ls = [];
        $ls[] = '### LITESPEED_CACHE_START - Do not remove this line, LSCache plugin will automatically update it';
        $ls[] = '# automatically genereated by LiteSpeedCache plugin: https://docs.litespeedtech.com/lscache/lscps/';
        $ls[] = '<IfModule LiteSpeed>';
        $ls[] = 'CacheLookup on';
        $mobileUA = 'phone|iPhone|iPod|BlackBerry|Palm|Googlebot-Mobile|Mobile|mobile|mobi|Windows_Mobile|Safari_Mobile|Android|Opera_Mini';

        if ($guestMode || $mobileView) {
            $ls[] = 'RewriteEngine on';
        }
        if ($guestMode) {
            $ls[] = 'RewriteCond %{HTTP_COOKIE} !PrestaShop-';
            $ls[] = 'RewriteRule .* - [E=Cache-Control:vary=guest]';
        }
        if ($mobileView) {
            $ls[] = '';
            $ls[] = '### marker MOBILE start ###';
            if ($guestMode) {
                $ls[] = 'RewriteCond %{HTTP_COOKIE} !PrestaShop-';
                $ls[] = 'RewriteCond %{HTTP_USER_AGENT} "' . $mobileUA . '" [NC]';
                $ls[] = 'RewriteRule .* - [E=Cache-Control:vary=guestm]';
                $ls[] = 'RewriteCond %{HTTP_COOKIE} PrestaShop-';
            }
            $ls[] = 'RewriteCond %{HTTP_USER_AGENT} "' . $mobileUA . '" [NC]';
            $ls[] = 'RewriteRule .* - [E=Cache-Control:vary=ismobile]';
            $ls[] = '### marker MOBILE end ###';
            $ls[] = '';
        }
        $ls[] = '</IfModule>';
        $ls[] = '### LITESPEED_CACHE_END';

        return implode("\n", $ls) . "\n";
    }

    public static function htAccessBackup(string $suffix): bool
    {
        $path = _PS_ROOT_DIR_ . '/.htaccess';
        $newfile = $path . '.' . $suffix . time();
        if (!file_exists($newfile)) {
            $content = self::getFileContent($path);
            if ($content) {
                $res = file_put_contents($newfile, $content);
                if ($res) {
                    LSLog::log(__FUNCTION__ . ' backed up as ' . $newfile, LSLog::LEVEL_UPDCONFIG);

                    return true;
                }
            }
        }

        return false;
    }

    public static function htAccessUpdateVaryCookies(string $loginCookie, string $varyCookies): bool
    {
        $htaccessPath = _PS_ROOT_DIR_ . '/.htaccess';
        if (!is_file($htaccessPath) || !is_writable($htaccessPath)) {
            return false;
        }

        $content = file_get_contents($htaccessPath);

        // Remove existing LSCache vary cookie block
        $content = preg_replace(
            '/\n?# BEGIN LSCache Vary Cookie.*?# END LSCache Vary Cookie\n?/s',
            '',
            $content
        );

        // Build new block
        $block = "\n# BEGIN LSCache Vary Cookie\n";
        $block .= '<IfModule LiteSpeed>' . "\n";
        $block .= 'CacheLookup on' . "\n";

        if ($loginCookie !== '_lscache_vary') {
            $block .= 'RewriteRule .* - [E="Cache-Vary:' . $loginCookie . '"]' . "\n";
        }

        if (!empty($varyCookies)) {
            $cookies = preg_split("/\r?\n/", $varyCookies, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($cookies as $cookie) {
                $cookie = trim($cookie);
                if ($cookie !== '') {
                    $block .= 'RewriteRule .* - [E="Cache-Vary:' . $cookie . '"]' . "\n";
                }
            }
        }

        $block .= '</IfModule>' . "\n";
        $block .= "# END LSCache Vary Cookie\n";

        if (preg_match('/(<IfModule mod_rewrite\.c>)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $content = substr($content, 0, $m[0][1]) . $block . substr($content, $m[0][1]);
        } else {
            $content .= $block;
        }

        file_put_contents($htaccessPath, $content);

        return true;
    }

    public static function htAccessUpdate(bool $enableCache, bool $guestMode, bool $mobileView): bool
    {
        $path = _PS_ROOT_DIR_ . '/.htaccess';
        $oldlines = file($path);
        if ($oldlines === false) {
            LSLog::log(__FUNCTION__ . ' please manually fix .htaccess, may due to permission', LSLog::LEVEL_FORCE);

            return false;
        }

        $newlines = [];
        $ind = false;
        foreach ($oldlines as $line) {
            if (!$ind) {
                if (strpos($line, 'LITESPEED_CACHE_START') !== false) {
                    $ind = true;
                } else {
                    $newlines[] = $line;
                }
            } else {
                if (strpos($line, 'LITESPEED_CACHE_END') !== false) {
                    $ind = false;
                }
            }
        }

        $newcontent = '';
        if ($enableCache) {
            $newcontent = self::genHtAccessContent($guestMode, $mobileView);
        }
        $newcontent .= implode('', $newlines);

        $res = file_put_contents($path, $newcontent);
        if ($res) {
            LSLog::log(__FUNCTION__ . ' updated', LSLog::LEVEL_UPDCONFIG);

            return true;
        }

        LSLog::log(__FUNCTION__ . ' cannot save! Please manually fix .htaccess file', LSLog::LEVEL_FORCE);

        return false;
    }

    public static function getRelatedItems($id): array
    {
        $items = [];
        $dir = '';
        $cacheFile = self::getCacheFilePath($dir);
        $snapshot = self::getFileContent($cacheFile);
        $saved = json_decode($snapshot, true);

        if (!is_array($saved)
            || json_last_error() !== JSON_ERROR_NONE
            || ($id != false && !isset($saved['data'][$id]))) {
            return $items;
        }

        $related = [];
        $tag = ($id) ? $saved['data'][$id]['tag'] : Conf::TAG_ENV;
        if ($tag === Conf::TAG_ENV) {
            $related = array_keys($saved['data']);
        } elseif (isset($saved['tags'][$tag])) {
            $related = array_keys($saved['tags'][$tag]);
        }

        foreach ($related as $rid) {
            if ($rid != $id) {
                $ri = EsiItem::newFromSavedData($saved['data'][$rid]);
                if ($ri !== null) {
                    $items[] = $ri;
                }
            }
        }

        return $items;
    }

    public static function syncItemCache(array $itemList): void
    {
        $dir = '';
        $cacheFile = self::getCacheFilePath($dir);
        $snapshot = self::getFileContent($cacheFile);
        $saved = json_decode($snapshot, true);

        if (!is_array($saved) || json_last_error() !== JSON_ERROR_NONE) {
            $saved = ['data' => [], 'tags' => []];
        }

        foreach ($itemList as $item) {
            if (!$item->isPrivate() || $item->noItemCache()) {
                continue;
            }
            $id = $item->getId();
            $descr = $item->getInfoLog(true);
            $saved['data'][$id] = $item->getSavedData();
            $tags = $item->getTags();

            if ($tags[0] === Conf::TAG_ENV) {
                continue;
            }
            foreach ($tags as $tag) {
                if (!isset($saved['tags'][$tag])) {
                    $saved['tags'][$tag] = [$id => $descr];
                } elseif (!isset($saved['tags'][$tag][$id])) {
                    $saved['tags'][$tag][$id] = $descr;
                }
            }
        }

        ksort($saved['data']);
        ksort($saved['tags']);
        $newsnapshot = json_encode($saved, JSON_UNESCAPED_SLASHES);
        if ($snapshot !== $newsnapshot) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_SAVED_DATA) {
                LSLog::log(__FUNCTION__ . " $cacheFile updated data " . var_export($saved, true), LSLog::LEVEL_SAVED_DATA);
            }
            file_put_contents($cacheFile, $newsnapshot);
        }
    }

    public static function isStaticResource(string $url): bool
    {
        return (bool) preg_match('/(js|css|jpg|png|svg|gif|woff|woff2)$/', $url);
    }

    public static function licenseEnabled(): bool
    {
        return (isset($_SERVER['X-LSCACHE']) && $_SERVER['X-LSCACHE'])
            || (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']);
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'disabled';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . ' hours';
        }
        if ($seconds < 604800) {
            return round($seconds / 86400, 1) . ' days';
        }

        return round($seconds / 604800, 1) . ' weeks';
    }
}
