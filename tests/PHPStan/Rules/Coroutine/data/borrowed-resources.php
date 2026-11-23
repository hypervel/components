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
use LogicException;
use PDO;
use Throwable;

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

    public function spawns(): void
    {
        $connection = $this->database;

        $connection->withPinnedSession(function () use ($connection) {
            go(function () use ($connection) {
                $this->pdo = $connection->getPdo();
            });
        });
        $this->database->withPinnedSession(function () {
            go(function () {
                $this->pdo = $this->database->getPdo();
            });
        });
        DB::withPinnedSession(function () {
            go(function () {
                $this->pdo = DB::getPdo();
            });
            $store = function () {
                $this->pdo = DB::getPdo();
            };
            $store();
        });
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
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $inner = function () use (&$client) {
                return $client->get('key');
            };
            $task = fn () => $inner();
            array_map(function () use ($task) {
                go($task);
            }, [1]);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $inner = function () use (&$connection) {
                return $connection->get('key');
            };
            $task = fn () => $inner();
            $launch = function () use ($task) {
                go($task);
            };
            $launch();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $task = function () use (&$client) {
                return $client?->get('key');
            };
            $launch = function () use ($task, &$client, $connection) {
                $client = $connection->client();
                go($task);
            };
            $launch();
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
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $inner = function () use (&$client) {
                return $client?->get('key');
            };
            $task = fn () => $inner();
            $launch = function () use ($task) {
                go($task);
            };
            $client = $connection->client();
            $launch();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $task = function () use (&$client) {
                return $client?->get('key');
            };
            $launch = function () use ($task, &$client) {
                $client = null;
                go($task);
            };
            $launch();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $attempts = 0;
            $task = function () use (&$client, &$attempts) {
                ++$attempts;

                return $client?->get('key');
            };
            $launch = function () use ($task, $client, &$attempts) {
                if ($client !== null) {
                    go($task);
                }
            };
            $client = null;
            $launch();
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

class GlobalStores
{
    public function escapes(bool $shared): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $GLOBALS['held'] = $connection->client();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $registry;
            $registry->client = $connection->client();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            $store = function () use (&$held, $connection) {
                $held = $connection;
            };
            $store();
        });

        $out = null;

        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $store = function () use (&$out, $connection) {
                $out = $connection;
            };
            unset($out);
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $registry;
            $store = function () use ($registry, $connection) {
                $registry->value = $connection->client();
            };
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $registry;
            $store = fn () => $registry->value = $connection->client();
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $out = new Holder;
            $store = function () use ($out, $connection) {
                $out->value = $connection->client();
            };
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $task = function () use (&$client) {
                $client->ping();
            };
            unset($client);
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $task = function () use (&$client) {
                $client->ping();
            };
            $other = null;
            $client = &$other;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $inner = function () use (&$client) {
                return $client;
            };
            $task = function () use (&$inner) {
                return $inner();
            };
            unset($inner);
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };
            global $client;
            $client = $connection->client();
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $other = null;
            $client = &$other;
            unset($client);

            if ($shared) {
                $client = $connection->client();

                return $client;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                return $client;
            } finally {
                unset($client);
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $task = function () use (&$client) {
                $client->ping();
            };

            try {
                go($task);
            } finally {
                unset($client);
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();

            if ($shared) {
                $copy = $client;
                unset($client);

                return $copy;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];

            if ($shared) {
                unset($clients);
                $clients[] = $connection->client();

                return $clients;
            }

            return [];
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;

            if ($shared) {
                unset($client);
                $client = $connection->client();

                return $client;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $batch = [];

            foreach ([1, 2] as $row) {
                unset($batch);
                $batch = [];
                $batch[] = $connection->client();
            }

            return $batch;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $inner = function () use (&$inner, &$connection) {
                return $connection;
            };
            $task = function () use (&$inner) {
                return $inner();
            };
            unset($inner, $connection);
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [$connection->client()];
            $copy = $clients;

            foreach ($clients as &$client) {
                $client = null;
            }

            unset($client);

            return $copy;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $clients[] = $connection->client();
            $result = [];

            if ($shared) {
                unset($unrelated);
                $result = $clients;
            }

            return $result;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [];
            $result = [];
            $copy = [];
            $unrelated = null;

            foreach ([1, 2] as $row) {
                $result = $clients;
                unset($unrelated);
                $clients[] = $connection->client();
                $copy = $result;
            }

            return $copy;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();
            $label = null;

            if ($shared) {
                unset($label);
                $label = 'main';
                $task = fn () => [$label, $client];
                $client = null;

                return $task;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $tasks = [];

            foreach ([1, 2] as $row) {
                unset($label);
                $label = 'main';
                $tasks[] = fn () => [$label, $client];
                $client = null;
            }

            return $tasks;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [];
            $label = null;

            foreach ([1, 2] as $row) {
                unset($label);
                $label = 'main';
                $task = fn () => [$label, $clients];
                $clients[] = $connection->client();
            }

            return $task;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [];

            foreach ([1, 2] as $row) {
                $task = fn () => $clients;
                $clients[] = $connection->client();
            }

            return $task;
        });
    }

    public function staysInside(bool $shared): void
    {
        $out = null;

        Redis::withConnection(function (RedisConnection $connection) {
            $held = $connection;
            global $held;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            unset($held);
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            static $held;
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            $store = function () use ($connection) {
                $held = $connection;
            };
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            $local = null;
            $held = &$local;
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            static $held;
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $client = $connection->client();
            $out = &$client;
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $local = null;
            $out = &$local;
            $out = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            unset($out);
            $out = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $registry;
            $holder = new Holder;
            $store = function () use ($holder, $connection) {
                $holder->value = $connection->client();
            };
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };
            unset($client);
            $client = $connection->client();
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients[] = $connection->client();
            unset($clients);
            $clients[] = 'done';

            return $clients;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;

            if (! function_exists(__NAMESPACE__ . '\\keepConnection')) {
                function keepConnection(RedisConnection $connection): RedisConnection
                {
                    $held = $connection;

                    return $connection;
                }
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();
            $task = function () use (&$client) {
                return $client;
            };

            if ($shared) {
                $client = null;
                unset($client);
            }

            $client = null;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $inner = function () use (&$client) {
                return $client;
            };
            $task = function () use (&$inner) {
                return $inner();
            };
            unset($inner);
            $client = null;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $other = null;
            $client = &$other;

            if ($shared) {
                unset($client);
            }

            if ($shared) {
                $client = $connection->client();
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            if ($shared) {
                $clients[] = $connection->client();
                unset($clients);
                $clients[] = 'done';

                return $clients;
            }

            return [];
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $batch = [];

            foreach ([1, 2] as $row) {
                $batch[] = $connection->client();
                unset($batch);
                $batch = [];
            }

            return $batch;
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $items = [null];

            foreach ($items as &$out) {
            }

            $out = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $other = null;
            $client = &$other;

            if ($shared) {
                unset($client);
                $client = $connection->client();
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [$connection->client()];
            [&$client] = $clients;
            $client = null;

            return $clients;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients[] = $connection->client();

            foreach ($clients as &$client) {
                $client = null;
            }

            unset($client);

            return $clients;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clients = [$connection->client()];
            $store = function () use (&$clients) {
                $this->clients = $clients;
            };

            foreach ($clients as &$client) {
                $client = null;
            }

            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $clients[] = $connection->client();
            $result = [];

            if ($shared) {
                unset($clients);
                $clients = [];
                $result = $clients;
            }

            return $result;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $clients[] = $connection->client();
            $result = [];
            $copy = [];
            $other = null;

            if ($shared) {
                unset($clients);
                $clients = [];
                $result = $clients;
                unset($other);
                $copy = $result;
            }

            return $copy;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };
            $copy = null;

            if ($shared) {
                unset($client);
                $client = $connection;
                $copy = $task;
                $this->callback = $copy;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();
            $safe = null;
            $unrelated = null;

            if ($shared) {
                $client = &$safe;
                unset($unrelated);
                $copy = $client;

                return $copy;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $clients[] = $connection->client();
            $result = [];

            foreach ([1, 2] as $row) {
                if ($shared) {
                    unset($clients);
                    $clients = [];
                    $result = $clients;
                }
            }

            return $result;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $clients[] = $connection->client();
            $result = [];
            $copy = [];
            $other = null;

            while ($shared) {
                unset($clients);
                $clients = [];
                $result = $clients;
                unset($other);
                $copy = $result;
                $shared = false;
            }

            return $copy;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];
            $result = [];
            $copy = [];
            $unrelated = null;

            if ($shared) {
                $result = $clients;
                unset($unrelated);
                $clients[] = $connection->client();
                $copy = $result;
                $reset = function (): void {
                    $local = null;
                };
                $clear = fn () => $local = null;
            }

            return $copy;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();

            if ($shared) {
                $copy = [($client = null), $client];

                return $copy;
            }

            return null;
        });
    }
}

class StoreForms
{
    public mixed $client = null;

    public mixed $name = null;

    public function escapes(bool $shared): void
    {
        $out = null;

        Redis::withConnection(function (RedisConnection $connection) {
            $this->client ??= $connection->client();
            $GLOBALS['held'] ??= $connection->client();
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $out ??= $connection->client();
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();
            $this->client = $shared ? $client : null;
            $this->client = $client ?: null;
            $this->client = $client ?? null;
            $this->client = match ($shared) {
                true => $client,
                false => null,
            };
            go($shared ? fn () => $connection->get('key') : fn () => null);
            $task = $shared ? function () use ($connection) {
                $connection->get('key');
            } : null;
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $shared ? $connection->client() : null;
            [$this->client, $local] = [$connection->client(), 'local'];
            $this->name = $client ??= 'other';
        });
        Redis::withConnection(function (RedisConnection $connection) use (&$out) {
            $alias = &$out;
            $alias = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $alias = &$GLOBALS['held'];
            $alias = $connection;
            $GLOBALS['other'] = &$local;
            $local = $connection;
            $first = &$GLOBALS['chained'];
            $second = &$first;
            $second = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $alias = &$GLOBALS['held'];
            $store = function () use (&$alias, $connection) {
                $alias = $connection;
            };
            $alias = &$local;
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $shared ? $connection->client() : null;
            $client ??= 'other';

            return $client;
        });
    }

    public function staysInside(): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            [$local, $this->name] = [$connection->client(), 'name'];
            ['name' => $this->name] = ['client' => $connection->client(), 'name' => 'safe'];
        });
        Redis::withConnection(function (RedisConnection $connection) {
            global $held;
            $held = &$local;
            $local = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $alias = &$GLOBALS['held'];
            unset($alias);
            $alias = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $store = function () use (&$alias, $connection) {
                $alias = $connection;
            };
            $alias = &$GLOBALS['held'];
            $store();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [$connection];
            global $held;
            [&$held] = $items;
            $held = null;
        });
    }
}

