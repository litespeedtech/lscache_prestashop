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

/**
 * Locates elements marked with data-ps-fragment without re-serializing the
 * complete HTML document.
 *
 * The parser only needs element boundaries. It deliberately preserves the
 * original bytes and offsets so LiteSpeed can replace the exact fragment with
 * an ESI include.
 */
class LiteSpeedCacheDynamicFragmentParser
{
    private static $voidElements = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /*
     * Markup-like text inside raw-text and RCDATA elements is not parsed as
     * nested HTML. For fragment-boundary purposes both kinds are opaque until
     * their corresponding closing tag.
     */
    private static $textElements = [
        'script', 'style', 'textarea', 'title',
    ];

    /**
     * @param string $html
     * @param array  $errors Parsing errors related to data-ps-fragment markup
     *
     * @return array
     */
    public static function find($html, &$errors = [])
    {
        $errors = [];

        if (!is_string($html) || stripos($html, 'data-ps-fragment') === false) {
            return [];
        }

        $fragments = [];
        $current = null;
        $length = strlen($html);
        $position = 0;

        while ($position < $length) {
            $tagStart = strpos($html, '<', $position);
            if ($tagStart === false) {
                break;
            }

            // Comments may contain arbitrary markup-like text.
            if (substr($html, $tagStart, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $tagStart + 4);
                if ($commentEnd === false) {
                    if ($current !== null) {
                        $errors[] = 'Unclosed HTML comment inside dynamic fragment "' . $current['name'] . '"';
                    }
                    break;
                }

                $position = $commentEnd + 3;
                continue;
            }

            /*
             * A literal '<' does not start an HTML tag unless it is followed
             * by an ASCII letter, '/', '!' or '?'. In particular, do not let
             * quotes in normal text (for example "Orders < 50 won't ship")
             * affect the search for a later '>'.
             */
            if (!isset($html[$tagStart + 1])
                || !preg_match('~[a-zA-Z/!?]~', $html[$tagStart + 1])) {
                $position = $tagStart + 1;
                continue;
            }

            $tagEnd = self::findTagEnd($html, $tagStart);
            if ($tagEnd === false) {
                if ($current !== null) {
                    $errors[] = 'Unclosed HTML tag inside dynamic fragment "' . $current['name'] . '"';
                }
                break;
            }

            $tag = substr($html, $tagStart, $tagEnd - $tagStart + 1);
            $tagInfo = self::parseTag($tag);
            if ($tagInfo === null) {
                $position = $tagEnd + 1;
                continue;
            }

            $tagName = $tagInfo['name'];
            $isClosing = $tagInfo['closing'];
            $isSelfClosing = $tagInfo['self_closing'] || in_array($tagName, self::$voidElements, true);

            if (!$isClosing) {
                $fragmentName = self::getAttributeValue($tag, 'data-ps-fragment');

                if ($current !== null) {
                    if ($fragmentName !== null) {
                        /*
                         * Nested dynamic fragments would create nested ESI
                         * boundaries. Reject them and let the caller disable FPC
                         * for the response instead of guessing.
                         */
                        $errors[] = 'Nested dynamic fragment "' . $fragmentName
                            . '" inside "' . $current['name'] . '"';
                        $current['invalid'] = true;
                    }

                    if (!$isSelfClosing && $tagName === $current['tag']) {
                        ++$current['depth'];
                    }
                } elseif ($fragmentName !== null) {
                    $fragmentName = trim($fragmentName);

                    if ($fragmentName === '') {
                        $errors[] = 'Empty data-ps-fragment value';
                    } elseif ($isSelfClosing || in_array($tagName, self::$textElements, true)) {
                        $errors[] = 'Dynamic fragment "' . $fragmentName
                            . '" must use an element with an explicit closing tag';
                    } else {
                        $current = [
                            'name' => $fragmentName,
                            'tag' => $tagName,
                            'start' => $tagStart,
                            'depth' => 1,
                            'invalid' => false,
                        ];
                    }
                }

                /*
                 * Ignore markup-like text inside raw-text/RCDATA elements. HTML treats the
                 * first matching closing raw-text tag as the end of the element.
                 */
                if (in_array($tagName, self::$textElements, true) && !$isSelfClosing) {
                    $rawCloseStart = self::findTextElementClosingTag($html, $tagName, $tagEnd + 1);
                    if ($rawCloseStart !== false) {
                        $rawCloseEnd = self::findTagEnd($html, $rawCloseStart);
                        if ($rawCloseEnd !== false) {
                            $position = $rawCloseEnd + 1;
                            continue;
                        }
                    }
                }
            } elseif ($current !== null && $tagName === $current['tag']) {
                --$current['depth'];

                if ($current['depth'] === 0) {
                    if (!$current['invalid']) {
                        $fragmentEnd = $tagEnd + 1;
                        $fragments[] = [
                            'name' => $current['name'],
                            'tag' => $current['tag'],
                            'start' => $current['start'],
                            'length' => $fragmentEnd - $current['start'],
                        ];
                    }

                    $current = null;
                }
            }

            $position = $tagEnd + 1;
        }

        if ($current !== null) {
            $errors[] = 'Unclosed dynamic fragment "' . $current['name'] . '"';
        }

        return $fragments;
    }

