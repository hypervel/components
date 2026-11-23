<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use PHPUnit\Framework\Assert;

class RedisPingServer
{
    /**
     * Run a diagnostic against a Redis endpoint that answers one PING.
     */
    public static function check(Diagnostic $diagnostic, string $name, string $reply): DiagnosticResult
    {
        $server = new RespServer;
        [$host, $port] = $server->hostAndPort();
        config(['database.redis' => [
            'options' => [],
            $name => ['host' => $host, 'port' => $port, 'timeout' => 2.0, 'read_timeout' => 2.0],
        ]]);
        $server->start(static function ($client) use ($reply): void {
            stream_set_timeout($client, 2);
            $ping = "*1\r\n\$4\r\nPING\r\n";
            Assert::assertSame($ping, RespServer::readExact($client, strlen($ping)));
            fwrite($client, $reply . "\r\n");
        });

        try {
            return $diagnostic->check();
        } finally {
            try {
                Redis::purge($name);
            } finally {
                $server->wait();
            }
        }
    }
}
