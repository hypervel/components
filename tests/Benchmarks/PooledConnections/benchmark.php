<?php

declare(strict_types=1);

use Ably\AblyRest;
use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Http\Client\Common\HttpMethodsClient;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Mercure\Hub;
use Hypervel\Cache\DatabaseLock;
use Hypervel\Cache\Limiters\ConcurrencyLimiter as CacheLimiter;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Http\Client\Events\ResponseReceived;
use Hypervel\Http\Request;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Message;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Messages\SlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager as ObjectPools;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Queue\ClearableQueuePoolProxy;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Redis\Limiters\ConcurrencyLimiter as RedisLimiter;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisManager;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Scout\Console\ConcurrentImportRunner;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;
use Hypervel\Tests\Grpc\Fixtures\TestServiceClient;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector as RateLimitedConnector;
use Hypervel\Tests\Socialite\Fixtures\GenericTestProviderStub;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Meilisearch\Client as MeilisearchClient;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Pusher\Pusher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Process\InputStream;
use Typesense\Client as TypesenseClient;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = getopt('', ['case:', 'requests:', 'concurrency:', 'pool:', 'samples:', 'after-query', 'transaction', 'endpoint:', 'ca:', 'grpc:', 'redis-port:', 'smtp-port:', 'help']);
$cases = ['cache-lock', 'cache-funnel', 'database-lock', 'redis-funnel', 'redis-throttle', 'http-retry', 'saloon-delay', 'saloon-retry', 'saloon-rate-limit', 'concurrency', 'saloon-pool', 'saloon-generator', 'process-run', 'process-wait', 'process-stop', 'mail', 'filesystem-client', 'filesystem-driver', 'socialite', 'slack', 'sqs', 'grpc', 'grpc-retry', 'scout-algolia', 'scout-meilisearch', 'scout-typesense', 'pusher-release', 'ably-release', 'mercure-release', 'scout-join'];

if (isset($options['help'])) {
    echo "Usage: php benchmark.php --case=CASE [--requests=16 --concurrency=8 --pool=2 --samples=2 --after-query --transaction]\n"
        . "HTTPS cases: --endpoint=https://127.0.0.1:PORT --ca=/path/to/ca.pem\n"
        . "Other services: --smtp-port=PORT --redis-port=PORT --grpc=127.0.0.1:PORT\n"
        . 'Cases: ' . implode(', ', $cases) . "\n";
    exit(0);
}

class MeasuredDatabasePool extends DatabasePool
{
    public array $acquisitions = [];

    public array $holds = [];

    private array $borrowedAt = [];

    /**
     * Measure checkout latency, including queueing and connection creation.
     */
    public function borrow(): PoolConnection
    {
        $started = hrtime(true);
        $connection = parent::borrow();
        $acquired = hrtime(true);
        $this->acquisitions[] = ($acquired - $started) / 1e6;
        $this->borrowedAt[spl_object_id($connection)] = $acquired;

        return $connection;
    }

    /**
     * Record the physical hold before returning the slot to waiting coroutines.
     */
    public function release(PoolConnection $connection): void
    {
        $identity = spl_object_id($connection);
        $this->holds[] = (hrtime(true) - $this->borrowedAt[$identity]) / 1e6;
        unset($this->borrowedAt[$identity]);
        parent::release($connection);
    }
}

class BenchmarkFilesystemManager extends FilesystemManager
{
    /**
     * Build the ordinary S3 adapter around a pooled SDK client.
     */
    public function stack(S3Client $client, array $config): AwsS3V3Adapter
    {
        return $this->buildS3Disk($client, $config);
    }
}

/**
 * Model a custom pooled driver whose reads contact a remote service.
 */
class RemoteBenchmarkFilesystem extends FilesystemAdapter
{
    /**
     * Create the fixture's remote filesystem adapter.
     */
    public function __construct(private Client $client, private string $endpoint)
    {
        $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());
        parent::__construct(new Filesystem($adapter), $adapter);
    }

    /**
     * Read from the local HTTPS fixture.
     */
    public function get(string $path): ?string
    {
        return (string) $this->client->get($this->endpoint . '/' . $path)->getBody();
    }
}

