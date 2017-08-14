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

use LiteSpeedCacheDebugLog as DebugLog;

class LiteSpeedCacheEsiModuleFrontController extends ModuleFrontController
{

    public function init()
    {
        parent::init();
        $this->content_only = true;
        LiteSpeedCache::setEsiReq();
    }

    public function display()
    {
        DebugLog::log('In ESI controller display', DebugLog::LEVEL_ESI_INCLUDE);
        $moduleName = Tools::getValue('m');
        $hookName = Tools::getValue('h');
        // basic validation
        if ($hookName && $moduleName && ($module = Module::getInstanceByName($moduleName))) {
            $context = Context::getContext();
            $context->smarty->assign(array(
                'layout' => $this->getLayout(),
            ));
            $lsc = Module::getInstanceByName(LiteSpeedCache::MODULE_NAME);
            $lsc->addCacheControlByEsiModule($moduleName);

            $hook_args = array();
            $hook_args['smarty'] = $context->smarty;
            if (!isset($hook_args['cookie']) || !$hook_args['cookie']) {
                $hook_args['cookie'] = $context->cookie;
            }
            if (!isset($hook_args['cart']) || !$hook_args['cart']) {
                $hook_args['cart'] = $context->cart;
            }
            $html = $module->renderWidget($hookName, $hook_args);
            Hook::exec('actionOutputHTMLBefore', array('html' => &$html));
            echo trim($html);
        } else {
            DebugLog::log('Invalid ESI url', DebugLog::LEVEL_EXCEPTION);
            echo '';
        }
    }
}
