<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\BorrowedResources;

use Closure;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Parallel;
use Hypervel\Database\PdoConnection;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SqsQueue;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use PDO;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\wait;

class Holder
{
    public mixed $value = null;
}

class LendingService
{
    public function withConnection(Closure $callback): mixed
    {
        return $callback();
    }
}

class Repository
{
    private mixed $connection = null;

    private array $clients = [];

    private static mixed $shared = null;

    public function __construct(
        private RedisProxy $redis,
        private StoreContext $context,
        private QueuePoolProxy $queue,
        private FilesystemPoolProxy $disk,
        private ClientPooledFilesystem $cloud,
        private FilesystemAdapter $local,
        private PdoConnection $database,
        private Parallel $parallel,
    ) {
    }

    public function returns(): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            return $connection;
        });
        $this->redis->withConnection(fn (RedisConnection $connection) => $connection->client());
        $this->context->withConnection(function (RedisConnection $connection) {
            $alias = $connection;

            return $alias;
        });
        $this->queue->withConnection(fn (SqsQueue $queue) => $queue->getSqs());
        $this->disk->withDriver(fn (FilesystemOperator $driver) => $driver);
        $this->cloud->withClient(fn ($client) => $client);
        $this->local->withDriver(fn (FilesystemOperator $driver) => $driver);
        Storage::disk('s3')->withClient(fn ($client) => $client);
    }

    public function stores(): void
    {
        $held = null;

        Redis::withConnection(function (RedisConnection $connection) use (&$held) {
            $client = $connection->client();
            $this->connection = $connection;
            $this->clients['main'] = $client;
            self::$shared = $connection;
            $held = $client;
        });
    }

    public function spawns(): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            go(function () use ($connection) {
            });
            Coroutine::create(fn () => $connection->get('a'));
            parallel([fn () => $connection->get('b')]);
            $tasks = [];
            $tasks[] = fn () => $connection->get('c');
            parallel($tasks);
            $parallel = new Parallel;
            $parallel->add(fn () => $connection->get('d'));
            $this->parallel->add(fn () => $connection->get('e'));
            Concurrency::run(fn () => $connection->get('f'));
            Coroutine::defer(fn () => $connection->get('g'));
            wait(fn () => $connection->get('h'));
            wait(fn () => $connection->get('i'), waitForChildTermination: true);
            (new SyncDriver)->run(fn () => $connection->get('j'));
            go(fn () => 'unrelated');
        });
    }

    public function nested(): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            return array_map(function (string $key) use ($connection) {
                go(fn () => $connection->get($key));

                return $connection;
            }, ['a']);
        });
    }

    public function pinned(): void
    {
        $connection = $this->database;
        $other = $this->database;

        $connection->withPinnedSession(function () use ($connection) {
            return $connection->getPdo();
        });
        $connection->withPinnedSession(fn () => $connection->getReadPdo());
        DB::withPinnedSession(fn () => DB::getPdo());
        $connection->withPinnedSession(function () use ($connection) {
            $pdo = $connection->getPdo();
            $pdo->exec('select 1');
            go(fn () => $pdo->exec('select 2'));
        });
        $connection->withPinnedSession(fn () => $other->getPdo());
        $connection->withPinnedSession(function () use ($connection) {
            $connection = $this->database;

            return $connection->getPdo();
        });
    }

    public function contained(): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            $holder = new Holder;
            $holder->value = $connection;
            $items = [$connection];
            $items[] = $connection->client();

            return $connection->get('key');
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $alias = $connection;
            $alias = null;

            return $alias;
        });
        $this->disk->withDriver(fn (FilesystemOperator $driver) => $driver->readStream('file'));
        (new LendingService)->withConnection(fn (RedisConnection $connection) => $connection);
    }

    public function dispatchForms(Timer $timer, Concurrent $concurrent, QueuedParallel $queued, InlineParallel $inline): void
    {
        $join = true;

        Redis::withConnection(function (RedisConnection $connection) use ($timer, $concurrent, $queued, $inline) {
            $timer->after(1.0, fn () => $connection->get('k'));
            $concurrent->create(fn () => $connection->get('l'));
            $queued->add(fn () => $connection->get('m'));
            $inline->add(fn () => $connection->get('n'));
            wait(fn () => $connection->get('o'), waitForChildTermination: Joins::CHILD);
            $join = false;
            wait(fn () => $connection->get('p'), waitForChildTermination: $join);
        });
    }

    public function overriddenGetters(ReplicaConnection $replica): void
    {
        DB::withPinnedSession(fn () => ExternalDB::getPdo());
        $replica->withPinnedSession(fn () => $replica->getPdo());
    }

    public function escapesThroughValues(Holder $holder): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            return fn () => $connection->get('key');
        });
        Redis::withConnection(fn (RedisConnection $connection) => [$connection, 'main']);
        Redis::withConnection(function (RedisConnection $connection) use ($holder) {
            $this->connection = fn () => $connection->client();
            $holder->value = $connection;
            $local = new Holder;
            $local->value = $connection;
            $lazy = fn () => $connection->get('later');
            $this->clients = [$lazy];
            array_map(function () use ($local, $connection) {
                $local->value = $connection;
            }, [1]);
            array_map(function () use ($holder, $connection) {
                $holder->value = $connection;
            }, [1]);
        });
        Redis::withConnection(fn (RedisConnection $connection) => $holder->value = $connection);
        Redis::withConnection(function (RedisConnection $connection) {
            $tasks = [fn () => $connection->get('q')];
            parallel($tasks);
            go(fn () => array_map(fn ($connection) => $connection, ['safe']));
            go(fn () => new class {
                public function key(): string
                {
                    $connection = 'safe';

                    return $connection;
                }
            });
        });
    }
}

