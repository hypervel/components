<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentUniqueStringPrimaryKeysTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('foo');
            $table->uuid('bar');
            $table->timestamps();
        });

        Schema::create('foo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('foo');
            $table->ulid('bar');
            $table->timestamps();
        });

        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->uuid('foo');
            $table->uuid('bar');
            $table->timestamps();
        });

        Schema::create('pictures', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->timestamps();
        });
    }

    public function testModelWithUuidPrimaryKeyCanBeCreated()
    {
        $user = ModelWithUuidPrimaryKey::create();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertTrue(Str::isUuid($user->foo));
        $this->assertTrue(Str::isUuid($user->bar));
    }

    public function testModelWithUlidPrimaryKeyCanBeCreated()
    {
        $user = ModelWithUlidPrimaryKey::create();

        $this->assertTrue(Str::isUlid($user->id));
        $this->assertTrue(Str::isUlid($user->foo));
        $this->assertTrue(Str::isUlid($user->bar));
    }

    public function testModelWithoutUuidPrimaryKeyCanBeCreated()
    {
        $user = ModelWithoutUuidPrimaryKey::create();

        $this->assertTrue(is_int($user->id));
        $this->assertTrue(Str::isUuid($user->foo));
        $this->assertTrue(Str::isUuid($user->bar));
    }

    public function testModelWithCustomUuidPrimaryKeyNameCanBeCreated()
    {
        $user = ModelWithCustomUuidPrimaryKeyName::create();

        $this->assertTrue(Str::isUuid($user->uuid));
    }

    public function testModelWithUuidPrimaryKeyCanBeCreatedQuietly()
    {
        $user = new ModelWithUuidPrimaryKey();

        $user->saveQuietly();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertTrue(Str::isUuid($user->foo));
        $this->assertTrue(Str::isUuid($user->bar));
    }

    public function testModelWithUlidPrimaryKeyCanBeCreatedQuietly()
    {
        $user = new ModelWithUlidPrimaryKey();

        $user->saveQuietly();

        $this->assertTrue(Str::isUlid($user->id));
        $this->assertTrue(Str::isUlid($user->foo));
        $this->assertTrue(Str::isUlid($user->bar));
    }

    public function testModelWithoutUuidPrimaryKeyCanBeCreatedQuietly()
    {
        $user = new ModelWithoutUuidPrimaryKey();

        $user->saveQuietly();

        $this->assertTrue(is_int($user->id));
        $this->assertTrue(Str::isUuid($user->foo));
        $this->assertTrue(Str::isUuid($user->bar));
    }

    public function testModelWithCustomUuidPrimaryKeyNameCanBeCreatedQuietly()
    {
        $user = new ModelWithCustomUuidPrimaryKeyName();

        $user->saveQuietly();

        $this->assertTrue(Str::isUuid($user->uuid));
    }

    public function testUpsertWithUuidPrimaryKey()
    {
        ModelUpsertWithUuidPrimaryKey::create(['email' => 'foo', 'name' => 'bar']);
        ModelUpsertWithUuidPrimaryKey::create(['name' => 'bar1', 'email' => 'foo2']);

        ModelUpsertWithUuidPrimaryKey::upsert([['email' => 'foo3', 'name' => 'bar'], ['name' => 'bar2', 'email' => 'foo2']], ['email']);

        $this->assertEquals(3, ModelUpsertWithUuidPrimaryKey::count());
    }
}

class ModelWithUuidPrimaryKey extends Eloquent
{
    use HasUuids;

    protected ?string $table = 'users';

    protected array $guarded = [];

    public function uniqueIds(): array
    {
        return [$this->getKeyName(), 'foo', 'bar'];
    }
}

class ModelUpsertWithUuidPrimaryKey extends Eloquent
{
    use HasUuids;

    protected ?string $table = 'foo';

    protected array $guarded = [];

    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }
}

class ModelWithUlidPrimaryKey extends Eloquent
{
    use HasUlids;

    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function uniqueIds(): array
    {
        return [$this->getKeyName(), 'foo', 'bar'];
    }
}

class ModelWithoutUuidPrimaryKey extends Eloquent
{
    use HasUuids;

    protected ?string $table = 'songs';

    protected array $guarded = [];

    public function uniqueIds(): array
    {
        return ['foo', 'bar'];
    }
}

class ModelWithCustomUuidPrimaryKeyName extends Eloquent
{
    use HasUuids;

    protected ?string $table = 'pictures';

    protected array $guarded = [];

    protected string $primaryKey = 'uuid';
}
