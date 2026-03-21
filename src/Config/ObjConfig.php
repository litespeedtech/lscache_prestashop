<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Config;

/**
 * ObjConfig — stores object cache integration settings globally.
 */
class ObjConfig
{
    const ENTRY = 'LITESPEED_CACHE_OBJ';

    const OBJ_ENABLE         = 'obj_enable';
    const OBJ_METHOD         = 'obj_method';
    const OBJ_HOST           = 'obj_host';
    const OBJ_PORT           = 'obj_port';
    const OBJ_TTL            = 'obj_ttl';
    const OBJ_USERNAME       = 'obj_username';
    const OBJ_PASSWORD       = 'obj_password';
    const OBJ_REDIS_DB       = 'obj_redis_db';
    const OBJ_GLOBAL_GROUPS  = 'obj_global_groups';
    const OBJ_NOCACHE_GROUPS = 'obj_nocache_groups';
    const OBJ_PERSISTENT     = 'obj_persistent';
    const OBJ_ADMIN_CACHE    = 'obj_admin_cache';

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
            self::OBJ_ENABLE         => 0,
            self::OBJ_METHOD         => 'redis',
            self::OBJ_HOST           => 'localhost',
            self::OBJ_PORT           => 6379,
            self::OBJ_TTL            => 360,
            self::OBJ_USERNAME       => '',
            self::OBJ_PASSWORD       => '',
            self::OBJ_REDIS_DB       => 0,
            self::OBJ_GLOBAL_GROUPS  => "configuration\ncurrencies\nlanguages\ncountries\nzones\ngroups\ncarriers\nhook\nmodule\ncms\ncategory",
            self::OBJ_NOCACHE_GROUPS => "cart\ncustomer\norder",
            self::OBJ_PERSISTENT     => 0,
            self::OBJ_ADMIN_CACHE    => 0,
        ];
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
