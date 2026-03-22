<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * ExclusionsConfig — stores cache exclusion settings that extend CacheConfig.
 *
 * Note: nocache_urls and nocache_vars are managed by CacheConfig (ENTRY_ALL).
 * This class stores the remaining exclusion settings.
 */
class ExclusionsConfig
{
    const ENTRY = 'LITESPEED_CACHE_EXCL';

    const EXCL_NOCACHE_CATS    = 'exc_nocache_cats';
    const EXCL_NOCACHE_COOKIES = 'exc_nocache_cookies';
    const EXCL_NOCACHE_UA      = 'exc_nocache_ua';
    const EXCL_NOCACHE_ROLES   = 'exc_nocache_roles';

    /** @var array|null */
    private static $data = null;

    public static function getAll(): array
    {
        if (self::$data === null) {
            $raw        = \Configuration::getGlobalValue(self::ENTRY);
            $decoded    = $raw ? json_decode($raw, true) : [];
            self::$data = array_merge(self::getDefaults(), is_array($decoded) ? $decoded : []);
        }

        return self::$data;
    }

    public static function get(string $key)
    {
        return self::getAll()[$key] ?? null;
    }

    public static function saveAll(array $data): void
    {
        self::$data = $data;
        \Configuration::updateGlobalValue(self::ENTRY, json_encode($data));
    }

    public static function getDefaults(): array
    {
        return [
            self::EXCL_NOCACHE_CATS    => '',
            self::EXCL_NOCACHE_COOKIES => '',
            self::EXCL_NOCACHE_UA      => '',
            self::EXCL_NOCACHE_ROLES   => [],
        ];
    }

    /**
     * Returns cookie names as an array (non-empty lines).
     */
    public static function getNoCacheCookies(): array
    {
        $raw = self::get(self::EXCL_NOCACHE_COOKIES);
        return self::splitLines((string) $raw);
    }

    /**
     * Returns user agent strings as an array (non-empty lines).
     */
    public static function getNoCacheUAs(): array
    {
        $raw = self::get(self::EXCL_NOCACHE_UA);
        return self::splitLines((string) $raw);
    }

    /**
     * Returns excluded category IDs as an array of integers.
     */
    public static function getNoCacheCatIds(): array
    {
        $raw = self::get(self::EXCL_NOCACHE_CATS);
        $ids = [];
        foreach (self::splitLines((string) $raw) as $line) {
            if (is_numeric($line)) {
                $ids[] = (int) $line;
            }
        }
        return $ids;
    }

    /**
     * Returns excluded customer group IDs as an array of integers.
     */
    public static function getNoCacheRoles(): array
    {
        $roles = self::get(self::EXCL_NOCACHE_ROLES);
        return is_array($roles) ? array_map('intval', $roles) : [];
    }

    public static function reset(): void
    {
        self::$data = null;
    }

    private static function splitLines(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $value)),
            static function (string $line) { return $line !== ''; }
        ));
    }
}
