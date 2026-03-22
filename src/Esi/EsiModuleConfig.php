<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Esi;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use Tools;

/**
 * EsiModuleConfig — configuration for an ESI module block.
 *
 * Stores how a specific module's widget/hook should be cached, purged and varied.
 * Supports built-in, integrated, and user-customized module configurations.
 */
class EsiModuleConfig implements \JsonSerializable
{
    // Module type constants
    const TYPE_BUILTIN    = 0; // Built-in PS modules (cart, customer sign-in, …)
    const TYPE_INTEGRATED = 1; // Pre-integrated third-party modules
    const TYPE_CUSTOMIZED = 2; // User-customized via back-office

    // Field constants
    const FLD_PRIV               = 'priv';
    const FLD_DISABLED           = 'disableESI';
    const FLD_TEMPLATE_ARGUMENT  = 'argument';
    const FLD_TAG                = 'tag';
    const FLD_TTL                = 'ttl';
    const FLD_PURGE_EVENTS       = 'events';
    const FLD_PURGE_CONTROLLERS  = 'ctrl';
    const FLD_HOOK_METHODS       = 'methods';
    const FLD_RENDER_WIDGETS     = 'render';
    const FLD_ASVAR              = 'asvar';
    const FLD_IGNORE_EMPTY       = 'ie';
    const FLD_ONLY_CACHE_EMPTY   = 'ce';
    const FLD_TIPURL             = 'tipurl';

    /** @var string */
    private $moduleName;

    /** @var int */
    private $type;

    /** @var array */
    private $data;

    /** @var array */
    private $parsed = [];

    /** @var object|null */
    private $customHandler = null;

