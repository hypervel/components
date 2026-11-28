<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\CacheBasedMaintenanceMode;
use Hypervel\Foundation\Console\DownCommand;
use Hypervel\Foundation\Console\UpCommand;
use Hypervel\Foundation\Events\MaintenanceModeDisabled;
use Hypervel\Foundation\Events\MaintenanceModeEnabled;
use Hypervel\Foundation\FileBasedMaintenanceMode;
use Hypervel\Foundation\Http\MaintenanceModeBypassCookie;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class MaintenanceModeTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            @unlink(storage_path('framework/down'));
            @unlink(resource_path('views/errors/503.blade.php'));
        });

        parent::setUp();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        FailingRecyclingDownCommand::$recycleAttempted = false;
        FailingRecyclingDownCommand::$recycleFailure = null;
        FailingRecyclingUpCommand::$recycleAttempted = false;
        FailingRecyclingUpCommand::$recycleFailure = null;

        parent::tearDown();
    }

    public function testBasicMaintenanceModeResponse(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'refresh' => 60,
        ]));

        Route::get('/foo', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '60');
    }

    public function testCacheMaintenanceModeAllowsRequestWhenDeactivatedWhileReadingPayload(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->allows('store')->with('maintenance')->returns($cache);
        $cache->allows('has')->with('framework:down')->returns(true, false);
        $cache->expects('get')->with('framework:down')->returns(null);

        $this->app->instance(MaintenanceModeContract::class, new CacheBasedMaintenanceMode(
            $cache,
            'maintenance',
            'framework:down'
        ));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $this->get('/foo')
            ->assertOk()
            ->assertSeeText('Hello World');
    }

    public function testActiveCacheMaintenanceModeWithAnEmptyPayloadBlocksRequests(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->allows('store')->with('maintenance')->returns($cache);
        $cache->allows('has')->with('framework:down')->returns(true);
        $cache->expects('get')->with('framework:down')->returns([]);

        $this->app->instance(MaintenanceModeContract::class, new CacheBasedMaintenanceMode(
            $cache,
            'maintenance',
            'framework:down'
        ));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $this->get('/foo')->assertServiceUnavailable();
    }

    public function testConcurrentMaintenanceFileRemovalAllowsTheRequestToProceed(): void
    {
        $path = storage_path('framework/down');
        $files = Double::for(Filesystem::class)->strict();
        $files->allows('exists')->with($path)->returns(true, false);
        $files->expects('get')->with($path)
            ->throws(new FileNotFoundException('removed'));
        $this->app->instance(MaintenanceModeContract::class, new FileBasedMaintenanceMode($files));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertOk();
        $this->assertSame('Hello World', $response->original);
    }

    public function testUnreadableMaintenanceFileDoesNotAllowTheRequestToProceed(): void
    {
        $this->withoutExceptionHandling();

        $path = storage_path('framework/down');
        $exception = new FileNotFoundException('unreadable');
        $files = Double::for(Filesystem::class)->strict();
        $files->allows('exists')->with($path)->returns(true);
        $files->expects('get')->with($path)->throws($exception);
        $this->app->instance(MaintenanceModeContract::class, new FileBasedMaintenanceMode($files));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        try {
            $this->get('/foo');

            $this->fail('Expected the read failure to be rethrown.');
        } catch (FileNotFoundException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testMaintenanceModeCanHaveCustomStatus(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'status' => 200,
        ]));

        Route::get('/foo', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(200);
        $response->assertHeader('Retry-After', '60');
    }

    public function testMaintenanceModeCanHaveCustomTemplate(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'template' => 'Rendered Content',
        ]));

        Route::get('/foo', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $this->assertSame('Rendered Content', $response->original);
    }

    public function testMaintenanceModeDoesNotUseCustomTemplateForJsonRequests(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'refresh' => 30,
            'template' => 'Rendered Content',
        ]));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->getJson('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '30');
        $response->assertJson(['message' => 'Service Unavailable']);
    }

    public function testMaintenanceModeDoesNotRedirectJsonRequests(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'refresh' => 30,
            'redirect' => '/maintenance',
        ]));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->getJson('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '30');
        $response->assertJson(['message' => 'Service Unavailable']);
    }

    // REMOVED: The maintenance.php stub test; requests reach the already-booted worker's middleware.

    public function testDownCommandPrerendersTemplateIntoMaintenancePayload(): void
    {
        file_put_contents(resource_path('views/errors/503.blade.php'), 'Rendered {{ $retryAfter }}');

        $this->artisan(DownCommand::class, [
            '--render' => 'errors::503',
            '--retry' => 60,
        ]);

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);

        $this->assertSame('Rendered 60', trim($data['template']));
        $this->assertFileDoesNotExist(storage_path('framework/maintenance.php'));
    }

    public function testDownCommandReportsARelativeBypassPathWithoutACanonicalApplicationUrl(): void
    {
        config(['app.url' => null]);

        $this->artisan(DownCommand::class, ['--secret' => 'bypass-secret'])
            ->expectsOutputToContain('You may bypass maintenance mode via [/bypass-secret].')
            ->assertExitCode(0);
    }

    public function testMaintenanceModeCanRedirectWithBypassCookie(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
            'template' => 'Rendered Content',
        ]));

        Route::get('/foo', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(302);
        $response->assertCookie('hypervel_maintenance');
    }

    public function testMaintenanceModeCanBeBypassedWithValidCookie(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
        ]));

        $cookie = MaintenanceModeBypassCookie::create('foo');

        Route::get('/test', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->withUnencryptedCookies([
            'hypervel_maintenance' => $cookie->getValue(),
        ])->get('/test');

        $response->assertStatus(200);
        $this->assertSame('Hello World', $response->original);
    }

    public function testMaintenanceModeCanBeBypassedOnExcludedUrls(): void
    {
        $this->app->instance(PreventRequestsDuringMaintenance::class, new class($this->app) extends PreventRequestsDuringMaintenance {
            protected array $except = ['/test'];
        });

        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
        ]));

        Route::get('/test', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/test');

        $response->assertStatus(200);
        $this->assertSame('Hello World', $response->original);
    }

    public function testMaintenanceModeCantBeBypassedWithInvalidCookie(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
        ]));

        $cookie = MaintenanceModeBypassCookie::create('test-key');

        Route::get('/test', function (): string {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->withUnencryptedCookies([
            'hypervel_maintenance' => $cookie->getValue(),
        ])->get('/test');

        $response->assertStatus(503);
    }

    public function testArrayBypassCookieDoesNotCauseAnError(): void
    {
        Exceptions::fake();
        file_put_contents(storage_path('framework/down'), json_encode(['secret' => 'foo']));
        Route::get('/test', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $this->withUnencryptedCookies(['hypervel_maintenance' => ['x' => '1']])
            ->get('/test')
            ->assertServiceUnavailable();

        Exceptions::assertNothingReported();
    }

    public function testCanCreateBypassCookies(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('hypervel_maintenance', $cookie->getName());

        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'wrong-key'));

        CarbonImmutable::setTestNow(now()->addMonths(6));
        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
    }

    public function testBypassCookieWithMalformedMacIsInvalid(): void
    {
        foreach ([['mac' => []], ['mac' => ['nested']], ['expires_at' => 9999999999]] as $payload) {
            $cookie = base64_encode(json_encode(array_merge(['expires_at' => 9999999999], $payload)));

            $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie, 'test-key'));
        }
    }

    public function testDispatchEventWhenMaintenanceModeIsEnabled(): void
    {
        Event::fake();

        Event::assertNotDispatched(MaintenanceModeEnabled::class);
        $this->artisan(DownCommand::class);
        Event::assertDispatched(MaintenanceModeEnabled::class);
    }

    public function testDispatchEventWhenMaintenanceModeIsDisabled(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'refresh' => 60,
        ]));

        Event::fake();

        Event::assertNotDispatched(MaintenanceModeDisabled::class);
        $this->artisan(UpCommand::class);
        Event::assertDispatched(MaintenanceModeDisabled::class);
    }

    public function testPassiveObserversDoNotCauseMaintenanceEventsToDispatch(): void
    {
        $observedEvents = [];
        $events = $this->app->make(Dispatcher::class);
        $events->observe(
            MaintenanceModeEnabled::class,
            static function (MaintenanceModeEnabled $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );
        $events->observe(
            MaintenanceModeDisabled::class,
            static function (MaintenanceModeDisabled $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $this->artisan(DownCommand::class)->assertSuccessful();
        $this->assertFileExists(storage_path('framework/down'));
        $this->artisan(UpCommand::class)->assertSuccessful();
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertSame([], $observedEvents);
    }

    public function testDownAttemptsRecyclingAfterEventFailureAndPreservesTheEventFailure(): void
    {
        $eventException = new RuntimeException('event failed');
        $recycleException = new RuntimeException('recycle failed');
        $reportException = new RuntimeException('report failed');
        $command = $this->app->make(FailingRecyclingDownCommand::class);
        FailingRecyclingDownCommand::$recycleFailure = $recycleException;
        $this->app->make(KernelContract::class)->registerCommand($command);
        $this->app->make('events')->listen(
            MaintenanceModeEnabled::class,
            static fn (): never => throw $eventException,
        );

        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($eventException), [])->throws($reportException);
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->artisan(FailingRecyclingDownCommand::class)
            ->expectsOutputToContain('The application is in maintenance mode, but a follow-up operation failed: event failed.')
            ->doesntExpectOutputToContain('Application is now in maintenance mode.')
            ->assertExitCode(1);

        $this->assertTrue(FailingRecyclingDownCommand::$recycleAttempted);
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function testDownReportsRecyclingFailureAfterMaintenanceStateIsCommitted(): void
    {
        $command = $this->app->make(FailingRecyclingDownCommand::class);
        FailingRecyclingDownCommand::$recycleFailure = new RuntimeException('recycle failed');
        $this->app->make(KernelContract::class)->registerCommand($command);

        $this->artisan(FailingRecyclingDownCommand::class)
            ->expectsOutputToContain('The application is in maintenance mode, but a follow-up operation failed: recycle failed.')
            ->doesntExpectOutputToContain('Application is now in maintenance mode.')
            ->assertExitCode(1);

        $this->assertTrue(FailingRecyclingDownCommand::$recycleAttempted);
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function testUpAttemptsRecyclingAfterEventFailureAndPreservesTheEventFailure(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode(['status' => 503]));

        $eventException = new RuntimeException('event failed');
        $command = $this->app->make(FailingRecyclingUpCommand::class);
        FailingRecyclingUpCommand::$recycleFailure = new RuntimeException('recycle failed');
        $this->app->make(KernelContract::class)->registerCommand($command);
        $this->app->make('events')->listen(
            MaintenanceModeDisabled::class,
            static fn (): never => throw $eventException,
        );

        $this->artisan(FailingRecyclingUpCommand::class)
            ->expectsOutputToContain('The application is live, but a follow-up operation failed: event failed.')
            ->doesntExpectOutputToContain('Application is now live.')
            ->assertExitCode(1);

        $this->assertTrue(FailingRecyclingUpCommand::$recycleAttempted);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function testDownReportsDriverFailureBeforeMaintenanceStateIsCommitted(): void
    {
        $mode = Double::for(MaintenanceModeContract::class)->strict();
        $mode->expects('active')->returns(false);
        $mode->expects('activate')->throws(new RuntimeException('activation failed'));
        $this->app->instance(MaintenanceModeContract::class, $mode);

        $this->artisan(DownCommand::class)
            ->expectsOutputToContain('Failed to enter maintenance mode: activation failed.')
            ->doesntExpectOutputToContain('The application is in maintenance mode')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function testUpReportsDriverFailureBeforeMaintenanceStateIsCommitted(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode(['status' => 503]));

        $exception = new RuntimeException('deactivation failed');
        $mode = Double::for(MaintenanceModeContract::class)->strict();
        $mode->expects('active')->returns(true);
        $mode->expects('deactivate')->throws($exception);
        $this->app->instance(MaintenanceModeContract::class, $mode);

        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception), []);
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->artisan(UpCommand::class)
            ->expectsOutputToContain('Failed to disable maintenance mode: deactivation failed.')
            ->doesntExpectOutputToContain('The application is live')
            ->assertExitCode(1);

        $this->assertFileExists(storage_path('framework/down'));
    }

    #[DataProvider('retryAfterDatetimeProvider')]
    public function testMaintenanceModeRetryCanAcceptDatetime(string $datetime): void
    {
        CarbonImmutable::setTestNow('2023-01-01 00:00:00');

        $this->artisan(DownCommand::class, ['--retry' => $datetime]);

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);

        $expectedDate = CarbonImmutable::parse($datetime)->toRfc7231String();
        $this->assertSame($expectedDate, $data['retry']);
    }

    /**
     * Get the supported retry date formats.
     */
    public static function retryAfterDatetimeProvider(): array
    {
        return [
            'ISO 8601 format' => ['2023-01-08 00:00:00'],
            'natural language' => ['tomorrow 14:00'],
            'relative time' => ['+2 hours'],
        ];
    }

    public function testMaintenanceModeRetryDatetimeIsConvertedToGmt(): void
    {
        $timezone = date_default_timezone_get();

        date_default_timezone_set('Europe/Berlin');

        try {
            $this->artisan(DownCommand::class, ['--retry' => '2026-12-31 15:30:00']);

            $data = json_decode(file_get_contents(storage_path('framework/down')), true);

            $this->assertSame('Thu, 31 Dec 2026 14:30:00 GMT', $data['retry']);

            $this->artisan(DownCommand::class, ['--retry' => '2026-12-31T15:30:00+02:00']);

            $data = json_decode(file_get_contents(storage_path('framework/down')), true);

            $this->assertSame('Thu, 31 Dec 2026 13:30:00 GMT', $data['retry']);

            $this->artisan(DownCommand::class, ['--retry' => '2023-01-08 12:00:00 Australia/Sydney']);

            $data = json_decode(file_get_contents(storage_path('framework/down')), true);

            $this->assertSame('Sun, 08 Jan 2023 01:00:00 GMT', $data['retry']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testMaintenanceModeRetryWithHttpDateHeader(): void
    {
        $retryDate = CarbonImmutable::now()->addWeek();
        $expectedHeader = $retryDate->toRfc7231String();

        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => $expectedHeader,
        ]));

        Route::get('/foo', fn (): string => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', $expectedHeader);
    }

    public function testMaintenanceModeRetryWithInvalidDatetimeReturnsNull(): void
    {
        $this->artisan(DownCommand::class, ['--retry' => 'not-a-valid-date']);

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);

        $this->assertNull($data['retry']);
    }

    public function testMaintenanceModeRetryWithAtTimestampNotation(): void
    {
        $futureTimestamp = time() + 3600;

        $this->artisan(DownCommand::class, ['--retry' => '@' . $futureTimestamp]);

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);

        $expectedDate = CarbonImmutable::createFromTimestamp($futureTimestamp)->toRfc7231String();
        $this->assertSame($expectedDate, $data['retry']);
    }

    public function testMaintenanceModeCanBeRefreshedWithNewOptions(): void
    {
        $this->artisan(DownCommand::class, ['--retry' => 60])
            ->expectsOutputToContain('Application is now in maintenance mode.');

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);
        $this->assertSame(60, $data['retry']);

        $this->artisan(DownCommand::class, ['--retry' => 120])
            ->expectsOutputToContain('Maintenance mode options updated.');

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);
        $this->assertSame(120, $data['retry']);
    }

    public function testMaintenanceModeRespectsBootstrapConfiguredExcludedPaths(): void
    {
        PreventRequestsDuringMaintenance::except([
            '/api/*',
            '/webhooks/*',
        ]);
        $this->artisan(DownCommand::class);

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);

        $this->assertSame([
            '/api/*',
            '/webhooks/*',
        ], $data['except']);
    }
}

class FailingRecyclingDownCommand extends DownCommand
{
    public static bool $recycleAttempted = false;

    public static ?Throwable $recycleFailure = null;

    /**
     * Simulate a worker recycle failure.
     */
    protected function recycleWorkers(): void
    {
        static::$recycleAttempted = true;

        if (static::$recycleFailure !== null) {
            throw static::$recycleFailure;
        }
    }
}

class FailingRecyclingUpCommand extends UpCommand
{
    public static bool $recycleAttempted = false;

    public static ?Throwable $recycleFailure = null;

    /**
     * Simulate a worker recycle failure.
     */
    protected function recycleWorkers(): void
    {
        static::$recycleAttempted = true;

        if (static::$recycleFailure !== null) {
            throw static::$recycleFailure;
        }
    }
}
