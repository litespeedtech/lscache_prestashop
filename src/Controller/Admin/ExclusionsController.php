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

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\ExclusionsConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Context;
class ExclusionsController extends AbstractController
{
    use NavPillsTrait;


    public function indexAction(Request $request): Response
    {
        $config = Conf::getInstance();
        $excl   = ExclusionsConfig::getAll();
        $all    = $config->getAllConfigValues();

        if ($request->isMethod('POST') && $request->request->has('submitExclusions')) {
            $this->handleSave($request, $config, $all);
            return $this->redirectToRoute('admin_litespeedcache_exclusions');
        }

        $groups = \Group::getGroups((int) Context::getContext()->language->id);

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/exclusions.html.twig', [
            'values'       => $excl,
            'nocache_urls' => $all['nocache_urls'] ?? '',
            'nocache_vars' => $all['nocache_vars'] ?? '',
            'groups'       => $groups,
            'noRoles'      => $excl[ExclusionsConfig::EXCL_NOCACHE_ROLES] ?? [],
        ], $request);
    }

    private function handleSave(Request $request, Conf $config, array $currentAll): void
    {
        // Update nocache_urls and nocache_vars in CacheConfig (ENTRY_ALL)
        $currentAll['nocache_urls'] = trim((string) $request->request->get('nocache_urls', ''));
        $currentAll['nocache_vars'] = trim((string) $request->request->get('nocache_vars', ''));
        $config->updateConfiguration(Conf::ENTRY_ALL, $currentAll);

        // Save the new exclusion fields
        $roles = $request->request->all()['exc_nocache_roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }

        $new = [
            ExclusionsConfig::EXCL_NOCACHE_CATS    => trim((string) $request->request->get('exc_nocache_cats', '')),
            ExclusionsConfig::EXCL_NOCACHE_COOKIES => trim((string) $request->request->get('exc_nocache_cookies', '')),
            ExclusionsConfig::EXCL_NOCACHE_UA      => trim((string) $request->request->get('exc_nocache_ua', '')),
            ExclusionsConfig::EXCL_NOCACHE_ROLES   => array_map('intval', $roles),
        ];

        ExclusionsConfig::saveAll($new);
        $this->addFlash('success', $this->trans('Exclusion settings saved.', 'Modules.Litespeedcache.Admin'));
    }
}
