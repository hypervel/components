<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling\ScheduleGroupTest;

use Carbon\CarbonInterface;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule as ScheduleClass;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Schedule;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ScheduleGroupTest extends TestCase
{
    public function testGroupCanSetScheduleCronExpression()
    {
        $schedule = new ScheduleClass;

        $schedule
            ->daily()
            ->group(function (ScheduleClass $schedule) {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $this->assertSame('0 0 * * *', $events[0]->expression);
    }

    public function testGroupedScheduleCanOverrideGroupCronExpression()
    {
        Schedule::daily()->group(function () {
            Schedule::command('inspire');
            Schedule::command('inspire')
                ->twiceDaily();
        });

        $events = Schedule::events();
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertSame('0 1,13 * * *', $events[1]->expression);
    }

    public function testGroupCanSetScheduleRepeatSeconds()
    {
        Schedule::everyMinute()
            ->everyThirtySeconds()
            ->group(function () {
                Schedule::command('inspire');
            });

        $events = Schedule::events();
        $this->assertSame(30, $events[0]->repeatSeconds);
        $this->assertSame('* * * * *', $events[0]->expression);
    }

    public function testGroupedScheduleCanOverrideGroupRepeatSeconds()
    {
        Schedule::everyMinute()
            ->everyThirtySeconds()
            ->group(function () {
                Schedule::command('inspire');
                Schedule::command('inspire')
                    ->everyTwentySeconds();
            });

        $events = Schedule::events();
        $this->assertSame(30, $events[0]->repeatSeconds);
        $this->assertSame('* * * * *', $events[0]->expression);

        $this->assertSame(20, $events[1]->repeatSeconds);
        $this->assertSame('* * * * *', $events[1]->expression);
    }

    public function testGroupedScheduleCanBeNested()
    {
        Schedule::daily()
            ->timezone('UTC')
            ->group(function () {
                Schedule::command('inspire');
                Schedule::timezone('Asia/Dhaka')->group(function () {
                    Schedule::command('inspire');
                });
            });

        $events = Schedule::events();
        $this->assertSame('UTC', $events[0]->timezone);
        $this->assertSame('Asia/Dhaka', $events[1]->timezone);
    }

    public function testGroupCanApplyAttributesToSchedules()
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function () {
            Schedule::command('inspire');
        });

        $events = Schedule::events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
    }

    public function testGroupAttributesAreNotDuplicatedOnPendingSchedules()
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function () {
            Schedule::dailyAt('09:00')->command('inspire');
        });

        $events = Schedule::events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
        $this->assertSame('0 9 * * *', $events[0]->expression);
    }

    public function testGroupAttributesAreMergedWithPendingAttributes()
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function () {
            Schedule::withAttributes(['tagName' => 'import-premium-podcasts'])
                ->command('audio:import-podcasts --only-premium');
        });

        $events = Schedule::events();

        $this->assertSame([
            'team' => 'platform',
            'tagName' => 'import-premium-podcasts',
        ], $events[0]->attributes);
    }

    #[DataProvider('groupAttributes')]
    public function testGroupCanApplyAttributeToSchedules(string $property, mixed $value)
    {
        Schedule::$property($value)->group(function () {
            Schedule::command('inspire');
        });

        $events = Schedule::events();

        if ($property !== 'withoutOverlapping') {
            $this->assertSame($value, $events[0]->{$property});
        } else {
            $this->assertSame($value, $events[0]->expiresAt);
            $this->assertTrue($events[0]->withoutOverlapping);
            $this->assertTrue($events[0]->releaseOnTerminationSignals);
        }
    }

    public static function groupAttributes(): array
    {
        return [
            'user' => ['user', fake()->userName()],
            'timezone' => ['timezone', fake()->timezone()],
            'onOneServer' => ['onOneServer', true],
            'environments' => [
                'environments',
                fake()->randomElements(['local', 'production', 'testing', 'staging'], 2),
            ],
            'runInBackground' => ['runInBackground', true],
            'evenInMaintenanceMode' => ['evenInMaintenanceMode', true],
            'evenWhenPaused' => ['evenWhenPaused', true],
            'withoutOverlapping' => ['withoutOverlapping', rand(1000, 1400)],
        ];
    }

    #[DataProvider('scheduleTestCases')]
    public function testGroupedScheduleExecution(CarbonInterface $time, array $expected, string $description): void
    {
        CarbonImmutable::setTestNow($time);
        $app = app();

        Schedule::days([1, 2, 3, 4, 5, 6])->group(function () {
            Schedule::between('07:00', '08:00')->group(function () {
                Schedule::call(fn () => 'Task 1')->everyMinute();
                Schedule::call(fn () => 'Task 2')->everyFiveMinutes();
            });

            Schedule::call(fn () => 'Task 3')->at('08:05');
        });

        $events = Schedule::events();

        foreach (array_keys($expected) as $index => $task) {
            $this->assertTaskExecution(
                $events[$index],
                $app,
                $expected[$task],
                "[{$description}] {$task} should " . ($expected[$task] ? 'run' : 'not run')
            );
        }
    }

    public static function scheduleTestCases(): array
    {
        return [
            [
                CarbonImmutable::create(2024, 1, 1, 7, 30),
                [
                    'Task 1' => true,
                    'Task 2' => true,
                    'Task 3' => false,
                ],
                'Tasks at 07:30',
            ],
            [
                CarbonImmutable::create(2024, 1, 1, 8, 5),
                [
                    'Task 1' => false,
                    'Task 2' => false,
                    'Task 3' => true,
                ],
                'Tasks at 08:05',
            ],
        ];
    }

    private function assertTaskExecution($event, $app, $expected, $message): void
    {
        $this->assertSame(
            $expected,
            $event->filtersPass($app) && $event->isDue($app),
            $message
        );
    }

    public function testGroupedPendingEventAttribute()
    {
        $schedule = new ScheduleClass;
        $schedule->weekdays()->group(function ($schedule) {
            $schedule->command('inspire')->at('00:00'); // this is event, not pending attribute
            $schedule->at('01:00')->command('inspire'); // this is pending attribute
            $schedule->command('inspire');  // this goes back to group pending attribute
        });

        $events = $schedule->events();
        $this->assertSame('0 0 * * 1-5', $events[0]->expression);
        $this->assertSame('0 1 * * 1-5', $events[1]->expression);
        $this->assertSame('* * * * 1-5', $events[2]->expression);
    }

    public function testGroupedPendingEventAttributesWithoutOverlapping()
    {
        $schedule = new ScheduleClass;
        $schedule->weekdays()->withoutOverlapping()->group(function ($schedule) {
            $schedule->command('inspire')->at('14:00'); // this is event, not pending attribute
            $schedule->at('03:00')->command('inspire'); // this is pending attribute
            $schedule->command('inspire');  // this goes back to group pending attribute
            $schedule->job(JobToTestWithSchedule::class)->at('04:00');  // this is pending attribute
        });

        $events = $schedule->events();
        $this->assertSame('0 14 * * 1-5', $events[0]->expression);
        $this->assertSame('0 3 * * 1-5', $events[1]->expression);
        $this->assertSame('* * * * 1-5', $events[2]->expression);
        $this->assertSame('0 4 * * 1-5', $events[3]->expression);
    }

    public function testGroupCanOptOutOfReleaseOnTerminationSignals()
    {
        $schedule = new ScheduleClass;
        $schedule->daily()
            ->withoutOverlapping(1440, releaseOnTerminationSignals: false)
            ->group(function ($schedule) {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertFalse($events[0]->releaseOnTerminationSignals);
    }

    public function testGroupAppliesEventMacrosToAllEvents()
    {
        Event::macro('groupTestAttribute', function () {
            return $this->withAttributes(['macro' => 'applied']);
        });

        $schedule = new ScheduleClass;
        $schedule->daily()->groupTestAttribute()->group(function ($schedule) {
            $schedule->command('inspire');
            $schedule->command('inspire');
        });

        $events = $schedule->events();
        $this->assertSame(['macro' => 'applied'], $events[0]->attributes);
        $this->assertSame(['macro' => 'applied'], $events[1]->attributes);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertSame('0 0 * * *', $events[1]->expression);
    }

    public function testGroupAppliesLifecycleCallbacksToAllEvents()
    {
        $calls = 0;

        $schedule = new ScheduleClass;
        $schedule->daily()->after(function () use (&$calls) {
            ++$calls;
        })->group(function ($schedule) {
            $schedule->command('inspire');
            $schedule->command('inspire');
        });

        $events = $schedule->events();

        $events[0]->callAfterCallbacks(app());
        $events[1]->callAfterCallbacks(app());

        $this->assertSame(2, $calls);
    }
}

class JobToTestWithSchedule implements ShouldQueue
{
}
