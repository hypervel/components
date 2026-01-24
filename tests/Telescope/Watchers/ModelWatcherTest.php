<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ModelWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class ModelWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                ModelWatcher::class => [
                    'enabled' => true,
                    'events' => [
                        \Hypervel\Database\Eloquent\Events\Created::class,
                        \Hypervel\Database\Eloquent\Events\Updated::class,
                        \Hypervel\Database\Eloquent\Events\Retrieved::class,
                    ],
                    'hydrations' => true,
                ],
            ]);

        $this->startTelescope();
    }

    public function testModelWatcherRegistersEntry()
    {
        Telescope::withoutRecording(function () {
            $this->createUsersTable();
        });

        UserEloquent::query()
            ->create([
                'name' => 'Telescope',
                'email' => 'telescope@laravel.com',
                'password' => 1,
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::MODEL, $entry->type);
        $this->assertSame('created', $entry->content['action']);
        $this->assertSame(UserEloquent::class . ':1', $entry->content['model']);
    }

    public function testModelWatcherCanRestrictEvents()
    {
        Telescope::withoutRecording(function () {
            $this->createUsersTable();
        });

        $user = UserEloquent::query()
            ->create([
                'name' => 'Telescope',
                'email' => 'telescope@laravel.com',
                'password' => 1,
            ]);

        $user->delete();

        $entries = $this->loadTelescopeEntries();
        $entry = $entries->last();

        $this->assertCount(1, $entries);
        $this->assertSame(EntryType::MODEL, $entry->type);
        $this->assertSame('created', $entry->content['action']);
        $this->assertSame(UserEloquent::class . ':1', $entry->content['model']);
    }

    public function testModelWatcherRegistersHydrationEntry()
    {
        Telescope::withoutRecording(function () {
            $this->createUsersTable();
        });

        Telescope::stopRecording();
        $this->createUser();
        $this->createUser();
        $this->createUser();

        Telescope::startRecording();
        UserEloquent::all();
        Telescope::stopRecording();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::MODEL, $entry->type);
        $this->assertSame(3, $entry->content['count']);
        $this->assertSame(UserEloquent::class, $entry->content['model']);
        $this->assertCount(1, $this->loadTelescopeEntries());
    }

    protected function createUser()
    {
        UserEloquent::create([
            'name' => 'Telescope',
            'email' => Str::random(),
            'password' => 1,
        ]);
    }
}

class UserEloquent extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];
}
