<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheConfig as Conf;

class AdminLiteSpeedCacheManageController extends ModuleAdminController
{
    private $config;

    private $is_shop_level; // -1: not multishop, 0: multishop global, 1: multishop shop

    private $labels;

    private $current_values;

    private $license_disabled;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        parent::__construct();

        $this->config = Conf::getInstance();
        if (!$this->module->active) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules'));
        }

        $title = $this->l('LiteSpeed Cache Management');
        $this->page_header_toolbar_title = $title;
        $this->meta_title = $title;
        //// -1: not multishop, 0: multishop global, 1: multishop shop or group
        if (Shop::isFeatureActive()) {
            $this->is_shop_level = (Shop::getContext() == Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $this->is_shop_level = -1;
        }
    }

    public function init()
    {
        parent::init();
        if (!LiteSpeedCacheHelper::licenseEnabled()) {
            $this->license_disabled = true;
            $this->errors[] = $this->l('LiteSpeed Server with LSCache module is required.') . ' '
                    . $this->l('Please contact your sysadmin or your host to get a valid LiteSpeed license.');
        }

        $this->labels = [
            'home' => $this->l('Home Page'),
            '404' => $this->l('All 404 Pages'),
            'search' => $this->l('All Categories and Products Pages'),
            'brand' => $this->l('All Brands Pages'),
            'supplier' => $this->l('All Suppliers Pages'),
            'sitemap' => $this->l('Site Map'),
            'cms' => $this->l('All CMS Pages'),
            'pc' => $this->l('All Product Comments'),
            'priv' => $this->l('All Private ESI Blocks'),
            'prod0' => $this->l('Product'),
            'cat0' => $this->l('Category'),
            'brand0' => $this->l('Brand'),
            'supplier0' => $this->l('Supplier'),
            'cms0' => $this->l('CMS'),
            'pc0' => $this->l('Comments for Product ID'),
            'shop0' => $this->l('Shop'),
            'affectall' => $this->l('This will affect all shops'),
        ];
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        
        $js = '
        (function() {
            if (typeof $ !== "undefined") {
                $(document).ready(function() {
                    $("#collapse-all-id_category, #expand-all-id_category").hide();
                    $(".tree-actions").hide();
                });
            }
        })();
        ';
        $this->addJS('data:text/javascript;base64,' . base64_encode($js));
    }

    public function initPageHeaderToolbar()
    {
        if ($this->is_shop_level !== 1) {
            $this->page_header_toolbar_btn['purge_shops'] = [
                'href' => self::$currentIndex . '&purge_shops&token=' . $this->token,
                'desc' => $this->l('Flush All PrestaShop Pages'),
                'icon' => 'process-icon-delete',
            ];
            $this->page_header_toolbar_btn['purge_all'] = [
                'href' => self::$currentIndex . '&purge_all&token=' . $this->token,
                'desc' => $this->l('Flush Entire Cache Storage'),
                'icon' => 'process-icon-delete',
                'class' => 'btn-warning',
            ];
        }
        parent::initPageHeaderToolbar();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('purge_shops')) {
            $this->processPurgeShops();
        } elseif (Tools::isSubmit('purge_all')) {
            $this->processPurgeAll();
        } elseif (Tools::isSubmit('submitPurgeSelection')) {
            $this->processPurgeSelection();
        } elseif (Tools::isSubmit('submitPurgeId')) {
            $this->processPurgeIds();
        }

        return parent::postProcess();
    }

    private function processPurgeAll()
    {
        if ($this->doPurge(1, 'ALL')) {
            $this->confirmations[] = $this->l('Notified LiteSpeed Server to flush the entire cache storage.');
        }
    }

    private function processPurgeShops()
    {
        if ($this->doPurge('*')) {
            $this->confirmations[] = $this->l('Notified LiteSpeed Server to flush all pages of this PrestaShop.');
        }
    }

    private function processPurgeSelection()
    {
        $tags = [];
        $info = [];
        if (Tools::getValue('cbPurge_home')) {
            $tags[] = Conf::TAG_HOME;
            $info[] = $this->labels['home'];
        }
        if (Tools::getValue('cbPurge_404')) {
            $tags[] = Conf::TAG_404;
            $info[] = $this->labels['404'];
        }
        if (Tools::getValue('cbPurge_search')) {
            $tags[] = Conf::TAG_SEARCH;
            $tags[] = Conf::TAG_PREFIX_CATEGORY;
            $tags[] = Conf::TAG_PREFIX_PRODUCT;
            $info[] = $this->labels['search'];
        }
        if (Tools::getValue('cbPurge_brand')) {
            $tags[] = Conf::TAG_PREFIX_MANUFACTURER;
            $info[] = $this->labels['brand'];
        }
        if (Tools::getValue('cbPurge_supplier')) {
            $tags[] = Conf::TAG_PREFIX_SUPPLIER;
            $info[] = $this->labels['supplier'];
        }
        if (Tools::getValue('cbPurge_sitemap')) {
            $tags[] = Conf::TAG_SITEMAP;
            $info[] = $this->labels['sitemap'];
        }
        if (Tools::getValue('cbPurge_cms')) {
            $tags[] = Conf::TAG_PREFIX_CMS;
            $info[] = $this->labels['cms'];
        }
        if (Tools::getValue('cbPurge_pc')) {
            $tags[] = Conf::TAG_PREFIX_PCOMMENTS;
            $info[] = $this->labels['pc'];
        }
        if (Tools::getValue('cbPurge_priv')) {
            $tags[] = Conf::TAG_PREFIX_PRIVATE;
            $info[] = $this->labels['priv'];
            LiteSpeedCacheHelper::clearInternalCache();
        }
        if ($cid = Tools::getValue('rcats')) {
            $tags[] = Conf::TAG_PREFIX_CATEGORY . $cid;
            $info[] = $this->l('Category with ID') . ' ' . $cid;
        }
        if (count($tags)) {
            if ($this->doPurge($tags)) {
                $t = implode(', ', $info);
                $this->confirmations[] = $this->l('Notified LiteSpeed Server to flush cached pages:') . ' ' . $t;
            }
        } else {
            $this->warnings[] = $this->l('Nothing selected. No action taken.');
        }
    }

    private function processPurgeIds()
    {
        $by = Tools::getValue('purgeby');
        switch ($by) {
            case 'prod':
                $pre = Conf::TAG_PREFIX_PRODUCT;
                $desc = $this->labels['prod0'];
                break;
            case 'cat':
                $pre = Conf::TAG_PREFIX_CATEGORY;
                $desc = $this->labels['cat0'];
                break;
            case 'brand':
                $pre = Conf::TAG_PREFIX_MANUFACTURER;
                $desc = $this->labels['brand0'];
                break;
            case 'supplier':
                $pre = Conf::TAG_PREFIX_SUPPLIER;
                $desc = $this->labels['supplier0'];
                break;
            case 'cms':
                $pre = Conf::TAG_PREFIX_CMS;
                $desc = $this->labels['cms0'];
                break;
            case 'pc':
                $pre = Conf::TAG_PREFIX_PCOMMENTS;
                $desc = $this->labels['pc0'];
                break;
            case 'shop':
                $pre = Conf::TAG_PREFIX_SHOP;
                $desc = $this->labels['shop0'];
                break;
            default:
                $this->errors[] = $this->l('Illegal entrance');

                return;
        }

        $pattern = "/[\s,]+/";
        $id = Tools::getValue('purgeids');
        $ids = preg_split($pattern, $id, null, PREG_SPLIT_NO_EMPTY);
        $tags = [];
        $hasError = false;
        if (empty($ids)) {
            $hasError = true;
        } else {
            foreach ($ids as $i) {
                if (((string) ((int) $i) === (string) $i) && ((int) $i > 0)) { // make sure is int
                    $tags[] = $pre . $i;
                } else {
                    $hasError = true;
                    break;
                }
            }
        }
        if ($hasError) {
            $this->current_values['purgeby'] = $by;
            $this->current_values['purgeids'] = $id;
            $this->errors[] = $this->l('Please enter valid IDs');
        } else {
            if ($this->doPurge($tags)) {
                $t = $desc . ' ' . implode(', ', $ids);
                $this->confirmations[] = $this->l('Notified LiteSpeed Server to flush cached pages:') . ' ' . $t;
            }
        }
    }

    private function doPurge($tags, $key = 'public')
    {
        if (LiteSpeedCache::isActive() || $tags == '*') {
            $params = ['from' => 'AdminLiteSpeedCacheManage', $key => $tags];
            Hook::exec('litespeedCachePurge', $params);

            return true;
        }

        $this->warnings[] = $this->l('No action taken.') . ' '
                . $this->l('This Module is not enabled. Only action allowed is Flush All Prestashop Pages.');

        return false;
    }

    public function renderView()
    {
        if ($this->license_disabled) {
            $this->warnings[] = $this->l('No action taken.') . ' '
                    . $this->l('No LiteSpeed Server with LSCache available.');

            return false;
        }

        $html = $this->renderPurgeSelection();
        $html .= $this->renderPurgeId();
        
        // Add inline script to hide non-functional buttons
        $html .= '<script type="text/javascript">
            (function() {
                document.addEventListener("DOMContentLoaded", function() {
                    var collapseBtn = document.getElementById("collapse-all-id_category");
                    var expandBtn = document.getElementById("expand-all-id_category");
                    if (collapseBtn) collapseBtn.style.display = "none";
                    if (expandBtn) expandBtn.style.display = "none";
                });
                
                // Also try with jQuery if available
                if (typeof jQuery !== "undefined") {
                    jQuery(document).ready(function($) {
                        $("#collapse-all-id_category, #expand-all-id_category").hide();
                    });
                }
            })();
        </script>';

        return $html;
    }

    private function renderPurgeSelection()
    {
        $title = $this->l('Purge by Selection');
        $form = $this->newFieldForm($title, 'list-ul', $title);
        $cbPurge = [
            'type' => 'checkbox',
            'label' => $this->l('Select All Pages You Want to Purge'),
            'name' => 'cbPurge',
            'values' => [
                'query' => [
                    ['id' => 'home', 'name' => $this->labels['home']],
                    ['id' => '404', 'name' => $this->labels['404']],
                    ['id' => 'search', 'name' => $this->labels['search']],
                    ['id' => 'brand', 'name' => $this->labels['brand']],
                    ['id' => 'supplier', 'name' => $this->labels['supplier']],
                    ['id' => 'sitemap', 'name' => $this->labels['sitemap']],
                    ['id' => 'cms', 'name' => $this->labels['cms']],
                    ['id' => 'pc', 'name' => $this->labels['pc']],
                    ['id' => 'priv', 'name' => $this->labels['priv']],
                ],
                'id' => 'id', 'name' => 'name', ],
        ];
        $selCat = [
            'type' => 'categories',
            'label' => $this->l('Select Categories'),
            'name' => 'rcats',
            'tree' => [
                'root_category' => 1,
                'id' => 'id_category',
                'name' => 'name_category',
            ],
        ];
        if ($this->is_shop_level !== -1) {
            $cbPurge['hint'] = $this->labels['affectall'];
            $selCat['hint'] = $this->labels['affectall'];
        }
        $form['input'][] = $cbPurge;
        $form['input'][] = $selCat;

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->languages = $this->getLanguages();
        $helper->name_controller = $this->controller_name;
        $helper->token = $this->token;
        $helper->default_form_language = $this->default_form_language;
        $helper->allow_employee_form_lang = $this->allow_employee_form_lang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPurgeSelection';
        $helper->currentIndex = self::$currentIndex;

        return $helper->generateForm([['form' => $form]]);
    }

    private function renderPurgeId()
    {
        $title = $this->l('Purge by ID');
        $desc = $this->l('Cached pages should be automatically purged through related hooks.') . ' '
                . $this->l('This tool is mainly for testing purposes.') . ' '
                . $this->l('You need to know the exact IDs if you want to use this function.') . ' '
                . $this->l('No extra validation on the ID value.');
        $form = $this->newFieldForm($title, 'list-ol', $title, $desc);
        $query = [
            ['purgeby' => 'prod', 'name' => $this->labels['prod0']],
            ['purgeby' => 'cat', 'name' => $this->labels['cat0']],
            ['purgeby' => 'brand', 'name' => $this->labels['brand0']],
            ['purgeby' => 'supplier', 'name' => $this->labels['supplier0']],
            ['purgeby' => 'cms', 'name' => $this->labels['cms0']],
            ['purgeby' => 'pc', 'name' => $this->labels['pc0']],
            ['purgeby' => 'shop', 'name' => $this->labels['shop0']],
        ];
        $textareaIds = [
            'type' => 'textarea',
            'class' => 'input',
            'desc' => $this->l('You can enter multiple IDs by using a comma-delimited string.'),
            'label' => $this->l('Enter the IDs of the Pages You Want to Purge'),
            'name' => 'purgeids',
        ];
        if ($this->is_shop_level !== -1) {
            $query[] = ['purgeby' => 'shop', 'name' => $this->labels['shop0']];
            $textareaIds['hint'] = $this->labels['affectall'];
        }

        $form['input'][] = [
            'type' => 'select',
            'label' => $this->l('Select ID Type'),
            'name' => 'purgeby',
            'required' => false,
            'options' => ['query' => $query, 'id' => 'purgeby', 'name' => 'name'],
        ];

        $form['input'][] = $textareaIds;

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->languages = $this->getLanguages();
        $helper->name_controller = $this->controller_name;
        $helper->token = $this->token;
        $helper->default_form_language = $this->default_form_language;
        $helper->allow_employee_form_lang = $this->allow_employee_form_lang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPurgeId';
        $helper->currentIndex = self::$currentIndex;

        $cur_purgeby = isset($this->current_values['purgeby']) ? $this->current_values['purgeby'] : '';
        $cur_ids = isset($this->current_values['purgeids']) ? $this->current_values['purgeids'] : '';
        $helper->tpl_vars = [
            'fields_value' => ['purgeby' => $cur_purgeby, 'purgeids' => $cur_ids],
        ];

        return $helper->generateForm([['form' => $form]]);
    }

    private function newFieldForm($title, $icon, $submit_title, $desc = '')
    {
        $form = [
            'legend' => ['title' => $title, 'icon' => "icon-$icon"],
            'submit' => ['icon' => 'process-icon-delete', 'title' => $submit_title],
        ];
        if ($desc) {
            $form['description'] = $desc;
        }
        $form['input'] = [];

        return $form;
    }


/**
 * Shim for legacy $this->l() calls on PS 8/9.
 * Maps to Symfony translator.
 */
protected function l($string, $specific = null, $locale = null)
{
    $translator = \Context::getContext()->getTranslator();
    return $translator->trans($string, [], 'Modules.Litespeedcache.Admin', $locale);
}
}
