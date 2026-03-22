<?php

/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Resolver;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\HookManager;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Product\ProductPresentationSettings;

/**
 * HookParamsResolver — rebuilds/enriches $params for ESI rendering context.
 *
 * Some hooks rely on data prepared by front controllers (e.g. ProductController)
 * that is unavailable in ESI requests. This class patches those params.
 */
class HookParamsResolver
{
    private $context;

    public function __construct($context)
    {
        $this->context = $context;
    }

    public function resolve($item, array &$params): void
    {
        $method = $item->getConf()->getMethods();
        if (!$method) {
            return;
        }

        $map = [
            'hookDisplayProductAdditionalInfo' => 'resolveDisplayProductAdditionalInfo',
        ];

        if (!isset($map[$method])) {
            return;
        }

        $handler = $map[$method];
        if (method_exists($this, $handler)) {
            $this->$handler($params);
        }
    }

    private function resolveDisplayProductAdditionalInfo(array &$params): void
    {
        if (!isset($params['product']['id_product'])) {
            return;
        }

        $idProduct = (int) $params['product']['id_product'];
        $idProductAttribute = (int) ($params['product']['id_product_attribute'] ?? 0);

        $productObj = new \Product($idProduct, true, $this->context->language->id, $this->context->shop->id);
        if (!\Validate::isLoadedObject($productObj)) {
            return;
        }

        $presented = (new ObjectPresenter())->present($productObj);
        // ObjectPresenter maps ObjectModel::$id as 'id', but getProductProperties needs 'id_product'
        $presented['id_product'] = $idProduct;
        $presented['out_of_stock'] = (int) $productObj->out_of_stock;
        $presented['id_product_attribute'] = $idProductAttribute;

        $productFull = \Product::getProductProperties($this->context->language->id, $presented, $this->context);
        if (!is_array($productFull)) {
            return;
        }

        $productPresenter = new ProductPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator(),
            new HookManager(),
            new \PrestaShop\PrestaShop\Adapter\Configuration()
        );

        $settings = new ProductPresentationSettings();
        $settings->catalog_mode = (bool) \Configuration::get('PS_CATALOG_MODE');
        $settings->catalog_mode_with_prices = (bool) \Configuration::get('PS_CATALOG_MODE_WITH_PRICES');
        $settings->restricted_country_mode = isset($this->context->restricted_country_mode) && $this->context->restricted_country_mode;
        $settings->include_taxes = $this->context->country ? $this->context->country->display_tax_label : false;
        $settings->allow_add_variant_to_cart_from_listing = (int) \Configuration::get('PS_ATTRIBUTE_CATEGORY_DISPLAY');
        $settings->stock_management_enabled = (bool) \Configuration::get('PS_STOCK_MANAGEMENT');
        $settings->showPrices = !(bool) \Configuration::get('PS_CATALOG_MODE');
        $settings->lastRemainingItems = (int) \Configuration::get('PS_LAST_QTIES');
        $settings->showLabelOOSListingPages = (bool) \Configuration::get('PS_SHOW_LABEL_OOS_LISTING_PAGES');

        $params['product'] = $productPresenter->present($settings, $productFull, $this->context->language);
        $this->context->smarty->assign('product', $params['product']);
    }
}
