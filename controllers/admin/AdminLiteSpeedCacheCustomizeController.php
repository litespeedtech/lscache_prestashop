<?php
/**
 * LiteSpeed Cache for Prestashop
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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheDebugLog as DebugLog;
use LiteSpeedCacheConfig as Conf;

class AdminLiteSpeedCacheCustomizeController extends ModuleAdminController
{
    private $config;
    private $is_shop_level; // -1: not multishop, 0: multishop global, 1: multishop shop
    private $labels;
    private $current_values;
    private $default;
    private $license_disabled;
    private $module_options;
    private $changed;
    private $config_values;
    private $original_values;
    private $current_id;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->config = Conf::getInstance();
        if (!$this->module->active) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminHome'));
        }

        $this->page_header_toolbar_title = $this->l('LiteSpeed Cache Settings');
        $this->meta_title = $this->l('LiteSpeed Cache Customization');
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

        $this->default = $this->config->getDefaultConfData(Conf::ENTRY_MODULE);
        $this->initDisplayValues();
        $this->labels = array(
            'id' => $this->l('Module'),
            'priv' => $this->l('Is Private'),
            'ttl' => $this->l('TTL'),
            'tag' => $this->l('Cache Tag'),
            'events' => $this->l('Purge Events'),
        );
    }

    private function initDisplayValues()
    {
        $this->config_values = $this->config->getModConfigValues();
        if ($this->display == 'edit' || $this->display == 'view') {
            $name = $this->current_id;
            $this->original_values = $this->config_values[$name];
        } elseif ($this->display == 'add') {
            $this->original_values = array(
                'id' => '',
                'priv' => 1,
                'ttl' => 1800,
                'tag' => '',
                'events' => '',
            );
        } else { // list
            $this->original_values = $this->config_values;
        }
        if ($this->display != 'list') {
            $this->getModuleOptions();
        }
        $this->current_values = $this->original_values;
    }

    /**
     * Retrieve GET and POST value and translate them to actions
     */
    public function initProcess()
    {
        $t = $this->table;
        $this->current_id = Tools::getIsset($this->identifier) ? Tools::getValue($this->identifier) : null;
        $this->display = 'list'; // default

        if (Tools::getIsset('add' . $t)) {
            if ($this->access('add')) {
                $this->action = 'new';
                $this->display = 'add';
            } else {
                $this->errors[] = $this->l('You do not have permission to add this.');
            }
        } elseif (Tools::getIsset('update' . $t) && $this->current_id) {
            if ($this->access('edit')) {
                $this->action = 'edit';
                $this->display = 'edit';
            } else {
                $this->errors[] = $this->l('You do not have permission to edit this.');
            }
        } elseif (Tools::getIsset('delete' . $t) && $this->current_id) {
            /* Delete object */
            if ($this->access('delete')) {
                $this->action = 'delete';
            } else {
                $this->errors[] = $this->l('You do not have permission to delete this.');
            }
        } elseif (Tools::getIsset('view' . $t) && $this->current_id) {
            $this->display = 'view';
            $this->action = 'view';
        }
    }

    public function initContent()
    {
        if (!$this->viewAccess()) {
            $this->errors[] = $this->l('You do not have permission to view this.');
            return;
        }
        if ($this->is_shop_level == 1) {
            $this->informations[] = $this->l('This section is only available at the global level.');
            return;
        }

        if ($this->display == 'edit' || $this->display == 'add' || $this->display == 'view') {
            $this->content .= $this->renderForm();
        } elseif ($this->display == 'list') {
            $s = ' ';
            $this->informations[] = $this->l('You can make an ESI block for a widget, also known as Hole-Punching.').$s
                . $this->l('Default modules are "Customer Sign in" and "Shopping cart" which cannot be changed.') . $s
                . $this->l('These are advanced settings for third-party modules.') . $s
                . $this->l('If you need help, you can order support service from LiteSpeed Tech.');
            $this->content .= $this->renderList();
        }

        $this->context->smarty->assign(array(
            'content' => $this->content,
        ));
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitConfig')) {
            $this->processFormSave();
        } elseif ($this->action == 'delete') {
            $this->saveModConfig(array('id' => $this->current_id));
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

        switch ($name) {
            case 'id':
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
                }
                break;

            case 'tag':
                if ($postVal === '') {
                    // ok, will use default value
                } elseif (preg_match('/^[a-zA-Z-_0-9]+$/', $postVal) !== 1) {
                    $this->errors[] = $invalid . $s . $this->l('Invalid characters found.');
                }
                break;

            case 'events':
                $clean = array_unique(preg_split("/[\s,]+/", $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $evt) {
                        if (!preg_match('/^[a-zA-Z]+$/', $evt)) {
                            $this->errors[] = $invalid . $s . $this->l('Invalid characters found.');
                        } elseif (Tools::strlen($evt) < 8) {
                            $this->errors[] = $invalid . $s . $this->l('Event string usually starts with "action".');
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
        $inputs = array('id', 'priv', 'ttl', 'tag', 'events');
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
        $moduleOptions = array();
        if ($this->display == 'edit' || $this->display == 'view') {
            $name = $this->current_id;
            $moduleOptions[] = array(
                'id' => $name,
                'name' => $this->config_values[$name]['name']
            );
        } elseif ($this->display == 'add') {
            $list = array();
            $modules = Module::getModulesInstalled();
            $existing = array_keys($this->config_values);
            foreach ($modules as $module) {
                if (($module['active'] == 1)
                    && (!in_array($module['name'], $existing))
                    && ($tmp_instance = Module::getInstanceByName($module['name']))
                    && ($tmp_instance instanceof PrestaShop\PrestaShop\Core\Module\WidgetInterface)) {
                    $list[$module['name']] = $tmp_instance->displayName;
                }
            }
            natsort($list);
            foreach ($list as $id => $name) {
                $moduleOptions[] = array('id' => $id, 'name' => $name);
            }
        }

        $this->module_options = $moduleOptions;
    }

    public function renderForm()
    {
        // for new & edit & view
        $disabled = ($this->display == 'view');
        $form = array(
            'legend' => array(
                'title' => $this->l('Convert Widget to ESI Block'),
                'icon' => 'icon-cogs'
            ),
            'description' => $this->l('You can hole punch a widget as an ESI block.') . ' '
                . $this->l('Each ESI block can have its own TTL and purge events.'),
            'input' => array(
                array(
                    'type'    => 'select',
                    'label'   => $this->labels['id'],
                    'name'    => 'id',
                    'options' => array('query' => $this->module_options,
                        'id'    => 'id', 'name'  => 'name'),
                    'desc'    => $this->l('Please select a front-end widget module only.') . ' '
                        . $this->l('This will only be effective if this widget is showing on a cacheable page.'),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->labels['priv'],
                    'desc' => $this->l('A public block will only have one cached copy which is shared by everyone.') . ' '
                        . $this->l('A private block will be cached individually for each user.'),
                    'name' => 'priv',
                    'disabled'=> $disabled,
                    'is_bool' => true,
                    'values' => array(array('value' => 1), array('value' => 0)),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->labels['ttl'],
                    'name' => 'ttl',
                    'readonly'=> $disabled,
                    'desc' => $this->l('Leave this blank if you want to use the default setting.'),
                    'suffix' => $this->l('seconds'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->labels['tag'],
                    'name' => 'tag',
                    'readonly'=> $disabled,
                    'desc' => $this->l('Only allow one tag per module.') . ' '
                        . $this->l('Same tag can be used for multiple modules.') . ' '
                        . $this->l('Leave blank to use the module name as the default value.'),
                ),
                array(
                    'type' => 'textarea',
                    'label' => $this->labels['events'],
                    'name' => 'events',
                    'hint' => $this->l('This requires a deep understanding of the internals of Prestashop.') . ' ' .
                    $this->l('If you need help, you can order Support service from LiteSpeed Tech.'),
                    'readonly'=> $disabled,
                    'desc' => $this->l('You can automatically purge the cached ESI blocks by events.') . ' ' .
                    $this->l('Leave blank to solely rely on the TTL for timeout.') . ' ' .
                    $this->l('Login and logout events are included by default for all private blocks.') . ' ' .
                    $this->l('Specify a comma-delimited list of events.'),
                ),
            ),

        );
        if (!$disabled) {
            $form['submit'] = array('title' => $this->l('Save'));
        }

        $forms = array(array('form' => $form));

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

        $helper->tpl_vars = array('fields_value' => $this->current_values);
        return $helper->generateForm($forms);
    }

    public function renderList()
    {
        $this->fields_list = array(
            'name' => array('title' => $this->labels['id'], 'width' => 'auto'),
            'priv' => array('title' => $this->labels['priv'], 'width' => '25', 'align' => 'center', 'type' => 'bool'),
            'ttl' => array('title' => $this->labels['ttl'], 'align' => 'center', 'class' => 'fixed-width-sm'),
            'tag' => array('title' => $this->labels['tag'], 'align' => 'center', 'class' => 'fixed-width-sm'),
            'events' => array('title' => $this->labels['events'], 'align' => 'center', 'width' => 'auto'),
        );

        $this->_list = $this->config_values;

        $this->actions[] = 'view';
        $this->actions[] = 'edit';
        $this->actions[] = 'delete';

        $defaultmods = array_keys($this->default);
        $this->list_skip_actions['edit'] = $defaultmods;
        $this->list_skip_actions['delete'] = $defaultmods;
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

    public function initPageHeaderToolbar()
    {
        if ($this->is_shop_level !== 1) {
            if ($this->display == 'list') {
                $this->page_header_toolbar_btn['new_esi'] = array(
                    'href' => self::$currentIndex . '&addesimod&token=' . $this->token,
                    'desc' => $this->l('Add New ESI Block'),
                    'icon' => 'process-icon-new'
                );
            } else {
                $this->page_header_toolbar_btn['goback'] = array(
                    'href' => self::$currentIndex . '&token=' . $this->token,
                    'desc' => $this->l('Back to List'),
                    'icon' => 'process-icon-back'
                );
            }
        }
        parent::initPageHeaderToolbar();
    }
}
