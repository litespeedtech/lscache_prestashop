<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
namespace LiteSpeed\Cache\Config;

use LiteSpeed\Cache\Esi\EsiModuleConfig as EsiConf;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Core\CacheState;

/**
 * CacheConfig — singleton that stores all LiteSpeedCache configuration.
 * Manages global, per-shop and per-module (ESI) configuration.
 */
class CacheConfig
{
    // public tag prefix
    const TAG_PREFIX_CMS          = 'G';
    const TAG_PREFIX_CATEGORY     = 'C';
    const TAG_PREFIX_PRODUCT      = 'P';
    const TAG_PREFIX_ESIBLOCK     = 'E';
    const TAG_PREFIX_MANUFACTURER = 'M';
    const TAG_PREFIX_SUPPLIER     = 'L';
    const TAG_PREFIX_SHOP         = 'S';
    const TAG_PREFIX_PCOMMENTS    = 'N';
    const TAG_PREFIX_PRIVATE      = 'PRIV';

    // public tags
    const TAG_SEARCH  = 'SR';
    const TAG_HOME    = 'H';
    const TAG_SITEMAP = 'SP';
    const TAG_STORES  = 'ST';
    const TAG_404     = 'D404';

    // common private tags
    const TAG_CART   = 'cart';
    const TAG_SIGNIN = 'signin';
    const TAG_ENV    = 'env';

    // config entry
    const ENTRY_ALL    = 'LITESPEED_CACHE_GLOBAL';
    const ENTRY_SHOP   = 'LITESPEED_CACHE_SHOP';
    const ENTRY_MODULE = 'LITESPEED_CACHE_MODULE';

    // config fields
    const CFG_ENABLED          = 'enable';
    const CFG_PUBLIC_TTL       = 'ttl';
    const CFG_PRIVATE_TTL      = 'privttl';
    const CFG_404_TTL          = '404ttl';
    const CFG_HOME_TTL         = 'homettl';
    const CFG_PCOMMENTS_TTL    = 'pcommentsttl';
    const CFG_DIFFMOBILE       = 'diff_mobile';
    const CFG_DIFFCUSTGRP      = 'diff_customergroup';
    const CFG_FLUSH_PRODCAT    = 'flush_prodcat';
    const CFG_FLUSH_ALL        = 'flush_all';
    const CFG_FLUSH_HOME       = 'flush_home';
    const CFG_FLUSH_HOME_INPUT = 'flush_homeinput';
    const CFG_GUESTMODE        = 'guestmode';
    const CFG_NOCACHE_VAR      = 'nocache_vars';
    const CFG_NOCACHE_URL      = 'nocache_urls';
    const CFG_VARY_BYPASS      = 'vary_bypass';
    const CFG_DEBUG            = 'debug';
    const CFG_DEBUG_HEADER     = 'debug_header';
    const CFG_DEBUG_LEVEL      = 'debug_level';
    const CFG_ALLOW_IPS        = 'allow_ips';
    const CFG_DEBUG_IPS        = 'debug_ips';
    const CFG_DEBUG_URI_INC    = 'debug_uri_inc';
    const CFG_DEBUG_URI_EXC    = 'debug_uri_exc';
    const CFG_DEBUG_STR_EXC    = 'debug_str_exc';

    private $esiModConf;
    private $pubController  = [];
    private $purgeController = [];
    private $all;
    private $shop;
    private $custMod;
    private $enforceDiffGroup = 0;
    private $isDebug          = 0;
    private $flushHomePids    = null;

