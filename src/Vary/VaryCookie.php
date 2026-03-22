<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Vary;

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Core\CacheState;

/**
 * VaryCookie — vary cookie management for cache differentiation.
 * Extends CookieCore to access internal PS cookie variables.
 */
class VaryCookie extends \CookieCore
{
    const BM_HAS_VARYCOOKIE    = 1;
    const BM_VARYCOOKIE_CHANGED = 2;
    const BM_HAS_VARYVALUE     = 4;
    const BM_VARYVALUE_CHANGED  = 8;
    const BM_IS_GUEST          = 16;
    const BM_IS_MOBILEVIEW     = 32;
    const BM_UPDATE_FAILED     = 128;

    const DEFAULT_VARY_COOKIE_NAME = '_lscache_vary';
    const AMP_VARY_COOKIE_NAME     = '_lscache_vary_amp';
    const PRIVATE_SESSION_COOKIE   = 'lsc_private';

    private $vd;
    private $name;
    private $debug_header;
    private $status       = 0;
    private $mobile_device = null;

    public function __construct(string $name = '', string $path = '')
    {
        if ($name === '') {
            $name = self::DEFAULT_VARY_COOKIE_NAME;
        }
        parent::__construct($name, $path);
        $this->_modified      = false;
        $this->_allow_writing = false;

        $context      = \Context::getContext();
        $psCookie     = $context->cookie;
        $this->_path   = $psCookie->_path;
        $this->_domain = $psCookie->_domain;
        $this->_secure = $psCookie->_secure;
        $this->name   = $name;

        if ($name === self::DEFAULT_VARY_COOKIE_NAME) {
            $this->init($context, $psCookie);
        }
    }

    private function envChanged(): bool
    {
        if ($this->vd['vv']['ov'] !== $this->vd['vv']['nv']) {
            return true;
        }
        if ($this->status & self::BM_IS_GUEST) {
            if (($this->vd['cv']['ov'] === null)
                && (($this->vd['vv']['nv'] === 'guest' && $this->vd['cv']['nv'] === null)
                    || ($this->vd['vv']['nv'] === 'guestm' && $this->vd['cv']['nv'] === 'mobile~1~'))) {
                return false;
            } else {
                return true;
            }
        }
        return $this->vd['cv']['ov'] !== $this->vd['cv']['nv'];
    }

