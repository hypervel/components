<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Encryption\Encrypter;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Fixtures\PreventRequestForgeryExceptStub;

class PreventRequestForgeryExceptTest extends TestCase
{
    private PreventRequestForgeryExceptStub $stub;

    private Request $request;

    /**
     * Configure a global exclusion for the middleware tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        PreventRequestForgeryExceptStub::except(['/globally/ignored']);
        $this->stub = new PreventRequestForgeryExceptStub(app(), new Encrypter(Encrypter::generateKey('AES-128-CBC')));
        $this->request = Request::create('http://example.com/foo/bar', 'POST');
    }

    public function testItCanExceptPaths(): void
    {
        $this->assertMatchingExcept(['/foo/bar']);
        $this->assertMatchingExcept(['foo/bar']);
        $this->assertNonMatchingExcept(['/bar/foo']);
    }

    public function testPathsCanBeGloballyIgnored(): void
    {
        $this->request = Request::create('http://example.com/globally/ignored', 'POST');
        $this->assertMatchingExcept([]);
    }

    public function testItCanExceptWildcardPaths(): void
    {
        $this->assertMatchingExcept(['/foo/*']);
        $this->assertNonMatchingExcept(['/bar*']);
    }

    public function testItCanExceptFullUrlPaths(): void
    {
        $this->assertMatchingExcept(['http://example.com/foo/bar']);
        $this->assertMatchingExcept(['http://example.com/foo/bar/']);

        $this->assertNonMatchingExcept(['https://example.com/foo/bar/']);
        $this->assertNonMatchingExcept(['http://foobar.com/']);
    }

    public function testItCanExceptFullUrlWildcardPaths(): void
    {
        $this->assertMatchingExcept(['http://example.com/*']);
        $this->assertMatchingExcept(['*example.com*']);

        $this->request = Request::create('https://example.com', 'POST');
        $this->assertMatchingExcept(['*example.com']);
    }

    /**
     * Assert whether the request matches the given exclusions.
     */
    private function assertMatchingExcept(array $except, bool $bool = true): void
    {
        $this->assertSame($bool, $this->stub->setExcept($except)->checkInExceptArray($this->request));
    }

    /**
     * Assert that the request does not match the given exclusions.
     */
    private function assertNonMatchingExcept(array $except): void
    {
        $this->assertMatchingExcept($except, false);
    }
}
