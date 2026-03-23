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
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * CacheLogger — structured debug logger for LiteSpeedCache.
 *
 * Provides levelled logging to var/logs/lscache.log.
 * Designed as a singleton so state (isDebug level) persists for the request lifecycle.
 */
class CacheLogger
{
    // Log levels — numbers can overlap intentionally; used for threshold comparison only
    public const LEVEL_FORCE = 0;
    public const LEVEL_FOOTER_COMMENT = 3.5;
    public const LEVEL_EXCEPTION = 1;
    public const LEVEL_UNEXPECTED = 2;
    public const LEVEL_NOTICE = 3;
    public const LEVEL_CUST_SMARTY = 3;
    public const LEVEL_UPDCONFIG = 3;
    public const LEVEL_SETHEADER = 4;
    public const LEVEL_ENVCOOKIE_CHANGE = 5;
    public const LEVEL_PURGE_EVENT = 5;
    public const LEVEL_NOCACHE_REASON = 6;
    public const LEVEL_ESI_INCLUDE = 7;
    public const LEVEL_CACHE_ROUTE = 8;
    public const LEVEL_ENVCOOKIE_DETAIL = 9;
    public const LEVEL_WEBSERVICE_DETAIL = 9;
    public const LEVEL_SPECIFIC_PRICE = 9.5;
    public const LEVEL_ESI_OUTPUT = 9.5;
    public const LEVEL_SAVED_DATA = 10;
    public const LEVEL_HOOK_DETAIL = 10;
    public const LEVEL_TEMPORARY = 8.5;

    /** @var \FileLogger|null */
    protected $logger;

    /** @var string */
    protected $prefix;

    /** @var int|float */
    private $isDebug = 0;

    /** @var self|null */
    protected static $instance;

    /** @var array|null Cached debug filters to avoid re-reading config on every log call */
    private $debugFilters;

    /** @var bool Guard against recursive calls from CacheConfig::init() */
    private $filtering = false;

    /**
     * @return static
     */
    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    protected function __construct()
    {
        $signature = [];
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $signature[] = $_SERVER['REMOTE_ADDR'];
        }
        if (isset($_SERVER['REMOTE_PORT'])) {
            $signature[] = $_SERVER['REMOTE_PORT'];
        } elseif (isset($_SERVER['USER'])) {
            $signature[] = $_SERVER['USER'];
        }
        preg_match("/\d{6}/", microtime(), $msec);
        $signature[] = $msec[0];
        $this->prefix = '[' . implode(':', $signature) . ']';
    }

    protected function getLogger(): \FileLogger
    {
        if ($this->logger === null) {
            $this->logger = new \FileLogger(\FileLogger::DEBUG);

            if (version_compare(_PS_VERSION_, '1.7.0.0', '<')) {
                $path = '/log';
            } elseif (version_compare(_PS_VERSION_, '1.7.3', '<=')) {
                $path = '/app/logs';
            } else {
                $path = '/var/logs';
            }
            $this->logger->setFilename(_PS_ROOT_DIR_ . $path . '/lscache.log');
        }

        return $this->logger;
    }

    /**
     * @param string $mesg
     * @param int|float $debugLevel
     */
    protected function logDebug(string $mesg, $debugLevel): void
    {
        if ($this->isDebug >= $debugLevel) {
            if (!$this->passesDebugFilters($mesg)) {
                return;
            }
            if ($debugLevel == self::LEVEL_EXCEPTION) {
                ob_start();
                debug_print_backtrace(0, 30);
                $trace = ob_get_contents();
                ob_end_clean();
                $mesg .= "\n" . $trace;
            } elseif ($debugLevel == self::LEVEL_UNEXPECTED) {
                $mesg = '!!!!! UNEXPECTED !!!!! ' . $mesg;
            } elseif ($debugLevel == self::LEVEL_NOTICE) {
                $mesg = '!!! NOTICE !!! ' . $mesg;
            } elseif ($debugLevel == self::LEVEL_TEMPORARY) {
                $mesg .= ' ###############';
            }
            if ($debugLevel != self::LEVEL_TEMPORARY) {
                $mesg = str_replace("\n", "\n" . $this->prefix . '  ', $mesg);
            }
            $this->getLogger()->logDebug($this->prefix . ' (' . $debugLevel . ') ' . $mesg);
        }
    }

    /**
     * @param int|float $debugLevel
     */
    public static function setDebugLevel($debugLevel): void
    {
        self::getInstance()->isDebug = $debugLevel;
    }

    /**
     * @param string $mesg
     * @param int|float $debugLevel
     */
    public static function log(string $mesg, $debugLevel = 9): void
    {
        self::getInstance()->logDebug($mesg, $debugLevel);
    }

    private function loadDebugFilters(): array
    {
        if ($this->debugFilters !== null) {
            return $this->debugFilters;
        }

        // Prevent recursion: CacheConfig::get() may trigger init() which logs
        if ($this->filtering) {
            return ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => ''];
        }

        $this->filtering = true;
        try {
            $config = \LiteSpeed\Cache\Config\CacheConfig::getInstance();
            $this->debugFilters = [
                'uri_inc' => trim($config->get(\LiteSpeed\Cache\Config\CacheConfig::CFG_DEBUG_URI_INC) ?? ''),
                'uri_exc' => trim($config->get(\LiteSpeed\Cache\Config\CacheConfig::CFG_DEBUG_URI_EXC) ?? ''),
                'str_exc' => trim($config->get(\LiteSpeed\Cache\Config\CacheConfig::CFG_DEBUG_STR_EXC) ?? ''),
            ];
        } catch (\Throwable $e) {
            $this->debugFilters = ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => ''];
        }
        $this->filtering = false;

        return $this->debugFilters;
    }

    private function passesDebugFilters(string $mesg): bool
    {
        $filters = $this->loadDebugFilters();
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        $uriInc = $filters['uri_inc'];
        if ($uriInc !== '') {
            $patterns = preg_split("/\r?\n/", $uriInc, -1, PREG_SPLIT_NO_EMPTY);
            $matched = false;
            foreach ($patterns as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                if ($this->matchUri($requestUri, $p)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        // URI exclude filter: skip log if URI matches
        $uriExc = $filters['uri_exc'];
        if ($uriExc !== '') {
            $patterns = preg_split("/\r?\n/", $uriExc, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($patterns as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                if ($this->matchUri($requestUri, $p)) {
                    return false;
                }
            }
        }

        // String exclude filter: skip log if message contains any listed string
        $strExc = $filters['str_exc'];
        if ($strExc !== '') {
            $strings = preg_split("/\r?\n/", $strExc, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($strings as $s) {
                $s = trim($s);
                if ($s !== '' && strpos($mesg, $s) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchUri(string $uri, string $pattern): bool
    {
        $exact = substr($pattern, -1) === '$';
        $start = strpos($pattern, '^') === 0;

        if ($exact) {
            $pattern = substr($pattern, 0, -1);
        }
        if ($start) {
            $pattern = substr($pattern, 1);
        }

        if ($start && $exact) {
            return $uri === $pattern;
        }
        if ($start) {
            return strpos($uri, $pattern) === 0;
        }
        if ($exact) {
            return substr($uri, -strlen($pattern)) === $pattern;
        }

        return strpos($uri, $pattern) !== false;
    }
}
