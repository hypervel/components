<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resources\JsonApi;

use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Tests\TestCase;

class JsonApiRequestTest extends TestCase
{
    public function testItCanDetermineIfSparseFieldsetWasProvided(): void
    {
        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'fields' => [
                'users' => '',
            ],
        ]));

        $this->assertTrue($request->hasSparseFieldset('users'));
        $this->assertFalse($request->hasSparseFieldset('posts'));
    }

    public function testItCanResolveTopLevelAndNestedSparseIncludes(): void
    {
        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'include' => 'teams,posts.author,posts.comments',
        ]));

        $this->assertSame(['teams', 'posts'], $request->sparseIncluded());
        $this->assertSame([], $request->sparseIncluded('teams'));
        $this->assertSame(['author', 'comments'], $request->sparseIncluded('posts'));
    }
}
