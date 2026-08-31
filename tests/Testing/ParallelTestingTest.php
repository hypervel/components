<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Container\Container;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use TypeError;

class ParallelTestingTest extends TestCase
{
    private mixed $originalParallelTesting;

    private bool $hadServerTestToken;

    private mixed $originalTestToken;

    private bool $hadEnvironmentTestToken;

    private mixed $originalEnvironmentTestToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;
        $this->hadServerTestToken = array_key_exists('TEST_TOKEN', $_SERVER);
        $this->originalTestToken = $_SERVER['TEST_TOKEN'] ?? null;
        $this->hadEnvironmentTestToken = array_key_exists('TEST_TOKEN', $_ENV);
        $this->originalEnvironmentTestToken = $_ENV['TEST_TOKEN'] ?? null;

        unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN']);
    }

    protected function tearDown(): void
    {
        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        if ($this->hadServerTestToken) {
            $_SERVER['TEST_TOKEN'] = $this->originalTestToken;
        } else {
            unset($_SERVER['TEST_TOKEN']);
        }

        if ($this->hadEnvironmentTestToken) {
            $_ENV['TEST_TOKEN'] = $this->originalEnvironmentTestToken;
        } else {
            unset($_ENV['TEST_TOKEN']);
        }

        parent::tearDown();
    }

    public function testTokenReturnsFalseWhenNotRunningInParallel(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $parallelTesting->resolveTokenUsing(fn () => false);

        $this->assertFalse($parallelTesting->token());
    }

    public function testTokenReturnsValueFromResolver(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $parallelTesting->resolveTokenUsing(fn () => '3');

        $this->assertSame('3', $parallelTesting->token());
    }

    public function testTokenCastsScalarServerFallbacks(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $_SERVER['TEST_TOKEN'] = 0;
        $this->assertSame('0', $parallelTesting->token());

        $_SERVER['TEST_TOKEN'] = true;
        $this->assertSame('1', $parallelTesting->token());
    }

    public function testTokenRejectsAbsentAndNonScalarServerFallbacks(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $this->assertFalse($parallelTesting->token());

        $_SERVER['TEST_TOKEN'] = ['invalid'];
        $this->assertFalse($parallelTesting->token());
    }

    public function testTokenDoesNotCoerceResolverResults(): void
    {
        $parallelTesting = new ParallelTesting(new Container);
        $parallelTesting->resolveTokenUsing(fn (): int => 1);

        $this->expectException(TypeError::class);

        $parallelTesting->token();
    }

    public function testProcessTokenSanitizesFilesystemIdentityAndPrefersServerValues(): void
    {
        $_SERVER['TEST_TOKEN'] = 'server/token:one';
        $_ENV['TEST_TOKEN'] = 'environment-token';

        $this->assertSame('server_token_one', ParallelTesting::processToken());
        $this->assertStringContainsString('/hypervel-test-server_token_one-', ParallelTesting::tempDir());
    }

    #[DataProvider('processTokenValues')]
    public function testProcessTokenNormalizesScalarValues(mixed $token, ?string $expected): void
    {
        $_SERVER['TEST_TOKEN'] = $token;

        $this->assertSame($expected, ParallelTesting::processToken());
    }

    public static function processTokenValues(): iterable
    {
        yield 'integer zero' => [0, '0'];
        yield 'integer' => [7, '7'];
        yield 'boolean true' => [true, '1'];
        yield 'boolean false' => [false, null];
        yield 'empty string' => ['', null];
        yield 'array' => [['invalid'], null];
        yield 'object' => [new stdClass, null];
    }

    public function testProcessTokenUsesEnvironmentFallbackAndDefaultsWhenAbsent(): void
    {
        $_ENV['TEST_TOKEN'] = 'environment/token';

        $this->assertSame('environment_token', ParallelTesting::processToken());

        unset($_ENV['TEST_TOKEN']);

        $this->assertNull(ParallelTesting::processToken());
        $this->assertStringContainsString('/hypervel-test-default-', ParallelTesting::tempDir());
    }

    public function testInParallelReturnsFalseWithoutToken(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => false);

        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testInParallelReturnsFalseWithoutServerVariable(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testInParallelReturnsTrueWithTokenAndServerVariable(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $this->assertTrue($parallelTesting->inParallel());
    }

    public function testOptionReturnsFalseByDefault(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $this->assertFalse($parallelTesting->option('recreate_databases'));
        $this->assertFalse($parallelTesting->option('without_databases'));
    }

    public function testOptionUsesCustomResolver(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $parallelTesting->resolveOptionsUsing(fn (string $option) => $option === 'recreate_databases');

        $this->assertTrue($parallelTesting->option('recreate_databases'));
        $this->assertFalse($parallelTesting->option('without_databases'));
    }

    public function testOptionResolverCanBeReset(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $parallelTesting->resolveOptionsUsing(fn () => true);
        $this->assertTrue($parallelTesting->option('anything'));

        $parallelTesting->resolveOptionsUsing(null);
        $this->assertFalse($parallelTesting->option('anything'));
    }

    public function testSetUpTestCaseCallbacksNotCalledWithoutParallelTesting(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $called = false;
        $parallelTesting->setUpTestCase(function () use (&$called) {
            $called = true;
        });

        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertFalse($called);
    }

    public function testSetUpTestCaseCallbacksCalledWithToken(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

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

    public function testTearDownTestCaseCallbacksNotCalledWithoutParallelTesting(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $called = false;
        $parallelTesting->tearDownTestCase(function () use (&$called) {
            $called = true;
        });

        $parallelTesting->callTearDownTestCaseCallbacks($this);

        $this->assertFalse($called);
    }

    public function testTearDownTestCaseCallbacksCalledWithToken(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

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

    public function testMultipleCallbacksAreCalledInOrder(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

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

    public function testCallbacksReceiveCorrectTokenValue(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

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

    public function testTokenResolverCanBeReset(): void
    {
        $parallelTesting = new ParallelTesting(new Container);

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;

        $parallelTesting->resolveTokenUsing(fn () => '1');
        $this->assertSame('1', $parallelTesting->token());
        $this->assertTrue($parallelTesting->inParallel());

        $parallelTesting->resolveTokenUsing(fn () => false);
        $this->assertFalse($parallelTesting->token());
        $this->assertFalse($parallelTesting->inParallel());
    }

    public function testTearDownProcessCallbacksContinueAfterFailuresAndThrowTheFirstFailure(): void
    {
        $parallelTesting = new ParallelTesting(new Container);
        $callbacks = [];

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => '4');

        $parallelTesting->tearDownProcess(function (string $token) use (&$callbacks): never {
            $callbacks[] = "first:{$token}";

            throw new RuntimeException('first failure');
        });
        $parallelTesting->tearDownProcess(function (string $token) use (&$callbacks): void {
            $callbacks[] = "second:{$token}";
        });
        $parallelTesting->tearDownProcess(function (string $token) use (&$callbacks): never {
            $callbacks[] = "third:{$token}";

            throw new RuntimeException('third failure');
        });

        try {
            $parallelTesting->callTearDownProcessCallbacks();
            $this->fail('The teardown exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('first failure', $exception->getMessage());
        }

        $this->assertSame(['first:4', 'second:4', 'third:4'], $callbacks);
    }

    public function testTearDownTestCaseCallbacksContinueAfterFailuresAndThrowTheFirstFailure(): void
    {
        $parallelTesting = new ParallelTesting(new Container);
        $callbacks = [];

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => '6');

        $parallelTesting->tearDownTestCase(function (string $token, mixed $testCase) use (&$callbacks): never {
            $this->assertSame($this, $testCase);
            $callbacks[] = "first:{$token}";

            throw new RuntimeException('first failure');
        });
        $parallelTesting->tearDownTestCase(function (string $token, mixed $testCase) use (&$callbacks): void {
            $this->assertSame($this, $testCase);
            $callbacks[] = "second:{$token}";
        });

        try {
            $parallelTesting->callTearDownTestCaseCallbacks($this);
            $this->fail('The teardown exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('first failure', $exception->getMessage());
        }

        $this->assertSame(['first:6', 'second:6'], $callbacks);
    }

    public function testSetUpCallbacksRemainFailFast(): void
    {
        $parallelTesting = new ParallelTesting(new Container);
        $callbacks = [];

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;
        $parallelTesting->resolveTokenUsing(fn () => '8');

        $parallelTesting->setUpProcess(function () use (&$callbacks): void {
            $callbacks[] = 'first';
        });
        $parallelTesting->setUpProcess(function () use (&$callbacks): never {
            $callbacks[] = 'second';

            throw new RuntimeException('setup failed');
        });
        $parallelTesting->setUpProcess(function () use (&$callbacks): void {
            $callbacks[] = 'third';
        });

        try {
            $parallelTesting->callSetUpProcessCallbacks();
            $this->fail('The setup exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('setup failed', $exception->getMessage());
        }

        $this->assertSame(['first', 'second'], $callbacks);
    }

    #[DataProvider('allCallbackTypes')]
    public function testAllCallbackTypesFireWhenInParallel(string $callback, array $callerArgs): void
    {
        $parallelTesting = new ParallelTesting(new Container);
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
            'setUpTestCase' => ['setUpTestCase', [new stdClass]],
            'setUpTestDatabase' => ['setUpTestDatabase', ['test_db']],
            'setUpTestDatabaseBeforeMigrating' => ['setUpTestDatabaseBeforeMigrating', ['test_db']],
            'tearDownTestCase' => ['tearDownTestCase', [new stdClass]],
            'tearDownProcess' => ['tearDownProcess', []],
        ];
    }
}
