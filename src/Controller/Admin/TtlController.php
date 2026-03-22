<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Admin\ConfigValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TtlController extends AbstractController
{
    use NavPillsTrait;

    public function indexAction(Request $request): Response
    {
        $config = Conf::getInstance();

        if (\Shop::isFeatureActive()) {
            $shopLevel = (\Shop::getContext() === \Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $shopLevel = -1;
        }

        $currentValues = $config->getAllConfigValues();

        if ($request->isMethod('POST') && $request->request->has('submitTtl')) {
            $this->handleSave($request, $config, $currentValues, $shopLevel);
            return $this->redirectToRoute('admin_litespeedcache_ttl');
        }

        // Convert seconds to human-readable for display
        $ttlFields = [
            ['name' => 'ttl',          'label' => 'Default Public Cache TTL',  'value' => $currentValues['ttl'] ?? 86400,      'help' => 'How long publicly cached pages are stored. Default: 86400.', 'min' => 30],
            ['name' => 'privttl',      'label' => 'Default Private Cache TTL', 'value' => $currentValues['privttl'] ?? 1800,    'help' => 'How long private ESI blocks are stored. Default: 1800.', 'min' => 60, 'max' => 3600],
            ['name' => 'homettl',      'label' => 'Home Page TTL',             'value' => $currentValues['homettl'] ?? 86400,   'help' => 'How long the home page is cached. Default: 86400.', 'min' => 30],
            ['name' => '404ttl',       'label' => '404 Pages TTL',             'value' => $currentValues['404ttl'] ?? 86400,    'help' => 'Set to 0 to disable caching for 404 pages. Default: 86400.', 'min' => 0],
            ['name' => 'pcommentsttl', 'label' => 'Product Comments TTL',      'value' => $currentValues['pcommentsttl'] ?? 7200, 'help' => 'Set to 0 to disable caching for product comments. Default: 7200.', 'min' => 0],
        ];

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/ttl.html.twig', [
            'ttlFields' => $ttlFields,
        ], $request);
    }

    private function handleSave(Request $request, Conf $config, array $originalValues, int $shopLevel): void
    {
        $d = 'Modules.Litespeedcache.Admin';
        $fields = ['ttl', 'privttl', 'homettl', '404ttl', 'pcommentsttl'];

        $validator = new ConfigValidator();
        $currentValues = $originalValues;
        $changed = 0;
        $errors = [];

        foreach ($fields as $field) {
            [$value, $fieldErrors, $flag] = $validator->validate(
                $field,
                $request->request->get($field),
                $originalValues[$field] ?? null
            );
            if ($fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = $field . ': ' . $msg;
                }
            } else {
                $currentValues[$field] = $value;
                $changed |= $flag;
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                $this->addFlash('error', $e);
            }
            return;
        }

        if ($changed === 0) {
            $this->addFlash('info', $this->trans('No changes detected.', $d));
            return;
        }

        if ($changed & ConfigValidator::SCOPE_SHOP) {
            $config->updateConfiguration(Conf::ENTRY_SHOP, $currentValues);
        }

        $this->addFlash('success', $this->trans('TTL settings saved.', $d));
        \PrestaShopLogger::addLog('TTL settings updated', 1, null, 'LiteSpeedCache', 0, true);
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) return 'disabled';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . ' minutes';
        if ($seconds < 86400) return round($seconds / 3600, 1) . ' hours';
        if ($seconds < 604800) return round($seconds / 86400, 1) . ' days';
        return round($seconds / 604800, 1) . ' weeks';
    }
}
