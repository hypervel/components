<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Str;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ModelWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    ModelWatcher::class => [
        'enabled' => true,
        'events' => ['eloquent.created*', 'eloquent.updated*', 'eloquent.retrieved*'],
        'hydrations' => true,
    ],
])]
class ModelWatcherTest extends FeatureTestCase
{
    public function testModelWatcherRegistersEntry()
    {
        UserEloquent::query()
            ->create([
                'name' => 'Telescope',
                'email' => 'telescope@hypervel.org',
                'password' => 1,
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::MODEL, $entry->type);
        $this->assertSame('created', $entry->content['action']);
        $this->assertSame(UserEloquent::class . ':1', $entry->content['model']);
    }

    public function testModelWatcherCanRestrictEvents()
    {
        $user = UserEloquent::query()
            ->create([
                'name' => 'Telescope',
                'email' => 'telescope@hypervel.org',
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
