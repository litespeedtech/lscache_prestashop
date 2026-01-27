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

use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Adapter\HookManager;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

/**
 * HookParamsResolver
 *
 * Applies hook-specific parameter normalization for LiteSpeed ESI rendering.
 * Some hooks rely on data normally prepared by front controllers (e.g. ProductController),
 * but in ESI context those params can be incomplete. This class rebuilds/enriches $params
 * only for supported hooks, keeping the logic isolated and easy to extend.
 */
class HookParamsResolver
{
    private $context;

    public function __construct($context)
    {
        $this->context = $context;
    }

    public function resolve($item, &$params)
    {
        $method = $item->getConf()->getMethods();
        if (!$method) {
            return;
        }

        // Map hook method => handler method
        $map = [
            'hookDisplayProductAdditionalInfo' => 'resolveDisplayProductAdditionalInfo',
        ];

        if (!isset($map[$method])) {
            return;
        }

        $handler = $map[$method];
        if (method_exists(__CLASS__, $handler)) {
            $this->$handler($params);
        }
    }

    private function resolveDisplayProductAdditionalInfo(&$params)
    {
        if (!isset($params['product']['id_product'])) {
            return;
        }

        $idProduct = (int) $params['product']['id_product'];
        $idProductAttribute = (int) ($params['product']['id_product_attribute'] ?? 0);

        $productObj = new Product($idProduct, true, $this->context->language->id, $this->context->shop->id);
        if (!Validate::isLoadedObject($productObj)) {
            return;
        }

        // Build base product array
        $presented = (new ObjectPresenter())->present($productObj);

        // Add fields commonly required by templates/modules
        $presented['out_of_stock'] = (int) $productObj->out_of_stock;
        $presented['id_product_attribute'] = $idProductAttribute;

        $productFull = Product::getProductProperties($this->context->language->id, $presented, $this->context);

        $productPresenter = new ProductPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator(),
            new HookManager(),
            new Configuration()
        );

        $factory = new ProductPresenterFactory($this->context, new TaxConfiguration());
        $settings = $factory->getPresentationSettings();

        $params['product'] = $productPresenter->present($settings, $productFull, $this->context->language);
        $this->context->smarty->assign('product', $params['product']);
    }
}
