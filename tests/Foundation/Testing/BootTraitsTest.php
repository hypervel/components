<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class BootTraitsTest extends TestCase
{
    use TestTrait;

    public function testSetUpTraits()
    {
        $testCase = new TestCaseWithTrait('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->setUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->tearDown);
    }
}

/**
 * @internal
 * @coversNothing
 */
class TestCaseWithTrait extends TestbenchTestCase
{
    use TestTrait;

    /**
     * Dummy test method required for setUpTraits() to work.
     *
     * PHPUnit TestCase expects the named test method to exist, and
     * AttributeParser reflects on it to check for database attributes.
     */
    public function foo(): void
    {
    }
}

trait TestTrait
{
    public bool $setUp = false;

    public bool $tearDown = false;

    public function setUpTestTrait()
    {
        $this->setUp = true;
    }

    public function tearDownTestTrait()
    {
        $this->tearDown = true;
    }
}
