<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tmp;

use Hypervel\Database\Eloquent\Model;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class ModelEventsIntegrationTest extends TmpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset static state
        TmpUser::$eventLog = [];
    }

    public function testBasicModelCanBeCreatedAndRetrieved(): void
    {
        $user = TmpUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertInstanceOf(TmpUser::class, $user);
        $this->assertTrue($user->exists);
        $this->assertSame('John Doe', $user->name);
        $this->assertSame('john@example.com', $user->email);

        // Retrieve from database
        $retrieved = TmpUser::find($user->id);
        $this->assertNotNull($retrieved);
        $this->assertSame('John Doe', $retrieved->name);
    }

    public function testCreatingEventIsFired(): void
    {
        TmpUser::creating(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'creating:' . $user->name;
        });

        $user = TmpUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $this->assertContains('creating:Jane Doe', TmpUser::$eventLog);
    }

    public function testCreatedEventIsFired(): void
    {
        TmpUser::created(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'created:' . $user->id;
        });

        $user = TmpUser::create([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
        ]);

        $this->assertContains('created:' . $user->id, TmpUser::$eventLog);
    }

    public function testUpdatingAndUpdatedEventsAreFired(): void
    {
        TmpUser::updating(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'updating:' . $user->name;
        });

        TmpUser::updated(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'updated:' . $user->name;
        });

        $user = TmpUser::create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
        ]);

        TmpUser::$eventLog = []; // Reset after creation

        $user->name = 'Updated Name';
        $user->save();

        $this->assertContains('updating:Updated Name', TmpUser::$eventLog);
        $this->assertContains('updated:Updated Name', TmpUser::$eventLog);
    }

    public function testSavingAndSavedEventsAreFired(): void
    {
        TmpUser::saving(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'saving:' . $user->name;
        });

        TmpUser::saved(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'saved:' . $user->name;
        });

        $user = TmpUser::create([
            'name' => 'Save Test',
            'email' => 'save@example.com',
        ]);

        $this->assertContains('saving:Save Test', TmpUser::$eventLog);
        $this->assertContains('saved:Save Test', TmpUser::$eventLog);
    }

    public function testDeletingAndDeletedEventsAreFired(): void
    {
        TmpUser::deleting(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'deleting:' . $user->id;
        });

        TmpUser::deleted(function (TmpUser $user) {
            TmpUser::$eventLog[] = 'deleted:' . $user->id;
        });

        $user = TmpUser::create([
            'name' => 'Delete Test',
            'email' => 'delete@example.com',
        ]);

        $userId = $user->id;
        TmpUser::$eventLog = []; // Reset after creation

        $user->delete();

        $this->assertContains('deleting:' . $userId, TmpUser::$eventLog);
        $this->assertContains('deleted:' . $userId, TmpUser::$eventLog);
    }

    public function testCreatingEventCanPreventCreation(): void
    {
        TmpUser::creating(function (TmpUser $user) {
            if ($user->name === 'Blocked') {
                return false;
            }
        });

        $user = new TmpUser([
            'name' => 'Blocked',
            'email' => 'blocked@example.com',
        ]);

        $result = $user->save();

        $this->assertFalse($result);
        $this->assertFalse($user->exists);
        $this->assertNull(TmpUser::where('email', 'blocked@example.com')->first());
    }

    public function testObserverMethodsAreCalled(): void
    {
        TmpUser::observe(TmpUserObserver::class);

        $user = TmpUser::create([
            'name' => 'Observer Test',
            'email' => 'observer@example.com',
        ]);

        $this->assertContains('observer:creating:Observer Test', TmpUser::$eventLog);
        $this->assertContains('observer:created:' . $user->id, TmpUser::$eventLog);
    }
}

class TmpUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}

class TmpUserObserver
{
    public function creating(TmpUser $user): void
    {
        TmpUser::$eventLog[] = 'observer:creating:' . $user->name;
    }

    public function created(TmpUser $user): void
    {
        TmpUser::$eventLog[] = 'observer:created:' . $user->id;
    }
}
