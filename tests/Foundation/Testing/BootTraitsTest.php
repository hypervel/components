<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Foundation\Testing\Attributes\SetUp;
use Hypervel\Foundation\Testing\Attributes\TearDown;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

class BootTraitsTest extends TestCase
{
    public function testSetUpAndTearDownTraits()
    {
        $testCase = new TestCaseWithTrait('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->setUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->tearDown);
    }

    public function testSetUpAndTearDownWithAttributes()
    {
        $testCase = new TestCaseWithAttributeTrait('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->attributeSetUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->attributeTearDown);
    }

    public function testConventionalAndAttributeTraitsWorkTogether()
    {
        $testCase = new TestCaseWithBothTraits('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->setUp);
        $this->assertTrue($testCase->attributeSetUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->tearDown);
        $this->assertTrue($testCase->attributeTearDown);
    }
}

class TestCaseWithTrait extends FoundationTestCase
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

class TestCaseWithAttributeTrait extends FoundationTestCase
{
    use TestTraitWithAttributes;

    public function foo(): void
    {
    }
}

class TestCaseWithBothTraits extends FoundationTestCase
{
    use TestTrait;
    use TestTraitWithAttributes;

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

trait TestTraitWithAttributes
{
    public bool $attributeSetUp = false;

    public bool $attributeTearDown = false;

    #[SetUp]
    public function initializeSearch()
    {
        $this->attributeSetUp = true;
    }

    #[TearDown]
    public function cleanUpSearch()
    {
        $this->attributeTearDown = true;
    }
}
