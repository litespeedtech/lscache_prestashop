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
 * @copyright  Copyright (c) 2017-2025 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

// for integration with PrestaBlog module (https://www.prestablog.fr)

use LiteSpeedCacheConfig as Conf;

class LscPrestablog extends LscIntegration
{
    const NAME = 'prestablog';

    const BLOG_CONTROLLER = 'PrestaBlogBlogModuleFrontController';

    protected function init()
    {
        // blog pages are public cacheable, tags added in initCacheTagsByController
        $this->addCacheableControllers([self::BLOG_CONTROLLER => '']);
        $this->addInitCacheTagAction($this);

        return true;
    }

    protected function initCacheTagsByController($params)
    {
        if (!isset($params['controller'])
            || strcasecmp(get_class($params['controller']), self::BLOG_CONTROLLER) != 0) {
            return null;
        }

        // bare blog tag is on every blog page, allows purge of all blog pages at once
        $tags = [Conf::TAG_PREFIX_BLOG];

        if (($id = (int) Tools::getValue('id')) > 0) { // single article
            $tags[] = Conf::TAG_PREFIX_BLOG . $id;
        } elseif (($catid = (int) Tools::getValue('c')) > 0) { // category listing
            $tags[] = Conf::TAG_PREFIX_BLOG_CATEGORY . $catid;
        }

        return $tags;
    }
}

LscPrestablog::register();