    /** @var self|null */
    private static $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function get($configField)
    {
        if ($this->all === null) {
            $this->init();
        }

        switch ($configField) {
            case self::ENTRY_ALL:
                return array_replace($this->getDefaultConfData(self::ENTRY_ALL), $this->all);
            case self::CFG_ENABLED:
            case self::CFG_DIFFMOBILE:
            case self::CFG_GUESTMODE:
            case self::CFG_NOCACHE_VAR:
            case self::CFG_NOCACHE_URL:
            case self::CFG_VARY_BYPASS:
            case self::CFG_FLUSH_PRODCAT:
            case self::CFG_FLUSH_ALL:
            case self::CFG_FLUSH_HOME:
            case self::CFG_FLUSH_HOME_INPUT:
            case self::CFG_DEBUG:
            case self::CFG_DEBUG_HEADER:
            case self::CFG_DEBUG_LEVEL:
            case self::CFG_ALLOW_IPS:
            case self::CFG_DEBUG_IPS:
            case self::CFG_DEBUG_URI_INC:
            case self::CFG_DEBUG_URI_EXC:
            case self::CFG_DEBUG_STR_EXC:
                if (!isset($this->all[$configField])) {
                    $this->all = array_replace($this->getDefaultConfData(self::ENTRY_ALL), $this->all);
                }
                return $this->all[$configField];
            case self::ENTRY_SHOP:
                return array_replace($this->getDefaultConfData(self::ENTRY_SHOP), $this->shop);
            case self::CFG_PUBLIC_TTL:
            case self::CFG_PRIVATE_TTL:
            case self::CFG_HOME_TTL:
            case self::CFG_404_TTL:
            case self::CFG_PCOMMENTS_TTL:
            case self::CFG_DIFFCUSTGRP:
                if (!isset($this->shop[$configField])) {
                    $this->shop = array_replace($this->getDefaultConfData(self::ENTRY_SHOP), $this->shop);
                }
                return $this->shop[$configField];
            case self::ENTRY_MODULE:
                return $this->esiModConf['mods'];
        }

        return null;
    }

    public function overrideGuestMode(): void
    {
        if ($this->all[self::CFG_GUESTMODE] == 1) {
            $this->all[self::CFG_GUESTMODE] = 2;
        }
    }

    public function getArray($configField): array
    {
        if (($value = $this->get($configField)) != '') {
            return preg_split("/[\s,]+/", $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        return [];
    }

    public function getDefaultConfData(string $key): array
    {
        if ($key === self::ENTRY_ALL) {
            return [
                self::CFG_ENABLED          => 0,
                self::CFG_DIFFMOBILE       => 0,
                self::CFG_GUESTMODE        => 1,
                self::CFG_NOCACHE_VAR      => '',
                self::CFG_NOCACHE_URL      => '',
                self::CFG_VARY_BYPASS      => '',
                self::CFG_FLUSH_PRODCAT    => 0,
                self::CFG_FLUSH_ALL        => 0,
                self::CFG_FLUSH_HOME       => 0,
                self::CFG_FLUSH_HOME_INPUT => '',
                self::CFG_DEBUG            => 0,
                self::CFG_DEBUG_HEADER     => 0,
                self::CFG_DEBUG_LEVEL      => 9,
                self::CFG_ALLOW_IPS        => '',
                self::CFG_DEBUG_IPS        => '',
                self::CFG_DEBUG_URI_INC    => '',
                self::CFG_DEBUG_URI_EXC    => '',
                self::CFG_DEBUG_STR_EXC    => '',
            ];
        } elseif ($key === self::ENTRY_SHOP) {
            return [
                self::CFG_PUBLIC_TTL    => 86400,
                self::CFG_PRIVATE_TTL   => 1800,
                self::CFG_HOME_TTL      => 86400,
                self::CFG_404_TTL       => 86400,
                self::CFG_PCOMMENTS_TTL => 7200,
                self::CFG_DIFFCUSTGRP   => 2,
            ];
        }
        return [];
    }

    public function getAllConfigValues(): array
    {
        return array_merge($this->get(self::ENTRY_ALL), $this->get(self::ENTRY_SHOP));
    }

    public function saveModConfigValues(array $currentEntry, string $action)
    {
        if (empty($currentEntry['id'])) {
            return false;
        }
        $id   = $currentEntry['id'];
        $item = $this->getEsiModuleConf($id);
        if ($item !== null && !$item->isCustomized()) {
            return false;
        }

        $oldevents = array_keys($this->esiModConf['purge_events']);

        if ($action === 'new' || $action === 'edit') {
            $newitem = new EsiConf($id, EsiConf::TYPE_CUSTOMIZED, $currentEntry);
            $this->esiModConf['mods'][$id] = $newitem;
        } elseif ($action === 'delete') {
            unset($this->esiModConf['mods'][$id]);
        } else {
            return false;
        }

        $newMod    = [];
        $newevents = [];
        foreach ($this->esiModConf['mods'] as $mi) {
            if (($events = $mi->getPurgeEvents()) !== null) {
                $newevents = array_merge($newevents, $events);
            }
            if ($mi->isCustomized()) {
                $newMod[$mi->getModuleName()] = $mi;
            }
        }
        $newevents = array_unique($newevents);

        if (!empty($newMod)) {
            ksort($newMod);
        }
        $newModValue = json_encode($newMod);
        if ($newModValue !== $this->custMod) {
            $this->updateConfiguration(self::ENTRY_MODULE, $newModValue);
        }

        $builtin = $this->getReservedHooks();
        $added   = array_diff($newevents, $oldevents, $builtin);
        $removed = array_diff($oldevents, $newevents, $builtin);

        if (!empty($added) || !empty($removed)) {
            $mymod = \Module::getInstanceByName('litespeedcache');
            foreach ($added as $a) {
                $res = $mymod->registerHook($a);
                if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
                    LSLog::log('in registerHook ' . $a . '=' . $res, LSLog::LEVEL_UPDCONFIG);
                }
            }
            foreach ($removed as $r) {
                $res = $mymod->unregisterHook($r);
                if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
                    LSLog::log('in unregisterHook ' . $r . '=' . $res, LSLog::LEVEL_UPDCONFIG);
                }
            }
            return 2;
        }

        return 1;
    }

