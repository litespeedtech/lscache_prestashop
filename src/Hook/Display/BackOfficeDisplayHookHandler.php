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

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Hook\Display;

class BackOfficeDisplayHookHandler
{
    /** @var string */
    private $modulePathUri;

    public function __construct(string $modulePathUri)
    {
        $this->modulePathUri = $modulePathUri;
    }

    public function onDisplayBackOfficeHeader(): string
    {
        return '<style>'
            . '#subtab-AdminLiteSpeedCache > a .material-icons.mi-bolt {'
            . '  font-size: 0 !important;'
            . '  line-height: 20px !important;'
            . '  width: 20px !important;'
            . '  height: 20px !important;'
            . '  background: url(' . $this->modulePathUri . 'views/img/litespeed-icon.svg) no-repeat center center !important;'
            . '  background-size: contain !important;'
            . '}'
            . '</style>';
    }
}
