<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentPivotSerializationTest;

use Hypervel\Database\Eloquent\Collection as DatabaseCollection;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class EloquentPivotSerializationTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('project_users', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('project_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->integer('tag_id');
            $table->integer('taggable_id');
            $table->string('taggable_type');
        });
    }

    public function testPivotCanBeSerializedAndRestored()
    {
        $user = PivotSerializationTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $project = PivotSerializationTestProject::forceCreate(['name' => 'Test Project']);
        $project->collaborators()->attach($user);

        $project = $project->fresh();

        $class = new PivotSerializationTestClass($project->collaborators->first()->pivot);
        $class = unserialize(serialize($class));

        $this->assertEquals($project->collaborators->first()->pivot->user_id, $class->pivot->user_id);
        $this->assertEquals($project->collaborators->first()->pivot->project_id, $class->pivot->project_id);

        $class->pivot->save();
    }

    public function testMorphPivotCanBeSerializedAndRestored()
    {
        $project = PivotSerializationTestProject::forceCreate(['name' => 'Test Project']);
        $tag = PivotSerializationTestTag::forceCreate(['name' => 'Test Tag']);
        $project->tags()->attach($tag);

        $project = $project->fresh();

        $class = new PivotSerializationTestClass($project->tags->first()->pivot);
        $class = unserialize(serialize($class));

        $this->assertEquals($project->tags->first()->pivot->tag_id, $class->pivot->tag_id);
        $this->assertEquals($project->tags->first()->pivot->taggable_id, $class->pivot->taggable_id);
        $this->assertEquals($project->tags->first()->pivot->taggable_type, $class->pivot->taggable_type);

        $class->pivot->save();
    }

    #[TestWith([PivotSerializationTestCollaborator::class])]
    #[TestWith([PivotSerializationTestInheritedCollaborator::class])]
    public function testCollectionOfPivotsCanBeSerializedAndRestored(string $pivotClass): void
    {
        $user = PivotSerializationTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $user2 = PivotSerializationTestUser::forceCreate(['email' => 'mohamed@laravel.com']);
        $project = PivotSerializationTestProject::forceCreate(['name' => 'Test Project']);

        $project->collaborators()->attach($user);
        $project->collaborators()->attach($user2);

        $project = $project->fresh();

        $project->setRelation('collaborators', $project->collaborators()->using($pivotClass)->get());

        $pivots = (new DatabaseCollection($project->collaborators->map->pivot))->load('user');
        $class = new PivotSerializationTestCollectionClass($pivots);
        $class = unserialize(serialize($class));

        $this->assertCount(2, $class->pivots);
        $this->assertEquals($project->collaborators[0]->pivot->user_id, $class->pivots[0]->user_id);
        $this->assertEquals($project->collaborators[1]->pivot->project_id, $class->pivots[1]->project_id);

        foreach ($class->pivots as $pivot) {
            $this->assertTrue($pivot->relationLoaded('user'));
            $this->assertSame($pivot->user_id, $pivot->user->getKey());
        }
    }

    public function testCollectionOfMorphPivotsCanBeSerializedAndRestored(): void
    {
        $tag = PivotSerializationTestTag::forceCreate(['name' => 'Test Tag 1']);
        $tag2 = PivotSerializationTestTag::forceCreate(['name' => 'Test Tag 2']);
        $project = PivotSerializationTestProject::forceCreate(['name' => 'Test Project']);

        $project->tags()->attach($tag);
        $project->tags()->attach($tag2);

        $project = $project->fresh();

        $pivots = (new DatabaseCollection($project->tags->map->pivot))->load('tag');
        $class = new PivotSerializationTestCollectionClass($pivots);
        $class = unserialize(serialize($class));

        $this->assertEquals($project->tags[0]->pivot->tag_id, $class->pivots[0]->tag_id);
        $this->assertEquals($project->tags[0]->pivot->taggable_id, $class->pivots[0]->taggable_id);
        $this->assertEquals($project->tags[0]->pivot->taggable_type, $class->pivots[0]->taggable_type);

        $this->assertEquals($project->tags[1]->pivot->tag_id, $class->pivots[1]->tag_id);
        $this->assertEquals($project->tags[1]->pivot->taggable_id, $class->pivots[1]->taggable_id);
        $this->assertEquals($project->tags[1]->pivot->taggable_type, $class->pivots[1]->taggable_type);

        foreach ($class->pivots as $pivot) {
            $this->assertTrue($pivot->relationLoaded('tag'));
            $this->assertSame($pivot->tag_id, $pivot->tag->getKey());
        }
    }

    #[DataProvider('morphPivotCompoundKeyColumns')]
    public function testMorphPivotQueueableIdsRejectMissingCompoundKeys(string $missingColumn): void
    {
        $pivot = $this->createMorphPivot();
        $attributes = $pivot->getAttributes();

        unset($attributes[$missingColumn]);

        $pivot->setRawAttributes($attributes, true);

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage("The attribute [{$missingColumn}]");

        $pivot->getQueueableId();
    }

    public static function morphPivotCompoundKeyColumns(): array
    {
        return [
            'foreign key' => ['taggable_id'],
            'related key' => ['tag_id'],
        ];
    }

    public function testMorphPivotQueueableIdsUseOriginalCompoundKeysAndRelationMorphMetadata(): void
    {
        $pivot = $this->createMorphPivot();
        $originalTagId = $pivot->tag_id;
        $originalTaggableId = $pivot->taggable_id;

        $pivot->tag_id = 98;
        $pivot->taggable_id = 99;
        $pivot->taggable_type = 'changed';

        $this->assertSame(
            "taggable_id:{$originalTaggableId}:tag_id:{$originalTagId}:taggable_type:"
                . PivotSerializationTestProject::class,
            $pivot->getQueueableId()
        );
    }

    private function createMorphPivot(): PivotSerializationTestTagAttachment
    {
        $project = PivotSerializationTestProject::forceCreate(['name' => 'Test Project']);
        $tag = PivotSerializationTestTag::forceCreate(['name' => 'Test Tag']);

        $project->tags()->attach($tag);

        return $project->tags()->firstOrFail()->pivot;
    }
}

