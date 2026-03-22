<?php

/**
 * LiteSpeed Cache for Prestashop — backward-compatibility shim.
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
