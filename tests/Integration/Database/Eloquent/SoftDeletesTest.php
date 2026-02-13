<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Eloquent;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class SoftDeletesTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('soft_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $post = SoftPost::create(['title' => 'Test Post', 'body' => 'Test Body']);

        $this->assertNull($post->deleted_at);

        $post->delete();

        $this->assertNotNull($post->deleted_at);
        $this->assertInstanceOf(CarbonInterface::class, $post->deleted_at);
    }

    public function testSoftDeletedModelsAreExcludedByDefault(): void
    {
        $post1 = SoftPost::create(['title' => 'Post 1', 'body' => 'Body 1']);
        $post2 = SoftPost::create(['title' => 'Post 2', 'body' => 'Body 2']);
        $post3 = SoftPost::create(['title' => 'Post 3', 'body' => 'Body 3']);

        $post2->delete();

        $posts = SoftPost::all();

        $this->assertCount(2, $posts);
        $this->assertNull(SoftPost::find($post2->id));
    }

    public function testWithTrashedIncludesSoftDeleted(): void
    {
        $post1 = SoftPost::create(['title' => 'Post 1', 'body' => 'Body 1']);
        $post2 = SoftPost::create(['title' => 'Post 2', 'body' => 'Body 2']);

        $post2->delete();

        $posts = SoftPost::withTrashed()->get();

        $this->assertCount(2, $posts);
    }

    public function testOnlyTrashedReturnsOnlySoftDeleted(): void
    {
        $post1 = SoftPost::create(['title' => 'Post 1', 'body' => 'Body 1']);
        $post2 = SoftPost::create(['title' => 'Post 2', 'body' => 'Body 2']);
        $post3 = SoftPost::create(['title' => 'Post 3', 'body' => 'Body 3']);

        $post1->delete();
        $post3->delete();

        $trashedPosts = SoftPost::onlyTrashed()->get();

        $this->assertCount(2, $trashedPosts);
        $this->assertContains('Post 1', $trashedPosts->pluck('title')->toArray());
        $this->assertContains('Post 3', $trashedPosts->pluck('title')->toArray());
    }

    public function testTrashedMethodReturnsTrue(): void
    {
        $post = SoftPost::create(['title' => 'Test', 'body' => 'Body']);

        $this->assertFalse($post->trashed());

        $post->delete();

        $this->assertTrue($post->trashed());
    }

    public function testRestoreModel(): void
    {
        $post = SoftPost::create(['title' => 'Restore Test', 'body' => 'Body']);
        $post->delete();

        $this->assertTrue($post->trashed());
        $this->assertNull(SoftPost::find($post->id));

        $post->restore();

        $this->assertFalse($post->trashed());
        $this->assertNull($post->deleted_at);
        $this->assertNotNull(SoftPost::find($post->id));
    }

    public function testForceDeletePermanentlyRemoves(): void
    {
        $post = SoftPost::create(['title' => 'Force Delete Test', 'body' => 'Body']);
        $postId = $post->id;

        $post->forceDelete();

        $this->assertNull(SoftPost::withTrashed()->find($postId));
    }

    public function testSoftDeletedEventsAreFired(): void
    {
        SoftPost::$eventLog = [];

        SoftPost::deleting(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'deleting:' . $post->id;
        });

        SoftPost::deleted(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'deleted:' . $post->id;
        });

        $post = SoftPost::create(['title' => 'Event Test', 'body' => 'Body']);
        $postId = $post->id;

        $post->delete();

        $this->assertContains('deleting:' . $postId, SoftPost::$eventLog);
        $this->assertContains('deleted:' . $postId, SoftPost::$eventLog);
    }

    public function testRestoringAndRestoredEventsAreFired(): void
    {
        SoftPost::$eventLog = [];

        SoftPost::restoring(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'restoring:' . $post->id;
        });

        SoftPost::restored(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'restored:' . $post->id;
        });

        $post = SoftPost::create(['title' => 'Restore Event Test', 'body' => 'Body']);
        $postId = $post->id;
        $post->delete();

        SoftPost::$eventLog = [];

        $post->restore();

        $this->assertContains('restoring:' . $postId, SoftPost::$eventLog);
        $this->assertContains('restored:' . $postId, SoftPost::$eventLog);
    }

    public function testForceDeletedEventsAreFired(): void
    {
        SoftPost::$eventLog = [];

        SoftPost::forceDeleting(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'forceDeleting:' . $post->id;
        });

        SoftPost::forceDeleted(function (SoftPost $post) {
            SoftPost::$eventLog[] = 'forceDeleted:' . $post->id;
        });

        $post = SoftPost::create(['title' => 'Force Delete Event Test', 'body' => 'Body']);
        $postId = $post->id;

        $post->forceDelete();

        $this->assertContains('forceDeleting:' . $postId, SoftPost::$eventLog);
        $this->assertContains('forceDeleted:' . $postId, SoftPost::$eventLog);
    }

    public function testWithTrashedOnFind(): void
    {
        $post = SoftPost::create(['title' => 'Find Test', 'body' => 'Body']);
        $postId = $post->id;
        $post->delete();

        $notFound = SoftPost::find($postId);
        $this->assertNull($notFound);

        $found = SoftPost::withTrashed()->find($postId);
        $this->assertNotNull($found);
        $this->assertSame('Find Test', $found->title);
    }

    public function testQueryBuilderWhereOnSoftDeletes(): void
    {
        $post1 = SoftPost::create(['title' => 'Active Post', 'body' => 'Body']);
        $post2 = SoftPost::create(['title' => 'Deleted Post', 'body' => 'Body']);
        $post2->delete();

        $results = SoftPost::where('title', 'like', '%Post%')->get();
        $this->assertCount(1, $results);

        $resultsWithTrashed = SoftPost::withTrashed()->where('title', 'like', '%Post%')->get();
        $this->assertCount(2, $resultsWithTrashed);
    }

    public function testCountWithSoftDeletes(): void
    {
        SoftPost::create(['title' => 'Post 1', 'body' => 'Body']);
        SoftPost::create(['title' => 'Post 2', 'body' => 'Body']);
        $post3 = SoftPost::create(['title' => 'Post 3', 'body' => 'Body']);
        $post3->delete();

        $this->assertSame(2, SoftPost::count());
        $this->assertSame(3, SoftPost::withTrashed()->count());
        $this->assertSame(1, SoftPost::onlyTrashed()->count());
    }

    public function testDeleteByQuery(): void
    {
        SoftPost::create(['title' => 'PHP Post', 'body' => 'Body']);
        SoftPost::create(['title' => 'PHP Tutorial', 'body' => 'Body']);
        SoftPost::create(['title' => 'Laravel Post', 'body' => 'Body']);

        SoftPost::where('title', 'like', 'PHP%')->delete();

        $this->assertSame(1, SoftPost::count());
        $this->assertSame(2, SoftPost::onlyTrashed()->count());
    }

    public function testRestoreByQuery(): void
    {
        $post1 = SoftPost::create(['title' => 'Restore 1', 'body' => 'Body']);
        $post2 = SoftPost::create(['title' => 'Restore 2', 'body' => 'Body']);
        $post3 = SoftPost::create(['title' => 'Keep Deleted', 'body' => 'Body']);

        $post1->delete();
        $post2->delete();
        $post3->delete();

        SoftPost::onlyTrashed()->where('title', 'like', 'Restore%')->restore();

        $this->assertSame(2, SoftPost::count());
        $this->assertSame(1, SoftPost::onlyTrashed()->count());
    }

    public function testForceDeleteByQuery(): void
    {
        SoftPost::create(['title' => 'Keep 1', 'body' => 'Body']);
        SoftPost::create(['title' => 'Force Delete 1', 'body' => 'Body']);
        SoftPost::create(['title' => 'Force Delete 2', 'body' => 'Body']);

        SoftPost::where('title', 'like', 'Force Delete%')->forceDelete();

        $this->assertSame(1, SoftPost::count());
        $this->assertSame(1, SoftPost::withTrashed()->count());
    }
}

class SoftPost extends Model
{
    use SoftDeletes;

    protected ?string $table = 'soft_posts';

    protected array $fillable = ['title', 'body'];

    public static array $eventLog = [];
}
