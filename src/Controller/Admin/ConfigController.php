<?php


namespace LiteSpeed\Cache\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigController extends AbstractController
{
    public function indexAction(Request $request): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_cache_settings');
    }
}
