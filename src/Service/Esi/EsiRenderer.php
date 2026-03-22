<?php

declare(strict_types=1);

/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Service\Esi;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/**
 * EsiRenderer -- handles ESI injection for widget rendering and hook calls.
 *
 * Extracted from LiteSpeedCache main module class to isolate the ESI injection
 * entry points used by PrestaShop hook dispatching.
 */
class EsiRenderer
{
    private CacheConfig $config;
    private EsiMarkerManager $markerManager;

    public function __construct(CacheConfig $config, EsiMarkerManager $markerManager)
    {
        $this->config = $config;
        $this->markerManager = $markerManager;
    }

    /**
     * Attempt to inject an ESI marker for a renderWidget call.
     *
     * @param object $module The PrestaShop module instance
     * @param string $hook_name The hook being rendered
     * @param array|false $params Hook parameters (includes smarty, etc.)
     *
     * @return string|false ESI marker string on success, false if injection is not applicable
     */
    public function injectRenderWidget($module, string $hook_name, $params = false)
    {
        if (!CacheState::canInjectEsi()) {
            return false;
        }
        $m = $module->name;
        $esiParam = ['pt' => EsiItem::ESI_RENDERWIDGET, 'm' => $m, 'h' => $hook_name];
        $conf = $this->config->canInjectEsi($m, $esiParam);
        if ($conf === false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log(__FUNCTION__ . " $m : $hook_name", LSLog::LEVEL_ESI_INCLUDE);
        }
        $mp = $this->getModuleParams($params, $conf->getTemplateArgs());
        if (!empty($mp)) {
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $this->markerManager->registerMarker($esiParam, $conf);
    }

    /**
     * Attempt to inject an ESI marker for a hook call (non-widget).
     *
     * @param object $module The PrestaShop module instance
     * @param string $method The hook method name
     * @param array|false $params Hook parameters
     *
     * @return string|false ESI marker string on success, false if injection is not applicable
     */
    public function injectCallHook($module, string $method, $params = false)
    {
        if (!CacheState::canInjectEsi()) {
            return false;
        }
        $m = $module->name;
        $esiParam = ['pt' => EsiItem::ESI_CALLHOOK, 'm' => $m, 'mt' => $method];
        $conf = $this->config->canInjectEsi($m, $esiParam);
        if ($conf === false) {
            return false;
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log(__FUNCTION__ . " $m : $method", LSLog::LEVEL_ESI_INCLUDE);
        }
        $mp = $this->getModuleParams($params, $conf->getTemplateArgs());
        if (!empty($mp)) {
            $esiParam['mp'] = json_encode($mp, JSON_UNESCAPED_UNICODE);
        }

        return $this->markerManager->registerMarker($esiParam, $conf);
    }

    /**
     * Extract module parameters from hook params based on template argument spec.
     *
     * @param array|false $params Hook parameters
     * @param string|null $tas Comma-separated template argument specifiers
     *
     * @return array|false Extracted parameter values, or false if none
     */
    private function getModuleParams($params, ?string $tas)
    {
        if (!$tas || !$params) {
            return false;
        }
        $smarty = $params['smarty'];
        $mp = [];
        foreach (explode(',', $tas) as $mv) {
            $parts = explode('.', trim($mv));
            if ($parts[0] === 'smarty') {
                $val = $smarty->getTemplateVars($parts[1]);
                $mp[] = (isset($parts[2]) && $val) ? $val->{$parts[2]} : $val;
            } else {
                $val = $params[$parts[0]];
                $mp[] = (isset($parts[1]) && $val) ? $val[$parts[1]] : $val;
            }
        }

        return $mp;
    }
}
