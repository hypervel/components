<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Grpc\Health\HealthService;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

class HealthProtocolParityTest extends TestCase
{
    public function testEveryProtocolRpcHasAuthoredClientServiceAndRouteSupport(): void
    {
        $repositoryRoot = dirname(__DIR__, 2);
        $protocol = file_get_contents(
            $repositoryRoot . '/src/grpc/resources/proto/grpc/health/v1/health.proto',
        );
        $routes = file_get_contents($repositoryRoot . '/src/grpc/stubs/grpc.php');
        $rpcCount = preg_match_all('/^\s*rpc\b/m', $protocol);
        $matchedRpcCount = preg_match_all(
            '/^\s*rpc\s+(?<rpc>[A-Za-z_]\w*)\s*\(\s*(?<request_stream>stream\s+)?[^)]+\)\s*returns\s*\(\s*(?<response_stream>stream\s+)?[^)]+\)\s*;/m',
            $protocol,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertGreaterThan(0, $rpcCount);
        $this->assertSame($rpcCount, $matchedRpcCount, 'Every health protocol RPC must be recognized by the parity test.');
        $this->assertSame(
            $rpcCount,
            preg_match_all('/^\s*Grpc::(unary|clientStream|serverStream|bidiStream)\(/m', $routes),
            'The published gRPC route stub must register exactly the protocol RPCs.',
        );

        foreach ($matches as $match) {
            $rpc = $match['rpc'];
            $method = lcfirst($rpc);
            $requestStreams = ($match['request_stream'] ?? '') !== '';
            $responseStreams = ($match['response_stream'] ?? '') !== '';
            $routeMethod = match ([$requestStreams, $responseStreams]) {
                [false, false] => 'unary',
                [true, false] => 'clientStream',
                [false, true] => 'serverStream',
                [true, true] => 'bidiStream',
            };

            $this->assertTrue(
                method_exists(HealthClient::class, $method),
                "HealthClient must implement the [{$rpc}] RPC.",
            );
            $this->assertTrue(
                (new ReflectionMethod(HealthClient::class, $method))->isPublic(),
                "HealthClient::{$method}() must be public.",
            );
            $this->assertTrue(
                method_exists(HealthService::class, $method),
                "HealthService must implement the [{$rpc}] RPC.",
            );
            $this->assertTrue(
                (new ReflectionMethod(HealthService::class, $method))->isPublic(),
                "HealthService::{$method}() must be public.",
            );
            $this->assertStringContainsString(
                "Grpc::{$routeMethod}('{$rpc}', [HealthService::class, '{$method}']);",
                $routes,
                "The published gRPC route stub must register the [{$rpc}] RPC.",
            );
        }
    }
}
