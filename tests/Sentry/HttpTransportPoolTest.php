<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Sentry\Transport\HttpTransportPool;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\HttpClient\HttpClient;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Response;
use Sentry\Transport\ResultStatus;
use Stringable;

class HttpTransportPoolTest extends TestCase
{
    use VerifiesDoubles;

    public function testEachTransportGetsItsOwnDefaultClientIdentifiedByTheBuilder(): void
    {
        $builder = new ClientBuilder;
        $builder->setSdkIdentifier('sentry.php.custom');
        $builder->setSdkVersion('1.2.3');
        $pool = new HttpTransportPool($builder, $this->poolOptions(2));
        $first = $pool->borrow();
        $second = $pool->borrow();

        $firstClient = $first->getHttpClient();

        $this->assertInstanceOf(HttpClient::class, $firstClient);
        $this->assertNotSame($firstClient, $second->getHttpClient());
        $this->assertSame('sentry.php.custom', (new ReflectionProperty($firstClient, 'sdkIdentifier'))->getValue($firstClient));
        $this->assertSame('1.2.3', (new ReflectionProperty($firstClient, 'sdkVersion'))->getValue($firstClient));

        $pool->release($first);
        $pool->release($second);
        $pool->close();
    }

    public function testTransportsShareTheBuilderClientAndLogThroughTheBuilderLogger(): void
    {
        $httpClient = Double::for(HttpClientInterface::class)->strict();
        $httpClient->expects('sendRequest')
            ->times(2)
            ->returns(new Response(200, [], ''));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /**
             * Record the log message.
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $builder = ClientBuilder::create(['dsn' => 'https://public@example.com/1']);
        $builder->setHttpClient($httpClient);
        $builder->setLogger($logger);
        $pool = new HttpTransportPool($builder, $this->poolOptions(2));
        $first = $pool->borrow();
        $second = $pool->borrow();

        $this->assertSame($httpClient, $first->getHttpClient());
        $this->assertSame($httpClient, $second->getHttpClient());
        $this->assertSame(ResultStatus::success(), $first->send(Event::createEvent())->getStatus());
        $this->assertSame(ResultStatus::success(), $second->send(Event::createEvent())->getStatus());
        $this->assertCount(2, array_filter($logger->messages, static fn (string $message): bool => str_starts_with($message, 'Sending ')));

        $pool->release($first);
        $pool->release($second);
        $pool->close();
    }

    public function testPooledTransportRetainsRateLimitsFromRealResponses(): void
    {
        $httpClient = Double::for(HttpClientInterface::class)->strict();
        $httpClient->expects('sendRequest')
            ->returns(new Response(429, [
                'X-Sentry-Rate-Limits' => ['60:error'],
            ], ''));

        $builder = ClientBuilder::create(['dsn' => 'https://public@example.com/1']);
        $builder->setHttpClient($httpClient);
        $pool = new HttpTransportPool($builder, $this->poolOptions());

        $transport = $pool->borrow();
        $this->assertSame(ResultStatus::rateLimit(), $transport->send(Event::createEvent())->getStatus());
        $pool->release($transport);

        $sameTransport = $pool->borrow();
        $this->assertSame($transport, $sameTransport);
        $this->assertSame(ResultStatus::rateLimit(), $sameTransport->send(Event::createEvent())->getStatus());
        $pool->release($sameTransport);
        $pool->close();
    }

    /**
     * Get pool options for the given number of reusable transports.
     */
    private function poolOptions(int $maxObjects = 1): PoolOptions
    {
        return PoolOptions::fromArray([
            'min_retained_objects' => 0,
            'max_objects' => $maxObjects,
            'max_lifetime' => null,
            'pool_idle_timeout' => null,
        ]);
    }
}
