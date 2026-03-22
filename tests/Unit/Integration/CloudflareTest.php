<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Tests\Unit\Integration;

use LiteSpeed\Cache\Integration\Cloudflare;
use PHPUnit\Framework\TestCase;

class CloudflareTest extends TestCase
{
    public function testGetErrorsWithMessages(): void
    {
        $result = Cloudflare::getErrors([
            'errors' => [
                ['message' => 'Auth fail'],
                ['message' => 'Rate limit'],
            ],
        ]);

        $this->assertSame('Auth fail Rate limit', $result);
    }

    public function testGetErrorsEmpty(): void
    {
        $this->assertSame('Unknown Cloudflare API error.', Cloudflare::getErrors([]));
    }

    public function testGetErrorsNoMessageKey(): void
    {
        $result = Cloudflare::getErrors(['errors' => [['code' => 1000]]]);
        $this->assertSame('', $result);
    }

    public function testGetErrorsSingleMessage(): void
    {
        $result = Cloudflare::getErrors(['errors' => [['message' => 'Forbidden']]]);
        $this->assertSame('Forbidden', $result);
    }
}
