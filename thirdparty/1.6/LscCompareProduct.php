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

use LiteSpeedCacheEsiModConf as EsiConf;
use LiteSpeedCacheLog as LSLog;

// this is for PS1.6 only
class LscCompareProduct extends LscIntegration
{
    const NAME = 'lsc_compareproduct';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_TAG => 'compare',
            EsiConf::FLD_PURGE_CONTROLLERS => 'CompareController?id_product',
            EsiConf::FLD_ASVAR => 1,
            EsiConf::FLD_TIPURL => 'https://www.litespeedtech.com/support/wiki/doku.php/'
            . 'litespeed_wiki:cache:lscps:customization_1_6#update_template_for_compare_product_feature',
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_BUILTIN, $confData);
        $this->registerEsiModule();

        $this->addJsDef('comparedProductsIds', $this);

        return true;
    }

    protected function jsKeyProcess($jskey)
    {
        $value = '[]';
        if ($jskey != 'comparedProductsIds') {
            //something wrong, should not happen
            LSLog::log(__FUNCTION__ . ' unexpected key ' . $jskey, LSLog::LEVEL_EXCEPTION);
        } elseif (isset(Context::getContext()->cookie->id_compare)) {
            $compared_products = CompareProduct::getCompareProducts(Context::getContext()->cookie->id_compare);
            if (is_array($compared_products)) {
                $value = '[' . implode(',', $compared_products) . ']';
            }
        }

        return $value;
    }

    protected function moduleFieldProcess($params)
    {
        $countVal = 0;
        if ($params['f'] == 'comparedcount' && isset(Context::getContext()->cookie->id_compare)) {
            $compared_products = CompareProduct::getCompareProducts(Context::getContext()->cookie->id_compare);
            $countVal = count($compared_products);
        }

        return "$countVal";
    }

    public static function isUsed($name)
    {
        $res = ($name == self::NAME)
            && Configuration::get('PS_COMPARATOR_MAX_ITEM')
            && method_exists('CompareProduct', 'getCompareProducts');

        return $res;
    }
}

LscCompareProduct::register();

/*
 * README: If you want to enable comparison, you have to locate the template file and modify it to inject ESI.
 * For example, the default one is located at
 *     themes/default-bootstrap/product-compare.tpl
 * You need to identify the actual theme template used.
 *
 * Please wrap around
 *      {count($compared_products)}
 * with {hook h="litespeedEsiBegin" m="lsc_compareproduct" field="comparedcount"} in front
 * and {hook h="litespeedEsiEnd"} at back
 *
 * Please do not add extra space or line break in between.
 *
 * Please note, "{count($compared_products)}" appeared twice, both need to be replaced.
 * Please check the wiki link for more info
 */
