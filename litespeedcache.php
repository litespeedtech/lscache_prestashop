<?php

/**
 * LiteSpeed Cache for Prestashop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Helper\ObjectCacheActivator;
use LiteSpeed\Cache\Hook\Action\AuthHookHandler;
use LiteSpeed\Cache\Hook\Action\CacheHookHandler;
use LiteSpeed\Cache\Hook\Action\CatalogEntitiesHookHandler;
// Hook handlers
use LiteSpeed\Cache\Hook\Action\CategoryHookHandler;
use LiteSpeed\Cache\Hook\Action\CmsHookHandler;
use LiteSpeed\Cache\Hook\Action\DispatcherHookHandler;
use LiteSpeed\Cache\Hook\Action\EsiHookHandler;
use LiteSpeed\Cache\Hook\Action\OrderHookHandler;
use LiteSpeed\Cache\Hook\Action\PricingHookHandler;
use LiteSpeed\Cache\Hook\Action\ProductHookHandler;
use LiteSpeed\Cache\Hook\Display\BackOfficeDisplayHookHandler;
use LiteSpeed\Cache\Hook\Display\FrontDisplayHookHandler;
use LiteSpeed\Cache\Hook\Filter\ContentFilterHookHandler;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Module\TabManager;
use LiteSpeed\Cache\Service\Esi\EsiMarkerManager;
// ESI services
use LiteSpeed\Cache\Service\Esi\EsiOutputProcessor;
use LiteSpeed\Cache\Service\Esi\EsiRenderer;
use LiteSpeed\Cache\Service\Esi\EsiResponseConfigurator;
use LiteSpeed\Cache\Vary\VaryCookie;

class LiteSpeedCache extends Module
{
    public const MODULE_NAME = 'litespeedcache';

    // Bitmask constants kept for backward compatibility with third-party integrations.
    public const CCBM_CACHEABLE = CacheState::CACHEABLE;
    public const CCBM_PRIVATE = CacheState::PRIV;
    public const CCBM_CAN_INJECT_ESI = CacheState::CAN_INJECT_ESI;
    public const CCBM_ESI_ON = CacheState::ESI_ON;
    public const CCBM_ESI_REQ = CacheState::ESI_REQ;
    public const CCBM_GUEST = CacheState::GUEST;
    public const CCBM_ERROR_CODE = CacheState::ERROR_CODE;
    public const CCBM_NOT_CACHEABLE = CacheState::NOT_CACHEABLE;
    public const CCBM_VARY_CHECKED = CacheState::VARY_CHECKED;
    public const CCBM_VARY_CHANGED = CacheState::VARY_CHANGED;
    public const CCBM_FRONT_CONTROLLER = CacheState::FRONT_CTRL;
    public const CCBM_MOD_ACTIVE = CacheState::MOD_ACTIVE;
    public const CCBM_MOD_ALLOWIP = CacheState::MOD_ALLOWIP;

    public const ESI_MARKER_END = '_LSCESIEND_';

    /** @var CacheManager */
    private $cache;

    /** @var Conf */
    private $config;

    // Lazy-loaded handlers and services
    private ?EsiMarkerManager $esiMarkerManager = null;
    private ?EsiRenderer $esiRenderer = null;
    private ?EsiOutputProcessor $esiOutputProcessor = null;
    private ?EsiResponseConfigurator $esiResponseConfigurator = null;
    private ?DispatcherHookHandler $dispatcherHandler = null;
    private ?FrontDisplayHookHandler $frontDisplayHandler = null;
    private ?BackOfficeDisplayHookHandler $backOfficeHandler = null;
    private ?ContentFilterHookHandler $contentFilterHandler = null;
    private ?EsiHookHandler $esiHookHandler = null;
    private ?CacheHookHandler $cacheHandler = null;

    public function __construct()
    {
        $this->name = 'litespeedcache';
        $this->tab = 'administration';
        $this->author = 'LiteSpeedTech';
        $this->version = '2.1.0';
        $this->need_instance = 0;
        $this->module_key = '2a93f81de38cad872010f09589c279ba';

        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_,
        ];

        $this->controllers = ['esi'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('LiteSpeed Cache Plugin', [], 'Modules.Litespeedcache.Admin');
        $this->description = $this->trans('Integrates with LiteSpeed Full Page Cache on LiteSpeed Server.', [], 'Modules.Litespeedcache.Admin');

        $this->config = Conf::getInstance();
        $this->cache = new CacheManager($this->config);

        CacheState::set($this->config->moduleEnabled());

        if (!defined('_LITESPEED_CACHE_')) {
            define('_LITESPEED_CACHE_', 1);
        }
        if (!defined('_LITESPEED_DEBUG_')) {
            define('_LITESPEED_DEBUG_', 0);
        }

        // Auto-register backoffice header hook if missing
        if ($this->active && $this->id) {
            try {
                $hookId = (int) Hook::getIdByName('displayBackOfficeHeader');
                if ($hookId) {
                    $registered = Db::getInstance()->getValue(
                        'SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_module = ' . (int) $this->id . ' AND id_hook = ' . $hookId
                    );
                    if (!$registered) {
                        $this->registerHook('displayBackOfficeHeader');
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (self::isActiveForUser()) {
            require_once _PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php';
            Hook::exec('actionLiteSpeedCacheInitThirdParty');
        }
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    // ---- Version ----------------------------------------------------------------

    public static function getVersion(): string
    {
        return '2.1.0';
    }

    // ---- State accessors --------------------------------------------------------

    public static function isActive(): bool
    {
        return CacheState::isActive();
    }

    public static function isActiveForUser(): bool
    {
        return CacheState::isActiveForUser();
    }

    public static function isRestrictedIP(): bool
    {
        return CacheState::isRestrictedIP();
    }

    public static function isCacheable(): bool
    {
        return CacheState::isCacheable();
    }

    public static function isEsiRequest(): bool
    {
        return CacheState::isEsiRequest();
    }

    public static function canInjectEsi(): bool
    {
        return CacheState::canInjectEsi();
    }

    public static function isFrontController(): bool
    {
        return CacheState::isFrontController();
    }

    public static function getCCFlag(): int
    {
        return CacheState::flag();
    }

    public static function getCCFlagDebugInfo(): string
    {
        return CacheState::debugInfo();
    }

    public function setEsiOn(): void
    {
        CacheState::set(CacheState::ESI_ON);
    }

    // ---- Lazy service/handler getters -------------------------------------------

    private function esiMarkers(): EsiMarkerManager
    {
        return $this->esiMarkerManager ??= new EsiMarkerManager($this->config);
    }

    private function esiRenderer(): EsiRenderer
    {
        return $this->esiRenderer ??= new EsiRenderer($this->config, $this->esiMarkers());
    }

    private function esiOutput(): EsiOutputProcessor
    {
        return $this->esiOutputProcessor ??= new EsiOutputProcessor($this->cache, $this->esiMarkers());
    }

    private function esiResponse(): EsiResponseConfigurator
    {
        return $this->esiResponseConfigurator ??= new EsiResponseConfigurator($this->config, $this->cache, $this->esiMarkers());
    }

    private function dispatcher(): DispatcherHookHandler
    {
        return $this->dispatcherHandler ??= new DispatcherHookHandler($this->cache, $this->config);
    }

    private function frontDisplay(): FrontDisplayHookHandler
    {
        return $this->frontDisplayHandler ??= new FrontDisplayHookHandler($this->config);
    }

    private function backOffice(): BackOfficeDisplayHookHandler
    {
        return $this->backOfficeHandler ??= new BackOfficeDisplayHookHandler($this->getPathUri());
    }

    private function contentFilter(): ContentFilterHookHandler
    {
        return $this->contentFilterHandler ??= new ContentFilterHookHandler($this->cache);
    }

    private function esiHook(): EsiHookHandler
    {
        return $this->esiHookHandler ??= new EsiHookHandler($this->config, $this->esiMarkers());
    }

    private function cacheHook(): CacheHookHandler
    {
        return $this->cacheHandler ??= new CacheHookHandler($this->cache, $this->config);
    }

    // ---- Hook delegation: Dispatcher -------------------------------------------

    public function hookActionDispatcher(array $params): void
    {
        $this->dispatcher()->onDispatcher($params);
    }

    // ---- Hook delegation: Display ----------------------------------------------

    public function hookOverrideLayoutTemplate(array $params): void
    {
        $this->frontDisplay()->onOverrideLayoutTemplate($params, $this->cache);
    }

    public function hookDisplayOverrideTemplate(array $params): void
    {
        $this->frontDisplay()->onDisplayOverrideTemplate($params, $this->cache);
    }

    public function hookDisplayFooterAfter(array $params): string
    {
        return $this->frontDisplay()->onDisplayFooterAfter($params);
    }

    public function hookDisplayBackOfficeHeader(): string
    {
        return $this->backOffice()->onDisplayBackOfficeHeader();
    }

    // ---- Hook delegation: Filters ----------------------------------------------

    public function hookActionProductSearchAfter(array $params): void
    {
        (new ProductHookHandler($this->cache))->onProductSearchAfter($params);
    }

    public function hookFilterCategoryContent(array $params): array
    {
        return $this->contentFilter()->onFilterCategoryContent($params);
    }

    public function hookFilterProductContent(array $params): array
    {
        return $this->contentFilter()->onFilterProductContent($params);
    }

    public function hookFilterCmsCategoryContent(array $params): array
    {
        return $this->contentFilter()->onFilterCmsCategoryContent($params);
    }

    public function hookFilterCmsContent(array $params): array
    {
        return $this->contentFilter()->onFilterCmsContent($params);
    }

    // ---- Hook delegation: ESI --------------------------------------------------

    public function hookLitespeedEsiBegin(array $params): string
    {
        return $this->esiHook()->onEsiBegin($params);
    }

    public function hookLitespeedEsiEnd(array $params): string
    {
        return $this->esiHook()->onEsiEnd($params);
    }

    // ---- Hook delegation: Cache ------------------------------------------------

    public function hookLitespeedCachePurge(array $params): void
    {
        $this->cacheHook()->onCachePurge($params);
    }

    public function hookLitespeedNotCacheable(array $params): void
    {
        $this->cacheHook()->onNotCacheable($params);
    }

    public function hookActionHtaccessCreate(array $params): void
    {
        $this->cacheHook()->onHtaccessCreate($params);
    }

    // ---- Catchall hook router --------------------------------------------------

    /** @var array<string, array{handler: string, method: string}>|null */
    private static ?array $hookMap = null;

    /**
     * Catchall for registered hooks without explicit hookXxx() methods.
     * Routes to the appropriate handler based on event name.
     */
    public function __call(string $method, array $args)
    {
        if (count($args) === 1 && array_keys($args) === [0]) {
            $args = $args[0];
        }

        $event = Tools::strtolower(Tools::substr($method, 4));

        // Cache clear hooks must always run
        if ($event === 'actionclearcompilecache') {
            $this->cacheHook()->onClearCompileCache(is_array($args) ? $args : []);

            return;
        }
        if ($event === 'actionclearsf2cache') {
            $this->cacheHook()->onClearSf2Cache(is_array($args) ? $args : []);

            return;
        }

        if (!self::isActive()) {
            return;
        }

        $resolved = $this->resolveHandler($event);
        if ($resolved) {
            $resolved['instance']->{$resolved['method']}(is_array($args) ? $args : []);

            return;
        }

        // Fallback: delegate to CacheManager's existing catchall
        $this->cache->purgeByCatchAllMethod($method, is_array($args) ? $args : []);
    }

    private function resolveHandler(string $event): ?array
    {
        if (self::$hookMap === null) {
            self::$hookMap = [
                // Product
                'actionproductadd' => ['handler' => 'product', 'method' => 'onProductAdd'],
                'actionproductsave' => ['handler' => 'product', 'method' => 'onProductSave'],
                'actionproductupdate' => ['handler' => 'product', 'method' => 'onProductUpdate'],
                'actionproductdelete' => ['handler' => 'product', 'method' => 'onProductDelete'],
                'actionproductattributedelete' => ['handler' => 'product', 'method' => 'onProductAttributeDelete'],
                'deleteproductattribute' => ['handler' => 'product', 'method' => 'onDeleteProductAttribute'],
                'updateproduct' => ['handler' => 'product', 'method' => 'onUpdateProduct'],
                'actionupdatequantity' => ['handler' => 'product', 'method' => 'onUpdateQuantity'],
                'litespeedcacheproductupdate' => ['handler' => 'product', 'method' => 'onCacheProductUpdate'],
                // Category
                'categoryupdate' => ['handler' => 'category', 'method' => 'onCategoryUpdate'],
                'actioncategoryupdate' => ['handler' => 'category', 'method' => 'onActionCategoryUpdate'],
                'actioncategoryadd' => ['handler' => 'category', 'method' => 'onCategoryAdd'],
                'actioncategorydelete' => ['handler' => 'category', 'method' => 'onCategoryDelete'],
                // CMS
                'actionobjectcmsaddafter' => ['handler' => 'cms', 'method' => 'onCmsAdd'],
                'actionobjectcmsupdateafter' => ['handler' => 'cms', 'method' => 'onCmsUpdate'],
                'actionobjectcmsdeleteafter' => ['handler' => 'cms', 'method' => 'onCmsDelete'],
                // Pricing
                'actionobjectspecificpriceaddafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceAdd'],
                'actionobjectspecificpriceupdateafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceUpdate'],
                'actionobjectspecificpricedeleteafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceDelete'],
                'actionobjectcartruleaddafter' => ['handler' => 'pricing', 'method' => 'onCartRuleAdd'],
                'actionobjectcartruleupdateafter' => ['handler' => 'pricing', 'method' => 'onCartRuleUpdate'],
                'actionobjectcartruledeleteafter' => ['handler' => 'pricing', 'method' => 'onCartRuleDelete'],
                'actionobjectspecificpriceruleaddafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceRuleAdd'],
                'actionobjectspecificpriceruleupdateafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceRuleUpdate'],
                'actionobjectspecificpriceruledeleteafter' => ['handler' => 'pricing', 'method' => 'onSpecificPriceRuleDelete'],
                // Auth
                'actionauthentication' => ['handler' => 'auth', 'method' => 'onAuthentication'],
                'actioncustomerlogoutafter' => ['handler' => 'auth', 'method' => 'onCustomerLogout'],
                'actioncustomeraccountadd' => ['handler' => 'auth', 'method' => 'onCustomerAccountAdd'],
                // Order
                'displayorderconfirmation' => ['handler' => 'order', 'method' => 'onOrderConfirmation'],
                // Catalog entities
                'actionobjectsupplieraddafter' => ['handler' => 'catalog', 'method' => 'onSupplierAdd'],
                'actionobjectsupplierupdateafter' => ['handler' => 'catalog', 'method' => 'onSupplierUpdate'],
                'actionobjectsupplierdeleteafter' => ['handler' => 'catalog', 'method' => 'onSupplierDelete'],
                'actionobjectmanufactureraddafter' => ['handler' => 'catalog', 'method' => 'onManufacturerAdd'],
                'actionobjectmanufacturerupdateafter' => ['handler' => 'catalog', 'method' => 'onManufacturerUpdate'],
                'actionobjectmanufacturerdeleteafter' => ['handler' => 'catalog', 'method' => 'onManufacturerDelete'],
                'actionobjectstoreupdateafter' => ['handler' => 'catalog', 'method' => 'onStoreUpdate'],
                // Cache
                'actionwatermark' => ['handler' => 'cacheHook', 'method' => 'onWatermark'],
                'addwebserviceresources' => ['handler' => 'cacheHook', 'method' => 'onWebserviceResources'],
            ];
        }

        if (!isset(self::$hookMap[$event])) {
            return null;
        }

        $entry = self::$hookMap[$event];
        $instance = $this->getHandlerInstance($entry['handler']);

        return ['instance' => $instance, 'method' => $entry['method']];
    }

    private function getHandlerInstance(string $key): object
    {
        static $handlers = [];
        if (!isset($handlers[$key])) {
            $handlers[$key] = match ($key) {
                'product' => new ProductHookHandler($this->cache),
                'category' => new CategoryHookHandler($this->cache),
                'cms' => new CmsHookHandler($this->cache),
                'pricing' => new PricingHookHandler($this->cache),
                'auth' => new AuthHookHandler($this->cache),
                'order' => new OrderHookHandler($this->cache),
                'catalog' => new CatalogEntitiesHookHandler($this->cache),
                'cacheHook' => $this->cacheHook(),
                default => throw new LogicException("Unknown handler key: $key"),
            };
        }

        return $handlers[$key];
    }

    // ---- ESI static wrappers (called from Hook.php / Media.php overrides) ------

    /** @return string|false */
    public static function injectRenderWidget($module, string $hook_name, $params = false)
    {
        return self::myInstance()->esiRenderer()->injectRenderWidget($module, $hook_name, $params);
    }

    /** @return string|false */
    public static function injectCallHook($module, string $method, $params = false)
    {
        return self::myInstance()->esiRenderer()->injectCallHook($module, $method, $params);
    }

    public static function filterJsDef(array &$jsDef): void
    {
        self::myInstance()->esiResponse()->filterJsDef($jsDef);
    }

    public static function callbackOutputFilter(string $buffer): string
    {
        return self::myInstance()->esiOutput()->processBuffer($buffer);
    }

    public function addCacheControlByEsiModule(LiteSpeed\Cache\Esi\EsiItem $item): void
    {
        $this->esiResponse()->addCacheControlByEsiModule($item);
    }

    public static function forceNotCacheable(string $reason): void
    {
        CacheState::markNotCacheable($reason);
        if ($reason && _LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
            LSLog::log('setNotCacheable - ' . $reason, LSLog::LEVEL_NOCACHE_REASON);
        }
    }

    // ---- Vary cookie -----------------------------------------------------------

    public static function setVaryCookie(): bool
    {
        if (!CacheState::has(CacheState::VARY_CHECKED)) {
            if (VaryCookie::setVary()) {
                CacheState::set(CacheState::VARY_CHANGED);
            }
            CacheState::set(CacheState::VARY_CHECKED);
        }

        return CacheState::has(CacheState::VARY_CHANGED);
    }

    // ---- Module lifecycle -------------------------------------------------------

    public function getContent(): void
    {
        $url = $this->context->link->getAdminLink('AdminLiteSpeedCacheConfig');
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        Tools::redirectAdmin($url);
    }

    public function install(): bool
    {
        (new TabManager($this))->install();

        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        Configuration::updateGlobalValue(Conf::ENTRY_ALL, json_encode($this->config->getDefaultConfData(Conf::ENTRY_ALL)));
        Configuration::updateValue(Conf::ENTRY_SHOP, json_encode($this->config->getDefaultConfData(Conf::ENTRY_SHOP)));
        Configuration::updateValue(Conf::ENTRY_MODULE, json_encode($this->config->getDefaultConfData(Conf::ENTRY_MODULE)));
        Configuration::updateGlobalValue(CdnConfig::ENTRY, json_encode(CdnConfig::getDefaults()));
        Configuration::updateGlobalValue(ObjConfig::ENTRY, json_encode(ObjConfig::getDefaults()));
        Configuration::updateGlobalValue(ExclusionsConfig::ENTRY, json_encode(ExclusionsConfig::getDefaults()));

        CacheHelper::htAccessBackup('b4lsc');
        $defaults = $this->config->getDefaultConfData(Conf::ENTRY_ALL);
        CacheHelper::htAccessUpdate(
            (bool) $defaults[Conf::CFG_ENABLED],
            $defaults[Conf::CFG_GUESTMODE] == 1,
            (bool) $defaults[Conf::CFG_DIFFMOBILE]
        );

        PrestaShopLogger::addLog('LiteSpeed Cache module installed', 1, null, 'LiteSpeedCache', 0, true);

        return $this->installHooks();
    }

    public function disable($force_all = false)
    {
        $this->disablePsCache();

        return parent::disable($force_all);
    }

    public function uninstall(): bool
    {
        $this->disablePsCache();

        PrestaShopLogger::addLog('LiteSpeed Cache module uninstalled', 2, null, 'LiteSpeedCache', 0, true);
        (new TabManager($this))->uninstall();
        CacheHelper::htAccessUpdate(false, false, false);
        Configuration::deleteByName(Conf::ENTRY_ALL);
        Configuration::deleteByName(Conf::ENTRY_SHOP);
        Configuration::deleteByName(Conf::ENTRY_MODULE);
        Configuration::deleteByName(CdnConfig::ENTRY);
        Configuration::deleteByName(ObjConfig::ENTRY);
        Configuration::deleteByName(ExclusionsConfig::ENTRY);

        return parent::uninstall();
    }

    private function disablePsCache(): void
    {
        try {
            $this->cache->purgeByTags('*', false, 'module disable/uninstall');
            if (!headers_sent()) {
                header('X-LiteSpeed-Purge: *');
            }
            ObjectCacheActivator::disableIfActive();
            $cdnCfg = CdnConfig::getAll();
            if (!empty($cdnCfg[CdnConfig::CF_ENABLE]) && !empty($cdnCfg[CdnConfig::CF_PURGE]) && !empty($cdnCfg[CdnConfig::CF_ZONE_ID])) {
                (new LiteSpeed\Cache\Integration\Cloudflare($cdnCfg[CdnConfig::CF_KEY], $cdnCfg[CdnConfig::CF_EMAIL]))->purgeAll($cdnCfg[CdnConfig::CF_ZONE_ID]);
            }
            Tools::clearSf2Cache();
            PrestaShopLogger::addLog('All caches flushed and disabled (module disable/uninstall)', 2, null, 'LiteSpeedCache', 0, true);
        } catch (Throwable $e) {
        }
    }

    // ---- Private helpers --------------------------------------------------------

    /** @var self|null */
    private static $cachedInstance;

    private static function myInstance(): self
    {
        if (self::$cachedInstance === null) {
            $instance = Module::getInstanceByName(self::MODULE_NAME);
            if (!$instance instanceof self) {
                throw new RuntimeException('LiteSpeedCache module instance not found');
            }
            self::$cachedInstance = $instance;
        }

        return self::$cachedInstance;
    }

    private function installHooks(): bool
    {
        foreach ($this->config->getReservedHooks() as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }
}
