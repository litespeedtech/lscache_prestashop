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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
namespace LiteSpeed\Cache;

use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Warmup extends Command
{
    protected $output;

    protected function configure()
    {
        $this->setName('litespeedcache:warmup')
             ->setDescription('Warm up all cache layers: LiteSpeed, Redis and CDN')
             ->addArgument('sitemap', null, 'Sitemap XML URL')
             ->addArgument('useragent', null, 'Crawl with an extra UserAgent')
             ->addOption('shop-url', null, InputOption::VALUE_REQUIRED, 'Base shop URL for product pages');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $sitemap = $input->getArgument('sitemap');
        if ($sitemap === null) {
            $output->writeln('<error>Invalid or Missing Sitemap XML URL!</error>');
            return 1;
        }

        $xml = @simplexml_load_file($sitemap);
        if ($xml === false) {
            $output->writeln('<error>Could not load sitemap: ' . $sitemap . '</error>');
            return 1;
        }

        $sitemapUrls = $this->parseSitemap($xml);
        if (empty($sitemapUrls)) {
            $output->writeln('<error>No URLs found!</error>');
            return 1;
        }

        $cookie    = $this->getDefaultCookies($sitemapUrls[0]);
        $userAgent = $input->getArgument('useragent');
        $ua        = $userAgent !== null ? 'lscache_runner;' . $userAgent : 'lscache_runner';

        // Phase 1: LiteSpeed Cache
        $this->printHeader('LITESPEED CACHE — Full page cache (' . count($sitemapUrls) . ' URLs)');
        $this->crawlUrls($sitemapUrls, '', $ua);

        if ($cookie) {
            $this->printHeader('LITESPEED CACHE — With vary cookie (' . count($sitemapUrls) . ' URLs)');
            $this->crawlUrls($sitemapUrls, $cookie, $ua);
        }

        // Phase 2: Redis — Product prices
        $objCfg = ObjConfig::getAll();
        if (!empty($objCfg[ObjConfig::OBJ_ENABLE])) {
            $productUrls = $this->getProductUrls($input);
            if (!empty($productUrls)) {
                $this->printHeader('REDIS — Product prices (' . count($productUrls) . ' URLs)');
                $this->crawlUrls($productUrls, '', $ua);

                if ($cookie) {
                    $this->printHeader('REDIS — Product prices with vary cookie (' . count($productUrls) . ' URLs)');
                    $this->crawlUrls($productUrls, $cookie, $ua);
                }
            } else {
                $this->printHeader('REDIS — Skipped (No active products found)');
            }
        } else {
            $this->printHeader('REDIS — Skipped (Object cache not enabled)');
        }

        // Phase 3: CDN
        $cdnCfg = CdnConfig::getAll();
        if (!empty($cdnCfg[CdnConfig::CF_ENABLE])) {
            $this->printHeader('CDN — Cloudflare');
            $output->writeln('  URLs already requested through CDN in previous phases.');
            $output->writeln('  Check headers above for CDN cache status.');
        } else {
            $this->printHeader('CDN — Skipped (Cloudflare not enabled)');
        }

        $output->writeln("\n<info>Warm up completed!</info>");
        return 0;
    }

    private function printHeader(string $title): void
    {
        $sep = str_repeat('=', 60);
        $this->output->writeln("\n" . $sep);
        $this->output->writeln('  ' . $title);
        $this->output->writeln($sep . "\n");
    }

    private function parseSitemap(\SimpleXMLElement $xml): array
    {
        $urls = [];

        if (isset($xml->sitemap)) {
            $this->output->writeln('Detected sitemap index, loading child sitemaps...');
            foreach ($xml->sitemap as $entry) {
                $childUrl = (string) $entry->loc;
                $this->output->writeln('  Loading: ' . $childUrl);
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

    private function getProductUrls(InputInterface $input): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');

        $shopUrl = $input->getOption('shop-url');
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

    private function crawlUrls($urls, $cookie = '', $userAgent = 'lscache_runner')
    {
        set_time_limit(0);
        $acceptCode = [200, 201];
        $total   = count($urls);
        $current = 0;

        foreach ($urls as $url) {
            $current++;
            $ch = $this->getCurlHandler($url, $cookie, true, $userAgent);
            $response   = curl_exec($ch);
            $httpcode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $headers  = substr($response, 0, $headerSize);
            $lscache  = $this->extractHeader($headers, 'X-LiteSpeed-Cache');
            $cfCache  = $this->extractHeader($headers, 'CF-Cache-Status');

            $cacheInfo = '';
            $parts = [];
            if ($lscache) {
                $parts[] = 'LiteSpeed: ' . $lscache;
            }
            if ($cfCache) {
                $parts[] = 'CDN: ' . $cfCache;
            }
            if ($parts) {
                $cacheInfo = ' [' . implode(' | ', $parts) . ']';
            }

            if (in_array($httpcode, $acceptCode)) {
                $this->output->writeln('  ' . $current . '/' . $total . '  ' . $url . '  success ' . $httpcode . $cacheInfo);
            } elseif ($httpcode == 428) {
                $this->output->writeln('  <error>Web Server crawler feature not enabled</error>');
                break;
            } else {
                $this->output->writeln('  ' . $current . '/' . $total . '  ' . $url . '  failed ' . $httpcode . $cacheInfo);
            }
        }
    }

    private function extractHeader(string $headers, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function getCurlHandler($url, $cookie = '', $getHeader = false, $userAgent = 'lscache_runner')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, $getHeader);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        return $ch;
    }

    private function getDefaultCookies($url)
    {
        $cookie = '_lscache_vary=' . uniqid('lscache');
        $ch     = $this->getCurlHandler($url, $cookie, true);
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
