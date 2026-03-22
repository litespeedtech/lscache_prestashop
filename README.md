# LiteSpeed Cache for PrestaShop

Full Page Cache module for PrestaShop running on LiteSpeed Web Server. Saves and serves static copies of dynamic pages, greatly reducing page-load time. The only PrestaShop cache module to support `esi:include` and `esi:inline`.

## Requirements

| Component | Version |
|---|---|
| PHP | >= 8.1 |
| PrestaShop | 8.x / 9.x |
| Web Server | LiteSpeed Enterprise or OpenLiteSpeed |

## Installation

1. Download `litespeedcache.zip` from the [latest release](https://github.com/ecomlabs-es/lscache_prestashop/releases/latest).
2. In PrestaShop Admin, go to **Modules > Module Manager** and click **Upload a Module**.
3. Select the zip file.
4. Navigate to **LiteSpeed Cache > Settings** and set **Enable LiteSpeed Cache** to `Yes`.

> Make sure your LiteSpeed license includes the LSCache module. A free 2-CPU trial license is available for 15 days.

## Features

- Full page cache with automatic purge on content changes
- Edge Side Includes (ESI) for per-user dynamic blocks (cart, account)
- Multi-store, multi-language, multi-currency and geolocation support
- Separate mobile view caching
- Tag-based purge (products, categories, CMS, prices, manufacturers, suppliers)
- Redis object cache backend
- Cloudflare CDN integration
- Configurable TTL per content type
- Cache exclusions by URL, query string or customer group
- Cache warmup via CLI and admin UI
- Import/export configuration
- Debug headers and logging

## Architecture

```
litespeedcache.php          Main module class (hooks, install/uninstall)
src/
├── Admin/                  ConfigValidator
├── Cache/                  Redis object cache integration
├── Command/                WarmupLscacheCommand (Symfony console)
├── Config/                 CacheConfig, CdnConfig, ObjConfig, ExclusionsConfig
├── Controller/Admin/       14 Symfony admin controllers
├── Core/                   CacheManager, CacheState
├── Esi/                    EsiItem, EsiModuleConfig
├── Form/                   CachingTypeExtension, ImportSettingsType
├── Helper/                 CacheHelper, ObjectCacheActivator
├── Hook/
│   ├── Action/             Product, Category, CMS, Pricing, Auth, Order...
│   ├── Display/            BackOffice, Front display hooks
│   └── Filter/             Content filter hooks
├── Integration/            Cloudflare, ObjectCache
├── Logger/                 CacheLogger
├── Module/                 TabManager
├── Resolver/               HookParamsResolver
├── Service/Esi/            EsiMarkerManager, EsiOutputProcessor, EsiRenderer
├── Update/                 ModuleUpdater
└── Vary/                   VaryCookie
config/
├── routes.yml              28 admin routes
└── services.yml            Symfony DI services
views/templates/admin/      Twig templates for admin UI
```

## Hooks

The module registers 54 hooks:

| Category | Hooks |
|---|---|
| **Product** | actionProductAdd, actionProductSave, actionProductUpdate, actionProductDelete, actionProductAttributeDelete, actionUpdateQuantity, actionProductSearchAfter |
| **Category** | actionCategoryUpdate, actionCategoryAdd, actionCategoryDelete |
| **CMS** | actionObjectCmsAddAfter, actionObjectCmsUpdateAfter, actionObjectCmsDeleteAfter |
| **Pricing** | actionObjectSpecificPrice\*, actionObjectCartRule\*, actionObjectSpecificPriceRule\* (add/update/delete) |
| **Auth** | actionAuthentication, actionCustomerLogoutAfter, actionCustomerAccountAdd |
| **Catalog** | actionObjectSupplier\*, actionObjectManufacturer\*, actionObjectStoreUpdateAfter |
| **Display** | displayFooterAfter, overrideLayoutTemplate, DisplayOverrideTemplate, displayOrderConfirmation, displayBackOfficeHeader |
| **Filter** | filterCategoryContent, filterProductContent, filterCmsContent, filterCmsCategoryContent |
| **Core** | actionDispatcher, actionWatermark, actionHtaccessCreate, actionClearCompileCache, actionClearSf2Cache |
| **Custom** | litespeedCachePurge, litespeedNotCacheable, litespeedEsiBegin, litespeedEsiEnd, litespeedCacheProductUpdate |

## CLI Commands

Cache warmup from the PrestaShop root directory:

```bash
# Warm up all pages from sitemap
php bin/console litespeedcache:warmup https://example.com/sitemap.xml

# Warm up with mobile user-agent (when separate mobile view is enabled)
php bin/console litespeedcache:warmup https://example.com/sitemap.xml iphone
```

## Testing Cache Headers

Use your browser's developer tools (Network tab) to check response headers:

| Header | Meaning |
|---|---|
| `X-LiteSpeed-Cache: hit` | Page served from cache |
| `X-LiteSpeed-Cache: miss` | Page generated and cached |
| `X-LiteSpeed-Cache-Control: no-cache` | Page not cacheable |

## Development

```bash
# Install dev dependencies
composer install

# Run tests
vendor/bin/phpunit --testdox

# Check coding standards
composer cs-check

# Fix coding standards
composer cs-fix
```

## CI/CD

The repository includes GitHub Actions workflows:

- **compatibility.yml** — PHP lint (8.1/8.2/8.3), PHP CS Fixer, composer validate, PHPUnit, Twig lint, integration tests on PS8 + PS9
- **release.yml** — Builds `litespeedcache.zip` and creates a GitHub release on tag push (`v*`)

## License

GPL-3.0+

## Links

- [LiteSpeed Cache for PrestaShop documentation](https://docs.litespeedtech.com/lscache/lscps/)
- [LiteSpeed Web Server](https://www.litespeedtech.com)
