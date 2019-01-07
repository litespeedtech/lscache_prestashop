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

class LiteSpeedCacheLog
{
    // LEVEL is by meaning only, number can be duplicated
    const LEVEL_FORCE = 0;
    const LEVEL_FOOTER_COMMENT = 3.5;
    const LEVEL_EXCEPTION = 1;
    const LEVEL_UNEXPECTED = 2;
    const LEVEL_NOTICE = 3;
    const LEVEL_CUST_SMARTY = 3;
    const LEVEL_UPDCONFIG = 3;
    const LEVEL_SETHEADER = 4;
    const LEVEL_ENVCOOKIE_CHANGE = 5;
    const LEVEL_PURGE_EVENT = 5;
    const LEVEL_NOCACHE_REASON = 6;
    const LEVEL_ESI_INCLUDE = 7;
    const LEVEL_CACHE_ROUTE = 8;
    const LEVEL_ENVCOOKIE_DETAIL = 9;
    const LEVEL_SPECIFIC_PRICE = 9.5;
    const LEVEL_ESI_OUTPUT = 9.5;
    const LEVEL_SAVED_DATA = 10;
    const LEVEL_HOOK_DETAIL = 10;
    const LEVEL_TEMPORARY = 8.5;

    protected $logger;
    protected $prefix;
    private $isDebug = 0;
    protected static $instance = null;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new LiteSpeedCacheLog();
        }
        return self::$instance;
    }

    protected function __construct()
    {
        preg_match("/\d{6}/", microtime(), $msec);
        $this->prefix = '[' . $_SERVER['REMOTE_ADDR'] . ':' . $_SERVER['REMOTE_PORT'] . ':' . $msec[0] . ']';
    }

    protected function getLogger()
    {
        if ($this->logger == null) {
            $this->logger = new FileLogger(FileLogger::DEBUG);
            $path = version_compare(_PS_VERSION_, '1.7.0.0', '>=') ? '/app/logs' : '/log';
            $this->logger->setFilename(_PS_ROOT_DIR_ . $path . '/lscache.log');
        }
        return $this->logger;
    }

    protected function logDebug($mesg, $debugLevel)
    {
        if ($this->isDebug >= $debugLevel) {
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
                $mesg = str_replace("\n", ("\n" . $this->prefix . '  '), $mesg);
            }
            $this->getLogger()->logDebug($this->prefix . ' (' . $debugLevel . ') ' . $mesg);
        }
    }

    public static function setDebugLevel($debugLevel)
    {
        self::getInstance()->isDebug = $debugLevel;
    }

    public static function log($mesg, $debugLevel = 9)
    {
        self::getInstance()->logDebug($mesg, $debugLevel);
    }
}
