<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Sentry\Logs\LogsHandler;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Mockery as m;
use Monolog\Handler\BufferHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventType;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;
use Sentry\SentrySdk;
use Swoole\Server;

class ServerForkTest extends TestCase
{
    private ServerForkRecordingHttpClient $httpClient;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        // The artisan binary marks the server process before the application boots.
        $app->setRunningInConsole(false);

        return parent::getPackageProviders($app);
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        // Let captured events reach the transport instead of stopping at the base test case's recorder
        $config->set('sentry.before_send', static fn (Event $event): Event => $event);
        $config->set('sentry.http_client', $this->httpClient = new ServerForkRecordingHttpClient);
        $config->set('sentry.enable_logs', true);
        $config->set('logging.channels.buffered_sentry_logs', [
            'driver' => 'monolog',
            'handler' => BufferHandler::class,
            'handler_with' => ['handler' => new LogsHandler],
        ]);

        $customize = $this->providedData()['customize'] ?? null;

        if ($customize instanceof Closure) {
            $customize($app);
        }
    }

    public function testRecordsBufferedByLogChannelsAreSentBeforeTheServerForks(): void
    {
        $this->app->make('log')->channel('buffered_sentry_logs')->info('Logged while the server boots');

        $this->assertSame([], $this->getCapturedSentryEventsOfType(EventType::logs()));

        $this->dispatchHypervelEvent(new BeforeServerFork(m::mock(Server::class)));

        $logs = $this->getCapturedSentryEventsOfType(EventType::logs());

        $this->assertCount(1, $logs);
        $this->assertSame('Logged while the server boots', $logs[0][0]->getLogs()[0]->getBody());

        // A forked process flushes what it inherited, which must no longer include these records.
        SentrySdk::flush();

        $this->assertCount(1, $this->getCapturedSentryEventsOfType(EventType::logs()));
    }

    #[DataProvider('forkedProcessStarts')]
    public function testServerSendsWithoutConnectionSharingUntilAForkedProcessStarts(Closure $start): void
    {
        $hub = $this->getSentryHubFromContainer();

        $hub->captureMessage('Sent while the server boots');
        $hub->getClient()->flush(1);

        $this->dispatchHypervelEvent($start($this->app));

        $hub->captureMessage('Sent by a forked process');
        $hub->getClient()->flush(1);

        $this->assertSame([false, true], array_column($this->httpClient->sends, 'shareHandle'));
        $this->assertSame($this->httpClient->sends[0]['options'], $this->httpClient->sends[1]['options']);
        $this->assertTrue($this->app->make(ClientBuilder::class)->getOptions()->isShareHandleEnabled());
    }

    /**
     * Get the events that start a forked server process.
     */
    public static function forkedProcessStarts(): iterable
    {
        yield 'worker' => ['start' => static fn (): BeforeWorkerStart => new BeforeWorkerStart(m::mock(Server::class), 0)];

        yield 'custom process' => ['start' => static function (ApplicationContract $app): BeforeProcessHandle {
            // Keep the process start log line out of the test output.
            $app->instance(StdoutLoggerInterface::class, m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing());

            return new BeforeProcessHandle(new ServerForkProcess($app), 0);
        }];
    }

    #[DataProvider('sharingChoices')]
    public function testForkedProcessesRestoreOnlyTheChosenSetting(Closure $customize, bool $sharesAfterStart): void
    {
        $options = $this->getSentryClientFromContainer()->getOptions();

        $this->assertFalse($options->isShareHandleEnabled());

        $this->dispatchHypervelEvent(new BeforeWorkerStart(m::mock(Server::class), 0));

        $this->assertSame($sharesAfterStart, $options->isShareHandleEnabled());
    }

    /**
     * Get the ways an application can choose the connection sharing setting.
     */
    public static function sharingChoices(): iterable
    {
        yield 'option disabled' => [
            'customize' => static function (ApplicationContract $app): void {
                $app->make('config')->set('sentry.http_enable_curl_share_handle', false);
            },
            'sharesAfterStart' => false,
        ];

        yield 'disabled by a builder extender' => [
            'customize' => static function (ApplicationContract $app): void {
                $app->extend(ClientBuilder::class, static function (ClientBuilder $clientBuilder): ClientBuilder {
                    $clientBuilder->getOptions()->setEnableShareHandle(false);

                    return $clientBuilder;
                });
            },
            'sharesAfterStart' => false,
        ];

        yield 'enabled by a builder extender over a disabled option' => [
            'customize' => static function (ApplicationContract $app): void {
                $app->make('config')->set('sentry.http_enable_curl_share_handle', false);
                $app->extend(ClientBuilder::class, static function (ClientBuilder $clientBuilder): ClientBuilder {
                    $clientBuilder->getOptions()->setEnableShareHandle(true);

                    return $clientBuilder;
                });
            },
            'sharesAfterStart' => true,
        ];
    }
}

class ServerForkRecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{options: Options, shareHandle: bool}> */
    public array $sends = [];

    /**
     * Record the connection sharing setting the request was sent with.
     */
    public function sendRequest(Request $request, Options $options): Response
    {
        $this->sends[] = ['options' => $options, 'shareHandle' => $options->isShareHandleEnabled()];

        return new Response(200, [], '');
    }
}

class ServerForkProcess extends AbstractProcess
{
    /**
     * Handle the test process.
     */
    public function handle(): void
    {
    }
}
