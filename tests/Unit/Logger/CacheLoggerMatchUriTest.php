<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Tests\Unit\Logger;

use LiteSpeed\Cache\Logger\CacheLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheLoggerMatchUriTest extends TestCase
{
    private \ReflectionMethod $matchUri;

    protected function setUp(): void
    {
        $this->matchUri = new \ReflectionMethod(CacheLogger::class, 'matchUri');
        $this->matchUri->setAccessible(true);

        // Reset singleton
        $r = new \ReflectionClass(CacheLogger::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private function match(string $uri, string $pattern): bool
    {
        return $this->matchUri->invoke(CacheLogger::getInstance(), $uri, $pattern);
    }

    public function testContainsMatch(): void
    {
        $this->assertTrue($this->match('/foo/bar', 'foo'));
    }

    public function testContainsNoMatch(): void
    {
        $this->assertFalse($this->match('/foo/bar', 'baz'));
    }

    public function testStartAnchorMatch(): void
    {
        $this->assertTrue($this->match('/foo/bar', '^/foo'));
    }

    public function testStartAnchorNoMatch(): void
    {
        $this->assertFalse($this->match('/foo/bar', '^/bar'));
    }

    public function testEndAnchorMatch(): void
    {
        $this->assertTrue($this->match('/foo/bar', 'bar$'));
    }

    public function testEndAnchorNoMatch(): void
    {
        $this->assertFalse($this->match('/foo/bar', 'foo$'));
    }

    public function testExactMatch(): void
    {
        $this->assertTrue($this->match('/foo', '^/foo$'));
    }

    public function testExactNoMatch(): void
    {
        $this->assertFalse($this->match('/foo/bar', '^/foo$'));
    }

    public function testEmptyPatternMatchesAnything(): void
    {
        $this->assertTrue($this->match('/foo', ''));
    }

    #[DataProvider('passesDebugFiltersProvider')]
    public function testPassesDebugFilters(array $filters, string $uri, string $message, bool $expected): void
    {
        $instance = CacheLogger::getInstance();

        // Inject filters directly via reflection
        $r = new \ReflectionClass($instance);
        $p = $r->getProperty('debugFilters');
        $p->setAccessible(true);
        $p->setValue($instance, $filters);

        // Set REQUEST_URI
        $_SERVER['REQUEST_URI'] = $uri;

        $method = $r->getMethod('passesDebugFilters');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($instance, $message));
    }

    public static function passesDebugFiltersProvider(): array
    {
        return [
            'no filters passes everything' => [
                ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => ''],
                '/catalog', 'any message', true,
            ],
            'uri include blocks non-matching' => [
                ['uri_inc' => "/admin", 'uri_exc' => '', 'str_exc' => ''],
                '/catalog', 'message', false,
            ],
            'uri include allows matching' => [
                ['uri_inc' => "/admin", 'uri_exc' => '', 'str_exc' => ''],
                '/admin/config', 'message', true,
            ],
            'uri exclude blocks matching' => [
                ['uri_inc' => '', 'uri_exc' => "/health", 'str_exc' => ''],
                '/health', 'message', false,
            ],
            'uri exclude allows non-matching' => [
                ['uri_inc' => '', 'uri_exc' => "/health", 'str_exc' => ''],
                '/catalog', 'message', true,
            ],
            'string exclude blocks matching message' => [
                ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => "smarty"],
                '/any', 'smarty template loaded', false,
            ],
            'string exclude allows non-matching message' => [
                ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => "smarty"],
                '/any', 'cache purge event', true,
            ],
            'multiple uri include lines' => [
                ['uri_inc' => "/admin\n/api", 'uri_exc' => '', 'str_exc' => ''],
                '/api/products', 'message', true,
            ],
            'multiple string excludes' => [
                ['uri_inc' => '', 'uri_exc' => '', 'str_exc' => "debug\nverbose"],
                '/any', 'verbose output here', false,
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
    }
}
