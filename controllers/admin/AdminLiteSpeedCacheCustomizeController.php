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
 * @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheConfig as Conf;
use LiteSpeedCacheEsiModConf as EsiConf;

class AdminLiteSpeedCacheCustomizeController extends ModuleAdminController
{
    private $config;

    private $is_shop_level; // -1: not multishop, 0: multishop global, 1: multishop shop

    private $labels;

    private $current_values;

    private $default_ids;

    private $license_disabled;

    private $module_options;

    private $changed;

    private $config_values;

    private $original_values;

    private $current_id;

    public function __construct()
    {
        $this->bootstrap = true;
        //$this->display = 'list';
        parent::__construct();

        if (!$this->module->active) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminHome'));
        }

        $this->config = Conf::getInstance();
        $title = $this->l('LiteSpeed Cache Customization');
        $this->page_header_toolbar_title = $title;
        $this->meta_title = $title;
        $this->list_id = 'esimods';
        $this->identifier = 'id';
        $this->table = 'esimod'; // no real table, faked
        //// -1: not multishop, 0: multishop global, 1: multishop shop or group
        if (Shop::isFeatureActive()) {
            $this->is_shop_level = (Shop::getContext() == Shop::CONTEXT_ALL) ? 0 : 1;
        } else {
            $this->is_shop_level = -1;
        }
    }

    public function init()
    {
        parent::init(); // parent init must be at beginning

        if (!LiteSpeedCacheHelper::licenseEnabled()) {
            $this->license_disabled = true;
            $this->errors[] = $this->l('LiteSpeed Server with LSCache module is required.') . ' '
                    . $this->l('Please contact your sysadmin or your host to get a valid LiteSpeed license.');
        }
        include_once _PS_MODULE_DIR_ . 'litespeedcache/thirdparty/lsc_include.php';

        $this->initDisplayValues();
        $this->labels = [
            'id' => $this->l('Module'),
            'name' => $this->l('Name'),
            'pubpriv' => $this->l('Cache'),
            'priv' => $this->l('Is Private'),
            'ttl' => $this->l('TTL'),
            'tag' => $this->l('Cache Tag'),
            'type' => $this->l('Type'),
            'events' => $this->l('Purge Events'),
            'ctrl' => $this->l('Purge Controllers'),
            'methods' => $this->l('Hooked Methods'),
            'render' => $this->l('Widget Render Hooks'),
            'asvar' => $this->l('As Variable'),
            'ie' => $this->l('Ignore If Empty'),
            'ce' => $this->l('Only Cache When Empty'),
        ];
    }

    public function initPageHeaderToolbar()
    {
        if ($this->is_shop_level !== 1) {
            if ($this->display == 'list') {
                $this->page_header_toolbar_btn['new_esi'] = [
                    'href' => self::$currentIndex . '&addesimod&token=' . $this->token,
                    'desc' => $this->l('Add New ESI Block'),
                    'icon' => 'process-icon-new',
                ];
            } else {
                $this->page_header_toolbar_btn['goback'] = [
                    'href' => self::$currentIndex . '&token=' . $this->token,
                    'desc' => $this->l('Back to List'),
                    'icon' => 'process-icon-back',
                ];
            }
        }
        parent::initPageHeaderToolbar();
    }

    private function initDisplayValues()
    {
        $data = $this->config->get(Conf::ENTRY_MODULE);
        $this->config_values = [];
        $this->default_ids = [];

        foreach ($data as $id => $ci) {
            $idata = $ci->getCustConfArray();
            if ($idata['priv']) {
                $idata['pubpriv'] = $this->l('Private');
                $idata['badge_success'] = true;
            } else {
                $idata['pubpriv'] = $this->l('Public');
                $idata['badge_danger'] = true;
            }
            if ($idata['type'] == EsiConf::TYPE_CUSTOMIZED) {
                $idata['typeD'] = $this->l('Customized');
            } else {
                $this->default_ids[] = $id;
                $idata['badge_warning'] = 1; // no edits allowed
                $idata['typeD'] = ($idata['type'] == EsiConf::TYPE_BUILTIN) ?
                        $this->l('Built-in') : $this->l('Integrated');
            }
            if ($idata['tipurl']) {
                $this->warnings[] = $idata['name'] . ': <a href="' . $idata['tipurl']
                    . '" target="_blank" rel="noopener noreferrer">' . $this->l('See online tips') . '</a>';
                $idata['name'] .= ' (*)';
            }
            $this->config_values[$id] = $idata;
        }

        if ($this->display == 'edit' || $this->display == 'view') {
            $name = $this->current_id;
            $this->original_values = $this->config_values[$name];
        } elseif ($this->display == 'add') {
            $this->original_values = [
                'id' => '',
                'name' => '',
                'priv' => 1,
                'ttl' => 1800,
                'tag' => '',
                'events' => '',
                'ctrl' => '',
                'methods' => '',
                'render' => '',
                'asvar' => '',
                'ie' => '',
                'ce' => '',
            ];
        } else { // list
            $this->original_values = $this->config_values;
        }
        if ($this->display != 'list') {
            $this->getModuleOptions();
        }
        $this->current_values = $this->original_values;
    }

    /**
     * Retrieve GET and POST value and translate them to actions.
     */
    public function initProcess()
    {
        $t = $this->table;
        $this->current_id = Tools::getIsset($this->identifier) ? Tools::getValue($this->identifier) : null;
        $this->display = 'list'; // default

        if (Tools::getIsset('add' . $t)) {
            if ($this->canDo('add')) {
                $this->action = 'new';
                $this->display = 'add';
            } else {
                $this->errors[] = $this->l('You do not have permission to add this.');
            }
        } elseif (Tools::getIsset('update' . $t) && $this->current_id) {
            if ($this->canDo('edit')) {
                $this->action = 'edit';
                $this->display = 'edit';
            } else {
                $this->errors[] = $this->l('You do not have permission to edit this.');
            }
        } elseif (Tools::getIsset('delete' . $t) && $this->current_id) {
            // Delete object
            if ($this->canDo('delete')) {
                $this->action = 'delete';
            } else {
                $this->errors[] = $this->l('You do not have permission to delete this.');
            }
        } elseif (Tools::getIsset('view' . $t) && $this->current_id) {
            $this->display = 'view';
            $this->action = 'view';
        }
    }

    protected function canDo($action)
    {
        if (method_exists($this, 'access')) { // 1.7 +
            return $this->access($action);
        } else {
            return $this->tabAccess[$action] === '1';
        }
    }

    public function initContent()
    {
//        if (!$this->viewAccess()) {
//            $this->errors[] = $this->l('You do not have permission to view this.');
//            return;
//        }
//

        parent::initContent();
        if ($this->is_shop_level == 1) {
            $this->informations[] = $this->l('This section is only available at the global level.');

            return;
        }

        if ($this->display == 'edit' || $this->display == 'add' || $this->display == 'view') {
            $this->content = $this->renderForm();
        } elseif ($this->display == 'list') {
            $s = ' ';
            $this->informations[] = $this->l('You can make an ESI block for a widget, also known as Hole-Punching.') . $s
                . $this->l('Built-in and integrated modules cannot be changed.') . $s
                . $this->l('These are advanced settings for third-party modules.') . $s
                . '<a href="https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscps" '
                . 'target="_blank" rel="noopener noreferrer">'
                . $this->l('Wiki Help') . '</a>';
            $this->content = $this->renderList();
        }

        $this->context->smarty->assign([
            'content' => $this->content,
        ]);
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitConfig')) {
            $this->processFormSave();
        } elseif ($this->action == 'delete') {
            $this->saveModConfig(['id' => $this->current_id]);
        } else {
            parent::postProcess();
        }
    }

    private function validateInput($name)
    {
        //'id', 'priv', 'ttl', 'tag', 'events'
        $postVal = trim(Tools::getValue($name));
        $origVal = $this->original_values[$name];
        $invalid = $this->l('Invalid value') . ': ' . $this->labels[$name];
        $s = ' - '; // spacer
        $invalidChars = $this->l('Invalid characters found.');
        $splitPattern = '/[\s,]+/';

        switch ($name) {
            case 'id':
                break;

            case 'priv':
            case 'asvar':
            case 'ie':
            case 'ce':
                $postVal = (int) $postVal;
                break;

            case 'ttl':
                if ($postVal === '') {
                    // ok, will use default value
                } elseif (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } elseif ($postVal < 60) {
                    $this->errors[] = $invalid . $s . $this->l('Must be greater than 60 seconds.');
                } elseif ($this->current_values['priv'] == 1 && $postVal > 7200) {
                    $this->errors[] = $invalid . $s . $this->l('Private TTL must be less than 7200 seconds.');
                } else {
                    $postVal = (int) $postVal;
                }
                break;

            case 'tag':
                if ($postVal === '') {
                    // ok, will use default value
                } elseif (preg_match('/^[a-zA-Z-_0-9]+$/', $postVal) !== 1) {
                    $this->errors[] = $invalid . $s . $invalidChars;
                }
                break;

            case 'events':
                $clean = array_unique(preg_split($splitPattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $ci) {
                        if (!preg_match('/^[a-zA-Z]+$/', $ci)) {
                            $this->errors[] = $invalid . $s . $invalidChars;
                        } elseif (Tools::strlen($ci) < 8) {
                            $this->errors[] = $invalid . $s . $this->l('Event string usually starts with "action".');
                        }
                    }
                    $postVal = implode(', ', $clean);
                }
                break;

            case 'ctrl':
                $clean = array_unique(preg_split($splitPattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $ci) {
                        // allow ClassName?param1&param2
                        if (!preg_match('/^([a-zA-Z_]+)(\?[a-zA-Z_0-9\-&]+)?$/', $ci, $m)) {
                            $this->errors[] = $invalid . $s . $invalidChars;
                        } /*elseif (!class_exists($m[1])) {
                            $this->errors[] = $invalid . $s . ' ' . $m[1] . ' ' . $this->l('Invalid class name.');
                        }*/
                    }
                    $postVal = implode(', ', $clean);
                }
                break;

            case 'methods':
                $clean = array_unique(preg_split($splitPattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $ci) {
                        if (!preg_match('/^(\!)?([a-zA-Z_]+)$/', $ci, $m)) {
                            $this->errors[] = $invalid . $s . $invalidChars;
                        } else {
                            // no further validation for now
                        }
                    }
                    $postVal = implode(', ', $clean);
                }
                break;

            case 'render':
                $clean = array_unique(preg_split($splitPattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } elseif (count($clean) == 1 && $clean[0] == '*') {
                    $postVal = '*'; // allow * for all
                } else {
                    foreach ($clean as $ci) {
                        if (!preg_match('/^(\!)?([a-zA-Z_]+)$/', $ci, $m)) {
                            $this->errors[] = $invalid . $s . $invalidChars;
                        } else {
                            // no further validation for now
                        }
                    }
                    $postVal = implode(', ', $clean);
                }
                break;
        }

        if ($postVal != $origVal) {
            $this->changed |= 1;
        }

        $this->current_values[$name] = $postVal;
    }

    private function processFormSave()
    {
        $inputs = ['id', 'priv', 'ttl', 'tag', 'events', 'ctrl', 'methods', 'render', 'asvar', 'ie', 'ce'];
        $this->changed = 0;
        foreach ($inputs as $field) {
            $this->validateInput($field);
        }
        if (count($this->errors)) {
            return;
        }

        if ($this->changed == 0) {
            $this->confirmations[] = $this->l('No changes detected. Nothing to save.');

            return;
        }
        $this->saveModConfig($this->current_values);
    }

    private function saveModConfig($values)
    {
        $res = $this->config->saveModConfigValues($values, $this->action);
        if ($res && ($this->display == 'add')) { // successfully added
            $this->display = 'list';
        }
        $this->initDisplayValues();
        if ($res == 1) {
            $this->confirmations[] = $this->l('Settings saved.') . ' '
                . $this->l('Please flush all cached pages.');
        } elseif ($res == 2) {
            $this->confirmations[] = $this->l('Settings saved and hooks updated') . ' '
                . $this->l('Please flush all cached pages.');
        } else {
            $this->errors[] = $this->l('Fail to update the settings.');
        }
    }

    private function getModuleOptions()
    {
        $moduleOptions = [];
        $is17 = version_compare(_PS_VERSION_, '1.7.0.0', '>=');
        if ($this->display == 'edit' || $this->display == 'view') {
            $name = $this->current_id;
            $moduleOptions[] = [
                'id' => $name,
                'name' => "[$name] " . $this->config_values[$name]['name'],
            ];
        } elseif ($this->display == 'add') {
            $list = [];
            $modules = Module::getModulesInstalled();
            $existing = array_keys($this->config_values);
            foreach ($modules as $module) {
                if (($module['active'] == 1)
                    && (!in_array($module['name'], $existing))
                    && ($tmp_instance = Module::getInstanceByName($module['name']))
                    /*&& (!$is17
                            || ($tmp_instance instanceof PrestaShop\PrestaShop\Core\Module\WidgetInterface))*/) {
                    $list[$module['name']] = $tmp_instance->displayName;
                }
            }
            natsort($list);
            foreach ($list as $id => $name) {
                $name = "[$id] $name";
                $moduleOptions[] = ['id' => $id, 'name' => $name];
            }
        }

        $this->module_options = $moduleOptions;
    }

    public function renderForm()
    {
        $s = ' ';
        // for new & edit & view
        $disabled = ($this->display == 'view');
        $input = [
            [
                'type' => 'select',
                'label' => $this->labels['id'],
                'name' => 'id',
                'hint' => $this->l('This will only be effective if this widget is showing on a cacheable page.'),
                'options' => ['query' => $this->module_options, 'id' => 'id', 'name' => 'name'],
                'desc' => $this->l('Please select a front-end widget module only.'),
            ],
            [
                'type' => 'switch',
                'label' => $this->labels['priv'],
                'desc' => $this->l('A public block will only have one cached copy which is shared by everyone.')
                . $s . $this->l('A private block will be cached individually for each user.'),
                'name' => 'priv',
                'disabled' => $disabled,
                'is_bool' => true,
                'values' => [['value' => 1], ['value' => 0]],
            ],
            [
                'type' => 'text',
                'label' => $this->labels['ttl'],
                'name' => 'ttl',
                'readonly' => $disabled,
                'desc' => $this->l('Leave this blank if you want to use the default setting.'),
                'suffix' => $this->l('seconds'),
            ],
            [
                'type' => 'text',
                'label' => $this->labels['tag'],
                'name' => 'tag',
                'readonly' => $disabled,
                'desc' => $this->l('Only allow one tag per module.') . $s
                . $this->l('Same tag can be used for multiple modules.') . $s
                . $this->l('Leave blank to use the module name as the default value.'),
            ],
            [
                'type' => 'textarea',
                'label' => $this->labels['events'],
                'name' => 'events',
                'hint' => $this->l('No need to add login/logout events.') . $s
                . $this->l('Those are included by default for all private blocks.'),
                'readonly' => $disabled,
                'desc' => $this->l('You can automatically purge the cached ESI blocks by events.') . $s .
                $this->l('Specify a comma-delimited list of events.'),
            ],
            [
                'type' => 'textarea',
                'label' => $this->labels['ctrl'],
                'name' => 'ctrl',
                'hint' => $this->l('For example, cart block is set to be purged by this setting:')
                . $s . '"CartController:id_product"',
                'readonly' => $disabled, // allow ClassName?param1&param2
                'desc' => $this->l('You can automatically purge the cached ESI blocks by dispatched controllers.')
                . $s . $this->l('Specify a comma-delimited list of controller class names.') . $s
                . $this->l('If you add "?param" after the name, purge will be triggered only if that param is set.')
                . $s . $this->l('You can add multiple parameters, like "className?param1&param2".'),
            ],
            [
                'type' => 'textarea',
                'label' => $this->labels['methods'],
                'name' => 'methods',
                'hint' => $this->l('Instead of listing all possible ones, you can simply define an exlusion list.'),
                'readonly' => $disabled,
                'desc' => $this->l('Hooked methods that will trigger ESI injection.') . $s
                . $this->l('Specify a comma-delimited list of methods (prefix with "!" to exclude one).') . $s
                . $this->l('Leave blank to disable injection on CallHook method.'),
            ],
            [
                'type' => 'textarea',
                'label' => $this->labels['render'],
                'name' => 'render',
                'hint' => $this->l('This is only available for PS1.7.'),
                'readonly' => $disabled,
                'desc' => $this->l('You can further tune ESI injection for widget rendering by invoking hooks.')
                . '<br> ' . $this->l('Specify a comma-delimited list of allowed hooks;')
                . $s . $this->l('Or a list of not-allowed hooks by prefixing with "!".')
                . $s . $this->l('Use "*" for all hooks allowed; leave blank to disable renderWidget injection.'),
            ],
            [
                'type' => 'switch',
                'label' => $this->labels['asvar'],
                'desc' => $this->l('Enable if the rendered content is used as a variable, such as a token,')
                . $s . $this->l('or if it is small enough (e.g. less than 256 bytes).'),
                'name' => 'asvar',
                'disabled' => $disabled,
                'is_bool' => true,
                'values' => [['value' => 1], ['value' => 0]],
            ],
            [
                'type' => 'switch',
                'label' => $this->labels['ie'],
                'desc' => $this->l('Enable to avoid punching a hole for an ESI block whose rendered content is empty.'),
                'name' => 'ie',
                'hint' => $this->l('No need to hole-punch if the overridden template intentionally blank it out.'),
                'disabled' => $disabled,
                'is_bool' => true,
                'values' => [['value' => 1], ['value' => 0]],
            ],
            [
                'type' => 'switch',
                'label' => $this->labels['ce'],
                'desc' => $this->l('Enable to selectively cache this ESI block only when it contains no content.') . ' '
                    . $this->l('Non-empty blocks will not be cached. Can be used for popup notices or message blocks.'),
                'name' => 'ce',
                'disabled' => $disabled,
                'is_bool' => true,
                'values' => [['value' => 1], ['value' => 0]],
            ],
        ];

        $form = [
            'legend' => [
                'title' => $this->l('Convert Widget to ESI Block'),
                'icon' => 'icon-cogs',
            ],
            'description' => $this->l('You can hole punch a widget as an ESI block.') . $s
                . $this->l('Each ESI block can have its own TTL and purge events.') . $s
                . $this->l('For more complicated cases, a third-party integration class is required.') . $s
                . $this->l('This requires a deep understanding of the internals of Prestashop.') . $s
                . $this->l('If you need help, you can order Support service from LiteSpeed Tech.'),
            'input' => $input,
        ];
        if (!$disabled) {
            $form['submit'] = ['title' => $this->l('Save')];
        }

        $forms = [['form' => $form]];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->languages = $this->getLanguages();
        $helper->name_controller = $this->controller_name;
        $helper->token = $this->token;
        $helper->default_form_language = $this->default_form_language;
        $helper->allow_employee_form_lang = $this->allow_employee_form_lang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitConfig';
        if ($this->display == 'add') {
            $helper->currentIndex = self::$currentIndex . '&addesimod';
        } else {
            $helper->currentIndex = self::$currentIndex . '&updateesimod&id=' . $this->original_values['id'];
        }

        $helper->tpl_vars = ['fields_value' => $this->current_values];

        return $helper->generateForm($forms);
    }

    public function renderList()
    {
        $this->fields_list = [
            'id' => ['title' => $this->labels['id'], 'width' => 'auto'],
            'name' => ['title' => $this->labels['name'], 'width' => 'auto'],
            'pubpriv' => ['title' => $this->labels['pubpriv'], 'width' => '25', 'align' => 'center',
                'badge_success' => true, 'badge_danger' => true, ],
            'ttl' => ['title' => $this->labels['ttl'], 'align' => 'center', 'class' => 'fixed-width-sm'],
            'tag' => ['title' => $this->labels['tag'], 'align' => 'center', 'class' => 'fixed-width-sm'],
            'typeD' => ['title' => $this->labels['type'], 'align' => 'center', 'badge_warning' => true],
        ];

        $this->_list = $this->config_values;

        $this->actions[] = 'view';
        $this->actions[] = 'edit';
        $this->actions[] = 'delete';

        $this->list_skip_actions['edit'] = $this->default_ids;
        $this->list_skip_actions['delete'] = $this->default_ids;
        // populate _list
        $helper = new HelperList();

        $this->setHelperDisplay($helper);
        $helper->simple_header = true;
        $helper->tpl_vars = $this->getTemplateListVars();
        $helper->tpl_delete_link_vars = $this->tpl_delete_link_vars;

        $helper->languages = $this->getLanguages();
        $helper->name_controller = $this->controller_name;
        $helper->token = $this->token;
        $helper->default_form_language = $this->default_form_language;
        $helper->allow_employee_form_lang = $this->allow_employee_form_lang;
        $helper->identifier = $this->identifier;

        $list = $helper->generateList($this->_list, $this->fields_list);

        return $list;
    }
}
