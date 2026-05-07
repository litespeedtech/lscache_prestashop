# lscache_prestashop
LiteSpeed Cache Plugin for Prestashop

## About

LiteSpeed Cache for PrestaShop communicates directly with your installation of LiteSpeed Web Server to save and serve static copies of dynamic web pages, 
greatly reducing your shop’s page-load time. LSCPS is the only PrestaShop Cache module to support *esi:include* and *esi:inline*.

### Installation

Install LiteSpeed Web Server Enterprise.
Disable any other page caches as these will interfere with LSCPS.
Download the LSCPS plugin.
Log in to your PrestaShop Admin, navigate to **Modules > Modules & Services**, and click on **Upload a Module**.
Select the LSCPS zip file.
Enable the module by navigating to **LiteSpeed Cache > Settings** and setting **Enable LiteSpeed Cache** to ```Yes```.

### Notes For Litespeed Web Server Enterprise (LSWS)

Make sure that your license includes the LSCache module enabled. A 2-CPU trial license with LSCache module is available for free for 15 days.
The server must be configured to have caching enabled. If you are the server admin, 
click [here](https://docs.litespeedtech.com/lscache/lscps/installation/). 
Otherwise request that the server admin configure the cache root for the server.

### Module Features

* Support for PrestaShop 1.7+, and Prestashop 8, Prestashop 9.
* LSCPS supports multiple stores, multi-language, multi-currency, geolocation and mobile view.
* Integrated into both LiteSpeed Web Server and LiteSpeed Web ADC. Works in a single-server environment using LSWS, or a clustered environment using LS Web ADC.
* Caching is highly customizable on both a global level and a per-store basis. Tag-based caching allows purge by tag from external programs.
* Main page and public blocks are cached once and served to all users. Private blocks are cached per user and served only to that user.
* LSCPS automatically caches the following pages with a GET request (including AJAX GET): Home, Categories, Products, CMS, New products, Best sales, Suppliers, Manufacturers, Prices drop, Sitemap.
* User information can be cached privately via ESI blocks and auto purged when the information changes. Support for cart and account sign in are built in. Other third-party modules that contain private information can be easily added.
* Updates in the shop admin area automatically trigger a purge of any related pages in the cache.
* New client orders automatically trigger a purge of related product and catalog pages based on stock status or quantity (configurable).
* The cache can be manually flushed from within the PrestaShop admin.
* If a page contains products with specific prices, TTL will be auto adjusted based on special price effective dates.


### CLI commands

CLI commands are only allowed to execute from the website host server.

**WarmUp whole website**

 in `/prestashop_root/` folder, execute `bin/console` command :

```
bin/console litespeedcache:warmup https://example.com/sitemap.xml
```

**WarmUp Desktop and Mobile View if enabled Separate Mobile View**

 in `/prestashop_root/` folder, execute `bin/console` command :

```
bin/console litespeedcache:warmup https://example.com/sitemap.xml iphone
```

### Testing the Module

LSCPS utilizes LiteSpeed-specific response headers. Use your browser’s developer tools to check them: Select the **Network** tab and look at the response headers for the first file listed.
Visiting a page for the first time should result in a ```X-LiteSpeed-Cache-Control:miss``` or ```X-LiteSpeed-Cache-Control:no-cache``` response header for the page. 
Subsequent requests should have the ```X-LiteSpeed-Cache-Control:hit``` response header until the page is updated, expired, or purged. 

### Learn More

For additional instructions, tips, and ideas, please see 
the [LiteSpeed Cache for PrestaShop documentation](https://docs.litespeedtech.com/lscache/lscps/).

## FAQ

### 1. How do I enable LiteSpeed ESI?

Please refer to this document to enable the ESI feature in LiteSpeed Web Server:

[Enabling the Crawler and ESI](https://docs.litespeedtech.com/lsws/cp/cpanel/lscache/#enabling-the-crawler-and-esi)

If you are using a native installation, enable the ESI feature in the LSWS WebAdmin Console by navigating to **Server > Cache > Cache Features**.

### 2. How can I avoid 500 errors after enabling the LiteSpeed Cache module?

Navigate to **Admin Panel > Design > Positions**, find all hooks for the **LiteSpeed Cache Plugin** module, and move them to top priority.

### 3. Why can't I enable the LiteSpeed Cache module when CookiePlus has overwritten `coreCallHook`?

1. Deactivate the CookiePlus module, then enable the LiteSpeed Cache module.
2. Copy the contents of `WEBROOT/modules/cookieplus/override/classes/Hook.php` into `WEBROOT/override/classes/HOOK.php`.
3. Back up and delete `WEBROOT/modules/cookieplus/override/classes/Hook.php`.
4. Activate the CookiePlus module.
