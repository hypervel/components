<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use OpenTelemetry\Context\Context;

class OperationOriginTest extends TestCase
{
    public function testItStoresAndResolvesOriginsWithItsSharedContextKey(): void
    {
        $origins = new OperationOrigin;
        $context = $origins->withOrigin(Context::getRoot(), OperationOrigin::RPC);

        $this->assertSame(OperationOrigin::RPC, $origins->resolve($context));
        $this->assertNull((new OperationOrigin)->resolve($context));
    }

    public function testItFallsBackToExistingRequestAndWebSocketContext(): void
    {
        $origins = new OperationOrigin;
        $context = Context::getRoot();

        RequestContext::set(Request::create('/'));
        $this->assertSame(OperationOrigin::REQUEST, $origins->resolve($context));

        RequestContext::forget();
        CoroutineContext::set(WebSocketContext::FD, 42);
        $this->assertSame(OperationOrigin::WEBSOCKET, $origins->resolve($context));
    }

    public function testItMapsOnlyTruthfulProcessIdentities(): void
    {
        $origins = new OperationOrigin;
        $context = Context::getRoot();

        $this->assertSame(OperationOrigin::CONSOLE, $origins->resolve($context, ProcessIdentity::cli()));
        $this->assertSame(
            OperationOrigin::PROCESS,
            $origins->resolve($context, ProcessIdentity::serverProcess(self::class, 'relay', 0)),
        );
        $this->assertNull($origins->resolve($context, ProcessIdentity::eventWorker(0)));
        $this->assertNull($origins->resolve($context, ProcessIdentity::taskWorker(1)));
    }
}
