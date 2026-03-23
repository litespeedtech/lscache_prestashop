<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Command;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\WarmupConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WarmupLscacheCommand extends Command
{
    protected static $defaultName = 'litespeedcache:warmup';

    protected function configure(): void
    {
        $this->setDescription('Warm up LiteSpeed page cache by crawling the sitemap')
             ->addArgument('sitemap', InputArgument::REQUIRED, 'Sitemap XML URL')
             ->addArgument('useragent', InputArgument::OPTIONAL, 'Extra User-Agent string')
             ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Concurrent requests (overrides saved setting)')
             ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between batches in milliseconds (overrides saved setting)')
             ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout per URL in seconds (overrides saved setting)')
             ->addOption('load-limit', 'l', InputOption::VALUE_REQUIRED, 'Server load limit (overrides saved setting)')
             ->addOption('mobile', 'm', InputOption::VALUE_NONE, 'Also crawl with mobile user-agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(0);

        $settings = WarmupConfig::getAll();
        $concurrency = (int) ($input->getOption('concurrency') ?? $settings[WarmupConfig::CONCURRENT_REQUESTS]);
        $delayMs = (int) ($input->getOption('delay') ?? $settings[WarmupConfig::CRAWL_DELAY]);
        $timeout = (int) ($input->getOption('timeout') ?? $settings[WarmupConfig::CRAWL_TIMEOUT]);
        $loadLimit = (float) ($input->getOption('load-limit') ?? $settings[WarmupConfig::SERVER_LOAD_LIMIT]);
        $mobileCrawl = $input->getOption('mobile') || (bool) $settings[WarmupConfig::MOBILE_CRAWL];
        $mobileUA = $settings[WarmupConfig::MOBILE_USER_AGENT];

        $sitemap = $input->getArgument('sitemap');
        $urls = $this->parseSitemap($sitemap, $output);
        if (empty($urls)) {
            return Command::FAILURE;
        }

        // Load blacklist
        $blacklist = json_decode(\Configuration::getGlobalValue('LITESPEED_WARMUP_BLACKLIST') ?: '[]', true) ?: [];
        if (!empty($blacklist)) {
            $before = count($urls);
            $urls = array_values(array_filter($urls, function ($url) use ($blacklist) {
                return !in_array($url, $blacklist);
            }));
            $skipped = $before - count($urls);
            if ($skipped > 0) {
                $output->writeln("<comment>Skipped {$skipped} blacklisted URLs.</comment>");
            }
        }

        $cookie = $this->getDefaultCookies($urls[0], $timeout);

        $output->writeln("<info>Settings: concurrency={$concurrency}, delay={$delayMs}ms, timeout={$timeout}s, load_limit={$loadLimit}</info>");
        $output->writeln('');

        // Phase 1: Desktop
        $output->writeln('<info>[LSCache] Crawling ' . count($urls) . ' URLs...</info>');
        $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit);

        if ($cookie) {
            $output->writeln('<info>[LSCache] Crawling with cookies...</info>');
            $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit, $cookie);
        }

        $userAgent = $input->getArgument('useragent');
        if ($userAgent !== null) {
            $ua = 'lscache_runner;' . $userAgent;
            $output->writeln('<info>[LSCache] Crawling with extra User-Agent...</info>');
            $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit, '', $ua);

            if ($cookie) {
                $output->writeln('<info>[LSCache] Crawling with extra User-Agent + cookies...</info>');
                $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit, $cookie, $ua);
            }
        }

        // Phase 2: Mobile
        if ($mobileCrawl) {
            $output->writeln('<info>[Mobile] Crawling ' . count($urls) . ' URLs with mobile UA...</info>');
            $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit, '', $mobileUA);

            if ($cookie) {
                $output->writeln('<info>[Mobile] Crawling with mobile UA + cookies...</info>');
                $this->crawlUrls($urls, $output, $concurrency, $delayMs, $timeout, $loadLimit, $cookie, $mobileUA);
            }
        }

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    private function crawlUrls(
        array $urls,
        OutputInterface $output,
        int $concurrency,
        int $delayMs,
        int $timeout,
        float $loadLimit,
        string $cookie = '',
        string $userAgent = 'lscache_runner'
    ): void {
        $total = count($urls);
        $current = 0;
        $mh = curl_multi_init();
        $active = [];
        $queue = $urls;

        while (!empty($queue) || !empty($active)) {
            // Check server load
            if ($loadLimit > 0 && function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                while ($load[0] >= $loadLimit) {
                    $output->writeln("  <comment>Server load {$load[0]} >= {$loadLimit}, pausing 10s...</comment>");
                    sleep(10);
                    $load = sys_getloadavg();
                }
            }

            // Fill the pool
            while (count($active) < $concurrency && !empty($queue)) {
                $url = array_shift($queue);
                $ch = $this->getCurlHandler($url, $cookie, false, $userAgent, $timeout);
                curl_multi_add_handle($mh, $ch);
                $active[(int) $ch] = $url;

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }

            // Execute
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            // Collect results
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = (int) $ch;
                $url = $active[$id] ?? '?';
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                ++$current;
                $status = in_array($code, [200, 201]) ? 'success' : 'failed';
                $output->writeln("  {$current}/{$total} {$url} [{$code} {$status}]");

                if ($code === 428) {
                    $output->writeln('<error>Crawler feature not enabled on web server</error>');
                    // Drain remaining
                    foreach ($active as $ahId => $ahUrl) {
                        $ah = null;
                        // Can't easily get handle from ID, just break
                    }
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    curl_multi_close($mh);

                    return;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$id]);
            }

            // Wait for activity
            if ($running > 0) {
                curl_multi_select($mh, 1);
            }
        }

        curl_multi_close($mh);
    }

    private function getCurlHandler(string $url, string $cookie = '', bool $getHeader = false, string $userAgent = 'lscache_runner', int $timeout = 30)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, $getHeader);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        return $ch;
    }

    private function parseSitemap(string $sitemapUrl, OutputInterface $output): array
    {
        $content = $this->fetchUrl($sitemapUrl);
        if (!$content) {
            $output->writeln('<error>Cannot load sitemap: ' . $sitemapUrl . '</error>');

            return [];
        }

        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            $output->writeln('<error>Invalid XML in sitemap: ' . $sitemapUrl . '</error>');

            return [];
        }

        $urls = [];

        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $entry) {
                $childUrl = (string) $entry->loc;
                $childContent = $this->fetchUrl($childUrl);
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

        if (empty($urls)) {
            $output->writeln('<error>No URLs found in sitemap</error>');
        } else {
            $output->writeln('<info>Found ' . count($urls) . ' URLs in sitemap.</info>');
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

    private function getDefaultCookies(string $url, int $timeout = 30): string
    {
        $cookie = '_lscache_vary=' . uniqid('lscache');
        $ch = $this->getCurlHandler($url, $cookie, true, 'lscache_runner', $timeout);
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
        foreach ($cookies as $k => $v) {
            $parts[] = "{$k}={$v}";
        }

        return implode('; ', $parts);
    }
}
