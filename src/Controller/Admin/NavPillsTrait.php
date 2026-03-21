<?php
namespace LiteSpeed\Cache\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

trait NavPillsTrait
{
    protected function renderWithNavPills(string $template, array $params, Request $request): Response
    {
        $currentRoute = $request->attributes->get('_route', '');

        if (!isset($params['layoutTitle'])) {
            $params['layoutTitle'] = $this->getTabTitle($currentRoute);
        }

        $params['headerTabContent'] = $this->buildHeaderTabContent($currentRoute);
        $params['lscBreadcrumb'] = $this->buildBreadcrumb($currentRoute);

        return $this->render($template, $params);
    }

    protected function getTabTitle(string $currentRoute): string
    {
        $idLang = (int) \Context::getContext()->language->id;
        $parentId = (int) \Tab::getIdFromClassName('AdminLiteSpeedCache');
        if (!$parentId) {
            return 'LiteSpeed Cache';
        }

        $parent = new \Tab($parentId, $idLang);
        $prefix = $parent->name ?: 'LiteSpeed Cache';

        $tabs = \Tab::getTabs($idLang, $parentId);
        foreach ($tabs as $tab) {
            if (!empty($tab['route_name']) && $tab['route_name'] === $currentRoute) {
                return $prefix . ' - ' . $tab['name'];
            }
            $children = \Tab::getTabs($idLang, (int) $tab['id_tab']);
            foreach ($children as $child) {
                if (!empty($child['route_name']) && $child['route_name'] === $currentRoute) {
                    return $prefix . ' - ' . $child['name'];
                }
            }
        }

        return $prefix;
    }

    private function buildBreadcrumb(string $currentRoute): ?array
    {
        $idLang = (int) \Context::getContext()->language->id;
        $link = \Context::getContext()->link;
        $parentId = (int) \Tab::getIdFromClassName('AdminLiteSpeedCache');
        if (!$parentId) {
            return null;
        }

        $tabs = \Tab::getTabs($idLang, $parentId);
        foreach ($tabs as $tab) {
            $children = \Tab::getTabs($idLang, (int) $tab['id_tab']);
            foreach ($children as $child) {
                if (!empty($child['route_name']) && $child['route_name'] === $currentRoute) {
                    return [
                        ['name' => 'LiteSpeed Cache'],
                        ['name' => $tab['name'], 'href' => $link->getTabLink($tab)],
                        ['name' => $child['name']],
                    ];
                }
            }
        }

        return null;
    }

    private function buildHeaderTabContent(string $currentRoute): array
    {
        $idLang = (int) \Context::getContext()->language->id;
        $link = \Context::getContext()->link;

        $parentId = (int) \Tab::getIdFromClassName('AdminLiteSpeedCache');
        if (!$parentId) {
            return [];
        }

        $tabs = \Tab::getTabs($idLang, $parentId);
        if (empty($tabs)) {
            return [];
        }

        $html = '<ul class="nav nav-pills">';

        foreach ($tabs as $tab) {
            if (!$tab['active']) {
                continue;
            }

            $href = htmlspecialchars($link->getTabLink($tab));
            $isActive = (!empty($tab['route_name']) && $tab['route_name'] === $currentRoute);

            $children = \Tab::getTabs($idLang, (int) $tab['id_tab']);
            $activeChildren = array_filter($children, function ($c) { return $c['active']; });

            if (!empty($activeChildren)) {
                $isChildActive = false;
                foreach ($activeChildren as $child) {
                    if (!empty($child['route_name']) && $child['route_name'] === $currentRoute) {
                        $isChildActive = true;
                        break;
                    }
                }

                $active = $isChildActive ? ' active current' : '';
                $html .= '<li class="nav-item dropdown">';
                $html .= '<a class="nav-link tab dropdown-toggle' . $active . '" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">' . htmlspecialchars($tab['name']) . '</a>';
                $html .= '<div class="dropdown-menu">';
                foreach ($activeChildren as $child) {
                    $childHref = htmlspecialchars($link->getTabLink($child));
                    $childActive = (!empty($child['route_name']) && $child['route_name'] === $currentRoute) ? ' active' : '';
                    $html .= '<a class="dropdown-item' . $childActive . '" href="' . $childHref . '">' . htmlspecialchars($child['name']) . '</a>';
                }
                $html .= '</div></li>';
            } else {
                $active = $isActive ? ' active current' : '';
                $html .= '<li class="nav-item">';
                $html .= '<a class="nav-link tab' . $active . '" href="' . $href . '">' . htmlspecialchars($tab['name']) . '</a>';
                $html .= '</li>';
            }
        }

        $html .= '</ul>';

        // Make native PS sub-tabs (second #head_tabs) semi-transparent
        $html .= '<style>.page-head-tabs#head_tabs ~ .page-head-tabs#head_tabs { background-color: #f7f7f7; }</style>';


        // Wrap in \Twig\Markup so PS9 Twig won't escape the HTML
        // PS8 Smarty calls __toString() transparently
        return [new \Twig\Markup($html, 'UTF-8')];
    }
}
