<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Eloquent;

use Hypervel\Coroutine\Channel;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use RuntimeException;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

/**
 * Tests coroutine safety of Model state methods.
 *
 * These tests verify that withoutEvents(), withoutBroadcasting(), and
 * withoutTouching() use Context (per-coroutine storage) rather than
 * static properties (process-global), ensuring concurrent requests
 * don't interfere with each other.
 *
 * @internal
 * @coversNothing
 */
class ModelCoroutineSafetyTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('tmp_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        CoroutineTestUser::$eventLog = [];
    }

    public function testWithoutEventsDisablesEventsWithinCallback(): void
    {
        CoroutineTestUser::creating(function (CoroutineTestUser $user) {
            CoroutineTestUser::$eventLog[] = 'creating:' . $user->name;
        });

        CoroutineTestUser::create(['name' => 'Normal', 'email' => 'normal@example.com']);
        $this->assertContains('creating:Normal', CoroutineTestUser::$eventLog);

        CoroutineTestUser::$eventLog = [];

        Model::withoutEvents(function () {
            CoroutineTestUser::create(['name' => 'Silent', 'email' => 'silent@example.com']);
        });

        $this->assertNotContains('creating:Silent', CoroutineTestUser::$eventLog);
        $this->assertEmpty(CoroutineTestUser::$eventLog);

        CoroutineTestUser::create(['name' => 'AfterSilent', 'email' => 'after@example.com']);
        $this->assertContains('creating:AfterSilent', CoroutineTestUser::$eventLog);
    }

    public function testWithoutEventsRestoresStateAfterException(): void
    {
        $this->assertFalse(Model::eventsDisabled());

        try {
            Model::withoutEvents(function () {
                $this->assertTrue(Model::eventsDisabled());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertFalse(Model::eventsDisabled());
    }

    public function testWithoutEventsSupportsNesting(): void
    {
        $this->assertFalse(Model::eventsDisabled());

        Model::withoutEvents(function () {
            $this->assertTrue(Model::eventsDisabled());

            Model::withoutEvents(function () {
                $this->assertTrue(Model::eventsDisabled());
            });

            $this->assertTrue(Model::eventsDisabled());
        });

        $this->assertFalse(Model::eventsDisabled());
    }

    public function testWithoutEventsIsCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutEvents(function () use ($channel) {
                    $channel->push(['coroutine' => 1, 'disabled' => Model::eventsDisabled()]);
                    usleep(50000);
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000);
                $channel->push(['coroutine' => 2, 'disabled' => Model::eventsDisabled()]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            $results = [];
            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['disabled'];
            }

            $this->assertTrue($results[1], 'Coroutine 1 should have events disabled');
            $this->assertFalse($results[2], 'Coroutine 2 should have events enabled (isolated context)');
        });
    }

    public function testWithoutBroadcastingDisablesBroadcastingWithinCallback(): void
    {
        $this->assertTrue(Model::isBroadcasting());

        Model::withoutBroadcasting(function () {
            $this->assertFalse(Model::isBroadcasting());
        });

        $this->assertTrue(Model::isBroadcasting());
    }

    public function testWithoutBroadcastingRestoresStateAfterException(): void
    {
        $this->assertTrue(Model::isBroadcasting());

        try {
            Model::withoutBroadcasting(function () {
                $this->assertFalse(Model::isBroadcasting());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertTrue(Model::isBroadcasting());
    }

    public function testWithoutBroadcastingSupportsNesting(): void
    {
        $this->assertTrue(Model::isBroadcasting());

        Model::withoutBroadcasting(function () {
            $this->assertFalse(Model::isBroadcasting());

            Model::withoutBroadcasting(function () {
                $this->assertFalse(Model::isBroadcasting());
            });

            $this->assertFalse(Model::isBroadcasting());
        });

        $this->assertTrue(Model::isBroadcasting());
    }

    public function testWithoutBroadcastingIsCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutBroadcasting(function () use ($channel) {
                    $channel->push(['coroutine' => 1, 'broadcasting' => Model::isBroadcasting()]);
                    usleep(50000);
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000);
                $channel->push(['coroutine' => 2, 'broadcasting' => Model::isBroadcasting()]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            $results = [];
            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['broadcasting'];
            }

            $this->assertFalse($results[1], 'Coroutine 1 should have broadcasting disabled');
            $this->assertTrue($results[2], 'Coroutine 2 should have broadcasting enabled (isolated context)');
        });
    }

    public function testWithoutTouchingDisablesTouchingWithinCallback(): void
    {
        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));

        Model::withoutTouching(function () {
            $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));
        });

        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));
    }

    public function testWithoutTouchingOnSpecificModels(): void
    {
        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));

        Model::withoutTouchingOn([CoroutineTestUser::class], function () {
            $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));
        });

        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));
    }

    public function testWithoutTouchingRestoresStateAfterException(): void
    {
        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));

        try {
            Model::withoutTouching(function () {
                $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));
    }

    public function testWithoutTouchingSupportsNesting(): void
    {
        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));

        Model::withoutTouching(function () {
            $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));

            Model::withoutTouching(function () {
                $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));
            });

            $this->assertTrue(Model::isIgnoringTouch(CoroutineTestUser::class));
        });

        $this->assertFalse(Model::isIgnoringTouch(CoroutineTestUser::class));
    }

    public function testWithoutTouchingIsCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutTouching(function () use ($channel) {
                    $channel->push([
                        'coroutine' => 1,
                        'ignoring' => Model::isIgnoringTouch(CoroutineTestUser::class),
                    ]);
                    usleep(50000);
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000);
                $channel->push([
                    'coroutine' => 2,
                    'ignoring' => Model::isIgnoringTouch(CoroutineTestUser::class),
                ]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            $results = [];
            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['ignoring'];
            }

            $this->assertTrue($results[1], 'Coroutine 1 should be ignoring touch');
            $this->assertFalse($results[2], 'Coroutine 2 should NOT be ignoring touch (isolated context)');
        });
    }

    public function testWithoutRecursionIsCoroutineIsolated(): void
    {
        $model = new RecursionTestModel();
        $counter = $this->newRecursionCounter();
        $results = [];

        run(function () use ($model, $counter, &$results): void {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $callback = function () use ($counter): int {
                usleep(50000);
                return ++$counter->value;
            };

            $waiter->add(1);
            go(function () use ($model, $callback, $channel, $waiter): void {
                $channel->push([
                    'coroutine' => 1,
                    'result' => $model->runRecursionGuard($callback, -1),
                ]);
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($model, $callback, $channel, $waiter): void {
                usleep(10000);
                $channel->push([
                    'coroutine' => 2,
                    'result' => $model->runRecursionGuard($callback, -1),
                ]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['result'];
            }
        });

        sort($results);

        $this->assertSame([1, 2], $results);
        $this->assertSame(2, $counter->value);
    }

    public function testAllStateMethodsAreCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutEvents(function () use ($channel) {
                    Model::withoutBroadcasting(function () use ($channel) {
                        Model::withoutTouching(function () use ($channel) {
                            $channel->push([
                                'coroutine' => 1,
                                'eventsDisabled' => Model::eventsDisabled(),
                                'broadcasting' => Model::isBroadcasting(),
                                'ignoringTouch' => Model::isIgnoringTouch(CoroutineTestUser::class),
                            ]);
                            usleep(50000);
                        });
                    });
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000);
                $channel->push([
                    'coroutine' => 2,
                    'eventsDisabled' => Model::eventsDisabled(),
                    'broadcasting' => Model::isBroadcasting(),
                    'ignoringTouch' => Model::isIgnoringTouch(CoroutineTestUser::class),
                ]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            $results = [];
            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result;
            }

            $this->assertTrue($results[1]['eventsDisabled'], 'Coroutine 1: events should be disabled');
            $this->assertFalse($results[1]['broadcasting'], 'Coroutine 1: broadcasting should be disabled');
            $this->assertTrue($results[1]['ignoringTouch'], 'Coroutine 1: should be ignoring touch');

            $this->assertFalse($results[2]['eventsDisabled'], 'Coroutine 2: events should be enabled');
            $this->assertTrue($results[2]['broadcasting'], 'Coroutine 2: broadcasting should be enabled');
            $this->assertFalse($results[2]['ignoringTouch'], 'Coroutine 2: should NOT be ignoring touch');
        });
    }

    private function newRecursionCounter(): object
    {
        return new class {
            public int $value = 0;
        };
    }
}

class CoroutineTestUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}

class RecursionTestModel extends Model
{
    public function runRecursionGuard(callable $callback, mixed $default = null): mixed
    {
        return $this->withoutRecursion($callback, $default);
    }
}
