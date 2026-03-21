<?php
namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WarmupController extends FrameworkBundleAdminController
{
    use NavPillsTrait;


    public function indexAction(Request $request): Response
    {
        $objCfg = ObjConfig::getAll();
        $cdnCfg = CdnConfig::getAll();

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/warmup.html.twig', [
            'urlsAction'   => $this->generateUrl('admin_litespeedcache_warmup_urls'),
            'crawlAction'  => $this->generateUrl('admin_litespeedcache_warmup_crawl'),
            'redisEnabled' => !empty($objCfg[ObjConfig::OBJ_ENABLE]),
            'cdnEnabled'   => !empty($cdnCfg[CdnConfig::CF_ENABLE]),
        ], $request);
    }

    /**
     * AJAX: returns all URLs to crawl, grouped by phase.
     */
    public function urlsAction(Request $request): JsonResponse
    {
        $sitemap = trim($request->request->get('sitemap_url', ''));
        if (empty($sitemap)) {
            return new JsonResponse(['success' => false, 'error' => 'Please enter a valid Sitemap XML URL.'], 400);
        }

        $sitemapUrls = $this->parseSitemap($sitemap);
        if (empty($sitemapUrls)) {
            return new JsonResponse(['success' => false, 'error' => 'No URLs found in the sitemap.'], 400);
        }

        $productUrls = $this->getProductUrls($request);
        $cookie = $this->getDefaultCookies($sitemapUrls[0]);

        return new JsonResponse([
            'success'     => true,
            'sitemapUrls' => $sitemapUrls,
            'productUrls' => $productUrls,
            'cookie'      => $cookie,
        ]);
    }

    /**
     * AJAX: crawls a single URL and returns the result with cache headers.
     */
    public function crawlAction(Request $request): JsonResponse
    {
        $url       = $request->request->get('url', '');
        $userAgent = $request->request->get('useragent', 'lscache_runner');
        $cookie    = $request->request->get('cookie', '');

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent ?: 'lscache_runner');

        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error      = curl_error($ch);
        curl_close($ch);

        $headers    = substr($response, 0, $headerSize);
        $lscache    = $this->extractHeader($headers, 'X-LiteSpeed-Cache');
        $cfCache    = $this->extractHeader($headers, 'CF-Cache-Status');

        return new JsonResponse([
            'success'  => in_array($httpCode, [200, 201]),
            'url'      => $url,
            'httpCode' => $httpCode,
            'lscache'  => $lscache,
            'cfCache'  => $cfCache,
            'error'    => $error,
        ]);
    }

    private function extractHeader(string $headers, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function getProductUrls(Request $request): array
    {
        $shopUrl = trim($request->request->get('shop_url', ''));
        $idLang  = (int) \Configuration::get('PS_LANG_DEFAULT');
        $idShop  = (int) \Configuration::get('PS_SHOP_DEFAULT');

        if (!$shopUrl) {
            $ssl    = \Configuration::get('PS_SSL_ENABLED');
            $domain = $ssl ? \Configuration::get('PS_SHOP_DOMAIN_SSL') : \Configuration::get('PS_SHOP_DOMAIN');
            $shopUrl = ($ssl ? 'https://' : 'http://') . $domain;
        }

        $sql = new \DbQuery();
        $sql->select('p.id_product, pl.link_rewrite');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int) $idShop);
        $sql->innerJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . (int) $idShop);
        $sql->where('p.active = 1');
        $sql->orderBy('p.id_product ASC');

        $products = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        if (empty($products)) {
            return [];
        }

        $context = \Context::getContext();
        if (!$context->link) {
            $context->link = new \Link();
        }

        $urls    = [];
        $shopUrl = rtrim($shopUrl, '/');

        foreach ($products as $product) {
            try {
                $url = $context->link->getProductLink((int) $product['id_product'], $product['link_rewrite'], null, null, $idLang, $idShop);
            } catch (\Throwable $e) {
                $url = $shopUrl . '/' . $product['link_rewrite'] . '.html';
            }
            if ($url) {
                if (strpos($url, 'http') !== 0) {
                    $url = $shopUrl . '/' . ltrim($url, '/');
                }
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function parseSitemap(string $sitemapUrl): array
    {
        $xml = @simplexml_load_file($sitemapUrl);
        if ($xml === false) {
            return [];
        }

        $urls = [];

        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $entry) {
                $childUrl = (string) $entry->loc;
                $childXml = @simplexml_load_file($childUrl);
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
