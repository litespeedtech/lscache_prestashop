<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;

class AdvancedController extends FrameworkBundleAdminController
{
    public function indexAction(): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_cache_settings');
    }
}