    /**
     * Find the matching closing tag for a raw-text/RCDATA element.
     *
     * A prefix such as </scripture> must not terminate a <script> element.
     * After the tag name HTML only allows whitespace, '/', or '>' for a
     * matching end tag token.
     */
    private static function findTextElementClosingTag($html, $tagName, $position)
    {
        $needle = '</' . $tagName;
        $needleLength = strlen($needle);
        $length = strlen($html);

        while (($candidate = stripos($html, $needle, $position)) !== false) {
            $delimiterPosition = $candidate + $needleLength;

            if ($delimiterPosition < $length) {
                $delimiter = $html[$delimiterPosition];

                if ($delimiter === '>'
                    || $delimiter === '/'
                    || $delimiter === ' '
                    || $delimiter === "\t"
                    || $delimiter === "\n"
                    || $delimiter === "\f"
                    || $delimiter === "\r") {
                    return $candidate;
                }
            }

            $position = $delimiterPosition;
        }

        return false;
    }

    /**
     * Find the closing > while respecting quoted attribute values.
     */
    private static function findTagEnd($html, $tagStart)
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $tagStart + 1; $i < $length; ++$i) {
            $char = $html[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i;
            }
        }

        return false;
    }

    private static function parseTag($tag)
    {
        if (!preg_match('~^<(/?)\s*([a-zA-Z][a-zA-Z0-9:_-]*)\b~', $tag, $matches)) {
            return null;
        }

        return [
            'closing' => $matches[1] === '/',
            'name' => strtolower($matches[2]),
            'self_closing' => preg_match('~/\s*>$~', $tag) === 1,
        ];
    }

    /**
     * Parse one attribute without matching text contained inside another
     * attribute value.
     */
    private static function getAttributeValue($tag, $wantedName)
    {
        $length = strlen($tag);
        $position = 1;

        while ($position < $length && ctype_space($tag[$position])) {
            ++$position;
        }

        if ($position < $length && $tag[$position] === '/') {
            return null;
        }

        // Skip tag name.
        while ($position < $length
            && !ctype_space($tag[$position])
            && $tag[$position] !== '>'
            && $tag[$position] !== '/') {
            ++$position;
        }

        while ($position < $length) {
            while ($position < $length
                && (ctype_space($tag[$position]) || $tag[$position] === '/')) {
                ++$position;
            }

            if ($position >= $length || $tag[$position] === '>') {
                break;
            }

            $nameStart = $position;
            while ($position < $length
                && !ctype_space($tag[$position])
                && $tag[$position] !== '='
                && $tag[$position] !== '>'
                && $tag[$position] !== '/') {
                ++$position;
            }

            $name = substr($tag, $nameStart, $position - $nameStart);

            while ($position < $length && ctype_space($tag[$position])) {
                ++$position;
            }

            $value = null;
            if ($position < $length && $tag[$position] === '=') {
                ++$position;

                while ($position < $length && ctype_space($tag[$position])) {
                    ++$position;
                }

                if ($position < $length && ($tag[$position] === '"' || $tag[$position] === "'")) {
                    $quote = $tag[$position];
                    ++$position;
                    $valueStart = $position;

                    while ($position < $length && $tag[$position] !== $quote) {
                        ++$position;
                    }

                    $value = substr($tag, $valueStart, $position - $valueStart);
                    if ($position < $length) {
                        ++$position;
                    }
                } else {
                    $valueStart = $position;
                    while ($position < $length
                        && !ctype_space($tag[$position])
                        && $tag[$position] !== '>') {
                        ++$position;
                    }

                    $value = substr($tag, $valueStart, $position - $valueStart);
                }
            }

            if (strcasecmp($name, $wantedName) === 0) {
                return $value === null ? '' : $value;
            }
        }

        return null;
    }
}
