<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\StrCache;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class StrCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        StrCache::flush();

        parent::tearDown();
    }

    public function testSnake()
    {
        $this->assertSame('foo_bar', StrCache::snake('fooBar'));
        $this->assertSame('foo_bar_baz', StrCache::snake('fooBarBaz'));
    }

    public function testSnakeWithCustomDelimiter()
    {
        $this->assertSame('foo-bar', StrCache::snake('fooBar', '-'));
    }

    public function testSnakeReturnsCachedResult()
    {
        $first = StrCache::snake('fooBar');
        $second = StrCache::snake('fooBar');

        $this->assertSame($first, $second);
        $this->assertSame('foo_bar', $second);
    }

    public function testCamel()
    {
        $this->assertSame('fooBar', StrCache::camel('foo_bar'));
        $this->assertSame('fooBarBaz', StrCache::camel('foo_bar_baz'));
    }

    public function testCamelReturnsCachedResult()
    {
        $first = StrCache::camel('foo_bar');
        $second = StrCache::camel('foo_bar');

        $this->assertSame($first, $second);
    }

    public function testStudly()
    {
        $this->assertSame('FooBar', StrCache::studly('foo_bar'));
        $this->assertSame('FooBarBaz', StrCache::studly('foo_bar_baz'));
    }

    public function testStudlyReturnsCachedResult()
    {
        $first = StrCache::studly('foo_bar');
        $second = StrCache::studly('foo_bar');

        $this->assertSame($first, $second);
    }

    public function testPlural()
    {
        $this->assertSame('users', StrCache::plural('user'));
        $this->assertSame('children', StrCache::plural('child'));
    }

    public function testPluralReturnsCachedResult()
    {
        $first = StrCache::plural('user');
        $second = StrCache::plural('user');

        $this->assertSame($first, $second);
    }

    public function testPluralWithCountNotCached()
    {
        $this->assertSame('user', StrCache::plural('user', 1));
        $this->assertSame('users', StrCache::plural('user', 3));
    }

    public function testSingular()
    {
        $this->assertSame('user', StrCache::singular('users'));
        $this->assertSame('child', StrCache::singular('children'));
    }

    public function testSingularReturnsCachedResult()
    {
        $first = StrCache::singular('users');
        $second = StrCache::singular('users');

        $this->assertSame($first, $second);
    }

    public function testPluralStudly()
    {
        $this->assertSame('UserProfiles', StrCache::pluralStudly('UserProfile'));
    }

    public function testPluralStudlyReturnsCachedResult()
    {
        $first = StrCache::pluralStudly('UserProfile');
        $second = StrCache::pluralStudly('UserProfile');

        $this->assertSame($first, $second);
    }

    public function testPluralStudlyWithCountNotCached()
    {
        $this->assertSame('UserProfile', StrCache::pluralStudly('UserProfile', 1));
        $this->assertSame('UserProfiles', StrCache::pluralStudly('UserProfile', 5));
    }

    public function testFlush()
    {
        StrCache::snake('fooBar');
        StrCache::camel('foo_bar');
        StrCache::studly('foo_bar');
        StrCache::plural('user');
        StrCache::singular('users');
        StrCache::pluralStudly('UserProfile');

        StrCache::flush();

        // After flush, results are recomputed (same values, but cache was cleared)
        $this->assertSame('foo_bar', StrCache::snake('fooBar'));
        $this->assertSame('fooBar', StrCache::camel('foo_bar'));
    }

    public function testFlushSnake()
    {
        StrCache::snake('fooBar');
        StrCache::camel('foo_bar');

        StrCache::flushSnake();

        // Camel cache should still work
        $this->assertSame('fooBar', StrCache::camel('foo_bar'));
    }

    public function testFlushCamel()
    {
        StrCache::camel('foo_bar');

        StrCache::flushCamel();

        // Recomputes after flush
        $this->assertSame('fooBar', StrCache::camel('foo_bar'));
    }

    public function testFlushStudly()
    {
        StrCache::studly('foo_bar');

        StrCache::flushStudly();

        $this->assertSame('FooBar', StrCache::studly('foo_bar'));
    }

    public function testFlushPlural()
    {
        StrCache::plural('user');

        StrCache::flushPlural();

        $this->assertSame('users', StrCache::plural('user'));
    }

    public function testFlushSingular()
    {
        StrCache::singular('users');

        StrCache::flushSingular();

        $this->assertSame('user', StrCache::singular('users'));
    }

    public function testFlushPluralStudly()
    {
        StrCache::pluralStudly('UserProfile');

        StrCache::flushPluralStudly();

        $this->assertSame('UserProfiles', StrCache::pluralStudly('UserProfile'));
    }
}
