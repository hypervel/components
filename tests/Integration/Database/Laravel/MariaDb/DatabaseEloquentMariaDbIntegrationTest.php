<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\MariaDb;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentMariaDbIntegrationTest extends MariaDbTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('database_eloquent_mariadb_integration_users')) {
            Schema::create('database_eloquent_mariadb_integration_users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->timestamps();
            });
        }
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('database_eloquent_mariadb_integration_users');
    }

    public function testCreateOrFirst()
    {
        $user1 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(['email' => 'taylorotwell@gmail.com']);

        $this->assertSame('taylorotwell@gmail.com', $user1->email);
        $this->assertNull($user1->name);

        $user2 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(
            ['email' => 'taylorotwell@gmail.com'],
            ['name' => 'Taylor Otwell']
        );

        $this->assertEquals($user1->id, $user2->id);
        $this->assertSame('taylorotwell@gmail.com', $user2->email);
        $this->assertNull($user2->name);

        $user3 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(
            ['email' => 'abigailotwell@gmail.com'],
            ['name' => 'Abigail Otwell']
        );

        $this->assertNotEquals($user3->id, $user1->id);
        $this->assertSame('abigailotwell@gmail.com', $user3->email);
        $this->assertSame('Abigail Otwell', $user3->name);

        $user4 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(
            ['name' => 'Dries Vints'],
            ['name' => 'Nuno Maduro', 'email' => 'nuno@laravel.com']
        );

        $this->assertSame('Nuno Maduro', $user4->name);
    }

    public function testCreateOrFirstWithinTransaction()
    {
        $user1 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(['email' => 'taylor@laravel.com']);

        DB::transaction(function () use ($user1) {
            $user2 = DatabaseEloquentMariaDbIntegrationUser::createOrFirst(
                ['email' => 'taylor@laravel.com'],
                ['name' => 'Taylor Otwell']
            );

            $this->assertEquals($user1->id, $user2->id);
            $this->assertSame('taylor@laravel.com', $user2->email);
            $this->assertNull($user2->name);
        });
    }
}

class DatabaseEloquentMariaDbIntegrationUser extends Model
{
    protected ?string $table = 'database_eloquent_mariadb_integration_users';

    protected array $guarded = [];
}
