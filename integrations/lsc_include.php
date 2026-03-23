<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Base class — must be included first
include __DIR__ . '/LscIntegration.php';

// Core ESI blocks
include __DIR__ . '/core/LscToken.php';
include __DIR__ . '/core/LscEnv.php';

// PrestaShop native modules
include __DIR__ . '/prestashop/LscCustomerSignIn.php';
include __DIR__ . '/prestashop/LscShoppingcart.php';
include __DIR__ . '/prestashop/LscEmailAlerts.php';

// Third-party modules — comment out if not installed
include __DIR__ . '/modules/LscGdprPro.php';
include __DIR__ . '/modules/LscPscartdropdown.php';

// Theme integrations — comment out if not using the theme
include __DIR__ . '/themes/iqit/loader.php';              // Warehouse theme
include __DIR__ . '/themes/panda_transform/loader.php';   // Panda / Transform theme
include __DIR__ . '/themes/promokit/loader.php';           // Alysum theme
