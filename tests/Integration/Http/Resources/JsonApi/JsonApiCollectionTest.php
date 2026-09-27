<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Comment;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Profile;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Team;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
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
        $now = $this->freezeSecond();

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
                            'membership' => [
                                'user_id' => $user->getKey(),
                                'team_id' => $team->getKey(),
                                'role' => 'Admin',
                                'created_at' => $now->toISOString(),
                                'updated_at' => $now->toISOString(),
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $team->getKey(),
                        'type' => 'teams',
                        'attributes' => [
                            'user_id' => $team->user_id,
                            'name' => 'Hypervel Team',
                            'personal_team' => true,
                            'membership' => [
                                'user_id' => $user->getKey(),
                                'team_id' => $team->getKey(),
                                'role' => 'Member',
                                'created_at' => $now->toISOString(),
                                'updated_at' => $now->toISOString(),
                            ],
                        ],
                    ],
                ]
            );
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
