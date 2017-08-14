<?php
/**
 * LiteSpeed Cache for Prestashop
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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

/**
 * LiteSpeed Cache needs to do ESI hole punch automatically.
 * To override Hook is the only way to do it without changing Prestashop core code.
 * This should not affect any existing functionalities of your Prestashop installation and it is safe to use.
 *
 */
class Hook extends HookCore
{

    public static function coreCallHook($module, $method, $params)
    {
        if (defined('_LITESPEED_CACHE_DEBUG_')) {
            // No logic added here. This is to print out all hook events, for module customization,
            // to add purge or tagging event hook
            $mesg = '  in hook coreCallHook ' . get_class($module) . ' - ' . $method;
            LiteSpeedCacheDebugLog::log($mesg, LiteSpeedCacheDebugLog::LEVEL_HOOK_DETAIL);
        }
        return parent::coreCallHook($module, $method, $params);
    }

    public static function coreRenderWidget($module, $hook_name, $params)
    {
        if (defined('_LITESPEED_CACHE_DEBUG_')) {
            // this debug log will only print when debug level is set to 9
            $mesg = '  in hook coreRenderWidget module ' . get_class($module) . ' - ' . $hook_name;
            LiteSpeedCacheDebugLog::log($mesg, LiteSpeedCacheDebugLog::LEVEL_HOOK_DETAIL);
        }
        if (defined('_LITESPEED_CACHE_')
                && ($output = LiteSpeedCache::overrideRenderWidget($module, $hook_name)) !== false) {
            return $output;
        }
        return parent::coreRenderWidget($module, $hook_name, $params);
    }
}
