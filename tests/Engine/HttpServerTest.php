<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Http\Server;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;

class HttpServerTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testCoroutineOverloadCompletesTheNativeResponse(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $logger = m::mock(LoggerInterface::class);
            $logger->shouldReceive('critical')
                ->once()
                ->with(m::on(fn (string $message): bool => str_contains($message, 'Unable to create coroutine')));
            $response = new OverloadResponse;
            $handled = false;
            $server = new InspectableHttpServer($logger);
            $server->handle(function () use (&$handled): void {
                $handled = true;
            });

            $server->dispatch(new stdClass, $response);

            $this->assertFalse($handled);
            $this->assertSame(503, $response->status);
            $this->assertSame('Service Unavailable', $response->body);
        });
    }
}

class InspectableHttpServer extends Server
{
    public function dispatch(mixed $request, mixed $response): void
    {
        $this->dispatchRequest($request, $response);
    }
}

class OverloadResponse
{
    public ?int $status = null;

    public ?string $body = null;

    public function status(int $status): void
    {
        $this->status = $status;
    }

    public function end(string $body): void
    {
        $this->body = $body;
    }
}
