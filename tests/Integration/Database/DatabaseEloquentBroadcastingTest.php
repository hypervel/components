<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Database\Eloquent\BroadcastableModelEventOccurred;
use Hypervel\Database\Eloquent\BroadcastsEvents;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Mockery as m;

class DatabaseEloquentBroadcastingTest extends DatabaseTestCase
{
    /**
     * Create the tables used by the tests.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_eloquent_broadcasting_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function testBasicBroadcasting(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUser;
        $model->name = 'Taylor';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUser
                    && count($event->broadcastOn()) === 1
                    && $event->model->name === 'Taylor'
                    && $event->broadcastOn()[0]->name === "private-Hypervel.Tests.Integration.Database.TestEloquentBroadcastUser.{$event->model->id}";
        });
    }

    public function testChannelRouteFormatting(): void
    {
        $model = new TestEloquentBroadcastUser;

        $this->assertSame('Hypervel.Tests.Integration.Database.TestEloquentBroadcastUser.{testEloquentBroadcastUser}', $model->broadcastChannelRoute());
    }

    public function testBroadcastingOnModelTrashing(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new SoftDeletableTestEloquentBroadcastUser;
        $model->name = 'Bean';
        $model->saveQuietly();

        $model->delete();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof SoftDeletableTestEloquentBroadcastUser
                && $event->event() === 'trashed'
                && count($event->broadcastOn()) === 1
                && $event->model->name === 'Bean'
                && $event->broadcastOn()[0]->name === "private-Hypervel.Tests.Integration.Database.SoftDeletableTestEloquentBroadcastUser.{$event->model->id}";
        });
    }

    public function testBroadcastingForSpecificEventsOnly(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUserOnSpecificEventsOnly;
        $model->name = 'James';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserOnSpecificEventsOnly
                && $event->event() === 'created'
                && count($event->broadcastOn()) === 1
                && $event->model->name === 'James'
                && $event->broadcastOn()[0]->name === "private-Hypervel.Tests.Integration.Database.TestEloquentBroadcastUserOnSpecificEventsOnly.{$event->model->id}";
        });

        $model->name = 'Graham';
        $model->save();

        Event::assertNotDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserOnSpecificEventsOnly
                && $event->model->name === 'Graham'
                && $event->event() === 'updated';
        });
    }

    public function testBroadcastNameDefault(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUser;
        $model->name = 'Mohamed';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUser
                && $event->model->name === 'Mohamed'
                && $event->broadcastAs() === 'TestEloquentBroadcastUserCreated'
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return $eventName === 'TestEloquentBroadcastUserCreated';
                });
        });
    }

    public function testBroadcastNameCanBeDefined(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUserWithSpecificBroadcastName;
        $model->name = 'Nuno';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserWithSpecificBroadcastName
                && $event->model->name === 'Nuno'
                && $event->broadcastAs() === 'foo'
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return $eventName === 'foo';
                });
        });

        $model->name = 'Dries';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserWithSpecificBroadcastName
                && $event->model->name === 'Dries'
                && $event->broadcastAs() === 'TestEloquentBroadcastUserWithSpecificBroadcastNameUpdated'
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return $eventName === 'TestEloquentBroadcastUserWithSpecificBroadcastNameUpdated';
                });
        });
    }

    public function testBroadcastPayloadDefault(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUser;
        $model->name = 'Nuno';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUser
                && $event->model->name === 'Nuno'
                && is_null($event->broadcastWith())
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return Arr::has($payload, ['model', 'connection', 'queue', 'socket']);
                });
        });
    }

    public function testBroadcastPayloadCanBeDefined(): void
    {
        Event::fake([BroadcastableModelEventOccurred::class]);

        $model = new TestEloquentBroadcastUserWithSpecificBroadcastPayload;
        $model->name = 'Dries';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserWithSpecificBroadcastPayload
                && $event->model->name === 'Dries'
                && $event->broadcastWith() === ['foo' => 'bar']
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return Arr::has($payload, ['foo', 'socket']);
                });
        });

        $model->name = 'Graham';
        $model->save();

        Event::assertDispatched(function (BroadcastableModelEventOccurred $event): bool {
            return $event->model instanceof TestEloquentBroadcastUserWithSpecificBroadcastPayload
                && $event->model->name === 'Graham'
                && is_null($event->broadcastWith())
                && $this->assertHandldedBroadcastableEvent($event, function (array $channels, string $eventName, array $payload): bool {
                    return Arr::has($payload, ['model', 'connection', 'queue', 'socket']);
                });
        });
    }

    /**
     * Assert the event is broadcast with the expected channels and payload.
     */
    private function assertHandldedBroadcastableEvent(BroadcastableModelEventOccurred $event, Closure $closure): bool
    {
        $broadcaster = m::mock(Broadcaster::class);
        $broadcaster->expects('broadcast')
            ->withArgs(function (array $channels, string $eventName, array $payload) use ($closure): bool {
                return $closure($channels, $eventName, $payload);
            });

        $manager = m::mock(BroadcastingFactory::class);
        $manager->expects('connection')->with(null)->andReturn($broadcaster);

        (new BroadcastEvent($event))->handle($manager);

        return true;
    }
}

class TestEloquentBroadcastUser extends Model
{
    use BroadcastsEvents;

    protected ?string $table = 'test_eloquent_broadcasting_users';
}

class SoftDeletableTestEloquentBroadcastUser extends Model
{
    use BroadcastsEvents;
    use SoftDeletes;

    protected ?string $table = 'test_eloquent_broadcasting_users';
}

class TestEloquentBroadcastUserOnSpecificEventsOnly extends Model
{
    use BroadcastsEvents;

    protected ?string $table = 'test_eloquent_broadcasting_users';

    /**
     * Get the channels for the model event.
     */
    public function broadcastOn(string $event): ?array
    {
        switch ($event) {
            case 'created':
                return [$this];
        }

        return null;
    }
}

class TestEloquentBroadcastUserWithSpecificBroadcastName extends Model
{
    use BroadcastsEvents;

    protected ?string $table = 'test_eloquent_broadcasting_users';

    /**
     * Get the custom broadcast name for the model event.
     */
    public function broadcastAs(string $event): ?string
    {
        switch ($event) {
            case 'created':
                return 'foo';
        }

        return null;
    }
}

class TestEloquentBroadcastUserWithSpecificBroadcastPayload extends Model
{
    use BroadcastsEvents;

    protected ?string $table = 'test_eloquent_broadcasting_users';

    /**
     * Get the custom broadcast payload for the model event.
     */
    public function broadcastWith(string $event): ?array
    {
        switch ($event) {
            case 'created':
                return ['foo' => 'bar'];
        }

        return null;
    }
}
