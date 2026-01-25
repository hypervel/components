<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration\Eloquent;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Database\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class EventsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        EventsTestUser::$eventLog = [];
    }

    public function testBasicModelCanBeCreatedAndRetrieved(): void
    {
        $user = EventsTestUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertInstanceOf(EventsTestUser::class, $user);
        $this->assertTrue($user->exists);
        $this->assertSame('John Doe', $user->name);
        $this->assertSame('john@example.com', $user->email);

        $retrieved = EventsTestUser::find($user->id);
        $this->assertNotNull($retrieved);
        $this->assertSame('John Doe', $retrieved->name);
    }

    public function testCreatingEventIsFired(): void
    {
        EventsTestUser::creating(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'creating:' . $user->name;
        });

        $user = EventsTestUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $this->assertContains('creating:Jane Doe', EventsTestUser::$eventLog);
    }

    public function testCreatedEventIsFired(): void
    {
        EventsTestUser::created(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'created:' . $user->id;
        });

        $user = EventsTestUser::create([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
        ]);

        $this->assertContains('created:' . $user->id, EventsTestUser::$eventLog);
    }

    public function testUpdatingAndUpdatedEventsAreFired(): void
    {
        EventsTestUser::updating(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'updating:' . $user->name;
        });

        EventsTestUser::updated(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'updated:' . $user->name;
        });

        $user = EventsTestUser::create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
        ]);

        EventsTestUser::$eventLog = [];

        $user->name = 'Updated Name';
        $user->save();

        $this->assertContains('updating:Updated Name', EventsTestUser::$eventLog);
        $this->assertContains('updated:Updated Name', EventsTestUser::$eventLog);
    }

    public function testSavingAndSavedEventsAreFired(): void
    {
        EventsTestUser::saving(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'saving:' . $user->name;
        });

        EventsTestUser::saved(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'saved:' . $user->name;
        });

        $user = EventsTestUser::create([
            'name' => 'Save Test',
            'email' => 'save@example.com',
        ]);

        $this->assertContains('saving:Save Test', EventsTestUser::$eventLog);
        $this->assertContains('saved:Save Test', EventsTestUser::$eventLog);
    }

    public function testDeletingAndDeletedEventsAreFired(): void
    {
        EventsTestUser::deleting(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'deleting:' . $user->id;
        });

        EventsTestUser::deleted(function (EventsTestUser $user) {
            EventsTestUser::$eventLog[] = 'deleted:' . $user->id;
        });

        $user = EventsTestUser::create([
            'name' => 'Delete Test',
            'email' => 'delete@example.com',
        ]);

        $userId = $user->id;
        EventsTestUser::$eventLog = [];

        $user->delete();

        $this->assertContains('deleting:' . $userId, EventsTestUser::$eventLog);
        $this->assertContains('deleted:' . $userId, EventsTestUser::$eventLog);
    }

    public function testCreatingEventCanPreventCreation(): void
    {
        EventsTestUser::creating(function (EventsTestUser $user) {
            if ($user->name === 'Blocked') {
                return false;
            }
        });

        $user = new EventsTestUser([
            'name' => 'Blocked',
            'email' => 'blocked@example.com',
        ]);

        $result = $user->save();

        $this->assertFalse($result);
        $this->assertFalse($user->exists);
        $this->assertNull(EventsTestUser::where('email', 'blocked@example.com')->first());
    }

    public function testObserverMethodsAreCalled(): void
    {
        EventsTestUser::observe(EventsTestUserObserver::class);

        $user = EventsTestUser::create([
            'name' => 'Observer Test',
            'email' => 'observer@example.com',
        ]);

        $this->assertContains('observer:creating:Observer Test', EventsTestUser::$eventLog);
        $this->assertContains('observer:created:' . $user->id, EventsTestUser::$eventLog);
    }
}

class EventsTestUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}

class EventsTestUserObserver
{
    public function creating(EventsTestUser $user): void
    {
        EventsTestUser::$eventLog[] = 'observer:creating:' . $user->name;
    }

    public function created(EventsTestUser $user): void
    {
        EventsTestUser::$eventLog[] = 'observer:created:' . $user->id;
    }
}
