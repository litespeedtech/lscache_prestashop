<?php

namespace LiteSpeed\Cache\Controller\Admin;

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
