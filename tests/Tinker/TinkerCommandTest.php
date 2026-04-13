<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Tinker\TinkerServiceProvider;

/**
 * @internal
 * @coversNothing
 */
class TinkerCommandTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [TinkerServiceProvider::class];
    }

    protected function defineEnvironment(Application $app): void
    {
        // Point to the real vendor directory so the classmap file is found.
        Env::getRepository()->set('COMPOSER_VENDOR_DIR', dirname(__DIR__, 2) . '/vendor');
    }

    public function testExecuteSuccess()
    {
        $this->artisan('tinker', ['--execute' => 'echo "hello";'])
            ->assertExitCode(0);
    }

    public function testExecuteFailure()
    {
        $this->artisan('tinker', ['--execute' => 'throw new \Exception("fail");'])
            ->assertExitCode(1);
    }

    public function testExecuteRunsInsideCoroutine()
    {
        $file = tempnam(sys_get_temp_dir(), 'tinker_coroutine_');

        $code = sprintf(
            "file_put_contents('%s', \\Hypervel\\Coroutine\\Coroutine::inCoroutine() ? 'true' : 'false');",
            addslashes($file)
        );

        $this->artisan('tinker', ['--execute' => $code])
            ->assertExitCode(0);

        $this->assertSame('true', file_get_contents($file));

        unlink($file);
    }
}
