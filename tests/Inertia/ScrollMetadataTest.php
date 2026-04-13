<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Inertia\ScrollMetadata;
use Hypervel\Tests\Inertia\Fixtures\User;
use Hypervel\Tests\Inertia\Fixtures\UserResource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class ScrollMetadataTest extends TestCase
{
    use InteractsWithUserModels;

    #[DataProvider('wrappedOrUnwrappedProvider')]
    public function testExtractMetadataFromSimplePaginator(bool $wrappedinHttpResource): void
    {
        $users = User::query()->simplePaginate(15);

        if ($wrappedinHttpResource) {
            $users = UserResource::collection($users);
        }

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => null,
            'nextPage' => 2,
            'currentPage' => 1,
        ], ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?page=2'));
        $users = User::query()->simplePaginate(15);

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => 1,
            'nextPage' => 3,
            'currentPage' => 2,
        ], ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?page=3'));
        $users = User::query()->simplePaginate(15);

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => 2,
            'nextPage' => null,
            'currentPage' => 3,
        ], ScrollMetadata::fromPaginator($users)->toArray());
    }

    #[DataProvider('wrappedOrUnwrappedProvider')]
    public function testExtractMetadataFromLengthAwarePaginator(bool $wrappedinHttpResource): void
    {
        $users = User::query()->paginate(15);

        if ($wrappedinHttpResource) {
            $users = UserResource::collection($users);
        }

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => null,
            'nextPage' => 2,
            'currentPage' => 1,
        ], ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?page=2'));
        $users = User::query()->paginate(15);

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => 1,
            'nextPage' => 3,
            'currentPage' => 2,
        ], ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?page=3'));
        $users = User::query()->paginate(15);

        $this->assertEquals([
            'pageName' => 'page',
            'previousPage' => 2,
            'nextPage' => null,
            'currentPage' => 3,
        ], ScrollMetadata::fromPaginator($users)->toArray());
    }

    #[DataProvider('wrappedOrUnwrappedProvider')]
    public function testExtractMetadataFromCursorPaginator(bool $wrappedinHttpResource): void
    {
        $users = User::query()->cursorPaginate(15);

        if ($wrappedinHttpResource) {
            $users = UserResource::collection($users);
        }

        $this->assertEquals([
            'pageName' => 'cursor',
            'previousPage' => null,
            'nextPage' => $users->nextCursor()?->encode(),
            'currentPage' => 1,
        ], $first = ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?cursor=' . $first['nextPage']));
        $users = User::query()->cursorPaginate(15);

        $this->assertEquals([
            'pageName' => 'cursor',
            'previousPage' => $users->previousCursor()?->encode(),
            'nextPage' => $users->nextCursor()?->encode(),
            'currentPage' => $first['nextPage'],
        ], $second = ScrollMetadata::fromPaginator($users)->toArray());

        RequestContext::set(Request::create('/?cursor=' . $second['nextPage']));
        $users = User::query()->cursorPaginate(15);

        $this->assertEquals([
            'pageName' => 'cursor',
            'previousPage' => $users->previousCursor()?->encode(),
            'nextPage' => null,
            'currentPage' => $second['nextPage'],
        ], ScrollMetadata::fromPaginator($users)->toArray());
    }

    /**
     * @return array<string, array<bool>>
     */
    public static function wrappedOrUnwrappedProvider(): array
    {
        return [
            'wrapped in http resource' => [true],
            'not wrapped in http resource' => [false],
        ];
    }

    public function testThrowsExceptionIfNotAPaginator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given value is not a Hypervel paginator instance. Use a custom callback to extract pagination metadata.');

        ScrollMetadata::fromPaginator(collect());
    }
}
