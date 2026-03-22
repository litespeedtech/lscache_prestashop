<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ManageController extends AbstractController
{
    use NavPillsTrait;

    public function indexAction(Request $request): Response
    {
        if (\Shop::isFeatureActive()) {
            $shopLevel = (\Shop::getContext() === \Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $shopLevel = -1;
        }

        $licenseOk = CacheHelper::licenseEnabled();
        if (!$licenseOk) {
            $this->addFlash('error', $this->trans('LiteSpeed Server with LSCache module is required. Please contact your sysadmin or your host to get a valid LiteSpeed license.', 'Modules.Litespeedcache.Admin'));
        }

        $purgeSelectionValues = ['purgeby' => '', 'purgeids' => ''];

        if ($request->query->has('purge_shops')) {
            if ($this->doPurge('*')) {
                $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush all pages of this PrestaShop.', 'Modules.Litespeedcache.Admin'));
            }
            $referer = $request->headers->get('referer');

            return $referer ? $this->redirect($referer) : $this->redirectToRoute('admin_litespeedcache_manage');
        } elseif ($request->query->has('purge_all')) {
            if ($this->doPurge(1, 'ALL')) {
                $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush the entire cache storage.', 'Modules.Litespeedcache.Admin'));
            }

            return $this->redirectToRoute('admin_litespeedcache_manage');
        } elseif ($request->isMethod('POST') && $request->request->has('submitPurgeSelection')) {
            $this->handlePurgeSelection($request);

            return $this->redirectToRoute('admin_litespeedcache_manage');
        } elseif ($request->isMethod('POST') && $request->request->has('submitPurgeId')) {
            $purgeSelectionValues = $this->handlePurgeIds($request);

            return $this->redirectToRoute('admin_litespeedcache_manage');
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/tools/purge.html.twig', [
            'active_tab' => 'admin_litespeedcache_tools_purge',
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
            ],
            'shopLevel' => $shopLevel,
            'licenseOk' => $licenseOk,
            'purgeAllUrl' => $this->generateUrl('admin_litespeedcache_manage', ['purge_all' => 1]),
            'purgeSelectionValues' => $purgeSelectionValues,
        ], $request);
    }

    private function handlePurgeSelection(Request $request): void
    {
        $tags = [];
        $info = [];

        $map = [
            'cbPurge_home' => ['H',    'Home Page'],
            'cbPurge_404' => ['D404', 'All 404 Pages'],
            'cbPurge_brand' => ['M',    'All Brands Pages'],
            'cbPurge_supplier' => ['L',    'All Suppliers Pages'],
            'cbPurge_sitemap' => ['SP',   'Site Map'],
            'cbPurge_cms' => ['G',    'All CMS Pages'],
            'cbPurge_pc' => ['N',    'All Product Comments'],
            'cbPurge_priv' => ['PRIV', 'All Private ESI Blocks'],
        ];

        foreach ($map as $cb => [$tag, $label]) {
            if ($request->request->get($cb)) {
                $tags[] = $tag;
                $info[] = $label;
                if ($cb === 'cbPurge_priv') {
                    CacheHelper::clearInternalCache();
                }
            }
        }

        if ($request->request->get('cbPurge_search')) {
            $tags[] = 'SR';
            $tags[] = 'C';
            $tags[] = 'P';
            $info[] = 'All Categories and Products Pages';
        }

        if ($cid = $request->request->get('rcats')) {
            $tags[] = 'C' . $cid;
            $info[] = 'Category with ID ' . $cid;
        }

        if ($tags) {
            if ($this->doPurge($tags)) {
                $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush cached pages: %s', 'Modules.Litespeedcache.Admin', [implode(', ', $info)]));
            }
        } else {
            $this->addFlash('warning', $this->trans('Nothing selected. No action taken.', 'Modules.Litespeedcache.Admin'));
        }
    }

    private function handlePurgeIds(Request $request): array
    {
        $by = $request->request->get('purgeby');
        $id = $request->request->get('purgeids', '');

        $preMap = [
            'prod' => 'P',
            'cat' => 'C',
            'brand' => 'M',
            'supplier' => 'L',
            'cms' => 'G',
            'pc' => 'N',
            'shop' => 'S',
        ];

        if (!isset($preMap[$by])) {
            $this->addFlash('error', $this->trans('Illegal entrance', 'Modules.Litespeedcache.Admin'));

            return ['purgeby' => $by, 'purgeids' => $id];
        }

        $pre = $preMap[$by];
        $ids = preg_split('/[\s,]+/', $id, -1, PREG_SPLIT_NO_EMPTY);
        $tags = [];
        $ok = !empty($ids);

        foreach ($ids as $i) {
            if (((string) ((int) $i) === (string) $i) && ((int) $i > 0)) {
                $tags[] = $pre . $i;
            } else {
                $ok = false;
                break;
            }
        }

        if (!$ok) {
            $this->addFlash('error', $this->trans('Please enter valid IDs', 'Modules.Litespeedcache.Admin'));

            return ['purgeby' => $by, 'purgeids' => $id];
        }

        if ($this->doPurge($tags)) {
            $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush cached pages: %s %s', 'Modules.Litespeedcache.Admin', [$by, implode(', ', $ids)]));
        }

        return ['purgeby' => '', 'purgeids' => ''];
    }

    private function doPurge($tags, string $key = 'public'): bool
    {
        if (CacheState::isActive() || $tags === '*') {
            \Hook::exec('litespeedCachePurge', ['from' => 'AdminLiteSpeedCacheManage', $key => $tags]);
            // Send purge header directly for admin requests where the
            // output buffer callback may not run.
            if (!headers_sent() && $tags === '*') {
                header('X-LiteSpeed-Purge: *');
            }

            return true;
        }
        $this->addFlash('warning', $this->trans('No action taken. This Module is not enabled. Only action allowed is Flush All Prestashop Pages.', 'Modules.Litespeedcache.Admin'));

        return false;
    }
}
