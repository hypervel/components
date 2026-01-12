<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

/**
 * Tests that pivot model events fire when using a custom pivot class via ->using()
 * on MorphToMany relationships.
 *
 * @internal
 * @coversNothing
 */
class MorphToManyPivotEventsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear event log between tests
        MorphPivotEventsTestTaggable::$eventsCalled = [];
    }

    // =========================================================================
    // Tests for attach()
    // =========================================================================

    public function testAttachFiresCreatingAndCreatedEventsWithCustomMorphPivot(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);

        $post->tagsWithPivot()->attach($tag);

        $this->assertEquals(
            ['saving', 'creating', 'created', 'saved'],
            MorphPivotEventsTestTaggable::$eventsCalled
        );
    }

    public function testAttachMultipleFiresEventsForEachRecord(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag1 = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $tag2 = MorphPivotEventsTestTag::forceCreate(['name' => 'Laravel']);
        $tag3 = MorphPivotEventsTestTag::forceCreate(['name' => 'Hypervel']);

        $post->tagsWithPivot()->attach([$tag1->id, $tag2->id, $tag3->id]);

        // 3 creates = 3x (saving, creating, created, saved)
        $this->assertCount(12, MorphPivotEventsTestTaggable::$eventsCalled);
        $this->assertEquals(3, substr_count(implode(',', MorphPivotEventsTestTaggable::$eventsCalled), 'creating'));
        $this->assertEquals(3, substr_count(implode(',', MorphPivotEventsTestTaggable::$eventsCalled), 'created'));
    }

    public function testAttachWithoutCustomMorphPivotDoesNotFireEvents(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);

        // Using tagsWithoutPivot which doesn't use ->using()
        $post->tagsWithoutPivot()->attach($tag->id);

        $this->assertEquals([], MorphPivotEventsTestTaggable::$eventsCalled);

        $this->assertDatabaseHas('pivot_events_taggables', [
            'taggable_id' => $post->id,
            'taggable_type' => MorphPivotEventsTestPost::class,
            'tag_id' => $tag->id,
        ]);
    }

    // =========================================================================
    // Tests for detach()
    // =========================================================================

    public function testDetachFiresDeletingAndDeletedEventsWithCustomMorphPivot(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $post->tagsWithPivot()->attach($tag->id);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        $deleted = $post->tagsWithPivot()->detach($tag->id);

        $this->assertSame(1, $deleted);
        $this->assertEquals(['deleting', 'deleted'], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    public function testDetachMultipleFiresEventsForEachRecord(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag1 = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $tag2 = MorphPivotEventsTestTag::forceCreate(['name' => 'Laravel']);
        $post->tagsWithPivot()->attach([$tag1->id, $tag2->id]);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        $deleted = $post->tagsWithPivot()->detach([$tag1->id, $tag2->id]);

        $this->assertSame(2, $deleted);
        $this->assertEquals(['deleting', 'deleted', 'deleting', 'deleted'], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    public function testDetachAllFiresEventsForAllRecords(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag1 = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $tag2 = MorphPivotEventsTestTag::forceCreate(['name' => 'Laravel']);
        $post->tagsWithPivot()->attach([$tag1->id, $tag2->id]);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        $deleted = $post->tagsWithPivot()->detach();

        $this->assertSame(2, $deleted);
        $this->assertEquals(['deleting', 'deleted', 'deleting', 'deleted'], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    public function testDetachWithoutCustomMorphPivotDoesNotFireEvents(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $post->tagsWithoutPivot()->attach($tag->id);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        $post->tagsWithoutPivot()->detach($tag->id);

        $this->assertEquals([], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    // =========================================================================
    // Tests for updateExistingPivot()
    // =========================================================================

    public function testUpdateExistingPivotFiresSavingAndSavedEventsWithCustomMorphPivot(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $post->tagsWithPivot()->attach($tag->id, ['is_primary' => false]);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        $updated = $post->tagsWithPivot()->updateExistingPivot($tag->id, ['is_primary' => true]);

        $this->assertSame(1, $updated);
        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    public function testUpdateExistingPivotDoesNotFireEventsWhenNotDirty(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $post->tagsWithPivot()->attach($tag->id, ['is_primary' => true]);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        // Update with same value - should not be dirty
        $updated = $post->tagsWithPivot()->updateExistingPivot($tag->id, ['is_primary' => true]);

        $this->assertSame(0, $updated);
        $this->assertEquals([], MorphPivotEventsTestTaggable::$eventsCalled);
    }

    // =========================================================================
    // Tests for sync()
    // =========================================================================

    public function testSyncFiresEventsForAttachAndDetach(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag1 = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $tag2 = MorphPivotEventsTestTag::forceCreate(['name' => 'Laravel']);
        $tag3 = MorphPivotEventsTestTag::forceCreate(['name' => 'Hypervel']);

        // Attach tag1 and tag2
        $post->tagsWithPivot()->attach([$tag1->id, $tag2->id]);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        // Sync to tag2 and tag3 (detaches tag1, attaches tag3, keeps tag2)
        $changes = $post->tagsWithPivot()->sync([$tag2->id, $tag3->id]);

        $this->assertSame([$tag1->id], $changes['detached']);
        $this->assertSame([$tag3->id], $changes['attached']);

        $this->assertEquals(
            ['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'],
            MorphPivotEventsTestTaggable::$eventsCalled
        );
    }

    // =========================================================================
    // Tests for toggle()
    // =========================================================================

    public function testToggleFiresEventsForAttachAndDetach(): void
    {
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $tag1 = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);
        $tag2 = MorphPivotEventsTestTag::forceCreate(['name' => 'Laravel']);

        // Attach tag1
        $post->tagsWithPivot()->attach($tag1->id);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        // Toggle tag1 (detach) and tag2 (attach)
        $changes = $post->tagsWithPivot()->toggle([$tag1->id, $tag2->id]);

        $this->assertSame([$tag1->id], $changes['detached']);
        $this->assertContains($tag2->id, $changes['attached']);

        $this->assertEquals(
            ['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'],
            MorphPivotEventsTestTaggable::$eventsCalled
        );
    }

    // =========================================================================
    // Tests for morph type constraint in delete
    // =========================================================================

    public function testDetachOnlyDeletesForCorrectMorphType(): void
    {
        // Create a post and a video, both with the same tag
        $post = MorphPivotEventsTestPost::forceCreate(['title' => 'Test Post']);
        $video = MorphPivotEventsTestVideo::forceCreate(['title' => 'Test Video']);
        $tag = MorphPivotEventsTestTag::forceCreate(['name' => 'PHP']);

        $post->tagsWithPivot()->attach($tag->id);
        $video->tagsWithPivot()->attach($tag->id);

        MorphPivotEventsTestTaggable::$eventsCalled = [];

        // Detach from post only
        $deleted = $post->tagsWithPivot()->detach($tag->id);

        $this->assertSame(1, $deleted);

        // Video should still have the tag
        $this->assertDatabaseHas('pivot_events_taggables', [
            'taggable_id' => $video->id,
            'taggable_type' => MorphPivotEventsTestVideo::class,
            'tag_id' => $tag->id,
        ]);

        // Post should not have the tag
        $this->assertDatabaseMissing('pivot_events_taggables', [
            'taggable_id' => $post->id,
            'taggable_type' => MorphPivotEventsTestPost::class,
            'tag_id' => $tag->id,
        ]);
    }
}

// =============================================================================
// Test Models
// =============================================================================

class MorphPivotEventsTestPost extends Model
{
    protected ?string $table = 'pivot_events_posts';

    protected array $guarded = [];

    /**
     * Relationship WITH custom pivot class - should fire events.
     *
     * @return MorphToMany<MorphPivotEventsTestTag, $this, MorphPivotEventsTestTaggable>
     */
    public function tagsWithPivot(): MorphToMany
    {
        return $this->morphToMany(
            MorphPivotEventsTestTag::class,
            'taggable',
            'pivot_events_taggables',
            'taggable_id',
            'tag_id'
        )->using(MorphPivotEventsTestTaggable::class)->withPivot('is_primary')->withTimestamps();
    }

    /**
     * Relationship WITHOUT custom pivot class - should NOT fire events (uses raw queries).
     *
     * @return MorphToMany<MorphPivotEventsTestTag, $this>
     */
    public function tagsWithoutPivot(): MorphToMany
    {
        return $this->morphToMany(
            MorphPivotEventsTestTag::class,
            'taggable',
            'pivot_events_taggables',
            'taggable_id',
            'tag_id'
        )->withPivot('is_primary')->withTimestamps();
    }
}

class MorphPivotEventsTestVideo extends Model
{
    protected ?string $table = 'pivot_events_videos';

    protected array $guarded = [];

    /**
     * @return MorphToMany<MorphPivotEventsTestTag, $this, MorphPivotEventsTestTaggable>
     */
    public function tagsWithPivot(): MorphToMany
    {
        return $this->morphToMany(
            MorphPivotEventsTestTag::class,
            'taggable',
            'pivot_events_taggables',
            'taggable_id',
            'tag_id'
        )->using(MorphPivotEventsTestTaggable::class)->withPivot('is_primary')->withTimestamps();
    }
}

class MorphPivotEventsTestTag extends Model
{
    protected ?string $table = 'pivot_events_tags';

    protected array $guarded = [];
}

class MorphPivotEventsTestTaggable extends MorphPivot
{
    protected ?string $table = 'pivot_events_taggables';

    public bool $incrementing = false;

    public bool $timestamps = true;

    protected array $casts = [
        'is_primary' => 'boolean',
    ];

    public static array $eventsCalled = [];

    protected function boot(): void
    {
        parent::boot();

        static::registerCallback('creating', function ($model) {
            static::$eventsCalled[] = 'creating';
        });

        static::registerCallback('created', function ($model) {
            static::$eventsCalled[] = 'created';
        });

        static::registerCallback('updating', function ($model) {
            static::$eventsCalled[] = 'updating';
        });

        static::registerCallback('updated', function ($model) {
            static::$eventsCalled[] = 'updated';
        });

        static::registerCallback('saving', function ($model) {
            static::$eventsCalled[] = 'saving';
        });

        static::registerCallback('saved', function ($model) {
            static::$eventsCalled[] = 'saved';
        });

        static::registerCallback('deleting', function ($model) {
            static::$eventsCalled[] = 'deleting';
        });

        static::registerCallback('deleted', function ($model) {
            static::$eventsCalled[] = 'deleted';
        });
    }
}
