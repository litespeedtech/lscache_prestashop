<?php

namespace LiteSpeed\Cache\Tests\Unit\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\ObjConfig;
use PHPUnit\Framework\TestCase;

class ObjConfigTest extends TestCase
{
    protected function setUp(): void
    {
        ObjConfig::reset();
        \Configuration::deleteByName(ObjConfig::ENTRY);
    }

    public function testGetDefaultsHasExpectedKeys(): void
    {
        $defaults = ObjConfig::getDefaults();

        $this->assertArrayHasKey(ObjConfig::OBJ_ENABLE, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_METHOD, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_HOST, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_PORT, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_TTL, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_PASSWORD, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_REDIS_DB, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_GLOBAL_GROUPS, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_NOCACHE_GROUPS, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_PERSISTENT, $defaults);
        $this->assertArrayHasKey(ObjConfig::OBJ_ADMIN_CACHE, $defaults);
    }

    public function testDefaultsDisabled(): void
    {
        $defaults = ObjConfig::getDefaults();
        $this->assertSame(0, $defaults[ObjConfig::OBJ_ENABLE]);
        $this->assertSame('redis', $defaults[ObjConfig::OBJ_METHOD]);
        $this->assertSame(6379, $defaults[ObjConfig::OBJ_PORT]);
    }

    public function testSaveAndGetAll(): void
    {
        $data = ObjConfig::getDefaults();
        $data[ObjConfig::OBJ_ENABLE] = 1;
        $data[ObjConfig::OBJ_HOST] = 'redis';

        ObjConfig::saveAll($data);
        ObjConfig::reset();

        $loaded = ObjConfig::getAll();
        $this->assertSame(1, $loaded[ObjConfig::OBJ_ENABLE]);
        $this->assertSame('redis', $loaded[ObjConfig::OBJ_HOST]);
    }

    public function testResetClearsCache(): void
    {
        $data = ObjConfig::getDefaults();
        $data[ObjConfig::OBJ_ENABLE] = 1;
        ObjConfig::saveAll($data);

        $this->assertSame(1, ObjConfig::get(ObjConfig::OBJ_ENABLE));

        ObjConfig::reset();
        \Configuration::deleteByName(ObjConfig::ENTRY);

        $this->assertSame(0, ObjConfig::get(ObjConfig::OBJ_ENABLE));
    }
}
