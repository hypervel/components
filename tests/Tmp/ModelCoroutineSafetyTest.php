<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tmp;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Channel;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

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
 * @group integration
 * @group pgsql-integration
 */
class ModelCoroutineSafetyTest extends DatabaseIntegrationTestCase
{
    use RefreshDatabase;

    protected function getDatabaseDriver(): string
    {
        return 'pgsql';
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static state
        CoroutineTestUser::$eventLog = [];
    }

    // =========================================================================
    // withoutEvents() Tests
    // =========================================================================

    public function testWithoutEventsDisablesEventsWithinCallback(): void
    {
        CoroutineTestUser::creating(function (CoroutineTestUser $user) {
            CoroutineTestUser::$eventLog[] = 'creating:' . $user->name;
        });

        // Events should fire normally
        CoroutineTestUser::create(['name' => 'Normal', 'email' => 'normal@example.com']);
        $this->assertContains('creating:Normal', CoroutineTestUser::$eventLog);

        CoroutineTestUser::$eventLog = [];

        // Events should be disabled within callback
        Model::withoutEvents(function () {
            CoroutineTestUser::create(['name' => 'Silent', 'email' => 'silent@example.com']);
        });

        $this->assertNotContains('creating:Silent', CoroutineTestUser::$eventLog);
        $this->assertEmpty(CoroutineTestUser::$eventLog);

        // Events should fire again after callback
        CoroutineTestUser::create(['name' => 'AfterSilent', 'email' => 'after@example.com']);
        $this->assertContains('creating:AfterSilent', CoroutineTestUser::$eventLog);
    }

    public function testWithoutEventsRestoresStateAfterException(): void
    {
        $this->assertFalse(Model::eventsDisabled());

        try {
            Model::withoutEvents(function () {
                $this->assertTrue(Model::eventsDisabled());
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // State should be restored even after exception
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

            // Should still be disabled after inner callback
            $this->assertTrue(Model::eventsDisabled());
        });

        $this->assertFalse(Model::eventsDisabled());
    }

    public function testWithoutEventsIsCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            // Coroutine 1: Disables events for a period
            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutEvents(function () use ($channel) {
                    // Signal that events are disabled in this coroutine
                    $channel->push(['coroutine' => 1, 'disabled' => Model::eventsDisabled()]);

                    // Wait a bit to ensure coroutine 2 runs while we're in withoutEvents
                    usleep(50000); // 50ms
                });
                $waiter->done();
            });

            // Coroutine 2: Checks events status (should NOT be disabled)
            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                // Small delay to ensure coroutine 1 is inside withoutEvents
                usleep(10000); // 10ms

                // This coroutine should have events enabled (isolated context)
                $channel->push(['coroutine' => 2, 'disabled' => Model::eventsDisabled()]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            // Collect results
            $results = [];
            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['disabled'];
            }

            // Coroutine 1 should have events disabled
            $this->assertTrue($results[1], 'Coroutine 1 should have events disabled');

            // Coroutine 2 should have events enabled (isolated from coroutine 1)
            $this->assertFalse($results[2], 'Coroutine 2 should have events enabled (isolated context)');
        });
    }

    // =========================================================================
    // withoutBroadcasting() Tests
    // =========================================================================

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
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException) {
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

            // Should still be disabled after inner callback
            $this->assertFalse(Model::isBroadcasting());
        });

        $this->assertTrue(Model::isBroadcasting());
    }

    public function testWithoutBroadcastingIsCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            // Coroutine 1: Disables broadcasting
            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::withoutBroadcasting(function () use ($channel) {
                    $channel->push(['coroutine' => 1, 'broadcasting' => Model::isBroadcasting()]);
                    usleep(50000);
                });
                $waiter->done();
            });

            // Coroutine 2: Should still have broadcasting enabled
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

    // =========================================================================
    // withoutTouching() Tests
    // =========================================================================

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
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException) {
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

            // Coroutine 1: Disables touching
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

            // Coroutine 2: Should NOT be ignoring touch
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

    // =========================================================================
    // Combined Coroutine Isolation Test
    // =========================================================================

    public function testAllStateMethodsAreCoroutineIsolated(): void
    {
        run(function () {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            // Coroutine 1: Disables ALL features
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

            // Coroutine 2: Should have all features ENABLED
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

            // Coroutine 1: all disabled
            $this->assertTrue($results[1]['eventsDisabled'], 'Coroutine 1: events should be disabled');
            $this->assertFalse($results[1]['broadcasting'], 'Coroutine 1: broadcasting should be disabled');
            $this->assertTrue($results[1]['ignoringTouch'], 'Coroutine 1: should be ignoring touch');

            // Coroutine 2: all enabled (isolated)
            $this->assertFalse($results[2]['eventsDisabled'], 'Coroutine 2: events should be enabled');
            $this->assertTrue($results[2]['broadcasting'], 'Coroutine 2: broadcasting should be enabled');
            $this->assertFalse($results[2]['ignoringTouch'], 'Coroutine 2: should NOT be ignoring touch');
        });
    }
}

class CoroutineTestUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}