class BenchmarkNotification extends Notification
{
    /**
     * Build a webhook notification with the fixture's trusted certificate.
     */
    public function toSlack(BenchmarkNotifiable $notifiable): SlackMessage
    {
        return (new SlackMessage)->content('benchmark')->http(['verify' => $notifiable->certificate]);
    }
}

class BenchmarkNotifiable
{
    /**
     * Keep the fixture URL and certificate together.
     */
    public function __construct(public string $endpoint, public string $certificate)
    {
    }

    /**
     * Route the notification to the local webhook fixture.
     */
    public function routeNotificationFor(string $driver, Notification $notification): string
    {
        return $this->endpoint . '/slack';
    }
}

/**
 * Read or reset counts from the SMTP fixture.
 */
function smtpStats(int $port, string $command): array
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 3);
    if ($socket === false) {
        throw new RuntimeException('SMTP fixture unavailable.');
    }

    try {
        if (! str_starts_with((string) fgets($socket), '220 ')) {
            throw new RuntimeException('Unexpected SMTP fixture greeting.');
        }
        fwrite($socket, $command . "\r\n");

        return json_decode(substr((string) fgets($socket), 4), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        fclose($socket);
    }
}

/**
 * Summarize measured milliseconds without retaining per-request reports.
 */
function percentiles(array $values): array
{
    sort($values, SORT_NUMERIC);

    return [
        'p50' => $values[(int) ceil(count($values) * 0.5) - 1],
        'p95' => $values[(int) ceil(count($values) * 0.95) - 1],
    ];
}

/**
 * Build only the selected workload and its optional cleanup.
 *
 * @return array{Closure(Connection): mixed, null|Closure(): void}
 */
