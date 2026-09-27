<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\Comment;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\User;
use Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures\UserResource;
use Override;
use PDO;

class JsonApiRelationshipConnectionsTest extends TestCase
{
    protected string $directory;

    /**
     * Configure two isolated databases with colliding owner identifiers.
     */
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $this->directory = ParallelTesting::tempDir('JsonApiRelationshipConnectionsTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->directory);
        $filesystem->ensureDirectoryExists($this->directory);

        foreach (['first', 'second'] as $connection) {
            $database = $this->directory . '/' . $connection . '.sqlite';
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec('create table users (id integer primary key, name text, email text)');
            $pdo->prepare('insert into users (id, name, email) values (1, ?, ?)')
                ->execute([$connection, $connection . '@example.com']);

            $app->make('config')->set('database.connections.' . $connection, [
                'driver' => 'sqlite',
                'database' => $database,
            ]);
        }
    }

    /**
     * Remove the isolated databases after their connections have been released.
     */
    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    public function testNestedRelationshipBatchesPreserveEachModelsConnection(): void
    {
        $first = (new Comment)->setConnection('first')->forceFill([
            'id' => 1, 'user_id' => 1, 'content' => 'First comment',
        ]);
        $second = (new Comment)->setConnection('second')->forceFill([
            'id' => 2, 'user_id' => 1, 'content' => 'Second comment',
        ]);
        $user = (new User)->setConnection('first')->forceFill([
            'id' => 99, 'name' => 'Parent', 'email' => 'parent@example.com',
        ])->setRelation('comments', $first->newCollection([$first, $second]));

        (new UserResource($user))->resolve(JsonApiRequest::create('/?include=comments.commenter'));

        $this->assertSame('first', $first->getRelation('commenter')->name);
        $this->assertSame('second', $second->getRelation('commenter')->name);
        $this->assertSame('first', $first->getRelation('commenter')->getConnectionName());
        $this->assertSame('second', $second->getRelation('commenter')->getConnectionName());
    }
}
