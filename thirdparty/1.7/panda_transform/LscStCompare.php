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

use LiteSpeedCacheEsiModConf as EsiConf;

class LscStCompare extends LscIntegration
{
    const NAME = 'stcompare';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_PURGE_CONTROLLERS => 'StCompareCompareModuleFrontController?custom_handler',
            EsiConf::FLD_IGNORE_EMPTY => 1,
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_INTEGRATED, $confData);
        $this->esiConf->setCustomHandler($this);

        $this->registerEsiModule();
        $this->addCheckPurgeControllerCustomHandler('StCompareCompareModuleFrontController', $this);
        return true;
    }

    public function canInject(&$params)
    {
        if ($params['pt'] != 'rw') {
            return false;
        }
        if (stripos($params['h'], 'Product') !== false) {
            // product level, adjust params
            $pid = Tools::getValue('id_product');
            $params['id_product'] = $pid;
            // item cache is for related esi block, do not save at product id level
        } 
        
        return true;
    }

    public function getTags($params)
    {
        $tags = ['compare'];
        if (isset($params['id_product'])) {
            $tags[] = 'compare_' . $params['id_product'];
        }
        return $tags;
    }

    public function getNoItemCache($params)
    {
        return isset($params['id_product']);
    }

    //id_product,StCompareCompareModuleFrontController?action'
    protected function checkPurgeControllerCustomHandler($lowercase_controller_class, &$tags)
    {
        // * @param type $tags = ['pub' => [], 'priv' => []];
        if (Tools::getValue('action') == false) {
            return;
        }
        $pid = Tools::getValue('id_product');
        if ($pid) {
            $tags['priv'][] = "compare_$pid"; // addCompareProduct, deleteCompareProduct
        } else {
            $tags['priv'][] = 'compare'; // deleteAllCompareProducts
        }
    }
}

LscStCompare::register();
