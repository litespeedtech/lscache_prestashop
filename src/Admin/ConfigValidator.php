<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;

/**
 * ConfigValidator — validates and normalises LiteSpeedCache configuration POST values.
 *
 * Extracted from AdminLiteSpeedCacheConfigController::validateInput() so that
 * validation logic is separated from the controller lifecycle and can be tested
 * without a full PS controller context.
 *
 * Usage:
 *   $v = new ConfigValidator();
 *   [$value, $errors, $changeFlag] = $v->validate($name, $postVal, $origVal);
 *
 * $changeFlag is a bitmask using the SCOPE_x/PURGE_x constants defined below.
 * Map these back to the controller's BMC_* constants when needed.
 */
class ConfigValidator
{
    // Change-tracking bitmask — mirrors the BMC_* constants in the config controller.
    const SCOPE_SHOP = 1;   // change is per-shop
    const SCOPE_ALL  = 2;   // change is global
    const PURGE_NONE = 4;   // effective immediately, no purge needed
    const PURGE_MAY  = 8;   // purge recommended but not required
    const PURGE_MUST = 16;  // purge required
    const PURGE_DONE = 32;  // auto-purge will run
    const HTACCESS   = 64;  // .htaccess update required

    private const SPLIT = '/[\s,]+/';

    /**
     * Validates a single configuration field.
     *
     * @param string $name  Config key (one of Conf::CFG_*)
     * @param mixed  $post  Raw POST value
     * @param mixed  $orig  Current stored value (used for change detection)
     *
     * @return array{0: mixed, 1: string[], 2: int}  [normalisedValue, errors, changeFlags]
     */
    public function validate(string $name, $post, $orig): array
    {
        switch ($name) {
            case Conf::CFG_ENABLED:
                $post = (int) $post;
                $c    = ($post !== $orig)
                    ? self::SCOPE_ALL | self::HTACCESS | ($post === 0 ? self::PURGE_DONE : self::PURGE_NONE)
                    : 0;
                return [$post, [], $c];

            case Conf::CFG_PUBLIC_TTL:
                return $this->unsignedIntMin($post, $orig, 300, self::SCOPE_SHOP, true);

            case Conf::CFG_PRIVATE_TTL:
                return $this->unsignedIntRange($post, $orig, 180, 7200, self::SCOPE_SHOP | self::PURGE_NONE);

            case Conf::CFG_HOME_TTL:
                return $this->unsignedIntMin($post, $orig, 60, self::SCOPE_SHOP, true);

            case Conf::CFG_404_TTL:
            case Conf::CFG_PCOMMENTS_TTL:
                return $this->optionalTtl($post, $orig, 300, self::SCOPE_SHOP);

            case Conf::CFG_DIFFMOBILE:
                $post = $this->clamp($post, 0, 2);
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_MUST : 0];

            case Conf::CFG_DIFFCUSTGRP:
                $post = $this->clamp($post, 0, 3);
                return [$post, [], $post !== $orig ? self::SCOPE_SHOP | self::PURGE_MUST : 0];

            case Conf::CFG_FLUSH_ALL:
            case Conf::CFG_FLUSH_PRODCAT:
                $post = $this->clamp($post, 0, 4);
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_NONE : 0];

