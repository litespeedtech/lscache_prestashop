<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Core;

/**
 * CacheState — per-request cache control flag register.
 *
 * Owns the bitmask that tracks cacheable/private/ESI state for the current
 * HTTP request. Previously scattered as static fields on the main LiteSpeedCache
 * module class; moved here so CacheManager can read state without creating a
 * circular dependency on the Module class.
 *
 * All public methods are static — there is exactly one cache state per PHP process.
 *
 * Bitmask values are intentionally identical to the original CCBM_* constants on
 * LiteSpeedCache so external code that still reads LiteSpeedCache::CCBM_* keeps
 * working without change.
 */
final class CacheState
{
    const CACHEABLE      = 1;
    const PRIV           = 2;    // "private" is a PHP reserved word
    const CAN_INJECT_ESI = 4;
    const ESI_ON         = 8;
    const ESI_REQ        = 16;
    const GUEST          = 32;
    const ERROR_CODE     = 64;
    const NOT_CACHEABLE  = 128;
    const VARY_CHECKED   = 256;
    const VARY_CHANGED   = 512;
    const FRONT_CTRL     = 1024;
    const MOD_ACTIVE     = 2048;
    const MOD_ALLOWIP    = 4096;

    private static int $flag          = 0;
    private static string $noCacheReason = '';

    /** Static-only class — no instantiation. */
    private function __construct() {}

    // ---- Low-level bitfield operations ------------------------------------------

    public static function set(int $bits): void
    {
        self::$flag |= $bits;
    }

    public static function clear(int $bits): void
    {
        self::$flag &= ~$bits;
    }

    public static function has(int $bits): bool
    {
        return (self::$flag & $bits) !== 0;
    }

    /** Returns the raw integer flag (backward-compat: used by getCCFlag()). */
    public static function flag(): int
    {
        return self::$flag;
    }

    public static function noCacheReason(): string
    {
        return self::$noCacheReason;
    }

    public static function appendNoCacheReason(string $reason): void
    {
        self::$noCacheReason .= $reason;
    }

    /** Resets state for testing or multi-request CLI scenarios. */
    public static function reset(): void
    {
        self::$flag          = 0;
        self::$noCacheReason = '';
    }

    // ---- Semantic read accessors -------------------------------------------------

    public static function isActive(): bool
    {
        return (self::$flag & (self::MOD_ACTIVE | self::MOD_ALLOWIP)) !== 0;
    }

    public static function isActiveForUser(): bool
    {
        return (self::$flag & self::MOD_ACTIVE) !== 0;
    }

    public static function isRestrictedIP(): bool
    {
        return (self::$flag & self::MOD_ALLOWIP) !== 0;
    }

    public static function isCacheable(): bool
    {
        return ((self::$flag & self::NOT_CACHEABLE) === 0) && ((self::$flag & self::CACHEABLE) !== 0);
    }

    public static function isEsiRequest(): bool
    {
        return (self::$flag & self::ESI_REQ) !== 0;
    }

    public static function canInjectEsi(): bool
    {
        return (self::$flag & self::CAN_INJECT_ESI) !== 0;
    }

    public static function isFrontController(): bool
    {
        return (self::$flag & self::FRONT_CTRL) !== 0;
    }

    // ---- Semantic write accessors ------------------------------------------------

    public static function markNotCacheable(string $reason = ''): void
    {
        self::$flag |= self::NOT_CACHEABLE;
        if ($reason !== '') {
            self::$noCacheReason .= $reason;
        }
    }

    // ---- Debug ------------------------------------------------------------------

    public static function debugInfo(): string
    {
        $info = [];
        if (self::$flag & self::MOD_ALLOWIP) { $info[] = 'Allowed IP'; }
        if (self::$flag & self::FRONT_CTRL)  { $info[] = 'FrontController'; }
        if (self::$flag & self::GUEST)       { $info[] = 'Guest'; }
        if (self::$flag & self::CACHEABLE)   { $info[] = 'Cacheable'; }
        if (self::$noCacheReason)            { $info[] = 'NO CACHE reason: ' . self::$noCacheReason; }

        return implode('; ', $info);
    }
}
