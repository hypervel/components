<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client;

use Hypervel\Database\Pool\PoolManager;
use Hypervel\Http\Client\Factory;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use PHPUnit\Framework\Attributes\TestWith;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

#[WithConfig('database.connections.wait', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'pool' => ['testing_enabled' => true, 'max_connections' => 1],
])]
class StreamingResponseConnectionReleaseTest extends TestCase
{
    #[TestWith(['idle'])]
    #[TestWith(['transaction'])]
    #[TestWith(['pinned'])]
    public function testWaitingForMoreOfTheBodyReturnsIdleSessionsButPreservesTransactionsAndPins(string $mode): void
    {
        $pool = $this->app->make(PoolManager::class)->pool('wait');
        $queried = new Channel(1);
        $observed = new Channel(1);
        $server = LoopbackHttpServer::start([static function (Socket $client) use ($pool, $queried, $observed): void {
            $client->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 11\r\nConnection: close\r\n\r\nfirst");

            if ($queried->pop(2) === true) {
                // Let the reader reach its network wait before observing the pool.
                usleep(50000);
                $observed->push($pool->getBorrowedCount());
            }

            $client->sendAll('second');
        }]);
        $connection = DB::connection('wait');
        $read = static function () use ($connection, $server, $queried): string {
            $response = (new Factory)->withOptions(['stream' => true])->get('http://127.0.0.1:' . $server->port);

            try {
                $body = $response->toPsrResponse()->getBody();
                $first = $body->read(5);
                $connection->selectOne('select 1');
                $queried->push(true);

                return $first . $body->getContents();
            } finally {
                $response->close();
            }
        };

        $body = match ($mode) {
            'idle' => $read(),
            'transaction' => $connection->transaction($read),
            'pinned' => $connection->withPinnedSession($read),
        };

        $this->assertSame('firstsecond', $body);
        $this->assertSame($mode === 'idle' ? 0 : 1, $observed->pop(1));
    }
}
