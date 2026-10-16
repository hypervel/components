<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Transport;

use Hypervel\Mcp\Server\Transport\FakeTransporter;
use Hypervel\Tests\TestCase;
use LogicException;

class FakeTransporterTest extends TestCase
{
    public function testThrowsWhenRunningSinceItIsNotImplemented(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Not implemented.');
        $transporter = new FakeTransporter;

        $transporter->run();
    }

    public function testAcceptsOnReceiveHandlerWithoutSideEffects(): void
    {
        $transporter = new FakeTransporter;

        $called = false;
        $transporter->onReceive(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called); // FakeTransporter does not invoke the handler
    }

    public function testSendIsANoOpAndDoesNotThrow(): void
    {
        $transporter = new FakeTransporter;

        $transporter->send('{"ping":true}');

        $this->expectNotToPerformAssertions();
    }

    public function testStreamAcceptsAClosureAndDoesNothing(): void
    {
        $transporter = new FakeTransporter;

        $didRun = false;
        $transporter->stream(function () use (&$didRun): void {
            $didRun = true; // FakeTransporter should not execute this
        });

        $this->assertFalse($didRun);
    }
}
