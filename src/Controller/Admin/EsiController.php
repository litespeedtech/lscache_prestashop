<?php


namespace LiteSpeed\Cache\Controller\Admin;

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Esi\EsiModuleConfig as EsiConf;
use LiteSpeed\Cache\Helper\CacheHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EsiController extends AbstractController
{
    use NavPillsTrait;


    private function init(): array
    {
        $config = Conf::getInstance();
        $config->get(Conf::ENTRY_MODULE); // force CacheConfig::init() before lsc_include.php registers built-ins

        include_once _PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php';
        \Hook::exec('actionLiteSpeedCacheInitThirdParty');

        $data   = $config->get(Conf::ENTRY_MODULE);
        $rows   = [];
        $defaultIds = [];

        foreach ($data as $id => $ci) {
            $row = $ci->getCustConfArray();
            $row['pubpriv']  = $row['priv'] ? 'Private' : 'Public';
            $row['isPrivate'] = (bool)$row['priv'];

            if ($row['type'] == EsiConf::TYPE_CUSTOMIZED) {
                $row['typeLabel'] = 'Customized';
                $row['editable']  = true;
            } else {
                $defaultIds[]    = $id;
                $row['editable'] = false;
                $row['typeLabel'] = ($row['type'] == EsiConf::TYPE_BUILTIN) ? 'Built-in' : 'Integrated';
            }
            $rows[$id] = $row;
        }

        return [$config, $rows, $defaultIds];
    }

    public function indexAction(Request $request): Response
    {
        if (\Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_ALL) {
            $this->addFlash('info', $this->trans('This section is only available at the global level.', 'Modules.Litespeedcache.Admin'));
        }

        $licenseOk = CacheHelper::licenseEnabled();
        if (!$licenseOk) {
            $this->addFlash('error', $this->trans('LiteSpeed Server with LSCache module is required.', 'Modules.Litespeedcache.Admin'));
        }

        [, $rows, $defaultIds] = $this->init();

        foreach ($rows as $id => $row) {
            if ($row['tipurl']) {
                $this->addFlash('warning', $row['name'] . ': <a href="' . $row['tipurl'] . '" target="_blank" rel="noopener noreferrer">See online tips</a>');
            }
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/esi/list.html.twig', [
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
                'add' => [
                    'href' => $this->generateUrl('admin_litespeedcache_esi_add'),
                    'desc' => 'Add ESI Block',
                ],
            ],
            'rows'       => $rows,
            'defaultIds' => $defaultIds,
            'editUrl'    => $this->generateUrl('admin_litespeedcache_esi_edit', ['id' => '__ID__']),
            'viewUrl'    => $this->generateUrl('admin_litespeedcache_esi_view', ['id' => '__ID__']),
            'deleteUrl'  => $this->generateUrl('admin_litespeedcache_esi_delete', ['id' => '__ID__']),
        ], $request);
    }

    public function addAction(Request $request): Response
    {
        [$config, $rows,] = $this->init();

        $values = [
            'id' => '', 'name' => '', 'priv' => 1, 'disableESI' => 0,
            'ttl' => 1800, 'tag' => '', 'events' => '', 'ctrl' => '',
            'methods' => '', 'render' => '', 'asvar' => '', 'ie' => '', 'ce' => '', 'argument' => '',
        ];

        if ($request->isMethod('POST')) {
            [$values, $errors] = $this->validateForm($request, $values);
            if (!$errors) {
                $res = $config->saveModConfigValues($values, 'new');
                if ($res) {
                    $this->addFlash('success', $this->trans('Settings saved. Please flush all cached pages.', 'Modules.Litespeedcache.Admin'));
                    return $this->redirectToRoute('admin_litespeedcache_esi');
                }
                $this->addFlash('error', $this->trans('Fail to update the settings.', 'Modules.Litespeedcache.Admin'));
            } else {
                foreach ($errors as $e) {
                    $this->addFlash('error', $e);
                }
            }
        }

        // Module options for select: active modules not already in list
        $moduleOptions = $this->getAddModuleOptions($rows);

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/esi/form.html.twig', [
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
            ],
            'values'        => $values,
            'readonly'      => false,
            'moduleOptions' => $moduleOptions,
            'backUrl'       => $this->generateUrl('admin_litespeedcache_esi'),
            'formAction'    => $this->generateUrl('admin_litespeedcache_esi_add'),
            'mode'          => 'add',
        ], $request);
    }

    public function editAction(Request $request, string $id): Response
    {
        [$config, $rows,] = $this->init();

        if (!isset($rows[$id])) {
            $this->addFlash('error', $this->trans('ESI module not found.', 'Modules.Litespeedcache.Admin'));
            return $this->redirectToRoute('admin_litespeedcache_esi');
        }

        $values = $rows[$id];

        if ($request->isMethod('POST')) {
            [$values, $errors] = $this->validateForm($request, $values);
            if (!$errors) {
                $res = $config->saveModConfigValues($values, 'edit');
                if ($res) {
                    $this->addFlash('success', $this->trans('Settings saved. Please flush all cached pages.', 'Modules.Litespeedcache.Admin'));
                    return $this->redirectToRoute('admin_litespeedcache_esi');
                }
                $this->addFlash('error', $this->trans('Fail to update the settings.', 'Modules.Litespeedcache.Admin'));
            } else {
                foreach ($errors as $e) {
                    $this->addFlash('error', $e);
                }
            }
        }

        $moduleOptions = [['id' => $id, 'name' => "[$id] " . $rows[$id]['name']]];

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/esi/form.html.twig', [
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
            ],
            'values'        => $values,
            'readonly'      => false,
            'moduleOptions' => $moduleOptions,
            'backUrl'       => $this->generateUrl('admin_litespeedcache_esi'),
            'formAction'    => $this->generateUrl('admin_litespeedcache_esi_edit', ['id' => $id]),
            'mode'          => 'edit',
        ], $request);
    }

    public function viewAction(Request $request, string $id): Response
    {
        [, $rows,] = $this->init();

        if (!isset($rows[$id])) {
            $this->addFlash('error', $this->trans('ESI module not found.', 'Modules.Litespeedcache.Admin'));
            return $this->redirectToRoute('admin_litespeedcache_esi');
        }

        $values = $rows[$id];
        $moduleOptions = [['id' => $id, 'name' => "[$id] " . $rows[$id]['name']]];

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/esi/form.html.twig', [
            'layoutHeaderToolbarBtn' => [
                'flush_pages' => [
                    'href' => $this->generateUrl('admin_litespeedcache_manage', ['purge_shops' => 1]),
                    'desc' => 'Flush Pages',
                ],
            ],
            'values'        => $values,
            'readonly'      => true,
            'moduleOptions' => $moduleOptions,
            'backUrl'       => $this->generateUrl('admin_litespeedcache_esi'),
            'formAction'    => '',
            'mode'          => 'view',
        ], $request);
    }

    public function deleteAction(Request $request, string $id): Response
    {
        [$config,,] = $this->init();

        $res = $config->saveModConfigValues(['id' => $id], 'delete');
        if ($res) {
            $this->addFlash('success', $this->trans('Settings saved. Please flush all cached pages.', 'Modules.Litespeedcache.Admin'));
        } else {
            $this->addFlash('error', $this->trans('Fail to delete the settings.', 'Modules.Litespeedcache.Admin'));
        }

        return $this->redirectToRoute('admin_litespeedcache_esi');
    }

    private function validateForm(Request $request, array $origValues): array
    {
        $inputs  = ['id', 'priv', 'ttl', 'tag', 'events', 'ctrl', 'methods', 'render', 'asvar', 'ie', 'ce', 'disableESI', 'argument'];
        $current = $origValues;
        $errors  = [];
        $split   = '/[\s,]+/';

        foreach ($inputs as $name) {
            $postVal = trim((string)$request->request->get($name, ''));

            switch ($name) {
                case 'id':
                case 'argument':
                    break;

                case 'priv':
                case 'asvar':
                case 'ie':
                case 'ce':
                case 'disableESI':
                    $postVal = (int)$postVal;
                    break;

                case 'ttl':
                    if ($postVal !== '') {
                        if (!\Validate::isUnsignedInt($postVal)) {
                            $errors[] = 'Invalid value: TTL';
                        } elseif ((int)$postVal < 60 && (int)$postVal !== 0) {
                            $errors[] = 'Invalid value: TTL — Must be greater than 60 seconds.';
                        } elseif ($current['priv'] == 1 && (int)$postVal > 7200) {
                            $errors[] = 'Invalid value: TTL — Private TTL must be less than 7200 seconds.';
                        } else {
                            $postVal = (int)$postVal;
                        }
                    }
                    break;

                case 'tag':
                    if ($postVal !== '' && preg_match('/^[a-zA-Z-_0-9]+$/', $postVal) !== 1) {
                        $errors[] = 'Invalid value: Cache Tag — Invalid characters found.';
                    }
                    break;

                case 'events':
                    $clean = array_unique(preg_split($split, $postVal, -1, PREG_SPLIT_NO_EMPTY));
                    if (!$clean) {
                        $postVal = '';
                    } else {
                        foreach ($clean as $ci) {
                            if (!preg_match('/^[a-zA-Z]+$/', $ci)) {
                                $errors[] = 'Invalid value: Purge Events — Invalid characters found.';
                            } elseif (strlen($ci) < 8) {
                                $errors[] = 'Invalid value: Purge Events — Event string usually starts with "action".';
                            }
                        }
                        $postVal = implode(', ', $clean);
                    }
                    break;

                case 'ctrl':
                    $clean = array_unique(preg_split($split, $postVal, -1, PREG_SPLIT_NO_EMPTY));
                    if (!$clean) {
                        $postVal = '';
                    } else {
                        foreach ($clean as $ci) {
                            if (!preg_match('/^([a-zA-Z_]+)(\?[a-zA-Z_0-9\-&]+)?$/', $ci)) {
                                $errors[] = 'Invalid value: Purge Controllers — Invalid characters found.';
                            }
                        }
                        $postVal = implode(', ', $clean);
                    }
                    break;

                case 'methods':
                    $clean = array_unique(preg_split($split, $postVal, -1, PREG_SPLIT_NO_EMPTY));
                    if (!$clean) {
                        $postVal = '';
                    } else {
                        foreach ($clean as $ci) {
                            if (!preg_match('/^(\!)?([a-zA-Z_0-9]+)$/', $ci)) {
                                $errors[] = 'Invalid value: Hooked Methods — Invalid characters found.';
                            }
                        }
                        $postVal = implode(', ', $clean);
                    }
                    break;

                case 'render':
                    $clean = array_unique(preg_split($split, $postVal, -1, PREG_SPLIT_NO_EMPTY));
                    if (!$clean) {
                        $postVal = '';
                    } elseif (count($clean) === 1 && $clean[0] === '*') {
                        $postVal = '*';
                    } else {
                        foreach ($clean as $ci) {
                            if (!preg_match('/^(\!)?([a-zA-Z_]+)$/', $ci)) {
                                $errors[] = 'Invalid value: Widget Render Hooks — Invalid characters found.';
                            }
                        }
                        $postVal = implode(', ', $clean);
                    }
                    break;
            }

            $current[$name] = $postVal;
        }

        return [$current, $errors];
    }

    private function getAddModuleOptions(array $existingRows): array
    {
        $list    = [];
        $modules = \Module::getModulesInstalled();
        $existing = array_keys($existingRows);

        foreach ($modules as $module) {
            if ($module['active'] == 1
                && !in_array($module['name'], $existing)
                && ($tmp = \Module::getInstanceByName($module['name']))
            ) {
                $list[$module['name']] = $tmp->displayName;
            }
        }
        natsort($list);

        $options = [];
        foreach ($list as $id => $name) {
            $options[] = ['id' => $id, 'name' => "[$id] $name"];
        }
        return $options;
    }
}
