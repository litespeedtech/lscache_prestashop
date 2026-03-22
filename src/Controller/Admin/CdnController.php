<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Integration\Cloudflare;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CdnController extends AbstractController
{
    use NavPillsTrait;

    public function indexAction(Request $request): Response
    {
        $cfg = CdnConfig::getAll();
        $cfEnabled = (bool) $cfg[CdnConfig::CF_ENABLE];
        $zoneId = $cfg[CdnConfig::CF_ZONE_ID];

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
                } elseif ($request->request->has('cf_proxy_on')) {
                    $cf->setProxyStatus($zoneId, $cfg[CdnConfig::CF_DOMAIN], true)
                        ? $this->addFlash('success', $this->trans('Proxy mode enabled.', 'Modules.Litespeedcache.Admin'))
                        : $this->addFlash('error', $this->trans('Failed to enable proxy: %s', 'Modules.Litespeedcache.Admin', [$cf->lastError]));
                } elseif ($request->request->has('cf_proxy_off')) {
                    $cf->setProxyStatus($zoneId, $cfg[CdnConfig::CF_DOMAIN], false)
                        ? $this->addFlash('success', $this->trans('Proxy mode disabled (DNS only).', 'Modules.Litespeedcache.Admin'))
                        : $this->addFlash('error', $this->trans('Failed to disable proxy: %s', 'Modules.Litespeedcache.Admin', [$cf->lastError]));
                } elseif ($request->request->has('cf_unblock_bots')) {
                    $cf->deleteFirewallRule($zoneId, 'Block Problematic Bots');
                    $this->addFlash('success', $this->trans('Bot blocking rule removed.', 'Modules.Litespeedcache.Admin'));
                    \PrestaShopLogger::addLog('Block bots rule removed', 1, null, 'LiteSpeedCache', 0, true);
                } elseif ($request->request->has('cf_block_countries')) {
                    $result = $cf->createBlockProblematicCountries($zoneId);
                    if ($result['success']) {
                        $msg = $result['value'] === 'already_exists' ? 'Country blocking rule already exists.' : 'Problematic countries blocked successfully.';
                        $this->addFlash('success', $this->trans($msg, 'Modules.Litespeedcache.Admin'));
                    } else {
                        $this->addFlash('error', $this->trans('Failed to create country blocking rule: %s', 'Modules.Litespeedcache.Admin', [$result['error']]));
                    }
                    \PrestaShopLogger::addLog('Block countries rule: ' . ($result['value'] ?? 'error'), 1, null, 'LiteSpeedCache', 0, true);
                } elseif ($request->request->has('cf_unblock_countries')) {
                    $cf->deleteFirewallRule($zoneId, 'Block Problematic Countries');
                    $this->addFlash('success', $this->trans('Country blocking rule removed.', 'Modules.Litespeedcache.Admin'));
                    \PrestaShopLogger::addLog('Block countries rule removed', 1, null, 'LiteSpeedCache', 0, true);
                } elseif ($request->request->has('cf_revert_optimize')) {
                    $results = $cf->revertOptimization($zoneId);
                    $this->addFlash('success', $this->trans('Cloudflare optimization reverted to previous settings.', 'Modules.Litespeedcache.Admin'));
                    \PrestaShopLogger::addLog('Cloudflare optimization reverted', 2, null, 'LiteSpeedCache', 0, true);
                } elseif ($request->request->has('cf_block_bots')) {
                    $result = $cf->createBlockProblematicBots($zoneId);
                    if ($result['success']) {
                        $msg = $result['value'] === 'already_exists' ? 'Bot blocking rule already exists.' : 'Problematic bots blocked successfully.';
                        $this->addFlash('success', $this->trans($msg, 'Modules.Litespeedcache.Admin'));
                    } else {
                        $this->addFlash('error', $this->trans('Failed to create bot blocking rule: %s', 'Modules.Litespeedcache.Admin', [$result['error']]));
                    }
                    \PrestaShopLogger::addLog('Block problematic bots rule: ' . ($result['value'] ?? 'error'), 1, null, 'LiteSpeedCache', 0, true);
                } elseif ($request->request->has('cf_optimize')) {
                    $results = $cf->optimizeForPrestaShop($zoneId);
                    $success = array_filter($results, fn ($r) => $r['success']);
                    $failed = array_filter($results, fn ($r) => !$r['success']);

                    if (count($success) > 0) {
                        $this->addFlash('success', $this->trans('%d settings optimized for PrestaShop.', 'Modules.Litespeedcache.Admin', [count($success)]));
                    }
                    foreach ($failed as $key => $r) {
                        $this->addFlash('warning', $key . ': ' . $r['error']);
                    }
                    if (empty($failed)) {
                        $this->addFlash('success', $this->trans('All Cloudflare settings applied. Cache purged.', 'Modules.Litespeedcache.Admin'));
                    }
                    \PrestaShopLogger::addLog('Cloudflare optimized for PrestaShop: ' . count($success) . ' OK, ' . count($failed) . ' failed', 1, null, 'LiteSpeedCache', 0, true);
                }
            } else {
                $this->addFlash('error', $this->trans('Cloudflare is not ready. Enable the API and save your credentials first.', 'Modules.Litespeedcache.Admin'));
            }

            return $this->redirectToRoute('admin_litespeedcache_cdn');
        }

        $cfInfo = [
            'domain' => $cfg[CdnConfig::CF_DOMAIN] ?: '-',
            'zone' => $zoneId ?: '-',
            'dev_mode' => null,
        ];

        $cfSettings = [];
        $cfProxy = null;
        $cfOptimized = false;
        $cfPendingSettings = [];
        $cfBotsBlocked = false;
        $cfCountriesBlocked = false;
        if ($cfReady) {
            $cf = $this->buildApi($cfg);
            $cfInfo['zone'] = $cf->getZoneName($zoneId);
            $cfInfo['dev_mode'] = $cf->getDevModeStatus($zoneId);
            $cfSettings = $cf->getAllSettings($zoneId);
            $cfProxy = $cf->getProxyStatus($zoneId, $cfg[CdnConfig::CF_DOMAIN]);
            $cfPendingSettings = $this->getPendingOptimizations($cfSettings);
            $cfOptimized = empty($cfPendingSettings);
            $cfBotsBlocked = $cf->hasFirewallRule($zoneId, 'Block Problematic Bots');
            $cfCountriesBlocked = $cf->hasFirewallRule($zoneId, 'Block Problematic Countries');
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/cdn.html.twig', [
            'values' => $cfg,
            'cfInfo' => $cfInfo,
            'cfEnabled' => $cfEnabled,
            'cfReady' => $cfReady,
            'cfProxy' => $cfProxy,
            'cfSettings' => $cfSettings,
            'cfOptimized' => $cfOptimized,
            'cfPendingSettings' => $cfPendingSettings,
            'cfBotsBlocked' => $cfBotsBlocked,
            'cfCountriesBlocked' => $cfCountriesBlocked,
            'cfCanRevert' => (bool) \Configuration::getGlobalValue('LITESPEED_CF_OPTIMIZE_BACKUP'),
        ], $request);
    }

    private function handleSave(Request $request, array $oldCfg): void
    {
        $new = [
            CdnConfig::CF_ENABLE => (int) $request->request->get('cf_enable', 0),
            CdnConfig::CF_KEY => trim((string) $request->request->get('cf_key', '')),
            CdnConfig::CF_EMAIL => trim((string) $request->request->get('cf_email', '')),
            CdnConfig::CF_DOMAIN => trim((string) $request->request->get('cf_domain', '')),
            CdnConfig::CF_PURGE => (int) $request->request->get('cf_purge', 0),
            CdnConfig::CF_ZONE_ID => $oldCfg[CdnConfig::CF_ZONE_ID] ?? '',
        ];

        $domainChanged = $new[CdnConfig::CF_DOMAIN] !== $oldCfg[CdnConfig::CF_DOMAIN];
        $keyChanged = $new[CdnConfig::CF_KEY] !== $oldCfg[CdnConfig::CF_KEY];
        $noZone = $new[CdnConfig::CF_ZONE_ID] === '';

        if ($new[CdnConfig::CF_ENABLE] && $new[CdnConfig::CF_KEY] && $new[CdnConfig::CF_DOMAIN]
            && ($domainChanged || $keyChanged || $noZone)
        ) {
            $cf = new Cloudflare($new[CdnConfig::CF_KEY], $new[CdnConfig::CF_EMAIL]);
            $zoneId = $cf->findZone($new[CdnConfig::CF_DOMAIN]);

            if ($zoneId) {
                $new[CdnConfig::CF_ZONE_ID] = $zoneId;
                $this->addFlash('success', $this->trans('Cloudflare zone resolved: %s', 'Modules.Litespeedcache.Admin', [$zoneId]));
            } else {
                $new[CdnConfig::CF_ZONE_ID] = '';
                $this->addFlash('warning', $this->trans('Cloudflare zone not found for domain "%domain%". Check your API credentials and domain name.', ['%domain%' => $new[CdnConfig::CF_DOMAIN]], 'Modules.Litespeedcache.Admin'));
            }
        }

        CdnConfig::saveAll($new);
        $this->addFlash('success', $this->trans('CDN settings saved.', 'Modules.Litespeedcache.Admin'));
    }

    private function getPendingOptimizations(array $cfSettings): array
    {
        $expected = [
            'ssl' => ['target' => 'full',     'label' => 'SSL/TLS Mode',                'good' => ['full', 'strict']],
            'tls_1_3' => ['target' => 'on',       'label' => 'TLS 1.3',                     'good' => ['on', 'zrt']],
            'http3' => ['target' => 'on',       'label' => 'HTTP/3 (QUIC)',                'good' => ['on']],
            'brotli' => ['target' => 'on',       'label' => 'Brotli Compression',           'good' => ['on']],
            'early_hints' => ['target' => 'on',       'label' => 'Early Hints (103)',             'good' => ['on']],
            'always_use_https' => ['target' => 'on',       'label' => 'Always Use HTTPS',              'good' => ['on']],
            '0rtt' => ['target' => 'on',       'label' => '0-RTT Connection Resumption',   'good' => ['on']],
            'rocket_loader' => ['target' => 'off',      'label' => 'Rocket Loader',                 'good' => ['off']],
            'email_obfuscation' => ['target' => 'off',      'label' => 'Email Obfuscation',             'good' => ['off']],
            'cache_level' => ['target' => 'basic',    'label' => 'Cache Level',                   'good' => ['basic']],
            'browser_cache_ttl' => ['target' => '0',        'label' => 'Browser Cache TTL',             'good' => ['0', 0]],
            'security_level' => ['target' => 'medium',   'label' => 'Security Level',                'good' => ['medium']],
            'challenge_ttl' => ['target' => '3600',     'label' => 'Challenge TTL',                 'good' => ['3600', 3600]],
            'browser_check' => ['target' => 'on',       'label' => 'Browser Integrity Check',       'good' => ['on']],
        ];

        $pending = [];
        foreach ($expected as $key => $def) {
            $current = $cfSettings[$key] ?? null;
            if ($current === null || !in_array($current, $def['good'], false)) {
                $pending[$key] = [
                    'label' => $def['label'],
                    'current' => $current ?? '—',
                    'target' => $def['target'],
                ];
            }
        }

        // Check minify separately
        $minify = $cfSettings['minify'] ?? [];
        if (!is_array($minify) || ($minify['css'] ?? '') !== 'off' || ($minify['js'] ?? '') !== 'off' || ($minify['html'] ?? '') !== 'off') {
            $pending['minify'] = [
                'label' => 'Auto Minify (CSS/JS/HTML)',
                'current' => 'on',
                'target' => 'off',
            ];
        }

        return $pending;
    }

    private function buildApi(array $cfg): Cloudflare
    {
        return new Cloudflare($cfg[CdnConfig::CF_KEY], $cfg[CdnConfig::CF_EMAIL]);
    }
}
