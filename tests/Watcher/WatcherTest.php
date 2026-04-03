<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Tests\TestCase;
use Hypervel\Watcher\Option;

/**
 * @internal
 * @coversNothing
 */
class WatcherTest extends TestCase
{
    public function testOption()
    {
        $config = new Repository([
            'watcher' => [
                'driver' => 'xxx',
                'watch' => [
                    'scan_interval' => 1500,
                ],
            ],
        ]);

        $option = new Option($config->get('watcher'), ['src'], []);

        $this->assertSame('xxx', $option->getDriver());
        $this->assertSame(['app', 'config', 'src'], $option->getWatchDir());
        $this->assertSame(['.env'], $option->getWatchFile());
        $this->assertSame(1500, $option->getScanInterval());
        $this->assertSame(1.5, $option->getScanIntervalSeconds());
    }
}
