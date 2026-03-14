<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Container\Container;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ParallelTestingTest extends TestCase
{
    public function testTokenReturnsFalseWhenNotRunningInParallel()
    {
        $parallelTesting = new ParallelTesting(new Container());

        // Without a token resolver or TEST_TOKEN env var, token() returns false
        $parallelTesting->resolveTokenUsing(fn () => false);

        $this->assertFalse($parallelTesting->token());
    }

    public function testTokenReturnsValueFromResolver()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveTokenUsing(fn () => '3');

        $this->assertSame('3', $parallelTesting->token());
    }

    public function testInParallelReturnsFalseWithoutToken()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveTokenUsing(fn () => false);

        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testInParallelReturnsTrueWithToken()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveTokenUsing(fn () => '1');

        $this->assertTrue($parallelTesting->inParallel());
    }

    public function testOptionReturnsFalseByDefault()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $this->assertFalse($parallelTesting->option('recreate_databases'));
        $this->assertFalse($parallelTesting->option('without_databases'));
    }

    public function testOptionUsesCustomResolver()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveOptionsUsing(fn (string $option) => $option === 'recreate_databases');

        $this->assertTrue($parallelTesting->option('recreate_databases'));
        $this->assertFalse($parallelTesting->option('without_databases'));
    }

    public function testOptionResolverCanBeReset()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveOptionsUsing(fn () => true);
        $this->assertTrue($parallelTesting->option('anything'));

        $parallelTesting->resolveOptionsUsing(null);
        $this->assertFalse($parallelTesting->option('anything'));
    }

    public function testSetUpTestCaseCallbacksNotCalledWithoutToken()
    {
        $parallelTesting = new ParallelTesting(new Container());
        $parallelTesting->resolveTokenUsing(fn () => false);

        $called = false;
        $parallelTesting->setUpTestCase(function () use (&$called) {
            $called = true;
        });

        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertFalse($called);
    }

    public function testSetUpTestCaseCallbacksCalledWithToken()
    {
        $parallelTesting = new ParallelTesting(new Container());
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $receivedToken = null;
        $receivedTestCase = null;
        $parallelTesting->setUpTestCase(function ($token, $testCase) use (&$receivedToken, &$receivedTestCase) {
            $receivedToken = $token;
            $receivedTestCase = $testCase;
        });

        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertSame('1', $receivedToken);
        $this->assertSame($this, $receivedTestCase);
    }

    public function testTearDownTestCaseCallbacksNotCalledWithoutToken()
    {
        $parallelTesting = new ParallelTesting(new Container());
        $parallelTesting->resolveTokenUsing(fn () => false);

        $called = false;
        $parallelTesting->tearDownTestCase(function () use (&$called) {
            $called = true;
        });

        $parallelTesting->callTearDownTestCaseCallbacks($this);

        $this->assertFalse($called);
    }

    public function testTearDownTestCaseCallbacksCalledWithToken()
    {
        $parallelTesting = new ParallelTesting(new Container());
        $parallelTesting->resolveTokenUsing(fn () => '2');

        $receivedToken = null;
        $receivedTestCase = null;
        $parallelTesting->tearDownTestCase(function ($token, $testCase) use (&$receivedToken, &$receivedTestCase) {
            $receivedToken = $token;
            $receivedTestCase = $testCase;
        });

        $parallelTesting->callTearDownTestCaseCallbacks($this);

        $this->assertSame('2', $receivedToken);
        $this->assertSame($this, $receivedTestCase);
    }

    public function testMultipleCallbacksAreCalledInOrder()
    {
        $parallelTesting = new ParallelTesting(new Container());
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $order = [];
        $parallelTesting->setUpTestCase(function () use (&$order) {
            $order[] = 'first';
        });
        $parallelTesting->setUpTestCase(function () use (&$order) {
            $order[] = 'second';
        });
        $parallelTesting->setUpTestCase(function () use (&$order) {
            $order[] = 'third';
        });

        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testCallbacksReceiveCorrectTokenValue()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $tokens = [];

        $parallelTesting->setUpTestCase(function ($token) use (&$tokens) {
            $tokens[] = $token;
        });

        $parallelTesting->resolveTokenUsing(fn () => '5');
        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $parallelTesting->resolveTokenUsing(fn () => '10');
        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertSame(['5', '10'], $tokens);
    }

    public function testTokenResolverCanBeReset()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $parallelTesting->resolveTokenUsing(fn () => '1');
        $this->assertSame('1', $parallelTesting->token());
        $this->assertTrue($parallelTesting->inParallel());

        $parallelTesting->resolveTokenUsing(fn () => false);
        $this->assertFalse($parallelTesting->token());
        $this->assertFalse($parallelTesting->inParallel());
    }
}