class ControlFlow
{
    public mixed $client = null;

    public mixed $callback = null;

    public function escapes(bool $shared, int $mode, array $rows, ?RedisConnection $other): void
    {
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();

            if ($shared) {
                unset($client);
            } else {
                return $client;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $clients = [];

            if ($shared) {
                $clients[] = $connection->client();
            } else {
                $clients = [];
            }

            return $clients;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            if ($shared) {
                global $held;
            }
            $held = $connection;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };

            if ($shared) {
                unset($client);
            }

            $client = $connection->client();
            go($task);
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $clients = [];

            foreach ($rows as $row) {
                $task = fn () => $clients;
                $clients[] = $connection->client();
            }

            return $task;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };

            foreach ($rows as $row) {
                go($task);
                $client = $connection->client();
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows, $shared) {
            $clients = [];

            foreach ($rows as $row) {
                $clients[] = $connection->client();

                if ($shared) {
                    return $clients;
                }

                $clients = [];
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();

            foreach ($rows as $row) {
                $this->client = $client;
                $client = null;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };

            for ($index = 0; $index < 2; ++$index) {
                $client = $connection;
                $this->callback = $task;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $found = null;

            foreach ($rows as $row) {
                $found = $connection->client();

                break;
            }

            return $found;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = null;

            foreach ($rows as $row) {
                foreach ($rows as $inner) {
                    $client = $connection->client();

                    break 2;
                }

                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $last = null;

            foreach ($rows as $row) {
                $last = $connection->client();

                if ($row) {
                    continue;
                }

                $last = null;
            }

            return $last;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();

            foreach ($rows as $row) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;

            do {
                $client = $connection->client();
            } while ($shared);

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();

            foreach ([...$rows] as $row) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $keys = ['key'];

            if ($rows === []) {
                $keys = [];
            }

            $client = $connection;

            foreach ($keys as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $keys = ['key'];
            unset($keys[0]);
            $client = $connection;

            foreach ($keys as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $keys = ['key'];
            array_pop($keys);
            $client = $connection;

            foreach ($keys as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $alias = &$keys;
            $keys = ['key'];
            $alias = [];
            $client = $connection;

            foreach ($keys as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($mode) {
            $client = null;

            switch ($mode) {
                case 1:
                    $client = $connection->client();
                    // no break
                case 2:
                    return $client;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($mode) {
            $client = $connection->client();

            switch ($mode) {
                case 1:
                    return $client;
                case ($client = null) ?? 2:
                    break;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($mode) {
            $client = $connection->client();

            return match ($mode) {
                1, ($client = null) ?? 2 => $client,
                default => null,
            };
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                return $client;
            } finally {
                $client = null;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            try {
                $client = $connection->client();
                $client->ping();
                $client = null;
            } catch (Throwable $exception) {
                return $client;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;

            try {
                if ($shared) {
                    $client = $connection->client();

                    return null;
                }
            } finally {
                $this->client = $client;
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                $client->ping();
                $client = null;
            } finally {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;

            try {
                if ($shared) {
                    return null;
                }

                $client = $connection->client();
            } finally {
                $store = function () use ($client) {
                    $this->client = $client;
                };
                $store();
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                try {
                    $client->ping();
                } finally {
                    $client->close();
                }
            } catch (Throwable $exception) {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $value = $connection;

            try {
                $value = $connection->get('key');
            } catch (LogicException) {
                $value = null;
            } finally {
                $this->client = $value;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $value = $connection;

            try {
                throw new LogicException();
            } finally {
                $this->client = $value;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $client ??= $connection->client();

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $client ??= throw new LogicException();

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($other) {
            $client = $connection->client();
            $other?->get(throw new LogicException());

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $clear = function () use (&$value) {
                $value = null;
            };
            $value = 'value';
            $clear();
            $value ??= $connection;

            return $value;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();

            if ($shared) {
                return [$client, ($client = null)];
            }

            return null;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            static $child = go(fn () => $connection->get('key'));
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $this->{go(fn () => $connection)}();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [];
            [$items[go(fn () => $connection)]] = ['item'];
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $items = [];

            foreach ($rows as $items[go(fn () => $connection)]) {
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $items = [];
            [$this->client, $items[throw new LogicException()]] = [$connection, 'item'];
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();
            $items = [];

            foreach ($rows as $client => $items[go(fn () => $client)]) {
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $items = [];
            $source = ['first', 'second'];
            [$items[go(fn () => $client)], &$client] = $source;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            yield $connection->get('key');
        });
        Redis::withConnection(fn (RedisConnection $connection) => yield $connection->get('key'));
        DB::withPinnedSession(function () {
            yield DB::getPdo();
        });
    }

    public function staysInside(bool $shared, int $mode, array $rows, PdoConnection $database, PdoConnection $replica): void
    {
        Redis::withConnection(function (RedisConnection $connection) {
            foreach (['first', 'second'] as $row) {
                $clients = [];
                $task = fn () => $clients;
                $clients[] = $connection->client();
            }

            return $task;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $clients = [];

            foreach ($rows as $row) {
                $clients[] = $connection->client();
                $clients = [];
            }

            return $clients;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = null;
            $task = function () use (&$client) {
                return $client;
            };

            for ($index = 0; $index < 2; ++$index) {
                unset($client);
                $client = $connection;
                $this->callback = $task;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            while (true) {
                $client->ping();
                $client = null;

                break;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = $connection->client();

            do {
                $client = null;
            } while ($shared);

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = null;

            foreach ($rows as $row) {
                $client = $connection->client();

                try {
                    break;
                } finally {
                    $client = null;
                }
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($shared) {
            $client = null;

            foreach (['row'] as $row) {
                try {
                    if ($shared) {
                        $client = $connection->client();

                        return 'returned';
                    }

                    break;
                } finally {
                    $row = null;
                }
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            foreach (['key'] as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $keys = ['key'];
            $client = $connection;

            foreach ($keys as $key) {
                $client = null;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($mode) {
            $client = $connection->client();

            switch ($mode) {
                default:
                    $client = null;

                    break;
            }

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) use ($mode) {
            $client = $connection->client();

            return match ($mode) {
                default => $client,
                ($client = null) ?? 1 => 1,
            };
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = 'none';
            $client ??= $connection->client();

            return $client;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                $client = null;
                $this->refresh();
            } finally {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                try {
                    $client->ping();
                } finally {
                    $client = null;
                }
            } catch (Throwable $exception) {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                try {
                    $client->ping();
                } finally {
                    return null;
                }
            } catch (Throwable $exception) {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();

            try {
                $length = strlen(...);
                $client = null;
            } finally {
                $this->client = $client;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $value = $connection;

            try {
                $value = $connection->get('key');
            } catch (Throwable) {
                $value = null;
            } finally {
                $this->client = $value;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $value = $connection;

            try {
                throw new LogicException();
            } catch (Throwable) {
                $value = null;
            } finally {
                $this->client = $value;
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $items = [];
            [$client, $items[go(fn () => $client?->ping())]] = [null, 'item'];
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();
            $items = [];

            foreach ($rows as [$client, $items[go(fn () => $client)]]) {
            }
        });
        Redis::withConnection(function (RedisConnection $connection) use ($rows) {
            $client = $connection->client();
            $items = [];

            foreach ($rows as $items[go(fn () => $client)] => $client) {
            }
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $items = [];
            unset($client, $items[go(fn () => $client)]);
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $items = [];
            $source = ['first', 'second'];
            [&$client, $items[go(fn () => $client)]] = $source;
        });
        Redis::withConnection(function (RedisConnection $connection) {
            yield 'value';
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $keys = $connection->keys('*');

            return (function () use ($keys) {
                yield from $keys;
            })();
        });
        Redis::withConnection(function (RedisConnection $connection) {
            $connection = null;

            yield $connection;
        });
        $database->withPinnedSession(function () use ($database, $replica) {
            $database = $replica;

            yield $database->getPdo();
        });
    }

    private function refresh(): void
    {
    }
}
