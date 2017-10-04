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
        if (defined('_LITESPEED_DEBUG_')
            && _LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_HOOK_DETAIL ) {
            // No logic added here. This is to print out all hook events, for module customization,
            // to add purge or tagging event hook
            $mesg = '  in hook coreCallHook ' . get_class($module) . ' - ' . $method;
            LiteSpeedCacheLog::log($mesg, LiteSpeedCacheLog::LEVEL_HOOK_DETAIL);
        }

        $html = parent::coreCallHook($module, $method, $params);

        if (defined('_LITESPEED_CACHE_')
                && ($marker = LiteSpeedCache::injectCallHook($module, $method)) !== false) {
            $html = $marker . $html . LiteSpeedCache::ESI_MARKER_END;
        }
        return $html;
    }

    /* only avail for PS 1.7 */
    public static function coreRenderWidget($module, $hook_name, $params)
    {
        if (defined('_LITESPEED_DEBUG_')
                && _LITESPEED_DEBUG_ >= LiteSpeedCacheLog::LEVEL_HOOK_DETAIL) {
            // this debug log will only print when debug level is set to 10
            $mesg = '  in hook coreRenderWidget module ' . get_class($module) . ' - ' . $hook_name;
            LiteSpeedCacheLog::log($mesg, LiteSpeedCacheLog::LEVEL_HOOK_DETAIL);
        }

        $html = parent::coreRenderWidget($module, $hook_name, $params);

        if (defined('_LITESPEED_CACHE_')
                && ($marker = LiteSpeedCache::injectRenderWidget($module, $hook_name)) !== false) {
            $html = $marker . $html . LiteSpeedCache::ESI_MARKER_END;
        }
        return $html;
    }
}
