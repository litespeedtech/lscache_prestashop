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

class LscStShoppingcart extends LscIntegration
{
    const NAME = 'stshoppingcart';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_TAG => LiteSpeedCacheConfig::TAG_CART,
            EsiConf::FLD_PURGE_CONTROLLERS => 'CartController?id_product,StShoppingcartAjaxModuleFrontController',
            EsiConf::FLD_RENDER_WIDGETS => '*',
            EsiConf::FLD_HOOK_METHODS => 'hookDisplaySideBar',
            EsiConf::FLD_IGNORE_EMPTY => 1,
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_INTEGRATED, $confData);

        return $this->registerEsiModule();
    }
}

LscStShoppingcart::register();