class PinnedService
{
    private ?PDO $pdo = null;

    public PdoConnection $hooked {
        get => $this->database;
    }

    public function __construct(
        private PdoConnection $database,
        private PdoConnection $replica,
    ) {
    }

    public function pins(): void
    {
        $this->database->withPinnedSession(fn () => $this->database->getPdo());
        $this->database->withPinnedSession(function () {
            $this->pdo = $this->database->getPdo();
        });
        $this->database->withPinnedSession(fn () => $this->replica->getPdo());
        $this->database->withPinnedSession(function () {
            $this->database = $this->replica;

            return $this->database->getPdo();
        });
        $this->database->withPinnedSession(function () {
            foreach ([$this->replica] as $this->database) {
            }

            return $this->database->getPdo();
        });
        $this->hooked->withPinnedSession(fn () => $this->hooked->getPdo());
    }
}

class Joins
{
    public const bool CHILD = true;
}

class QueuedParallel extends Parallel
{
}

class InlineParallel extends Parallel
{
    public function add(callable $callable, int|string|null $key = null): void
    {
        $callable();
    }
}

class ExternalDB extends DB
{
    public static function getPdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

class ReplicaConnection extends PdoConnection
{
    public function getPdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

class OrderedEscapes
{
    private array $items = [];

    public function escapes(bool $cached, RedisConnection $other): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [];
            $items[] = $connection;
            $this->items = $items;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($cached) {
            $task = fn () => $connection->get('key');

            if ($cached) {
                $task = fn () => 'cached';
            }

            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $task = fn () => $connection->get('key');
            $connection = $other;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $task = function () use (&$connection) {
                return $connection->get('key');
            };
            go($task);
        });

        $held = null;

        Redis::withConnection(function (RedisConnection $connection) use (&$held) {
            array_map(function () use ($connection, &$held) {
                $held = $connection;
            }, [1]);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $store = function () use (&$client) {
                $this->items = [$client];
            };
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $inner = function () use (&$client) {
                return $client?->get('key');
            };
            $task = function () use ($inner) {
                return $inner();
            };
            $inner = static fn () => null;
            $client = $connection->client();
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [$connection];
            $this->items = &$items;
        });
    }

    public function staysInside(RedisConnection $other): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [];
            $items[] = $connection;
            $this->consume($items);
            $items = [];

            return $items;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $task = fn () => $connection->get('key');
            $value = $task();
            $task = fn () => $value;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $task = function () use (&$connection) {
                return $connection->get('key');
            };
            $connection = $other;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $task = function () use (&$connection) {
                return $connection->get('key');
            };
            $connection = $other;

            return $task;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $store = function () use (&$connection) {
                $this->items = [$connection];
            };
            $connection = $other;
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $inner = function () use (&$client) {
                return $client?->get('key');
            };
            $task = function () use ($inner) {
                return $inner();
            };
            $client = null;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $alias = &$connection;
            $connection = $other;

            return $alias;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $alias = &$connection;
            $second = &$alias;
            $connection = $other;

            return $second;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $store = function () use (&$connection) {
                $this->items = [$connection];
            };
            $alias = &$connection;
            $alias = $other;
            $store();
        });
    }

    private function consume(array $items): void
    {
    }
}

class ReferencedOwners
{
    public function __construct(
        private PdoConnection $database,
        private PdoConnection $replica,
    ) {
    }

    public function pins(): void
    {
        $connection = $this->database;

        $connection->withPinnedSession(function () use ($connection) {
            $alias = &$connection;
            $alias = $this->replica;

            return $connection->getPdo();
        });
        $this->database->withPinnedSession(function () {
            $alias = &$this->database;
            $alias = $this->replica;

            return $this->database->getPdo();
        });
    }
}
