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

class LscStWishlist extends LscIntegration
{
    const NAME = 'stwishlist';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_TAG => 'stwish',
            EsiConf::FLD_PURGE_CONTROLLERS => 'stwishlistmywishlistModuleFrontController?action',
            EsiConf::FLD_HOOK_METHODS => 'hookdisplaySideBar',
            EsiConf::FLD_IGNORE_EMPTY => 0,
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_INTEGRATED, $confData);
        $this->esiConf->setCustomHandler($this);

        $this->registerEsiModule();
        LiteSpeedCacheConfig::getInstance()->enforceDiffCustomerGroup(2); // $diffCustomerGroup 0: No; 1: Yes; 2: login_out

        return true;
    }


}

LscStWishlist::register();
