<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Tests\Unit\Config;

use LiteSpeed\Cache\Config\ExclusionsConfig;
use PHPUnit\Framework\TestCase;

class ExclusionsConfigTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::clear();
        ExclusionsConfig::reset();
    }

    public function testGetDefaultsReturnsArray(): void
    {
        $defaults = ExclusionsConfig::getDefaults();
        $this->assertIsArray($defaults);
    }

    public function testSaveAndGetAll(): void
    {
        $data = ExclusionsConfig::getDefaults();
        ExclusionsConfig::saveAll($data);
        ExclusionsConfig::reset();

        $loaded = ExclusionsConfig::getAll();
        $this->assertIsArray($loaded);
    }

    public function testResetClearsCache(): void
    {
        ExclusionsConfig::getAll(); // populate cache
        ExclusionsConfig::reset();
        // After reset, next call re-reads from Configuration
        $loaded = ExclusionsConfig::getAll();
        $this->assertIsArray($loaded);
    }
}
