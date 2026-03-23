<?php

namespace LiteSpeed\Cache\Tests\Unit\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\WarmupConfig;
use PHPUnit\Framework\TestCase;

class WarmupConfigTest extends TestCase
{
    protected function setUp(): void
    {
        WarmupConfig::reset();
        \Configuration::deleteByName(WarmupConfig::ENTRY);
    }

    public function testGetDefaultsHasExpectedKeys(): void
    {
        $defaults = WarmupConfig::getDefaults();

        $this->assertArrayHasKey(WarmupConfig::PROFILE, $defaults);
        $this->assertArrayHasKey(WarmupConfig::CRAWL_DELAY, $defaults);
        $this->assertArrayHasKey(WarmupConfig::CONCURRENT_REQUESTS, $defaults);
        $this->assertArrayHasKey(WarmupConfig::CRAWL_TIMEOUT, $defaults);
        $this->assertArrayHasKey(WarmupConfig::SERVER_LOAD_LIMIT, $defaults);
        $this->assertArrayHasKey(WarmupConfig::MOBILE_CRAWL, $defaults);
        $this->assertArrayHasKey(WarmupConfig::MOBILE_USER_AGENT, $defaults);
    }

    public function testDefaultProfileIsMedium(): void
    {
        $defaults = WarmupConfig::getDefaults();
        $this->assertSame(WarmupConfig::PROFILE_MEDIUM, $defaults[WarmupConfig::PROFILE]);
    }

    public function testSaveAndGetAll(): void
    {
        $data = WarmupConfig::getDefaults();
        $data[WarmupConfig::CONCURRENT_REQUESTS] = 8;
        $data[WarmupConfig::CRAWL_DELAY] = 0;

        WarmupConfig::saveAll($data);
        WarmupConfig::reset();

        $loaded = WarmupConfig::getAll();
        $this->assertSame(8, $loaded[WarmupConfig::CONCURRENT_REQUESTS]);
        $this->assertSame(0, $loaded[WarmupConfig::CRAWL_DELAY]);
    }

    public function testResetClearsCache(): void
    {
        $data = WarmupConfig::getDefaults();
        $data[WarmupConfig::CONCURRENT_REQUESTS] = 10;
        WarmupConfig::saveAll($data);

        $this->assertSame(10, WarmupConfig::get(WarmupConfig::CONCURRENT_REQUESTS));

        WarmupConfig::reset();
        \Configuration::deleteByName(WarmupConfig::ENTRY);

        $this->assertSame(
            WarmupConfig::getDefaults()[WarmupConfig::CONCURRENT_REQUESTS],
            WarmupConfig::get(WarmupConfig::CONCURRENT_REQUESTS)
        );
    }

    public function testGetProfileSettingsReturnsArrayForValidProfile(): void
    {
        $low = WarmupConfig::getProfileSettings(WarmupConfig::PROFILE_LOW);
        $this->assertIsArray($low);
        $this->assertArrayHasKey(WarmupConfig::CONCURRENT_REQUESTS, $low);
        $this->assertSame(1, $low[WarmupConfig::CONCURRENT_REQUESTS]);
    }

    public function testGetProfileSettingsReturnsNullForInvalidProfile(): void
    {
        $this->assertNull(WarmupConfig::getProfileSettings('nonexistent'));
    }

    public function testProfileConstants(): void
    {
        $profiles = WarmupConfig::getProfiles();
        $this->assertArrayHasKey(WarmupConfig::PROFILE_LOW, $profiles);
        $this->assertArrayHasKey(WarmupConfig::PROFILE_MEDIUM, $profiles);
        $this->assertArrayHasKey(WarmupConfig::PROFILE_HIGH, $profiles);
        $this->assertCount(3, $profiles);
    }
}
