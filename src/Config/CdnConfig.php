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
 * CdnConfig — stores Cloudflare / CDN integration settings globally.
 */
class CdnConfig
{
    public const ENTRY = 'LITESPEED_CACHE_CDN';

    public const CF_ENABLE = 'cf_enable';
    public const CF_KEY = 'cf_key';
    public const CF_EMAIL = 'cf_email';
    public const CF_DOMAIN = 'cf_domain';
    public const CF_PURGE = 'cf_purge';
    public const CF_ZONE_ID = 'cf_zone_id';

    /** @var array|null */
    private static $data;

    public static function getAll(): array
    {
        if (self::$data === null) {
            $raw = \Configuration::getGlobalValue(self::ENTRY);
            $decoded = $raw ? json_decode($raw, true) : [];
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
            self::CF_ENABLE => 0,
            self::CF_KEY => '',
            self::CF_EMAIL => '',
            self::CF_DOMAIN => '',
            self::CF_PURGE => 0,
            self::CF_ZONE_ID => '',
        ];
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
