<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class DatabaseSchemaProxyTest extends TestCase
{
    /**
     * Configure isolated in-memory connections.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'primary');
        $app->make('config')->set('database.connections', [
            'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
    }

    public function testFacadeResolverAppliesToFreshBuildersOnTheSelectedConnection(): void
    {
        $calls = [];

        Schema::blueprintResolver(function (Connection $connection, string $table, ?Closure $callback) use (&$calls): Blueprint {
            $calls[] = [$connection, $table, $callback];

            return new Blueprint($connection, $table, $callback);
        });

        $create = static function (Blueprint $table): void {
            $table->id();
        };
        $alter = static function (Blueprint $table): void {
            $table->string('name');
        };

        Schema::create('users', $create);
        Schema::table('users', $alter);
        Schema::connection('secondary')->create('users', $create);
        DB::usingConnection('secondary', static function () use ($create): void {
            Schema::create('posts', $create);
        });

        $this->assertSame([
            [DB::connection('primary'), 'users', null],
            [DB::connection('primary'), 'users', $alter],
            [DB::connection('secondary'), 'users', null],
            [DB::connection('secondary'), 'posts', null],
        ], $calls);
        $this->assertTrue(Schema::hasColumn('users', 'name'));
        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertTrue(Schema::connection('secondary')->hasTable('posts'));
    }

    public function testFacadeUsesItsApplicationWhenTheGlobalContainerDiffers(): void
    {
        $primary = DB::connection('primary');
        $secondary = DB::connection('secondary');
        $container = Container::getInstance();

        Container::setInstance(new Container);

        try {
            $this->assertSame($secondary, Schema::connection('secondary')->getConnection());
            $this->assertSame($primary, Schema::getConnection());
        } finally {
            Container::setInstance($container);
        }
    }

    public function testBuilderResolverOverridesRemainLocal(): void
    {
        $defaultTables = [];
        $localTables = [];

        Schema::blueprintResolver(function (Connection $connection, string $table, ?Closure $callback) use (&$defaultTables): Blueprint {
            $defaultTables[] = $table;

            return new Blueprint($connection, $table, $callback);
        });

        $local = Schema::connection();
        $other = Schema::connection();

        $local->blueprintResolver(function (Connection $connection, string $table, ?Closure $callback) use (&$localTables): Blueprint {
            $localTables[] = $table;

            return new Blueprint($connection, $table, $callback);
        });

        $create = static function (Blueprint $table): void {
            $table->id();
        };

        $local->create('local_users', $create);
        $other->create('other_users', $create);
        Schema::create('default_users', $create);

        $this->assertSame(['local_users'], $localTables);
        $this->assertSame(['other_users', 'default_users'], $defaultTables);
    }
}
