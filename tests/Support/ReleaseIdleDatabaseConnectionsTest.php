<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Aws\Credentials\Credentials;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Mail\MailManager;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;

class ReleaseIdleDatabaseConnectionsTest extends TestCase
{
    /**
     * Configure a pool with a single physical session.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.sessions', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => ['testing_enabled' => true, 'min_retained_connections' => 1, 'max_connections' => 1],
        ]);
    }

    #[DataProvider('commands')]
    public function testClientsReturnIdleSessionsBeforeFetchingCredentialsAndSending(string $consumer, bool $transaction, int $idle): void
    {
        $pool = $this->app->make(PoolManager::class)->pool('sessions');
        $observed = [];
        $configuration = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'https://aws.example.test',
            'retries' => 0,
            'pool' => ['fingerprint' => 'sessions'],
            'credentials' => static function () use ($pool, &$observed): PromiseInterface {
                $observed['credentials'] = $pool->getIdleCount();

                return Create::promiseFor(new Credentials('key', 'secret'));
            },
            'http_handler' => static function () use ($pool, &$observed, $consumer): PromiseInterface {
                $observed['request'] = $pool->getIdleCount();

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], match ($consumer) {
                    'sqs' => '{"Attributes":{"ApproximateNumberOfMessages":"0","ApproximateNumberOfMessagesDelayed":"0","ApproximateNumberOfMessagesNotVisible":"0"}}',
                    'ses' => '{"MessageId":"message-id"}',
                    's3' => '',
                }));
            },
        ];

        switch ($consumer) {
            case 'sqs':
                config(['queue.connections.sessions' => $configuration + ['driver' => 'sqs', 'queue' => 'sessions', 'prefix' => 'https://aws.example.test/account']]);
                $queue = $this->app->make(QueueManager::class)->connection('sessions');
                $command = static fn (): int => $queue->size();
                break;
            case 's3':
                config(['filesystems.disks.sessions' => $configuration + ['driver' => 's3', 'bucket' => 'sessions', 'use_path_style_endpoint' => true]]);
                $disk = $this->app->make(FilesystemManager::class)->disk('sessions');
                $command = static fn (): bool => $disk->fileExists('example.txt');
                break;
            case 'ses':
                config(['mail.mailers.sessions' => $configuration + ['transport' => 'ses-v2']]);
                $transport = $this->app->make(MailManager::class)->mailer('sessions')->getSymfonyTransport();
                $command = static fn (): string => $transport->send(
                    (new Email)->from('sender@example.test')->to('recipient@example.test')->text('Hello'),
                )->getMessageId();
                break;
        }

        $connection = DB::connection('sessions');
        $connection->selectOne('select 1');
        $transaction ? $connection->transaction($command) : $command();

        $this->assertSame(['credentials' => $idle, 'request' => $idle], $observed);
    }

    /**
     * Provide each SDK consumer's command with an idle session, and one inside a transaction, whose session stays pinned.
     */
    public static function commands(): array
    {
        return [
            'SQS' => ['sqs', false, 1],
            'S3' => ['s3', false, 1],
            'SES v2' => ['ses', false, 1],
            'S3 in a transaction' => ['s3', true, 0],
        ];
    }
}
