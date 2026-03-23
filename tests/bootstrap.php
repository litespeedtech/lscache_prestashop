<?php
/**
 * PHPUnit bootstrap — stubs for PrestaShop global classes.
 * Allows unit-testing module code without a running PrestaShop instance.
 */

// PrestaShop constants
if (!defined('_PS_VERSION_')) define('_PS_VERSION_', '8.2.3');
if (!defined('_PS_ROOT_DIR_')) define('_PS_ROOT_DIR_', sys_get_temp_dir());
if (!defined('_PS_MODULE_DIR_')) define('_PS_MODULE_DIR_', __DIR__ . '/../');
if (!defined('_PS_ADMIN_DIR_')) define('_PS_ADMIN_DIR_', sys_get_temp_dir() . '/admin');
if (!defined('_PS_CACHE_DIR_')) define('_PS_CACHE_DIR_', sys_get_temp_dir() . '/cache/');
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_', 'ps_');
if (!defined('_PS_USE_SQL_SLAVE_')) define('_PS_USE_SQL_SLAVE_', false);
if (!defined('_LITESPEED_CACHE_')) define('_LITESPEED_CACHE_', 1);
if (!defined('_LITESPEED_DEBUG_')) define('_LITESPEED_DEBUG_', 0);

// ---- Configuration stub ----
if (!class_exists('Configuration')) {
    class Configuration
    {
        private static $store = [];

        public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
        {
            return self::$store[$key] ?? false;
        }

        public static function getGlobalValue($key)
        {
            return self::$store[$key] ?? false;
        }

        public static function updateValue($key, $value)
        {
            self::$store[$key] = $value;
            return true;
        }

        public static function updateGlobalValue($key, $value)
        {
            self::$store[$key] = $value;
            return true;
        }

        public static function deleteByName($key)
        {
            unset(self::$store[$key]);
            return true;
        }

        // Test helpers
        public static function seed(array $data): void
        {
            self::$store = array_merge(self::$store, $data);
        }

        public static function clear(): void
        {
            self::$store = [];
        }
    }
}

// ---- Validate stub ----
if (!class_exists('Validate')) {
    class Validate
    {
        public static function isUnsignedInt($value): bool
        {
            return ctype_digit((string) $value) && (int) $value >= 0;
        }
    }
}

// ---- Shop stub ----
if (!class_exists('Shop')) {
    class Shop
    {
        const CONTEXT_ALL = 1;
        const CONTEXT_GROUP = 2;
        const CONTEXT_SHOP = 4;

        public static function getContext() { return self::CONTEXT_ALL; }
        public static function isFeatureActive() { return false; }
    }
}

// ---- Db stub ----
if (!class_exists('Db')) {
    class Db
    {
        private static $instance;
        public static function getInstance($slave = false) {
            if (!self::$instance) self::$instance = new self();
            return self::$instance;
        }
        public function getValue($sql) { return ''; }
        public function executeS($sql) { return []; }
        public function execute($sql) { return true; }
        public function getRow($sql) { return []; }
    }
}

// ---- DbQuery stub ----
if (!class_exists('DbQuery')) {
    class DbQuery
    {
        public function select($v) { return $this; }
        public function from($v, $a = null) { return $this; }
        public function innerJoin($t, $a = null, $on = null) { return $this; }
        public function leftJoin($t, $a = null, $on = null) { return $this; }
        public function where($v) { return $this; }
        public function orderBy($v) { return $this; }
        public function limit($v, $o = 0) { return $this; }
    }
}

// ---- Module stub ----
if (!class_exists('Module')) {
    class Module
    {
        public $name = 'litespeedcache';
        public $version = '2.0.0';
        public $active = true;

        public static function getInstanceByName($name) {
            $m = new self();
            $m->name = $name;
            return $m;
        }

        public static function getModulesInstalled() { return []; }
    }
}

// ---- Hook stub ----
if (!class_exists('Hook')) {
    class Hook
    {
        public static function exec($hookName, $params = []) { return ''; }
    }
}

// ---- Tools stub ----
if (!class_exists('Tools')) {
    class Tools
    {
        public static function clearSf2Cache() { return true; }
        public static function clearCache() { return true; }
        public static function getShopDomainSsl($withScheme = false) { return 'localhost'; }
    }
}

// ---- Context stub ----
if (!class_exists('Context')) {
    class Context
    {
        public $shop;
        public $language;
        public $link;
        private static $instance;

        public static function getContext() {
            if (!self::$instance) {
                self::$instance = new self();
                self::$instance->shop = (object) ['id' => 1];
                self::$instance->language = (object) ['id' => 1];
            }
            return self::$instance;
        }
    }
}

// ---- FileLogger stub ----
if (!class_exists('FileLogger')) {
    class FileLogger
    {
        const DEBUG = 0;
        public function __construct($level = 0) {}
        public function setFilename($f) {}
        public function logDebug($msg) {}
    }
}

// ---- PrestaShopLogger stub ----
if (!class_exists('PrestaShopLogger')) {
    class PrestaShopLogger
    {
        public static function addLog($msg, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false) {}
    }
}

// ---- Twig\Markup stub (for NavPillsTrait) ----
if (!class_exists('Twig\Markup')) {
    // Already available via composer — skip
}

// ---- Symfony / PrestaShop Bundle stubs ----
if (!class_exists('Symfony\Component\HttpFoundation\Response')) {
    eval('namespace Symfony\Component\HttpFoundation; class Response {}');
    eval('namespace Symfony\Component\HttpFoundation; class Request {}');
    eval('namespace Symfony\Component\HttpFoundation; class JsonResponse extends Response {}');
}
if (!class_exists('Symfony\Bundle\FrameworkBundle\Controller\AbstractController')) {
    eval('namespace Symfony\Bundle\FrameworkBundle\Controller; abstract class AbstractController {
        protected function trans($id, $domain = null, $params = []) { return $id; }
        protected function addFlash($type, $msg) {}
        protected function redirectToRoute($route) { return new \Symfony\Component\HttpFoundation\Response(); }
        protected function redirect($url) { return new \Symfony\Component\HttpFoundation\Response(); }
        protected function render($template, $params = []) { return new \Symfony\Component\HttpFoundation\Response(); }
        protected function generateUrl($route, $params = []) { return "/"; }
        protected function createForm($type) { return null; }
        public function get($service) { return null; }
    }');
}

if (!class_exists('PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController')) {
    eval('namespace PrestaShopBundle\Controller\Admin; class FrameworkBundleAdminController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController {}');
}

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';
