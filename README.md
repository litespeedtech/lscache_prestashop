# LiteSpeed Cache for PrestaShop

Full Page Cache module for PrestaShop running on LiteSpeed Web Server. Saves and serves static copies of dynamic pages, greatly reducing page-load time. The only PrestaShop cache module to support `esi:include` and `esi:inline`.

## Requirements

| Component | Version |
|---|---|
| PHP | >= 7.4 |
| PrestaShop | 1.7.8 / 8.x / 9.x |
| Web Server | LiteSpeed Enterprise or OpenLiteSpeed |

## Installation

1. Download `litespeedcache.zip` from the [latest release](https://github.com/ecomlabs-es/lscache_prestashop/releases/latest).
2. In PrestaShop Admin, go to **Modules > Module Manager** and click **Upload a Module**.
3. Select the zip file.
4. Click **Configure** to launch the Setup Wizard.

> Make sure your LiteSpeed license includes the LSCache module. A free 2-CPU trial license is available for 15 days.

## Setup Wizard

The module includes a guided wizard that configures the cache based on your store:

1. **Your Store** — Auto-detects languages, currencies, multistore. Asks about hosting type, mobile theme, customer group pricing, catalog update frequency.
2. **Purge** — Configures automatic cache purge on orders and content changes.
3. **Object Cache** — Detects Redis and enables object caching if available.
4. **CDN** — Cloudflare integration with email, API key and auto-detected domain.
5. **Summary** — Review and apply configuration.

## Features

- Full page cache with automatic purge on content changes
- Setup Wizard for guided configuration
- Edge Side Includes (ESI) for per-user dynamic blocks (cart, account)
- Automatic product page purge on cart changes (stock/availability sync)
- Multi-store, multi-language, multi-currency and geolocation support
- Separate mobile view caching
- Tag-based purge (products, categories, CMS, prices, manufacturers, suppliers)
- Redis object cache backend with auto-detection
- Cloudflare CDN integration
- Configurable TTL per content type
- Cache exclusions by URL, query string, cookie, user agent or customer group
- Cache warmup with concurrent crawling and performance profiles (Low/Medium/High)
- Server load throttling for crawl operations
- Mobile cache warmup
- Import/export full configuration
- Debug headers and logging
- Compatible with PrestaShop 1.7.8, 8.x and 9.x

## Architecture

```
litespeedcache.php          Main module class (hooks, install/uninstall)
src/
├── Admin/                  ConfigValidator
├── Cache/                  CacheRedis (object cache driver)
├── Command/                WarmupLscacheCommand (CLI: litespeedcache:warmup)
├── Config/                 CacheConfig, CdnConfig, ObjConfig, ExclusionsConfig, WarmupConfig
├── Controller/Admin/       15 Symfony admin controllers + WizardController
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
integrations/
├── LscIntegration.php      Base class for ESI integrations
├── core/                   Internal ESI blocks (Token, Env)
├── prestashop/             Native PS modules (CustomerSignIn, Shoppingcart, EmailAlerts)
├── modules/                Third-party modules (GdprPro, Pscartdropdown)
└── themes/                 Theme integrations (Warehouse, Panda, Alysum)
config/
├── routes.yml              30 admin routes
└── services.yml            Symfony DI services
views/templates/admin/      Twig templates for admin UI
```

## CLI Commands

Cache warmup from the PrestaShop root directory:

```bash
# Warm up all pages from sitemap (uses saved config: concurrency, delay, timeout, load limit)
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml

# Override settings for a single run
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml --concurrency=8 --delay=0

# Include mobile cache warmup
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml --mobile
```

### Cron setup

```bash
# Run daily at 3 AM
0 3 * * * cd /var/www/html && php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml
```

The command reads the crawler configuration (concurrency, delay, timeout, load limit, mobile) from the module settings automatically.

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

# Run tests (61 tests, 103 assertions)
vendor/bin/phpunit --testdox

# Check coding standards
composer cs-check

# Fix coding standards
composer cs-fix
```

## CI/CD

GitHub Actions workflows:

- **compatibility.yml** — PHP Lint (8.1/8.2/8.3), PHP CS Fixer, Composer Validate, Unit Tests (PHPUnit), Twig Lint
- **release.yml** — Builds `litespeedcache.zip` and creates a GitHub release on tag push (`v*`)

## License

GPL-3.0+

## Links

- [LiteSpeed Cache for PrestaShop documentation](https://docs.litespeedtech.com/lscache/lscps/)
- [LiteSpeed Web Server](https://www.litespeedtech.com)
