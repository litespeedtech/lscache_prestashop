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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

class LiteSpeedCacheHelper
{
    /* unique prefix for this PS installation, avoid conflict of multiple installations within same cache root */

    public static function getTagPrefix()
    {
        $unique = str_split(md5(_PS_ROOT_DIR_));
        $tag = implode('', array_slice($unique, 0, 5)); // take 5 char
        return 'PS' . $tag;
    }

    public static function licenseEnabled()
    {
        // possible string "on,crawler,esi", will enforce checking in future
        return ( (isset($_SERVER['X-LSCACHE']) && $_SERVER['X-LSCACHE']) // for lsws
                || (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']));  // lslb
    }
}
