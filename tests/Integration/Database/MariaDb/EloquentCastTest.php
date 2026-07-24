<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Schema;

class EloquentCastTest extends MariaDbTestCase
{
    protected string $driver = 'mariadb';

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('users_nullable_timestamps', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('users');
    }

    public function testItCastTimestampsCreatedByTheBuilderWhenTimeHasNotPassed(): void
    {
        CarbonImmutable::setTestNow(now());
        $createdAt = now()->timestamp;

        $castUser = UserWithIntTimestampsViaCasts::create([
            'email' => fake()->unique()->email,
        ]);
        $attributeUser = UserWithIntTimestampsViaAttribute::create([
            'email' => fake()->unique()->email,
        ]);
        $mutatorUser = UserWithIntTimestampsViaMutator::create([
            'email' => fake()->unique()->email,
        ]);

        $this->assertSame($createdAt, $castUser->created_at->timestamp);
        $this->assertSame($createdAt, $castUser->updated_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->created_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->updated_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->created_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->updated_at->timestamp);

        $castUser->update([
            'email' => fake()->unique()->email,
        ]);
        $attributeUser->update([
            'email' => fake()->unique()->email,
        ]);
        $mutatorUser->update([
            'email' => fake()->unique()->email,
        ]);

        $this->assertSame($createdAt, $castUser->created_at->timestamp);
        $this->assertSame($createdAt, $castUser->updated_at->timestamp);
        $this->assertSame($createdAt, $castUser->fresh()->updated_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->created_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->updated_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->fresh()->updated_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->created_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->updated_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->fresh()->updated_at->timestamp);
    }

    public function testItCastTimestampsCreatedByTheBuilderWhenTimeHasPassed(): void
    {
        CarbonImmutable::setTestNow(now());
        $createdAt = now()->timestamp;

        $castUser = UserWithIntTimestampsViaCasts::create([
            'email' => fake()->unique()->email,
        ]);
        $attributeUser = UserWithIntTimestampsViaAttribute::create([
            'email' => fake()->unique()->email,
        ]);
        $mutatorUser = UserWithIntTimestampsViaMutator::create([
            'email' => fake()->unique()->email,
        ]);

        $this->assertSame($createdAt, $castUser->created_at->timestamp);
        $this->assertSame($createdAt, $castUser->updated_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->created_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->updated_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->created_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->updated_at->timestamp);

        CarbonImmutable::setTestNow(now()->addSecond());
        $updatedAt = now()->timestamp;

        $castUser->update([
            'email' => fake()->unique()->email,
        ]);
        $attributeUser->update([
            'email' => fake()->unique()->email,
        ]);
        $mutatorUser->update([
            'email' => fake()->unique()->email,
        ]);

        $this->assertSame($createdAt, $castUser->created_at->timestamp);
        $this->assertSame($updatedAt, $castUser->updated_at->timestamp);
        $this->assertSame($updatedAt, $castUser->fresh()->updated_at->timestamp);
        $this->assertSame($createdAt, $attributeUser->created_at->timestamp);
        $this->assertSame($updatedAt, $attributeUser->updated_at->timestamp);
        $this->assertSame($updatedAt, $attributeUser->fresh()->updated_at->timestamp);
        $this->assertSame($createdAt, $mutatorUser->created_at->timestamp);
        $this->assertSame($updatedAt, $mutatorUser->updated_at->timestamp);
        $this->assertSame($updatedAt, $mutatorUser->fresh()->updated_at->timestamp);
    }

    public function testItCastTimestampsUpdatedByAMutator(): void
    {
        CarbonImmutable::setTestNow(now());

        $mutatorUser = UserWithUpdatedAtViaMutator::create([
            'email' => fake()->unique()->email,
        ]);

        $this->assertNull($mutatorUser->updated_at);

        CarbonImmutable::setTestNow(now()->addSecond());
        $updatedAt = now()->timestamp;

        $mutatorUser->update([
            'email' => fake()->unique()->email,
        ]);

        $this->assertSame($updatedAt, $mutatorUser->updated_at->timestamp);
        $this->assertSame($updatedAt, $mutatorUser->fresh()->updated_at->timestamp);
    }
}

class UserWithIntTimestampsViaCasts extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    protected array $casts = [
        'created_at' => UnixTimeStampToCarbon::class,
        'updated_at' => UnixTimeStampToCarbon::class,
    ];
}

class UnixTimeStampToCarbon implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return CarbonImmutable::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return CarbonImmutable::parse($value)->timestamp;
    }
}

class UserWithIntTimestampsViaAttribute extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => CarbonImmutable::parse($value),
            set: fn ($value) => CarbonImmutable::parse($value)->timestamp,
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => CarbonImmutable::parse($value),
            set: fn ($value) => CarbonImmutable::parse($value)->timestamp,
        );
    }
}

class UserWithIntTimestampsViaMutator extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    protected function getUpdatedAtAttribute($value)
    {
        return CarbonImmutable::parse($value);
    }

    protected function setUpdatedAtAttribute($value)
    {
        $this->attributes['updated_at'] = CarbonImmutable::parse($value)->timestamp;
    }

    protected function getCreatedAtAttribute($value)
    {
        return CarbonImmutable::parse($value);
    }

    protected function setCreatedAtAttribute($value)
    {
        $this->attributes['created_at'] = CarbonImmutable::parse($value)->timestamp;
    }
}

class UserWithUpdatedAtViaMutator extends Model
{
    protected ?string $table = 'users_nullable_timestamps';

    protected array $fillable = ['email', 'updated_at'];

    public function setUpdatedAtAttribute($value)
    {
        if (! $this->id) {
            return;
        }

        $this->attributes['updated_at'] = $value;
    }
}
