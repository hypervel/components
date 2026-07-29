<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
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

    #[RunInSeparateProcess]
    public function testCommandReturnsTheMasterMonitorStatus(): void
    {
        $barrierReached = false;

        Coroutine::create(function () use (&$barrierReached): void {
            try {
                $masters = app(MasterSupervisorRepository::class);
                $deadline = hrtime(true) + 10_000_000_000;

                while (hrtime(true) < $deadline) {
                    if ($masters->find(MasterSupervisor::name()) !== null) {
                        $barrierReached = true;
                        break;
                    }

                    Coroutine::sleep(0.01);
                }
            } finally {
                // Always release the foreground command so a failed barrier cannot hang the test.
                posix_kill(getmypid(), SIGINT);
            }
        });

        $this->artisan('horizon')->assertExitCode(0);
        $this->assertTrue($barrierReached, 'Horizon master never registered before the SIGINT barrier.');
    }
}
