<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\TransportSelectionTest;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Version;
use Hypervel\Tests\Sentry\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class TransportSelectionTest extends TestCase
{
    private RecordingTransport $transport;

    private RecordingHttpClient $httpClient;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        // Let captured events reach the transport instead of stopping at the base test case's recorder
        $app->make('config')->set('sentry.before_send', static fn (Event $event): Event => $event);

        $this->transport = new RecordingTransport;
        $this->httpClient = new RecordingHttpClient;

        $customize = $this->providedData()[0] ?? null;

        if ($customize instanceof Closure) {
            $customize($app, $this->transport, $this->httpClient);
        }
    }

    #[DataProvider('transportCustomizations')]
    public function testCapturedEventsUseTheConfiguredTransport(Closure $customize): void
    {
        $this->getSentryHubFromContainer()->captureMessage('custom transport');

        $this->assertCount(1, $this->transport->events);
        $this->assertSame('custom transport', $this->transport->events[0]->getMessage());
    }

    /**
     * Get the ways an application can supply its own transport.
     */
    public static function transportCustomizations(): iterable
    {
        yield 'transport option' => [static function (ApplicationContract $app, TransportInterface $transport): void {
            $app->make('config')->set('sentry.transport', $transport);
        }];

        yield 'options transport set by a builder extender' => [static function (ApplicationContract $app, TransportInterface $transport): void {
            $app->extend(ClientBuilder::class, static function (ClientBuilder $clientBuilder) use ($transport): ClientBuilder {
                $clientBuilder->getOptions()->setTransport($transport);

                return $clientBuilder;
            });
        }];

        yield 'builder transport set by a builder extender' => [static function (ApplicationContract $app, TransportInterface $transport): void {
            $app->extend(ClientBuilder::class, static function (ClientBuilder $clientBuilder) use ($transport): ClientBuilder {
                return $clientBuilder->setTransport($transport);
            });
        }];
    }

    #[DataProvider('httpClientCustomizations')]
    public function testPooledTransportsSendThroughTheConfiguredHttpClient(Closure $customize): void
    {
        $this->getSentryHubFromContainer()->captureMessage('custom client');
        $this->getSentryClientFromContainer()->flush(1);

        $this->assertCount(1, $this->httpClient->requests);
        $this->assertStringContainsString('custom client', $this->httpClient->requests[0]->getStringBody());
    }

    /**
     * Get the ways an application can supply its own HTTP client.
     */
    public static function httpClientCustomizations(): iterable
    {
        yield 'http_client option' => [static function (ApplicationContract $app, TransportInterface $transport, HttpClientInterface $httpClient): void {
            $app->make('config')->set('sentry.http_client', $httpClient);
        }];

        yield 'builder HTTP client set by a builder extender' => [static function (ApplicationContract $app, TransportInterface $transport, HttpClientInterface $httpClient): void {
            $app->extend(ClientBuilder::class, static function (ClientBuilder $clientBuilder) use ($httpClient): ClientBuilder {
                return $clientBuilder->setHttpClient($httpClient);
            });
        }];
    }

    public function testPooledTransportsIdentifyTheHypervelSdk(): void
    {
        $transport = $this->getSentryClientFromContainer()->getTransport();
        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $pool = (new ReflectionProperty(HttpPoolTransport::class, 'pool'))->getValue($transport);
        $httpTransport = $pool->borrow();

        try {
            $httpClient = $httpTransport->getHttpClient();

            $this->assertSame(Version::SDK_IDENTIFIER, (new ReflectionProperty($httpClient, 'sdkIdentifier'))->getValue($httpClient));
            $this->assertSame(Version::getSdkVersion(), (new ReflectionProperty($httpClient, 'sdkVersion'))->getValue($httpClient));
        } finally {
            $pool->release($httpTransport);
        }
    }
}

class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    /**
     * Record the event.
     */
    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    /**
     * Report that nothing is pending.
     */
    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<Request> */
    public array $requests = [];

    /**
     * Record the request and accept it.
     */
    public function sendRequest(Request $request, Options $options): Response
    {
        $this->requests[] = $request;

        return new Response(200, [], '');
    }
}
