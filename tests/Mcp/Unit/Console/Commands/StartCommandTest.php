<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Console\Commands;

use Hypervel\Mcp\Server\Registrar;
use Hypervel\Tests\Mcp\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class StartCommandTest extends TestCase
{
    use VerifiesDoubles;

    private Registrar&DoubleInterface $registrar;

    /**
     * Bind the local server registry double.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = Double::for(Registrar::class)->strict();
        $this->app->instance(Registrar::class, $this->registrar);
    }

    public function testStartsARegisteredLocalServerSuccessfully(): void
    {
        $serverCalled = false;
        $server = function () use (&$serverCalled): void {
            $serverCalled = true;
        };

        $this->registrar->expects('getLocalServer')->with('demo')->returns($server);

        $this->artisan('mcp:start', ['handle' => 'demo'])
            ->assertExitCode(0);

        $this->assertTrue($serverCalled);
    }

    public function testFailsWhenServerHandleIsNotFound(): void
    {
        $this->registrar->expects('getLocalServer')->with('invalid')->returns(null);

        $this->artisan('mcp:start', ['handle' => 'invalid'])
            ->expectsOutputToContain('MCP Server with name [invalid] not found. Did you register it using [Mcp::local()]?')
            ->assertExitCode(1);
    }

    public function testRequiresHandleArgument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "handle")');
        $this->artisan('mcp:start')->run();
    }

    public function testAcceptsAStringHandle(): void
    {
        $server = function (): void {};

        $this->registrar->expects('getLocalServer')->with('test-handle')->returns($server);

        $this->artisan('mcp:start', ['handle' => 'test-handle'])
            ->assertExitCode(0);
    }
}
