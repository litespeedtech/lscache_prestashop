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

use LiteSpeedCacheEsiModConf as EsiConf;

class LscDynamicFragment extends LscIntegration
{
    const NAME = 'litespeedcache_dynamic_fragment';

    protected function init()
    {
        $confData = [
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_TAG => LiteSpeedCacheConfig::TAG_CART,
            EsiConf::FLD_TTL => 0,
        ];
        $this->esiConf = new EsiConf(self::NAME, EsiConf::TYPE_BUILTIN, $confData);

        return $this->registerEsiModule();
    }

    public static function isUsed($name)
    {
        return $name == self::NAME;
    }
}

LscDynamicFragment::register();
