<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class HorizonCommandTest extends IntegrationTestCase
{
    public function testAlreadyRunningMasterIsASuccessfulNoOp(): void
    {
        (new MasterSupervisor)->persist();

        $this->artisan('horizon')
            ->expectsOutputToContain('A master supervisor is already running on this machine.')
            ->assertExitCode(0);
    }

    #[DataProvider('environmentProvider')]
    #[RunInSeparateProcess]
    public function testCommandUsesTheSelectedEnvironment(
        ?string $horizonEnvironment,
        ?string $commandEnvironment,
        string $expectedEnvironment,
    ): void {
        config()->set([
            'app.env' => 'application',
            'horizon.env' => $horizonEnvironment,
        ]);

        $barrierReached = false;
        $observedEnvironment = null;

        Coroutine::create(function () use (&$barrierReached, &$observedEnvironment): void {
            try {
                $masters = app(MasterSupervisorRepository::class);
                $deadline = hrtime(true) + 10_000_000_000;

                while (hrtime(true) < $deadline) {
                    if (($master = $masters->find(MasterSupervisor::name())) !== null) {
                        $barrierReached = true;
                        $observedEnvironment = $master->environment;
                        break;
                    }

                    Coroutine::sleep(0.01);
                }
            } finally {
                // Always release the foreground command so a failed barrier cannot hang the test.
                posix_kill(getmypid(), SIGINT);
            }
        });

        $parameters = $commandEnvironment === null ? [] : ['--environment' => $commandEnvironment];

        $this->artisan('horizon', $parameters)->assertExitCode(0);
        $this->assertTrue($barrierReached, 'Horizon master never registered before the SIGINT barrier.');
        $this->assertSame($expectedEnvironment, $observedEnvironment);
    }

    /**
     * Provide Horizon environment precedence cases.
     */
    public static function environmentProvider(): array
    {
        return [
            'application environment' => [null, null, 'application'],
            'Horizon environment' => ['horizon', null, 'horizon'],
            'command option' => ['horizon', 'command', 'command'],
        ];
    }
}
