<?php

namespace LiteSpeed\Cache\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

class WarmupConfig
{
    public const ENTRY = 'LITESPEED_WARMUP_SETTINGS';

    public const CRAWL_DELAY = 'crawl_delay';
    public const CONCURRENT_REQUESTS = 'concurrent';
    public const CRAWL_TIMEOUT = 'crawl_timeout';
    public const SERVER_LOAD_LIMIT = 'load_limit';
    public const MOBILE_CRAWL = 'mobile_crawl';
    public const MOBILE_USER_AGENT = 'mobile_useragent';

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
            self::CRAWL_DELAY => 500,
            self::CONCURRENT_REQUESTS => 3,
            self::CRAWL_TIMEOUT => 30,
            self::SERVER_LOAD_LIMIT => 1.0,
            self::MOBILE_CRAWL => 0,
            self::MOBILE_USER_AGENT => 'lscache_runner Mobile Safari/537.36 iPhone',
        ];
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
