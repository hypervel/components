<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentBelongsToManyCreateOrFirstCollisionTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentBelongsToManyCreateOrFirstCollisionTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('collision_sources', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('collision_related', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
        });

        Schema::create('collision_pivot', function (Blueprint $table): void {
            $table->integer('source_id');
            $table->integer('related_id');
            $table->string('collision_key')->unique();
            $table->unique(['source_id', 'related_id']);
        });
    }

    public function testFirstOrCreateRethrowsAnIndependentPivotUniqueViolation(): void
    {
        [$source, $related] = $this->seedIndependentPivotCollision();

        try {
            $source->related()->firstOrCreate(
                ['name' => $related->name],
                joining: ['collision_key' => 'occupied'],
                touch: false,
            );

            $this->fail('Expected the independent pivot unique violation to be rethrown.');
        } catch (UniqueConstraintViolationException) {
            $this->assertExactPivotIsMissing($source, $related);
        }
    }

    public function testCreateOrFirstRethrowsAnIndependentPivotUniqueViolation(): void
    {
        [$source, $related] = $this->seedIndependentPivotCollision();

        try {
            $source->related()->createOrFirst(
                ['name' => $related->name],
                joining: ['collision_key' => 'occupied'],
                touch: false,
            );

            $this->fail('Expected the independent pivot unique violation to be rethrown.');
        } catch (UniqueConstraintViolationException) {
            $this->assertExactPivotIsMissing($source, $related);
        }
    }

    /**
     * Seed a pivot row whose independent unique key will collide with the attempted attachment.
     *
     * @return array{CollisionSource, CollisionRelated}
     */
    protected function seedIndependentPivotCollision(): array
    {
        $source = CollisionSource::create();
        $related = CollisionRelated::create(['name' => 'target']);
        $otherSource = CollisionSource::create();
        $otherRelated = CollisionRelated::create(['name' => 'other']);

        DB::table('collision_pivot')->insert([
            'source_id' => $otherSource->id,
            'related_id' => $otherRelated->id,
            'collision_key' => 'occupied',
        ]);

        return [$source, $related];
    }

    /**
     * Assert that the intended relation membership was not created.
     */
    protected function assertExactPivotIsMissing(CollisionSource $source, CollisionRelated $related): void
    {
        $this->assertFalse(
            DB::table('collision_pivot')
                ->where('source_id', $source->id)
                ->where('related_id', $related->id)
                ->exists()
        );
    }
}

class CollisionSource extends Model
{
    protected ?string $table = 'collision_sources';

    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the related models.
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(
            CollisionRelated::class,
            'collision_pivot',
            'source_id',
            'related_id',
        );
    }
}

class CollisionRelated extends Model
{
    protected ?string $table = 'collision_related';

    protected array $guarded = [];

    public bool $timestamps = false;
}
