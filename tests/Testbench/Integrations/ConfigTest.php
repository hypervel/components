<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Config;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class ConfigTest extends TestCase
{
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    #[Test]
    public function itLoadsConfigFacade(): void
    {
        $this->assertSame('testbench', Config::get('database.default'));
    }

    #[Test]
    public function itLoadsConfigHelper(): void
    {
        $this->assertSame('testbench', config('database.default'));
    }
}
