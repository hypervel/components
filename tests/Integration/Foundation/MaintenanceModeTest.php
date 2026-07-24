<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use DateTimeInterface;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\Console\DownCommand;
use Hypervel\Foundation\Console\UpCommand;
use Hypervel\Foundation\Events\MaintenanceModeDisabled;
use Hypervel\Foundation\Events\MaintenanceModeEnabled;
use Hypervel\Foundation\Http\MaintenanceModeBypassCookie;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class MaintenanceModeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            @unlink(storage_path('framework/down'));
            @unlink(resource_path('views/errors/503.blade.php'));
        });

        parent::setUp();
    }

    protected function tearDown(): void
    {
        FailingReloadDownCommand::$reloadAttempted = false;
        FailingReloadDownCommand::$reloadFailure = null;
        FailingReloadUpCommand::$reloadAttempted = false;
        FailingReloadUpCommand::$reloadFailure = null;

        parent::tearDown();
    }

    public function testBasicMaintenanceModeResponse()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'refresh' => 60,
        ]));

        Route::get('/foo', function () {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '60');
    }

    public function testConcurrentMaintenanceFileRemovalAllowsTheRequestToProceed(): void
    {
        $mode = m::mock(MaintenanceModeContract::class);
        $mode->shouldReceive('active')->twice()->andReturnTrue();
        $mode->shouldReceive('data')->twice()->andThrow(new FileNotFoundException('removed'));
        $this->app->instance(MaintenanceModeContract::class, $mode);

        Route::get('/foo', fn () => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertOk();
        $this->assertSame('Hello World', $response->original);
    }

    public function testMaintenanceModeCanHaveCustomStatus()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'status' => 200,
        ]));

        Route::get('/foo', function () {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(200);
        $response->assertHeader('Retry-After', '60');
    }

    public function testMaintenanceModeCanHaveCustomTemplate()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'template' => 'Rendered Content',
        ]));

        Route::get('/foo', function () {
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

        Route::get('/foo', fn () => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

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

        Route::get('/foo', fn () => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->getJson('/foo');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('Refresh', '30');
        $response->assertJson(['message' => 'Service Unavailable']);
    }

    public function testDownCommandPrerendersTemplateIntoMaintenancePayload()
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

    public function testMaintenanceModeCanRedirectWithBypassCookie()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
            'template' => 'Rendered Content',
        ]));

        Route::get('/foo', function () {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/foo');

        $response->assertStatus(302);
        $response->assertCookie('hypervel_maintenance');
    }

    public function testMaintenanceModeCanBeBypassedWithValidCookie()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
        ]));

        $cookie = MaintenanceModeBypassCookie::create('foo');

        Route::get('/test', function () {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->withUnencryptedCookies([
            'hypervel_maintenance' => $cookie->getValue(),
        ])->get('/test');

        $response->assertStatus(200);
        $this->assertSame('Hello World', $response->original);
    }

    public function testMaintenanceModeCanBeBypassedOnExcludedUrls()
    {
        $this->app->instance(PreventRequestsDuringMaintenance::class, new class($this->app) extends PreventRequestsDuringMaintenance {
            protected array $except = ['/test'];
        });

        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
        ]));

        Route::get('/test', fn () => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->get('/test');

        $response->assertStatus(200);
        $this->assertSame('Hello World', $response->original);
    }

    public function testMaintenanceModeCantBeBypassedWithInvalidCookie()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => 60,
            'secret' => 'foo',
        ]));

        $cookie = MaintenanceModeBypassCookie::create('test-key');

        Route::get('/test', function () {
            return 'Hello World';
        })->middleware(PreventRequestsDuringMaintenance::class);

        $response = $this->withUnencryptedCookies([
            'hypervel_maintenance' => $cookie->getValue(),
        ])->get('/test');

        $response->assertStatus(503);
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

    public function testDispatchEventWhenMaintenanceModeIsEnabled()
    {
        Event::fake();

        Event::assertNotDispatched(MaintenanceModeEnabled::class);
        $this->artisan(DownCommand::class);
        Event::assertDispatched(MaintenanceModeEnabled::class);
    }

    public function testDispatchEventWhenMaintenanceModeIsDisabled()
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

    public function testDownAttemptsReloadAfterEventFailureAndPreservesTheEventFailure(): void
    {
        $eventException = new RuntimeException('event failed');
        $reloadException = new RuntimeException('reload failed');
        $reportException = new RuntimeException('report failed');
        $command = $this->app->make(FailingReloadDownCommand::class);
        FailingReloadDownCommand::$reloadFailure = $reloadException;
        $this->app->make(KernelContract::class)->registerCommand($command);
        $this->app->make('events')->listen(
            MaintenanceModeEnabled::class,
            static fn () => throw $eventException,
        );

        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($eventException)->andThrow($reportException);
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->artisan(FailingReloadDownCommand::class)
            ->expectsOutputToContain('The application is in maintenance mode, but a follow-up operation failed: event failed.')
            ->doesntExpectOutputToContain('Application is now in maintenance mode.')
            ->assertExitCode(1);

        $this->assertTrue(FailingReloadDownCommand::$reloadAttempted);
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function testDownReportsReloadFailureAfterMaintenanceStateIsCommitted(): void
    {
        $command = $this->app->make(FailingReloadDownCommand::class);
        FailingReloadDownCommand::$reloadFailure = new RuntimeException('reload failed');
        $this->app->make(KernelContract::class)->registerCommand($command);

        $this->artisan(FailingReloadDownCommand::class)
            ->expectsOutputToContain('The application is in maintenance mode, but a follow-up operation failed: reload failed.')
            ->doesntExpectOutputToContain('Application is now in maintenance mode.')
            ->assertExitCode(1);

        $this->assertTrue(FailingReloadDownCommand::$reloadAttempted);
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function testUpAttemptsReloadAfterEventFailureAndPreservesTheEventFailure(): void
    {
        file_put_contents(storage_path('framework/down'), json_encode(['status' => 503]));

        $eventException = new RuntimeException('event failed');
        $command = $this->app->make(FailingReloadUpCommand::class);
        FailingReloadUpCommand::$reloadFailure = new RuntimeException('reload failed');
        $this->app->make(KernelContract::class)->registerCommand($command);
        $this->app->make('events')->listen(
            MaintenanceModeDisabled::class,
            static fn () => throw $eventException,
        );

        $this->artisan(FailingReloadUpCommand::class)
            ->expectsOutputToContain('The application is live, but a follow-up operation failed: event failed.')
            ->doesntExpectOutputToContain('Application is now live.')
            ->assertExitCode(1);

        $this->assertTrue(FailingReloadUpCommand::$reloadAttempted);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function testDownReportsDriverFailureBeforeMaintenanceStateIsCommitted(): void
    {
        $mode = m::mock(MaintenanceModeContract::class);
        $mode->shouldReceive('active')->once()->andReturnFalse();
        $mode->shouldReceive('activate')->once()->andThrow(new RuntimeException('activation failed'));
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

        $mode = m::mock(MaintenanceModeContract::class);
        $mode->shouldReceive('active')->once()->andReturnTrue();
        $mode->shouldReceive('deactivate')->once()->andThrow(new RuntimeException('deactivation failed'));
        $this->app->instance(MaintenanceModeContract::class, $mode);

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

        $expectedDate = CarbonImmutable::parse($datetime)->format(DateTimeInterface::RFC7231);
        $this->assertSame($expectedDate, $data['retry']);

        CarbonImmutable::setTestNow();
    }

    public static function retryAfterDatetimeProvider(): array
    {
        return [
            'ISO 8601 format' => ['2023-01-08 00:00:00'],
            'natural language' => ['tomorrow 14:00'],
            'relative time' => ['+2 hours'],
        ];
    }

    public function testMaintenanceModeRetryWithHttpDateHeader(): void
    {
        $retryDate = CarbonImmutable::now()->addWeek();
        $expectedHeader = $retryDate->format(DateTimeInterface::RFC7231);

        file_put_contents(storage_path('framework/down'), json_encode([
            'retry' => $expectedHeader,
        ]));

        Route::get('/foo', fn () => 'Hello World')->middleware(PreventRequestsDuringMaintenance::class);

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

        $expectedDate = CarbonImmutable::createFromTimestamp($futureTimestamp)->format(DateTimeInterface::RFC7231);
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

    public function testMaintenanceModeRespectsBootstrapConfiguredExcludedPaths()
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

class FailingReloadDownCommand extends DownCommand
{
    public static bool $reloadAttempted = false;

    public static ?Throwable $reloadFailure = null;

    protected function reloadWorkers(): void
    {
        static::$reloadAttempted = true;

        if (static::$reloadFailure !== null) {
            throw static::$reloadFailure;
        }
    }
}

class FailingReloadUpCommand extends UpCommand
{
    public static bool $reloadAttempted = false;

    public static ?Throwable $reloadFailure = null;

    protected function reloadWorkers(): void
    {
        static::$reloadAttempted = true;

        if (static::$reloadFailure !== null) {
            throw static::$reloadFailure;
        }
    }
}
