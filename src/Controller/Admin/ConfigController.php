<?php
namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Admin\ConfigValidator;
use LiteSpeed\Cache\Helper\CacheHelper;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigController extends FrameworkBundleAdminController
{
    use NavPillsTrait;

    const BMC_SHOP         = ConfigValidator::SCOPE_SHOP;
    const BMC_ALL          = ConfigValidator::SCOPE_ALL;
    const BMC_NONEED_PURGE = ConfigValidator::PURGE_NONE;
    const BMC_MAY_PURGE    = ConfigValidator::PURGE_MAY;
    const BMC_MUST_PURGE   = ConfigValidator::PURGE_MUST;
    const BMC_DONE_PURGE   = ConfigValidator::PURGE_DONE;
    const BMC_HTACCESS     = ConfigValidator::HTACCESS;

    public function indexAction(Request $request): Response
    {
        $config = Conf::getInstance();

        if (\Shop::isFeatureActive()) {
            $shopLevel = (\Shop::getContext() === \Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $shopLevel = -1;
        }

        $disabled = ($shopLevel === 1);

        $licenseOk = CacheHelper::licenseEnabled();
        if (!$licenseOk) {
            $this->addFlash('error', $this->trans('LiteSpeed Server with LSCache module is required. Please contact your sysadmin or your host to get a valid LiteSpeed license.', 'Modules.Litespeedcache.Admin'));
        }

        $originalValues = $config->getAllConfigValues();
        $originalValues['esi'] = (isset($_SERVER['X-LSCACHE']) && strpos($_SERVER['X-LSCACHE'], 'esi') !== false) ? 1 : 0;
        $currentValues = $originalValues;
        $changed = 0;
        $errors = [];

        if ($request->isMethod('POST') && $request->request->has('submitConfig')) {
            [$currentValues, $changed, $errors] = $this->handleConfigSave($request, $config, $originalValues, $shopLevel);
            if ($errors) {
                foreach ($errors as $e) {
                    $this->addFlash('error', $e);
                }
            } elseif ($changed === 0) {
                $this->addFlash('info', $this->trans('No changes detected. Nothing to save.', 'Modules.Litespeedcache.Admin'));
            } else {
                $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Litespeedcache.Admin'));
                \PrestaShopLogger::addLog('LiteSpeed Cache configuration updated', 1, null, 'LiteSpeedCache', 0, true);
                if ($changed & self::BMC_DONE_PURGE) {
                    $this->execPurge('*');
                    // Send purge header directly — the normal output buffer
                    // callback may not run during an admin POST redirect.
                    if (!headers_sent()) {
                        header('X-LiteSpeed-Purge: *');
                    }
                    $this->addFlash('success', $this->trans('Disabled LiteSpeed Cache.', 'Modules.Litespeedcache.Admin'));
                } elseif ($changed & self::BMC_MUST_PURGE) {
                    $this->addFlash('warning', $this->trans('You must flush all pages to make this change effective.', 'Modules.Litespeedcache.Admin'));
                } elseif ($changed & self::BMC_MAY_PURGE) {
                    $this->addFlash('warning', $this->trans('You may want to purge related contents to make this change effective.', 'Modules.Litespeedcache.Admin'));
                } elseif ($changed & self::BMC_NONEED_PURGE) {
                    $this->addFlash('info', $this->trans('Changes will be effective immediately. No need to purge.', 'Modules.Litespeedcache.Admin'));
                }
                if ($changed & self::BMC_HTACCESS) {
                    $guest  = ($currentValues['guestmode'] == 1);
                    $mobile = $currentValues['diff_mobile'];
                    if (CacheHelper::htAccessUpdate($currentValues['enable'], $guest, $mobile)) {
                        $this->addFlash('success', $this->trans('.htaccess file updated accordingly.', 'Modules.Litespeedcache.Admin'));
                    } else {
                        $this->addFlash('warning', $this->trans('Failed to update .htaccess due to permission. Please manually update: https://docs.litespeedtech.com/lscache/lscps/installation/#htaccess-update', 'Modules.Litespeedcache.Admin'));
                    }
                }
            }
        } elseif ($request->query->has('purge_shops')) {
            $this->execPurge('*');
            $this->addFlash('success', $this->trans('Notified LiteSpeed Server to flush all pages of this PrestaShop.', 'Modules.Litespeedcache.Admin'));
            return $this->redirectToRoute('admin_litespeedcache_config');
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/config.html.twig', [
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
            ],
            'values'    => $currentValues,
            'disabled'  => $disabled,
            'shopLevel' => $shopLevel,
            'licenseOk' => $licenseOk,
        ], $request);
    }

    private function handleConfigSave(Request $request, Conf $config, array $originalValues, int $shopLevel): array
    {
        $fields = [
            'ttl', 'privttl', 'homettl', '404ttl', 'pcommentsttl', 'diff_customergroup',
        ];
        if ($shopLevel !== 1) {
            $fields = array_merge($fields, [
                'enable', 'diff_mobile', 'guestmode', 'nocache_vars', 'nocache_urls', 'vary_bypass',
                'flush_prodcat', 'flush_all', 'flush_home', 'flush_homeinput',
            ]);
        }

        $validator     = new ConfigValidator();
        $currentValues = $originalValues;
        $changed       = 0;
        $errors        = [];

        foreach ($fields as $field) {
            [$value, $fieldErrors, $flag] = $validator->validate(
                $field,
                $request->request->get($field),
                $originalValues[$field]
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

        if (!$errors && $changed) {
            $guest  = ($currentValues['guestmode'] == 1);
            $mobile = $currentValues['diff_mobile'];
            if ($guest && ($originalValues['diff_mobile'] != $mobile)) {
                $changed |= self::BMC_HTACCESS;
            }
            if ($changed & self::BMC_SHOP) {
                $config->updateConfiguration(Conf::ENTRY_SHOP, $currentValues);
            }
            if ($changed & self::BMC_ALL) {
                $config->updateConfiguration(Conf::ENTRY_ALL, $currentValues);
            }
        }

        return [$currentValues, $changed, $errors];
    }

    private function execPurge(string $tags): void
    {
        \Hook::exec('litespeedCachePurge', ['from' => 'AdminLiteSpeedCacheConfig', 'public' => $tags]);
    }
}
