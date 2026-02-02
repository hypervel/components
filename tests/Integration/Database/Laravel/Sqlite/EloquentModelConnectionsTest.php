<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Sqlite\EloquentModelConnectionsTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\Laravel\Sqlite\SqliteTestCase;
use UnitEnum;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelConnectionsTest extends SqliteTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'conn1');

        $app['config']->set('database.connections.conn1', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.conn2', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Clean up any existing tables from previous tests
        Schema::dropIfExists('child');
        Schema::dropIfExists('parent');
        Schema::connection('conn2')->dropIfExists('child');
        Schema::connection('conn2')->dropIfExists('parent');

        Schema::create('parent', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('child', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('parent_id');
        });

        Schema::connection('conn2')->create('parent', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::connection('conn2')->create('child', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('parent_id');
        });
    }

    public function testChildObeysParentConnection()
    {
        $parent1 = ParentModel::create(['name' => Str::random()]);
        $parent1->children()->create(['name' => 'childOnConn1']);
        $parents1 = ParentModel::with('children')->get();
        $this->assertSame('childOnConn1', ChildModel::on('conn1')->first()->name);
        $this->assertSame('childOnConn1', $parent1->children()->first()->name);
        $this->assertSame('childOnConn1', $parents1[0]->children[0]->name);

        $parent2 = ParentModel::on('conn2')->create(['name' => Str::random()]);
        $parent2->children()->create(['name' => 'childOnConn2']);
        $parents2 = ParentModel::on('conn2')->with('children')->get();
        $this->assertSame('childOnConn2', ChildModel::on('conn2')->first()->name);
        $this->assertSame('childOnConn2', $parent2->children()->first()->name);
        $this->assertSame('childOnConn2', $parents2[0]->children[0]->name);
    }

    public function testChildUsesItsOwnConnectionIfSet()
    {
        $parent1 = ParentModel::create(['name' => Str::random()]);
        $parent1->childrenDefaultConn2()->create(['name' => 'childAlwaysOnConn2']);
        $parents1 = ParentModel::with('childrenDefaultConn2')->get();
        $this->assertSame('childAlwaysOnConn2', ChildModelDefaultConn2::first()->name);
        $this->assertSame('childAlwaysOnConn2', $parent1->childrenDefaultConn2()->first()->name);
        $this->assertSame('childAlwaysOnConn2', $parents1[0]->childrenDefaultConn2[0]->name);
        $this->assertSame('childAlwaysOnConn2', $parents1[0]->childrenDefaultConn2[0]->name);
    }

    public function testChildUsesItsOwnConnectionIfSetEvenIfParentExplicitConnection()
    {
        $parent1 = ParentModel::on('conn1')->create(['name' => Str::random()]);
        $parent1->childrenDefaultConn2()->create(['name' => 'childAlwaysOnConn2']);
        $parents1 = ParentModel::on('conn1')->with('childrenDefaultConn2')->get();
        $this->assertSame('childAlwaysOnConn2', ChildModelDefaultConn2::first()->name);
        $this->assertSame('childAlwaysOnConn2', $parent1->childrenDefaultConn2()->first()->name);
        $this->assertSame('childAlwaysOnConn2', $parents1[0]->childrenDefaultConn2[0]->name);
    }
}

class ParentModel extends Model
{
    protected ?string $table = 'parent';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function children(): HasMany
    {
        return $this->hasMany(ChildModel::class, 'parent_id');
    }

    public function childrenDefaultConn2(): HasMany
    {
        return $this->hasMany(ChildModelDefaultConn2::class, 'parent_id');
    }
}

class ChildModel extends Model
{
    protected ?string $table = 'child';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }
}

class ChildModelDefaultConn2 extends Model
{
    protected UnitEnum|string|null $connection = 'conn2';

    protected ?string $table = 'child';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }
}
