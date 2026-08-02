<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\ArrayBackedJsonApiResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Post;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserWithArrayRelationshipResource;
use Override;

#[WithMigration]
#[WithConfig('auth.providers.users.model', User::class)]
abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    #[Override]
    protected function setUp(): void
    {
        Model::shouldBeStrict(true);

        parent::setUp();
    }

    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->get('users', function () {
            return User::paginate(5)->toResourceCollection();
        });

        $router->get('users/{userId}', function ($userId) {
            return User::find($userId)->toResource();
        });

        $router->get('users/{userId}/with-chaperone-posts', function ($userId) {
            return User::find($userId)->load('chaperonePosts')->toResource();
        });

        $router->get('posts', function () {
            return Post::paginate(5)->toResourceCollection();
        });

        $router->get('posts/{postId}', function ($postId) {
            return Post::find($postId)->toResource();
        });

        $router->get('things/{id}', function ($id) {
            return new ArrayBackedJsonApiResource(['id' => (int) $id, 'name' => 'test']);
        });

        $router->get('users/{userId}/with-array-relationship', function ($userId) {
            $resource = new UserWithArrayRelationshipResource(User::find($userId));
            $resource->loadedRelationshipsMap = [
                [new ArrayBackedJsonApiResource(['id' => 99, 'name' => 'test']), 'things', '99', true],
            ];

            return $resource;
        });

        $router->get('users/{userId}/with-duplicate-instances', function ($userId) {
            $instance1 = User::find($userId);
            $instance2 = User::find($userId);

            $resource = new UserWithArrayRelationshipResource(User::find($userId));
            $resource->loadedRelationshipsMap = [
                [new UserResource($instance1), 'users', (string) $instance1->getKey(), true],
                [new UserResource($instance2), 'users', (string) $instance2->getKey(), true],
            ];

            return $resource;
        });
    }

    protected function afterRefreshingDatabase(): void
    {
        require __DIR__ . '/Fixtures/migrations.php';
    }
}
