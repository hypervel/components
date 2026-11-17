<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Session\SessionManager;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use SessionHandlerInterface;

#[WithConfig('session.driver', 'custom')]
#[WithConfig('session.lifetime', 7)]
class SessionGarbageCollectCommandTest extends TestCase
{
    use VerifiesDoubles;

    #[TestWith([2, 0, 'ran successfully'], 'sessions removed')]
    #[TestWith([0, 0, 'ran successfully'], 'nothing expired')]
    #[TestWith([false, 1, 'failed'], 'handler failure')]
    public function testRegisteredCommandCollectsTheConfiguredSessionDriver(int|false $result, int $exitCode, string $message): void
    {
        $handler = Double::for(SessionHandlerInterface::class)->strict();
        $handler->expects('gc')->with(420)->returns($result);
        $this->app->make(SessionManager::class)->extend('custom', fn () => $handler);

        $this->artisan('session:garbage-collect')
            ->expectsOutputToContain('Session garbage collector ' . $message . '.')
            ->assertExitCode($exitCode);
    }
}
