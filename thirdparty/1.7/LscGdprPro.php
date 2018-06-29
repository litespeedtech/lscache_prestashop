<?php
/**
 * LiteSpeed Cache for Prestashop
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
 * @copyright  Copyright (c) 2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

/* for integration with PrestaChamps gdprpro module */

use LiteSpeedCacheEsiModConf as EsiConf;
use LiteSpeedCacheLog as LSLog;

class LscGdprPro extends LscIntegration
{
    const NAME = 'gdprpro';

    protected function init()
    {
        $confData = array(
            EsiConf::FLD_PRIV => 1,
            EsiConf::FLD_TAG => 'gdprpro',
            EsiConf::FLD_PURGE_CONTROLLERS => 'GdprProStoreCookieModuleFrontController',
            EsiConf::FLD_ASVAR => 1,
        );
        $this->esiConf = new LiteSpeedCacheEsiModConf(self::NAME, EsiConf::TYPE_INTEGRATED, $confData);
        $this->registerEsiModule();
        LiteSpeedCacheConfig::getInstance()->overrideGuestMode();
        $this->addJsDef('gdprSettings:showWindow', $this);
        return true;
    }

    protected function JSKeyProcess($jskey)
    {
        if ($jskey != 'gdprSettings:showWindow') {
            //something wrong, should not happen
            LSLog::log(__FUNCTION__ . ' unexpected key ' . $jskey, LSLog::LEVEL_EXCEPTION);
            return '';
        }
        $data = !Context::getContext()->cookie->gdpr_windows_was_opened;
        return json_encode($data);
    }
}

LscGdprPro::register();