class PivotSerializationTestClass
{
    use SerializesModels;

    public $pivot;

    public function __construct($pivot)
    {
        $this->pivot = $pivot;
    }
}

class PivotSerializationTestCollectionClass
{
    use SerializesModels;

    public $pivots;

    public function __construct($pivots)
    {
        $this->pivots = $pivots;
    }
}

class PivotSerializationTestUser extends Model
{
    public ?string $table = 'users';
}

class PivotSerializationTestProject extends Model
{
    public ?string $table = 'projects';

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotSerializationTestUser::class,
            'project_users',
            'project_id',
            'user_id'
        )->using(PivotSerializationTestCollaborator::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(PivotSerializationTestTag::class, 'taggable', 'taggables', 'taggable_id', 'tag_id')
            ->using(PivotSerializationTestTagAttachment::class);
    }
}

class PivotSerializationTestTag extends Model
{
    public ?string $table = 'tags';

    public function projects(): MorphToMany
    {
        return $this->morphedByMany(PivotSerializationTestProject::class, 'taggable', 'taggables', 'tag_id', 'taggable_id')
            ->using(PivotSerializationTestTagAttachment::class);
    }
}

class PivotSerializationTestCollaborator extends Pivot
{
    public ?string $table = 'project_users';

    public bool $timestamps = false;

    /**
     * Get the collaborator's user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PivotSerializationTestUser::class, 'user_id');
    }
}

class PivotSerializationTestAsPivotModel extends Model
{
    use AsPivot;

    public ?string $table = 'project_users';

    public bool $timestamps = false;

    /**
     * Get the collaborator's user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PivotSerializationTestUser::class, 'user_id');
    }
}

class PivotSerializationTestInheritedCollaborator extends PivotSerializationTestAsPivotModel
{
}

class PivotSerializationTestTagAttachment extends MorphPivot
{
    public ?string $table = 'taggables';

    public bool $timestamps = false;

    /**
     * Get the attached tag.
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(PivotSerializationTestTag::class, 'tag_id');
    }
}
