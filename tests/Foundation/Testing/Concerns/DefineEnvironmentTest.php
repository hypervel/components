<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DefineEnvironmentTest extends TestCase
{
    protected bool $defineEnvironmentCalled = false;

    protected ?ApplicationContract $passedApp = null;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->defineEnvironmentCalled = true;
        $this->passedApp = $app;

        // Set a config value to verify it takes effect before providers boot
        $app->make('config')->set('testing.define_environment_test', 'configured');
    }

    public function testDefineEnvironmentIsCalledDuringSetUp(): void
    {
        $this->assertTrue($this->defineEnvironmentCalled);
    }

    public function testAppInstanceIsPassed(): void
    {
        $this->assertNotNull($this->passedApp);
        $this->assertInstanceOf(ApplicationContract::class, $this->passedApp);
        $this->assertSame($this->app, $this->passedApp);
    }

    public function testConfigChangesAreApplied(): void
    {
        $this->assertSame(
            'configured',
            $this->app->make('config')->get('testing.define_environment_test')
        );
    }
}
