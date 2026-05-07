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
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheEsiItem as EsiItem;
use LiteSpeedCacheHelper as LSHelper;
use LiteSpeedCacheLog as LSLog;

require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/HookParamsResolver.php';
require_once _PS_MODULE_DIR_ . 'litespeedcache/classes/DynamicFragment.php';

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
        ob_clean();
        echo $inline . $html;
        ob_end_flush();        
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
            case EsiItem::ESI_DYNAMIC_FRAGMENT:
                $this->processDynamicFragment($item);
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

            $smarty = $params['smarty'];
            $urls = $smarty->getTemplateVars('urls');
            $currentUrl = $urls['current_url'];
            if (strpos($currentUrl, 'litespeedcache/esi') !== false) {
                $urls['current_url'] = $urls['base_url'];
                $urls = $this->context->smarty->getTemplateVars('urls');
                $urls['current_url'] = $urls['base_url'];
            }            
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
            $item->preRenderWidget();
            $item->setContent($module->renderWidget($item->getParam('h'), $params));
        }
    }

    private function processWidgetBlock($item)
    {
        $params = [];
        if (($module = $this->initWidget($item->getParam('m'), $params)) == null) {
            $item->setFailed();
        } else {
            $this->handleModuleVariables($params, $item);

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
            $this->handleModuleVariables($params, $item);

            if (method_exists($module, 'getWidgetVariables')) {
                $this->context->smarty->assign($module->getWidgetVariables('', $params));
            }

            $method = $item->getParam('mt');

            $content = $module->$method($params);

            // Avoid empty ESI fragments: some hooks/modules may return NULL or empty content
            if ($content === null || $content === ''){
                $content = '&nbsp;';
            }

            $item->setContent($content);
        }
    }

    private function handleModuleVariables(&$params, $item){
        $smarty = $params['smarty'];

        if(($mp=$item->getParam('mp')) && ($tas = $item->getConf()->getTemplateArgs())){
            $tas1 = explode(',', $tas);
            $mp1 = json_decode($mp, true);
            $i=0;
            foreach($tas1 as $mv){
                $mvs = explode('.', trim($mv));
                if($mvs[0]!='smarty'){
                    if(!$mvs[1]){
                        $params[$mvs[0]] = $mp1[$i];                        
                    } else {
                        if(isset($params[$mvs[0]])){

                            $params[$mvs[0]][$mvs[1]] = $mp1[$i];
                        } else {
                            $params[$mvs[0]] = [$mvs[1]=>$mp1[$i]];
                        }
                    }
                } else if(!$mvs[2]){
                    $smarty->assign($mvs[1],$mp1[$i]);
                } else {
                    $mv2 = $smarty->getTemplateVars($mvs[1]);
                    if($mv2){
                        $mv2[$mvs[2]] = $mp1[$i];
                        $smarty->assign($mvs[1],$mv2);
                    } else {
                        $mp2 = [$mvs[2]=>$mp1[$i]];
                        $smarty->assign($mvs[1],$mp2);
                    }
                }
                $i++;
            }
        }

        // Apply hook-specific parameter fixes for ESI rendering
        $hookParamsResolver = new HookParamsResolver($this->context);
        $hookParamsResolver->resolve($item, $params);
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

    private function processDynamicFragment($item)
    {
        switch ($item->getParam('f')) {
            case LiteSpeedCacheDynamicFragment::PRODUCT_ADD_TO_CART:
                $this->processProductAddToCartFragment($item);
                break;
            case LiteSpeedCacheDynamicFragment::NOTIFICATIONS:
                $this->processNotificationsFragment($item);
                break;
            default:
                $item->setFailed('Unknown dynamic fragment ' . $item->getParam('f'));
        }
    }

    private function processProductAddToCartFragment($item)
    {
        $product = $this->getPresentedProductFromItem($item);
        if ($product == null) {
            $item->setFailed('Missing product for add to cart fragment');

            return;
        }

        $this->assignBaseTemplateVariables();
        $this->context->smarty->assign('product', $product);

        $template = $item->getParam('t') ?: 'catalog/_partials/product-add-to-cart.tpl';
        $item->setContent($this->fetchThemeTemplate($template));
    }

    private function processNotificationsFragment($item)
    {
        $notifications = [
            'error' => [],
            'warning' => [],
            'success' => [],
            'info' => [],
        ];

        $idProduct = (int) $item->getParam('id_product');
        if ($idProduct > 0 && (bool) Configuration::get('PS_DISPLAY_AMOUNT_IN_CART')) {
            $quantities = $this->context->cart->getProductQuantityInAllVariants($idProduct);

            if ($quantities['standalone_quantity'] > 0 && $quantities['pack_quantity'] > 0) {
                $notifications['info'][] = $this->trans(
                    'Your cart contains %1s of these products and another %2s of these are included in packs in your cart.',
                    [$quantities['standalone_quantity'], $quantities['pack_quantity']],
                    'Shop.Theme.Catalog'
                );
            } elseif ($quantities['standalone_quantity'] > 0) {
                $notifications['info'][] = $this->trans(
                    'Your cart contains %1s of these products.',
                    [$quantities['standalone_quantity']],
                    'Shop.Theme.Catalog'
                );
            } elseif ($quantities['pack_quantity'] > 0) {
                $notifications['info'][] = $this->trans(
                    '%1s of these products are included in packs in your cart.',
                    [$quantities['pack_quantity']],
                    'Shop.Theme.Catalog'
                );
            }
        }

        $this->assignBaseTemplateVariables();
        $product = $this->getPresentedProductFromItem($item);
        if ($product != null) {
            $this->context->smarty->assign('product', $product);
        }
        $this->context->smarty->assign('notifications', $notifications);

        $template = $item->getParam('t') ?: '_partials/notifications.tpl';
        $item->setContent($this->fetchThemeTemplate($template));
    }

    private function assignBaseTemplateVariables()
    {
        $this->assignGeneralPurposeVariables();
    }

    private function getPresentedProductFromItem($item)
    {
        $idProduct = (int) $item->getParam('id_product');
        if ($idProduct <= 0) {
            return null;
        }

        $idProductAttribute = (int) $item->getParam('id_product_attribute');
        $idCustomization = (int) $item->getParam('id_customization');

        $productObj = new Product($idProduct, true, $this->context->language->id, $this->context->shop->id);
        if (!Validate::isLoadedObject($productObj)) {
            return null;
        }

        $product = (new PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter())->present($productObj);
        $product['id_product'] = $idProduct;
        $product['id_product_attribute'] = $idProductAttribute;
        $product['id_customization'] = $idCustomization;
        $product['out_of_stock'] = (int) $productObj->out_of_stock;
        $product['minimal_quantity'] = $this->getProductMinimalQuantity($idProduct, $idProductAttribute, $product);
        $product['cart_quantity'] = $this->context->cart->getProductQuantity($idProduct, $idProductAttribute)['quantity'];
        $product['quantity_wanted'] = (int) Tools::getValue('quantity_wanted', max(1, (int) $product['minimal_quantity']));
        $product['quantity_required'] = max(1, (int) $product['minimal_quantity'] - (int) $product['cart_quantity']);
        $product = Product::getProductProperties($this->context->language->id, $product, $this->context);
        if (!is_array($product)) {
            return null;
        }

        $factory = new ProductPresenterFactory($this->context, new TaxConfiguration());

        return $factory->getPresenter()->present(
            $factory->getPresentationSettings(),
            $product,
            $this->context->language
        );
    }

    private function getProductMinimalQuantity($idProduct, $idProductAttribute, $product)
    {
        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute);
            if (Validate::isLoadedObject($combination)) {
                return (int) $combination->minimal_quantity;
            }
        }

        if (isset($product['minimal_quantity'])) {
            return (int) $product['minimal_quantity'];
        }

        $productObj = new Product($idProduct, false, $this->context->language->id, $this->context->shop->id);
        if (Validate::isLoadedObject($productObj)) {
            return (int) $productObj->minimal_quantity;
        }

        return 1;
    }

    private function fetchThemeTemplate($template)
    {
        if (substr($template, -4) == '.tpl') {
            $template = substr($template, 0, -4);
        }

        return $this->context->smarty->fetch($this->getTemplateFile($template));
    }
}
