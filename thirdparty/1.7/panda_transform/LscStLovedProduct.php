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

class LscStLovedProduct extends LscIntegration
{
    const NAME = 'stlovedproduct';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_PURGE_CONTROLLERS => 'StLovedProductMyLovedModuleFrontController?custom_handler',
            EsiConf::FLD_IGNORE_EMPTY => 0,
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_INTEGRATED, $confData);
        $this->esiConf->setCustomHandler($this);

        $this->registerEsiModule();
        LiteSpeedCacheConfig::getInstance()->overrideGuestMode();
        LiteSpeedCacheConfig::getInstance()->enforceDiffCustomerGroup(2); // $diffCustomerGroup 0: No; 1: Yes; 2: login_out

        $this->addCheckPurgeControllerCustomHandler('StLovedProductMyLovedModuleFrontController', $this);
        return true;
    }

    public function canInject(&$params)
    {
        switch ($params['pt']) { // param type
            case 'rw':
                if (stripos($params['h'], 'Product') !== false) {
                    // product level, adjust params
                    $pid = Tools::getValue('id_product');
                    $params['id_product'] = $pid;
                }
                return true;

            case 'ch':
                return ('hookdisplaySideBar' == $params['mt']);

            default:
                return false;
        }
    }

    public function getNoItemCache($params)
    {
        return isset($params['id_product']);
    }

    public function getTags($params)
    {
        $tags = ['stloved'];
        if ($this->isLoggedIn()) { // private tags
            if (isset($params['id_product'])) {
                $tags[] = 'public:stloved_' . $params['id_product'];
            }
        } else { // public tags
            if (isset($params['id_product'])) {
                $tags[] = 'stloved_' . $params['id_product'];
            }
        }
        return $tags;
    }

    public function isPrivate($params)
    {
        // for non-logged-in user, it's public ESI
        return $this->isLoggedIn();
    }

    //id_product,StLovedProductMyLovedModuleFrontController?action'
    protected function checkPurgeControllerCustomHandler($lowercase_controller_class, &$tags)
    {
        // * @param type $tags = ['pub' => [], 'priv' => []];
        if (Tools::getValue('action') == false) {
            return;
        }
        $pid = Tools::getValue('id_source');
        if ($pid) {
            $tags['pub'][] = "stloved_$pid"; // addCompareProduct, deleteCompareProduct
        } 
        $tags['priv'][] = 'stloved'; // private loved ESI block
    }
}

LscStLovedProduct::register();