    public function updateConfiguration(string $key, $values)
    {
        if ($this->isDebug >= LSLog::LEVEL_UPDCONFIG) {
            LSLog::log('in updateConfiguration context=' . \Shop::getContext()
                . " key = $key value = " . var_export($values, true), LSLog::LEVEL_UPDCONFIG);
        }
        switch ($key) {
            case self::ENTRY_ALL:
                $this->all = [
                    self::CFG_ENABLED          => $values[self::CFG_ENABLED],
                    self::CFG_DIFFMOBILE       => $values[self::CFG_DIFFMOBILE],
                    self::CFG_GUESTMODE        => $values[self::CFG_GUESTMODE],
                    self::CFG_NOCACHE_VAR      => $values[self::CFG_NOCACHE_VAR],
                    self::CFG_NOCACHE_URL      => $values[self::CFG_NOCACHE_URL],
                    self::CFG_VARY_BYPASS      => $values[self::CFG_VARY_BYPASS],
                    self::CFG_FLUSH_PRODCAT    => $values[self::CFG_FLUSH_PRODCAT],
                    self::CFG_FLUSH_ALL        => $values[self::CFG_FLUSH_ALL],
                    self::CFG_FLUSH_HOME       => $values[self::CFG_FLUSH_HOME],
                    self::CFG_FLUSH_HOME_INPUT => $values[self::CFG_FLUSH_HOME_INPUT],
                    self::CFG_DEBUG            => $values[self::CFG_DEBUG],
                    self::CFG_DEBUG_HEADER     => $values[self::CFG_DEBUG_HEADER],
                    self::CFG_DEBUG_LEVEL      => $values[self::CFG_DEBUG_LEVEL],
                    self::CFG_ALLOW_IPS        => $values[self::CFG_ALLOW_IPS],
                    self::CFG_DEBUG_IPS        => $values[self::CFG_DEBUG_IPS],
                    self::CFG_DEBUG_URI_INC    => $values[self::CFG_DEBUG_URI_INC] ?? '',
                    self::CFG_DEBUG_URI_EXC    => $values[self::CFG_DEBUG_URI_EXC] ?? '',
                    self::CFG_DEBUG_STR_EXC    => $values[self::CFG_DEBUG_STR_EXC] ?? '',
                ];
                \Configuration::updateGlobalValue(self::ENTRY_ALL, json_encode($this->all));
                break;
            case self::ENTRY_SHOP:
                $this->shop = [
                    self::CFG_PUBLIC_TTL    => $values[self::CFG_PUBLIC_TTL],
                    self::CFG_PRIVATE_TTL   => $values[self::CFG_PRIVATE_TTL],
                    self::CFG_HOME_TTL      => $values[self::CFG_HOME_TTL],
                    self::CFG_404_TTL       => $values[self::CFG_404_TTL],
                    self::CFG_PCOMMENTS_TTL => $values[self::CFG_PCOMMENTS_TTL],
                    self::CFG_DIFFCUSTGRP   => $values[self::CFG_DIFFCUSTGRP],
                ];
                \Configuration::updateValue(self::ENTRY_SHOP, json_encode($this->shop));
                break;
            case self::ENTRY_MODULE:
                $this->custMod = $values;
                \Configuration::updateValue(self::ENTRY_MODULE, $this->custMod);
                break;
            default:
                return false;
        }
        return true;
    }

