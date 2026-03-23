<?php
/**
 * LiteSpeed Cache — Cache override (PS 8 / PS 9 compatible).
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 *
 * When _PS_CACHING_SYSTEM_ is 'CacheRedis', loads the module's Composer
 * autoloader and instantiates the namespaced Redis driver. All other
 * caching systems fall through to the parent implementation.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class Cache extends CacheCore
{
    public static function getInstance()
    {
        if (!self::$instance) {
            $caching_system = _PS_CACHING_SYSTEM_;

            if ($caching_system === 'CacheRedis') {
                $autoloader = _PS_MODULE_DIR_ . 'litespeedcache/vendor/autoload.php';
                if (!class_exists(LiteSpeed\Cache\Cache\CacheRedis::class, false)) {
                    if (is_file($autoloader)) {
                        require_once $autoloader;
                    }
                }
                if (class_exists(LiteSpeed\Cache\Cache\CacheRedis::class)) {
                    self::$instance = new LiteSpeed\Cache\Cache\CacheRedis();

                    return self::$instance;
                }

                // Module disabled or missing — disable cache entirely
                return null;
            }

            if (class_exists($caching_system)) {
                self::$instance = new $caching_system();
            }
        }

        return self::$instance;
    }
}
