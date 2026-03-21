<?php
namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Integration\Cloudflare;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CdnController extends FrameworkBundleAdminController
{
    use NavPillsTrait;


    public function indexAction(Request $request): Response
    {
        $cfg       = CdnConfig::getAll();
        $cfEnabled = (bool) $cfg[CdnConfig::CF_ENABLE];
        $zoneId    = $cfg[CdnConfig::CF_ZONE_ID];

        $cfReady = $cfEnabled && $zoneId !== '';

        if ($request->isMethod('POST')) {
            if ($request->request->has('submitCdn')) {
                $this->handleSave($request, $cfg);
                return $this->redirectToRoute('admin_litespeedcache_cdn');
            }

            if ($cfReady) {
                $cf = $this->buildApi($cfg);

                if ($request->request->has('cf_dev_on')) {
                    $cf->setDevMode($zoneId, true)
                        ? $this->addFlash('success', $this->trans('Development mode enabled.', 'Modules.Litespeedcache.Admin'))
                        : $this->addFlash('error', $this->trans('Failed to enable development mode: %s', 'Modules.Litespeedcache.Admin', [$cf->lastError]));
                } elseif ($request->request->has('cf_dev_off')) {
                    $cf->setDevMode($zoneId, false)
                        ? $this->addFlash('success', $this->trans('Development mode disabled.', 'Modules.Litespeedcache.Admin'))
                        : $this->addFlash('error', $this->trans('Failed to disable development mode: %s', 'Modules.Litespeedcache.Admin', [$cf->lastError]));
                } elseif ($request->request->has('cf_dev_check')) {
                    $status = $cf->getDevModeStatus($zoneId);
                    $this->addFlash('info', $this->trans('Development mode is currently: %s', 'Modules.Litespeedcache.Admin', [$status ?? 'unknown']));
                } elseif ($request->request->has('cf_purge_all')) {
                    $cf->purgeAll($zoneId)
                        ? $this->addFlash('success', $this->trans('Cloudflare cache purged successfully.', 'Modules.Litespeedcache.Admin'))
                        : $this->addFlash('error', $this->trans('Failed to purge Cloudflare cache: %s', 'Modules.Litespeedcache.Admin', [$cf->lastError]));
                }
            } else {
                $this->addFlash('error', $this->trans('Cloudflare is not ready. Enable the API and save your credentials first.', 'Modules.Litespeedcache.Admin'));
            }

            return $this->redirectToRoute('admin_litespeedcache_cdn');
        }

        $cfInfo = [
            'domain'   => $cfg[CdnConfig::CF_DOMAIN] ?: '-',
            'zone'     => $zoneId ?: '-',
            'dev_mode' => null,
        ];

        if ($cfReady) {
            $cf               = $this->buildApi($cfg);
            $cfInfo['zone']   = $cf->getZoneName($zoneId);
            $cfInfo['dev_mode'] = $cf->getDevModeStatus($zoneId);
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/cdn.html.twig', [
            'values'      => $cfg,
            'cfInfo'      => $cfInfo,
            'cfEnabled'   => $cfEnabled,
            'cfReady'     => $cfReady,
        ], $request);
    }

    private function handleSave(Request $request, array $oldCfg): void
    {
        $new = [
            CdnConfig::CF_ENABLE  => (int) $request->request->get('cf_enable', 0),
            CdnConfig::CF_KEY     => trim((string) $request->request->get('cf_key', '')),
            CdnConfig::CF_EMAIL   => trim((string) $request->request->get('cf_email', '')),
            CdnConfig::CF_DOMAIN  => trim((string) $request->request->get('cf_domain', '')),
            CdnConfig::CF_PURGE   => (int) $request->request->get('cf_purge', 0),
            CdnConfig::CF_ZONE_ID => $oldCfg[CdnConfig::CF_ZONE_ID] ?? '',
        ];

        $domainChanged = $new[CdnConfig::CF_DOMAIN] !== $oldCfg[CdnConfig::CF_DOMAIN];
        $keyChanged    = $new[CdnConfig::CF_KEY] !== $oldCfg[CdnConfig::CF_KEY];
        $noZone        = $new[CdnConfig::CF_ZONE_ID] === '';

        if ($new[CdnConfig::CF_ENABLE] && $new[CdnConfig::CF_KEY] && $new[CdnConfig::CF_DOMAIN]
            && ($domainChanged || $keyChanged || $noZone)
        ) {
            $cf     = new Cloudflare($new[CdnConfig::CF_KEY], $new[CdnConfig::CF_EMAIL]);
            $zoneId = $cf->findZone($new[CdnConfig::CF_DOMAIN]);

            if ($zoneId) {
                $new[CdnConfig::CF_ZONE_ID] = $zoneId;
                $this->addFlash('success', $this->trans('Cloudflare zone resolved: %s', 'Modules.Litespeedcache.Admin', [$zoneId]));
            } else {
                $new[CdnConfig::CF_ZONE_ID] = '';
                $this->addFlash('warning', $this->trans('Cloudflare zone not found for domain "%s". Check your API credentials and domain name.', [$new[CdnConfig::CF_DOMAIN]], 'Modules.Litespeedcache.Admin'));
            }
        }

        CdnConfig::saveAll($new);
        $this->addFlash('success', $this->trans('CDN settings saved.', 'Modules.Litespeedcache.Admin'));
    }

    private function buildApi(array $cfg): Cloudflare
    {
        return new Cloudflare($cfg[CdnConfig::CF_KEY], $cfg[CdnConfig::CF_EMAIL]);
    }
}
