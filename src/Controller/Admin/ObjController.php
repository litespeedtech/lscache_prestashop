<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Helper\ObjectCacheActivator;
use LiteSpeed\Cache\Integration\ObjectCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ObjController extends AbstractController
{
    use NavPillsTrait;


    public function indexAction(Request $request): Response
    {
        $cfg = ObjConfig::getAll();

        if ($request->isMethod('POST')) {
            if ($request->request->has('submitObj')) {
                $this->handleSave($request);
                return $this->redirectToRoute('admin_litespeedcache_objectcache');
            }

            if ($request->request->has('testConnection')) {
                $result = ObjectCache::testConnection($cfg);
                $result
                    ? $this->addFlash('success', $this->trans('Connection successful.', 'Modules.Litespeedcache.Admin'))
                    : $this->addFlash('error', $this->trans('Connection failed. Check your host, port and credentials.', 'Modules.Litespeedcache.Admin'));
                return $this->redirectToRoute('admin_litespeedcache_objectcache');
            }
        }

        $extensions = ObjectCache::extensionStatus();
        $connStatus = $cfg[ObjConfig::OBJ_ENABLE] ? ObjectCache::testConnection($cfg) : null;

        // Reflect actual PrestaShop cache state (defined in bootstrap, not config)
        $psDriver  = defined('_PS_CACHING_SYSTEM_') ? _PS_CACHING_SYSTEM_ : null;
        $psEnabled = defined('_PS_CACHE_ENABLED_')  ? (bool) _PS_CACHE_ENABLED_ : false;

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/obj.html.twig', [
            'values'       => $cfg,
            'extensions'   => $extensions,
            'connStatus'   => $connStatus,
            'psEnabled'    => $psEnabled,
            'psDriver'     => $psDriver,
        ], $request);
    }

    private function handleSave(Request $request): void
    {
        $method = $request->request->get('obj_method', 'redis');
        if (!in_array($method, ['memcached', 'redis'], true)) {
            $method = 'redis';
        }

        $new = [
            ObjConfig::OBJ_ENABLE         => (int)  $request->request->get('obj_enable', 0),
            ObjConfig::OBJ_METHOD         => $method,
            ObjConfig::OBJ_HOST           => trim((string) $request->request->get('obj_host', 'localhost')),
            ObjConfig::OBJ_PORT           => max(1, (int) $request->request->get('obj_port', 6379)),
            ObjConfig::OBJ_TTL            => max(1, (int) $request->request->get('obj_ttl', 360)),
            ObjConfig::OBJ_USERNAME       => trim((string) $request->request->get('obj_username', '')),
            ObjConfig::OBJ_PASSWORD       => trim((string) $request->request->get('obj_password', '')),
            ObjConfig::OBJ_REDIS_DB       => max(0, (int) $request->request->get('obj_redis_db', 0)),
            ObjConfig::OBJ_GLOBAL_GROUPS  => trim((string) $request->request->get('obj_global_groups', '')),
            ObjConfig::OBJ_NOCACHE_GROUPS => trim((string) $request->request->get('obj_nocache_groups', '')),
            ObjConfig::OBJ_PERSISTENT     => (int)  $request->request->get('obj_persistent', 0),
            ObjConfig::OBJ_ADMIN_CACHE    => (int)  $request->request->get('obj_admin_cache', 0),
        ];

        ObjConfig::saveAll($new);
        $state = $new[ObjConfig::OBJ_ENABLE] ? 'enabled' : 'disabled';
        \PrestaShopLogger::addLog('Object cache (' . $new[ObjConfig::OBJ_METHOD] . ') ' . $state . ' — host: ' . $new[ObjConfig::OBJ_HOST] . ':' . $new[ObjConfig::OBJ_PORT], 1, null, 'LiteSpeedCache', 0, true);

        // Activate or deactivate the Redis driver in PrestaShop's cache layer
        if ($new[ObjConfig::OBJ_ENABLE] && $new[ObjConfig::OBJ_METHOD] === 'redis') {
            if (ObjectCacheActivator::enable($new)) {
                $this->addFlash('success', $this->trans('Object cache settings saved. Redis cache activated in PrestaShop.', 'Modules.Litespeedcache.Admin'));
            } else {
                $this->addFlash('warning', $this->trans('Settings saved but could not write cache configuration. Check file permissions on app/config/.', 'Modules.Litespeedcache.Admin'));
            }
        } else {
            ObjectCacheActivator::disable();
            $this->addFlash('success', $this->trans('Object cache settings saved.', 'Modules.Litespeedcache.Admin'));
        }
    }
}
