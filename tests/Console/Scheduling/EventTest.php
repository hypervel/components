<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use DateTimeZone;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use TypeError;

enum EventTestTimezoneStringEnum: string
{
    case NewYork = 'America/New_York';
    case London = 'Europe/London';
}

enum EventTestTimezoneIntEnum: int
{
    case Zone1 = 1;
    case Zone2 = 2;
}

enum EventTestTimezoneUnitEnum
{
    case UTC;
    case EST;
}

/**
 * @internal
 * @coversNothing
 */
class EventTest extends TestCase
{
    use HasMockedApplication;

    protected ?Container $container = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->getApplication();
        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testSendOutputToWithIsNotFile()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->sendOutputTo($output = 'test.log');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(false);

        $this->container->instance(Filesystem::class, $filesystem);
        $event->writeOutput($this->container);
    }

    public function testSendOutputTo()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->sendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(true);
        $filesystem->shouldReceive('put')
            ->once()
            ->with($output, $result);

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);
    }

    public function testSendOutputToWithSystemProcess()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');
        $event->isSystem = true;

        $event->sendOutputTo($output = 'test.log');

        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');
        Context::set($key = "__console.scheduling_process.{$event->mutexName()}", $process);

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('put')
            ->once()
            ->with($output, $result);

        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);

        Context::destroy($key);
    }

    public function testDaysOfMonthMethod()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->daysOfMonth(1, 15);
        $this->assertSame('0 0 1,15 * *', $event->getExpression());

        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->daysOfMonth([1, 10, 20, 30]);
        $this->assertSame('0 0 1,10,20,30 * *', $event->getExpression());
    }

    public function testAppendOutput()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->appendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(true);
        $filesystem->shouldReceive('append')
            ->once()
            ->with($output, $result);

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);
    }

    public function testNextRunDate()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->dailyAt('10:15');

        $this->assertSame('10:15:00', $event->nextRunDate()->toTimeString());
    }

    public function testCustomMutexName()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->description('Fancy command description');

        $this->assertSame('framework' . DIRECTORY_SEPARATOR . 'schedule-eeb46c93d45e928d62aaf684d727e213b7094822', $event->mutexName());

        $event->createMutexNameUsing(function (Event $event) {
            return Str::slug($event->description);
        });

        $this->assertSame('fancy-command-description', $event->mutexName());
    }

    public function testTimezoneAcceptsStringBackedEnum(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->timezone(EventTestTimezoneStringEnum::NewYork);

        // String-backed enum value should be used
        $this->assertSame('America/New_York', $event->timezone);
    }

    public function testTimezoneAcceptsUnitEnum(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->timezone(EventTestTimezoneUnitEnum::UTC);

        // Unit enum name should be used
        $this->assertSame('UTC', $event->timezone);
    }

    public function testTimezoneWithIntBackedEnumThrowsTypeError(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        // Int-backed enum causes TypeError because $timezone property is DateTimeZone|string|null
        $this->expectException(TypeError::class);
        $event->timezone(EventTestTimezoneIntEnum::Zone1);
    }

    public function testTimezoneAcceptsDateTimeZoneObject(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $tz = new DateTimeZone('UTC');
        $event->timezone($tz);

        // DateTimeZone object should be preserved
        $this->assertSame($tz, $event->timezone);
    }

    public function testBasicCronCompilation()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertTrue($event->isDue($app));
        $this->assertTrue($event->skip(function () {
            return true;
        })->isDue($app));
        $this->assertFalse($event->skip(function () {
            return true;
        })->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->environments('local')->isDue($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->when(function () {
            return false;
        })->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->when(false)->filtersPass($app));

        // chained rules should be commutative
        $eventA = new Event(m::mock(EventMutex::class), 'php foo');
        $eventB = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertEquals(
            $eventA->daily()->hourly()->getExpression(),
            $eventB->hourly()->daily()->getExpression()
        );

        $eventA = new Event(m::mock(EventMutex::class), 'php foo');
        $eventB = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertEquals(
            $eventA->weekdays()->hourly()->getExpression(),
            $eventB->hourly()->weekdays()->getExpression()
        );
    }

    public function testEventIsDueCheck()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        Carbon::setTestNow(Carbon::create(2015, 1, 1, 0, 0, 0));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * 4', $event->thursdays()->getExpression());
        $this->assertTrue($event->isDue($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('0 19 * * 3', $event->wednesdays()->at('19:00')->timezone('EST')->getExpression());
        $this->assertTrue($event->isDue($app));

        Carbon::setTestNow(null);
    }

    public function testTimeBetweenChecks()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(9));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('8:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('9:00', '9:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('23:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('8:00', '6:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->between('10:00', '11:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->between('10:00', '8:00')->filtersPass($app));

        Carbon::setTestNow(null);
    }

    public function testTimeUnlessBetweenChecks()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(9));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('8:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('9:00', '9:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('23:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('8:00', '6:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->unlessBetween('10:00', '11:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->unlessBetween('10:00', '8:00')->filtersPass($app));

        Carbon::setTestNow(null);
    }
}
