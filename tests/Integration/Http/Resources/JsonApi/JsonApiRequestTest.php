<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi;

use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;

class JsonApiRequestTest extends TestCase
{
    public function testItCanResolveSparseFields(): void
    {
        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'fields' => [
                'users' => 'name,email',
                'teams' => 'name',
            ],
        ]));

        $this->assertSame(['name', 'email'], $request->sparseFields('users'));
        $this->assertSame(['name'], $request->sparseFields('teams'));
        $this->assertSame([], $request->sparseFields('posts'));
    }

    public function testItCanResolveEmptySparseFields(): void
    {
        $request = JsonApiRequest::create(uri: '/');

        $this->assertSame([], $request->sparseFields('users'));
        $this->assertSame([], $request->sparseFields('teams'));
        $this->assertSame([], $request->sparseFields('posts'));
    }

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

    public function testItCanResolveSparseIncluded(): void
    {
        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'include' => 'teams,posts.author,posts.comments,profile.user.profile',
        ]));

        $this->assertSame(['teams', 'posts', 'profile'], $request->sparseIncluded());
        $this->assertSame([], $request->sparseIncluded('teams'));
        $this->assertSame(['author', 'comments'], $request->sparseIncluded('posts'));
        $this->assertSame(['user.profile'], $request->sparseIncluded('profile'));
    }

    public function testItCanResolveSparseIncludedWithMaxRelationshipNesting(): void
    {
        JsonApiResource::maxRelationshipDepth(2);

        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'include' => 'teams,posts.author,posts.comments,profile.user.profile',
        ]));

        $this->assertSame(['teams', 'posts', 'profile'], $request->sparseIncluded());
        $this->assertSame([], $request->sparseIncluded('teams'));
        $this->assertSame(['author', 'comments'], $request->sparseIncluded('posts'));
        $this->assertSame(['user'], $request->sparseIncluded('profile'));
    }

    public function testItCanResolveEmptySparseIncluded(): void
    {
        $request = JsonApiRequest::create(uri: '/');

        $this->assertSame([], $request->sparseIncluded());
    }
}
