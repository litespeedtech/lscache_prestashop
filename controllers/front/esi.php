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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheEsiItem as EsiItem;
use LiteSpeedCacheHelper as LSHelper;
use LiteSpeedCacheLog as LSLog;

class LiteSpeedCacheEsiModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        $this->content_only = true;
        $this->display_header = false;
        $this->display_header_javascript = false;
        $this->display_footer = false;
        parent::__construct();
    }

    public function display()
    {
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log('In ESI controller display', LSLog::LEVEL_ESI_INCLUDE);
        }

        $lsc = Module::getInstanceByName(LiteSpeedCache::MODULE_NAME);
        $html = '';

        if (LiteSpeedCache::setVaryCookie()) {
            $html = '<!-- EnvChange - reload -->';
            LSLog::log($html, LSLog::LEVEL_ESI_OUTPUT);
            $related = false;
        } else {
            $item = EsiItem::decodeEsiUrl();

            if (is_string($item) && _LITESPEED_DEBUG_ >= LSLog::LEVEL_EXCEPTION) {
                LSLog::log('Invalid ESI url ' . $item, LSLog::LEVEL_EXCEPTION);

                return;
            }
            $this->populateItemContent($item);
            $html = $item->getContent();

            if ($html == EsiItem::RES_FAILED) {
                if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_EXCEPTION) {
                    LSLog::log('Invalid ESI url - module not found ', LSLog::LEVEL_EXCEPTION);
                }

                return;
            }
            $related = $item->getId();
            $lsc->addCacheControlByEsiModule($item);
        }

        $related = LSHelper::getRelatedItems($related);
        $inline = '';
        foreach ($related as $ri) {
            $this->populateItemContent($ri);
            $inline .= $ri->getInline();
        }

        if ($inline) {
            $lsc->setEsiOn();
        }

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_OUTPUT) {
            LSLog::log('ESI controller output ' . $inline . $html, LSLog::LEVEL_ESI_OUTPUT);
        }
        echo $inline . $html;
    }

    private function populateItemContent($item)
    {
        switch ($item->getParam('pt')) {
            case EsiItem::ESI_RENDERWIDGET:
                $this->processRenderWidget($item);
                break;
            case EsiItem::ESI_CALLHOOK:
                $this->processCallHook($item);
                break;
            case EsiItem::ESI_SMARTYFIELD:
                $this->processSmartyField($item);
                break;
            case EsiItem::ESI_JSDEF:
                LscIntegration::processJsDef($item);
                break;
            case EsiItem::ESI_TOKEN:
                $this->processToken($item);
                break;
            case EsiItem::ESI_ENV:
                $this->processEnv($item);
                break;
            default:
                $item->setFailed();
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_INCLUDE) {
            LSLog::log(__FUNCTION__ . ' ' . $item->getInfoLog(), LSLog::LEVEL_ESI_INCLUDE);
        }
    }

    private function initWidget($moduleName, &$params)
    {
        $module = Module::getInstanceByName($moduleName);
        if ($module != null) {
            $params['smarty'] = $this->context->smarty;
            $params['cookie'] = $this->context->cookie;
            $params['cart'] = $this->context->cart;
        }

        return $module;
    }

    private function processRenderWidget($item)
    {
        $params = [];
        if (($module = $this->initWidget($item->getParam('m'), $params)) == null) {
            $item->setFailed();
        } else {
            // h can be empty
            $item->setContent($module->renderWidget($item->getParam('h'), $params));
        }
    }

    private function processWidgetBlock($item)
    {
        $params = [];
        if (($module = $this->initWidget($item->getParam('m'), $params)) == null) {
            $item->setFailed();
        } else {
            $this->context->smarty->assign($module->getWidgetVariables('', $params));
            $item->setContent($this->context->smarty->fetch($item->getParam('t')));
        }
    }

    private function processCallHook($item)
    {
        $params = [];
        if (($module = $this->initWidget($item->getParam('m'), $params)) == null) {
            $item->setFailed();
        } else {
            if (method_exists($module, 'getWidgetVariables')) {
                $this->context->smarty->assign($module->getWidgetVariables('', $params));
            }
            $method = $item->getParam('mt');
            $item->setContent($module->$method($params));
        }
    }

    private function processToken($item)
    {
        $item->setContent(Tools::getToken(false));
    }

    private function processEnv($item)
    {
        $item->setContent('');
    }

    private function processSmartyField($item)
    {
        $f = $item->getParam('f');
        if ($f == 'widget') {
            $this->processRenderWidget($item);
        } elseif ($f == 'widget_block') {
            $this->processWidgetBlock($item);
        } else {
            LscIntegration::processModField($item);
        }
    }
}
