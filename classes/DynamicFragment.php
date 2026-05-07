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
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

class LiteSpeedCacheDynamicFragment
{
    const PRODUCT_ADD_TO_CART = 'product_add_to_cart';

    const NOTIFICATIONS = 'notifications';

    public static function isSupported($params)
    {
        return !empty($params['name']) && in_array($params['name'], self::getSupportedNames());
    }

    public static function buildEsiParam($params)
    {
        if (!self::isSupported($params)) {
            return null;
        }

        $product = isset($params['product']) ? $params['product'] : false;
        $idProduct = self::getProductId($product);
        if ($idProduct <= 0) {
            return null;
        }

        $esiParam = [
            'pt' => LiteSpeedCacheEsiItem::ESI_DYNAMIC_FRAGMENT,
            'm' => LscDynamicFragment::NAME,
            'f' => $params['name'],
            'id_product' => $idProduct,
            'id_product_attribute' => (int) self::getProductValue($product, 'id_product_attribute'),
            'id_customization' => (int) self::getProductValue($product, 'id_customization'),
        ];

        if (!empty($params['template'])) {
            $esiParam['t'] = $params['template'];
        }

        return $esiParam;
    }

    private static function getSupportedNames()
    {
        return [
            self::PRODUCT_ADD_TO_CART,
            self::NOTIFICATIONS,
        ];
    }

    private static function getProductId($product)
    {
        $idProduct = self::getProductValue($product, 'id_product');

        return $idProduct ? (int) $idProduct : (int) self::getProductValue($product, 'id');
    }

    private static function getProductValue($product, $field)
    {
        if (empty($product)) {
            return null;
        }

        if (is_array($product) || $product instanceof ArrayAccess) {
            return isset($product[$field]) ? $product[$field] : null;
        }

        if (is_object($product) && isset($product->$field)) {
            return $product->$field;
        }

        return null;
    }
}
