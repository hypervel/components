<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware\TrimStringsTest;

use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class TrimStringsTest extends TestCase
{
    public function testNonStringValuesDoNotPerformExclusionMatching()
    {
        $middleware = new TrimStringsTrackingExclusionMatches;
        $symfonyRequest = new SymfonyRequest([
            'integer' => 123,
            'boolean' => true,
            'null' => null,
            'string' => ' value ',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame(123, $request->input('integer'));
            $this->assertTrue($request->input('boolean'));
            $this->assertNull($request->input('null'));
            $this->assertSame('value', $request->input('string'));
        });

        $this->assertSame(1, $middleware->exclusionMatchCount);
    }

    public function testTrimStringsIgnoringExceptAttribute()
    {
        $middleware = new TrimStringsWithExceptAttribute;
        $symfonyRequest = new SymfonyRequest([
            'abc' => '  123  ',
            'xyz' => '  456  ',
            'foo' => '  789  ',
            'bar' => '  010  ',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('123', $request->input('abc'));
            $this->assertSame('456', $request->input('xyz'));
            $this->assertSame('  789  ', $request->input('foo'));
            $this->assertSame('  010  ', $request->input('bar'));
        });
    }

    public function testTrimStringsSupportsExactAndWildcardExceptAttributes()
    {
        $middleware = new TrimStringsWithExactAndWildcardExceptAttributes;
        $symfonyRequest = new SymfonyRequest([
            'exact' => ' exact ',
            'other' => ' other ',
            'users' => [
                ['secret' => ' first ', 'name' => ' Taylor '],
                ['secret' => ' second ', 'name' => ' Abigail '],
            ],
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame(' exact ', $request->input('exact'));
            $this->assertSame('other', $request->input('other'));
            $this->assertSame(' first ', $request->input('users.0.secret'));
            $this->assertSame('Taylor', $request->input('users.0.name'));
            $this->assertSame(' second ', $request->input('users.1.secret'));
            $this->assertSame('Abigail', $request->input('users.1.name'));
        });
    }

    public function testGlobalExceptAppliesToAnExistingMiddlewareInstance()
    {
        $middleware = new TrimStrings;

        $this->assertSame('value', $this->handle($middleware, ['token' => ' value '])->input('token'));

        TrimStrings::except('token');

        $this->assertSame(' value ', $this->handle($middleware, ['token' => ' value '])->input('token'));
    }

    public function testFlushStateAppliesToAnExistingMiddlewareInstance()
    {
        TrimStrings::except('token');

        $middleware = new TrimStrings;

        $this->assertSame(' value ', $this->handle($middleware, ['token' => ' value '])->input('token'));

        TrimStrings::flushState();

        $this->assertSame('value', $this->handle($middleware, ['token' => ' value '])->input('token'));
    }

    public function testInstanceExceptChangesAreUsedBySubsequentRequests(): void
    {
        $middleware = new MutableExceptTrimStrings;

        $this->assertSame('value', $this->handle($middleware, ['token' => ' value '])->input('token'));

        $middleware->setExcept(['token']);

        $this->assertSame(' value ', $this->handle($middleware, ['token' => ' value '])->input('token'));
    }

    public function testTrimStringsNBSP()
    {
        $middleware = new TrimStrings;
        $symfonyRequest = new SymfonyRequest([
            // Here has some NBSP, but it still display to space.
            // Please note, do not edit in browser
            'abc' => '   123    ',
            'zwnbsp' => '﻿  ha  ﻿﻿',
            'xyz' => 'だ',
            'foo' => 'ム',
            'bar' => '   だ    ',
            'baz' => '   ム    ',
            'binary' => " \xE9  ",
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('123', $request->input('abc'));
            $this->assertSame('ha', $request->input('zwnbsp'));
            $this->assertSame('だ', $request->input('xyz'));
            $this->assertSame('ム', $request->input('foo'));
            $this->assertSame('だ', $request->input('bar'));
            $this->assertSame('ム', $request->input('baz'));
            $this->assertSame("\xE9", $request->input('binary'));
        });
    }

    public function testNoZeroWidthSpaceCharacterReturnsTheSameString(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title does not contain any zero-width space',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title does not contain any zero-width space', $request->title);
        });
    }

    public function testLeadingZeroWidthSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '​This title contains a zero-width space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a zero-width space at the beginning', $request->title);
        });
    }

    public function testTrimStringsCanGloballyIgnoreCertainInputs(): void
    {
        $request = new Request;

        $request->merge([
            'globally_ignored_title' => ' test title ',
        ]);

        TrimStrings::except(['globally_ignored_title']);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame(' test title ', $request->globally_ignored_title);
        });
    }

    public function testTrailingZeroWidthSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a zero-width space at the end​',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a zero-width space at the end', $request->title);
        });
    }

    public function testLeadingZeroWidthNonBreakableSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿This title contains a zero-width non-breakable space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a zero-width non-breakable space at the beginning', $request->title);
        });
    }

    public function testLeadingMultipleZeroWidthNonBreakableSpaceCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿﻿This title contains a zero-width non-breakable space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a zero-width non-breakable space at the beginning', $request->title);
        });
    }

    public function testCombinationOfLeadingAndTrailingZeroWidthNonBreakableSpaceAndZeroWidthSpaceCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿​﻿This title contains a combination of zero-width non-breakable space and zero-width spaces characters at the beginning and the end​',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a combination of zero-width non-breakable space and zero-width spaces characters at the beginning and the end', $request->title);
        });
    }

    public function testLeadingInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎This title contains a invisible character at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a invisible character at the beginning', $request->title);
        });
    }

    public function testTrailingInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a invisible character at the end‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a invisible character at the end', $request->title);
        });
    }

    public function testLeadingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎‎This title contains a invisible character at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a invisible character at the beginning', $request->title);
        });
    }

    public function testTrailingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a invisible character at the end‎‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a invisible character at the end', $request->title);
        });
    }

    public function testCombinationOfLeadingAndTrailingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎‎This title contains a combination of a invisible character at beginning and the end‎‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('This title contains a combination of a invisible character at beginning and the end', $request->title);
        });
    }

    public function testTrimStringsCanIgnoreNestedAttributesUsingWildcards(): void
    {
        $request = new Request;

        $request->merge([
            'users' => [
                ['name' => '  foo  ', 'role' => '  admin  '],
                ['name' => '  bar  ', 'role' => '  editor  '],
            ],
            'teams' => [
                ['name' => '  team  '],
            ],
            'orders' => [
                [
                    'items' => [
                        ['meta' => ['title' => '  foo  ', 'sku' => '  SKU-1  ', 'tags' => ['  alpha  ']]],
                    ],
                ],
                [
                    'items' => [
                        ['meta' => ['title' => '  bar  ', 'sku' => '  SKU-2  ', 'tags' => ['  beta  ']]],
                    ],
                ],
            ],
        ]);

        $middleware = new class extends TrimStrings {
            protected array $except = [
                'users.*.name',
                'orders.*.items.*.meta.title',
                'orders.*.items.*.meta.tags.*',
            ];
        };

        $middleware->handle($request, function (Request $request): void {
            $this->assertSame('  foo  ', $request->input('users.0.name'));
            $this->assertSame('  bar  ', $request->input('users.1.name'));
            $this->assertSame('admin', $request->input('users.0.role'));
            $this->assertSame('editor', $request->input('users.1.role'));
            $this->assertSame('team', $request->input('teams.0.name'));
            $this->assertSame('  foo  ', $request->input('orders.0.items.0.meta.title'));
            $this->assertSame('SKU-1', $request->input('orders.0.items.0.meta.sku'));
            $this->assertSame('  alpha  ', $request->input('orders.0.items.0.meta.tags.0'));

            $this->assertSame('  bar  ', $request->input('orders.1.items.0.meta.title'));
            $this->assertSame('SKU-2', $request->input('orders.1.items.0.meta.sku'));
            $this->assertSame('  beta  ', $request->input('orders.1.items.0.meta.tags.0'));
        });
    }

    private function handle(TrimStrings $middleware, array $input): Request
    {
        $symfonyRequest = new SymfonyRequest($input);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, fn (Request $request) => $request);

        return $request;
    }
}

class TrimStringsWithExceptAttribute extends TrimStrings
{
    protected array $except = [
        'foo',
        'bar',
    ];
}

class TrimStringsWithExactAndWildcardExceptAttributes extends TrimStrings
{
    protected array $except = [
        'exact',
        'users.*.secret',
    ];
}

class TrimStringsTrackingExclusionMatches extends TrimStrings
{
    public int $exclusionMatchCount = 0;

    protected function shouldSkip(string $key, array $except): bool
    {
        ++$this->exclusionMatchCount;

        return parent::shouldSkip($key, $except);
    }
}

class MutableExceptTrimStrings extends TrimStrings
{
    public function setExcept(array $except): void
    {
        $this->except = $except;
    }
}
