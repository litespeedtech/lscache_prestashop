<?php
/**
 * LiteSpeed Cache for PrestaShop.
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
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license  https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Hook\Display;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

class FrontDisplayHookHandler
{
    /** @var CacheConfig */
    private $config;

    public function __construct(CacheConfig $config)
    {
        $this->config = $config;
    }

    public function onDisplayFooterAfter(array $params): string
    {
        $output = '';

        // Cache debug panel — shown when debug headers are enabled
        if ($this->config->get(CacheConfig::CFG_DEBUG_HEADER)) {
            $output .= $this->renderDebugPanel();
        }

        if (!CacheState::isCacheable() || !_LITESPEED_DEBUG_) {
            return $output;
        }
        $comment = isset($_SERVER['HTTP_USER_AGENT'])
            ? '<!-- LiteSpeed Cache created with user_agent: ' . $_SERVER['HTTP_USER_AGENT'] . ' -->' . PHP_EOL
            : '<!-- LiteSpeed Cache snapshot generated at ' . gmdate('Y/m/d H:i:s') . ' GMT -->';

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_FOOTER_COMMENT) {
            LSLog::log('Add html comments in footer ' . $comment, LSLog::LEVEL_FOOTER_COMMENT);
        }

        return $output . $comment;
    }

    public function onOverrideLayoutTemplate(array $params, CacheManager $cache): void
    {
        if (!CacheState::isCacheable()) {
            return;
        }
        if ($cache->hasNotification()) {
            CacheState::markNotCacheable('Has private notification');
        } elseif (!CacheState::isEsiRequest()) {
            $cache->initCacheTagsByController($params);
        }
    }

    public function onDisplayOverrideTemplate(array $params, CacheManager $cache): void
    {
        if (!CacheState::isCacheable()) {
            return;
        }
        $cache->initCacheTagsByController($params);
    }

    /**
     * Renders a debug panel that updates via AJAX on every page visit.
     * Works even when LiteSpeed serves the page from cache (hit).
     */
    private function renderDebugPanel(): string
    {
        $entity = $this->detectEntity();
        $entityLabel = $entity['type'] . ($entity['id'] ? ' #' . $entity['id'] : '');

        // Read response headers already set by the module
        $debugHeaders = [];
        foreach (headers_list() as $h) {
            if (stripos($h, 'X-LiteSpeed') === 0 || stripos($h, 'X-LSCACHE') === 0) {
                [$name, $value] = explode(':', $h, 2);
                $debugHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        // Status items
        $items = [];
        if ($entityLabel) {
            $items[] = $this->debugRow('Entity', $entityLabel, '#fff');
        }

        // LiteSpeed
        $lsStatus = CacheState::isCacheable() ? 'CACHEABLE' : 'NO-CACHE';
        $lsColor = CacheState::isCacheable() ? '#70b580' : '#e84e6a';
        if (CacheConfig::isBypassed()) {
            $lsStatus = 'BYPASS';
            $lsColor = '#e84e6a';
        }
        $items[] = $this->debugRow('LiteSpeed', $lsStatus, $lsColor);

        // Redis
        $redisStatus = 'OFF';
        $redisColor = '#6c757d';
        if (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis') {
            $cache = \Cache::getInstance();
            $redisStatus = ($cache && method_exists($cache, 'isConnected') && $cache->isConnected()) ? 'HIT' : 'ERR';
            $redisColor = $redisStatus === 'HIT' ? '#70b580' : '#e84e6a';
        }
        $items[] = $this->debugRow('Redis', $redisStatus, $redisColor);

        // CDN
        $cdnCfg = CdnConfig::getAll();
        $cdnStatus = !empty($cdnCfg[CdnConfig::CF_ENABLE]) ? 'ON' : 'OFF';
        $items[] = $this->debugRow('CDN', $cdnStatus, $cdnStatus === 'ON' ? '#25b9d7' : '#6c757d');

        // ESI
        $esiOn = CacheState::canInjectEsi();
        $items[] = $this->debugRow('ESI', $esiOn ? 'ON' : 'OFF', $esiOn ? '#70b580' : '#6c757d');

        // Guest
        $guestOn = (bool) $this->config->get(CacheConfig::CFG_GUESTMODE);
        $items[] = $this->debugRow('Guest', $guestOn ? 'ON' : 'OFF', $guestOn ? '#70b580' : '#6c757d');

        // TTL
        $ttl = (int) ($this->config->get(CacheConfig::CFG_PUBLIC_TTL) ?: 0);
        $items[] = $this->debugRow('TTL', $ttl > 0 ? $this->formatTtl($ttl) : 'OFF', $ttl > 0 ? '#70b580' : '#6c757d');

        // Build debug headers section
        $debugSection = '';
        if (!empty($debugHeaders)) {
            $debugSection .= '<div style="border-top:1px solid #444;margin-top:4px;padding-top:4px">'
                . '<span style="color:#25b9d7;font-weight:bold;font-size:10px">LiteSpeed Debug</span></div>';

            foreach ($debugHeaders as $name => $value) {
                $formatted = $this->formatHeaderValue($name, $value);
                $debugSection .= '<div style="margin-bottom:3px">'
                    . '<span style="color:#6c757d">' . htmlspecialchars($name) . '</span><br>'
                    . $formatted . '</div>';
            }
        }

        $html = '<div id="lsc-debug-panel" style="'
            . 'position:fixed;top:50%;right:0;z-index:99999;'
            . 'transform:translateY(-50%);'
            . 'width:240px;max-height:90vh;overflow-y:auto;'
            . 'background:rgba(30,30,30,.92);color:#ccc;'
            . 'font-size:11px;padding:8px 10px;'
            . 'display:flex;flex-direction:column;gap:3px;'
            . 'font-family:monospace;'
            . 'border-left:2px solid #25b9d7;'
            . 'border-radius:6px 0 0 6px;">'
            . '<span style="color:#25b9d7;font-weight:bold;text-align:center;margin-bottom:4px;font-size:12px;">&#9889; CACHE STATUS</span>'
            . implode('', $items)
            . $debugSection
            . '<div id="lsc-debug-lsheaders"></div>'
            . '<span style="color:#6c757d;font-size:9px;text-align:center;margin-top:2px;cursor:pointer" '
            . 'onclick="this.parentElement.style.display=\'none\'">click to dismiss</span>'
            . '</div>'
            . '<script>'
            . 'var x=new XMLHttpRequest();x.open("HEAD",location.href,true);'
            . 'x.onload=function(){'
            . 'var el=document.getElementById("lsc-debug-lsheaders");if(!el)return;'
            . 'var hn=["x-litespeed-cache","x-lscache-debug-cc","x-lscache-debug-info","x-lscache-debug-tag","x-lscache-debug-vary"];'
            . 'var o="";'
            . 'function fmtV(v){'
            . 'try{'
            . 'var p=v.indexOf("{");if(p<0)return v;'
            . 'var s=v.substring(0,p).trim();var j=JSON.parse(v.substring(p));'
            . 'var b="margin:2px 0;padding:3px 5px;background:rgba(255,255,255,.07);border-radius:3px;font-size:10px";'
            . 'var r="<span style=\"color:#70b580;font-weight:bold\">"+s+"</span>";'
            . 'var cv=j.cv||{};'
            . 'r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">Cookie Vary</div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">name:</span> "+(cv.name||"-")+"</div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">value:</span> "+(cv.nv||cv.ov||"-")+"</div>";'
            . 'if(cv.data){var dk=Object.keys(cv.data);for(var k=0;k<dk.length;k++){r+="<span style=\"display:inline-block;background:#25b9d7;color:#000;padding:0 3px;border-radius:2px;margin:1px;font-size:9px\">"+dk[k]+"="+cv.data[dk[k]]+"</span>";}}'
            . 'r+="</div>";'
            . 'var vv=j.vv||{};r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">Vary Value</div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">original:</span> "+(vv.ov||"null")+"</div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">new:</span> "+(vv.nv||"null")+"</div></div>";'
            . 'var ps=j.ps||{};r+="<div style=\""+b+"\"><div style=\"color:#25b9d7\">PS Session</div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">original:</span> <span style=\"word-break:break-all\">"+(ps.ov||"null")+"</span></div>";'
            . 'r+="<div><span style=\"color:#adb5bd\">new:</span> <span style=\"word-break:break-all\">"+(ps.nv||"null")+"</span></div></div>";'
            . 'return r;'
            . '}catch(e){return v;}'
            . '}'
            . 'function fmtT(v){var t=v.split(",");var r="";for(var i=0;i<t.length;i++){r+="<span style=\"display:inline-block;background:#25b9d7;color:#000;padding:0 4px;border-radius:3px;margin:1px;font-size:10px\">"+t[i].trim()+"</span>";}return r;}'
            . 'function fmtC(v){var c=v.toLowerCase()==="hit"?"#70b580":v.toLowerCase()==="miss"?"#f0ad4e":"#e84e6a";return "<span style=\"color:"+c+";font-weight:bold\">"+v.toUpperCase()+"</span>";}'
            . 'for(var i=0;i<hn.length;i++){'
            . 'var v=x.getResponseHeader(hn[i]);'
            . 'if(!v)continue;'
            . 'var fv=v;'
            . 'if(hn[i].indexOf("vary")>-1)fv=fmtV(v);'
            . 'else if(hn[i].indexOf("tag")>-1)fv=fmtT(v);'
            . 'else if(hn[i]==="x-litespeed-cache")fv=fmtC(v);'
            . 'o+="<div style=\"margin-bottom:3px\"><span style=\"color:#6c757d\">"+hn[i]+"</span><br>"+fv+"</div>";'
            . '}'
            . 'if(o)el.innerHTML="<div style=\"border-top:1px solid #444;margin-top:4px;padding-top:4px\"><span style=\"color:#25b9d7;font-weight:bold;font-size:10px\">LiteSpeed Headers</span></div>"+o;'
            . '};x.send();'
            . '</script>';

        return $html;
    }

    /**
     * Detects the current entity type and ID from the controller context.
     */
    private function detectEntity(): array
    {
        $result = ['type' => '', 'id' => 0];

        try {
            $context = \Context::getContext();
            $controller = $context->controller ?? null;
            if (!$controller) {
                return $result;
            }

            $class = get_class($controller);

            if (isset($controller->php_self)) {
                $page = $controller->php_self;
            } elseif (method_exists($controller, 'getPageName')) {
                $page = $controller->getPageName();
            } else {
                $page = '';
            }

            switch ($page) {
                case 'product':
                    $result['type'] = 'Product';
                    $result['id'] = (int) \Tools::getValue('id_product');
                    break;
                case 'category':
                    $result['type'] = 'Category';
                    $result['id'] = (int) \Tools::getValue('id_category');
                    break;
                case 'cms':
                    $result['type'] = 'CMS';
                    $result['id'] = (int) \Tools::getValue('id_cms');
                    break;
                case 'manufacturer':
                    $result['type'] = 'Brand';
                    $result['id'] = (int) \Tools::getValue('id_manufacturer');
                    break;
                case 'supplier':
                    $result['type'] = 'Supplier';
                    $result['id'] = (int) \Tools::getValue('id_supplier');
                    break;
                case 'index':
                    $result['type'] = 'Home';
                    break;
                case 'search':
                    $result['type'] = 'Search';
                    break;
                case 'cart':
                    $result['type'] = 'Cart';
                    break;
                case 'order':
                case 'checkout':
                    $result['type'] = 'Checkout';
                    break;
                case 'my-account':
                    $result['type'] = 'Account';
                    break;
                case 'pagenotfound':
                    $result['type'] = '404';
                    break;
                case 'best-sales':
                    $result['type'] = 'Best Sales';
                    break;
                case 'new-products':
                    $result['type'] = 'New Products';
                    break;
                case 'prices-drop':
                    $result['type'] = 'Prices Drop';
                    break;
                default:
                    if ($page) {
                        $result['type'] = $page;
                    } else {
                        $result['type'] = str_replace('Controller', '', $class);
                    }
            }
        } catch (\Throwable $e) {
            $result['type'] = 'Unknown';
        }

        return $result;
    }

    private function debugRow(string $label, string $value, string $color): string
    {
        return '<div style="display:flex;justify-content:space-between;gap:8px">'
            . '<span>' . $label . '</span>'
            . '<strong style="color:' . $color . '">' . $value . '</strong></div>';
    }

    private function formatTtl(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }

    private function formatHeaderValue(string $name, string $value): string
    {
        // Tags — show as pills
        if (strpos($name, 'tag') !== false) {
            $tags = explode(',', $value);
            $pills = '';
            foreach ($tags as $t) {
                $pills .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 4px;border-radius:3px;margin:1px;font-size:10px">'
                    . htmlspecialchars(trim($t)) . '</span>';
            }

            return $pills;
        }

        // Vary — parse JSON and format nicely
        if (strpos($name, 'vary') !== false) {
            $jsonPos = strpos($value, '{');
            if ($jsonPos === false) {
                return '<span style="color:#fff">' . htmlspecialchars($value) . '</span>';
            }

            $status = trim(substr($value, 0, $jsonPos));
            $json = json_decode(substr($value, $jsonPos), true);
            if (!is_array($json)) {
                return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
            }

            $box = 'style="margin:2px 0;padding:3px 5px;background:rgba(255,255,255,.07);border-radius:3px;font-size:10px"';
            $out = '<span style="color:#70b580;font-weight:bold">' . htmlspecialchars($status) . '</span>';

            // Cookie Vary
            $cv = $json['cv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Cookie Vary</div>';
            $out .= '<div><span style="color:#adb5bd">name:</span> <span style="color:#fff">' . htmlspecialchars($cv['name'] ?? "\xe2\x80\x94") . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($cv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($cv['nv'] ?? 'null') . '</span></div>';
            if (!empty($cv['data']) && is_array($cv['data'])) {
                $out .= '<div><span style="color:#adb5bd">data:</span> ';
                foreach ($cv['data'] as $k => $v) {
                    $out .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 3px;border-radius:2px;margin:1px;font-size:9px">'
                        . htmlspecialchars($k) . '=' . htmlspecialchars((string) $v) . '</span>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';

            // Vary Value
            $vv = $json['vv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Vary Value</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($vv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($vv['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            // PS Session
            $ps = $json['ps'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">PS Session</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            return $out;
        }

        // Cache status — colored
        if (strpos($name, 'cache') !== false && strlen($value) < 10) {
            $v = strtoupper($value);
            if ($v === 'HIT') {
                $color = '#70b580';
            } elseif ($v === 'MISS') {
                $color = '#f0ad4e';
            } else {
                $color = '#e84e6a';
            }

            return '<span style="color:' . $color . ';font-weight:bold">' . htmlspecialchars($v) . '</span>';
        }

        return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
    }
}
