<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Comment;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
use Override;

class JsonApiRelationshipLoadingTest extends TestCase
{
    public function testEachParentKeepsItsOwnLoaderConstraint(): void
    {
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            foreach (['published', 'draft'] as $content) {
                $post = Post::factory()->create(['user_id' => $user->getKey()]);
                Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $post->getKey(), 'content' => $content]);
            }
        }

        $collection = new AnonymousResourceCollection(new BaseCollection([
            new ParentSpecificUserResource($users[0], 'published'),
            new ParentSpecificUserResource($users[1], 'draft'),
        ]), ParentSpecificUserResource::class);

        $response = $collection->toResponse(JsonApiRequest::create('/?include=posts.comments'))->getData(true);
        $comments = array_values(array_filter($response['included'], fn ($entry) => $entry['type'] === 'comments'));
        $this->assertSame(['published', 'draft'], array_column(array_column($comments, 'attributes'), 'content'));
    }

    public function testParentResourceHookControlsItsNestedRelationshipLoading(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->getKey()]);
        Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $post->getKey(), 'content' => 'published']);
        $otherPost = Post::factory()->create(['user_id' => $user->getKey()]);
        Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $otherPost->getKey(), 'content' => 'draft']);

        $resource = new class($user) extends UserResource {
            public array $calls = [];

            /**
             * Constrain comments before the default loader runs.
             */
            #[Override]
            protected function loadMissingRequestedRelationships(
                JsonApiRequest $request,
                BaseCollection $relatedModels,
                ?string $resourceClass,
                array $requestedRelationships
            ): void {
                $this->calls[] = [$relatedModels->first()::class, $relatedModels->count(), $resourceClass, $requestedRelationships];

                if ($relatedModels->first() instanceof Post) {
                    (new Collection($relatedModels->all()))->loadMissing([
                        'comments' => fn ($query) => $query->where('content', 'published'),
                    ]);
                }
                parent::loadMissingRequestedRelationships($request, $relatedModels, $resourceClass, $requestedRelationships);
            }
        };

        $response = $resource->toResponse(JsonApiRequest::create('/?include=posts.comments'))->getData(true);
        $comments = array_values(array_filter($response['included'], fn ($entry) => $entry['type'] === 'comments'));
        $this->assertSame(['published'], array_column(array_column($comments, 'attributes'), 'content'));
        $this->assertSame([[Post::class, 2, null, ['comments']]], $resource->calls);
    }

    public function testCollectionHookConstrainsLoadingBeforeLinkageAndAttributes(): void
    {
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            Comment::factory()->create(['user_id' => $user->getKey(), 'content' => 'public']);
            Comment::factory()->create(['user_id' => $user->getKey(), 'content' => 'private']);
        }

        $resources = new class($users, UserResource::class) extends AnonymousResourceCollection {
            public int $calls = 0;

            /**
             * Constrain comments for the whole collection before preparing resources.
             */
            #[Override]
            protected function loadMissingRequestedRelationships(Request $request): void
            {
                ++$this->calls;
                (new Collection($this->collection->pluck('resource')->all()))
                    ->loadMissing(['comments' => fn ($query) => $query->where('content', 'public')]);
                parent::loadMissingRequestedRelationships($request);
            }
        };

        DB::enableQueryLog();
        $response = $resources->toResponse(JsonApiRequest::create('/?include=comments'))->getData(true);

        $this->assertCount(2, $response['included']);
        $this->assertSame(['public', 'public'], array_column(array_column($response['included'], 'attributes'), 'content'));
        $this->assertCount(1, $response['data'][0]['relationships']['comments']['data']);
        $this->assertCount(1, $response['data'][1]['relationships']['comments']['data']);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(1, $resources->calls);
    }

    public function testSubclassCanLoadNestedRelationshipsWithoutInvokingUndeclaredMethods(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->getKey()]);
        Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $post->getKey()]);
        $resource = new class($user) extends UserResource {
            /**
             * Load requested relationships through the protected extension point.
             */
            public function loadRequestedRelationships(BaseCollection $models): void
            {
                $this->loadMissingRequestedRelationships(
                    new JsonApiRequest,
                    $models,
                    UserResource::class,
                    ['posts', 'posts.comments', 'posts.truncate', 'truncate'],
                );
            }
        };

        DB::enableQueryLog();
        $resource->loadRequestedRelationships(new Collection([$user]));

        $this->assertCount(2, DB::getQueryLog());
        $this->assertTrue($user->posts->first()->relationLoaded('comments'));
        $this->assertSame(1, Comment::count());
    }

    public function testCollectionPreservesResourceLoaderCallOrder(): void
    {
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            $post = Post::factory()->create(['user_id' => $user->getKey()]);
            Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $post->getKey()]);
        }

        $first = new class($users[0]) extends UserResource {
            public array $calls = [];

            /**
             * Record the receiver and arguments while retaining default loading.
             */
            #[Override]
            protected function loadMissingRequestedRelationships(
                JsonApiRequest $request,
                BaseCollection $relatedModels,
                ?string $resourceClass,
                array $requestedRelationships
            ): void {
                $this->calls[] = [$relatedModels->first()::class, $relatedModels->count(), $resourceClass, $requestedRelationships];

                parent::loadMissingRequestedRelationships($request, $relatedModels, $resourceClass, $requestedRelationships);
            }
        };
        $second = new ($first::class)($users[1]);
        $collection = new AnonymousResourceCollection(new BaseCollection([$first, $second]), $first::class);

        DB::enableQueryLog();
        $collection->toResponse(JsonApiRequest::create('/?include=posts.comments'));

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([
            [User::class, 2, $first::class, ['posts', 'posts.comments']],
            [Post::class, 2, null, ['comments']],
            [Post::class, 1, null, ['comments']],
        ], $first->calls);
        $this->assertSame([[Post::class, 1, null, ['comments']]], $second->calls);
    }
}

class ParentSpecificUserResource extends UserResource
{
    /**
     * Create a resource with its own comment constraint.
     */
    public function __construct(mixed $resource, private string $allowedContent)
    {
        parent::__construct($resource);
    }

    /**
     * Load comments using this parent's constraint.
     */
    #[Override]
    protected function loadMissingRequestedRelationships(
        JsonApiRequest $request,
        BaseCollection $relatedModels,
        ?string $resourceClass,
        array $requestedRelationships
    ): void {
        if ($relatedModels->first() instanceof Post) {
            (new Collection($relatedModels->all()))->loadMissing([
                'comments' => fn ($query) => $query->where('content', $this->allowedContent),
            ]);
        }
        parent::loadMissingRequestedRelationships($request, $relatedModels, $resourceClass, $requestedRelationships);
    }
}
