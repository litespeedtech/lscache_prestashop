<?php
/**
 * LiteSpeed Cache for PrestaShop.
 *
 * @author    LiteSpeed Technologies
 * @copyright Copyright (c) 2017-2024 LiteSpeed Technologies, Inc.
 * @license   https://opensource.org/licenses/GPL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$config = new PrestaShop\CodingStandards\CsFixer\Config();

/** @var \Symfony\Component\Finder\Finder $finder */
$finder = $config->setUsingCache(true)->getFinder();
$finder->in(__DIR__)->exclude(['vendor', 'node_modules', 'tests']);

return $config;
