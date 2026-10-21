<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\ObjectPool\Factory as PoolFactory;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Mail\MailManager;
use Hypervel\Queue\QueueManager;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mime\Email;

use function Hypervel\Coroutine\parallel;

class AwsCredentialConsumerTest extends TestCase
{
    #[DataProvider('consumers')]
    public function testPooledConsumersSerializeSharedProvidersWithoutSharingCallerCredentials(string $consumer): void
    {
        $active = 0;
        $maximumActive = 0;
        $authorization = [];
        $provider = static function () use (&$active, &$maximumActive): PromiseInterface {
            ++$active;
            $maximumActive = max($maximumActive, $active);
            $promise = new Promise(static function () use (&$promise, &$active): void {
                usleep(1000);
                --$active;
                $promise->resolve(new Credentials(CoroutineContext::get('__test.aws.tenant'), 'secret'));
            });

            return $promise;
        };
        $configuration = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'https://aws.example.test',
            'credentials' => $provider,
            'retries' => 0,
            'pool' => ['fingerprint' => 'shared-provider'],
            'http_handler' => static function (RequestInterface $request, array $options) use (&$authorization, $consumer): PromiseInterface {
                $authorization[CoroutineContext::get('__test.aws.tenant')][] = $request->getHeaderLine('Authorization');

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], match ($consumer) {
                    'sqs' => '{"Attributes":{"ApproximateNumberOfMessages":"0","ApproximateNumberOfMessagesDelayed":"0","ApproximateNumberOfMessagesNotVisible":"0"}}',
                    'ses' => '{"MessageId":"message-id"}',
                    's3' => '',
                }));
            },
        ];

        switch ($consumer) {
            case 'sqs':
                $configuration += ['driver' => 'sqs', 'queue' => 'credentials', 'prefix' => 'https://aws.example.test/account'];
                config(['queue.connections.first' => $configuration, 'queue.connections.second' => $configuration]);
                $manager = $this->app->make(QueueManager::class);
                $first = $manager->connection('first');
                $second = $manager->connection('second');
                $operation = static fn (object $connection): int => $connection->size();
                break;
            case 's3':
                $configuration += ['driver' => 's3', 'bucket' => 'credentials', 'use_path_style_endpoint' => true];
                config(['filesystems.disks.first' => $configuration, 'filesystems.disks.second' => $configuration]);
                $manager = $this->app->make(FilesystemManager::class);
                $first = $manager->disk('first');
                $second = $manager->disk('second');
                $operation = static fn (object $disk): bool => $disk->fileExists('example.txt');
                break;
            case 'ses':
                $configuration += ['transport' => 'ses-v2'];
                config(['mail.mailers.first' => $configuration, 'mail.mailers.second' => $configuration]);
                $manager = $this->app->make(MailManager::class);
                $first = $manager->mailer('first')->getSymfonyTransport();
                $second = $manager->mailer('second')->getSymfonyTransport();
                $operation = static fn (object $transport): string => $transport->send(
                    (new Email)->from('sender@example.test')->to('recipient@example.test')->text('Hello'),
                )->getMessageId();
                break;
        }

        $this->assertSame($first->getPoolName(), $second->getPoolName());
        $pools = $this->app->make(PoolFactory::class);

        try {
            $results = parallel([
                static function () use ($first, $operation): mixed {
                    CoroutineContext::set('__test.aws.tenant', 'tenant-first');

                    return $operation($first);
                },
                static function () use ($second, $operation): mixed {
                    CoroutineContext::set('__test.aws.tenant', 'tenant-second');

                    return $operation($second);
                },
            ]);

            $this->assertCount(2, $results);
            $this->assertSame(1, $maximumActive);
            $this->assertSame(0, $active);
            $this->assertCount(2, $authorization);
            $this->assertCount(1, $authorization['tenant-first']);
            $this->assertCount(1, $authorization['tenant-second']);
            $this->assertStringContainsString('Credential=tenant-first/', $authorization['tenant-first'][0]);
            $this->assertStringContainsString('Credential=tenant-second/', $authorization['tenant-second'][0]);
            $this->assertCount(1, $pools->getPools());
            $this->assertArrayHasKey($first->getPoolName(), $pools->getPools());
        } finally {
            $pools->purgeAll();
        }
    }

    /**
     * Provide SDK consumers that share custom credential providers across pooled clients.
     */
    public static function consumers(): array
    {
        return ['SQS' => ['sqs'], 'S3' => ['s3'], 'SES v2' => ['ses']];
    }

    #[DataProvider('credentialExpirations')]
    public function testSdkCallbacksCanReenterASharedMemoizedProvider(int $expiresIn): void
    {
        $fetches = 0;
        $requests = 0;
        $provider = CredentialProvider::memoize(static function () use (&$fetches, $expiresIn): PromiseInterface {
            ++$fetches;
            $promise = new Promise(static function () use (&$promise, $expiresIn): void {
                $promise->resolve(new Credentials('key', 'secret', null, time() + $expiresIn));
            });

            return $promise;
        });
        $configuration = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'bucket' => 'credentials',
            'credentials' => $provider,
            'retries' => 0,
            'http_handler' => static function (RequestInterface $request) use (&$requests): PromiseInterface {
                ++$requests;
                self::assertStringContainsString('Credential=key/', $request->getHeaderLine('Authorization'));

                return Create::promiseFor(new Response(200, [], '<ListAllMyBucketsResult><Buckets/></ListAllMyBucketsResult>'));
            },
        ];
        $manager = $this->app->make(FilesystemManager::class);
        $first = $manager->createS3Driver($configuration)->getClient();
        $configuration['credentials'] = [$provider, '__invoke'];
        $second = $manager->createS3Driver($configuration)->getClient();

        $pending = $first->listBucketsAsync()->then(static fn (): PromiseInterface => $second->listBucketsAsync());
        $this->assertSame([], $first->listBuckets()['Buckets']);
        $this->assertSame([], $pending->wait()['Buckets']);
        $this->assertSame([[]], parallel([static fn (): array => $second->listBuckets()['Buckets']]));
        $this->assertSame(4, $requests);

        if ($expiresIn > CredentialProvider::REFRESH_WINDOW) {
            $this->assertSame(1, $fetches);
        } else {
            $this->assertGreaterThan(1, $fetches);
        }
    }

    /**
     * Provide credentials outside and inside the SDK's refresh window.
     */
    public static function credentialExpirations(): array
    {
        return ['cached' => [3600], 'refreshing' => [30]];
    }
}
