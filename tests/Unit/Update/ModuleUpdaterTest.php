<?php

namespace LiteSpeed\Cache\Tests\Unit\Update;

use LiteSpeed\Cache\Update\ModuleUpdater;
use PHPUnit\Framework\TestCase;

class ModuleUpdaterTest extends TestCase
{
    public function testCleanTagStripsPrefixV(): void
    {
        $this->assertSame('1.6.0', ModuleUpdater::cleanTag('v1.6.0'));
    }

    public function testCleanTagStripsPrefixVDot(): void
    {
        $this->assertSame('1.5.3', ModuleUpdater::cleanTag('v.1.5.3'));
    }

    public function testCleanTagNoPrefix(): void
    {
        $this->assertSame('1.5.0', ModuleUpdater::cleanTag('1.5.0'));
    }

    public function testCleanTagEmptyString(): void
    {
        $this->assertSame('', ModuleUpdater::cleanTag(''));
    }

    public function testClassifyReleaseNewer(): void
    {
        $updater = new ModuleUpdater(sys_get_temp_dir());
        $this->assertSame('newer', $updater->classifyRelease('v1.7.0', '1.6.0'));
    }

    public function testClassifyReleaseCurrent(): void
    {
        $updater = new ModuleUpdater(sys_get_temp_dir());
        $this->assertSame('current', $updater->classifyRelease('v1.6.0', '1.6.0'));
    }

    public function testClassifyReleaseOlder(): void
    {
        $updater = new ModuleUpdater(sys_get_temp_dir());
        $this->assertSame('older', $updater->classifyRelease('v1.5.0', '1.6.0'));
    }

    public function testClassifyReleaseWithDotPrefix(): void
    {
        $updater = new ModuleUpdater(sys_get_temp_dir());
        $this->assertSame('current', $updater->classifyRelease('v.1.6.0', '1.6.0'));
    }
}
