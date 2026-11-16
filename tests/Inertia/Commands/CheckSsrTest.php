<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Commands;

use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\HasHealthCheck;
use Hypervel\Tests\Inertia\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class CheckSsrTest extends TestCase
{
    use VerifiesDoubles;

    public function testSuccessOnHealthySsrServer(): void
    {
        $mock = Double::for(Gateway::class, HasHealthCheck::class)->strict();
        $mock->allows('isHealthy')->returns(true);
        $this->app->instance(Gateway::class, $mock);

        $this->artisan('inertia:check-ssr')
            ->expectsOutput('Inertia SSR server is running.')
            ->assertExitCode(0);
    }

    public function testFailureOnUnhealthySsrServer(): void
    {
        $mock = Double::for(Gateway::class, HasHealthCheck::class)->strict();
        $mock->allows('isHealthy')->returns(false);
        $this->app->instance(Gateway::class, $mock);

        $this->artisan('inertia:check-ssr')
            ->expectsOutput('Inertia SSR server is not running.')
            ->assertExitCode(1);
    }

    public function testFailureOnUnsupportedGateway(): void
    {
        $this->mock(Gateway::class);

        $this->artisan('inertia:check-ssr')
            ->expectsOutput('The SSR gateway does not support health checks.')
            ->assertExitCode(1);
    }
}
