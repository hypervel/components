<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Credentials\Credentials;
use Aws\Credentials\EcsCredentialProvider;
use Aws\Credentials\InstanceProfileProvider;
use Closure;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;

class QueueSqsConnectorTest extends TestCase
{
    public function testConnectSucceedsWithoutAfterCommitConfig(): void
    {
        $connector = new SqsConnector;

        $queue = $connector->connect($this->config());

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }

    public function testConnectBuildsStaticCredentialsWithToken(): void
    {
        $queue = (new SqsConnector)->connect($this->config([
            'key' => 'key',
            'secret' => 'secret',
            'token' => 'token',
        ]));

        $credentials = $queue->getSqs()->getCredentials()->wait();

        $this->assertSame('key', $credentials->getAccessKeyId());
        $this->assertSame('secret', $credentials->getSecretKey());
        $this->assertSame('token', $credentials->getSecurityToken());
        $this->assertNull($queue->getSqs()->getConfig('token'));
    }

    public function testCallableAndObjectCredentialsPassThrough(): void
    {
        $connector = new QueueSqsConnectorStub;
        $callable = static fn () => null;
        $object = new Credentials('key', 'secret');

        $this->assertSame($callable, $connector->resolveCredentials([
            'credentials' => $callable,
        ]));
        $this->assertSame($object, $connector->resolveCredentials([
            'credentials' => $object,
        ]));
    }

    #[DataProvider('namedCredentialProvider')]
    public function testNamedCredentialProvidersReceiveOptionsAndAreMemoized(
        string $provider,
        array $options,
        string $expectedClass,
        string $option,
        mixed $expected,
    ): void {
        $credentials = (new QueueSqsConnectorStub)->resolveCredentials([
            'credentials' => ['provider' => $provider, ...$options],
        ]);

        $this->assertInstanceOf(Closure::class, $credentials);

        $resolved = (new ReflectionFunction($credentials))
            ->getClosureUsedVariables()['provider'];

        $this->assertInstanceOf($expectedClass, $resolved);
        $this->assertSame($expected, (new ClassInvoker($resolved))->{$option});
    }

    public static function namedCredentialProvider(): array
    {
        return [
            'ecs' => ['ecs', ['timeout' => 2], EcsCredentialProvider::class, 'timeout', 2],
            'instance' => ['instance', ['profile' => 'worker'], InstanceProfileProvider::class, 'profile', 'worker'],
        ];
    }

    public function testInvalidNamedCredentialProviderFailsDescriptively(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credential provider [invalid].');

        (new QueueSqsConnectorStub)->resolveCredentials([
            'credentials' => ['provider' => 'invalid'],
        ]);
    }

    public function testOverflowOptionsArePassedToTheQueueButNotTheAwsClient(): void
    {
        $overflow = [
            'enabled' => true,
            'store' => 'sqs-overflow',
        ];

        $queue = (new SqsConnector)->connect($this->config([
            'overflow' => $overflow,
        ]));

        $this->assertSame($overflow, (new ClassInvoker($queue))->overflowStorage);
        $this->assertNull($queue->getSqs()->getConfig('overflow'));
    }

    protected function config(array $overrides = []): array
    {
        return [
            'queue' => 'default',
            'region' => 'us-east-1',
            ...$overrides,
        ];
    }
}

class QueueSqsConnectorStub extends SqsConnector
{
    public function resolveCredentials(array $config): mixed
    {
        return $this->resolveCredentialProvider($config);
    }
}
