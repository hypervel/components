<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Http\Faking\FakeResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class FakeResponseTest extends TestCase
{
    public function testItCreatesJsonAndStringResponses(): void
    {
        $json = new FakeResponse(['name' => 'Taylor'], 201, ['X-Count' => 2]);
        $text = new FakeResponse('complete');

        $this->assertInstanceOf(JsonBodyRepository::class, $json->body());
        $this->assertSame('{"name":"Taylor"}', (string) $json->createPsrResponse()->getBody());
        $this->assertSame(201, $json->status());
        $this->assertSame(['2'], $json->createPsrResponse()->getHeader('X-Count'));
        $this->assertInstanceOf(StringBodyRepository::class, $text->body());
        $this->assertSame('complete', (string) $text->createPsrResponse()->getBody());
    }

    public function testItResolvesConfiguredExceptionsAgainstThePendingRequest(): void
    {
        $pendingRequest = m::mock(PendingRequest::class);
        $exception = new RuntimeException('Failed.');
        $response = (new FakeResponse)->throw(
            fn (PendingRequest $request): RuntimeException => $request === $pendingRequest
                ? $exception
                : new RuntimeException('Wrong request.'),
        );

        $this->assertSame($exception, $response->getException($pendingRequest));
        $this->assertNull((new FakeResponse)->getException($pendingRequest));
    }
}