    public function __construct(string $moduleName, int $type, array $data)
    {
        $this->moduleName = $moduleName;
        $this->type       = $type;
        $this->data       = [];

        // Sanitize data
        $this->data[self::FLD_PRIV]     = !empty($data[self::FLD_PRIV]) ? 1 : 0;
        $this->data[self::FLD_DISABLED] = isset($data[self::FLD_DISABLED]) ? (int) $data[self::FLD_DISABLED] : 0;

        $copyFields = [
            self::FLD_TAG,
            self::FLD_TTL,
            self::FLD_PURGE_EVENTS,
            self::FLD_PURGE_CONTROLLERS,
            self::FLD_HOOK_METHODS,
            self::FLD_RENDER_WIDGETS,
            self::FLD_TEMPLATE_ARGUMENT,
            self::FLD_ASVAR,
            self::FLD_IGNORE_EMPTY,
            self::FLD_ONLY_CACHE_EMPTY,
            self::FLD_TIPURL,
        ];
        foreach ($copyFields as $field) {
            if (isset($data[$field])) {
                $this->data[$field] = $data[$field];
            }
        }

        // Normalize tag to array
        if (isset($this->data[self::FLD_TAG]) && !is_array($this->data[self::FLD_TAG])) {
            $this->data[self::FLD_TAG] = [$this->data[self::FLD_TAG]];
        }

        $this->parseList(self::FLD_RENDER_WIDGETS);
        $this->parseList(self::FLD_HOOK_METHODS);
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getCustConfArray(): array
    {
        $cdata = [
            'id'        => $this->moduleName,
            'name'      => $this->moduleName,
            'disableESI'=> $this->isDisabled(),
            'priv'      => $this->isPrivate(),
            'ttl'       => $this->getTTL(),
            'tag'       => implode(', ', $this->getTags()),
            'type'      => $this->type,
            'events'    => $this->getFieldValue(self::FLD_PURGE_EVENTS, false, true),
            'ctrl'      => $this->getFieldValue(self::FLD_PURGE_CONTROLLERS, false, true),
            'methods'   => $this->getFieldValue(self::FLD_HOOK_METHODS, false, true),
            'render'    => $this->getFieldValue(self::FLD_RENDER_WIDGETS, false, true),
            'asvar'     => $this->getFieldValue(self::FLD_ASVAR, true),
            'ie'        => $this->getFieldValue(self::FLD_IGNORE_EMPTY, true),
            'ce'        => $this->getFieldValue(self::FLD_ONLY_CACHE_EMPTY, true),
            'tipurl'    => $this->getFieldValue(self::FLD_TIPURL),
            'argument'  => $this->getFieldValue(self::FLD_TEMPLATE_ARGUMENT, false, true),
        ];
        if ($tmp = \Module::getInstanceByName($this->moduleName)) {
            $cdata['name'] = htmlspecialchars_decode($tmp->displayName);
        }

        return $cdata;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $sdata       = $this->data;
        $sdata['id'] = $this->moduleName;

        return $sdata;
    }

    public function setCustomHandler(object $customHandler): void
    {
        if ($this->customHandler === null) {
            $this->customHandler = $customHandler;
        }
    }

    /** @param array $params */
    public function isPrivate(array $params = []): bool
    {
        if ($this->customHandler && method_exists($this->customHandler, 'isPrivate')) {
            return (bool) $this->customHandler->isPrivate($params);
        }

        return (bool) $this->data[self::FLD_PRIV];
    }

    /** @param array $params */
    public function isDisabled(array $params = []): bool
    {
        return (bool) $this->data[self::FLD_DISABLED];
    }

    /**
     * @param array $params
     * @return string|int
     */
    public function getTTL(array $params = [])
    {
        if ($this->customHandler && method_exists($this->customHandler, 'getTTL')) {
            return $this->customHandler->getTTL($params);
        }

        return isset($this->data[self::FLD_TTL]) ? $this->data[self::FLD_TTL] : '';
    }

    public function getTags(array $params = []): array
    {
        if ($this->customHandler && method_exists($this->customHandler, 'getTags')) {
            return $this->customHandler->getTags($params);
        }

        if (!empty($this->data[self::FLD_TAG])) {
            return $this->data[self::FLD_TAG];
        }

        return [$this->moduleName];
    }

    public function getNoItemCache(array $params): bool
    {
        if ($this->customHandler && method_exists($this->customHandler, 'getNoItemCache')) {
            return (bool) $this->customHandler->getNoItemCache($params);
        }

        return false;
    }

    public function asVar(array $params): bool
    {
        if ($this->customHandler && method_exists($this->customHandler, 'asVar')) {
            return (bool) $this->customHandler->asVar($params);
        }

        return isset($this->data[self::FLD_ASVAR]) && (bool) $this->data[self::FLD_ASVAR];
    }

    public function onlyCacheEmpty(array $params): bool
    {
        if ($this->customHandler && method_exists($this->customHandler, 'onlyCacheEmpty')) {
            return (bool) $this->customHandler->onlyCacheEmpty($params);
        }

        return isset($this->data[self::FLD_ONLY_CACHE_EMPTY]) && (bool) $this->data[self::FLD_ONLY_CACHE_EMPTY];
    }

    public function ignoreEmptyContent(array $params): bool
    {
        if ($this->customHandler && method_exists($this->customHandler, 'ignoreEmptyContent')) {
            return (bool) $this->customHandler->ignoreEmptyContent($params);
        }

        return isset($this->data[self::FLD_IGNORE_EMPTY]) && (bool) $this->data[self::FLD_IGNORE_EMPTY];
    }

    public function getPurgeControllers(): ?array
    {
        if (empty($this->data[self::FLD_PURGE_CONTROLLERS])) {
            return null;
        }
        $controllers = [];
        $list = preg_split("/[\s,]+/", $this->data[self::FLD_PURGE_CONTROLLERS], -1, PREG_SPLIT_NO_EMPTY);
        foreach ($list as $item) {
            $ct = explode('?', $item);
            $controllers[$ct[0]] = count($ct) > 1 ? $ct[1] : 0;
        }

        return $controllers;
    }

    public function getPurgeEvents(): ?array
    {
        if (!isset($this->data[self::FLD_PURGE_EVENTS])) {
            return null;
        }

        return preg_split("/[\s,]+/", $this->data[self::FLD_PURGE_EVENTS], -1, PREG_SPLIT_NO_EMPTY) ?: null;
    }

    public function canInject(array &$params): bool
    {
        if ($this->isDisabled()) {
            return false;
        }
        if (empty($params['pt'])) {
            if (defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                LSLog::log(__FUNCTION__ . ' missing pt', LSLog::LEVEL_UNEXPECTED);
            }

            return false;
        }
        if ($this->customHandler && method_exists($this->customHandler, 'canInject')) {
            return (bool) $this->customHandler->canInject($params);
        }

        switch ($params['pt']) {
            case EsiItem::ESI_RENDERWIDGET:
                return $this->checkInjection(self::FLD_RENDER_WIDGETS, $params['h']);
            case EsiItem::ESI_CALLHOOK:
                return $this->checkInjection(self::FLD_HOOK_METHODS, $params['mt']);
            default:
                return true;
        }
    }

    public function isCustomized(): bool
    {
        return $this->type === self::TYPE_CUSTOMIZED;
    }

    public function getTemplateArgs(): string
    {
        return $this->getFieldValue(self::FLD_TEMPLATE_ARGUMENT, false, true);
    }

    public function getMethods(): string
    {
        return $this->getFieldValue(self::FLD_HOOK_METHODS, false, true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $field
     * @param bool   $isBool
     * @param bool   $splitClean
     * @return mixed
     */
    private function getFieldValue(string $field, bool $isBool = false, bool $splitClean = false)
    {
        $value = isset($this->data[$field]) ? $this->data[$field] : '';
        if ($isBool) {
            $value = (bool) $value;
        }
        if ($splitClean && $value) {
            $parts = preg_split("/[\s,]+/", $value, -1, PREG_SPLIT_NO_EMPTY);
            $value = implode(', ', $parts);
        }

        return $value;
    }

    private function checkInjection(string $field, string $value): bool
    {
        $res  = $this->parsed[$field];
        $type = $res[0];
        if ($type === 9) {
            return true;
        }
        $value = Tools::strtolower($value);
        if ($type === 1) {
            return in_array($value, $res[1]);
        }
        if ($type === 2) {
            return !in_array($value, $res[2]);
        }

        return false;
    }

    private function parseList(string $field): void
    {
        $res = [];
        if (!isset($this->data[$field])) {
            $res[0] = -1;
        } elseif (is_object($this->data[$field])) {
            // Custom handler object
            $res = $this->data[$field];
        } elseif ($this->data[$field] === '*') {
            $res[0] = 9;
        } else {
            $list      = preg_split("/[\s,]+/", $this->data[$field], -1, PREG_SPLIT_NO_EMPTY);
            $isInclude = 0;
            foreach ($list as $d) {
                $d = Tools::strtolower($d);
                if ($d[0] === '!') {
                    $isInclude |= 2;
                    $res[2][]   = ltrim($d, '!');
                } else {
                    $isInclude |= 1;
                    $res[1][]   = $d;
                }
            }
            $res[0] = ($isInclude & 1) === 1 ? 1 : (($isInclude & 2) === 2 ? 2 : -1);
        }
        $this->parsed[$field] = $res;
    }
}