            case Conf::CFG_FLUSH_HOME:
                $post = $this->clamp($post, 0, 2);
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_NONE : 0];

            case Conf::CFG_FLUSH_HOME_INPUT:
                return $this->numericList($post, $orig, self::SCOPE_ALL | self::PURGE_NONE);

            case Conf::CFG_GUESTMODE:
                $post = (int) $post;
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_NONE | self::HTACCESS : 0];

            case Conf::CFG_NOCACHE_VAR:
                return $this->wordList($post, $orig, '/^[a-zA-Z1-9_\- ,]+$/', self::SCOPE_ALL | self::PURGE_MUST);

            case Conf::CFG_NOCACHE_URL:
                $clean = $this->split((string) $post);
                $post  = empty($clean) ? '' : implode("\n", $clean);
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_MUST : 0];

            case Conf::CFG_VARY_BYPASS:
                $clean   = $this->split((string) $post);
                $invalid = array_diff($clean, ['ctry', 'curr', 'lang']);
                if (!empty($invalid)) {
                    return [(string) $orig, ['Value not supported: ' . implode(', ', $invalid)], 0];
                }
                $post = empty($clean) ? '' : implode(', ', $clean);
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_MUST : 0];

            case Conf::CFG_DEBUG_HEADER:
            case Conf::CFG_DEBUG:
                $post = (int) $post;
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_NONE : 0];

            case Conf::CFG_DEBUG_LEVEL:
                $post = (int) $post;
                if ($post < 1 || $post > 10) {
                    return [(int) $orig, ['Valid range is 1 to 10.'], 0];
                }
                return [$post, [], $post !== $orig ? self::SCOPE_ALL | self::PURGE_NONE : 0];

            case Conf::CFG_ALLOW_IPS:
                return $this->ipList($post, $orig, self::SCOPE_ALL | self::PURGE_MUST);

            case Conf::CFG_DEBUG_IPS:
                return $this->ipList($post, $orig, self::SCOPE_ALL | self::PURGE_NONE);
        }

        return [$post, [], 0];
    }

    // ---- Private helpers --------------------------------------------------------

    /** Unsigned int with a minimum value; TTL changes can auto-purge by direction. */
    private function unsignedIntMin($post, $orig, int $min, int $scope, bool $dirPurge): array
    {
        if (!\Validate::isUnsignedInt($post)) {
            return [(int) $orig, ['Invalid value'], 0];
        }
        $post = (int) $post;
        if ($post < $min) {
            return [(int) $orig, ["Must be greater than {$min} seconds"], 0];
        }
        if ($post === $orig) {
            return [$post, [], 0];
        }
        $c = $scope | ($dirPurge ? ($post < $orig ? self::PURGE_MUST : self::PURGE_MAY) : 0);
        return [$post, [], $c];
    }

    /** Unsigned int constrained to [min, max]. */
    private function unsignedIntRange($post, $orig, int $min, int $max, int $scope): array
    {
        if (!\Validate::isUnsignedInt($post)) {
            return [(int) $orig, ['Invalid value'], 0];
        }
        $post = (int) $post;
        if ($post < $min || $post > $max) {
            return [(int) $orig, ["Must be within the {$min} to {$max} range"], 0];
        }
        return [$post, [], $post !== $orig ? $scope : 0];
    }

    /** TTL that can be 0 (disabled) or >= $min. */
    private function optionalTtl($post, $orig, int $min, int $scope): array
    {
        if (!\Validate::isUnsignedInt($post)) {
            return [(int) $orig, ['Invalid value'], 0];
        }
        $post = (int) $post;
        if ($post > 0 && $post < $min) {
            return [(int) $orig, ["Must be greater than {$min} seconds"], 0];
        }
        if ($post === $orig) {
            return [$post, [], 0];
        }
        $c = $scope | ($post === 0 ? self::PURGE_MUST : ($post < $orig ? self::PURGE_MUST : self::PURGE_MAY));
        return [$post, [], $c];
    }

    /** Comma/space-separated list of numeric IDs. */
    private function numericList($post, $orig, int $scope): array
    {
        $clean = $this->split((string) $post);
        if (empty($clean)) {
            $post = '';
        } else {
            $post = implode(', ', $clean);
            if (!preg_match('/^[\d ,]+$/', $post)) {
                return [(string) $orig, ['Invalid value'], 0];
            }
        }
        return [$post, [], $post !== $orig ? $scope : 0];
    }

    /** Comma/space-separated list of words matching a regex pattern. */
    private function wordList($post, $orig, string $pattern, int $scope): array
    {
        $clean = $this->split((string) $post);
        if (empty($clean)) {
            $post = '';
        } else {
            $post = implode(', ', $clean);
            if (!preg_match($pattern, $post)) {
                return [(string) $orig, ['Invalid value'], 0];
            }
        }
        return [$post, [], $post !== $orig ? $scope : 0];
    }

    /** Comma/space-separated list of IP addresses / hostnames. */
    private function ipList($post, $orig, int $scope): array
    {
        $clean  = $this->split((string) $post);
        $errors = [];
        foreach ($clean as $ip) {
            if (!preg_match('/^[[:alnum:]._-]+$/', $ip)) {
                $errors[] = 'Invalid value';
            }
        }
        if (!empty($errors)) {
            return [(string) $orig, $errors, 0];
        }
        $post = empty($clean) ? '' : implode(', ', $clean);
        return [$post, [], $post !== $orig ? $scope : 0];
    }

    private function clamp($value, int $min, int $max): int
    {
        $v = (int) $value;
        return ($v < $min || $v > $max) ? $min : $v;
    }

    private function split(string $value): array
    {
        return array_unique(preg_split(self::SPLIT, $value, -1, PREG_SPLIT_NO_EMPTY));
    }
}