    public function isDebug($requiredLevel = 0)
    {
        $this->get(self::CFG_DEBUG);
        return ($this->isDebug < $requiredLevel) ? 0 : $this->isDebug;
    }

    private function init(): void
    {
        $this->all = json_decode(\Configuration::getGlobalValue(self::ENTRY_ALL), true);
        if (!$this->all) {
            LSLog::log('Config not exist yet or decode err', LSLog::LEVEL_FORCE);
            $this->all = $this->getDefaultConfData(self::ENTRY_ALL);
        }

        if ($this->all[self::CFG_DEBUG]) {
            $ips = $this->getArray(self::CFG_DEBUG_IPS);
            if (empty($ips) || in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                $this->isDebug = $this->all[self::CFG_DEBUG_LEVEL];
            }
        }

        $this->shop = json_decode(\Configuration::get(self::ENTRY_SHOP), true);
        if (!$this->shop) {
            $this->shop = $this->getDefaultConfData(self::ENTRY_SHOP);
        }

        $this->addDefaultPurgeControllers();

        $this->custMod    = \Configuration::get(self::ENTRY_MODULE);
        $this->esiModConf = ['mods' => [], 'purge_events' => []];
        $custdata = json_decode($this->custMod, true);
        if ($custdata) {
            foreach ($custdata as $name => $sdata) {
                $esiconf = new EsiConf($name, EsiConf::TYPE_CUSTOMIZED, $sdata);
                $this->registerEsiModule($esiconf);
            }
        }

        $this->addExtraPubControllers([
            'IndexController'                                       => self::TAG_HOME,
            'ProductController'                                     => '',
            'CategoryController'                                    => '',
            'prestablogblogModuleFrontController'                   => '',
            'CmsController'                                         => '',
            'ManufacturerController'                                => '',
            'SupplierController'                                    => '',
            'SearchController'                                      => self::TAG_SEARCH,
            'BestSalesController'                                   => self::TAG_SEARCH,
            'NewProductsController'                                 => self::TAG_SEARCH,
            'PricesDropController'                                  => self::TAG_SEARCH,
            'SitemapController'                                     => self::TAG_SITEMAP,
            'StoresController'                                      => self::TAG_STORES,
            'PageNotFoundController'                                => self::TAG_404,
            'productcommentsListCommentsModuleFrontController'      => self::TAG_PREFIX_PCOMMENTS,
            'productcommentsCommentGradeModuleFrontController'      => self::TAG_PREFIX_PCOMMENTS,
        ]);

        LSLog::setDebugLevel($this->isDebug);
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', $this->isDebug);
        }
    }

    public function addExtraPubControllers(array $extraPubControllers): void
    {
        array_walk($extraPubControllers, function ($tag, $key) {
            $this->pubController[strtolower($key)] = $tag;
        });
    }

    public function isControllerCacheable(string $controllerClass)
    {
        $controllerClass = strtolower($controllerClass);
        if (!isset($this->pubController[$controllerClass])) {
            return false;
        }
        $tag = $this->pubController[$controllerClass];
        $ttl = -1;

        if ($controllerClass === 'indexcontroller') {
            $ttl = $this->get(self::CFG_HOME_TTL);
        } elseif ($controllerClass === 'pagenotfoundcontroller') {
            $ttl = $this->get(self::CFG_404_TTL);
        } elseif ($controllerClass === 'productcommentslistcommentsmodulefrontcontroller'
            || $controllerClass === 'productcommentscommentgrademodulefrontcontroller') {
            $ttl = $this->get(self::CFG_PCOMMENTS_TTL);
            $idProduct = \Tools::getValue('id_product');
            if ($idProduct) {
                $tag = self::TAG_PREFIX_PCOMMENTS . $idProduct;
            }
        }

        return ['tag' => $tag, 'ttl' => $ttl];
    }

    public function getNoCacheConf(): array
    {
        return [
            self::CFG_NOCACHE_URL => $this->getArray(self::CFG_NOCACHE_URL),
            self::CFG_NOCACHE_VAR => $this->getArray(self::CFG_NOCACHE_VAR),
        ];
    }

    public function getContextBypass(): array
    {
        return $this->getArray(self::CFG_VARY_BYPASS);
    }

    public function getFlushHomePids()
    {
        if ($this->flushHomePids === null) {
            if ($this->get(self::CFG_FLUSH_HOME) > 0) {
                $this->flushHomePids = $this->getArray(self::CFG_FLUSH_HOME_INPUT);
            } else {
                $this->flushHomePids = false;
            }
        }
        return $this->flushHomePids;
    }

    public function getDiffCustomerGroup(): int
    {
        $diffGroup = $this->get(self::CFG_DIFFCUSTGRP);
        if ($this->enforceDiffGroup === 1) {
            return 1;
        } elseif ($this->enforceDiffGroup === 2 && $diffGroup === 0) {
            return 2;
        }
        return $diffGroup;
    }

    public function enforceDiffCustomerGroup(int $enforcedSetting): void
    {
        if ($enforcedSetting === 1) {
            $this->enforceDiffGroup = 1;
        } elseif ($enforcedSetting === 2 && $this->enforceDiffGroup === 0) {
            $this->enforceDiffGroup = 2;
        }
    }

    public function canInjectEsi(string $name, array &$params)
    {
        $m = &$this->esiModConf['mods'];
        if (isset($m[$name]) && $m[$name]->canInject($params)) {
            return $m[$name];
        }
        return false;
    }

    public function isEsiModule(string $moduleName): bool
    {
        return isset($this->esiModConf['mods'][$moduleName]);
    }

    public function getEsiModuleConf(string $moduleName): ?EsiConf
    {
        return $this->esiModConf['mods'][$moduleName] ?? null;
    }

    public function getPurgeTagsByEvent(string $event): ?array
    {
        return $this->esiModConf['purge_events'][$event] ?? null;
    }

    public function registerEsiModule(EsiConf $esiConf): void
    {
        $modname = $esiConf->getModuleName();
        $this->esiModConf['mods'][$modname] = $esiConf;
        $type = $esiConf->isPrivate() ? 'priv' : 'pub';
        $tags = $esiConf->getTags();

        if ($pc = $esiConf->getPurgeControllers()) {
            foreach ($pc as $controllerName => $controllerParam) {
                $controllerName = \Tools::strtolower($controllerName);
                if (!isset($this->purgeController[$controllerName])) {
                    $this->purgeController[$controllerName] = [];
                }
                $detail = &$this->purgeController[$controllerName];
                if (!isset($detail[$controllerParam])) {
                    $detail[$controllerParam] = ['priv' => [], 'pub' => []];
                }
                $detail[$controllerParam][$type] = array_unique(array_merge($detail[$controllerParam][$type], $tags));
            }
        }

        $events = $esiConf->getPurgeEvents();
        if (!empty($events)) {
            foreach ($events as $event) {
                if (!isset($this->esiModConf['purge_events'][$event])) {
                    $this->esiModConf['purge_events'][$event] = ['priv' => [], 'pub' => []];
                }
                $this->esiModConf['purge_events'][$event][$type] = array_unique(
                    array_merge($this->esiModConf['purge_events'][$event][$type], $tags)
                );
            }
        }
    }

    private function addDefaultPurgeControllers(): void
    {
        if (version_compare(_PS_VERSION_, '1.7.1.0', '<')) {
            $this->purgeController['adminperformancecontroller'] = [
                'empty_smarty_cache' => ['priv' => ['*'], 'pub' => ['*']],
            ];
        }
    }

    public function isPurgeController(string $controller_class)
    {
        $c = \Tools::strtolower($controller_class);
        if (!isset($this->purgeController[$c])) {
            return false;
        }

        $conf = ['pub' => [], 'priv' => []];
        foreach ($this->purgeController[$c] as $param => $tags) {
            if ($param !== 0) {
                if ($param === 'custom_handler') {
                    \LscIntegration::checkPurgeController($c, $conf);
                    continue;
                }
                $params = explode('&', $param);
                foreach ($params as $paramName) {
                    if (\Tools::getValue($paramName) == false) {
                        continue 2;
                    }
                }
            }
            if (!empty($tags['priv'])) {
                $conf['priv'] = array_merge($conf['priv'], $tags['priv']);
            }
            if (!empty($tags['pub'])) {
                $conf['pub'] = array_merge($conf['pub'], $tags['pub']);
            }
        }

        if (empty($conf['priv']) && empty($conf['pub'])) {
            $this->advancedCheckPurgeController($controller_class, $conf);
        }

        if (!empty($conf['priv']) || !empty($conf['pub'])) {
            return $conf;
        }

        return false;
    }

    protected function advancedCheckPurgeController(string $controller_class, array &$conf): void
    {
        if ($controller_class === 'AdminProductsController') {
            if (\Tools::isSubmit('deleteSpecificPrice') || \Tools::isSubmit('submitSpecificPricePriorities')) {
                $id_product = \Tools::getValue('id_product');
                \Hook::exec('litespeedCacheProductUpdate', ['id_product' => $id_product]);
            }
        }
    }

    public function getDefaultPurgeTagsByProduct(): array
    {
        return [self::TAG_SEARCH, self::TAG_SITEMAP];
    }

    public function getDefaultPurgeTagsByCategory(): array
    {
        return [self::TAG_SEARCH, self::TAG_SITEMAP];
    }

    public static function isBypassed(): bool
    {
        return (bool) \Configuration::getGlobalValue('LITESPEED_CACHE_BYPASS');
    }

    public static function setBypass(bool $bypass): void
    {
        \Configuration::updateGlobalValue('LITESPEED_CACHE_BYPASS', $bypass ? 1 : 0);
    }

    public function moduleEnabled(): int
    {
        if (self::isBypassed()) {
            return 0;
        }

        $flag = 0;
        if (CacheHelper::licenseEnabled() && $this->get(self::CFG_ENABLED)) {
            $ips = $this->getArray(self::CFG_ALLOW_IPS);
            if (!empty($ips)) {
                $flag = CacheState::MOD_ALLOWIP;
                if (in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                    $flag |= CacheState::MOD_ACTIVE;
                }
            } else {
                $flag = CacheState::MOD_ACTIVE;
            }
        }
        return $flag;
    }

    public function getReservedHooks(): array
    {
        $hooks = [
            'actionDispatcher',
            'displayFooterAfter',
            'overrideLayoutTemplate',
            'DisplayOverrideTemplate',
            'filterCategoryContent',
            'filterProductContent',
            'filterCmsContent',
            'filterCmsCategoryContent',
            'actionCustomerLogoutAfter',
            'actionAuthentication',
            'actionCustomerAccountAdd',
            'actionProductSearchAfter',
            'actionProductAdd',
            'actionProductSave',
            'actionProductUpdate',
            'actionProductDelete',
            'actionProductAttributeDelete',
            'deleteProductAttribute',
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',
            'actionWatermark',
            'displayOrderConfirmation',
            'categoryUpdate',
            'actionCategoryUpdate',
            'actionCategoryAdd',
            'actionCategoryDelete',
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            'actionObjectCmsAddAfter',
            'actionObjectSupplierUpdateAfter',
            'actionObjectSupplierDeleteAfter',
            'actionObjectSupplierAddAfter',
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectManufacturerAddAfter',
            'actionObjectStoreUpdateAfter',
            'actionObjectCartRuleAddAfter',
            'actionObjectCartRuleUpdateAfter',
            'actionObjectCartRuleDeleteAfter',
            'actionObjectSpecificPriceRuleAddAfter',
            'actionObjectSpecificPriceRuleUpdateAfter',
            'actionObjectSpecificPriceRuleDeleteAfter',
            'litespeedCachePurge',
            'litespeedCacheProductUpdate',
            'litespeedNotCacheable',
            'litespeedEsiBegin',
            'litespeedEsiEnd',
            'addWebserviceResources',
            'updateProduct',
            'actionUpdateQuantity',
        ];

        if (version_compare(_PS_VERSION_, '1.7.1.0', '>=')) {
            $hooks[] = 'actionClearCompileCache';
            $hooks[] = 'actionClearSf2Cache';
        }

        $hooks[] = 'displayBackOfficeHeader';
        $hooks[] = 'actionHtaccessCreate';

        return $hooks;
    }
}
