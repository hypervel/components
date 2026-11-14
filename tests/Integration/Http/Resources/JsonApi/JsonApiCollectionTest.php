<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Comment;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\ObjectBackedJsonApiResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Profile;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Team;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserWithIdentifierMetaResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserWithLazyTeamAttributeResource;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\TestWith;

class JsonApiCollectionTest extends TestCase
{
    public function testItCanGenerateJsonApiResponse(): void
    {
        $users = User::factory()->times(5)->create();

        $this->getJson('/users')
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath(
                'data',
                $users->transform(fn ($user) => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ])->all()
            )->assertJsonMissingPath('jsonapi')->assertJsonMissingPath('included');
    }

    public function testItCanGenerateJsonApiResponseWithSparseFieldsets(): void
    {
        $users = User::factory()->times(5)->create();

        $this->getJson('/users/?' . http_build_query(['fields' => ['users' => 'name']]))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath(
                'data',
                $users->transform(fn ($user) => [
                    'id' => (string) $user->getKey(),
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                    ],
                ])->all()
            )->assertJsonMissingPath('jsonapi')->assertJsonMissingPath('included');
    }

    public function testItCanGenerateJsonApiResponseWithEmptyRelationshipsUsingSparseIncluded(): void
    {
        $users = User::factory()->times(5)->create();

        $this->getJson('/users/?' . http_build_query(['include' => 'posts']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath(
                'data',
                $users->transform(fn ($user) => [
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
                ])->all()
            )->assertJsonPath('included', [])->assertJsonMissingPath('jsonapi');
    }

    public function testItCanGenerateJsonApiResponseWithRelationshipsUsingSparseIncluded(): void
    {
        $users = User::factory()->times(4)->create();
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

        $this->expectsDatabaseQueryCount(5);

        $this->getJson('/users?' . http_build_query(['include' => 'profile,posts,teams']))
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertJsonPath(
                'data',
                [
                    ...$users->transform(fn ($user) => [
                        'id' => (string) $user->getKey(),
                        'type' => 'users',
                        'attributes' => [
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                        'relationships' => [
                            'profile' => ['data' => null],
                            'posts' => ['data' => []],
                            'teams' => ['data' => []],
                        ],
                    ])->all(),
                    [
                        'id' => (string) $user->getKey(),
                        'type' => 'users',
                        'attributes' => [
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                        'relationships' => [
                            'profile' => [
                                'data' => [
                                    'id' => (string) $profile->getKey(),
                                    'type' => 'profiles',
                                ],
                            ],
                            'posts' => [
                                'data' => [
                                    ['id' => (string) $posts[0]->getKey(), 'type' => 'posts'],
                                    ['id' => (string) $posts[1]->getKey(), 'type' => 'posts'],
                                ],
                            ],
                            'teams' => [
                                'data' => [
                                    ['id' => (string) $team->getKey(), 'type' => 'teams'],
                                ],
                            ],
                        ],
                    ],
                ]
            )->assertJsonPath(
                'included',
                [
                    [
                        'id' => (string) $profile->getKey(),
                        'type' => 'profiles',
                        'attributes' => [
                            'timezone' => 'America/Chicago',
                            'date_of_birth' => '2011-06-09',
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
                ]
            );
    }

    #[TestWith([1])]
    #[TestWith([0])]
    public function testItIncludesIdenticalBelongsToManyResourcesOnce(int $identifier): void
    {
        $users = User::factory()->times(2)->create();

        $team = Team::factory()->create([
            'id' => $identifier,
            'name' => 'Hypervel Team',
        ]);

        $users->each(fn ($user) => $user->teams()->attach($team, ['role' => 'Member']));

        $this->getJson('/users?' . http_build_query(['include' => 'teams', 'fields' => ['teams' => 'name']]))
            ->assertJsonPath('data.0.relationships.teams.data', [['id' => (string) $team->getKey(), 'type' => 'teams']])
            ->assertJsonPath('data.1.relationships.teams.data', [['id' => (string) $team->getKey(), 'type' => 'teams']])
            ->assertJsonPath('included', [
                [
                    'id' => (string) $team->getKey(),
                    'type' => 'teams',
                    'attributes' => [
                        'name' => 'Hypervel Team',
                    ],
                ],
            ]);
    }

    public function testItIncludesIdenticalBelongsToManyResourcesWithLazyAttributesOnce(): void
    {
        $users = User::factory()->times(2)->create();

        $team = Team::factory()->create([
            'name' => 'Hypervel Team',
        ]);

        $users->each(fn ($user) => $user->teams()->attach($team, ['role' => 'Member']));

        Route::get('users-with-lazy-team-attributes', function (): AnonymousResourceCollection {
            return UserWithLazyTeamAttributeResource::collection(User::all());
        });

        $this->getJson('/users-with-lazy-team-attributes?' . http_build_query(['include' => 'teams']))
            ->assertOk()
            ->assertJsonPath('included', [
                [
                    'id' => (string) $team->getKey(),
                    'type' => 'teams',
                    'attributes' => [
                        'name' => 'Hypervel Team',
                        'tags' => ['php'],
                    ],
                ],
            ]);
    }

    public function testIdentifierMetadataPreservesEachAssociationWhenIncludedResourcesMerge(): void
    {
        [$administrator, $member] = User::factory()->count(2)->create();
        $team = Team::factory()->create(['name' => 'Hypervel Team']);
        $administrator->teams()->attach($team, ['role' => 'Admin']);
        $member->teams()->attach($team, ['role' => 'Member']);

        $data = UserWithIdentifierMetaResource::collection([$administrator, $member])
            ->toResponse(JsonApiRequest::create('/?include=teams.users'))
            ->getData(true);

        $this->assertSame([
            ['id' => (string) $team->getKey(), 'type' => 'teams', 'meta' => ['role' => 'Admin']],
        ], $data['data'][0]['relationships']['teams']['data']);
        $this->assertSame([
            ['id' => (string) $team->getKey(), 'type' => 'teams', 'meta' => ['role' => 'Member']],
        ], $data['data'][1]['relationships']['teams']['data']);
        $this->assertSame([
            [
                'id' => (string) $team->getKey(),
                'type' => 'teams',
                'attributes' => ['name' => 'Hypervel Team'],
                'relationships' => ['users' => ['data' => [
                    ['id' => (string) $administrator->getKey(), 'type' => 'users', 'meta' => ['role' => 'Admin']],
                    ['id' => (string) $member->getKey(), 'type' => 'users', 'meta' => ['role' => 'Member']],
                ]]],
            ],
        ], $data['included']);
        $this->assertSame('Admin', $administrator->getRelation('teams')->first()->getRelation('membership')->role);
    }

    public function testRepeatedAssociationsWithIdentifierMetadataAreRejectedAfterConstrainedLoading(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $user->teams()->attach($team, ['role' => 'Admin']);
        $user->teams()->attach($team, ['role' => 'Member']);
        $user->load(['teams' => fn (BelongsToMany $query): BelongsToMany => $query->wherePivot('role', 'Admin')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Expose the associations as separate resources.');

        (new UserWithIdentifierMetaResource($user))->toResponse(JsonApiRequest::create('/?include=teams.users'));
    }

    public function testPreviouslyLoadedManyToManyCyclesRetainOriginalModelIdentity(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Hypervel Team']);
        $user->setRelation('teams', $user->newCollection([$team]));
        $team->setRelation('users', $team->newCollection([$user]));
        $request = JsonApiRequest::create('/');
        $resolutions = 0;
        $request->attributes->set('jsonapi.resolutionGuard', function () use (&$resolutions): void {
            $this->assertLessThan(3, ++$resolutions, 'The included-resource walk did not stop at a visited model.');
        });

        $data = (new UserWithIdentifierMetaResource($user))
            ->includePreviouslyLoadedRelationships()
            ->toResponse($request)
            ->getData(true);

        $this->assertSame([
            [
                'id' => (string) $team->getKey(),
                'type' => 'teams',
                'attributes' => ['name' => 'Hypervel Team'],
                'relationships' => ['users' => ['data' => [
                    ['id' => (string) $user->getKey(), 'type' => 'users'],
                ]]],
            ],
        ], $data['included']);
    }

    public function testItEagerLoadsIncludesForAllResourcesAtOnce(): void
    {
        $users = User::factory()->times(3)->create();

        foreach ($users as $user) {
            Profile::factory()->create(['user_id' => $user->getKey()]);
            Post::factory()->create(['user_id' => $user->getKey()]);
        }

        DB::enableQueryLog();

        $this->getJson('/posts?' . http_build_query(['include' => 'author.profile']))
            ->assertOk()
            ->assertJsonCount(6, 'included');

        // One query for the count, one for the posts, one for the authors and one for their profiles...
        $this->assertCount(4, DB::getQueryLog());
    }

    #[TestWith([ObjectBackedJsonApiResource::class])]
    #[TestWith([ObjectBackedResourceWithCustomRelationshipLoading::class])]
    public function testItCanGenerateJsonApiResponseForObjectBackedResources(string $resourceClass): void
    {
        Route::get('things', function () use ($resourceClass): AnonymousResourceCollection {
            return $resourceClass::collection([(object) ['id' => 99, 'name' => 'test']]);
        });

        $this->getJson('/things')
            ->assertHeader('Content-type', 'application/vnd.api+json')
            ->assertExactJson([
                'data' => [
                    [
                        'id' => '99',
                        'type' => 'things',
                        'attributes' => [
                            'name' => 'test',
                        ],
                    ],
                ],
            ]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNestedRelationshipsAreBatchedAcrossThePageWithoutReorderingIncludedResources(bool $includeProfile): void
    {
        $users = User::factory()->count(5)->create();
        $expectedIncluded = [];
        $profiles = [];

        foreach ($users as $user) {
            $posts = Post::factory()->count(2)->create(['user_id' => $user->getKey()]);
            $comments = $posts->map(fn (Post $post): Comment => Comment::factory()->create([
                'content' => 'public',
                'post_id' => $post->getKey(),
                'user_id' => $user->getKey(),
            ]));

            array_push(
                $expectedIncluded,
                ...$posts->map(fn (Post $post): string => 'posts:' . $post->getKey())->all(),
                ...$comments->map(fn (Comment $comment): string => 'comments:' . $comment->getKey())->all(),
            );

            if ($includeProfile) {
                $profile = Profile::factory()->create(['user_id' => $user->getKey()]);
                $profiles[] = ['id' => (string) $profile->getKey(), 'type' => 'profiles'];
                $expectedIncluded[] = 'profiles:' . $profile->getKey();
            }
        }

        $this->expectsDatabaseQueryCount($includeProfile ? 6 : 5);

        $response = $this->getJson('/users?include=posts.comments.commenter' . ($includeProfile ? '.profile' : ''))->assertOk();

        $this->assertSame($expectedIncluded, array_map(
            fn (array $resource): string => $resource['type'] . ':' . $resource['id'],
            $response->json('included'),
        ));

        if ($includeProfile) {
            $this->assertSame($profiles, array_map(
                fn (array $resource): array => $resource['relationships']['profile']['data'],
                $response->json('data'),
            ));
        }
    }

    public function testEmptyCollectionRetainsExplicitIncludes(): void
    {
        $data = UserResource::collection([])->toResponse(JsonApiRequest::create('/?include='))->getData(true);

        $this->assertSame(['data' => [], 'included' => []], $data);
        $this->assertSame(['data' => []], UserResource::collection([])->toResponse(JsonApiRequest::create('/'))->getData(true));
    }
}

class ObjectBackedResourceWithCustomRelationshipLoading extends ObjectBackedJsonApiResource
{
    /**
     * Load relationships through the resource's custom hook.
     */
    #[Override]
    protected function loadMissingRequestedRelationships(
        JsonApiRequest $request,
        Collection $relatedModels,
        ?string $resourceClass,
        array $requestedRelationships
    ): void {
        parent::loadMissingRequestedRelationships($request, $relatedModels, $resourceClass, $requestedRelationships);
    }
}
