<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Testbench\PHPUnit\TestCase as TestbenchTestCase;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\Exception\InvalidCountException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

class InteractsWithMockeryTest extends TestCase
{
    /**
     * @param class-string<MockeryLifecycleTestCase> $testCaseClass
     */
    #[DataProvider('caseProvider')]
    public function testBaseCaseVerifiesUnmetExpectations(string $testCaseClass): void
    {
        $testCase = new $testCaseClass('placeholder');
        m::mock()->shouldReceive('expected')->once();

        try {
            $testCase->finish();
            $this->fail('Expected Mockery verification to fail.');
        } catch (InvalidCountException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull((new ReflectionProperty(m::class, '_container'))->getValue());
    }

    /**
     * @param class-string<MockeryLifecycleTestCase> $testCaseClass
     */
    #[DataProvider('caseProvider')]
    public function testBaseCaseAddsMockeryExpectationsToItsAssertionCount(string $testCaseClass): void
    {
        $testCase = new $testCaseClass('placeholder');
        $mock = m::mock();
        $mock->shouldReceive('expected')->once();
        $mock->expected();

        $testCase->finish();

        $this->assertSame(1, $testCase->assertionCount());
    }

    /**
     * @return iterable<string, array{class-string<MockeryLifecycleTestCase>}>
     */
    public static function caseProvider(): iterable
    {
        yield 'components' => [ComponentsMockeryTestCase::class];
        yield 'testbench' => [TestbenchMockeryTestCase::class];
        yield 'foundation' => [FoundationMockeryTestCase::class];
    }

    public function testComponentsBaseCaseStillVerifiesMockeryWhenExceptionCleanupFails(): void
    {
        $testCase = new ComponentsMockeryTestCase('placeholder');
        $testCase->failExceptionCleanup = true;
        m::mock()->shouldReceive('expected')->once();

        try {
            $testCase->finish();
            $this->fail('Expected exception cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('exception cleanup failed', $exception->getMessage());
        }

        $this->assertNull((new ReflectionProperty(m::class, '_container'))->getValue());
    }
}

interface MockeryLifecycleTestCase
{
    public function finish(): void;

    public function assertionCount(): int;
}

class ComponentsMockeryTestCase extends TestCase implements MockeryLifecycleTestCase
{
    public bool $failExceptionCleanup = false;

    public function placeholder(): void
    {
    }

    public function finish(): void
    {
        $this->tearDown();
    }

    public function assertionCount(): int
    {
        return $this->numberOfAssertionsPerformed();
    }

    protected function flushExceptionHandlerState(): void
    {
        if ($this->failExceptionCleanup) {
            throw new RuntimeException('exception cleanup failed');
        }

        parent::flushExceptionHandlerState();
    }
}

class TestbenchMockeryTestCase extends TestbenchTestCase implements MockeryLifecycleTestCase
{
    public function placeholder(): void
    {
    }

    public function finish(): void
    {
        $this->tearDown();
    }

    public function assertionCount(): int
    {
        return $this->numberOfAssertionsPerformed();
    }
}

class FoundationMockeryTestCase extends FoundationTestCase implements MockeryLifecycleTestCase
{
    public function placeholder(): void
    {
    }

    public function finish(): void
    {
        $this->tearDown();
    }

    public function assertionCount(): int
    {
        return $this->numberOfAssertionsPerformed();
    }

    protected function withoutBootingFramework(): bool
    {
        return true;
    }
}
