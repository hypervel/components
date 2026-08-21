<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Providers;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\ArrayMaintenanceMode;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\DevCommands;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Foundation\Providers\FoundationServiceProvider;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Http\Request;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use Symfony\Component\VarDumper\VarDumper;

class FoundationServiceProviderTest extends TestCase
{
    public function testRequestHasValidSignatureMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('hasValidSignature'));
    }

    public function testRequestHasValidRelativeSignatureMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('hasValidRelativeSignature'));
    }

    public function testRequestHasValidSignatureWhileIgnoringMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('hasValidSignatureWhileIgnoring'));
    }

    public function testRequestHasValidRelativeSignatureWhileIgnoringMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('hasValidRelativeSignatureWhileIgnoring'));
    }

    public function testRequestValidateMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('validate'));
    }

    public function testRequestValidateWithBagMacroIsRegistered(): void
    {
        $this->assertTrue(Request::hasMacro('validateWithBag'));
    }

    public function testConsoleScheduleSingletonIsRegistered(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertSame($schedule, $this->app->make(Schedule::class));
    }

    public function testDevelopmentCommandsAreRegistered(): void
    {
        $artisan = $this->app->make(Kernel::class)->getArtisan();

        $this->assertTrue($artisan->has('dev'));
        $this->assertTrue($artisan->has('dev:list'));
    }

    public function testDefaultDevelopmentProcessesAreRegistered(): void
    {
        $this->assertSame(['server', 'queue', 'vite'], array_column(DevCommands::commands(), 'name'));
    }

    public function testClockSingletonIsRegistered(): void
    {
        $clock = $this->app->make(ClockInterface::class);

        $this->assertInstanceOf(ClockInterface::class, $clock);
        $this->assertSame($clock, $this->app->make(ClockInterface::class));
    }

    public function testClockReturnsCarbonImmutable(): void
    {
        $this->assertSame(
            CarbonImmutable::class,
            $this->app->make(ClockInterface::class)->now()::class
        );
    }

    public function testClockHonorsCarbonTestTime(): void
    {
        Carbon::setTestNow($expected = CarbonImmutable::parse('2026-07-03 12:34:56', 'UTC'));

        $this->assertSame(
            $expected->format('Y-m-d H:i:s.u P'),
            $this->app->make(ClockInterface::class)->now()->format('Y-m-d H:i:s.u P')
        );
    }

    public function testClockAgreesWithDateFacadeAndNowHelper(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-03 12:34:56', 'UTC'));

        $clockNow = $this->app->make(ClockInterface::class)->now();

        $this->assertSame(CarbonImmutable::class, $clockNow::class);
        $this->assertSame(now()->format('Y-m-d H:i:s.u P'), $clockNow->format('Y-m-d H:i:s.u P'));
        $this->assertSame(Date::now()->format('Y-m-d H:i:s.u P'), $clockNow->format('Y-m-d H:i:s.u P'));
    }

    public function testClockReturnsCarbonImmutableWhenDateFacadeUsesMutableCarbon(): void
    {
        Date::useClass(Carbon::class);

        $this->assertSame(
            CarbonImmutable::class,
            $this->app->make(ClockInterface::class)->now()::class
        );
    }

    public function testMaintenanceModeRefreshIntervalIsConfigured(): void
    {
        $this->assertSame(5, $this->app->make('config')->integer('app.maintenance.refresh_interval'));

        config(['app.maintenance.refresh_interval' => 11]);
        $this->app->forgetInstance(MaintenanceModeContract::class);

        $mode = $this->app->make(MaintenanceModeContract::class);

        $this->assertInstanceOf(WorkerCachedMaintenanceMode::class, $mode);
        $this->assertSame(
            11,
            (new ReflectionClass($mode))->getProperty('refreshInterval')->getValue($mode)
        );
    }

    public function testArrayMaintenanceModeDriverIsAvailable(): void
    {
        $this->app->make('config')->set('app.maintenance.driver', 'array');

        $driver = (new MaintenanceModeManager($this->app))->driver();

        $this->assertInstanceOf(ArrayMaintenanceMode::class, $driver);
    }

    #[DataProvider('dumperFormatValues')]
    public function testSetDumperFormatInstallsHypervelHandlerAndRestoresEnvironment(
        string $format,
        string $expectedDumper,
    ): void {
        $handlerProperty = new ReflectionProperty(VarDumper::class, 'handler');
        $originalHandler = $handlerProperty->getValue();
        $originalFormatExists = array_key_exists('VAR_DUMPER_FORMAT', $_SERVER);
        $originalFormat = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        $sentinelHandler = static function (): void {
        };

        unset($_SERVER['VAR_DUMPER_FORMAT']);
        VarDumper::setHandler($sentinelHandler);
        $_SERVER['VAR_DUMPER_FORMAT'] = $format;

        try {
            $provider = new FoundationServiceProvider($this->app);
            (new ReflectionClass($provider))->getMethod('registerDumper')->invoke($provider);

            $this->assertSame($format, $_SERVER['VAR_DUMPER_FORMAT']);
            $handler = $handlerProperty->getValue();
            $this->assertNotSame($sentinelHandler, $handler);
            $capturedDumpers = array_filter(
                (new ReflectionFunction($handler))->getStaticVariables(),
                static fn (mixed $value): bool => $value instanceof $expectedDumper,
            );
            $this->assertCount(1, $capturedDumpers);
        } finally {
            unset($_SERVER['VAR_DUMPER_FORMAT']);
            VarDumper::setHandler($originalHandler);

            if ($originalFormatExists) {
                $_SERVER['VAR_DUMPER_FORMAT'] = $originalFormat;
            }
        }
    }

    public static function dumperFormatValues(): array
    {
        return [
            'CLI' => ['cli', CliDumper::class],
            'HTML' => ['html', HtmlDumper::class],
            'blank' => ['', CliDumper::class],
            'unknown' => ['unknown', CliDumper::class],
        ];
    }
}
