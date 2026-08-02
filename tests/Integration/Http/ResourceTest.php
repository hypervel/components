<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Http\Exceptions\PostTooLargeException;
use Hypervel\Http\Middleware\ValidatePostSize;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\ConditionallyLoadsAttributes;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Http\Resources\JsonApi\AnonymousResourceCollection;
use Hypervel\Http\Resources\MergeValue;
use Hypervel\Http\Resources\MissingValue;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Http\Fixtures\Author;
use Hypervel\Tests\Integration\Http\Fixtures\AuthorResourceWithOptionalRelationship;
use Hypervel\Tests\Integration\Http\Fixtures\EmptyPostCollectionResource;
use Hypervel\Tests\Integration\Http\Fixtures\ObjectResource;
use Hypervel\Tests\Integration\Http\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Fixtures\PostCollectionResource;
use Hypervel\Tests\Integration\Http\Fixtures\PostCollectionResourceWithPaginationInformation;
use Hypervel\Tests\Integration\Http\Fixtures\PostModelCollectionResource;
use Hypervel\Tests\Integration\Http\Fixtures\PostResource;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithAnonymousResourceCollectionWithPaginationInformation;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithExtraData;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithJsonOptions;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithJsonOptionsAndTypeHints;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalAppendedAttributes;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalAttributes;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalData;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalHasAttributes;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalMerging;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalPivotRelationship;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalRelationship;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalRelationshipAggregates;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalRelationshipCounts;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalRelationshipExists;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithOptionalRelationshipUsingNamedParameters;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithoutWrap;
use Hypervel\Tests\Integration\Http\Fixtures\PostResourceWithUnlessOptionalData;
use Hypervel\Tests\Integration\Http\Fixtures\ReallyEmptyPostResource;
use Hypervel\Tests\Integration\Http\Fixtures\ResourceWithPreservedKeys;
use Hypervel\Tests\Integration\Http\Fixtures\SerializablePostResource;
use Hypervel\Tests\Integration\Http\Fixtures\Subscription;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class ResourceTest extends TestCase
{
    public function testResourceMayBeConvertedToArray(): void
    {
        $resource = new class((new User)->forceFill(['id' => 1, 'name' => 'Taylor Otwell'])) extends JsonResource {
            public function toArray(Request $request): array
            {
                return [
                    'id' => $this->id,
                    'name' => $this->name,
                    'posts' => (new AnonymousResourceCollection([
                        new Post([
                            'id' => 5,
                            'title' => 'Test Title',
                            'abstract' => 'Test abstract',
                        ]),
                        new Post([
                            'id' => 10,
                            'title' => 'Another Test Title',
                            'abstract' => 'Another Test abstract',
                        ]),
                    ], PostResource::class)),
                ];
            }
        };

        $request = Request::create('GET', '/users');

        tap($resource->toArray($request), function ($userAsArray) use ($request) {
            $this->assertSame(1, $userAsArray['id']);
            $this->assertSame('Taylor Otwell', $userAsArray['name']);

            $this->assertInstanceOf(AnonymousResourceCollection::class, $userAsArray['posts']);
            $this->assertSame(PostResource::class, $userAsArray['posts']->collects);

            tap($userAsArray['posts']->toArray($request), function ($postsAsArray) {
                $this->assertIsArray($postsAsArray);
                $this->assertCount(2, $postsAsArray);
                $this->assertSame(['id' => 5, 'title' => 'Test Title', 'custom' => true], $postsAsArray[0]);
                $this->assertSame(['id' => 10, 'title' => 'Another Test Title', 'custom' => true], $postsAsArray[1]);
            });
        });
    }

    public function testResourcesMayBeConvertedToJson(): void
    {
        Route::get('/', function () {
            return new PostResource(new Post([
                'id' => 5,
                'title' => 'Test Title',
                'abstract' => 'Test abstract',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
            ],
        ]);
    }

    public function testResourcesMayBeConvertedToJsonWithToJsonMethod(): void
    {
        $resource = new PostResource(new Post([
            'id' => 5,
            'title' => 'Test Title',
            'abstract' => 'Test abstract',
        ]));

        $this->assertSame('{"id":5,"title":"Test Title","custom":true}', $resource->toJson());
    }

    public function testAnObjectsMayBeConvertedToJson(): void
    {
        Route::get('/', function () {
            return ObjectResource::make(
                (object) ['first_name' => 'Bob', 'age' => 40]
            );
        });

        $this->withoutExceptionHandling()
            ->get('/', ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertExactJson([
                'data' => [
                    'name' => 'Bob',
                    'age' => 40,
                ],
            ]);
    }

    public function testArraysWithObjectsMayBeConvertedToJson(): void
    {
        Route::get('/', function () {
            $objects = [
                (object) ['first_name' => 'Bob', 'age' => 40],
                (object) ['first_name' => 'Jack', 'age' => 25],
            ];

            return ObjectResource::collection($objects);
        });

        $this->withoutExceptionHandling()
            ->get('/', ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertExactJson([
                'data' => [
                    ['name' => 'Bob', 'age' => 40],
                    ['name' => 'Jack', 'age' => 25],
                ],
            ]);
    }

    public function testResourcesMayHaveNoWrap(): void
    {
        Route::get('/', function () {
            return new PostResourceWithoutWrap(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertJson([
            'id' => 5,
            'title' => 'Test Title',
        ]);
    }

    public function testResourcesMayHaveOptionalValues(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalData(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'second' => 'value',
                'third' => 'value',
                'fourth' => 'default',
                'fifth' => 'default',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalValuesUsingUnless(): void
    {
        Route::get('/', function () {
            return new PostResourceWithUnlessOptionalData(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'first' => 'value',
                'fourth' => 'value',
                'fifth' => 'value',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalSelectedAttributes(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalAttributes(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'title' => 'no title',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalHasAttributes(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'is_published' => true,
            ]);

            return new PostResourceWithOptionalHasAttributes($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'first' => true,
                'second' => 'override value',
                'third' => 'override value',
                'fourth' => true,
                'fifth' => true,
            ],
        ]);
    }

    public function testResourcesWithOptionalHasAttributesReturnDefaultValuesAndNotMissingValues(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalHasAttributes(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'fourth' => 'default',
                'fifth' => 'default',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalAppendedAttributes(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
            ]);

            $post->append('is_published');

            return new PostResourceWithOptionalAppendedAttributes($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'first' => true,
                'second' => 'override value',
                'third' => 'override value',
                'fourth' => true,
                'fifth' => true,
            ],
        ]);
    }

    public function testResourcesWithOptionalAppendedAttributesReturnDefaultValuesAndNotMissingValues(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalAppendedAttributes(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'fourth' => 'default',
                'fifth' => 'default',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalMerges(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalMerging(new Post([
                'id' => 5,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'second' => 'value',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalRelationships(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalRelationship(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalRelationshipCounts(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]);

            return new PostResourceWithOptionalRelationshipCounts($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'comments' => 'None',
            ],
        ]);
    }

    public function testResourcesMayLoadOptionalRelationshipCounts(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
                'authors_count' => 2,
                'comments_count' => 5,
                'favourited_posts_count' => 1,
            ]);

            return new PostResourceWithOptionalRelationshipCounts($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'authors' => 2,
                'favourite_posts' => 1,
                'comments' => '5 comments',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalRelationshipExists(): void
    {
        Route::get('/', function () {
            return new PostResourceWithOptionalRelationshipExists(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'has_favourited_posts' => 'No',
            ],
        ]);
    }

    public function testResourcesMayLoadOptionalRelationshipExists(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
                'authors_exists' => true,
                'favourited_posts_exists' => true,
                'comments_exists' => false,
            ]);

            return new PostResourceWithOptionalRelationshipExists($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'has_authors' => true,
                'has_favourited_posts' => 'Yes',
                'comment_exists' => false,
            ],
        ]);
    }

    public function testResourcesMayLoadOptionalRelationships(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]);

            $post->setRelation('author', new Author(['name' => 'jrrmartin']));

            return new PostResourceWithOptionalRelationship($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'author' => ['name' => 'jrrmartin'],
                'author_name' => 'jrrmartin',
            ],
        ]);
    }

    public function testResourcesMayLoadOptionalRelationshipAggregates(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
                'comments_avg_rating' => 3.8,
                'comments_min_rating' => 2,
                'comments_max_rating' => 5,
            ]);

            return new PostResourceWithOptionalRelationshipAggregates($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
                'average_rating' => 3.8,
                'minimum_rating' => 2,
                'maximum_rating' => '5 ratings',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalRelationshipAggregates(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]);

            return new PostResourceWithOptionalRelationshipAggregates($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
                'maximum_rating' => 'Default Value',
            ],
        ]);
    }

    public function testResourcesMayShowNullForLoadedRelationshipWithValueNull(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]);

            $post->setRelation('author', null);

            return new PostResourceWithOptionalRelationship($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'author' => null,
                'author_name' => null,
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalRelationshipsWithDefaultValues(): void
    {
        Route::get('/', function () {
            return new AuthorResourceWithOptionalRelationship(new Author([
                'name' => 'jrrmartin',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'name' => 'jrrmartin',
                'posts_count' => 'not loaded',
                'latest_post_title' => 'not loaded',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalPivotRelationships(): void
    {
        Route::get('/', function () {
            $post = new Post(['id' => 5]);
            $post->setRelation('pivot', new Subscription);

            return new PostResourceWithOptionalPivotRelationship($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'subscription' => [
                    'foo' => 'bar',
                ],
            ],
        ]);
    }

    public function testResourceDoesNotThrowErrorWhenUsingEloquentStrictModeAndCheckingOptionalPivotRelationship(): void
    {
        Model::shouldBeStrict(true);

        Route::get('/', function () {
            $post = new Post(['id' => 5]);
            (function () {
                $this->exists = true;
                $this->wasRecentlyCreated = false;
            })->bindTo($post)();

            return new PostResourceWithOptionalPivotRelationship($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
            ],
        ]);
    }

    public function testWhenLoadedUsingNamedDefaultParameterOnMissingRelation(): void
    {
        Route::get('/', function () {
            $post = new Post(['id' => 1]);

            return new PostResourceWithOptionalRelationshipUsingNamedParameters($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 1,
                'author_defaulting_to_null' => null,
                'author_name' => 'Anonymous',
            ],
        ]);
    }

    public function testWhenLoadedUsingNamedDefaultParameterOnLoadedRelation(): void
    {
        Route::get('/', function () {
            $post = new Post(['id' => 1]);
            $post->setRelation('author', new Author(['name' => 'jrrmartin']));

            return new PostResourceWithOptionalRelationshipUsingNamedParameters($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 1,
                'author' => ['name' => 'jrrmartin'],
                'author_defaulting_to_null' => ['name' => 'jrrmartin'],
                'author_name' => 'jrrmartin',
            ],
        ]);
    }

    public function testResourcesMayHaveOptionalPivotRelationshipsWithCustomAccessor(): void
    {
        Route::get('/', function () {
            $post = new Post(['id' => 5]);
            $post->setRelation('accessor', new Subscription);

            return new PostResourceWithOptionalPivotRelationship($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertExactJson([
            'data' => [
                'id' => 5,
                'custom_subscription' => [
                    'foo' => 'bar',
                ],
            ],
        ]);
    }

    public function testResourceIsUrlRoutable(): void
    {
        $post = new PostResource(new Post([
            'id' => 5,
            'title' => 'Test Title',
        ]));

        $this->assertSame('http://localhost/post/5', url('/post', $post));
    }

    public function testNamedRoutesAreUrlRoutable(): void
    {
        $post = new PostResource(new Post([
            'id' => 5,
            'title' => 'Test Title',
        ]));

        Route::get('/post/{id}', function () use ($post) {
            return route('post.show', $post);
        })->name('post.show');

        $response = $this->withoutExceptionHandling()->get('/post/1');

        $this->assertSame('http://localhost/post/5', $response->original);
    }

    public function testResourcesMayBeSerializable(): void
    {
        Route::get('/', function () {
            return new SerializablePostResource(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
            ],
        ]);
    }

    public function testResourcesMayCustomizeResponses(): void
    {
        Route::get('/', function () {
            return new PostResource(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);
        $response->assertHeader('X-Resource', 'True');
    }

    public function testResourcesMayCustomizeExtraData(): void
    {
        Route::get('/', function () {
            return new PostResourceWithExtraData(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
            ],
            'foo' => 'bar',
        ]);
    }

    public function testResourcesMayCustomizeExtraDataWhenBuildingResponse(): void
    {
        Route::get('/', function () {
            return (new PostResourceWithExtraData(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ])))->additional(['baz' => 'qux']);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
            ],
            'foo' => 'bar',
            'baz' => 'qux',
        ]);
    }

    public function testResourcesMayCustomizeJsonOptions(): void
    {
        Route::get('/', function () {
            return new PostResourceWithJsonOptions(new Post([
                'id' => 5,
                'title' => 'Test Title',
                'reading_time' => 3.0,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $this->assertSame(
            '{"data":{"id":5,"title":"Test Title","reading_time":3.0}}',
            $response->baseResponse->content()
        );
    }

    public function testCollectionResourcesMayCustomizeJsonOptions(): void
    {
        Route::get('/', function () {
            return PostResourceWithJsonOptions::collection(collect([
                new Post(['id' => 5, 'title' => 'Test Title', 'reading_time' => 3.0]),
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $this->assertSame(
            '{"data":[{"id":5,"title":"Test Title","reading_time":3.0}]}',
            $response->baseResponse->content()
        );
    }

    public function testResourcesMayCustomizeJsonOptionsOnPaginatedResponse(): void
    {
        Route::get('/', function () {
            $paginator = new LengthAwarePaginator(
                collect([new Post(['id' => 5, 'title' => 'Test Title', 'reading_time' => 3.0])]),
                10,
                15,
                1
            );

            return PostResourceWithJsonOptions::collection($paginator);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $this->assertSame(
            '{"data":[{"id":5,"title":"Test Title","reading_time":3.0}],"links":{"first":"\/?page=1","last":"\/?page=1","prev":null,"next":null},"meta":{"current_page":1,"current_page_url":"\/?page=1","from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"\/?page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"\/","per_page":15,"to":1,"total":10}}',
            $response->baseResponse->content()
        );
    }

    public function testResourcesMayCustomizeJsonOptionsWithTypeHintedConstructor(): void
    {
        Route::get('/', function () {
            return new PostResourceWithJsonOptionsAndTypeHints(new Post([
                'id' => 5,
                'title' => 'Test Title',
                'reading_time' => 3.0,
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $this->assertSame(
            '{"data":{"id":5,"title":"Test Title","reading_time":3.0}}',
            $response->baseResponse->content()
        );
    }

    public function testCustomHeadersMayBeSetOnResponses(): void
    {
        Route::get('/', function () {
            return (new PostResource(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ])))->response()->setStatusCode(202)->header('X-Custom', 'True');
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(202);
        $response->assertHeader('X-Custom', 'True');
    }

    public function testResourcesMayReceiveProperStatusCodeForFreshModels(): void
    {
        Route::get('/', function () {
            $post = new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]);

            $post->wasRecentlyCreated = true;

            return new PostResource($post);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);
    }

    public function testCollectionsAreNotDoubledWrapped(): void
    {
        Route::get('/', function () {
            return new PostCollectionResource(collect([new Post([
                'id' => 5,
                'title' => 'Test Title',
            ])]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
        ]);
    }

    public function testPaginatorsReceiveLinks(): void
    {
        Route::get('/', function () {
            $paginator = new LengthAwarePaginator(
                collect([new Post(['id' => 5, 'title' => 'Test Title'])]),
                10,
                15,
                1
            );

            return new PostCollectionResource($paginator);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'links' => [
                'first' => '/?page=1',
                'last' => '/?page=1',
                'prev' => null,
                'next' => null,
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'path' => '/',
                'per_page' => 15,
                'to' => 1,
                'total' => 10,
            ],
        ]);
    }

    public function testPaginatorResourceCanPreserveQueryParameters(): void
    {
        Route::get('/', function () {
            $collection = collect([new Post(['id' => 2, 'title' => 'Hypervel Nova'])]);
            $paginator = new LengthAwarePaginator(
                $collection,
                3,
                1,
                2
            );

            return PostCollectionResource::make($paginator)->preserveQuery();
        });

        $response = $this->withoutExceptionHandling()->get(
            '/?framework=hypervel&author=Otwell&page=2',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 2,
                    'title' => 'Hypervel Nova',
                ],
            ],
            'links' => [
                'first' => '/?framework=hypervel&author=Otwell&page=1',
                'last' => '/?framework=hypervel&author=Otwell&page=3',
                'prev' => '/?framework=hypervel&author=Otwell&page=1',
                'next' => '/?framework=hypervel&author=Otwell&page=3',
            ],
            'meta' => [
                'current_page' => 2,
                'from' => 2,
                'last_page' => 3,
                'path' => '/',
                'per_page' => 1,
                'to' => 2,
                'total' => 3,
            ],
        ]);
    }

    public function testPaginatorResourceCanReceiveQueryParameters(): void
    {
        Route::get('/', function () {
            $collection = collect([new Post(['id' => 2, 'title' => 'Hypervel Nova'])]);
            $paginator = new LengthAwarePaginator(
                $collection,
                3,
                1,
                2
            );

            return PostCollectionResource::make($paginator)->withQuery(['author' => 'Taylor']);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/?framework=hypervel&author=Otwell&page=2',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 2,
                    'title' => 'Hypervel Nova',
                ],
            ],
            'links' => [
                'first' => '/?author=Taylor&page=1',
                'last' => '/?author=Taylor&page=3',
                'prev' => '/?author=Taylor&page=1',
                'next' => '/?author=Taylor&page=3',
            ],
            'meta' => [
                'current_page' => 2,
                'from' => 2,
                'last_page' => 3,
                'path' => '/',
                'per_page' => 1,
                'to' => 2,
                'total' => 3,
            ],
        ]);
    }

    public function testCursorPaginatorReceiveLinks(): void
    {
        Route::get('/', function () {
            $paginator = new CursorPaginator(
                collect([new Post(['id' => 5, 'title' => 'Test Title']), new Post(['id' => 6, 'title' => 'Hello'])]),
                1,
                null,
                ['parameters' => ['id']]
            );

            return new PostCollectionResource($paginator);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'links' => [
                'first' => null,
                'last' => null,
                'prev' => null,
                'next' => '/?cursor=' . (new Cursor(['id' => 5]))->encode(),
            ],
            'meta' => [
                'path' => '/',
                'per_page' => 1,
                'next_cursor' => (new Cursor(['id' => 5]))->encode(),
                'prev_cursor' => null,
            ],
        ]);
    }

    public function testCursorPaginatorResourceCanPreserveQueryParameters(): void
    {
        Route::get('/', function () {
            $collection = collect([new Post(['id' => 5, 'title' => 'Test Title']), new Post(['id' => 6, 'title' => 'Hello'])]);
            $paginator = new CursorPaginator(
                $collection,
                1,
                null,
                ['parameters' => ['id']]
            );

            return PostCollectionResource::make($paginator)->preserveQuery();
        });

        $response = $this->withoutExceptionHandling()->get(
            '/?framework=hypervel&author=Otwell',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'links' => [
                'first' => null,
                'last' => null,
                'prev' => null,
                'next' => '/?framework=hypervel&author=Otwell&cursor=' . (new Cursor(['id' => 5]))->encode(),
            ],
            'meta' => [
                'path' => '/',
                'per_page' => 1,
            ],
        ]);
    }

    public function testCursorPaginatorResourceCanReceiveQueryParameters(): void
    {
        Route::get('/', function () {
            $collection = collect([new Post(['id' => 5, 'title' => 'Test Title']), new Post(['id' => 6, 'title' => 'Hello'])]);
            $paginator = new CursorPaginator(
                $collection,
                1,
                null,
                ['parameters' => ['id']]
            );

            return PostCollectionResource::make($paginator)->withQuery(['author' => 'Taylor']);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/?framework=hypervel&author=Otwell',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'links' => [
                'first' => null,
                'last' => null,
                'prev' => null,
                'next' => '/?author=Taylor&cursor=' . (new Cursor(['id' => 5]))->encode(),
            ],
            'meta' => [
                'path' => '/',
                'per_page' => 1,
            ],
        ]);
    }

    public function testToJsonMayBeLeftOffOfCollection(): void
    {
        Route::get('/', function () {
            return new EmptyPostCollectionResource(new LengthAwarePaginator(
                collect([new Post(['id' => 5, 'title' => 'Test Title'])]),
                10,
                15,
                1
            ));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                    'custom' => true,
                ],
            ],
            'links' => [
                'first' => '/?page=1',
                'last' => '/?page=1',
                'prev' => null,
                'next' => null,
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'path' => '/',
                'per_page' => 15,
                'to' => 1,
                'total' => 10,
            ],
        ]);
    }

    public function testToJsonMayBeLeftOffOfSingleResource(): void
    {
        Route::get('/', function () {
            return new ReallyEmptyPostResource(new Post([
                'id' => 5,
                'title' => 'Test Title',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id' => 5,
                'title' => 'Test Title',
            ],
        ]);
    }

    public function testOriginalOnResponseIsModelWhenSingleResource(): void
    {
        $createdPost = new Post(['id' => 5, 'title' => 'Test Title']);
        Route::get('/', function () use ($createdPost) {
            return new ReallyEmptyPostResource($createdPost);
        });
        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );
        $this->assertTrue($createdPost->is($response->getOriginalContent()));
    }

    public function testOriginalOnResponseIsCollectionOfModelWhenCollectionResource(): void
    {
        $createdPosts = collect([
            new Post(['id' => 5, 'title' => 'Test Title']),
            new Post(['id' => 6, 'title' => 'Test Title 2']),
        ]);
        Route::get('/', function () use ($createdPosts) {
            return new EmptyPostCollectionResource(new LengthAwarePaginator($createdPosts, 10, 15, 1));
        });
        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );
        $createdPosts->each(function ($post) use ($response) {
            $this->assertTrue($response->getOriginalContent()->contains($post));
        });
    }

    public function testCollectionResourceWithPaginationInformation(): void
    {
        $posts = collect([
            new Post(['id' => 5, 'title' => 'Test Title']),
        ]);

        Route::get('/', function () use ($posts) {
            return new PostCollectionResourceWithPaginationInformation(new LengthAwarePaginator($posts, 10, 1, 1));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'current_page' => 1,
            'per_page' => 1,
            'total_page' => 10,
            'total' => 10,
        ]);
    }

    public function testResourceWithPaginationInformation(): void
    {
        $posts = collect([
            new Post(['id' => 5, 'title' => 'Test Title']),
        ]);

        Route::get('/', function () use ($posts) {
            return PostResourceWithAnonymousResourceCollectionWithPaginationInformation::collection(new LengthAwarePaginator($posts, 10, 1, 1));
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson([
            'data' => [
                [
                    'id' => 5,
                    'title' => 'Test Title',
                ],
            ],
            'current_page' => 1,
            'per_page' => 1,
            'total_page' => 10,
            'total' => 10,
        ]);
    }

    public function testCollectionResourcesAreCountable(): void
    {
        $posts = collect([
            new Post(['id' => 1, 'title' => 'Test title']),
            new Post(['id' => 2, 'title' => 'Test title 2']),
        ]);

        $collection = new PostCollectionResource($posts);

        $this->assertCount(2, $collection);
        $this->assertCount(2, $collection);
    }

    public function testCollectionResourcesMustCollectResources(): void
    {
        $posts = collect([
            new Post(['id' => 1, 'title' => 'Test title']),
            new Post(['id' => 2, 'title' => 'Test title 2']),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must collect');

        new PostModelCollectionResource($posts);
    }

    public function testKeysArePreservedIfTheResourceIsFlaggedToPreserveKeys(): void
    {
        $data = [
            'authorBook' => [
                'byId' => [
                    1 => [
                        'id' => 1,
                        'authorId' => 5,
                        'bookId' => 22,
                    ],
                    2 => [
                        'id' => 2,
                        'authorId' => 5,
                        'bookId' => 15,
                    ],
                    3 => [
                        'id' => 3,
                        'authorId' => 42,
                        'bookId' => 12,
                    ],
                ],
                'allIds' => [1, 2, 3],
            ],
        ];

        Route::get('/', function () use ($data) {
            return new ResourceWithPreservedKeys($data);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson(['data' => $data]);
    }

    public function testKeysArePreservedInAnAnonymousCollectionIfTheResourceIsFlaggedToPreserveKeys(): void
    {
        $data = Collection::make([
            [
                'id' => 1,
                'authorId' => 5,
                'bookId' => 22,
            ],
            [
                'id' => 2,
                'authorId' => 5,
                'bookId' => 15,
            ],
            [
                'id' => 3,
                'authorId' => 42,
                'bookId' => 12,
            ],
        ])->keyBy->id;

        Route::get('/', function () use ($data) {
            return ResourceWithPreservedKeys::collection($data);
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson(['data' => $data->toArray()]);
    }

    public function testKeysArePreservedInAnAnonymousCollectionUsingPreserveKeysMethod(): void
    {
        $data = Collection::make([
            ['id' => 1, 'title' => 'Test'],
            ['id' => 2, 'title' => 'Test 2'],
        ])->keyBy->id;

        Route::get('/', function () use ($data) {
            return JsonResource::collection($data)->preserveKeys();
        });

        $response = $this->withoutExceptionHandling()->get(
            '/',
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);

        $response->assertJson(['data' => $data->toArray()]);
    }

    public function testLeadingMergeKeyedValueIsMergedCorrectly(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    new MergeValue(['name' => 'mohamed', 'location' => 'hurghada']),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'name' => 'mohamed', 'location' => 'hurghada',
        ], $results);
    }

    public function testPostTooLargeException(): void
    {
        $request = new Request(server: ['CONTENT_LENGTH' => '4']);
        $post = new ValidatePostSize;
        $post->handle($request, fn () => new Response);

        $this->expectException(PostTooLargeException::class);
        $this->expectExceptionMessage('The POST data is too large.');

        $request = new Request(server: ['CONTENT_LENGTH' => '2147483640']);
        $post = new ValidatePostSize;
        $post->handle($request, fn () => null);
    }

    public function testLeadingMergeKeyedValueIsMergedCorrectlyWhenFirstValueIsMissing(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    new MergeValue([
                        0 => new MissingValue,
                        'name' => 'mohamed',
                        'location' => 'hurghada',
                    ]),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'name' => 'mohamed', 'location' => 'hurghada',
        ], $results);
    }

    public function testLeadingMergeValueIsMergedCorrectly(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    new MergeValue(['First', 'Second']),
                    'Taylor',
                    'Mohamed',
                    new MergeValue(['Adam', 'Matt']),
                    'Jeffrey',
                    new MergeValue(['Abigail', 'Lydia']),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'First', 'Second', 'Taylor', 'Mohamed', 'Adam', 'Matt', 'Jeffrey', 'Abigail', 'Lydia',
        ], $results);
    }

    public function testMergeValuesMayBeMissing(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    new MergeValue(['First', 'Second']),
                    'Taylor',
                    'Mohamed',
                    $this->mergeWhen(false, ['Adam', 'Matt']),
                    'Jeffrey',
                    new MergeValue(['Abigail', 'Lydia']),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'First', 'Second', 'Taylor', 'Mohamed', 'Jeffrey', 'Abigail', 'Lydia',
        ], $results);
    }

    public function testInitialMergeValuesMayBeMissing(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    $this->mergeWhen(false, ['First', 'Second']),
                    'Taylor',
                    'Mohamed',
                    $this->mergeWhen(true, ['Adam', 'Matt']),
                    'Jeffrey',
                    new MergeValue(['Abigail', 'Lydia']),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'Taylor', 'Mohamed', 'Adam', 'Matt', 'Jeffrey', 'Abigail', 'Lydia',
        ], $results);
    }

    public function testMergeValueCanMergeJsonSerializable(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                $postResource = new PostResource(new Post([
                    'id' => 1,
                    'title' => 'Test Title 1',
                ]));

                return $this->filter([
                    new MergeValue($postResource),
                    'user' => 'test user',
                    'age' => 'test age',
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'id' => 1,
            'title' => 'Test Title 1',
            'custom' => true,
            'user' => 'test user',
            'age' => 'test age',
        ], $results);
    }

    public function testMergeValueCanMergeCollectionOfJsonSerializable(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                $posts = collect([
                    new Post(['id' => 1, 'title' => 'Test title 1']),
                    new Post(['id' => 2, 'title' => 'Test title 2']),
                ]);

                return $this->filter([
                    new MergeValue(PostResource::collection($posts)),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            ['id' => 1, 'title' => 'Test title 1', 'custom' => true],
            ['id' => 2, 'title' => 'Test title 2', 'custom' => true],
        ], $results);
    }

    public function testAllMergeValuesMayBeMissing(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    $this->mergeWhen(false, ['First', 'Second']),
                    'Taylor',
                    'Mohamed',
                    $this->mergeWhen(false, ['Adam', 'Matt']),
                    'Jeffrey',
                    $this->mergeWhen(false, ['Abigail', 'Lydia']),
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'Taylor', 'Mohamed', 'Jeffrey',
        ], $results);
    }

    public function testMergeValuesMayFallbackToDefaults(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    $this->mergeUnless(false, ['Taylor', 'Mohamed'], ['First', 'Second']),
                    $this->mergeWhen(false, ['Adam', 'Matt'], ['Abigail', 'Lydia']),
                    'Jeffrey',
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            'Taylor', 'Mohamed', 'Abigail', 'Lydia', 'Jeffrey',
        ], $results);
    }

    public function testNestedMerges(): void
    {
        $filter = new class {
            use ConditionallyLoadsAttributes;

            public function work(): array
            {
                return $this->filter([
                    $this->mergeWhen(true, [['Something']]),
                    [
                        $this->mergeWhen(true, ['First', $this->mergeWhen(true, ['Second'])]),
                        'Third',
                    ],
                    [
                        'Fourth',
                    ],
                ]);
            }
        };

        $results = $filter->work();

        $this->assertEquals([
            [
                'Something',
            ],
            [
                'First', 'Second', 'Third',
            ],
            [
                'Fourth',
            ],
        ], $results);
    }

    public function testTheResourceCanBeAnArray(): void
    {
        $this->assertJsonResourceResponse([
            'user@example.com' => 'John',
            'admin@example.com' => 'Hank',
        ], [
            'data' => [
                'user@example.com' => 'John',
                'admin@example.com' => 'Hank',
            ],
        ]);
    }

    public function testItWillReturnAsAnArrayWhenStringKeysAreStripped(): void
    {
        $this->assertJsonResourceResponse([
            1 => 'John',
            2 => 'Hank',
            'foo' => new MissingValue,
        ], ['data' => ['John', 'Hank']]);

        $this->assertJsonResourceResponse([
            1 => 'John',
            'foo' => new MissingValue,
            3 => 'Hank',
        ], ['data' => ['John', 'Hank']]);

        $this->assertJsonResourceResponse([
            'foo' => new MissingValue,
            2 => 'John',
            3 => 'Hank',
        ], ['data' => ['John', 'Hank']]);
    }

    public function testItStripsNumericKeys(): void
    {
        $this->assertJsonResourceResponse([
            0 => 'John',
            1 => 'Hank',
        ], ['data' => ['John', 'Hank']]);

        $this->assertJsonResourceResponse([
            0 => 'John',
            1 => 'Hank',
            3 => 'Bill',
        ], ['data' => ['John', 'Hank', 'Bill']]);

        $this->assertJsonResourceResponse([
            5 => 'John',
            6 => 'Hank',
        ], ['data' => ['John', 'Hank']]);
    }

    public function testItWontStripKeysIfAnyOfThemAreStrings(): void
    {
        $this->assertJsonResourceResponse([
            '5' => 'John',
            '6' => 'Hank',
            'a' => 'Bill',
        ], ['data' => ['5' => 'John', '6' => 'Hank', 'a' => 'Bill']]);

        $this->assertJsonResourceResponse([
            0 => 10,
            1 => 20,
            'total' => 30,
        ], ['data' => [0 => 10, 1 => 20, 'total' => 30]]);
    }

    public function testItThrowsNoErrorInStrictModeWhenResourceIsPaginated(): void
    {
        $originalMode = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        try {
            Route::get('/', function () {
                $paginator = new LengthAwarePaginator(
                    collect([new Post(['id' => 5, 'title' => 'Test Title', 'reading_time' => 3.0])]),
                    10,
                    15,
                    1
                );

                return PostResourceWithJsonOptions::collection($paginator);
            });

            $response = $this->withoutExceptionHandling()->get(
                '/',
                ['Accept' => 'application/json']
            );

            $response->assertStatus(200);
        } finally {
            Model::preventAccessingMissingAttributes($originalMode);
        }
    }

    public function testResourceSkipsWrappingWhenDataKeyExists(): void
    {
        $resource = new class(['id' => 5, 'title' => 'Test', 'data' => 'some data']) extends JsonResource {
            public static ?string $wrap = 'data';
        };

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertEquals([
            'id' => 5,
            'title' => 'Test',
            'data' => 'some data',
        ], $content);
    }

    public function testResourceWrapsWhenDataKeyDoesNotExist(): void
    {
        $resource = new class(['id' => 5, 'title' => 'Test']) extends JsonResource {
            public static ?string $wrap = 'data';
        };

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertEquals([
            'data' => [
                'id' => 5,
                'title' => 'Test',
            ],
        ], $content);
    }

    public function testResourceCanOverrideWrapping(): void
    {
        $resource = new class(['id' => 5, 'title' => 'Test', 'data' => 'some data']) extends JsonResource {
            public static ?string $wrap = 'results';

            public static bool $forceWrapping = true;
        };

        JsonResource::flushState();

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertEquals([
            'results' => [
                'id' => 5,
                'title' => 'Test',
                'data' => 'some data',
            ],
        ], $content);
    }

    public function testResourceCollectionCanOverrideWrapping(): void
    {
        $resource = new class([new class(['id' => 5, 'title' => 'Test', 'data' => 'some data']) extends JsonResource {
            public static ?string $wrap = null;
        },
        ]) extends ResourceCollection {
            public static ?string $wrap = 'results';
        };

        JsonResource::flushState();

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertEquals([
            'results' => [
                [
                    'id' => 5,
                    'title' => 'Test',
                    'data' => 'some data',
                ],
            ],
        ], $content);
    }

    public function testPaginatedResourceCollectionCanOverrideWrapping(): void
    {
        $resource = new class(new LengthAwarePaginator([new class(['id' => 5, 'title' => 'Test', 'data' => 'some data']) extends JsonResource {
            public static ?string $wrap = null;
        },
        ], 10, 2)) extends ResourceCollection {
            public static ?string $wrap = 'results';
        };

        JsonResource::flushState();

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('results', $content);
        $this->assertArrayHasKey('links', $content);
        $this->assertArrayHasKey('meta', $content);

        $this->assertCount(1, $content['results']);
        $this->assertEquals([
            [
                'id' => 5,
                'title' => 'Test',
                'data' => 'some data',
            ],
        ], $content['results']);
    }

    public function testEmptyPaginatedResourceCollectionCanOverrideWrapping(): void
    {
        $resource = new class(new LengthAwarePaginator([], 10, 2)) extends ResourceCollection {
            public static ?string $wrap = 'results';
        };

        JsonResource::flushState();

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('results', $content);
        $this->assertArrayHasKey('links', $content);
        $this->assertArrayHasKey('meta', $content);

        $this->assertCount(0, $content['results']);
    }

    public function testResourceForceWrapOverridesDataKeyCheck(): void
    {
        $resource = new class(['id' => 5, 'title' => 'Test', 'data' => 'some data']) extends JsonResource {
            public static ?string $wrap = 'data';

            public static bool $forceWrapping = true;
        };

        $response = $resource->toResponse(request());
        $content = json_decode($response->getContent(), true);

        $this->assertEquals([
            'data' => [
                'id' => 5,
                'title' => 'Test',
                'data' => 'some data',
            ],
        ], $content);
    }

    private function assertJsonResourceResponse(array $data, array $expectedJson): void
    {
        Route::get('/', function () use ($data) {
            return new JsonResource($data);
        });

        $this->withoutExceptionHandling()
            ->get('/', ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertExactJson($expectedJson);
    }
}
