<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;

class TrimStringsTest extends TestCase
{
    /**
     * Test no zero-width space character returns the same string.
     */
    public function testNoZeroWidthSpaceCharacterReturnsTheSameString(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title does not contain any zero-width space',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title does not contain any zero-width space', $req->title);
        });
    }

    /**
     * Test leading zero-width space character is trimmed [ZWSP].
     */
    public function testLeadingZeroWidthSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '​This title contains a zero-width space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a zero-width space at the beginning', $req->title);
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

        $middleware->handle($request, function ($req) {
            $this->assertSame(' test title ', $req->globally_ignored_title);
        });
    }

    /**
     * Test trailing zero-width space character is trimmed [ZWSP].
     */
    public function testTrailingZeroWidthSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a zero-width space at the end​',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a zero-width space at the end', $req->title);
        });
    }

    /**
     * Test leading zero-width non-breakable space character is trimmed [ZWNBSP].
     */
    public function testLeadingZeroWidthNonBreakableSpaceCharacterIsTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿This title contains a zero-width non-breakable space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a zero-width non-breakable space at the beginning', $req->title);
        });
    }

    /**
     * Test leading multiple zero-width non-breakable space characters are trimmed [ZWNBSP].
     */
    public function testLeadingMultipleZeroWidthNonBreakableSpaceCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿﻿This title contains a zero-width non-breakable space at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a zero-width non-breakable space at the beginning', $req->title);
        });
    }

    /**
     * Test a combination of leading and trailing zero-width non-breakable space and zero-width space characters are trimmed [ZWNBSP], [ZWSP].
     */
    public function testCombinationOfLeadingAndTrailingZeroWidthNonBreakableSpaceAndZeroWidthSpaceCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '﻿​﻿This title contains a combination of zero-width non-breakable space and zero-width spaces characters at the beginning and the end​',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a combination of zero-width non-breakable space and zero-width spaces characters at the beginning and the end', $req->title);
        });
    }

    /**
     * Test leading invisible character are trimmed [U+200E].
     */
    public function testLeadingInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎This title contains a invisible character at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a invisible character at the beginning', $req->title);
        });
    }

    /**
     * Test trailing invisible character are trimmed [U+200E].
     */
    public function testTrailingInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a invisible character at the end‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a invisible character at the end', $req->title);
        });
    }

    /**
     * Test leading multiple invisible character are trimmed [U+200E].
     */
    public function testLeadingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎‎This title contains a invisible character at the beginning',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a invisible character at the beginning', $req->title);
        });
    }

    /**
     * Test trailing multiple invisible character are trimmed [U+200E].
     */
    public function testTrailingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => 'This title contains a invisible character at the end‎‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a invisible character at the end', $req->title);
        });
    }

    /**
     * Test combination of leading and trailing multiple invisible characters are trimmed [U+200E].
     */
    public function testCombinationOfLeadingAndTrailingMultipleInvisibleCharactersAreTrimmed(): void
    {
        $request = new Request;

        $request->merge([
            'title' => '‎‎This title contains a combination of a invisible character at beginning and the end‎‎',
        ]);

        $middleware = new TrimStrings;

        $middleware->handle($request, function ($req) {
            $this->assertSame('This title contains a combination of a invisible character at beginning and the end', $req->title);
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

        $middleware->handle($request, function ($req) {
            $this->assertSame('  foo  ', $req->input('users.0.name'));
            $this->assertSame('  bar  ', $req->input('users.1.name'));
            $this->assertSame('admin', $req->input('users.0.role'));
            $this->assertSame('editor', $req->input('users.1.role'));
            $this->assertSame('team', $req->input('teams.0.name'));
            $this->assertSame('  foo  ', $req->input('orders.0.items.0.meta.title'));
            $this->assertSame('SKU-1', $req->input('orders.0.items.0.meta.sku'));
            $this->assertSame('  alpha  ', $req->input('orders.0.items.0.meta.tags.0'));

            $this->assertSame('  bar  ', $req->input('orders.1.items.0.meta.title'));
            $this->assertSame('SKU-2', $req->input('orders.1.items.0.meta.sku'));
            $this->assertSame('  beta  ', $req->input('orders.1.items.0.meta.tags.0'));
        });
    }
}
