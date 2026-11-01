<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Aws\Result;
use Closure;
use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectorInterface;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Http\V2\Response as Http2Response;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Client\Events\ResponseReceived;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Request;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Messages\SlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager as ObjectPoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Queue\ClearableQueuePoolProxy;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClient;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClientFactory;
use Hypervel\Tests\Socialite\Fixtures\GenericTestProviderStub;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Socket;
use Symfony\Component\Process\InputStream;
use Throwable;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class DatabaseConnectionLeaseTest extends TestCase
{
    /**
     * Configure a single physical slot shared by independently owned connections.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.leases', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 1.0,
                'events' => [ConnectionReleasing::class],
            ],
        ]);
        $app->make('config')->set('database.connections.physical_leases', [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => ['host' => 'replica'],
            'write' => ['host' => 'primary'],
            'sticky' => true,
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->make('config')->set('database.connections.identity_leases', [
            'driver' => 'pgsql',
            'database' => 'app',
            'sticky' => true,
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->make('config')->set('database.connections.insert_id_leases', [
            'driver' => 'mysql',
            'database' => 'app',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->instance('db.connector.pgsql', new LeasePdoConnector);
        $app->instance('db.connector.mysql', new LeasePdoConnector);
        $app->instance('db.connector.mariadb', new LeasePdoConnector);
    }

    public function testRetainedBuildersAndCallerStateSurviveAnotherBorrower(): void
    {
        $connection = DB::connection('leases');
        $schema = $connection->getSchemaBuilder();
        $schema->create('records', static fn (Blueprint $table) => $table->integer('id'));
        $builder = $connection->table('records');
        $builder->insert(['id' => 7]);
        $connection->enableQueryLog();
        $queries = 0;
        $connection->beforeExecuting(static function () use (&$queries): void {
            ++$queries;
        });

        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());

        [$other] = parallel([static function (): Connection {
            $connection = DB::connection('leases');
            $connection->selectOne('select 1');
            $connection->setTablePrefix('other_');
            $connection->setDatabaseName('other');

            return $connection;
        }]);

        $this->assertNotSame($connection, $other);
        $this->assertSame($connection, DB::connection('leases'));
        $this->assertSame('', $connection->getTablePrefix());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame(7, $builder->value('id'));
        $this->assertTrue($schema->hasTable('records'));
        $this->assertTrue($connection->hasModifiedRecords());
        $this->assertTrue($connection->logging());
        $this->assertGreaterThanOrEqual(2, $queries);
        $this->assertCount($queries, $connection->getQueryLog());
    }

    public function testConcurrentExternalWaitsDoNotRetainTheOnlyPoolSlot(): void
    {
        $ready = new Channel(2);
        $resume = new Channel(2);
        $waitForProvider = static function () use ($ready, $resume): int {
            $connection = DB::connection('leases');
            $value = $connection->selectOne('select 1 as value')->value;
            DB::releaseIdleConnections();
            $ready->push(true);

            if ($resume->pop(3.0) !== true) {
                throw new RuntimeException('The simulated provider was not resumed.');
            }

            return $value + $connection->selectOne('select 2 as value')->value;
        };

        $results = parallel([
            $waitForProvider,
            $waitForProvider,
            function () use ($ready, $resume): void {
                try {
                    $this->assertTrue($ready->pop(3.0));
                    $this->assertTrue($ready->pop(3.0));
                    $this->assertSame(1, $this->pool()->getIdleCount());
                } finally {
                    $resume->push(true);
                    $resume->push(true);
                }
            },
        ]);

        $this->assertSame([3, 3, null], $results);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    public function testConcurrentHttpRequestsReleaseSessionsBeforeWaitingForHeaders(): void
    {
        $server = new Socket(AF_INET, SOCK_STREAM, 0);
        $this->assertTrue($server->bind('127.0.0.1', 0));
        $this->assertTrue($server->listen());
        $url = 'http://127.0.0.1:' . $server->getsockname()['port'];
        $request = function () use ($url): int {
            $connection = DB::connection('leases');
            $value = $connection->selectOne('select 1 as value')->value;
            $response = (new Factory)->timeout(3)->beforeSending(function () use ($connection): void {
                $connection->selectOne('select 1');
                $this->assertSame(0, $this->pool()->getIdleCount());
            })->get($url);
            $this->assertSame('OK', $response->body());
            $this->assertSame($connection, DB::connection('leases'));

            return $value + $connection->selectOne('select 2 as value')->value;
        };

        try {
            $results = parallel([
                $request,
                $request,
                function () use ($server): void {
                    $clients = [];

                    try {
                        for ($index = 0; $index < 2; ++$index) {
                            $client = $server->accept(3);
                            $this->assertInstanceOf(Socket::class, $client);
                            $clients[] = $client;
                            $headers = '';

                            while (! str_contains($headers, "\r\n\r\n")) {
                                $chunk = $client->recv(timeout: 3);
                                $this->assertIsString($chunk);
                                $this->assertNotSame('', $chunk);
                                $headers .= $chunk;
                            }
                        }

                        $this->assertSame(1, $this->pool()->getIdleCount());
                        $this->assertSame(9, DB::connection('leases')->selectOne('select 9 as value')->value);

                        foreach ($clients as $client) {
                            $client->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
                        }
                    } finally {
                        foreach ($clients as $client) {
                            $client->close();
                        }
                    }
                },
            ]);
        } finally {
            $server->close();
        }

        $this->assertSame([3, 3, null], $results);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSocialiteReleasesForItsDefaultTransportWithoutChangingCustomHandlers(bool $custom): void
    {
        $handler = function () use ($custom): PromiseInterface {
            $this->assertSame($custom ? 0 : 1, $this->pool()->getIdleCount());

            return Create::promiseFor(new Response(200, [], 'OK'));
        };
        $provider = new GenericTestProviderStub(Request::create('/'), $custom ? [
            'handler' => HandlerStack::create($handler),
        ] : ['max_host_connections' => 1]);
        $client = $provider->getProviderHttpClient();
        if (! $custom) {
            $client->getConfig('handler')->setHandler($handler);
        }

        DB::connection('leases')->selectOne('select 1');

        $this->assertSame('OK', (string) $client->get('https://identity.example/user')->getBody());
    }

    public function testSlackWebhookReleasesAfterPreparingTheNotification(): void
    {
        $this->app->register(SlackChannelServiceProvider::class);
        $channel = $this->app->make(SlackWebhookChannel::class);
        $client = (fn (): Client => $this->http)->call($channel);
        $client->getConfig('handler')->setHandler(function (): PromiseInterface {
            $this->assertSame(1, $this->pool()->getIdleCount());

            return Create::promiseFor(new Response(200, [], 'ok'));
        });
        $notifiable = new class {
            /**
             * Route the notification to the test webhook.
             */
            public function routeNotificationFor(string $driver, Notification $notification): string
            {
                return 'https://hooks.slack.example/services/test';
            }
        };
        $notification = new class extends Notification {
            /**
             * Build the message after querying its application data.
             */
            public function toSlack(mixed $notifiable): SlackMessage
            {
                DB::connection('leases')->selectOne('select 1');

                return (new SlackMessage)->content('Message');
            }
        };

        $this->assertSame('ok', (string) $channel->send($notifiable, $notification)->getBody());
    }

    public function testSqsCommandsReleaseIdleDatabaseSessions(): void
    {
        $queue = (new SqsConnector)->connect([
            'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/test',
            'region' => 'us-east-1',
            'key' => 'test',
            'secret' => 'test',
            'handler' => function (): PromiseInterface {
                $this->assertSame(1, $this->pool()->getIdleCount());

                return Create::promiseFor(new Result(['MessageId' => 'message']));
            },
        ]);
        DB::connection('leases')->selectOne('select 1');

        $this->assertSame('message', $queue->pushRaw('payload'));
    }

    public function testQueueAdmissionReleasesBeforeBorrowingTheBackend(): void
    {
        $pools = new ObjectPoolManager;
        $proxy = new ClearableQueuePoolProxy(
            new PoolDefinition('queue:release-test', 'sqs', 'test', PoolOptions::fromArray([])),
            function (): SqsQueue {
                $this->assertSame(1, $this->pool()->getIdleCount());

                return (new SqsConnector)->connect([
                    'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/test',
                    'region' => 'us-east-1',
                    'key' => 'test',
                    'secret' => 'test',
                    'handler' => static fn (): PromiseInterface => Create::promiseFor(new Result([
                        'Attributes' => ['ApproximateNumberOfMessages' => '2'],
                    ])),
                ]);
            },
            $pools,
        );
        DB::connection('leases')->selectOne('select 1');

        try {
            $this->assertSame(2, $proxy->pendingSize());
        } finally {
            $pools->purgeAll();
        }
    }

    public function testGrpcCallStartupReturnsIdleDatabaseSessions(): void
    {
        $engineClient = new ClientCallClient;
        $this->app->instance(ClientFactoryInterface::class, new ClientCallClientFactory($engineClient));
        $client = new HealthClient('health.example.test:50051');
        DB::connection('leases')->selectOne('select 1');

        try {
            $client->check(new HealthCheckRequest);

            $this->assertCount(1, $engineClient->sentRequests);
            $this->assertSame(1, $this->pool()->getIdleCount());
        } finally {
            $client->close();
        }
    }

    public function testAnotherCoroutineCanQueryDuringGrpcRetryBackoff(): void
    {
        $engineClient = new ClientCallClient;
        $this->app->instance(ClientFactoryInterface::class, new ClientCallClientFactory($engineClient));
        $client = new HealthClient('health.example.test:50051');
        $ready = new Channel(1);

        try {
            $call = $client->check(new HealthCheckRequest, options: [
                'retry' => new RetryPolicy(2, initialBackoff: 60, maxBackoff: 60),
            ]);
            $engineClient->respond(new Http2Response(1, 200, [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string) StatusCode::Unavailable->value,
                'grpc-retry-pushback-ms' => '60000',
            ], '', false));

            $results = parallel([
                static function () use ($call, $ready): StatusCode {
                    DB::connection('leases')->selectOne('select 1');
                    $ready->push(true);

                    return $call->status()->code();
                },
                function () use ($call, $ready): int {
                    try {
                        $this->assertTrue($ready->pop(3));

                        return DB::connection('leases')->selectOne('select 2 as value')->value;
                    } finally {
                        $call->cancel();
                    }
                },
            ]);

            $this->assertSame([StatusCode::Cancelled, 2], $results);
            $this->assertCount(1, $engineClient->sentRequests);
        } finally {
            $client->close();
        }
    }

    public function testHttpRetriesAndRedirectsReleaseSessionsAcquiredByRequestCallbacks(): void
    {
        $calls = 0;
        $response = (new Factory)->retry(2, 0)->beforeSending(function (): void {
            DB::connection('leases')->selectOne('select 1');
            $this->assertSame(0, $this->pool()->getIdleCount());
        })->setHandler(function () use (&$calls): PromiseInterface {
            $this->assertSame(1, $this->pool()->getIdleCount());

            return Create::promiseFor(match (++$calls) {
                1 => new Response(500),
                2 => new Response(302, ['Location' => 'http://example.test/redirected']),
                default => new Response(200, body: 'OK'),
            });
        })->get('http://example.test');

        $this->assertSame(3, $calls);
        $this->assertSame('OK', $response->body());
    }

    public function testRetryWaitReleasesSessionsAfterResponseListenersAndDelayCallbacks(): void
    {
        $observed = [];
        $connection = DB::connection('leases');
        $pool = $this->pool();
        Event::listen(ResponseReceived::class, static function () use ($connection): void {
            $connection->selectOne('select 1');
        });
        Http::fake(['*' => Http::sequence()
            ->pushStatus(500)->pushStatus(200)
            ->pushStatus(500)->pushStatus(200)
            ->pushStatus(500)->pushStatus(200)]);
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($pool, &$observed): void {
            $observed[] = $pool->getBorrowedCount();
        });
        $send = function () use ($connection): void {
            $response = Http::retry(2, static function () use ($connection): int {
                $connection->selectOne('select 2');

                return 1;
            })->get('https://example.com/retry');

            $this->assertTrue($response->successful());
        };

        $send();
        $connection->transaction($send);
        $connection->withPinnedSession($send);

        $this->assertSame([0, 1, 1], $observed);
    }

    #[TestWith(['run'])]
    #[TestWith(['wait'])]
    #[TestWith(['waitUntil'])]
    public function testAnotherCoroutineCanQueryWhileAProcessWaitsForInput(string $method): void
    {
        $ready = new Channel(1);
        $input = new InputStream;
        $command = [PHP_BINARY, '-r', 'fgets(STDIN); echo "READY\n"; fflush(STDOUT); fgets(STDIN); echo "DONE\n";'];
        $output = static function (string $type, string $buffer) use ($ready): bool {
            if (str_contains($buffer, 'READY')) {
                $ready->push(true);
            }

            return str_contains($buffer, 'DONE');
        };

        $results = parallel([
            static function () use ($method, $input, $command, $output): string {
                DB::connection('leases')->selectOne('select 1');
                $pending = (new ProcessFactory)->input($input)->timeout(3);
                $process = null;

                try {
                    if ($method === 'run') {
                        $input->write("start\n");
                        $result = $pending->run($command, $output);
                    } else {
                        $process = $pending->start($command);
                        $input->write("start\n");
                        $result = $process->{$method}($output);
                    }

                    return $result->output();
                } finally {
                    $input->close();
                    $process?->stop(0);
                }
            },
            function () use ($ready, $input): int {
                try {
                    $this->assertTrue($ready->pop(3));

                    return DB::connection('leases')->selectOne('select 2 as value')->value;
                } finally {
                    if (! $input->isClosed()) {
                        $input->write("finish\n");
                        $input->close();
                    }
                }
            },
        ]);

        $this->assertSame(["READY\nDONE\n", 2], $results);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAnotherCoroutineCanQueryDuringGracefulProcessShutdown(): void
    {
        $ready = new Channel(1);
        $input = new InputStream;

        $results = parallel([
            function () use ($ready, $input): ?int {
                $process = (new ProcessFactory)->input($input)->timeout(3)->start([
                    'sh', '-c', 'trap "" TERM; read first; printf "READY\n"; read second;',
                ]);

                try {
                    $input->write("start\n");
                    $response = $process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'READY'));
                    $this->assertSame("READY\n", $response->output());
                    DB::connection('leases')->selectOne('select 1');
                    $ready->push(true);

                    return $process->stop(3);
                } finally {
                    $input->close();
                    $process->stop(0);
                }
            },
            function () use ($ready, $input): int {
                try {
                    $this->assertTrue($ready->pop(3));

                    return DB::connection('leases')->selectOne('select 2 as value')->value;
                } finally {
                    if (! $input->isClosed()) {
                        $input->write("finish\n");
                        $input->close();
                    }
                }
            },
        ]);

        $this->assertSame([0, 2], $results);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    #[DataProvider('pinnedScopes')]
    public function testSessionDependentScopesPreventEarlyRelease(string $scope, string $name): void
    {
        $connection = DB::connection($name);
        $pool = $this->app->make(PoolManager::class)->pool($name);
        $check = function () use ($connection, $pool): void {
            $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
            DB::releaseIdleConnections();
            $this->assertSame(0, $pool->getIdleCount());
            (new Factory)->setHandler(function () use ($pool): PromiseInterface {
                $this->assertSame(0, $pool->getIdleCount());

                return Create::promiseFor(new Response(200));
            })->get('http://example.test');
        };

        if (in_array($scope, ['native transaction', 'SQL transaction', 'read transaction'], true)) {
            $pdo = $scope === 'read transaction' ? $connection->getReadPdo() : $connection->getPdo();

            if ($scope === 'SQL transaction') {
                $pdo->exec('BEGIN');
            } else {
                $pdo->beginTransaction();
            }

            try {
                $check();
            } finally {
                $pdo->rollBack();
            }
        } else {
            match ($scope) {
                'explicit' => $connection->withPinnedSession(
                    fn () => $connection->withPinnedSession($check)
                ),
                'transaction' => $connection->transaction($check),
                'foreign keys' => $connection->getSchemaBuilder()->withoutForeignKeyConstraints($check),
            };
        }

        DB::releaseIdleConnections();
        $this->assertSame(1, $pool->getIdleCount());
    }

    /**
     * Provide scopes whose work requires the same physical session.
     */
    public static function pinnedScopes(): array
    {
        return [
            ['explicit', 'leases'],
            ['transaction', 'leases'],
            ['foreign keys', 'leases'],
            ['native transaction', 'leases'],
            ['SQL transaction', 'leases'],
            ['read transaction', 'physical_leases'],
        ];
    }

    public function testAnAbandonedCursorReleasesItsPin(): void
    {
        $connection = DB::connection('leases');
        $cursor = $connection->cursor('select 1 as value union all select 2');
        $this->assertSame(1, $cursor->current()->value);
        DB::releaseIdleConnections();
        $this->assertSame(0, $this->pool()->getIdleCount());

        unset($cursor);
        DB::releaseIdleConnections();

        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    public function testConnectionAndQueryListenersKeepTheLogicalOwnerAttached(): void
    {
        $established = null;
        $released = null;
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$established): void {
            if ($event->connection->getName() !== 'leases') {
                return;
            }

            $established = $event->connection;
            DB::releaseIdleConnections();
            $this->assertSame(0, $this->pool()->getIdleCount());
            $this->assertSame(1, $event->connection->selectOne('select 1 as value')->value);
        });
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if ($event->connection->getName() === 'leases') {
                DB::releaseIdleConnections();
                $this->assertSame(0, $this->pool()->getIdleCount());
            }
        });
        Event::listen(ConnectionReleasing::class, function (ConnectionReleasing $event) use (&$released): void {
            $released = $event->connection->getConnection();
            $this->assertSame(2, $released->selectOne('select 2 as value')->value);
        });

        $connection = DB::connection('leases');
        DB::releaseIdleConnections();

        $this->assertSame($connection, $established);
        $this->assertSame($connection, $released);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    public function testAnEagerPdoResolverCanConstructTheLogicalDriver(): void
    {
        Connection::resolverFor(
            'sqlite',
            static fn (PDO|Closure $pdo, string $database, string $prefix, array $config): LeaseSqliteConnection => new LeaseSqliteConnection($pdo instanceof Closure ? $pdo() : $pdo, $database, $prefix, $config)
        );

        $connection = DB::connection('leases');
        $this->assertInstanceOf(LeaseSqliteConnection::class, $connection);
        $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    #[DataProvider('differentConfiguredIdentities')]
    public function testDifferentConfiguredIdentitiesRetainWholeConnectionOwnership(string $name, string $role, array $records): void
    {
        config()->set('database.connections.identity_leases.read', ['host' => 'replica']);
        config()->set('database.connections.identity_leases.write', ['host' => 'primary']);
        config()->set("database.connections.identity_leases.{$role}", $records);
        $connection = DB::connection($name);
        $pool = $this->app->make(PoolManager::class)->pool($name);
        $connection->statement('create table ' . $connection->getTablePrefix() . 'records (id integer)');
        $builder = $connection->table('records');
        $builder->insert(['id' => 7]);

        DB::releaseIdleConnections();

        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame($connection, DB::connection($name));
        $this->assertSame(7, $builder->value('id'));
    }

    /**
     * Provide pools whose selectable endpoints need different logical connections.
     */
    public static function differentConfiguredIdentities(): array
    {
        $prefixes = [['prefix' => 'first_'], ['prefix' => 'second_']];
        $drivers = [['driver' => 'mysql'], ['driver' => 'mariadb']];
        $databases = [['database' => 'first'], ['database' => 'second']];

        return [
            'read prefixes' => ['identity_leases::read', 'read', $prefixes],
            'write prefixes' => ['identity_leases', 'write', $prefixes],
            'read drivers' => ['identity_leases::read', 'read', $drivers],
            'write drivers' => ['identity_leases', 'write', $drivers],
            'read databases' => ['identity_leases::read', 'read', $databases],
            'write databases' => ['identity_leases', 'write', $databases],
        ];
    }

    #[DataProvider('reconnectedPdoRoles')]
    public function testConnectionListenerReconnectKeepsTheReplacementPdo(bool $read): void
    {
        $connection = DB::connection('physical_leases');
        $connection->disconnect();
        DB::releaseIdleConnections();
        $replacement = null;
        $original = null;
        $reconnected = false;

        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($read, &$original, &$replacement, &$reconnected): void {
            if ($event->connection->getName() === 'physical_leases' && ! $reconnected) {
                $reconnected = true;
                $original = $read ? $event->connection->getRawReadPdo() : $event->connection->getRawPdo();
                $event->connection->reconnect();

                if (! $read) {
                    $replacement = $event->connection->getPdo();
                }
            }
        });

        $resolved = $read ? $connection->getReadPdo() : $connection->getPdo();

        $this->assertInstanceOf(PDO::class, $original);
        $this->assertNotSame($original, $resolved);

        if (! $read) {
            $this->assertSame($replacement, $resolved);
        }

        $this->assertSame(1, $this->app->make(PoolManager::class)->pool('physical_leases')->getBorrowedCount());
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->app->make(PoolManager::class)->pool('physical_leases')->getIdleCount());
    }

    /**
     * Provide the physical handle resolved when a connection listener reconnects.
     */
    public static function reconnectedPdoRoles(): array
    {
        return ['write' => [false], 'read' => [true]];
    }

    public function testDisconnectDropsThePhysicalPdoAndReusesTheLogicalConnection(): void
    {
        $connection = DB::connection('physical_leases');
        $pdo = WeakReference::create($connection->getPdo());
        $readPdo = WeakReference::create($connection->getReadPdo());
        $this->assertNotSame($pdo->get(), $readPdo->get());

        DB::disconnect('physical_leases');

        $this->assertNull($pdo->get());
        $this->assertNull($readPdo->get());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
        $this->assertSame($connection, DB::connection('physical_leases'));
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    public function testReconnectReleasesWarningHandlesThroughThePhysicalConnection(): void
    {
        $this->app->instance('db.connector.pgsql', new class extends LeasePdoConnector {
            private bool $first = true;

            /**
             * Make the first physical handle warn when it is released.
             */
            public function connect(array $config): PDO
            {
                if (! $this->first) {
                    return parent::connect($config);
                }

                $this->first = false;

                return new class('sqlite::memory:') extends PDO {
                    /**
                     * Simulate a native teardown warning.
                     */
                    public function __destruct()
                    {
                        trigger_error('SSL: Broken pipe', E_USER_WARNING);
                    }
                };
            }
        });

        $connection = DB::connection('physical_leases');
        $original = WeakReference::create($connection->getPdo());

        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            if (error_reporting() & $severity) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        });

        try {
            $connection->reconnect();

            $this->assertNull($original->get());
            $this->assertSame(2, $connection->selectFromWriteConnection('select 2 as value')[0]->value);
        } finally {
            restore_error_handler();
        }
    }

    public function testCapturedMySqlInsertIdsSurviveEarlyRelease(): void
    {
        $connection = DB::connection('insert_id_leases');
        $connection->statement('create table records (id integer primary key)');
        $connection->insert('insert into records default values');

        DB::releaseIdleConnections();

        $this->assertSame('1', $connection->getLastInsertId());
        $this->assertSame(1, $this->app->make(PoolManager::class)->pool('insert_id_leases')->getIdleCount());
        $connection->insert('insert into records default values');
        $this->assertSame('2', $connection->getLastInsertId());
    }

    public function testReadWriteRolesAndStickyRoutingSurviveEarlyRelease(): void
    {
        $connection = DB::connection('physical_leases');
        $this->assertNotSame($connection->getPdo(), $connection->getReadPdo());
        $connection->statement('create table records (id integer)');

        DB::releaseIdleConnections();

        [$otherWasSticky] = parallel([static function (): bool {
            $other = DB::connection('physical_leases');

            return $other->getPdo() === $other->getReadPdo();
        }]);

        $this->assertFalse($otherWasSticky);
        $this->assertSame($connection->getPdo(), $connection->getReadPdo());
        DB::releaseIdleConnections();

        $read = DB::connection('physical_leases::read');
        $write = DB::connection('physical_leases::write');
        $this->assertSame('replica', $read->getConfig('host'));
        $this->assertSame('primary', $write->getConfig('host'));
        $this->assertSame($write->getPdo(), $write->getReadPdo());
        DB::releaseIdleConnections();

        $this->assertSame(1, $read->selectOne('select 1 as value')->value);
        $this->assertSame(2, $write->selectOne('select 2 as value')->value);
        $this->assertSame('replica', $read->getConfig('host'));
        $this->assertSame('primary', $write->getConfig('host'));
    }

    #[DataProvider('writeServers')]
    public function testWritesKeepTheirSessionWhileTheyMayReachDifferentServers(string $name, array $servers, bool $retained): void
    {
        config()->set('database.connections.affinity_leases', [
            'driver' => 'pgsql',
            'database' => 'app',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
            ...$servers,
        ]);
        $connection = DB::connection($name);
        $pool = $this->app->make(PoolManager::class)->pool($name);

        $connection->selectOne('select 1');
        DB::releaseIdleConnections();
        $this->assertSame(1, $pool->getIdleCount());

        $connection->statement('create table records (id integer)');
        DB::releaseIdleConnections();
        $this->assertSame($retained ? 0 : 1, $pool->getIdleCount());

        $connection->forgetRecordModificationState();
        DB::releaseIdleConnections();
        $this->assertSame(1, $pool->getIdleCount());
    }

    /**
     * Provide write candidates that do and do not span different servers.
     */
    public static function writeServers(): array
    {
        return [
            'write records' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => [['host' => 'first'], ['host' => 'second']]], true],
            'write host list' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => ['host' => ['first', 'second']]], true],
            'connection host list' => ['affinity_leases', ['host' => ['first', 'second']], true],
            'write ports' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => [['host' => 'primary', 'port' => 5432], ['host' => 'primary', 'port' => 5433]]], true],
            'PostgreSQL route ports' => ['affinity_leases', ['host' => 'primary', 'port' => 5432, 'read' => ['host' => 'replica'], 'write' => [['connect_via_port' => 6432], ['connect_via_port' => 6433]]], true],
            'hostless PostgreSQL ports' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => [['port' => 5432], ['port' => 5433]]], true],
            'PostgreSQL ignores MySQL socket setting' => ['affinity_leases', ['unix_socket' => '/tmp/unused.sock', 'read' => ['host' => 'replica'], 'write' => [['host' => 'first'], ['host' => 'second']]], true],
            'PostgreSQL route databases' => ['affinity_leases', ['host' => 'primary', 'read' => ['host' => 'replica'], 'write' => [['connect_via_database' => 'first'], ['connect_via_database' => 'second']]], true],
            'read alias records' => ['affinity_leases::read', ['read' => [['host' => 'first'], ['host' => 'second']], 'write' => ['host' => 'primary']], true],
            'write sockets' => ['affinity_leases', ['driver' => 'mysql', 'read' => ['unix_socket' => '/tmp/replica.sock'], 'write' => [['unix_socket' => '/tmp/first.sock'], ['unix_socket' => '/tmp/second.sock']]], true],
            'same server twice' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => [['host' => 'primary'], ['host' => 'primary']]], false],
            'same socket twice' => ['affinity_leases', ['driver' => 'mysql', 'read' => ['unix_socket' => '/tmp/replica.sock'], 'write' => [['unix_socket' => '/tmp/primary.sock'], ['unix_socket' => '/tmp/primary.sock']]], false],
            'single server' => ['affinity_leases', ['read' => ['host' => 'replica'], 'write' => ['host' => 'primary']], false],
            'varying reads only' => ['affinity_leases', ['read' => [['host' => 'first'], ['host' => 'second']], 'write' => ['host' => 'primary']], false],
        ];
    }

    #[DataProvider('configFirstExtensions')]
    public function testConfigFirstExtensionsRetainWholeConnectionOwnership(bool $read, bool $listed): void
    {
        $constructions = 0;
        $created = null;

        if ($read) {
            $record = ['driver' => 'custom_read'];
            config(['database.connections.physical_leases.read' => $listed ? [$record] : $record]);
        }

        DB::extend($read ? 'custom_read' : 'physical_leases', static function (array $config) use (&$constructions, &$created): PostgresConnection {
            ++$constructions;

            return $created = new PostgresConnection(new PDO('sqlite::memory:'), $config['database'], '', $config);
        });
        $name = $read ? 'physical_leases::read' : 'physical_leases';
        $connection = DB::connection($name);
        $connection->selectOne('select 1');

        DB::releaseIdleConnections();

        $pool = $this->app->make(PoolManager::class)->pool($name);
        $this->assertFalse($pool->usesSessionLeases());
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame($created, $connection);
        $this->assertSame($connection, DB::connection($name));
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
        $this->assertSame(1, $constructions);
    }

    /**
     * Provide named and effective read-driver extensions.
     */
    public static function configFirstExtensions(): array
    {
        return [
            'named PDO extension' => [false, false],
            'single read PDO extension' => [true, false],
            'listed read PDO extension' => [true, true],
        ];
    }

    public function testSessionCapabilitiesAreResolvedAgainAfterEarlyRelease(): void
    {
        Connection::resolverFor(
            'pgsql',
            static fn (PDO|Closure $pdo, string $database, string $prefix, array $config): LeaseCapabilityConnection => new LeaseCapabilityConnection($pdo, $database, $prefix, $config)
        );
        $connection = DB::connection('physical_leases');
        $connection->getPdo()->exec('pragma user_version = 100');
        $this->assertSame(100, $connection->maxBindings());

        DB::releaseIdleConnections();

        parallel([static function (): void {
            DB::connection('physical_leases')->getPdo()->exec('pragma user_version = 200');
        }]);

        $this->assertSame(200, $connection->maxBindings());
    }

    public function testSessionConfiguratorsRunOnTheLogicalOwnerAfterEachBorrowerChangesState(): void
    {
        $configurator = new LeaseSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        CoroutineContext::set('__lease_test.account', '1');
        $connection = DB::connection('leases');
        $this->assertSame(1, $connection->selectOne('pragma user_version')->user_version);
        DB::releaseIdleConnections();

        [$other] = parallel([function (): Connection {
            CoroutineContext::set('__lease_test.account', '2');
            $connection = DB::connection('leases');
            $this->assertSame(2, $connection->selectOne('pragma user_version')->user_version);

            return $connection;
        }]);

        $this->assertSame(1, $connection->selectOne('pragma user_version')->user_version);
        $this->assertSame([
            [spl_object_id($connection), '1'],
            [spl_object_id($other), '2'],
            [spl_object_id($connection), '1'],
        ], $configurator->applied);
    }

    #[DataProvider('connectionListenerFailures')]
    public function testConnectionListenerFailuresSettleTheLeaseOnce(bool $reacquire, bool $cancel): void
    {
        $connection = null;

        if ($reacquire) {
            $connection = DB::connection('leases');
            $connection->disconnect();
            DB::releaseIdleConnections();
        }

        $failure = $cancel ? new CanceledException('listener canceled') : new RuntimeException('listener failed');
        $fail = true;
        $retained = null;
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($failure, &$fail, &$retained): void {
            if ($event->connection->getName() === 'leases' && $fail) {
                $retained = $event->connection;
                $fail = false;
                throw $failure;
            }
        });

        try {
            if ($reacquire) {
                $connection->getPdo();
            } else {
                DB::connection('leases');
            }

            $this->fail('Expected connection publication to fail.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $this->pool()->getManagedCount());

        if (! $reacquire) {
            try {
                $retained->getPdo();
                $this->fail('Expected the failed initial owner to reject a new borrow.');
            } catch (LogicException) {
            }

            $this->assertSame(0, $this->pool()->getManagedCount());
        }

        $connection ??= DB::connection('leases');
        $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getManagedCount());
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    /**
     * Provide publication failures at initial acquisition and reacquisition.
     */
    public static function connectionListenerFailures(): array
    {
        return [
            'initial failure' => [false, false],
            'initial cancellation' => [false, true],
            'reacquisition failure' => [true, false],
            'reacquisition cancellation' => [true, true],
        ];
    }

    /**
     * Get the pool used by the test's connections.
     */
    protected function pool(): DatabasePool
    {
        return $this->app->make(PoolManager::class)->pool('leases');
    }
}

class LeaseSqliteConnection extends SQLiteConnection
{
}

class LeaseCapabilityConnection extends PostgresConnection
{
    /**
     * Read a session-specific capability from the fixture's SQLite database.
     */
    protected function resolveMaxBindings(): int
    {
        return (int) $this->getPdo()->query('pragma user_version')->fetchColumn();
    }
}

class LeasePdoConnector implements ConnectorInterface
{
    /**
     * Provide independent physical PDOs without an external database server.
     */
    public function connect(array $config): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

class LeaseSessionConfigurator implements SessionConfigurator
{
    public array $applied = [];

    /**
     * Resolve the current borrower's desired session state.
     */
    public function state(PdoConnection $connection): ?string
    {
        return $connection->getName() === 'leases'
            ? CoroutineContext::get('__lease_test.account')
            : null;
    }

    /**
     * Apply a visible SQLite session value and record its logical owner.
     */
    public function apply(PDO $pdo, string $state, PdoConnection $connection): void
    {
        $pdo->exec('pragma user_version = ' . $state);
        $this->applied[] = [spl_object_id($connection), $state];
    }
}
