<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Container\Container;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ParallelTestingTest extends TestCase
{
    private mixed $originalParallelTesting;

    private mixed $originalTestToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;
        $this->originalTestToken = $_SERVER['TEST_TOKEN'] ?? null;

        unset($_SERVER['TEST_TOKEN']);
    }

    protected function tearDown(): void
    {
        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        if ($this->originalTestToken === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $this->originalTestToken;
        }

        parent::tearDown();
    }

    public function testTokenReturnsFalseWhenNotRunningInParallel()
    {
        $parallelTesting = new ParallelTesting(new Container());

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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => false);

        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testInParallelReturnsFalseWithoutServerVariable()
    {
        $parallelTesting = new ParallelTesting(new Container());

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testInParallelReturnsTrueWithTokenAndServerVariable()
    {
        $parallelTesting = new ParallelTesting(new Container());

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
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

    public function testSetUpTestCaseCallbacksNotCalledWithoutParallelTesting()
    {
        $parallelTesting = new ParallelTesting(new Container());

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
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

    public function testTearDownTestCaseCallbacksNotCalledWithoutParallelTesting()
    {
        $parallelTesting = new ParallelTesting(new Container());

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;

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

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;

        $parallelTesting->resolveTokenUsing(fn () => '1');
        $this->assertSame('1', $parallelTesting->token());
        $this->assertTrue($parallelTesting->inParallel());

        $parallelTesting->resolveTokenUsing(fn () => false);
        $this->assertFalse($parallelTesting->token());
        $this->assertFalse($parallelTesting->inParallel());
    }

    #[DataProvider('allCallbackTypes')]
    public function testAllCallbackTypesFireWhenInParallel(string $callback, array $callerArgs)
    {
        $parallelTesting = new ParallelTesting(new Container());
        $caller = 'call' . ucfirst($callback) . 'Callbacks';

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;

        $state = false;
        $parallelTesting->{$caller}(...$callerArgs);
        $this->assertFalse($state);

        $parallelTesting->{$callback}(function ($token) use (&$state) {
            $this->assertSame('1', (string) $token);
            $state = true;
        });

        $parallelTesting->{$caller}(...$callerArgs);
        $this->assertFalse($state);

        $parallelTesting->resolveTokenUsing(fn () => '1');

        $parallelTesting->{$caller}(...$callerArgs);
        $this->assertTrue($state);
    }

    public static function allCallbackTypes(): array
    {
        return [
            'setUpProcess' => ['setUpProcess', []],
            'setUpTestCase' => ['setUpTestCase', [new stdClass()]],
            'setUpTestDatabase' => ['setUpTestDatabase', ['test_db']],
            'setUpTestDatabaseBeforeMigrating' => ['setUpTestDatabaseBeforeMigrating', ['test_db']],
            'tearDownTestCase' => ['tearDownTestCase', [new stdClass()]],
            'tearDownProcess' => ['tearDownProcess', []],
        ];
    }
}
