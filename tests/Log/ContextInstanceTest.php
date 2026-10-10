<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Context\CoroutineContext;
use Hypervel\Log\Context\Repository;
use Hypervel\Testbench\TestCase;

class ContextInstanceTest extends TestCase
{
    public function testGetInstanceReturnsRepositoryInstance(): void
    {
        $this->assertInstanceOf(Repository::class, Repository::getInstance());
    }

    public function testGetInstanceReturnsSameInstanceWithinContext(): void
    {
        $first = Repository::getInstance();
        $second = Repository::getInstance();

        $this->assertSame($first, $second);
    }

    public function testInstanceCanBeStoredInVariable(): void
    {
        $context = Repository::getInstance();
        $context->add('key', 'value');

        $this->assertSame('value', Repository::getInstance()->get('key'));
    }

    public function testFlushClearsContextInstance(): void
    {
        Repository::getInstance()->add('key', 'value');
        $this->assertTrue(Repository::hasInstance());

        CoroutineContext::flush();

        // After flush, hasInstance returns false and a new call
        // to getInstance() returns a fresh empty instance
        $this->assertFalse(Repository::hasInstance());
        $this->assertNull(Repository::getInstance()->get('key'));
    }

    public function testAddAndGet(): void
    {
        Repository::getInstance()->add('key', 'val');

        $this->assertSame('val', Repository::getInstance()->get('key'));
    }

    public function testContextDataDoesNotAppearInRawCoroutineContext(): void
    {
        Repository::getInstance()->add('key', 'val');

        $this->assertNull(CoroutineContext::get('key'));
    }

    public function testRawCoroutineContextDataDoesNotAppearInContext(): void
    {
        CoroutineContext::set('key', 'val');

        $this->assertNull(Repository::getInstance()->get('key'));
    }

    public function testHasInstanceReturnsFalseWhenNeverAccessed(): void
    {
        $this->assertFalse(Repository::hasInstance());
    }

    public function testHasInstanceReturnsTrueAfterGetInstance(): void
    {
        Repository::getInstance();

        $this->assertTrue(Repository::hasInstance());
    }

    public function testHasInstanceDoesNotCreateInstance(): void
    {
        // First call should not create an instance
        $this->assertFalse(Repository::hasInstance());

        // Second call should still be false — no instance was created
        $this->assertFalse(Repository::hasInstance());
    }
}
