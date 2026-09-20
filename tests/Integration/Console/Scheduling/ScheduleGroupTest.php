<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Carbon\CarbonInterface;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule as ScheduleClass;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Schedule;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Queue\Fixtures\JobToTestWithSchedule;
use PHPUnit\Framework\Attributes\DataProvider;

class ScheduleGroupTest extends TestCase
{
    public function testGroupCanSetScheduleCronExpression(): void
    {
        $schedule = new ScheduleClass;

        $schedule
            ->daily()
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $this->assertSame('0 0 * * *', $events[0]->expression);
    }

    public function testGroupedScheduleCanOverrideGroupCronExpression(): void
    {
        Schedule::daily()->group(function (): void {
            Schedule::command('inspire');
            Schedule::command('inspire')
                ->twiceDaily();
        });

        $events = Schedule::events();
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertSame('0 1,13 * * *', $events[1]->expression);
    }

    public function testGroupCanSetScheduleRepeatSeconds(): void
    {
        Schedule::everyMinute()
            ->everyThirtySeconds()
            ->group(function (): void {
                Schedule::command('inspire');
            });

        $events = Schedule::events();
        $this->assertSame(30, $events[0]->repeatSeconds);
        $this->assertSame('* * * * *', $events[0]->expression);
    }

    public function testGroupedScheduleCanOverrideGroupRepeatSeconds(): void
    {
        Schedule::everyMinute()
            ->everyThirtySeconds()
            ->group(function (): void {
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

    public function testGroupedScheduleCanBeNested(): void
    {
        Schedule::daily()
            ->timezone('UTC')
            ->group(function (): void {
                Schedule::command('inspire');
                Schedule::timezone('Asia/Dhaka')->group(function (): void {
                    Schedule::command('inspire');
                });
            });

        $events = Schedule::events();
        $this->assertSame('UTC', $events[0]->timezone);
        $this->assertSame('Asia/Dhaka', $events[1]->timezone);
    }

    public function testGroupCanApplyAttributesToSchedules(): void
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function (): void {
            Schedule::command('inspire');
        });

        $events = Schedule::events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
    }

    public function testGroupAttributesAreNotDuplicatedOnPendingSchedules(): void
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function (): void {
            Schedule::dailyAt('09:00')->command('inspire');
        });

