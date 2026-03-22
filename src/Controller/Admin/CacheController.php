<?php


namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Admin\ConfigValidator;
use LiteSpeed\Cache\Helper\CacheHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheController extends AbstractController
{
    use NavPillsTrait;

    public function redirectAction(): Response
    {
        return $this->redirectToRoute('admin_litespeedcache_cache_settings');
    }

    public function indexAction(Request $request): Response
    {
        $config = Conf::getInstance();

        if (\Shop::isFeatureActive()) {
            $shopLevel = (\Shop::getContext() === \Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $shopLevel = -1;
        }

        $disabled = ($shopLevel === 1);
        $currentValues = $config->getAllConfigValues();

        if ($request->isMethod('POST') && $request->request->has('submitCacheConfig')) {
            $this->handleSave($request, $config, $currentValues, $shopLevel);
            return $this->redirectToRoute('admin_litespeedcache_cache_settings');
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/cache.html.twig', [
            'values'   => $currentValues,
            'disabled' => $disabled,
        ], $request);
    }

    private function handleSave(Request $request, Conf $config, array $originalValues, int $shopLevel): void
    {
        $d = 'Modules.Litespeedcache.Admin';

        // Shop-level fields
        $shopFields = ['diff_customergroup'];

        // Global fields
        $globalFields = [
            'enable', 'diff_mobile', 'guestmode', 'nocache_vars', 'nocache_urls', 'vary_bypass',
            'flush_prodcat', 'flush_all', 'flush_home', 'flush_homeinput',
        ];

        $fields = $shopFields;
        if ($shopLevel !== 1) {
            $fields = array_merge($fields, $globalFields);
        }

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
                    $errors[] = 'Invalid value: ' . $field . ' — ' . $msg;
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

        // Persist
        if ($changed & ConfigValidator::SCOPE_SHOP) {
            $config->updateConfiguration(Conf::ENTRY_SHOP, $currentValues);
        }
        if ($changed & ConfigValidator::SCOPE_ALL) {
            $config->updateConfiguration(Conf::ENTRY_ALL, $currentValues);
        }

        // .htaccess update
        if ($changed & ConfigValidator::HTACCESS) {
            $guest = ($currentValues['guestmode'] == 1);
            $mobile = $currentValues['diff_mobile'];
            if (CacheHelper::htAccessUpdate($currentValues['enable'], $guest, $mobile)) {
                $this->addFlash('success', $this->trans('.htaccess file updated.', $d));
            } else {
                $this->addFlash('warning', $this->trans('Failed to update .htaccess. Please update manually.', $d));
            }
        }

        // Purge if needed
        if (!$currentValues['enable'] && $originalValues['enable']) {
            // Cache was disabled — purge everything
            \Hook::exec('litespeedCachePurge', ['from' => 'CacheController', 'public' => '*']);
            if (!headers_sent()) {
                header('X-LiteSpeed-Purge: *');
            }
            $this->addFlash('success', $this->trans('Cache disabled. All cached entries purged.', $d));
        } elseif ($changed & ConfigValidator::PURGE_MUST) {
            $this->addFlash('warning', $this->trans('You must flush all pages to make this change effective.', $d));
        }

        $this->addFlash('success', $this->trans('Settings saved.', $d));
        \PrestaShopLogger::addLog('Cache settings updated', 1, null, 'LiteSpeedCache', 0, true);
    }
}
