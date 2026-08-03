<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\DevCommands;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\parallel;

class HorizonTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testCspNonceIsNotRenderedWhenNotSet(): void
    {
        $this->assertStringContainsString('<style data-scheme="light">', (string) Horizon::css());
        $this->assertStringContainsString('<style data-scheme="dark">', (string) Horizon::css());
        $this->assertStringContainsString('<style>', (string) Horizon::css());
        $this->assertStringContainsString('<script type="module">', (string) Horizon::js());
    }

    public function testCspNonceIsRenderedAndCanBeReplacedWithinARequest(): void
    {
        Horizon::cspNonce('first');
        Horizon::cspNonce('second');

        $this->assertStringContainsString('<style data-scheme="light" nonce="second">', (string) Horizon::css());
        $this->assertStringContainsString('<style data-scheme="dark" nonce="second">', (string) Horizon::css());
        $this->assertStringContainsString('<style nonce="second">', (string) Horizon::css());
        $this->assertStringContainsString('<script type="module" nonce="second">', (string) Horizon::js());
    }

    public function testCspNonceIsIsolatedBetweenConcurrentRequests(): void
    {
        [$first, $second] = parallel([
            function (): string {
                Horizon::cspNonce('first');
                usleep(5000);

                return (string) Horizon::css();
            },
            function (): string {
                Horizon::cspNonce('second');
                usleep(5000);

                return (string) Horizon::css();
            },
        ]);

        $this->assertStringContainsString(' nonce="first"', $first);
        $this->assertStringNotContainsString(' nonce="second"', $first);
        $this->assertStringContainsString(' nonce="second"', $second);
        $this->assertStringNotContainsString(' nonce="first"', $second);
    }

    public function testHorizonDevelopmentCommandReplacesTheQueueCommand(): void
    {
        $commands = array_column(DevCommands::commands(), null, 'name');

        $this->assertSame('php artisan horizon', $commands['horizon']['command']);
        $this->assertArrayNotHasKey('queue', $commands);
    }
}