        $events = Schedule::events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
        $this->assertSame('0 9 * * *', $events[0]->expression);
    }

    public function testGroupAttributesAreMergedWithPendingAttributes(): void
    {
        Schedule::withAttributes(['team' => 'platform'])->group(function (): void {
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
    public function testGroupCanApplyAttributeToSchedules(string $property, mixed $value): void
    {
        Schedule::$property($value)->group(function (): void {
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

    /**
     * Get the group attribute cases.
     */
    public static function groupAttributes(): array
    {
        return [
            // REMOVED: user(); coroutine tasks share the scheduler's OS user.
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

        Schedule::days([1, 2, 3, 4, 5, 6])->group(function (): void {
            Schedule::between('07:00', '08:00')->group(function (): void {
                Schedule::call(fn (): string => 'Task 1')->everyMinute();
                Schedule::call(fn (): string => 'Task 2')->everyFiveMinutes();
            });

            Schedule::call(fn (): string => 'Task 3')->at('08:05');
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

    /**
     * Get the grouped execution cases.
     */
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

    /**
     * Assert whether the scheduled task should run.
     */
    private function assertTaskExecution(Event $event, Application $app, bool $expected, string $message): void
    {
        $this->assertSame(
            $expected,
            $event->filtersPass($app) && $event->isDue($app),
            $message
        );
    }

    public function testGroupedPendingEventAttribute(): void
    {
        $schedule = new ScheduleClass;
        $schedule->weekdays()->group(function (ScheduleClass $schedule): void {
            $schedule->command('inspire')->at('00:00'); // this is event, not pending attribute
            $schedule->at('01:00')->command('inspire'); // this is pending attribute
            $schedule->command('inspire');  // this goes back to group pending attribute
        });

        $events = $schedule->events();
        $this->assertSame('0 0 * * 1-5', $events[0]->expression);
        $this->assertSame('0 1 * * 1-5', $events[1]->expression);
        $this->assertSame('* * * * 1-5', $events[2]->expression);
    }

    public function testGroupedPendingEventAttributesWithoutOverlapping(): void
    {
        $schedule = new ScheduleClass;
        $schedule->weekdays()->withoutOverlapping()->group(function (ScheduleClass $schedule): void {
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

    public function testGroupCanOptOutOfReleaseOnTerminationSignals(): void
    {
        $schedule = new ScheduleClass;
        $schedule->daily()
            ->withoutOverlapping(1440, releaseOnTerminationSignals: false)
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertFalse($events[0]->releaseOnTerminationSignals);
    }

    public function testGroupAppliesEventMacrosToAllEvents(): void
    {
        Event::macro('sentryMonitor', function (): Event {
            return $this->withAttributes(['sentryMonitored' => true]);
        });

        $schedule = new ScheduleClass;
        $schedule->daily()->sentryMonitor()->group(function (ScheduleClass $schedule): void {
            $schedule->command('inspire');
            $schedule->command('inspire');
        });

        $events = $schedule->events();
        $this->assertTrue($events[0]->attributes['sentryMonitored']);
        $this->assertTrue($events[1]->attributes['sentryMonitored']);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertSame('0 0 * * *', $events[1]->expression);
    }

    public function testGroupAppliesEventMacroCalledBeforeBuiltInAttributes(): void
    {
        Event::macro('sentryMonitor', function (): Event {
            return $this->withAttributes(['sentryMonitored' => true]);
        });

        $schedule = new ScheduleClass;
        $schedule->sentryMonitor()->daily()->onOneServer()->group(function (ScheduleClass $schedule): void {
            $schedule->command('inspire');
        });

        $events = $schedule->events();
        $this->assertTrue($events[0]->attributes['sentryMonitored']);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertSame('0 0 * * *', $events[0]->expression);
    }

    public function testGroupAppliesMultipleEventMacros(): void
    {
        Event::macro('sentryMonitor', function (): Event {
            return $this->withAttributes(['sentryMonitored' => true]);
        });
        Event::macro('customTag', function (string $tag): Event {
            return $this->withAttributes(['customTag' => $tag]);
        });

        $schedule = new ScheduleClass;
        $schedule->daily()->sentryMonitor()->customTag('billing')->group(function (ScheduleClass $schedule): void {
            $schedule->command('inspire');
            $schedule->command('inspire');
        });

        $events = $schedule->events();
        $this->assertTrue($events[0]->attributes['sentryMonitored']);
        $this->assertSame('billing', $events[0]->attributes['customTag']);
        $this->assertTrue($events[1]->attributes['sentryMonitored']);
        $this->assertSame('billing', $events[1]->attributes['customTag']);
    }

    public function testNestedGroupInheritsEventMacros(): void
    {
        Event::macro('sentryMonitor', function (): Event {
            return $this->withAttributes(['sentryMonitored' => true]);
        });

        $schedule = new ScheduleClass;
        $schedule->daily()->sentryMonitor()->group(function (ScheduleClass $schedule): void {
            $schedule->command('inspire');
            $schedule->weekly()->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
            });
        });

        $events = $schedule->events();
        $this->assertTrue($events[0]->attributes['sentryMonitored']);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertTrue($events[1]->attributes['sentryMonitored']);
        $this->assertSame('0 0 * * 0', $events[1]->expression);
    }

    public function testGroupAppliesEventMacrosOnceToPendingSchedules(): void
    {
        Event::macro('sentryMonitor', function (): Event {
            return $this->withAttributes(['sentryMonitored' => ($this->attributes['sentryMonitored'] ?? 0) + 1]);
        });

        $schedule = new ScheduleClass;
        $schedule->daily()->sentryMonitor()->group(function (ScheduleClass $schedule): void {
            $schedule->at('09:00')->command('inspire');
        });

        $events = $schedule->events();
        $this->assertSame(1, $events[0]->attributes['sentryMonitored']);
        $this->assertSame('0 9 * * *', $events[0]->expression);
    }

    public function testGroupAppliesOnFailureCallbackToAllEvents(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->onFailure(function () use (&$calls): void {
                $calls[] = 'group-failure';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $this->assertCount(2, $events);

        $events[0]->finish(app(), 1);
        $events[1]->finish(app(), 1);

        $this->assertSame(['group-failure', 'group-failure'], $calls);
    }

    public function testGroupOnFailureCallbackDoesNotRunOnSuccess(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->onFailure(function () use (&$calls): void {
                $calls[] = 'group-failure';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $events[0]->finish(app(), 0);

        $this->assertSame([], $calls);
    }

    public function testGroupAppliesOnSuccessCallbackToAllEvents(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->onSuccess(function () use (&$calls): void {
                $calls[] = 'group-success';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $events[0]->finish(app(), 0);
        $events[1]->finish(app(), 0);

        $this->assertSame(['group-success', 'group-success'], $calls);
    }

    public function testGroupAppliesBeforeAndAfterCallbacksToAllEvents(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->before(function () use (&$calls): void {
                $calls[] = 'before';
            })
            ->after(function () use (&$calls): void {
                $calls[] = 'after';
            })
            ->then(function () use (&$calls): void {
                $calls[] = 'then';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire');
            });

        $events = $schedule->events();
        $events[0]->callBeforeCallbacks(app());
        $events[0]->finish(app(), 0);

        $this->assertSame(['before', 'after', 'then'], $calls);
    }

    public function testGroupAppliesAfterCallbackOnceToPendingSchedules(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule
            ->after(function () use (&$calls): void {
                $calls[] = 'after';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->at('09:00')->command('inspire');
            });

        $events = $schedule->events();
        $events[0]->finish(app(), 0);

        $this->assertSame(['after'], $calls);
        $this->assertSame('0 9 * * *', $events[0]->expression);
    }

    public function testGroupCallbacksCombineWithEventLevelCallbacks(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->onFailure(function () use (&$calls): void {
                $calls[] = 'group';
            })
            ->group(function (ScheduleClass $schedule) use (&$calls): void {
                $schedule->command('inspire')->onFailure(function () use (&$calls): void {
                    $calls[] = 'event';
                });
            });

        $events = $schedule->events();
        $events[0]->finish(app(), 1);

        $this->assertSame(['group', 'event'], $calls);
    }

    public function testNestedGroupInheritsLifecycleCallbacks(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule->daily()
            ->onFailure(function () use (&$calls): void {
                $calls[] = 'outer';
            })
            ->group(function (ScheduleClass $schedule) use (&$calls): void {
                $schedule->command('inspire');
                $schedule->weekly()
                    ->onFailure(function () use (&$calls): void {
                        $calls[] = 'inner';
                    })
                    ->group(function (ScheduleClass $schedule): void {
                        $schedule->command('inspire');
                    });
            });

        $events = $schedule->events();
        $this->assertCount(2, $events);

        $events[0]->finish(app(), 1);
        $this->assertSame(['outer'], $calls);

        $events[1]->finish(app(), 1);
        $this->assertSame(['outer', 'outer', 'inner'], $calls);
    }

    public function testNestedGroupInheritsLifecycleCallbacksOnce(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule
            ->after(function () use (&$calls): void {
                $calls[] = 'outer';
            })
            ->group(function (ScheduleClass $schedule) use (&$calls): void {
                $schedule
                    ->after(function () use (&$calls): void {
                        $calls[] = 'inner';
                    })
                    ->group(function (ScheduleClass $schedule): void {
                        $schedule->command('inspire');
                    });
            });

        $events = $schedule->events();
        $events[0]->finish(app(), 0);

        $this->assertSame(['outer', 'inner'], $calls);
    }

    public function testGroupCanStartWithLifecycleCallbackWithoutFrequency(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule
            ->before(function () use (&$calls): void {
                $calls[] = 'before';
            })
            ->onSuccess(function () use (&$calls): void {
                $calls[] = 'success';
            })
            ->onFailure(function () use (&$calls): void {
                $calls[] = 'failure';
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire')->daily();
                $schedule->command('inspire')->weekly();
            });

        $events = $schedule->events();
        $this->assertCount(2, $events);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertSame('0 0 * * 0', $events[1]->expression);

        $events[0]->callBeforeCallbacks(app());
        $events[0]->finish(app(), 0);
        $events[1]->callBeforeCallbacks(app());
        $events[1]->finish(app(), 1);

        $this->assertSame(['before', 'success', 'before', 'failure'], $calls);
    }

    public function testGroupCanStartWithOutputCallbackWithoutFrequency(): void
    {
        $calls = [];

        $schedule = new ScheduleClass;
        $schedule
            ->onFailureWithOutput(function (Event $event, Stringable $output) use (&$calls): void {
                $calls[] = 'failure:' . $output;
            })
            ->group(function (ScheduleClass $schedule): void {
                $schedule->command('inspire')->daily();
            });

        $events = $schedule->events();
        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events[0]->expression);

        $events[0]->finish(app(), 1);

        $this->assertCount(1, $calls);
    }
}