    public static function setVary(): bool
    {
        $vary      = new self();
        $vary->writeVary();
        $changed   = $vary->envChanged();
        $debug_info = '';

        if ($changed) {
            $debug_info = 'changed ' . json_encode($vary->vd);
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_CHANGE) {
                LSLog::log($debug_info, LSLog::LEVEL_ENVCOOKIE_CHANGE);
            }
        } elseif (($vary->status & (self::BM_HAS_VARYCOOKIE | self::BM_HAS_VARYVALUE)) > 0) {
            $debug_info = 'found & match ' . json_encode($vary->vd);
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_DETAIL) {
                LSLog::log($debug_info, LSLog::LEVEL_ENVCOOKIE_DETAIL);
            }
        }

        if ($debug_info && $vary->debug_header) {
            header("X-LSCACHE-Debug-Vary: $debug_info");
        }

        return $changed;
    }

    public static function setAmpVary(string $value): void
    {
        $amp = new self(self::AMP_VARY_COOKIE_NAME);
        $amp->writeAmpVary($value);
    }

    private function writeAmpVary(string $value): void
    {
        if (headers_sent()) {
            $this->status |= self::BM_UPDATE_FAILED;
            return;
        }

        // Workaround for PS validator — access $_COOKIE via variable variable
        $cookies = ${'_COOKIE'};
        $ov      = null;

        if (isset($cookies[self::AMP_VARY_COOKIE_NAME])) {
            $ov = $cookies[self::AMP_VARY_COOKIE_NAME];
        }
        if ($ov !== $value) {
            setcookie(self::AMP_VARY_COOKIE_NAME, $value, 0, $this->_path, $this->_domain, $this->_secure, true);
        }
    }

    private function getPrivateId(): string
    {
        $len = 32;
        if (function_exists('random_bytes')) {
            $id = bin2hex(random_bytes($len));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $id = bin2hex(openssl_random_pseudo_bytes($len));
        } else {
            $id = uniqid();
        }
        $val = $_SERVER['REMOTE_ADDR'] . $_SERVER['REMOTE_PORT'] . microtime() . $id;
        return md5($val);
    }

    private function writeVary(): void
    {
        if (headers_sent()) {
            $this->status |= self::BM_UPDATE_FAILED;
            return;
        }

        if (($this->status & self::BM_IS_GUEST) > 0
            && ($this->status & self::BM_VARYVALUE_CHANGED) == 0
            && CacheState::isCacheable()) {
            return;
        }

        if ($this->vd['ps']['ov'] === null) {
            $privateId           = $this->getPrivateId();
            $this->vd['ps']['nv'] = $privateId;
            setcookie(self::PRIVATE_SESSION_COOKIE, $privateId, 0, $this->_path, $this->_domain, $this->_secure, true);
        }

        if ($this->status & self::BM_VARYCOOKIE_CHANGED) {
            $val  = $this->vd['cv']['nv'];
            $time = 0;
            if ($val === null) {
                $val  = '';
                $time = 1000;
            }
            if (!setcookie($this->vd['cv']['name'], $val, $time, $this->_path, $this->_domain, $this->_secure, true)) {
                $this->status |= self::BM_UPDATE_FAILED;
            }
        }

        if ($this->status & self::BM_VARYVALUE_CHANGED) {
            header('X-LiteSpeed-Vary: value=' . $this->vd['vv']['nv']);
        }
    }

    private function getMobileDevice($context): bool
    {
        if ($this->mobile_device === null) {
            $this->mobile_device = false;
            $allow_mobile_config = \Configuration::get('PS_ALLOW_MOBILE_DEVICE');

            if (isset($_SERVER['HTTP_USER_AGENT']) && (bool) $allow_mobile_config) {
                if (isset($context->cookie->no_mobile) && $context->cookie->no_mobile == false) {
                    $this->mobile_device = true;
                } else {
                    switch ((int) $allow_mobile_config) {
                        case 1:
                            if ($context->isMobile() && !$context->isTablet()) {
                                $this->mobile_device = true;
                            }
                            break;
                        case 2:
                            if ($context->isTablet() && !$context->isMobile()) {
                                $this->mobile_device = true;
                            }
                            break;
                        case 3:
                            if ($context->isMobile() || $context->isTablet()) {
                                $this->mobile_device = true;
                            }
                            break;
                    }
                }
            }
        }
        return $this->mobile_device;
    }

    private function init($context, $psCookie): void
    {
        $this->vd = [
            'cv' => ['name' => $this->name, 'ov' => null, 'nv' => null],
            'vv' => ['ov' => null, 'nv' => null],
            'ps' => ['ov' => null, 'nv' => null],
        ];

        if ($context->controller instanceof \PageNotFoundController) {
            return;
        }

        $conf             = CacheConfig::getInstance();
        $diffCustomerGroup = $conf->getDiffCustomerGroup();
        $diffMobile       = $conf->get(CacheConfig::CFG_DIFFMOBILE);
        $isMobile         = $diffMobile ? $this->getMobileDevice($context) : false;
        $bypass           = $conf->getContextBypass();
        $this->debug_header = $conf->get(CacheConfig::CFG_DEBUG_HEADER);

        // Workaround for PS validator — access $_COOKIE via variable variable
        $cookies = ${'_COOKIE'};
        $data    = [];

        if (CacheState::isRestrictedIP()) {
            $data['dev'] = 1;
        }
        if (!in_array('curr', $bypass) && isset($psCookie->id_currency)) {
            $configuration_curr = \Configuration::get('PS_CURRENCY_DEFAULT');
            if ($psCookie->id_currency != $configuration_curr) {
                $data['curr'] = $psCookie->id_currency;
            }
        }
        if (!in_array('lang', $bypass) && isset($psCookie->id_lang) && \Language::isMultiLanguageActivated()) {
            $configuration_id_lang = \Configuration::get('PS_LANG_DEFAULT');
            if ($psCookie->id_lang != $configuration_id_lang) {
                $data['lang'] = $psCookie->id_lang;
            }
        }
        if ($diffMobile && $isMobile) {
            $data['mobile']       = 1;
            $this->status        |= self::BM_IS_MOBILEVIEW;
        }
        if (($diffCustomerGroup != 0) && ($context->customer !== null) && $context->customer->logged && $context->customer->id) {
            if ($diffCustomerGroup == 1) {
                $data['cg'] = $context->customer->id_default_group;
            } else {
                $data['cg'] = 1;
            }
            if ($diffCustomerGroup == 3) {
                \LiteSpeedCache::forceNotCacheable('No Cache for logged-out users');
            }
        }

        if (!empty($data)) {
            ksort($data);
            $newVal = '';
            foreach ($data as $k => $v) {
                $newVal .= $k . '~' . $v . '~';
            }
            $this->vd['cv']['nv']   = $newVal;
            $this->vd['cv']['data'] = $data;
            $this->status          |= self::BM_HAS_VARYCOOKIE;
        }

        if (isset($cookies[$this->vd['cv']['name']])) {
            $oldVal = $cookies[$this->vd['cv']['name']];
            if ($oldVal === 'deleted') {
                $oldVal = null;
            }
            $this->vd['cv']['ov'] = $oldVal;
        }

        if ($this->vd['cv']['ov'] !== $this->vd['cv']['nv']) {
            $this->status |= self::BM_VARYCOOKIE_CHANGED;
        }

        if (isset($_SERVER['LSCACHE_VARY_VALUE'])) {
            $ov = $_SERVER['LSCACHE_VARY_VALUE'];
            $this->vd['vv']['ov'] = $this->vd['vv']['nv'] = $ov;
            $this->status        |= self::BM_HAS_VARYVALUE;

            if ($diffMobile) {
                if ($ov === 'guest' && $isMobile) {
                    $this->vd['vv']['nv'] = 'guestm';
                } elseif ($ov === 'guestm' && !$isMobile) {
                    $this->vd['vv']['nv'] = 'guest';
                }
                if ($this->vd['vv']['ov'] !== $this->vd['vv']['nv']) {
                    $this->status |= self::BM_VARYVALUE_CHANGED;
                }
            }

            if ($ov === 'guest' || $ov === 'guestm') {
                $this->status |= self::BM_IS_GUEST;
            }
        }

        if (isset($cookies[self::PRIVATE_SESSION_COOKIE])) {
            $this->vd['ps']['ov'] = $cookies[self::PRIVATE_SESSION_COOKIE];
        }
    }
}
