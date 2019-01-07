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
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://opensource.org/licenses/GPL-3.0
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2019 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

/**
 * LiteSpeed Cache needs to do ESI hole punch automatically.
 * So any private data in javascript variable needs to be replaced with esi:include.
 * This should not affect any existing functionalities of your Prestashop installation
 * and it is safe to use.
 */

class Media extends MediaCore
{
    public static function addJsDef($jsDef)
    {
        if (defined('_LITESPEED_CACHE_')) {
            LiteSpeedCache::filterJsDef($jsDef);
        }
        parent::addJsDef($jsDef);
    }
}
