<?php

/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Hook\Action;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Service\Esi\EsiMarkerManager;

/** Handles ESI begin/end hooks for Smarty-driven ESI blocks. */
class EsiHookHandler
{
    private CacheConfig $config;
    private EsiMarkerManager $markerManager;

    public function __construct(CacheConfig $config, EsiMarkerManager $markerManager)
    {
        $this->config = $config;
        $this->markerManager = $markerManager;
    }

    public function onEsiBegin(array $params): string
    {
        if (!CacheState::canInjectEsi()) {
            return '';
        }

        $err = 0;
        $errFld = '';
        $m = $params['m'] ?? null;
        $f = $params['field'] ?? null;

        if ($m === null) {
            $err |= 1;
            $errFld .= 'm ';
        }
        if ($f === null) {
            $err |= 1;
            $errFld .= 'field ';
        }
        if ($this->markerManager->hasActiveTracker()) {
            $err |= 2;
        }

        $esiParam = ['pt' => EsiItem::ESI_SMARTYFIELD, 'm' => $m, 'f' => $f];
        if ($f === 'widget' && isset($params['hook'])) {
            $esiParam['h'] = $params['hook'];
        } elseif ($f === 'widget_block') {
            if (isset($params['tpl'])) {
                $esiParam['t'] = $params['tpl'];
            } else {
                $err |= 1;
                $errFld .= 'tpl ';
            }
        }

        $conf = $this->config->canInjectEsi($m, $esiParam);
        if ($conf === false) {
            $err |= 4;
        }

        $this->markerManager->pushTracker($err);

        if ($err) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CUST_SMARTY) {
                $msg = '';
                if ($err & 1) {
                    $msg .= 'Missing param (' . $errFld . '). ';
                }
                if ($err & 2) {
                    $msg .= 'Nested hookLitespeedEsiBegin ignored. ';
                }
                if ($err & 4) {
                    $msg .= 'Cannot inject ESI for ' . $m;
                }
                LSLog::log(__METHOD__ . ' ' . $msg, LSLog::LEVEL_CUST_SMARTY);
            }

            return '';
        }

        return $this->markerManager->registerMarker($esiParam, $conf);
    }

    public function onEsiEnd(array $params): string
    {
        if (!CacheState::canInjectEsi()) {
            return '';
        }

        $res = $this->markerManager->popTracker();
        if ($res === 0) {
            return \LiteSpeedCache::ESI_MARKER_END;
        }

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_CUST_SMARTY) {
            $err = ($res === null)
                ? ' Mismatched hookLitespeedEsiEnd detected'
                : ' Ignored hookLitespeedEsiEnd due to error in hookLitespeedEsiBegin';
            LSLog::log(__METHOD__ . $err, LSLog::LEVEL_CUST_SMARTY);
        }

        return '';
    }
}
