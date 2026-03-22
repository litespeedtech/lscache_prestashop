<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Tests\Unit\Config;

use LiteSpeed\Cache\Config\CacheConfig;
use PHPUnit\Framework\TestCase;

class CacheConfigTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::clear();
        // Reset singleton
        $r = new \ReflectionClass(CacheConfig::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    public function testGetDefaultConfDataEntryAllHasExpectedKeys(): void
    {
        $config = CacheConfig::getInstance();
        $defaults = $config->getDefaultConfData(CacheConfig::ENTRY_ALL);

        $this->assertArrayHasKey('enable', $defaults);
        $this->assertArrayHasKey('debug', $defaults);
        $this->assertArrayHasKey('debug_level', $defaults);
        $this->assertArrayHasKey('allow_ips', $defaults);
        $this->assertArrayHasKey('guestmode', $defaults);
        $this->assertArrayHasKey('diff_mobile', $defaults);
        $this->assertSame(0, $defaults['enable']);
        $this->assertSame(0, $defaults['debug']);
        $this->assertSame(1, $defaults['guestmode']);
    }

    public function testGetDefaultConfDataEntryShopHasExpectedKeys(): void
    {
        $config = CacheConfig::getInstance();
        $defaults = $config->getDefaultConfData(CacheConfig::ENTRY_SHOP);

        $this->assertArrayHasKey('ttl', $defaults);
        $this->assertArrayHasKey('privttl', $defaults);
        $this->assertArrayHasKey('homettl', $defaults);
        $this->assertArrayHasKey('404ttl', $defaults);
        $this->assertSame(86400, $defaults['ttl']);
        $this->assertSame(1800, $defaults['privttl']);
    }

    public function testGetDefaultConfDataUnknownKeyReturnsEmptyArray(): void
    {
        $config = CacheConfig::getInstance();
        $this->assertSame([], $config->getDefaultConfData('unknown'));
    }

    public function testOverrideGuestModeChangesOneToTwo(): void
    {
        // Get defaults first before resetting
        $tempConfig = CacheConfig::getInstance();
        $allDefaults = $tempConfig->getDefaultConfData(CacheConfig::ENTRY_ALL);
        $shopDefaults = $tempConfig->getDefaultConfData(CacheConfig::ENTRY_SHOP);

        $allDefaults['guestmode'] = 1;

        // Reset singleton and seed config
        $r = new \ReflectionClass(CacheConfig::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, null);

        \Configuration::clear();
        \Configuration::seed([
            CacheConfig::ENTRY_ALL => json_encode($allDefaults),
            CacheConfig::ENTRY_SHOP => json_encode($shopDefaults),
        ]);

        $config = CacheConfig::getInstance();
        $this->assertSame(1, $config->get(CacheConfig::CFG_GUESTMODE));
        $config->overrideGuestMode();
        $this->assertSame(2, $config->get(CacheConfig::CFG_GUESTMODE));
    }

    public function testTagPrefixConstants(): void
    {
        $this->assertSame('P', CacheConfig::TAG_PREFIX_PRODUCT);
        $this->assertSame('C', CacheConfig::TAG_PREFIX_CATEGORY);
        $this->assertSame('M', CacheConfig::TAG_PREFIX_MANUFACTURER);
        $this->assertSame('L', CacheConfig::TAG_PREFIX_SUPPLIER);
        $this->assertSame('G', CacheConfig::TAG_PREFIX_CMS);
        $this->assertSame('H', CacheConfig::TAG_HOME);
    }

    public function testIsBypassedDefaultFalse(): void
    {
        $this->assertFalse(CacheConfig::isBypassed());
    }

    public function testSetBypassAndIsBypassed(): void
    {
        CacheConfig::setBypass(true);
        $this->assertTrue(CacheConfig::isBypassed());

        CacheConfig::setBypass(false);
        $this->assertFalse(CacheConfig::isBypassed());
    }
}
