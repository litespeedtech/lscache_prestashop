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

        $html = '';

        return [new \Twig\Markup($html, 'UTF-8')];
    }
}
