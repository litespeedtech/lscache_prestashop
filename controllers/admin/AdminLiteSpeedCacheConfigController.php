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

class AdminLiteSpeedCacheConfigController extends ModuleAdminController
{
    private $config;

    private $is_shop_level; // -1: not multishop, 0: multishop global, 1: multishop shop

    private $labels;

    private $current_values;

    private $license_disabled;

    // BITMASK for changed
    const BMC_SHOP = 1; // change for shop

    const BMC_ALL = 2;  // change for all

    const BMC_NONEED_PURGE = 4; // no need to purge, effective immediately

    const BMC_MAY_PURGE = 8; // purge to be effective, but don't have to

    const BMC_MUST_PURGE = 16;

    const BMC_DONE_PURGE = 32; // already purged

    const BMC_HTACCESS_UPDATE = 64;

    private $original_values;

    private $changed = 0;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        parent::__construct();

        $this->config = Conf::getInstance();
        if (!$this->module->active) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules'));
        }

        $title = $this->l('LiteSpeed Cache Configuration');
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

        $this->original_values = $this->config->getAllConfigValues();
        $this->original_values['esi'] = (isset($_SERVER['X-LSCACHE']) && strpos($_SERVER['X-LSCACHE'],'esi')!==false) ? 1 : 0 ;
        $this->current_values = $this->original_values;
        $this->labels = [
            Conf::CFG_ENABLED => $this->l('Enable LiteSpeed Cache'),
            Conf::CFG_PUBLIC_TTL => $this->l('Default Public Cache TTL'),
            Conf::CFG_PRIVATE_TTL => $this->l('Default Private Cache TTL'),
            Conf::CFG_HOME_TTL => $this->l('Home Page TTL'),
            Conf::CFG_404_TTL => $this->l('404 Pages TTL'),
            Conf::CFG_PCOMMENTS_TTL => $this->l('Product Comments TTL'),
            Conf::CFG_DIFFMOBILE => $this->l('Separate Mobile View'),
            Conf::CFG_DIFFCUSTGRP => $this->l('Separate Cache Copy per Customer Group'),
            Conf::CFG_FLUSH_ALL => $this->l('Flush All Pages When Cache Cleared'),
            Conf::CFG_FLUSH_PRODCAT => $this->l('Flush Product and Categories When Order Placed'),
            Conf::CFG_FLUSH_HOME => $this->l('Flush Home Page When Order Placed'),
            Conf::CFG_FLUSH_HOME_INPUT => $this->l('Specify Product IDs for Home Page Flush'),
            Conf::CFG_GUESTMODE => $this->l('Enable Guest Mode'),
            Conf::CFG_NOCACHE_VAR => $this->l('Do-Not-Cache GET Parameters'),
            Conf::CFG_NOCACHE_URL => $this->l('URL Blacklist'),
            Conf::CFG_VARY_BYPASS => $this->l('Context Vary Bypass'),
            Conf::CFG_ALLOW_IPS => $this->l('Enable Cache Only for Listed IPs'),
            Conf::CFG_DEBUG_HEADER => $this->l('Enable Debug Headers'),
            Conf::CFG_DEBUG => $this->l('Enable Debug Log'),
            Conf::CFG_DEBUG_IPS => $this->l('Log Only for Listed IPs'),
            Conf::CFG_DEBUG_LEVEL => $this->l('Debug Level'),
        ];
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['purge_shops'] = [
            'href' => self::$currentIndex . '&purge_shops&token=' . $this->token,
            'desc' => $this->l('Flush All PrestaShop Pages'),
            'icon' => 'process-icon-delete',
        ];
        parent::initPageHeaderToolbar();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitConfig')) {
            $this->processConfigSave();
        } elseif (Tools::isSubmit('purge_shops')) {
            if ($this->license_disabled) {
                $this->warnings[] = $this->l('No action taken. No LiteSpeed Server with LSCache available.');
            } else {
                $this->processPurgeShops();
            }
        }

        return parent::postProcess();
    }

    private function processConfigSave()
    {
        $inputs = [
            Conf::CFG_PUBLIC_TTL,
            Conf::CFG_PRIVATE_TTL,
            Conf::CFG_HOME_TTL,
            Conf::CFG_404_TTL,
            Conf::CFG_PCOMMENTS_TTL,
            Conf::CFG_DIFFCUSTGRP,
        ];
        if ($this->is_shop_level != 1) {
            $all = [
                Conf::CFG_ENABLED,
                Conf::CFG_DIFFMOBILE,
                Conf::CFG_GUESTMODE,
                Conf::CFG_NOCACHE_VAR,
                Conf::CFG_NOCACHE_URL,
                Conf::CFG_VARY_BYPASS,
                Conf::CFG_FLUSH_PRODCAT,
                Conf::CFG_FLUSH_ALL,
                Conf::CFG_FLUSH_HOME,
                Conf::CFG_FLUSH_HOME_INPUT,
                Conf::CFG_DEBUG_HEADER,
                Conf::CFG_DEBUG,
                Conf::CFG_DEBUG_LEVEL,
                Conf::CFG_ALLOW_IPS,
                Conf::CFG_DEBUG_IPS,
            ];
            $inputs = array_merge($inputs, $all);
        }

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

        $guest = ($this->current_values[Conf::CFG_GUESTMODE] == 1); // only test if it has guestmode=1, for option=2, will handle in code, not htaccess
        $mobile = $this->current_values[Conf::CFG_DIFFMOBILE];
        if ($guest && ($this->original_values[Conf::CFG_DIFFMOBILE] != $mobile)) {
            $this->changed |= self::BMC_HTACCESS_UPDATE;
        }
        if ($this->changed & self::BMC_SHOP) {
            $this->config->updateConfiguration(Conf::ENTRY_SHOP, $this->current_values);
        }
        if ($this->changed & self::BMC_ALL) {
            $this->config->updateConfiguration(Conf::ENTRY_ALL, $this->current_values);
        }
        $this->confirmations[] = $this->l('Settings updated successfully.');
        //please manually fix .htaccess, may due to permission
        if ($this->changed & self::BMC_DONE_PURGE) {
            $this->processPurgeShops();
            $this->confirmations[] = $this->l('Disabled LiteSpeed Cache.');
        } elseif ($this->changed & self::BMC_MUST_PURGE) {
            $this->confirmations[] = $this->l('You must flush all pages to make this change effective.');
        } elseif ($this->changed & self::BMC_MAY_PURGE) {
            $this->confirmations[] = $this->l('You may want to purge related contents to make this change effective.');
        } elseif ($this->changed & self::BMC_NONEED_PURGE) {
            $this->confirmations[] = $this->l('Changes will be effective immediately. No need to purge.');
        }
        if ($this->changed & self::BMC_HTACCESS_UPDATE) {
            $res = LiteSpeedCacheHelper::htAccessUpdate($this->current_values[Conf::CFG_ENABLED], $guest, $mobile);
            if ($res) {
                $this->confirmations[] = $this->l('.htaccess file updated accordingly.');
            } else {
                $url = 'https://docs.litespeedtech.com/lscache/lscps/installation/#htaccess-update';
                $this->warnings[] = $this->l('Failed to update .htaccess due to permission.') . ' ' . '<a href="' . $url
                    . '"  target="_blank" rel="noopener noreferrer">' . $this->l('Please manually update.') . '</a>';
            }
        }
    }

    private function processPurgeShops()
    {
        $params = ['from' => 'AdminLiteSpeedCacheConfig', 'public' => '*'];
        Hook::exec('litespeedCachePurge', $params);
        $this->confirmations[] = $this->l('Notified LiteSpeed Server to flush all pages of this PrestaShop.');
    }

    private function validateInput($name)
    {
        $postVal = Tools::getValue($name);
        $origVal = $this->original_values[$name];
        $invalid = $this->l('Invalid value') . ': ' . $this->labels[$name];
        $s = ' - '; // spacer
        $pattern = "/[\s,]+/";
        // 1: no need to purge, 2: purge to be effective, but don't have to, 4: have to purge, 8: already purged

        switch ($name) {
            case Conf::CFG_ENABLED:
                $postVal = (int) $postVal;
                if ($postVal != $origVal) {
                    // if disable, purge all
                    $this->changed |= self::BMC_ALL | self::BMC_HTACCESS_UPDATE
                        | (($postVal == 0) ? self::BMC_DONE_PURGE : self::BMC_NONEED_PURGE);
                }
                break;

            case Conf::CFG_PUBLIC_TTL:
                if (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal < 300) {
                        $this->errors[] = $invalid . $s . $this->l('Must be greater than 300 seconds');
                    } elseif ($postVal != $origVal) {
                        $this->changed |= self::BMC_SHOP;
                        $this->changed |= ($postVal < $origVal) ? self::BMC_MUST_PURGE : self::BMC_MAY_PURGE;
                    }
                }
                break;

            case Conf::CFG_PRIVATE_TTL:
                if (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal < 180 || $postVal > 7200) {
                        $this->errors[] = $invalid . $s . $this->l('Must be within the 180 to 7200 range.');
                    } elseif ($postVal != $origVal) {
                        $this->changed |= self::BMC_SHOP | self::BMC_NONEED_PURGE;
                    }
                }
                break;

            case Conf::CFG_HOME_TTL:
                if (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal < 60) {
                        $this->errors[] = $invalid . $s .
                            $this->l('Must be greater than 60 seconds.');
                    } elseif ($postVal != $origVal) {
                        $this->changed |= self::BMC_SHOP;
                        $this->changed |= ($postVal < $origVal) ? self::BMC_MUST_PURGE : self::BMC_MAY_PURGE;
                    }
                }
                break;

            case Conf::CFG_404_TTL:
                if (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal > 0 && $postVal < 300) {
                        $this->errors[] = $invalid . $s . $this->l('Must be greater than 300 seconds.');
                    } elseif ($postVal != $origVal) {
                        if ($postVal == 0) {
                            $this->changed |= self::BMC_SHOP | self::BMC_MUST_PURGE;
                        } else {
                            $this->changed |= self::BMC_SHOP;
                            $this->changed |= ($postVal < $origVal) ? self::BMC_MUST_PURGE : self::BMC_MAY_PURGE;
                        }
                    }
                }
                break;

            case Conf::CFG_PCOMMENTS_TTL:
                if (!Validate::isUnsignedInt($postVal)) {
                    $this->errors[] = $invalid;
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal > 0 && $postVal < 300) {
                        $this->errors[] = $invalid . $s . $this->l('Must be greater than 300 seconds.');
                    } elseif ($postVal != $origVal) {
                        if ($postVal == 0) {
                            $this->changed |= self::BMC_SHOP | self::BMC_MUST_PURGE;
                        } else {
                            $this->changed |= self::BMC_SHOP;
                            $this->changed |= ($postVal < $origVal) ? self::BMC_MUST_PURGE : self::BMC_MAY_PURGE;
                        }
                    }
                }
                break;
                
            case Conf::CFG_DIFFMOBILE:
                $postVal = (int) $postVal;
                if ($postVal != 0 && $postVal != 1 && $postVal != 2) {
                    // should not happen in drop down
                    $postVal = 0;
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_MUST_PURGE;
                }
                break;

            case Conf::CFG_DIFFCUSTGRP:
                $postVal = (int) $postVal;
                if ($postVal != 0 && $postVal != 1 && $postVal != 2 && $postVal != 3) {
                    // should not happen in drop down
                    $postVal = 0;
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_SHOP | self::BMC_MUST_PURGE;
                }
                break;

            case Conf::CFG_FLUSH_ALL:
                $postVal = (int) $postVal;
                if ($postVal < 0 || $postVal > 4) {
                    // should not happen in drop down
                    $postVal = 0;
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;
                                
            case Conf::CFG_FLUSH_PRODCAT:
                $postVal = (int) $postVal;
                if ($postVal < 0 || $postVal > 4) {
                    // should not happen in drop down
                    $postVal = 0;
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;

            case Conf::CFG_FLUSH_HOME:
                $postVal = (int) $postVal;
                if ($postVal < 0 || $postVal > 2) {
                    // should not happen in drop down
                    $postVal = 0;
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;

            case Conf::CFG_FLUSH_HOME_INPUT:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    $postVal = implode(', ', $clean);
                    if (!preg_match('/^[\d ,]+$/', $postVal)) {
                        $this->errors[] = $invalid;
                    }
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;


            case Conf::CFG_GUESTMODE:
                $postVal = (int) $postVal;
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE | self::BMC_HTACCESS_UPDATE;
                }
                break;

            case Conf::CFG_NOCACHE_VAR:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    $postVal = implode(', ', $clean);
                    if (!preg_match('/^[a-zA-Z1-9_\- ,]+$/', $postVal)) {
                        $this->errors[] = $invalid;
                    }
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_MUST_PURGE;
                }
                break;

            case Conf::CFG_NOCACHE_URL:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    $postVal = implode("\n", $clean);
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_MUST_PURGE;
                }
                break;

            case Conf::CFG_VARY_BYPASS:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    $postVal = implode(', ', $clean);
                    $test = array_diff($clean, ['ctry', 'curr', 'lang']);
                    if (!empty($test)) {
                        $this->errors[] = $invalid . $s . $this->l('Value not supported') . ': ' . implode(', ', $test);
                    }
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_MUST_PURGE;
                }
                break;

            case Conf::CFG_DEBUG_HEADER:
                $postVal = (int) $postVal;
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;

            case Conf::CFG_DEBUG:
                $postVal = (int) $postVal;
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;

            case Conf::CFG_DEBUG_LEVEL:
                if (!Validate::isUnsignedInt($postVal) || $postVal < 1 || $postVal > 10) {
                    $this->errors[] = $invalid . $s . $this->l('Valid range is 1 to 10.');
                } else {
                    $postVal = (int) $postVal;
                    if ($postVal != $origVal) {
                        $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                    }
                }
                break;
            case Conf::CFG_ALLOW_IPS:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $ip) {
                        if (!preg_match('/^[[:alnum:]._-]+$/', $ip)) {
                            $this->errors[] = $invalid;
                        }
                    }
                    $postVal = implode(', ', $clean);
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_MUST_PURGE;
                }
                break;
            case Conf::CFG_DEBUG_IPS:
                $clean = array_unique(preg_split($pattern, $postVal, null, PREG_SPLIT_NO_EMPTY));
                if (count($clean) == 0) {
                    $postVal = '';
                } else {
                    foreach ($clean as $ip) {
                        if (!preg_match('/^[[:alnum:]._-]+$/', $ip)) {
                            $this->errors[] = $invalid;
                        }
                    }
                    $postVal = implode(', ', $clean);
                }
                if ($postVal != $origVal) {
                    $this->changed |= self::BMC_ALL | self::BMC_NONEED_PURGE;
                }
                break;
        }

        $this->current_values[$name] = $postVal;
    }

    public function renderView()
    {
        $disabled = ($this->is_shop_level == 1);
        if ($disabled) {
            $this->informations[] = $this->l('Some settings can only be set at the global level.');
        }

        $secs = $this->l('seconds');
        $s = ' - '; // spacer
        $fg = $this->newFieldForm($this->l('General') . ' v' . LiteSpeedCache::getVersion(), 'cogs');
        $fg['input'][] = $this->addInputSwitch(Conf::CFG_ENABLED, $this->labels[Conf::CFG_ENABLED], '', $disabled);
        $fg['input'][] = $this->addInputSwitch('esi', $this->l('Enable LiteSpeed ESI'), '', true);
        $fg['input'][] = $this->addInputText(
            Conf::CFG_PUBLIC_TTL,
            $this->labels[Conf::CFG_PUBLIC_TTL],
            $this->l('Default timeout for publicly cached pages.') . ' ' . $this->l('Recommended value is 86400.'),
            $secs
        );
        $fg['input'][] = $this->addInputText(
            Conf::CFG_PRIVATE_TTL,
            $this->labels[Conf::CFG_PRIVATE_TTL],
            $this->l('Default timeout for private cache ESI blocks. Suggested value is 1800. Must be less than 7200.'),
            $secs
        );
        $fg['input'][] = $this->addInputText(
            Conf::CFG_HOME_TTL,
            $this->labels[Conf::CFG_HOME_TTL],
            $this->l('Default timeout for the home page.') . ' '
            . $this->l('If you have random displayed items, you can have shorter TTL to make it refresh more often.'),
            $secs
        );
        $fg['input'][] = $this->addInputText(
            Conf::CFG_404_TTL,
            $this->labels[Conf::CFG_404_TTL],
            $this->l('Default timeout for all 404 (Not found) pages. 0 will disable caching for 404 pages.'),
            $secs
        );
        $fg['input'][] = $this->addInputText(
            Conf::CFG_PCOMMENTS_TTL,
            $this->labels[Conf::CFG_PCOMMENTS_TTL],
            $this->l('Timeout for product comments.') . ' '
            . $this->l('0 will disable caching for product comments.') . ' '
            . $this->l('There is no automatic purge when comments are updated.') . ' '
            . $this->l('You can set a shorter TTL if you want new comments to show more quickly, or you can manually flush the cache.'),
            $secs
        );
        $fg['input'][] = $this->addInputSwitch(
            Conf::CFG_DIFFMOBILE,
            $this->labels[Conf::CFG_DIFFMOBILE],
            $this->l('Enable this if you have a separate mobile theme.'),
            $disabled
        );

        $custgrpOptions = [
            ['id' => 0, 'name' => $this->l('No') . $s . $this->l('Everyone shares the same view')],
            ['id' => 1, 'name' => $this->l('Yes') . $s . $this->l('Each group has its own view')],
            ['id' => 2, 'name' => $this->l('Two views') . $s .
                $this->l('One for all logged-in users and another for logged-out users'), ],
            ['id' => 3, 'name' => $this->l('One view') . $s .
            $this->l('Only cache logged-out view'), ],

        ];
        $fg['input'][] = $this->addInputSelect(
            Conf::CFG_DIFFCUSTGRP,
            $this->labels[Conf::CFG_DIFFCUSTGRP],
            $custgrpOptions,
            $this->l('Enable this option if there is different pricing based on customer groups.')
        );

        $fg['input'][] = $this->addInputSwitch(Conf::CFG_FLUSH_ALL, $this->labels[Conf::CFG_FLUSH_ALL], 'If disabled, please manually flush all pages after clearing the Prestashop cache.', $disabled);

        $flushprodOptions = [
            ['id' => 0, 'name' => $this->l('Flush product when quantity or stock status change, flush categories only when stock status changes')],
            ['id' => 1, 'name' => $this->l('Flush product and categories only when stock status changes')],
            ['id' => 2, 'name' => $this->l('Flush product when stock status changes, do not flush categories when stock status or quantity change')],
            ['id' => 3, 'name' => $this->l('Always flush product and categories when quantity or stock status change')],
            ['id' => 4, 'name' => $this->l('Do not flush product or categories')],
        ];
        $fg['input'][] = $this->addInputSelect(
            Conf::CFG_FLUSH_PRODCAT,
            $this->labels[Conf::CFG_FLUSH_PRODCAT],
            $flushprodOptions,
            $this->l('Determines how changes in product quantity and stock status affect product pages and their associated category pages.'),
            $disabled
        );

        $flushhomeOptions = [
            ['id' => 0, 'name' => $this->l('Do not flush the home page')],
            ['id' => 1, 'name' => $this->l('Flush the home page when stock status changed for specified products')],
            ['id' => 2, 'name' => $this->l('Flush the home page when stock status or quantity is changed for specified products')],
        ];
        $fg['input'][] = $this->addInputSelect(
            Conf::CFG_FLUSH_HOME,
            $this->labels[Conf::CFG_FLUSH_HOME],
            $flushhomeOptions,
            $this->l('Determines how changes in product quantity and stock status affect the home page.') . ' '
                . $this->l('No need to flush if your home page does not show any products.'),
            $disabled
        );

        $fg['input'][] = $this->addInputTextArea(
            Conf::CFG_FLUSH_HOME_INPUT,
            $this->labels[Conf::CFG_FLUSH_HOME_INPUT],
            $this->l('Only flush the home page for specified product IDs. (Space or comma separated.)') . ' '
            . $this->l('If empty, any product update will trigger home page flush. Only effective when home page flush option is selected.'),
            $disabled
        );

        $guestOptions = [
            ['id' => 0, 'name' => $this->l('No') . $s . $this->l('No default guest view')],
            ['id' => 1, 'name' => $this->l('Yes') . $s . $this->l('Has default guest view')],
            ['id' => 2, 'name' => $this->l('First Page Only') . $s .
                $this->l('Only first page will show the default guest view'), ],
        ];
        $fg['input'][] = $this->addInputSelect(
            Conf::CFG_GUESTMODE,
            $this->labels[Conf::CFG_GUESTMODE],
            $guestOptions,
            $this->l('This will speed up the first page view for new visitors by serving the default view.') . ' '
            . $this->l('Robots will get an instant response without hitting the backend.') . ' '
            . $this->l('If you have different views based on GeoIP,') . ' '
            . $this->l('select "First Page Only" to make sure the second page will have the correct view.'),
            $disabled
        );

        $formUser = $this->newFieldForm(
            $this->l('User-Defined Cache Rules'),
            'cogs',
            $disabled ? $this->l('These settings can only be set at the global level.') :
                $this->l('Only need to set it NOT-CACHEABLE if a page is being cached by default.')
        );
        $formUser['input'][] = $this->addInputTextArea(
            Conf::CFG_NOCACHE_VAR,
            $this->labels[Conf::CFG_NOCACHE_VAR],
            $this->l('Comma-separated list of GET variables that prevents caching URLs within Cacheable Routes.'),
            $disabled
        );
        $formUser['input'][] = $this->addInputTextArea(
            Conf::CFG_NOCACHE_URL,
            $this->labels[Conf::CFG_NOCACHE_URL],
            $this->l('List of relative URLs contained in Cacheable Routes to be excluded from caching.') . '<br>'
            . $this->l('They start with "/" and don\’t include the domain name.') . ' '
            . $this->l('Partial matches can be performed by adding an "*" to the end of a URL.') . ' '
            . $this->l('Enter one relative URL per line.') . '<br>'
            . $this->l('URLs that do not start with "/" will be treated as URL REGEX rules.') . ' ',
            $disabled
        );
        $formUser['input'][] = $this->addInputTextArea(
            Conf::CFG_VARY_BYPASS,
            $this->labels[Conf::CFG_VARY_BYPASS],
            $this->l('If certain context changes are global and cacheable, you can list their names in a comma-delimited string to avoid duplicate cache copies and allow the first visit to have a cache hit.') . ' '
                . $this->l('Supported values are: ctry (if all countries have same view), curr (if different currency pages will not share same URL), and lang (if different language pages will always have different URLs).'),
            $disabled
        );

        $formDev = $this->newFieldForm($this->l('Developer Testing'), 'stethoscope');
        $formDev['input'][] = $this->addInputTextArea(
            Conf::CFG_ALLOW_IPS,
            $this->labels[Conf::CFG_ALLOW_IPS],
            $this->l('Limit LiteSpeed Cache to specified IPs. (Space or comma separated.)') . ' '
            . $this->l('Allows cache testing on a live site. If empty, cache will be served to everyone.'),
            $disabled
        );
        $formDev['input'][] = $this->addInputSwitch(
            Conf::CFG_DEBUG_HEADER,
            $this->labels[Conf::CFG_DEBUG_HEADER],
            $this->l('Show debug information through response headers. Turn off for production use.'),
            $disabled
        );
        $formDev['input'][] = $this->addInputSwitch(
            Conf::CFG_DEBUG,
            $this->labels[Conf::CFG_DEBUG],
            $this->l('Prints additional information to "lscache.log." Turn off for production use.'),
            $disabled
        );
        $formDev['input'][] = $this->addInputTextArea(
            Conf::CFG_DEBUG_IPS,
            $this->labels[Conf::CFG_DEBUG_IPS],
            $this->l('Only log activities from specified IPs. (Space or comma separated.)') . ' '
            . $this->l('If empty, all activities will be logged. Only effective when debug log is enabled.'),
            $disabled
        );
        $formDev['input'][] = $this->addInputText(
            Conf::CFG_DEBUG_LEVEL,
            $this->labels[Conf::CFG_DEBUG_LEVEL],
            $this->l('Specifies log level ranging from 1 to 10. The higher the value, the more detailed the output.'),
            '',
            false,
            $disabled
        );

        $forms = [['form' => $fg], ['form' => $formUser], ['form' => $formDev]];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->languages = $this->getLanguages();
        $helper->name_controller = $this->controller_name;
        $helper->token = $this->token;
        $helper->default_form_language = $this->default_form_language;
        $helper->allow_employee_form_lang = $this->allow_employee_form_lang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitConfig';
        $helper->currentIndex = self::$currentIndex;

        $helper->tpl_vars = ['fields_value' => $this->current_values];

        return $helper->generateForm($forms);
    }

    private function addInputText($name, $label, $desc, $suffix = '', $required = true, $disabled = false)
    {
        $input = [
            'type' => 'text', 'name' => $name, 'label' => $label,
            'desc' => $desc, 'class' => 'input fixed-width-sm', 'required' => $required,
        ];
        if ($disabled) {
            $input['disabled'] = 1;
        }
        if ($suffix) {
            $input['suffix'] = $suffix;
        }

        return $input;
    }

    private function addInputSwitch($name, $label, $desc = '', $disabled = false)
    {
        $input = [
            'type' => 'switch', 'name' => $name, 'label' => $label,
	    'values' => [
                ['value' => 1, 'id' => $name.'_on'],
                ['value' => 0, 'id' => $name.'_off']
            ]
        ];
        if ($desc) {
            $input['desc'] = $desc;
        }
        if ($disabled) {
            $input['disabled'] = 1;
        }

        return $input;
    }

    private function addInputSelect($name, $label, $query, $desc = '', $required = true, $disabled = false)
    {
        $input = [
            'type' => 'select', 'name' => $name, 'label' => $label,
            'options' => ['query' => $query, 'id' => 'id', 'name' => 'name'],
            'required' => $required, 'class' => 'input fixed-width-xxl',
        ];
        if ($desc) {
            $input['desc'] = $desc;
        }
        if ($disabled) {
            $input['disabled'] = 1;
        }

        return $input;
    }

    private function addInputTextArea($name, $label, $desc, $disabled = false)
    {
        $input = [
            'type' => 'textarea', 'name' => $name, 'label' => $label,
            'desc' => $desc,
        ];
        if ($disabled) {
            $input['readonly'] = 1;
        }

        return $input;
    }

    private function newFieldForm($title, $icon, $desc = '')
    {
        $form = ['legend' => ['title' => $title, 'icon' => "icon-$icon"]];
        if ($desc) {
            $form['description'] = $desc;
        }
        $form['input'] = [];
        $form['submit'] = ['title' => $this->l('Save')];

        return $form;
    }
}
