<?php

namespace LiteSpeed\Cache\Command;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WarmupLscacheCommand extends Command
{
    protected static $defaultName = 'litespeedcache:warmup:lscache';

    protected function configure(): void
    {
        $this->setDescription('Warm up LiteSpeed page cache by crawling the sitemap')
             ->addArgument('sitemap', InputArgument::REQUIRED, 'Sitemap XML URL')
             ->addArgument('useragent', InputArgument::OPTIONAL, 'Extra User-Agent string');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sitemap = $input->getArgument('sitemap');

        $xml = @simplexml_load_file($sitemap);
        if (!$xml) {
            $output->writeln('<error>Cannot load sitemap: ' . $sitemap . '</error>');

            return Command::FAILURE;
        }

        $urls = [];
        foreach ($xml->url as $item) {
            $urls[] = (string) $item->loc;
        }

        if (empty($urls)) {
            $output->writeln('<error>No URLs found in sitemap</error>');

            return Command::FAILURE;
        }

        $cookie = $this->getDefaultCookies($urls[0]);

        $output->writeln('<info>[LSCache] Crawling ' . count($urls) . ' URLs...</info>');
        $this->crawlUrls($urls, $output);

        if ($cookie) {
            $output->writeln('<info>[LSCache] Crawling with default cookies...</info>');
            $this->crawlUrls($urls, $output, $cookie);
        }

        $userAgent = $input->getArgument('useragent');
        if ($userAgent !== null) {
            $ua = 'lscache_runner;' . $userAgent;
            $output->writeln('<info>[LSCache] Crawling with extra User-Agent...</info>');
            $this->crawlUrls($urls, $output, '', $ua);

            if ($cookie) {
                $output->writeln('<info>[LSCache] Crawling with extra User-Agent + cookies...</info>');
                $this->crawlUrls($urls, $output, $cookie, $ua);
            }
        }

        return Command::SUCCESS;
    }

    private function crawlUrls(array $urls, OutputInterface $output, string $cookie = '', string $userAgent = 'lscache_runner'): void
    {
        set_time_limit(0);
        $total = count($urls);
        $current = 0;

        foreach ($urls as $url) {
            ++$current;
            $ch = $this->getCurlHandler($url, $cookie, false, $userAgent);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 428) {
                $output->writeln('<error>Crawler feature not enabled on web server</error>');
                break;
            }

            $status = in_array($code, [200, 201]) ? 'success' : 'failed';
            $output->writeln("  {$current}/{$total} {$url} [{$code} {$status}]");
        }
    }

    private function getCurlHandler(string $url, string $cookie = '', bool $getHeader = false, string $userAgent = 'lscache_runner')
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        return $ch;
    }

    private function getDefaultCookies(string $url): string
    {
        $cookie = '_lscache_vary=' . uniqid('lscache');
        $ch = $this->getCurlHandler($url, $cookie, true);
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
