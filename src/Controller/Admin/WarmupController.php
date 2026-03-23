<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Config\WarmupConfig;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WarmupController extends FrameworkBundleAdminController
{
    use NavPillsTrait;

    private const STATE_KEY = 'LITESPEED_WARMUP_STATE';
    private const BLACKLIST_KEY = 'LITESPEED_WARMUP_BLACKLIST';

    public function indexAction(Request $request): Response
    {
        $objCfg = ObjConfig::getAll();
        $cdnCfg = CdnConfig::getAll();

        // Handle blacklist actions
        if ($request->isMethod('POST')) {
            if ($request->request->has('blacklist_add')) {
                $url = trim($request->request->get('blacklist_url', ''));
                if ($url) {
                    $this->addToBlacklist($url);
                    $this->addFlash('success', 'URL added to blacklist.');
                }

                return $this->redirectToRoute('admin_litespeedcache_warmup');
            }
            if ($request->request->has('blacklist_remove')) {
                $url = $request->request->get('blacklist_remove');
                $this->removeFromBlacklist($url);
                $this->addFlash('success', 'URL removed from blacklist.');

                return $this->redirectToRoute('admin_litespeedcache_warmup');
            }
            if ($request->request->has('blacklist_clear')) {
                \Configuration::updateGlobalValue(self::BLACKLIST_KEY, '[]');
                $this->addFlash('success', 'Blacklist cleared.');

                return $this->redirectToRoute('admin_litespeedcache_warmup');
            }
            if ($request->request->has('save_settings')) {
                $sitemapUrl = trim($request->request->get('sitemap_url', ''));
                $shopUrl = trim($request->request->get('shop_url', ''));
                $userAgent = trim($request->request->get('useragent', ''));
                \Configuration::updateGlobalValue('LITESPEED_WARMUP_SITEMAP', $sitemapUrl);
                \Configuration::updateGlobalValue('LITESPEED_WARMUP_SHOP_URL', $shopUrl);
                \Configuration::updateGlobalValue('LITESPEED_WARMUP_USERAGENT', $userAgent);

                $profile = $request->request->get('profile', WarmupConfig::PROFILE_MEDIUM);
                $defaults = WarmupConfig::getDefaults();
                $profilePreset = WarmupConfig::getProfileSettings($profile);

                if ($profilePreset && $profile !== WarmupConfig::PROFILE_CUSTOM) {
                    $perf = array_merge($defaults, $profilePreset);
                } else {
                    $profile = WarmupConfig::PROFILE_CUSTOM;
                    $perf = $defaults;
                }

                WarmupConfig::saveAll([
                    WarmupConfig::PROFILE => $profile,
                    WarmupConfig::CRAWL_DELAY => ($profile === WarmupConfig::PROFILE_CUSTOM)
                        ? max(0, min(10000, (int) $request->request->get('crawl_delay', $perf[WarmupConfig::CRAWL_DELAY])))
                        : $perf[WarmupConfig::CRAWL_DELAY],
                    WarmupConfig::CONCURRENT_REQUESTS => ($profile === WarmupConfig::PROFILE_CUSTOM)
                        ? max(1, min(10, (int) $request->request->get('concurrent', $perf[WarmupConfig::CONCURRENT_REQUESTS])))
                        : $perf[WarmupConfig::CONCURRENT_REQUESTS],
                    WarmupConfig::CRAWL_TIMEOUT => ($profile === WarmupConfig::PROFILE_CUSTOM)
                        ? max(5, min(120, (int) $request->request->get('crawl_timeout', $perf[WarmupConfig::CRAWL_TIMEOUT])))
                        : $perf[WarmupConfig::CRAWL_TIMEOUT],
                    WarmupConfig::SERVER_LOAD_LIMIT => ($profile === WarmupConfig::PROFILE_CUSTOM)
                        ? max(0, min(100, (float) $request->request->get('load_limit', $perf[WarmupConfig::SERVER_LOAD_LIMIT])))
                        : $perf[WarmupConfig::SERVER_LOAD_LIMIT],
                    WarmupConfig::MOBILE_CRAWL => (int) (bool) $request->request->get('mobile_crawl', 0),
                    WarmupConfig::MOBILE_USER_AGENT => trim($request->request->get('mobile_useragent', '')) ?: $defaults[WarmupConfig::MOBILE_USER_AGENT],
                ]);

                $this->addFlash('success', 'Crawler settings saved.');

                return $this->redirectToRoute('admin_litespeedcache_warmup');
            }
            if ($request->request->has('reset_state')) {
                \Configuration::updateGlobalValue(self::STATE_KEY, '{}');
                $this->addFlash('success', 'Crawler state reset.');

                return $this->redirectToRoute('admin_litespeedcache_warmup');
            }
        }

        $lastState = json_decode(\Configuration::getGlobalValue(self::STATE_KEY) ?: '{}', true) ?: [];
        $blacklist = json_decode(\Configuration::getGlobalValue(self::BLACKLIST_KEY) ?: '[]', true) ?: [];

        $config = CacheConfig::getInstance();

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/warmup.html.twig', [
            'urlsAction' => $this->generateUrl('admin_litespeedcache_warmup_urls'),
            'crawlAction' => $this->generateUrl('admin_litespeedcache_warmup_crawl'),
            'saveStateAction' => $this->generateUrl('admin_litespeedcache_warmup_save_state'),
            'loadCheckAction' => $this->generateUrl('admin_litespeedcache_warmup_load_check'),
            'redisEnabled' => !empty($objCfg[ObjConfig::OBJ_ENABLE]),
            'cdnEnabled' => !empty($cdnCfg[CdnConfig::CF_ENABLE]),
            'diffMobile' => (bool) ($config->getAllConfigValues()['diff_mobile'] ?? 0),
            'warmupSettings' => WarmupConfig::getAll(),
            'profiles' => WarmupConfig::getProfiles(),
            'lastState' => $lastState,
            'blacklist' => $blacklist,
            'sitemapUrl' => \Configuration::getGlobalValue('LITESPEED_WARMUP_SITEMAP') ?: $this->detectSitemapUrl(),
            'shopUrl' => \Configuration::getGlobalValue('LITESPEED_WARMUP_SHOP_URL') ?: '',
            'userAgent' => \Configuration::getGlobalValue('LITESPEED_WARMUP_USERAGENT') ?: '',
            'cliCommand' => 'php bin/console litespeedcache:warmup',
        ], $request);
    }

    /**
     * AJAX: returns all URLs to crawl, grouped by phase.
     */
    public function urlsAction(Request $request): JsonResponse
    {
        @set_time_limit(300);

        $sitemap = trim($request->request->get('sitemap_url', ''));
        if (empty($sitemap)) {
            return new JsonResponse(['success' => false, 'error' => 'Please enter a valid Sitemap XML URL.'], 400);
        }

        $sitemapUrls = $this->parseSitemap($sitemap);
        if (empty($sitemapUrls)) {
            return new JsonResponse(['success' => false, 'error' => 'No URLs found in the sitemap.'], 400);
        }

        $cookie = $this->getDefaultCookies($sitemapUrls[0]);

        return new JsonResponse([
            'success' => true,
            'sitemapUrls' => $sitemapUrls,
            'cookie' => $cookie,
        ]);
    }

    /**
     * AJAX: returns current server load for throttling.
     */
    public function loadCheckAction(): JsonResponse
    {
        $limit = (float) WarmupConfig::get(WarmupConfig::SERVER_LOAD_LIMIT);
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
        $current = round($load[0], 2);

        return new JsonResponse([
            'load' => $current,
            'limit' => $limit,
            'ok' => $limit <= 0 || $current < $limit,
        ]);
    }

    /**
     * AJAX: crawls a single URL and returns the result with cache headers.
     */
    public function crawlAction(Request $request): JsonResponse
    {
        $url = $request->request->get('url', '');
        $userAgent = $request->request->get('useragent', 'lscache_runner');
        $cookie = $request->request->get('cookie', '');
        $timeout = (int) WarmupConfig::get(WarmupConfig::CRAWL_TIMEOUT) ?: 30;

        if (empty($url)) {
            return new JsonResponse(['success' => false, 'error' => 'No URL provided'], 400);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent ?: 'lscache_runner');

        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $lscache = $this->extractHeader($headers, 'X-LiteSpeed-Cache');
        $cfCache = $this->extractHeader($headers, 'CF-Cache-Status');
        $crawlOk = in_array($httpCode, [200, 201]);

        // Verify: second GET with only _lscache_vary cookie (guest mode).
        // PrestaShop requires cookies, but LiteSpeed only caches public pages
        // when the vary cookie indicates a guest visitor. Session cookies
        // (PHPSESSID, PrestaShop-*) prevent cache serving.
        $cached = false;
        if ($crawlOk) {
            // Extract the _lscache_vary value from the first response
            $varyCookie = '_lscache_vary=guest';
            if (preg_match('/^Set-Cookie:\s*(_lscache_vary=[^;]+)/mi', $headers, $varyMatch)) {
                $varyCookie = trim($varyMatch[1]);
            }

            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_HEADER, true);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch2, CURLOPT_MAXREDIRS, 1);
            curl_setopt($ch2, CURLOPT_ENCODING, '');
            curl_setopt($ch2, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch2, CURLOPT_URL, $url);
            curl_setopt($ch2, CURLOPT_TIMEOUT, max(10, (int) ($timeout / 2)));
            curl_setopt($ch2, CURLOPT_USERAGENT, $userAgent ?: 'lscache_runner');
            // Only the vary cookie — no session cookies
            curl_setopt($ch2, CURLOPT_COOKIE, $varyCookie);

            $verifyResponse = curl_exec($ch2);
            $verifyHeaderSize = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
            curl_close($ch2);

            $verifyHeaders = substr($verifyResponse, 0, $verifyHeaderSize);
            $verifyLscache = $this->extractHeader($verifyHeaders, 'X-LiteSpeed-Cache');
            $verifyCfCache = $this->extractHeader($verifyHeaders, 'CF-Cache-Status');

            if (stripos($verifyLscache, 'hit') !== false) {
                $cached = true;
                $lscache = $verifyLscache;
            }
            if (stripos($verifyCfCache, 'HIT') !== false) {
                $cached = true;
                $cfCache = $verifyCfCache;
            }
        }

        return new JsonResponse([
            'success' => $crawlOk,
            'cached' => $cached,
            'url' => $url,
            'httpCode' => $httpCode,
            'lscache' => $lscache,
            'cfCache' => $cfCache,
            'error' => $error,
        ]);
    }

    /**
     * AJAX: saves the final crawl state for display on next page load.
     */
    public function saveStateAction(Request $request): JsonResponse
    {
        $state = [
            'date' => date('Y-m-d H:i:s'),
            'total' => (int) $request->request->get('total', 0),
            'success' => (int) $request->request->get('success', 0),
            'fail' => (int) $request->request->get('fail', 0),
            'stopped' => (bool) $request->request->get('stopped', false),
            'duration' => (int) $request->request->get('duration', 0),
            'urls' => json_decode($request->request->get('urls', '[]'), true) ?: [],
        ];

        // Keep only last 200 URLs in state
        if (count($state['urls']) > 200) {
            $state['urls'] = array_slice($state['urls'], -200);
        }

        \Configuration::updateGlobalValue(self::STATE_KEY, json_encode($state));

        return new JsonResponse(['success' => true]);
    }

    private function addToBlacklist(string $url): void
    {
        $list = json_decode(\Configuration::getGlobalValue(self::BLACKLIST_KEY) ?: '[]', true) ?: [];
        if (!in_array($url, $list)) {
            $list[] = $url;
            \Configuration::updateGlobalValue(self::BLACKLIST_KEY, json_encode($list));
        }
    }

    private function removeFromBlacklist(string $url): void
    {
        $list = json_decode(\Configuration::getGlobalValue(self::BLACKLIST_KEY) ?: '[]', true) ?: [];
        $list = array_values(array_filter($list, fn ($u) => $u !== $url));
        \Configuration::updateGlobalValue(self::BLACKLIST_KEY, json_encode($list));
    }

    private function extractHeader(string $headers, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function detectSitemapUrl(): string
    {
        $ssl = \Configuration::get('PS_SSL_ENABLED');
        $domain = $ssl ? \Configuration::get('PS_SHOP_DOMAIN_SSL') : \Configuration::get('PS_SHOP_DOMAIN');
        if (!$domain) {
            return '';
        }

        $baseUrl = ($ssl ? 'https://' : 'http://') . $domain;
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT') ?: 1;

        // PrestaShop generates sitemap as {id_shop}_index_sitemap.xml
        $sitemapPath = _PS_ROOT_DIR_ . '/' . $idShop . '_index_sitemap.xml';
        if (is_file($sitemapPath)) {
            return $baseUrl . '/' . $idShop . '_index_sitemap.xml';
        }

        // Fallback: check for sitemap.xml
        if (is_file(_PS_ROOT_DIR_ . '/sitemap.xml')) {
            return $baseUrl . '/sitemap.xml';
        }

        // Default to expected PrestaShop sitemap path
        return $baseUrl . '/' . $idShop . '_index_sitemap.xml';
    }

    private function parseSitemap(string $sitemapUrl): array
    {
        $content = $this->fetchUrl($sitemapUrl);
        if (!$content) {
            return [];
        }

        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            return [];
        }

        $urls = [];

        // Sitemap index: fetch each child sitemap
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $entry) {
                $childContent = $this->fetchUrl((string) $entry->loc);
                if (!$childContent) {
                    continue;
                }
                $childXml = @simplexml_load_string($childContent);
                if ($childXml !== false && isset($childXml->url)) {
                    foreach ($childXml->url as $item) {
                        $urls[] = (string) $item->loc;
                    }
                }
            }
        }

        if (isset($xml->url)) {
            foreach ($xml->url as $item) {
                $urls[] = (string) $item->loc;
            }
        }

        return $urls;
    }

    private function fetchUrl(string $url): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'lscache_runner');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $result = curl_exec($ch);
        curl_close($ch);

        return $result ?: '';
    }

    private function getDefaultCookies(string $url): string
    {
        $cookie = '_lscache_vary=' . uniqid('lscache');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'lscache_runner');
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        $buffer = curl_exec($ch);
        curl_close($ch);

        $matches = [];
        $cookies = [];
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $buffer, $matches);
        foreach ($matches[1] as $item) {
            $parsed = [];
            parse_str($item, $parsed);
            unset($parsed['_lscache_vary']);
            $cookies = array_merge($cookies, $parsed);
        }

        $parts = [];
        foreach ($cookies as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return implode('; ', $parts);
    }
}
