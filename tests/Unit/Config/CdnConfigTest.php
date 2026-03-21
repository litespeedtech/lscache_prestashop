<?php

namespace LiteSpeed\Cache\Tests\Unit\Config;

use LiteSpeed\Cache\Config\CdnConfig;
use PHPUnit\Framework\TestCase;

class CdnConfigTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::clear();
        CdnConfig::reset();
    }

    public function testGetDefaultsHasExpectedKeys(): void
    {
        $defaults = CdnConfig::getDefaults();

        $this->assertArrayHasKey(CdnConfig::CF_ENABLE, $defaults);
        $this->assertArrayHasKey(CdnConfig::CF_KEY, $defaults);
        $this->assertArrayHasKey(CdnConfig::CF_EMAIL, $defaults);
        $this->assertArrayHasKey(CdnConfig::CF_DOMAIN, $defaults);
        $this->assertArrayHasKey(CdnConfig::CF_PURGE, $defaults);
        $this->assertArrayHasKey(CdnConfig::CF_ZONE_ID, $defaults);
        $this->assertSame(0, $defaults[CdnConfig::CF_ENABLE]);
        $this->assertSame('', $defaults[CdnConfig::CF_KEY]);
    }

    public function testSaveAndGetAll(): void
    {
        $data = CdnConfig::getDefaults();
        $data[CdnConfig::CF_ENABLE] = 1;
        $data[CdnConfig::CF_DOMAIN] = 'example.com';

        CdnConfig::saveAll($data);
        CdnConfig::reset();

        $loaded = CdnConfig::getAll();
        $this->assertSame(1, $loaded[CdnConfig::CF_ENABLE]);
        $this->assertSame('example.com', $loaded[CdnConfig::CF_DOMAIN]);
    }

    public function testResetClearsCache(): void
    {
        $data = CdnConfig::getDefaults();
        $data[CdnConfig::CF_ENABLE] = 1;
        CdnConfig::saveAll($data);

        $loaded1 = CdnConfig::getAll();
        $this->assertSame(1, $loaded1[CdnConfig::CF_ENABLE]);

        // Change underlying data
        \Configuration::updateGlobalValue(CdnConfig::ENTRY, json_encode(CdnConfig::getDefaults()));
        CdnConfig::reset();

        $loaded2 = CdnConfig::getAll();
        $this->assertSame(0, $loaded2[CdnConfig::CF_ENABLE]);
    }
}
