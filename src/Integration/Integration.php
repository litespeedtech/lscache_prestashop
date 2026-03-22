<?php

/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Integration;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Esi\EsiModuleConfig;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/*
 * Integration — abstract base class for third-party module ESI integrations.
 * Subclasses extend LscIntegration (global alias) which maps to this class.
 */
abstract class Integration
{
    /** @var array Static registry of integrated modules and shared state */
    protected static $integrated = [];

    protected $moduleName;

    /** @var EsiModuleConfig|null */
    protected $esiConf;

    protected function __construct()
    {
    }

    public static function isUsed($name)
    {
        return \Module::isEnabled($name);
    }

    public static function register(): void
    {
        $className = get_called_class();
        $name = $className::NAME;
        if ($className::isUsed($name)) {
            $instance = new $className();
            if ($instance->init()) {
                self::$integrated[$name] = ['class' => $instance];
            }
        }
    }

    protected function addJsDef(string $jsk, $proc): void
    {
        if (!isset(self::$integrated['jsdef'])) {
            self::$integrated['jsdef'] = [];
            self::$integrated['jsloc'] = [];
        }
        self::$integrated['jsdef'][$jsk] = ['proc' => $proc];
        $locator = explode(':', $jsk);
        $cur = &self::$integrated['jsloc'];
        while ($key = array_shift($locator)) {
            if (!empty($locator)) {
                $cur[$key] = [];
                $cur = &$cur[$key];
            } else {
                $cur[$key] = $jsk;
            }
        }
    }

    protected function addCacheableControllers(array $controllers): void
    {
        CacheConfig::getInstance()->addExtraPubControllers($controllers);
    }

    protected function registerEsiModule(): bool
    {
        if ($this->esiConf instanceof EsiModuleConfig) {
            CacheConfig::getInstance()->registerEsiModule($this->esiConf);

            return true;
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_NOTICE) {
            LSLog::log(__FUNCTION__ . 'something wrong', LSLog::LEVEL_NOTICE);
        }

        return false;
    }

    protected static function filterCurrentJSKeyVal(string $key, &$val, array &$injected, string &$log): bool
    {
        $def = &self::$integrated['jsdef'];
        if (!isset($def[$key])) {
            return false;
        }
        if (!isset($def[$key]['replace'])) {
            $proc = $def[$key]['proc'];
            $esiParam = [
                'pt' => EsiItem::ESI_JSDEF,
                'm' => $proc::NAME,
                'jsk' => $key,
            ];
            $log .= $proc::NAME . ':' . $key . ' ';

            $item = new EsiItem($esiParam, $proc->esiConf);
            $id = $item->getId();
            $injected[$id] = $item;
            $def[$key]['replace'] = '_LSCESIJS-' . $id . '-START__LSCESIEND_';
            $def[$key]['value'] = json_encode($val);
        }
        $val = $def[$key]['replace'];

        return true;
    }

    private static function locateJSKey(string $key, &$js, &$loc, array &$injected, string &$log): void
    {
        if (!isset($loc[$key])) {
            return;
        }
        if (is_array($loc[$key]) && is_array($js)) {
            $loc = &$loc[$key];
            foreach ($js as $key2 => &$js2) {
                self::locateJSKey($key2, $js2, $loc, $injected, $log);
            }
        } else {
            $curkey = $loc[$key];
            self::filterCurrentJSKeyVal($curkey, $js, $injected, $log);
        }
    }

    public static function filterJSDef(array &$jsDef): ?array
    {
        if (!isset(self::$integrated['jsloc']) || !is_array($jsDef)) {
            return null;
        }

        $injected = [];
        $log = '';

        foreach ($jsDef as $key => &$js) {
            $loc = &self::$integrated['jsloc'];
            self::locateJSKey($key, $js, $loc, $injected, $log);
        }

        if ($log && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log('filter JSDef = ' . $log, LSLog::LEVEL_ESI_INCLUDE);
        }

        return $injected;
    }

    public static function processJsDef(EsiItem $item): void
    {
        $name = $item->getParam('m');
        if (isset(self::$integrated[$name])) {
            $key = $item->getParam('jsk');
            if (isset(self::$integrated['jsdef'][$key]['value'])) {
                $item->setContent(self::$integrated['jsdef'][$key]['value']);

                return;
            }
            $proc = self::$integrated[$name]['class'];
            if (method_exists($proc, 'jsKeyProcess')) {
                $item->setContent($proc->jsKeyProcess($key));

                return;
            }
        }
        $item->setFailed();
    }

    public static function processModField(EsiItem $item): void
    {
        $name = $item->getParam('m');
        if (isset(self::$integrated[$name])) {
            $proc = self::$integrated[$name]['class'];
            if (method_exists($proc, 'moduleFieldProcess')) {
                $content = $proc->moduleFieldProcess($item->getParam());
                $item->setContent($content);

                return;
            }
        }
        $item->setFailed();
    }

    protected function addPreDispatchAction($proc): void
    {
        if (!isset(self::$integrated['predispatch'])) {
            self::$integrated['predispatch'] = [];
        }
        self::$integrated['predispatch'][] = $proc;
    }

    public static function preDispatchAction(): void
    {
        if (isset(self::$integrated['predispatch'])) {
            foreach (self::$integrated['predispatch'] as $proc) {
                $proc->actionPreDispatch();
            }
        }
    }

    protected function addCheckPurgeControllerCustomHandler(string $controller_class, $proc): void
    {
        $c = \Tools::strtolower($controller_class);
        if (!isset(self::$integrated['checkpurgecontroller'])) {
            self::$integrated['checkpurgecontroller'] = [];
        }
        if (!isset(self::$integrated['checkpurgecontroller'][$c])) {
            self::$integrated['checkpurgecontroller'][$c] = [$proc];
        } else {
            self::$integrated['checkpurgecontroller'][$c][] = $proc;
        }
    }

    public static function checkPurgeController(string $lowercase_controller_class, array &$tags): void
    {
        if (isset(self::$integrated['checkpurgecontroller'][$lowercase_controller_class])) {
            foreach (self::$integrated['checkpurgecontroller'][$lowercase_controller_class] as $proc) {
                $proc->checkPurgeControllerCustomHandler($lowercase_controller_class, $tags);
            }
        }
    }

    protected function addInitCacheTagAction($proc): void
    {
        if (!isset(self::$integrated['initCacheTag'])) {
            self::$integrated['initCacheTag'] = [];
        }
        self::$integrated['initCacheTag'][] = $proc;
    }

    public static function initCacheTagAction(array $params)
    {
        if (isset(self::$integrated['initCacheTag'])) {
            foreach (self::$integrated['initCacheTag'] as $proc) {
                $tag = $proc->initCacheTagsByController($params);
                if ($tag !== null) {
                    return $tag;
                }
            }
        }

        return null;
    }

    protected function isLoggedIn(): bool
    {
        $context = \Context::getContext();

        return ($context->customer !== null) && $context->customer->isLogged();
    }

    abstract protected function init();
}
