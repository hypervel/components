<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\Connectors\BackgroundConnector;
use Hypervel\Queue\Connectors\BeanstalkdConnector;
use Hypervel\Queue\Connectors\DatabaseConnector;
use Hypervel\Queue\Connectors\DeferredConnector;
use Hypervel\Queue\Connectors\FailoverConnector;
use Hypervel\Queue\Connectors\RedisConnector;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\Connectors\SyncConnector;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\RedisQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Pheanstalk\Pheanstalk;

class QueueConfigTest extends TestCase
{
    use VerifiesDoubles;

    public function testConcurrencyNumberIsLoadedAsIntegerFromEnvironment(): void
    {
        $this->withEnvironment(['QUEUE_CONCURRENCY' => '5'], function (): void {
            $config = $this->loadConfig();

            $this->assertSame(5, $config['concurrency']);
        });
    }

    public function testSqsOverflowStorageHasSecureDefaults(): void
    {
        $this->withEnvironment([
            'SQS_OVERFLOW_ENABLED' => null,
            'SQS_OVERFLOW_STORE' => null,
            'SQS_OVERFLOW_FLUSH_ON_CLEAR' => null,
        ], function (): void {
            $config = $this->loadConfig();

            $this->assertSame([
                'enabled' => false,
                'store' => null,
                'always' => false,
                'delete_after_processing' => true,
                'flush_on_clear' => false,
            ], $config['connections']['sqs']['overflow']);
        });
    }

    public function testFileFailedJobStorageDefaultsAreOwnedByTheProvider(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayNotHasKey('path', $config['failed']);
        $this->assertArrayNotHasKey('limit', $config['failed']);
    }

    public function testConnectorAfterCommitOmissionDefaultsMatchShippedConnections(): void
    {
        $connections = $this->loadConfig()['connections'];

        foreach ($connections as $name => $connection) {
            $connector = match ($name) {
                'sync' => new SyncConnector,
                'background' => new BackgroundConnector,
                'deferred' => new DeferredConnector,
                'database' => new DatabaseConnector(Double::for(ConnectionResolverInterface::class)->strict()),
                'beanstalkd' => new BeanstalkdConnector,
                'sqs' => new SqsConnector,
                'redis' => new RedisConnector(Double::for(RedisFactory::class)->strict()),
                'failover' => new FailoverConnector(
                    Double::for(QueueManager::class)->strict(),
                    Double::for(Dispatcher::class)->strict(),
                ),
            };
            $constructionConfig = $connection;
            unset($constructionConfig['after_commit'], $constructionConfig['pool']);

            $queue = $connector->connect($constructionConfig);

            $this->assertSame(
                $connection['after_commit'],
                (new ClassInvoker($queue))->dispatchAfterCommit,
                "The [{$name}] connector default does not match its shipped config.",
            );
        }
    }

    public function testConnectorOptionalMemberOmissionUsesOwnedDefaults(): void
    {
        $databaseQueue = (new DatabaseConnector(Double::for(ConnectionResolverInterface::class)->strict()))->connect([
            'table' => 'jobs',
            'queue' => 'default',
        ]);
        $databaseQueueProperties = new ClassInvoker($databaseQueue);

        $this->assertNull($databaseQueueProperties->connection);
        $this->assertSame(DatabaseQueue::DEFAULT_RETRY_AFTER, $databaseQueueProperties->retryAfter);

        $beanstalkdQueue = (new BeanstalkdConnector)->connect([
            'host' => 'localhost',
            'port' => 11300,
            'queue' => 'default',
        ]);
        $beanstalkdQueueProperties = new ClassInvoker($beanstalkdQueue);

        $this->assertSame(Pheanstalk::DEFAULT_TTR, $beanstalkdQueueProperties->timeToRun);
        $this->assertSame(0, $beanstalkdQueueProperties->blockFor);

        $redisQueue = (new RedisConnector(Double::for(RedisFactory::class)->strict()))->connect([
            'queue' => 'default',
        ]);
        $redisQueueProperties = new ClassInvoker($redisQueue);

        $this->assertNull($redisQueueProperties->connection);
        $this->assertSame(RedisQueue::DEFAULT_RETRY_AFTER, $redisQueueProperties->retryAfter);
        $this->assertNull($redisQueueProperties->blockFor);
    }

    protected function loadConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/queue.php';
    }

    protected function withEnvironment(array $variables, Closure $callback): mixed
    {
        $original = [];

        foreach ($variables as $key => $value) {
            $original[$key] = [
                'putenv' => getenv($key),
                'serverExists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'envExists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];

            unset($_SERVER[$key], $_ENV[$key]);

            $value === null ? putenv($key) : putenv("{$key}={$value}");
        }

        Env::flushRepository();

        try {
            return $callback();
        } finally {
            foreach ($original as $key => $value) {
                $value['putenv'] === false
                    ? putenv($key)
                    : putenv("{$key}={$value['putenv']}");

                if ($value['serverExists']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($value['envExists']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::flushRepository();
        }
    }
}
