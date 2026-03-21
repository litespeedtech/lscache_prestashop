<?php
/**
 * LiteSpeed Cache — Cache override (PS 8 / PS 9 compatible).
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
                if (!class_exists(\LiteSpeed\Cache\Cache\CacheRedis::class)) {
                    require_once _PS_MODULE_DIR_ . 'litespeedcache/vendor/autoload.php';
                }
                self::$instance = new \LiteSpeed\Cache\Cache\CacheRedis();

                return self::$instance;
            }

            if (class_exists($caching_system)) {
                self::$instance = new $caching_system();
            }
        }

        return self::$instance;
    }
}
