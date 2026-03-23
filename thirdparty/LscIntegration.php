<?php
/**
 * LiteSpeed Cache for Prestashop — backward-compatibility shim.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 *
 * The real implementation is in LiteSpeed\Cache\Integration\Integration.
 * This file exists only so third-party integrations extending LscIntegration continue to work.
 *
 * Since LscIntegration is abstract and subclasses extend it, class_alias() maps
 * the old name to the new namespaced abstract class. PHP correctly resolves
 * `extends LscIntegration` to `extends LiteSpeed\Cache\Integration\Integration`.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class_alias(LiteSpeed\Cache\Integration\Integration::class, 'LscIntegration');
