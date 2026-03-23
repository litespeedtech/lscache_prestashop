<?php

namespace LiteSpeed\Cache\Tests\Unit\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Admin\ConfigValidator;
use LiteSpeed\Cache\Config\CacheConfig as Conf;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function testEnableNoChange(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_ENABLED, 1, 1);
        $this->assertSame(1, $value);
        $this->assertEmpty($errors);
        $this->assertSame(0, $flags);
    }

    public function testEnableChanged(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_ENABLED, 1, 0);
        $this->assertSame(1, $value);
        $this->assertEmpty($errors);
        $this->assertNotSame(0, $flags);
        $this->assertTrue(($flags & ConfigValidator::SCOPE_ALL) !== 0);
        $this->assertTrue(($flags & ConfigValidator::HTACCESS) !== 0);
    }

    public function testEnableDisabledPurges(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_ENABLED, 0, 1);
        $this->assertSame(0, $value);
        $this->assertTrue(($flags & ConfigValidator::PURGE_DONE) !== 0);
    }

    public function testPublicTtlMinimum(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_PUBLIC_TTL, 100, 86400);
        // Should be clamped to minimum 300
        $this->assertGreaterThanOrEqual(300, $value);
    }

    public function testPrivateTtlRange(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_PRIVATE_TTL, 10, 1800);
        // Should be clamped to minimum 180
        $this->assertGreaterThanOrEqual(180, $value);
    }

    public function testDiffMobileClamp(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_DIFFMOBILE, 5, 0);
        $this->assertLessThanOrEqual(2, $value);
        $this->assertGreaterThanOrEqual(0, $value);
    }

    public function testDiffCustomerGroupClamp(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_DIFFCUSTGRP, 10, 0);
        $this->assertLessThanOrEqual(3, $value);
    }

    public function testVaryBypassValid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_VARY_BYPASS, 'lang, curr', '');
        $this->assertSame('lang, curr', $value);
        $this->assertEmpty($errors);
    }

    public function testVaryBypassInvalid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_VARY_BYPASS, 'lang, invalid', 'orig');
        $this->assertSame('orig', $value);
        $this->assertNotEmpty($errors);
    }

    public function testLoginCookieDefault(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_LOGIN_COOKIE, '', 'old');
        $this->assertSame('_lscache_vary', $value);
        $this->assertEmpty($errors);
    }

    public function testLoginCookieValid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_LOGIN_COOKIE, 'my_cookie_123', 'old');
        $this->assertSame('my_cookie_123', $value);
        $this->assertEmpty($errors);
    }

    public function testLoginCookieInvalid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_LOGIN_COOKIE, 'invalid-cookie!', 'old');
        $this->assertSame('old', $value);
        $this->assertNotEmpty($errors);
    }

    public function testVaryCookiesValid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_VARY_COOKIES, "cookie_a\ncookie_b", '');
        $this->assertSame("cookie_a\ncookie_b", $value);
        $this->assertEmpty($errors);
    }

    public function testVaryCookiesInvalid(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_VARY_COOKIES, "valid\ninvalid-chars!", 'orig');
        $this->assertSame('orig', $value);
        $this->assertNotEmpty($errors);
    }

    public function testFlushProdcatClamp(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_FLUSH_PRODCAT, 10, 0);
        $this->assertLessThanOrEqual(4, $value);
    }

    public function testFlushHomeClamp(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_FLUSH_HOME, 5, 0);
        $this->assertLessThanOrEqual(2, $value);
    }

    public function testNoChangeReturnsZeroFlags(): void
    {
        [$value, $errors, $flags] = $this->validator->validate(Conf::CFG_GUESTMODE, 1, 1);
        $this->assertSame(0, $flags);
    }

    public function testUnknownFieldPassesThrough(): void
    {
        [$value, $errors, $flags] = $this->validator->validate('unknown_field', 'test', 'orig');
        $this->assertSame('test', $value);
        $this->assertEmpty($errors);
        $this->assertSame(0, $flags);
    }
}
