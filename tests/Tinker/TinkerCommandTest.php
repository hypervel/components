<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Tinker\Console\TinkerCommand;
use Hypervel\Tinker\TinkerServiceProvider;
use Psy\Configuration;
use Psy\VarDumper\Presenter;
use Symfony\Component\VarDumper\Caster\Caster;

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

    public function testExecuteSuccess(): void
    {
        $this->artisan('tinker', ['--execute' => 'echo "hello";'])
            ->assertExitCode(0);
    }

    public function testExecuteFailure(): void
    {
        $this->artisan('tinker', ['--execute' => 'throw new \Exception("fail");'])
            ->assertExitCode(1);
    }

    public function testExecuteRunsInsideCoroutine(): void
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

    public function testConfiguredCasterIsAppliedByTheTinkerPresenter(): void
    {
        config()->set('tinker.casters', [
            TinkerCommandTestValue::class => TinkerCommandTestCaster::class . '::cast',
        ]);

        /** @var TinkerCommand $command */
        $command = $this->app->make('command.tinker');
        $command->setHypervel($this->app);

        $configuration = new Configuration;
        $configuration->getPresenter()->addCasters(
            (new ClassInvoker($command))->getCasters()
        );

        $output = $configuration->getPresenter()->present(
            new TinkerCommandTestValue,
            options: Presenter::RAW,
        );

        $this->assertStringContainsString('configured caster', $output);
    }
}

class TinkerCommandTestValue
{
}

class TinkerCommandTestCaster
{
    public static function cast(TinkerCommandTestValue $value): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'custom' => 'configured caster',
        ];
    }
}
