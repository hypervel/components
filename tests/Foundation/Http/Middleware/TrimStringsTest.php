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