function createOperation(string $case, ApplicationContract $app, array $settings, array $options, ObjectPools $objects, int &$deliveries): array
{
    $endpoint = $options['endpoint'] ?? '';
    $certificate = $options['ca'] ?? '';

    switch ($case) {
        case 'scout-algolia':
        case 'scout-meilisearch':
        case 'scout-typesense':
            if ($case === 'scout-typesense') {
                $app->make('config')->set('scout.typesense.client-settings', [
                    'api_key' => 'test', 'nodes' => [['host' => '127.0.0.1', 'port' => '8108', 'protocol' => 'http']],
                ]);
            }
            $scoutClient = match ($case) {
                'scout-algolia' => (new ReflectionProperty(GuzzleHttpClient::class, 'client'))->getValue(Algolia::getHttpClient()),
                'scout-meilisearch' => (new ClassInvoker((new ClassInvoker($app->make(MeilisearchClient::class)))->http))->http,
                'scout-typesense' => (new ReflectionProperty(TypesenseClient::class, 'config'))->getValue($app->make(TypesenseClient::class))->getClient(),
            };
            if ($scoutClient instanceof HttpMethodsClient) {
                $scoutClient = (new ReflectionProperty(HttpMethodsClient::class, 'httpClient'))->getValue($scoutClient);
            }

            return [static fn (): ResponseInterface => $scoutClient->get($endpoint . '/scout', ['verify' => $certificate, 'timeout' => 3]), null];
        case 'socialite':
            $provider = new GenericTestProviderStub(Request::create('/'), ['verify' => $certificate, 'timeout' => 3]);

            return [static fn (): ResponseInterface => $provider->getProviderHttpClient()->get($endpoint . '/user'), null];
        case 'slack':
            $channel = $app->make(SlackWebhookChannel::class);
            $notification = new BenchmarkNotification;
            $notifiable = new BenchmarkNotifiable($endpoint, $certificate);

            return [static fn (): ResponseInterface => $channel->send($notifiable, $notification), null];
        case 'filesystem-client':
        case 'filesystem-driver':
            $poolOptions = PoolOptions::fromArray(['max_objects' => $settings['concurrency'], 'max_lifetime' => null]);
            if ($case === 'filesystem-client') {
                $manager = new BenchmarkFilesystemManager($app);
                $config = [
                    'driver' => 's3', 'bucket' => 'benchmark', 'region' => 'us-east-1', 'version' => 'latest',
                    'credentials' => ['key' => 'test', 'secret' => 'test'], 'endpoint' => $endpoint,
                    'use_path_style_endpoint' => true, 'http' => ['verify' => $certificate, 'timeout' => 3],
                ];
                $disk = new ClientPooledFilesystem(
                    new PoolDefinition('filesystem:benchmark-client', 's3', 'client', $poolOptions),
                    static fn (): S3Client => new S3Client($config),
                    static fn (object $client): AwsS3V3Adapter => $manager->stack($client, $config),
                    $objects,
                    $config,
                );
            } else {
                $disk = new FilesystemPoolProxy(
                    new PoolDefinition('filesystem:benchmark-driver', 'custom', 'driver', $poolOptions),
                    static fn (): RemoteBenchmarkFilesystem => new RemoteBenchmarkFilesystem(new Client(['verify' => $certificate, 'timeout' => 3]), $endpoint),
                    $objects,
                    ['driver' => 'custom'],
                );
            }

            return [static function () use ($disk): void {
                if ($disk->get('file.txt') !== 'contents') {
                    throw new RuntimeException('Unexpected filesystem response.');
                }
            }, null];

        case 'sqs':
            $queue = new ClearableQueuePoolProxy(
                new PoolDefinition('queue:benchmark', 'sqs', 'queue', PoolOptions::fromArray(['max_objects' => $settings['concurrency'], 'max_lifetime' => null])),
                static fn (): Queue => (new SqsConnector)->connect([
                    'queue' => $endpoint . '/queue', 'endpoint' => $endpoint, 'region' => 'us-east-1',
                    'key' => 'test', 'secret' => 'test', 'http' => ['verify' => $certificate, 'timeout' => 3],
                ]),
                $objects,
            );

            return [static function () use ($queue): void {
                if ($queue->pushRaw('payload') !== 'benchmark-message') {
                    throw new RuntimeException('Unexpected SQS response.');
                }
            }, null];

        case 'pusher-release':
            // Controlled waits isolate the release boundary, not provider throughput.
            $client = m::mock(Pusher::class);
            $client->shouldReceive('trigger')->andReturnUsing(static function () use (&$deliveries): object {
                usleep(20_000);
                ++$deliveries;
                return (object) [];
            });
            $broadcaster = new PusherBroadcaster($app, $client);

            return [static function () use ($broadcaster): void {
                $broadcaster->broadcast(['orders'], 'created', ['value' => 1]);
            }, null];

        case 'ably-release':
            $client = m::mock(AblyRest::class);
            $channel = m::mock();
            $channel->shouldReceive('publish')->andReturnUsing(static function () use (&$deliveries): void {
                usleep(20_000);
                ++$deliveries;
            });
            $channel->shouldReceive('getCipherParams')->andReturnNull();
            $client->channels = m::mock();
            $client->channels->shouldReceive('get')->andReturn($channel);
            $client->channels->shouldReceive('release');
            $broadcaster = new AblyBroadcaster($app, $client);

            return [static function () use ($broadcaster): void {
                $broadcaster->broadcast(['orders'], 'created', ['value' => 1]);
            }, null];

        case 'mercure-release':
            $token = m::mock(TokenProviderInterface::class);
            $token->shouldReceive('getJwt')->andReturn('test.token.signature');
            $hub = new Hub(
                new PoolDefinition('benchmark:mercure', 'mercure', 'test', PoolOptions::fromArray(['max_objects' => $settings['concurrency']])),
                static fn (): MockHttpClient => new MockHttpClient(static function (): MockResponse {
                    usleep(20_000);
                    return new MockResponse('event-id');
                }),
                $objects,
                $app->make(UrlGenerator::class),
                'https://hub.test/.well-known/mercure',
                $token,
            );

            return [static function () use ($hub): void {
                if ($hub->publish(new Update('orders', 'created')) !== 'event-id') {
                    throw new RuntimeException('Unexpected Mercure response.');
                }
            }, null];

        case 'grpc':
        case 'grpc-retry':
            $app->instance(ClientFactoryInterface::class, new ClientFactory);
            $client = new TestServiceClient($options['grpc'], ['timeout' => 3]);

            return [static function (Connection $connection) use ($client, $case): void {
                if ($case === 'grpc-retry') {
                    $call = $client->unary((new TestRequest)->setValue('retry:pre'), options: ['retry' => new RetryPolicy(2, initialBackoff: 0.02, maxBackoff: 0.02)]);
                    $connection->selectOne('select 1');
                    $expected = 'retried:1';
                } else {
                    $call = $client->unary((new TestRequest)->setValue('delay:0.02'));
                    $expected = 'delayed';
                }
                if ($call->wait()->getValue() !== $expected) {
                    throw new RuntimeException('Unexpected gRPC response.');
                }
            }, $client->close(...)];

        case 'mail':
            $app->make('config')->set('mail.mailers.benchmark', [
                'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) $options['smtp-port'],
                'scheme' => 'smtp', 'auto_tls' => false, 'timeout' => 5,
                'pool' => ['max_objects' => $settings['concurrency'], 'wait_timeout' => 10],
            ]);
            $manager = $app->make(MailManager::class);
            $mailer = $manager->mailer('benchmark');

            return [static function () use ($mailer): void {
                $mailer->raw('Local benchmark message.', static function (Message $message): void {
                    $message->from('sender@example.test')->to('receiver@example.test')->subject('Local benchmark');
                });
            }, static function () use ($manager): void { $manager->purge('benchmark'); }];

        case 'concurrency':
        case 'scout-join':
            return [static function () use ($case): void {
                $first = static function (): int {
                    usleep(20_000);
                    return 1;
                };
                $second = static function (): int {
                    usleep(20_000);
                    return 2;
                };
                if ($case === 'concurrency') {
                    if ((new CoroutineDriver)->run([$first, $second]) !== [1, 2]) {
                        throw new RuntimeException('Unexpected child results.');
                    }
                } else {
                    $runner = new ConcurrentImportRunner(2);
                    $runner->create($first);
                    $runner->create($second);
                    $runner->wait();
                }
            }, null];

        case 'process-run':
        case 'process-wait':
        case 'process-stop':
            return [static function (Connection $connection) use ($case): void {
                $pending = (new ProcessFactory)->timeout(5);
                $command = [PHP_BINARY, '-r', 'usleep(20000); echo "OK";'];
                $process = null;
                $input = null;
                try {
                    if ($case === 'process-run') {
                        $result = $pending->run($command);
                    } elseif ($case === 'process-wait') {
                        $process = $pending->start($command);
                        $result = $process->wait();
                    } else {
                        $input = new InputStream;
                        $process = $pending->input($input)->start(['sh', '-c', 'read start; trap "sleep 0.02; exit 0" TERM; printf "READY\n"; while :; do sleep 0.01; done']);
                        $input->write("start\n");
                        $process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'READY'));
                        $connection->selectOne('select 1');
                        if ($process->stop(3) !== 0) {
                            throw new RuntimeException('Graceful stop failed.');
                        }
                    }
                    if ($case !== 'process-stop' && $result->output() !== 'OK') {
                        throw new RuntimeException('Process failed.');
                    }
                } finally {
                    $input?->close();
                    $process?->stop(0);
                }
            }, null];
        case 'http-retry':
        case 'saloon-delay':
        case 'saloon-retry':
        case 'saloon-rate-limit':
        case 'saloon-pool':
        case 'saloon-generator':
            $attempts = [];
            Http::fake(static function () use ($case, &$attempts): PromiseInterface {
                if (in_array($case, ['saloon-pool', 'saloon-generator'], true)) {
                    usleep(20_000);
                }

                if (in_array($case, ['saloon-retry', 'http-retry'], true)) {
                    $identity = Coroutine::id();
                    $attempts[$identity] = ($attempts[$identity] ?? 0) + 1;

                    return Http::response('OK', $attempts[$identity] === 1 ? 500 : 200);
                }

                return Http::response('OK');
            });
            if ($case === 'saloon-rate-limit') {
                $app->make('config')->set('saloon.rate_limiter.store', 'worker-array');
            }
            $rateLimiter = $case === 'saloon-rate-limit' ? $app->make(RateLimiter::class)->store('worker-array') : null;
            if ($case === 'http-retry') {
                $database = $app->make(DatabaseManager::class);
                Event::listen(ResponseReceived::class, static function () use ($database): void {
                    $database->connection('benchmark')->selectOne('select 1');
                });
            }

            return [static function (Connection $connection) use ($case, $rateLimiter, &$attempts): void {
                try {
                    if ($case === 'http-retry') {
                        $response = Http::retry(2, static function () use ($connection): int {
                            $connection->selectOne('select 1');
                            return 20;
                        })->get('https://example.test/retry');
                        if (! $response->successful()) {
                            throw new RuntimeException('HTTP retry failed.');
                        }
                    } elseif ($case === 'saloon-pool' || $case === 'saloon-generator') {
                        $requests = $case === 'saloon-generator' ? static function () use ($connection): Generator {
                            for ($index = 0; $index < 3; ++$index) {
                                $connection->selectOne('select 1');
                                yield new UserRequest;
                            }
                            $connection->selectOne('select 1');
                        } : [new UserRequest, new UserRequest, new UserRequest];
                        if (count((new TestConnector)->pool($requests)->setConcurrency(2)->send()) !== 3) {
                            throw new RuntimeException('Saloon pool did not complete.');
                        }
                    } else {
                        $request = new UserRequest;
                        $connector = new TestConnector;
                        if ($case === 'saloon-rate-limit') {
                            $policy = Limit::perSecond(1)->by('benchmark:' . Coroutine::id());
                            $connector = new RateLimitedConnector([$policy], [$policy]);
                            $rateLimiter->consume($policy, 'saloon:' . $connector::class);
                        } elseif ($case === 'saloon-retry') {
                            $request->retry(2, 20);
                        } else {
                            $request->delay(20);
                        }
                        if (! $connector->send($request)->successful()) {
                            throw new RuntimeException('Saloon failed.');
                        }
                    }
                } finally {
                    unset($attempts[Coroutine::id()]);
                }
            }, null];
        case 'cache-lock':
        case 'cache-funnel':
        case 'database-lock':
        case 'redis-funnel':
        case 'redis-throttle':
            $database = $app->make(DatabaseManager::class);
            $store = str_starts_with($case, 'cache-') ? new WorkerArrayStore : null;
            $redis = null;
            if (str_starts_with($case, 'redis-')) {
                $app->make('config')->set('database.redis.benchmark', [
                    'host' => '127.0.0.1', 'port' => (int) $options['redis-port'], 'database' => 0,
                    'pool' => ['max_connections' => $settings['concurrency'], 'min_retained_connections' => 0],
                ]);
                $redis = $app->make(RedisManager::class)->connection('benchmark');
            }

            return [static function () use ($case, $database, $store, $redis): void {
                $key = 'wait-benchmark:' . getmypid() . ':' . Coroutine::id();
                if ($case === 'database-lock') {
                    $holder = new DatabaseLock($database, 'benchmark', $key, 'cache_locks', 1, lottery: null);
                    if (! $holder->acquire()) {
                        throw new RuntimeException('Cannot establish database lock contention.');
                    }
                    (new DatabaseLock($database, 'benchmark', $key, 'cache_locks', 10, lottery: null))
                        ->betweenBlockedAttemptsSleepFor(100)->block(5, static fn (): null => null);
                    return;
                }
                if ($case === 'cache-lock') {
                    $holder = $store->lock($key, 10);
                    if (! $holder->acquire()) {
                        throw new RuntimeException('Cannot establish lock contention.');
                    }
                    $release = $holder->release(...);
                    $wait = fn (): null => $store->lock($key, 10)->betweenBlockedAttemptsSleepFor(5)->block(5, static fn (): null => null);
                } elseif ($case === 'cache-funnel' || $case === 'redis-funnel') {
                    $limiter = $case === 'cache-funnel'
                        ? new CacheLimiter($store, $key, 1, 10)
                        : new RedisLimiter($redis, $key, 1, 10);
                    $holder = $limiter->acquire(0);
                    $release = $holder->release(...);
                    $wait = fn (): bool => $limiter->acquire(5, 5)->release();
                } elseif ($case === 'redis-throttle') {
                    $limiter = new DurationLimiter($redis, $key, 1, 10);
                    if (! $limiter->acquire()) {
                        throw new RuntimeException('Cannot establish throttle contention.');
                    }
                    $release = $limiter->clear(...);
                    $wait = static function () use ($limiter): void {
                        $limiter->block(5, sleep: 5);
                        $limiter->clear();
                    };
                }
                waitForRelease($wait, $release);
            }, null];
    }
}

