<?php

namespace LiteSpeed\Cache\Tests\Unit\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Controller\Admin\TtlController;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HumanDurationTest extends TestCase
{
    #[DataProvider('durationProvider')]
    public function testHumanDurationReturnsExpectedString(int $seconds, string $expected): void
    {
        $this->assertSame($expected, TtlController::humanDuration($seconds));
    }

    public static function durationProvider(): array
    {
        return [
            'zero'       => [0, 'disabled'],
            'negative'   => [-1, 'disabled'],
            'seconds'    => [30, '30s'],
            'one minute' => [60, '1 minutes'],
            '5 minutes'  => [300, '5 minutes'],
            '1 hour'     => [3600, '1 hours'],
            '2 hours'    => [7200, '2 hours'],
            '1 day'      => [86400, '1 days'],
            '3.5 days'   => [302400, '3.5 days'],
            '1 week'     => [604800, '1 weeks'],
            '2 weeks'    => [1209600, '2 weeks'],
        ];
    }
}
