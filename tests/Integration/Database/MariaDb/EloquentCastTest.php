<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts\UserWithIntTimestampsViaAttribute;
use Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts\UserWithIntTimestampsViaCasts;
use Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts\UserWithIntTimestampsViaMutator;
use Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts\UserWithUpdatedAtViaMutator;

class EloquentCastTest extends MariaDbTestCase
{
    protected string $driver = 'mariadb';

    /**
     * Set up the database after refreshing it.
     */
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

    /**
     * Remove the database tables.
     */
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
