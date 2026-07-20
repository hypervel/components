<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc;

use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;

class GoTlsServerTest extends GrpcIntegrationTestCase
{
    protected int $serverPort = 19523;

    public function testVerifiedTlsConnectionToGrpcGo(): void
    {
        $client = $this->newTestClient([
            ...$this->tlsOptions(),
            'timeout' => 2.0,
        ]);

        $this->assertSame(
            'unary:secure',
            $client->unary((new TestRequest)->setValue('secure'))->wait()->getValue(),
        );
    }

    public function testTlsRejectsAnInvalidServerNameFromGrpcGo(): void
    {
        $options = $this->tlsOptions();
        $options['tls']['server_name'] = 'invalid.example';
        $options['timeout'] = 2.0;
        $client = $this->newTestClient($options);

        $this->expectException(ConnectionException::class);
        $client->unary((new TestRequest)->setValue('secure'))->wait();
    }
}
