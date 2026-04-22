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
 * @copyright  Copyright (c) 2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_1(LiteSpeedCache $module)
{
    return $module->registerHook([
        'displayDynamicFragmentBefore',
        'displayDynamicFragmentAfter',
    ]);
}