/**
 * Release a held lock after 20 ms while its caller waits, and join the helper.
 */
function waitForRelease(Closure $wait, Closure $release): void
{
    $finished = new WaitGroup;
    $failure = null;
    $finished->add();
    Coroutine::create(static function () use ($release, $finished, &$failure): void {
        try {
            usleep(20_000);
            $release();
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $finished->done();
        }
    });
    try {
        $wait();
    } finally {
        $finished->wait();
    }
    if ($failure !== null) {
        throw $failure;
    }
}

if (! isset($options['case'])) {
    throw new InvalidArgumentException('Usage: php benchmark.php --case=CASE [options]. Use --help to list cases.');
}
$case = $options['case'];
if (! in_array($case, $cases, true)) {
    throw new InvalidArgumentException('Unknown case: ' . $case);
}
$endpoint = $options['endpoint'] ?? '';
$certificate = $options['ca'] ?? '';
$https = in_array($case, ['filesystem-client', 'filesystem-driver', 'socialite', 'slack', 'sqs', 'scout-algolia', 'scout-meilisearch', 'scout-typesense'], true);
if ($https && (! str_starts_with($endpoint, 'https://127.0.0.1:') || ! is_file($certificate))) {
    throw new InvalidArgumentException('Supply the local HTTPS fixture endpoint and its certificate.');
}
$serviceOption = match ($case) {
    'mail' => 'smtp-port',
    'redis-funnel', 'redis-throttle' => 'redis-port',
    'grpc', 'grpc-retry' => 'grpc',
    default => null,
};
if ($serviceOption !== null && ! isset($options[$serviceOption])) {
    throw new InvalidArgumentException('Supply --' . $serviceOption . ' for this case.');
}
$settings = ['requests' => 16, 'concurrency' => 8, 'pool' => 2, 'samples' => 2];
foreach ($settings as $key => $default) {
    $value = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        throw new InvalidArgumentException('--' . $key . ' must be a positive integer.');
    }
    $settings[$key] = $value;
}
Bootstrapper::bootstrap();
$path = tempnam(sys_get_temp_dir(), 'pooled-connections-db-');
$app = null;
$objects = null;
try {
    $app = Application::create(options: [
        'load_environment_variables' => false,
        'extra' => ['providers' => match (true) {
            str_starts_with($case, 'scout-') && $case !== 'scout-join' => [ScoutServiceProvider::class],
            str_starts_with($case, 'saloon-') => [SaloonServiceProvider::class],
            $case === 'slack' => [SlackChannelServiceProvider::class],
            default => [],
        }],
    ]);
    $app->make('config')->set('database.connections.benchmark', [
        'driver' => 'sqlite', 'database' => $path,
        'pool' => ['testing_enabled' => true, 'max_connections' => $settings['pool'], 'min_retained_connections' => 0, 'wait_timeout' => 15, 'heartbeat_interval' => null, 'idle_check_interval' => null],
    ]);
    $app->bind(DatabasePool::class, MeasuredDatabasePool::class);
    $database = $app->make(DatabaseManager::class);
    $pool = $app->make(PoolManager::class)->pool('benchmark');
    $objects = new ObjectPools;
    $deliveries = 0;
    [$operation, $cleanup] = createOperation($case, $app, $settings, $options, $objects, $deliveries);
    $seenConnections = [];
    $reports = [];
    $failure = null;
    run(function () use ($app, $database, $pool, $case, $settings, $options, $operation, $cleanup, &$deliveries, &$seenConnections, &$reports, &$failure): void {
        try {
            if ($case === 'database-lock') {
                $database->connection('benchmark')->statement('create table cache_locks (key varchar(255) primary key, owner varchar(255), expiration integer)');
                ConnectionResolver::releaseIdleConnections();
            }
            for ($sample = -1; $sample < $settings['samples']; ++$sample) {
                $count = $sample < 0 ? $settings['concurrency'] : $settings['requests'];
                $pool->acquisitions = $pool->holds = [];
                $latencies = [];
                $completed = 0;
                $deliveries = 0;
                $connectionsBefore = count($seenConnections);
                if ($case === 'mail') {
                    smtpStats((int) $options['smtp-port'], 'XRESET');
                }
                gc_collect_cycles();
                memory_reset_peak_usage();
                $cpu = getrusage();
                $started = hrtime(true);
                $request = function () use ($database, $case, $options, $operation, &$seenConnections, &$latencies, &$completed): void {
                    $started = hrtime(true);
                    Coroutine::defer(static function () use ($started, &$latencies): void {
                        $latencies[] = (hrtime(true) - $started) / 1e6;
                    });
                    $connection = $database->connection('benchmark');
                    if (isset($options['transaction'])) {
                        $connection->beginTransaction();
                    }
                    if (! in_array($case, ['grpc-retry', 'process-stop'], true)) {
                        $connection->selectOne('select 1');
                    }
                    $response = $operation($connection);
                    if ($response instanceof ResponseInterface) {
                        if ((string) $response->getBody() !== 'contents') {
                            throw new RuntimeException('Unexpected HTTP response.');
                        }
                        $identity = $response->getHeaderLine('X-Benchmark-Connection');
                        if ($identity === '') {
                            throw new RuntimeException('The HTTPS fixture did not provide a connection identity.');
                        }
                        $seenConnections[$identity] = true;
                    }
                    if (isset($options['after-query'])) {
                        $connection->selectOne('select 2');
                    }
                    if (isset($options['transaction'])) {
                        $connection->commit();
                    }
                    ++$completed;
                };
                for ($done = 0; $done < $count; $done += $settings['concurrency']) {
                    parallel(array_fill(0, min($settings['concurrency'], $count - $done), $request));
                }
                $elapsed = (hrtime(true) - $started) / 1e9;
                $after = getrusage();
                if (in_array($case, ['pusher-release', 'ably-release'], true) && $deliveries !== $count) {
                    throw new RuntimeException('Unexpected broadcast delivery count.');
                }
                $smtp = $case === 'mail' ? smtpStats((int) $options['smtp-port'], 'XSTATS') : null;
                if ($smtp !== null && $smtp['messages'] !== $count) {
                    throw new RuntimeException('Unexpected SMTP message count.');
                }
                if ($completed !== $count || $pool->getBorrowedCount() !== 0) {
                    throw new RuntimeException('Incomplete operations or leaked database leases.');
                }
                if ($sample >= 0) {
                    $reports[] = [
                        'seconds' => $elapsed, 'operations_per_second' => $count / $elapsed,
                        'cpu_us_per_operation' => (($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $cpu['ru_utime.tv_sec'] - $cpu['ru_stime.tv_sec']) * 1e6 + $after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $cpu['ru_utime.tv_usec'] - $cpu['ru_stime.tv_usec']) / $count,
                        'request_ms' => percentiles($latencies), 'checkout_ms' => percentiles($pool->acquisitions),
                        'hold_ms_per_operation' => array_sum($pool->holds) / $count,
                        'checkouts_per_operation' => count($pool->acquisitions) / $count,
                        'new_connections' => in_array($case, ['scout-algolia', 'scout-meilisearch', 'scout-typesense', 'socialite', 'slack'], true) ? count($seenConnections) - $connectionsBefore : null,
                        'smtp' => $smtp,
                        'heap_peak' => memory_get_peak_usage(), 'completed' => $completed,
                    ];
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $cleanup?->__invoke();
            $app->make(PoolManager::class)->purgeAll();
        }
    });
    if ($failure) {
        throw $failure;
    }
    echo json_encode([
        'php' => PHP_VERSION,
        'swoole' => swoole_version(),
        'settings' => $settings + ['case' => $case, 'after-query' => isset($options['after-query']), 'transaction' => isset($options['transaction'])] + $options,
        'reports' => $reports,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    $objects?->purgeAll();
    m::close();
    $app?->terminate();
    unlink($path);
}
