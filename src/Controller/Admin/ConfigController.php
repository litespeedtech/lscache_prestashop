<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigController extends FrameworkBundleAdminController
{
    public function indexAction(Request $request): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_cache_settings');
    }
}
