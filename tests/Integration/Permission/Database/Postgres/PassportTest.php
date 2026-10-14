<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Permission\Database\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Permission\Fixtures\Models\Client;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

#[RequiresDatabase('pgsql')]
class PassportTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'database.default' => getenv('DB_CONNECTION') ?: 'testing',
            'auth.providers.users.model' => PassportUuidUser::class,
            'permission.models.default_model' => PassportUuidUser::class,
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
        });

        // Replace empty morph columns: PostgreSQL cannot cast bigint columns to UUID.
        $morphKey = config()->string('permission.column_names.model_morph_key');

        foreach (['model_has_roles' => 'role_pivot_key', 'model_has_permissions' => 'permission_pivot_key'] as $tableKey => $pivotKey) {
            $tableName = config()->string('permission.table_names.' . $tableKey);
            $pivotColumn = config()->string('permission.column_names.' . $pivotKey);

            Schema::table($tableName, function (Blueprint $table) use ($morphKey, $pivotColumn): void {
                $table->dropColumn($morphKey);
                $table->uuid($morphKey);
                $table->index([$morphKey, 'model_type']);
                $table->primary([$pivotColumn, $morphKey, 'model_type']);
            });
        }

        (require dirname(__DIR__, 5) . '/src/passport/database/migrations/2016_06_01_000004_create_oauth_clients_table.php')->up();
        Passport::useClientModel(Client::class);
        $this->flushPermissionState();
    }

    public function testUuidUsersAndClientsSharePermissionAssignments(): void
    {
        $user = PassportUuidUser::create(['email' => 'assigned@example.com']);
        $otherUser = PassportUuidUser::create(['email' => 'unassigned@example.com']);
        $client = ClientFactory::new()->asClientCredentials()->create();
        $otherClient = ClientFactory::new()->asClientCredentials()->create();

        foreach ([[$user, $otherUser, 'web'], [$client, $otherClient, 'passport']] as [$subject, $other, $guard]) {
            $role = Role::create(['name' => 'editor', 'guard_name' => $guard]);
            $permission = Permission::create(['name' => 'edit-posts', 'guard_name' => $guard]);
            $subject->assignRole($role);
            $subject->givePermissionTo($permission);

            $this->assertTrue($subject->fresh()->hasRole($role));
            $this->assertTrue($subject->fresh()->hasPermissionTo($permission));
            $this->assertFalse($other->hasRole($role));
            $this->assertFalse($other->hasPermissionTo($permission));
            $this->assertSame([$subject->getKey()], $subject->newQuery()->role($role)->pluck('id')->all());
            $this->assertSame([$other->getKey()], $subject->newQuery()->withoutRole($role)->pluck('id')->all());
            $this->assertSame([$subject->getKey()], $subject->newQuery()->permission($permission)->pluck('id')->all());
            $this->assertSame([$other->getKey()], $subject->newQuery()->withoutPermission($permission)->pluck('id')->all());

            $subjects = $subject->newQuery()->with('roles')->get()->keyBy('id');

            $this->assertSame([$role->getKey()], $subjects[$subject->getKey()]->roles->modelKeys());
            $this->assertTrue($subjects[$other->getKey()]->roles->isEmpty());
        }
    }
}

class PassportUuidUser extends User
{
    use HasUuids;
}
