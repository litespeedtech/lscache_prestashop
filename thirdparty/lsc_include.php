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

// base must be included first
include 'LscIntegration.php';

// share for all PS versions
include 'shared/LscToken.php';

// third-party theme integration
if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) { // for PS 1.7 only
    // default built-in modules
    include '1.7/LscCustomerSignIn.php';
    include '1.7/LscShoppingcart.php';

    // integrated modules
    include '1.7/LscIqitCompare.php';
    include '1.7/LscIqitWishlist.php';
} else { // for PS 1.6 only
    // default built-in modules
    include '1.6/LscBlockCart.php';
    include '1.6/LscBlockUserInfo.php';
    include '1.6/LscCompareProduct.php';
}
