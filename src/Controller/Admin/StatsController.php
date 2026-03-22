<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Config\ObjConfig;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Integration\Cloudflare;
use LiteSpeed\Cache\Integration\ObjectCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StatsController extends AbstractController
{
    use NavPillsTrait;


    public function indexAction(Request $request): Response
    {
        $config = Conf::getInstance();

        // LiteSpeed Cache
        $lsEnabled = (bool) $config->get(Conf::CFG_ENABLED);
        $lsActive  = CacheHelper::licenseEnabled();
        $lsStats   = $lsEnabled ? $this->getLsStats() : null;

        // Redis / Object Cache
        $objCfg     = ObjConfig::getAll();
        $objEnabled = (bool) $objCfg[ObjConfig::OBJ_ENABLE];
        $redisStats = $objEnabled ? ObjectCache::getStats($objCfg) : null;

        // Cloudflare CDN
        $cdnCfg    = CdnConfig::getAll();
        $cfEnabled = (bool) $cdnCfg[CdnConfig::CF_ENABLE] && !empty($cdnCfg[CdnConfig::CF_ZONE_ID]);
        $cfStats = null;
        $cfError = '';
        if ($cfEnabled) {
            try {
                $cf      = new Cloudflare($cdnCfg[CdnConfig::CF_KEY], $cdnCfg[CdnConfig::CF_EMAIL]);
                $cfStats = $cf->getAnalytics($cdnCfg[CdnConfig::CF_ZONE_ID]);
                $cfError = $cf->lastError;
            } catch (\Exception $e) {
                $cfError = $e->getMessage();
            }
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/stats.html.twig', [
            'lsEnabled'   => $lsEnabled,
            'lsActive'    => $lsActive,
            'lsStats'     => $lsStats,
            'objEnabled'  => $objEnabled,
            'redisStats'  => $redisStats,
            'cfEnabled'   => $cfEnabled,
            'cfStats'     => $cfStats,
            'cfError'     => $cfError,
        ], $request);
    }

    private function getLsStats(): array
    {
        $lastPurge = (int) \Configuration::getGlobalValue('LITESPEED_STAT_LAST_PURGE');

        return [
            'purge_count'  => (int) \Configuration::getGlobalValue('LITESPEED_STAT_PURGE_COUNT'),
            'cached_resp'  => (int) \Configuration::getGlobalValue('LITESPEED_STAT_CACHED_RESP'),
            'last_purge'   => $lastPurge > 0 ? date('Y-m-d H:i:s', $lastPurge) : null,
        ];
    }
}
