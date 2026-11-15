<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Exceptions\ResourceClassNotFoundException;
use Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\ArrayBackedJsonApiResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Comment;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\PostResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Profile;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\ProfileResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Team;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class JsonApiResourceTest extends TestCase
{
    public function testItCanGenerateJsonApiResponse(): void
    {
        $user = User::factory()->create();

        $this->getJson("/users/{$user->getKey()}")
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi')
            ->assertJsonMissingPath('included');
    }

    public function testItCanGenerateJsonApiResponseWithSparseFieldsets(): void
    {
        $user = User::factory()->create();

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['fields' => ['users' => 'name']]))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi')
            ->assertJsonMissingPath('included');
    }

    public function testItCanGenerateJsonApiResponseWithEmptySparseFieldsets(): void
    {
        $user = User::factory()->create();

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['fields' => ['users' => '']]))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                ],
            ])
            ->assertJsonMissingPath('jsonapi')
            ->assertJsonMissingPath('included');
    }

    public function testItCanGenerateJsonApiResponseWithEmptyRelationshipsUsingSparseIncluded(): void
    {
        $user = User::factory()->create();

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'posts']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'relationships' => [
                        'posts' => [
                            'data' => [],
                        ],
                    ],
                ],
                'included' => [],
            ])
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItCanGenerateJsonApiResponseWithRelationshipsUsingSparseIncluded(): void
    {
        $user = User::factory()->create();

        $profile = Profile::factory()->create([
            'user_id' => $user->getKey(),
            'date_of_birth' => '2011-06-09',
            'timezone' => 'America/Chicago',
        ]);

        $team = Team::factory()->create([
            'name' => 'Hypervel Team',
        ]);

        $user->teams()->attach($team, ['role' => 'Admin']);
        $user->teams()->attach($team, ['role' => 'Member']);

        $posts = Post::factory()->times(2)->create([
            'user_id' => $user->getKey(),
        ]);

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'profile,posts,teams']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'relationships' => [
                        'posts' => [
                            'data' => [
                                ['id' => (string) $posts[0]->getKey(), 'type' => 'posts'],
                                ['id' => (string) $posts[1]->getKey(), 'type' => 'posts'],
                            ],
                        ],
                        'profile' => [
                            'data' => [
                                'id' => (string) $profile->getKey(),
                                'type' => 'profiles',
                            ],
                        ],
                        'teams' => [
                            'data' => [
                                ['id' => (string) $team->getKey(), 'type' => 'teams'],
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'id' => (string) $profile->getKey(),
                        'type' => 'profiles',
                        'attributes' => [
                            'date_of_birth' => '2011-06-09',
                            'timezone' => 'America/Chicago',
                        ],
                    ],
                    [
                        'id' => (string) $posts[0]->getKey(),
                        'type' => 'posts',
                        'attributes' => [
                            'title' => $posts[0]->title,
                            'content' => $posts[0]->content,
                        ],
                    ],
                    [
                        'id' => (string) $posts[1]->getKey(),
                        'type' => 'posts',
                        'attributes' => [
                            'title' => $posts[1]->title,
                            'content' => $posts[1]->content,
                        ],
                    ],
                    [
                        'id' => (string) $team->getKey(),
                        'type' => 'teams',
                        'attributes' => [
                            'user_id' => $team->user_id,
                            'name' => 'Hypervel Team',
                            'personal_team' => true,
                        ],
                    ],
                ],
            ]);
    }

    public function testItCanGenerateJsonApiResponseWithRelationshipsUsingSparseIncludedAndSparseFieldsets(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();

        [$post1, $post2] = Post::factory()->times(2)->create([
            'user_id' => $user->getKey(),
        ]);

        $this->getJson('/posts?' . http_build_query(['include' => 'author', 'fields' => ['authors' => 'name']]))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    [
                        'attributes' => [
                            'content' => $post1->content,
                            'title' => $post1->title,
                        ],
                        'type' => 'posts',
                        'id' => (string) $post1->getKey(),
                        'relationships' => [
                            'author' => [
                                'data' => [
                                    'id' => (string) $user->getKey(),
                                    'type' => 'authors',
                                ],
                            ],
                        ],
                    ],
                    [
                        'attributes' => [
                            'content' => $post2->content,
                            'title' => $post2->title,
                        ],
                        'type' => 'posts',
                        'id' => (string) $post2->getKey(),
                        'relationships' => [
                            'author' => [
                                'data' => [
                                    'id' => (string) $user->getKey(),
                                    'type' => 'authors',
                                ],
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'name' => $user->name,
                        ],
                        'id' => (string) $user->getKey(),
                        'type' => 'authors',
                    ],
                ],
                'links' => [
                    'first' => url('/posts?page=1'),
                    'last' => url('/posts?page=1'),
                    'next' => null,
                    'prev' => null,
                ],
                'meta' => [
                    'current_page' => 1,
                    'current_page_url' => url('/posts?page=1'),
                    'from' => 1,
                    'last_page' => 1,
                    'links' => [
                        ['active' => false, 'label' => '&laquo; Previous', 'page' => null, 'url' => null],
                        ['active' => true, 'label' => '1', 'page' => 1, 'url' => url('/posts?page=1')],
                        ['active' => false, 'label' => 'Next &raquo;', 'page' => null, 'url' => null],
                    ],
                    'path' => url('/posts'),
                    'per_page' => 5,
                    'to' => 2,
                    'total' => 2,
                ],
            ])
            ->assertJsonCount(1, 'included')
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItCanResolveRelationshipWithCustomNameAndResourceClass(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();

        $profile = Profile::factory()->create([
            'user_id' => $user->getKey(),
            'date_of_birth' => '2011-06-09',
            'timezone' => 'America/Chicago',
        ]);

        [$post1, $post2] = Post::factory()->times(2)->create([
            'user_id' => $user->getKey(),
        ]);

        $comment = Comment::factory()->create([
            'post_id' => $post1->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->getJson("/posts/{$post1->getKey()}?" . http_build_query(['include' => 'author']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'attributes' => [
                        'content' => $post1->content,
                        'title' => $post1->title,
                    ],
                    'type' => 'posts',
                    'id' => (string) $post1->getKey(),
                    'relationships' => [
                        'author' => [
                            'data' => [
                                'id' => (string) $user->getKey(),
                                'type' => 'authors',
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'email' => $user->email,
                            'name' => $user->name,
                        ],
                        'id' => (string) $user->getKey(),
                        'type' => 'authors',
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi');
    }

    public function testToOneRelationshipIdentifiersExposeMetadata(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->getKey()]);
        $resource = new class($user) extends UserResource {
            /**
             * Get the profile resource with identifier metadata.
             */
            #[Override]
            public function toRelationships(Request $request): array
            {
                return ['profile' => function (User $user): ProfileResource {
                    return new class($user->profile) extends ProfileResource {
                        /**
                         * Get the resource type.
                         */
                        #[Override]
                        public function toType(Request $request): string
                        {
                            return 'profiles';
                        }

                        /**
                         * Get metadata for the profile identifier.
                         */
                        #[Override]
                        public function toIdentifierMeta(Request $request): array
                        {
                            return ['primary' => true];
                        }
                    };
                }];
            }
        };

        $data = $resource->toResponse(JsonApiRequest::create('/?include=profile'))->getData(true);

        $this->assertSame([
            'id' => (string) $profile->getKey(),
            'type' => 'profiles',
            'meta' => ['primary' => true],
        ], $data['data']['relationships']['profile']['data']);
    }

    public function testDefaultRelatedResourcesDoNotReportMissingResourceClasses(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $user->teams()->attach($team);

        $data = $user->toResource()->toResponse(JsonApiRequest::create('/?include=teams'))->getData(true);

        $this->assertSame((string) $team->getKey(), $data['included'][0]['id']);
        Exceptions::assertNothingReported();
    }

    #[TestWith(['profile', false])]
    #[TestWith(['posts', true])]
    public function testRelationshipClosuresPreserveResourceConstructorState(string $relationName, bool $many): void
    {
        $user = User::factory()->create();
        $relatedResources = [];

        foreach ($many ? ['first', 'second'] : ['first'] as $label) {
            $relatedModel = $many
                ? Post::factory()->create(['user_id' => $user->getKey()])
                : Profile::factory()->create(['user_id' => $user->getKey()]);
            $relatedResources[] = new ConstructorStateJsonApiResource($relatedModel, $label);

            if ($many) {
                Comment::factory()->create(['user_id' => $user->getKey(), 'post_id' => $relatedModel->getKey()]);
            }
        }

        $resource = new class($user) extends UserResource {
            public array $relationships = [];

            public array $loadedModelClasses = [];

            /**
             * Record the models passed to the custom preload hook.
             */
            #[Override]
            protected function loadMissingRequestedRelationships(
                JsonApiRequest $request,
                Collection $relatedModels,
                ?string $resourceClass,
                array $requestedRelationships
            ): void {
                $this->loadedModelClasses[] = $relatedModels->map(fn ($model) => $model::class)->all();

                parent::loadMissingRequestedRelationships($request, $relatedModels, $resourceClass, $requestedRelationships);
            }
        };
        $resource->relationships = [
            $relationName => fn () => $many
                ? new AnonymousResourceCollection($relatedResources, ConstructorStateJsonApiResource::class)
                : $relatedResources[0],
        ];

        $data = $resource->toResponse(JsonApiRequest::create('/?include=' . $relationName . ($many ? '.comments' : '')))->getData(true);

        $this->assertSame($many ? ['first', 'second'] : ['first'], array_column(array_column($data['included'], 'attributes'), 'label'));

        if ($many) {
            $this->assertSame([[Post::class, Post::class]], $resource->loadedModelClasses);
            $this->assertCount(2, array_filter($data['included'], fn ($entry) => $entry['type'] === 'comments'));
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSharedModelsRetainEveryRequestedResourceOccurrence(bool $distinctResolvers): void
    {
        $root = User::factory()->create();
        $author = User::factory()->create();
        [$firstPost, $secondPost] = Post::factory()->count(2)->create(['user_id' => $author->getKey()]);
        $profile = Profile::factory()->create(['user_id' => $author->getKey()]);
        $root->setRelation('author', $author)->setRelation('editor', $author);
        $first = new RelationshipStateUserResource($author);
        $second = $distinctResolvers ? new RelationshipStateUserResource($author) : $first;

        if ($distinctResolvers) {
            $first->relationships = ['posts' => fn () => collect([$firstPost])];
            $second->relationships = ['posts' => fn () => collect([$secondPost])];
        } else {
            $first->relationships = ['posts', 'profile'];
        }

        $resource = new RelationshipStateUserResource($root);
        $resource->relationships = ['author' => fn () => $first, 'editor' => fn () => $second];
        $include = $distinctResolvers ? 'author.posts,editor.posts' : 'author.posts,editor.profile';
        $data = $resource->toResponse(JsonApiRequest::create('/?include=' . $include))->getData(true);
        $posts = array_filter($data['included'], fn ($entry) => $entry['type'] === 'posts');
        $includedAuthor = collect($data['included'])->firstWhere('type', 'users');

        $this->assertEqualsCanonicalizing([(string) $firstPost->getKey(), (string) $secondPost->getKey()], array_column($posts, 'id'));
        $this->assertCount(2, $includedAuthor['relationships']['posts']['data']);

        if (! $distinctResolvers) {
            $this->assertSame((string) $profile->getKey(), $includedAuthor['relationships']['profile']['data']['id']);
        }
    }

    public function testSharedResourcesTerminateForFiniteRequestedCycles(): void
    {
        [$first, $second] = User::factory()->count(2)->create();
        $first->setRelation('peer', $second);
        $second->setRelation('peer', $first);
        $firstResource = new class($first) extends RelationshipStateUserResource {
            /**
             * Fail a non-terminating preparation instead of hanging the test worker.
             */
            #[Override]
            protected function compileResourceRelationships(JsonApiRequest $request): void
            {
                $compilations = $request->attributes->getInt('jsonapi.compilations') + 1;
                $request->attributes->set('jsonapi.compilations', $compilations);

                if ($compilations > 50) {
                    throw new RuntimeException('Preparation did not terminate for a finite include.');
                }

                parent::compileResourceRelationships($request);
            }
        };
        $secondResource = clone $firstResource;
        $secondResource->resource = $second;
        $firstResource->relationships = ['peer' => fn () => $secondResource];
        $secondResource->relationships = ['peer' => fn () => $firstResource];

        $data = $firstResource->toResponse(JsonApiRequest::create('/?include=peer.peer.peer.peer'))->getData(true);

        $this->assertCount(1, $data['included']);
        $this->assertSame((string) $second->getKey(), $data['included'][0]['id']);
        $this->assertSame((string) $first->getKey(), $data['included'][0]['relationships']['peer']['data']['id']);
    }

    #[TestWith(['profile', false])]
    #[TestWith(['teams', false])]
    #[TestWith(['teams', true])]
    public function testArrayBackedRelationshipResourcesAvoidModelOperations(string $relation, bool $nested): void
    {
        $user = User::factory()->create();
        $user->setRelation($relation, $relation === 'teams' ? $user->newCollection() : null);
        $related = new ArrayBackedJsonApiResource(['id' => 99, 'name' => 'Example']);
        $resource = new class($user) extends RelationshipStateUserResource {
            /**
             * Reject a preload for backing values that are not models.
             */
            #[Override]
            protected function loadMissingRequestedRelationships(JsonApiRequest $request, Collection $relatedModels, ?string $resourceClass, array $requestedRelationships): void
            {
                throw new RuntimeException('Array-backed resources must not reach model preloading.');
            }
        };
        $resource->relationships = [$relation => fn () => $relation === 'teams' ? collect([$related]) : $related];

        $data = $resource->toResponse(JsonApiRequest::create('/?include=' . $relation . ($nested ? '.nested' : '')))->getData(true);

        $this->assertSame([
            ['id' => '99', 'type' => 'things', 'attributes' => ['name' => 'Example']],
        ], $data['included']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNestedDiscoveryFailuresPropagateFromRelatedResourceConstructors(bool $discovered): void
    {
        $user = User::factory()->create();
        $team = new RelatedResourceDiscoveryTeam;
        $team->setRawAttributes(['id' => 100, 'name' => 'Outer team']);
        $team->hasResourceMapping = true;
        $user->setRelation('teams', $user->newCollection([$team]));
        $resource = new RelationshipStateUserResource($user);
        $resource->relationships = ['teams' => $discovered ? null : NestedMissingRelatedResource::class];

        $this->expectException(ResourceClassNotFoundException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [' . RelatedResourceDiscoveryTeam::class . '].');

        $resource->toResponse(JsonApiRequest::create('/?include=teams'));
    }

    public function testRelatedResourceConstructionErrorsAreNotReplacedWithDefaultResources(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $user->teams()->attach($team);
        $resource = new class($user) extends UserResource {
            protected array $relationships = ['teams' => FailingRelatedJsonApiResource::class];
        };

        $this->expectExceptionObject(new RuntimeException('The related resource could not be constructed.'));

        $resource->toResponse(JsonApiRequest::create('/?include=teams'));
    }

    public function testItCanResolveRelationshipWithNestedRelationship(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();

        $profile = Profile::factory()->create([
            'user_id' => $user->getKey(),
            'date_of_birth' => '2011-06-09',
            'timezone' => 'America/Chicago',
        ]);

        [$post1, $post2] = Post::factory()->times(2)->create([
            'user_id' => $user->getKey(),
        ]);

        $comment = Comment::factory()->create([
            'post_id' => $post1->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->getJson("/posts/{$post1->getKey()}?" . http_build_query(['include' => 'author,comments.commenter']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'attributes' => [
                        'content' => $post1->content,
                        'title' => $post1->title,
                    ],
                    'type' => 'posts',
                    'id' => (string) $post1->getKey(),
                    'relationships' => [
                        'author' => [
                            'data' => [
                                'id' => (string) $user->getKey(),
                                'type' => 'authors',
                            ],
                        ],
                        'comments' => [
                            'data' => [
                                ['id' => (string) $comment->getKey(), 'type' => 'comments'],
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'email' => $user->email,
                            'name' => $user->name,
                        ],
                        'id' => (string) $user->getKey(),
                        'type' => 'authors',
                    ],
                    [
                        'attributes' => [
                            'content' => $comment->content,
                        ],
                        'id' => (string) $comment->getKey(),
                        'type' => 'comments',
                        'relationships' => [
                            'commenter' => [
                                'data' => [
                                    'id' => (string) $user->getKey(),
                                    'type' => 'users',
                                ],
                            ],
                        ],
                    ],
                    [
                        'attributes' => [
                            'email' => $user->email,
                            'name' => $user->name,
                        ],
                        'id' => (string) $user->getKey(),
                        'type' => 'users',
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItCanResolveNestedRelationshipThroughClosureReturningResourceCollection(): void
    {
        $user = User::factory()->create();

        $publicPost = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        $privatePost = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        $publicComment = Comment::factory()->create([
            'content' => 'public',
            'post_id' => $publicPost->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $privateComment = Comment::factory()->create([
            'content' => 'private',
            'post_id' => $privatePost->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $response = $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'posts.comments']))
            ->assertJsonPath('data.relationships.posts.data.0.id', (string) $publicPost->getKey())
            ->assertJsonPath('included.0.relationships.comments.data.0.id', (string) $publicComment->getKey());

        $this->assertFalse(collect($response->json('included'))->contains(
            fn ($included) => $included['type'] === 'comments' && $included['id'] === (string) $privateComment->getKey()
        ));
    }

    public function testItIgnoresNestedIncludesThatAreNotResourceRelationships(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Comment::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        Comment::factory()->create([
            'user_id' => $otherUser->getKey(),
        ]);

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'comments.truncate']))
            ->assertOk()
            ->assertJsonCount(1, 'included');

        $this->assertSame(2, Comment::count());
    }

    public function testItIgnoresDeeplyNestedIncludesThatAreNotResourceRelationships(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        Comment::factory()->create([
            'post_id' => $post->getKey(),
            'user_id' => $user->getKey(),
        ]);

        Comment::factory()->create();

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'posts.comments.truncate']))
            ->assertOk()
            ->assertJsonCount(2, 'included');

        $this->assertSame(2, Comment::count());
    }

    public function testItEagerLoadsNestedIncludesForAllRelatedModelsAtOnce(): void
    {
        $user = User::factory()->create();

        $posts = Post::factory()->count(3)->create([
            'user_id' => $user->getKey(),
        ]);

        foreach ($posts as $post) {
            Comment::factory()->create([
                'post_id' => $post->getKey(),
                'user_id' => $user->getKey(),
            ]);
        }

        DB::enableQueryLog();

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'posts.comments']))
            ->assertOk()
            ->assertJsonCount(6, 'included');

        // One query for the user, one for the posts and one for the comments of all posts...
        $this->assertCount(3, DB::getQueryLog());
    }

    #[TestWith(['posts.comments', 3])]
    #[TestWith(['posts.comments.commenter', 4])]
    public function testItResolvesEachRelationshipClosureOnceWhenIncludingNestedRelationships(string $include, int $queryCount): void
    {
        $user = User::factory()->create();

        $posts = Post::factory()->count(2)->create([
            'user_id' => $user->getKey(),
        ]);

        foreach ($posts as $post) {
            Comment::factory()->create([
                'content' => 'public',
                'post_id' => $post->getKey(),
                'user_id' => $user->getKey(),
            ]);
        }

        PostResource::$commentsResolutionCount = 0;

        $this->expectsDatabaseQueryCount($queryCount);

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => $include]))
            ->assertJsonPath('data.relationships.posts.data.0.id', (string) $posts[0]->getKey());

        // The "comments" closure should be resolved once per included post, not multiple times...
        $this->assertSame(2, PostResource::$commentsResolutionCount);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRequestedRelationshipsAreAvailableToAttributeCallbacks(bool $collection): void
    {
        $user = User::factory()->create();
        Post::factory()->count(2)->create(['user_id' => $user->getKey()]);

        $resource = new class($user) extends UserResource {
            /**
             * Include the requested post count without loading it from the callback.
             */
            #[Override]
            public function toAttributes(Request $request): array
            {
                return [
                    ...parent::toAttributes($request),
                    'post_count' => $this->whenLoaded('posts', fn (): int => $this->posts->count()),
                ];
            }
        };

        $response = ($collection ? $resource::collection([$user]) : $resource)
            ->toResponse(JsonApiRequest::create('/?include=posts'))
            ->getData(true);

        $data = $collection ? $response['data'][0] : $response['data'];

        $this->assertSame(2, $data['attributes']['post_count']);
        $this->assertCount(2, $data['relationships']['posts']['data']);
    }

    public function testItCanResolveRelationshipWithRecursiveNestedRelationship(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();

        $profile = Profile::factory()->create([
            'user_id' => $user->getKey(),
            'date_of_birth' => '2011-06-09',
            'timezone' => 'America/Chicago',
        ]);

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'profile.user.profile']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'attributes' => [
                        'email' => $user->email,
                        'name' => $user->name,
                    ],
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'relationships' => [
                        'profile' => [
                            'data' => ['id' => (string) $profile->getKey(), 'type' => 'profiles'],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'date_of_birth' => '2011-06-09',
                            'timezone' => 'America/Chicago',
                        ],
                        'id' => (string) $profile->getKey(),
                        'type' => 'profiles',
                        'relationships' => [
                            'user' => [
                                'data' => ['id' => (string) $user->getKey(), 'type' => 'users'],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItCanResolveRelationshipWithRecursiveNestedRelationshipLimitedToDepthConfiguration(): void
    {
        JsonApiResource::maxRelationshipDepth(2);

        $now = $this->freezeSecond();

        $user = User::factory()->create();

        $profile = Profile::factory()->create([
            'user_id' => $user->getKey(),
            'date_of_birth' => '2011-06-09',
            'timezone' => 'America/Chicago',
        ]);

        $this->getJson("/users/{$user->getKey()}?" . http_build_query(['include' => 'profile.user.profile']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'attributes' => [
                        'email' => $user->email,
                        'name' => $user->name,
                    ],
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'relationships' => [
                        'profile' => [
                            'data' => ['id' => (string) $profile->getKey(), 'type' => 'profiles'],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'date_of_birth' => '2011-06-09',
                            'timezone' => 'America/Chicago',
                        ],
                        'id' => (string) $profile->getKey(),
                        'type' => 'profiles',
                        'relationships' => [
                            'user' => [
                                'data' => ['id' => (string) $user->getKey(), 'type' => 'users'],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItCanResolveRelationshipWithoutRedundantIncludedRelationship(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();

        [$post1, $post2] = Post::factory()->times(2)->create([
            'user_id' => $user->getKey(),
        ]);

        $this->getJson('/posts?' . http_build_query(['include' => 'author']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    [
                        'attributes' => [
                            'content' => $post1->content,
                            'title' => $post1->title,
                        ],
                        'type' => 'posts',
                        'id' => (string) $post1->getKey(),
                        'relationships' => [
                            'author' => [
                                'data' => [
                                    'id' => (string) $user->getKey(),
                                    'type' => 'authors',
                                ],
                            ],
                        ],
                    ],
                    [
                        'attributes' => [
                            'content' => $post2->content,
                            'title' => $post2->title,
                        ],
                        'type' => 'posts',
                        'id' => (string) $post2->getKey(),
                        'relationships' => [
                            'author' => [
                                'data' => [
                                    'id' => (string) $user->getKey(),
                                    'type' => 'authors',
                                ],
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'attributes' => [
                            'email' => $user->email,
                            'name' => $user->name,
                        ],
                        'id' => (string) $user->getKey(),
                        'type' => 'authors',
                    ],
                ],
                'links' => [
                    'first' => url('/posts?page=1'),
                    'last' => url('/posts?page=1'),
                    'next' => null,
                    'prev' => null,
                ],
                'meta' => [
                    'current_page' => 1,
                    'current_page_url' => url('/posts?page=1'),
                    'from' => 1,
                    'last_page' => 1,
                    'links' => [
                        ['active' => false, 'label' => '&laquo; Previous', 'page' => null, 'url' => null],
                        ['active' => true, 'label' => '1', 'page' => 1, 'url' => url('/posts?page=1')],
                        ['active' => false, 'label' => 'Next &raquo;', 'page' => null, 'url' => null],
                    ],
                    'path' => url('/posts'),
                    'per_page' => 5,
                    'to' => 2,
                    'total' => 2,
                ],
            ])
            ->assertJsonCount(1, 'included')
            ->assertJsonMissingPath('jsonapi');
    }

    public function testItHandlesBidirectionalRelationshipsWithChaperoneWithoutInfiniteLoop(): void
    {
        $user = User::factory()->create();

        $post = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        // The /with-chaperone-posts route loads chaperonePosts which uses chaperone()
        // to automatically set the inverse 'author' relationship on each Post,
        // creating circular object references (User -> Post -> User same instance).
        // Without the fix, this would hang forever due to infinite loop.
        // The same User model appears twice in included: once as "posts" (the Post)
        // and once as "authors" (Post's author via chaperone) - this is correct
        // because different resource types should not be deduplicated.
        $this->getJson("/users/{$user->getKey()}/with-chaperone-posts?" . http_build_query(['include' => 'chaperonePosts']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath('data.id', (string) $user->getKey())
            ->assertJsonPath('data.type', 'users')
            ->assertJsonPath('data.relationships.chaperonePosts.data.0.type', 'posts')
            ->assertJsonPath('data.relationships.chaperonePosts.data.0.id', (string) $post->getKey())
            ->assertJsonPath('included.0.type', 'posts')
            ->assertJsonPath('included.1.type', 'authors')
            ->assertJsonCount(2, 'included');
    }

    public function testIncludedResourcesCanBeArrayBackedCustomResources(): void
    {
        $user = User::factory()->create();

        $this->getJson("/users/{$user->getKey()}/with-array-relationship")
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath('data.id', (string) $user->getKey())
            ->assertJsonPath('data.type', 'users')
            ->assertJsonPath('included.0.id', '99')
            ->assertJsonPath('included.0.type', 'things')
            ->assertJsonPath('included.0.attributes.name', 'test')
            ->assertJsonCount(1, 'included');
    }

    public function testTopLevelArrayBackedCustomResourceCanGenerateJsonApiResponse(): void
    {
        $this->getJson('/things/99')
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => '99',
                    'type' => 'things',
                    'attributes' => [
                        'name' => 'test',
                    ],
                ],
            ])
            ->assertJsonMissingPath('jsonapi')
            ->assertJsonMissingPath('included');
    }

    public function testAttributesCanBeConditionallyMerged(): void
    {
        Route::get('things-with-merged-attributes/{id}', function (string $id) {
            return new class(['id' => (int) $id, 'name' => 'test', 'secret' => 'hidden', 'notes' => 'internal']) extends ArrayBackedJsonApiResource {
                /**
                 * Transform the resource into attributes.
                 */
                #[Override]
                public function toAttributes(Request $request): array
                {
                    return [
                        'name' => $this->resource['name'],
                        $this->mergeWhen(false, ['secret' => $this->resource['secret']]),
                        $this->mergeWhen(true, ['notes' => $this->resource['notes']]),
                    ];
                }
            };
        });

        $this->getJson('/things-with-merged-attributes/99')
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    'id' => '99',
                    'type' => 'things',
                    'attributes' => [
                        'name' => 'test',
                        'notes' => 'internal',
                    ],
                ],
            ]);
    }

    public function testSameModelWithTheSameResourceTypeIsDeduplicated(): void
    {
        $user = User::factory()->create();

        $post1 = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        $post2 = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        Comment::factory()->create([
            'post_id' => $post1->getKey(),
            'user_id' => $user->getKey(),
        ]);

        Comment::factory()->create([
            'post_id' => $post2->getKey(),
            'user_id' => $user->getKey(),
        ]);

        // Both comments have the same commenter (User). Per the JSON:API spec, the same
        // type+id pair should only appear once in included, even when referenced by
        // multiple relationships. We expect 3 items: 2 comments + 1 user (deduped).
        $response = $this->getJson('/posts?' . http_build_query(['include' => 'comments.commenter']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonCount(3, 'included');

        $included = $response->json('included');
        $types = array_column($included, 'type');

        $this->assertCount(2, array_filter($types, fn (string $t) => $t === 'comments'));
        $this->assertCount(1, array_filter($types, fn (string $t) => $t === 'users'));
    }

    public function testDifferentModelInstancesWithSameTypeAndIdAreDeduplicated(): void
    {
        $root = User::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->getKey()]);
        $profile = Profile::factory()->create(['user_id' => $user->getKey()]);

        // This route manually creates two different User model instances with the same ID and
        // adds them both to the loadedRelationshipsMap. Per the JSON:API spec, they should
        // be deduplicated since they have the same type+id, even though they're different object instances.
        $response = $this->getJson("/users/{$root->getKey()}/with-duplicate-instances/{$user->getKey()}")
            ->assertHeader('Content-type', 'application/vnd.api+json');

        $included = $response->json('included');

        $this->assertSame(['users', 'posts', 'profiles'], array_column($included, 'type'));
        $this->assertSame((string) $user->getKey(), $included[0]['id']);
        $this->assertSame(['id' => (string) $post->getKey(), 'type' => 'posts'], $included[0]['relationships']['posts']['data'][0]);
        $this->assertSame(['id' => (string) $profile->getKey(), 'type' => 'profiles'], $included[0]['relationships']['profile']['data']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPrimaryDuplicatesPreserveNestedRelationshipLinkage(bool $alreadyLoaded): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->getKey()]);
        $comment = Comment::factory()->create([
            'post_id' => $post->getKey(), 'user_id' => $user->getKey(), 'content' => 'public',
        ]);
        $profile = Profile::factory()->create(['user_id' => $user->getKey()]);

        if ($alreadyLoaded) {
            $user->load('posts.comments.commenter.profile');
        }

        $user->load(['profile' => fn (HasOne $query): HasOne => $query->whereRaw('1 = 0')]);

        $this->expectsDatabaseQueryCount($alreadyLoaded ? 0 : 4);
        $request = JsonApiRequest::create('/?include=profile,' . ($alreadyLoaded ? 'posts.comments' : 'posts.comments.commenter.profile'));
        $data = $user->toResource()->toResponse($request)->getData(true);

        $this->assertSame(['id' => (string) $profile->getKey(), 'type' => 'profiles'], $data['data']['relationships']['profile']['data']);
        $this->assertSame(['posts', 'comments', 'profiles'], array_column($data['included'], 'type'));
        $this->assertSame((string) $comment->getKey(), $data['included'][1]['id']);
        $this->assertSame((string) $profile->getKey(), $data['included'][2]['id']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPrimaryDuplicatesCombineConstrainedRelationshipLinkage(bool $empty): void
    {
        $user = User::factory()->create();
        $keep = Post::factory()->create(['user_id' => $user->getKey(), 'title' => 'keep']);
        $other = Post::factory()->create(['user_id' => $user->getKey(), 'title' => 'other']);
        Comment::factory()->create([
            'post_id' => $keep->getKey(), 'user_id' => $user->getKey(), 'content' => 'public',
        ]);
        $user->load(['posts' => fn (HasMany $query): HasMany => $query->where('title', $empty ? 'absent' : 'keep')]);

        $data = $user->toResource()->toResponse(JsonApiRequest::create('/?include=posts,comments.commenter.posts'))->getData(true);

        $this->assertSame([
            ['id' => (string) $keep->getKey(), 'type' => 'posts'],
            ['id' => (string) $other->getKey(), 'type' => 'posts'],
        ], $data['data']['relationships']['posts']['data']);
        $this->assertNotContains('users', array_column($data['included'], 'type'));
        $this->assertCount(3, $data['included']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testExplicitEmptyIncludesAreRetained(bool $collection): void
    {
        $user = User::factory()->create();
        $uri = $collection ? '/users' : '/users/' . $user->getKey();

        $this->getJson($uri . '?include=')->assertOk()->assertJsonPath('included', []);
    }

    public function testSameModelOnDifferentResourcesIsNotDeduplicated(): void
    {
        $user = User::factory()->create();

        $post = Post::factory()->create([
            'user_id' => $user->getKey(),
        ]);

        // Post's author uses AuthorResource ("authors"), root uses UserResource ("users").
        // We don't want to deduplicate them, as even though the underlying model is the
        // same, they are different resource types, so they have different identity.
        $this->getJson("/users/{$user->getKey()}/with-chaperone-posts?" . http_build_query(['include' => 'chaperonePosts.author']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath('data.id', (string) $user->getKey())
            ->assertJsonPath('data.type', 'users')
            ->assertJsonPath('included.0.type', 'posts')
            ->assertJsonPath('included.0.id', (string) $post->getKey())
            ->assertJsonPath('included.1.type', 'authors')
            ->assertJsonPath('included.1.id', (string) $user->getKey())
            ->assertJsonCount(2, 'included');
    }
}

class RelationshipStateUserResource extends UserResource
{
    public array $relationships = [];

    /**
     * Keep the user identity while customizing its relationship resolvers.
     */
    #[Override]
    public function toType(Request $request): string
    {
        return 'users';
    }
}

class RelatedResourceDiscoveryTeam extends Team
{
    public bool $hasResourceMapping = false;

    /**
     * Select a resource based on this model's presentation state.
     */
    #[Override]
    protected function resolveResourceFromAttribute(string $class): ?string
    {
        return $this->hasResourceMapping ? NestedMissingRelatedResource::class : null;
    }
}

class NestedMissingRelatedResource extends JsonApiResource
{
    /**
     * Attempt a nested lookup for an unmapped instance of the same model class.
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        (new RelatedResourceDiscoveryTeam)->toResource();
    }
}

class FailingRelatedJsonApiResource extends JsonApiResource
{
    /**
     * Fail while constructing the configured resource.
     */
    public function __construct(mixed $resource)
    {
        throw new RuntimeException('The related resource could not be constructed.');
    }
}

class ConstructorStateJsonApiResource extends JsonApiResource
{
    protected array $relationships = ['comments'];

    /**
     * Create a resource with caller-supplied presentation state.
     */
    public function __construct(mixed $resource, private string $label)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource attributes.
     */
    #[Override]
    public function toAttributes(Request $request): array
    {
        return ['label' => $this->label];
    }
}
