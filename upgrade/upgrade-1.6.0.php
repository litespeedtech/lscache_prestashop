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
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0(LiteSpeedCache $module)
{
    $hooksToUnregister = [
        'actionObjectSpecificPriceCoreAddAfter',
        'actionObjectSpecificPriceCoreUpdateAfter',
        'actionObjectSpecificPriceCoreDeleteAfter',
    ];

    foreach ($hooksToUnregister as $hookName) {
        // Unregister module from hook
        $module->unregisterHook($hookName);
        $hookId = Hook::getIdByName($hookName);

        // Remove unused hook created by mistake
        if ($hookId && Validate::isLoadedObject($hook = new Hook($hookId))) {
            $hook->delete();
        }
    }

    $module->registerHook([
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
    ]);

    return true;
}
