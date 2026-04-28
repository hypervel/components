<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\RequiresAnyTagModeRedis;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * Unit tests for the RequiresAnyTagModeRedis trait's decision logic.
 *
 * Uses a local subject class that overrides the trait's two
 * environment-detection seams (detectedPhpredisVersion /
 * detectedServerInfo) so the version-comparison branches can be
 * exercised without hitting a real Redis server.
 */
class RequiresAnyTagModeRedisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the trait's memoized static state on the subject class
        // so each test starts with a clean slate.
        RequiresAnyTagModeRedisTestSubject::flushStaticState();
    }

    public function testSkipsWhenPhpredisBelowMinimum(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.2.0';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/phpredis >= 6\.3\.0/');

        $subject->runCheck();
    }

    public function testSkipsWhenRedisVersionBelowMinimum(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '7.9.0'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Redis >= 8\.0\.0/');

        $subject->runCheck();
    }

    public function testSkipsWhenValkeyVersionBelowMinimum(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['valkey_version' => '8.0.0'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Valkey >= 9\.0\.0/');

        $subject->runCheck();
    }

    public function testValkeyVersionTakesPrecedenceOverRedisVersion(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';

        // Valkey 9.0.0 meets its minimum; redis_version is well below its
        // own minimum. Because valkey_version is checked first, this must
        // NOT skip — the trait uses valkey_version when present.
        $subject->stubServerInfo = [
            'valkey_version' => '9.0.0',
            'redis_version' => '7.0.0',
        ];

        $subject->runCheck();

        $this->assertTrue(true, 'runCheck() must not throw when Valkey meets its minimum');
    }

    public function testDoesNotSkipWhenRequirementsMet(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '8.0.0'];

        $subject->runCheck();

        $this->assertTrue(true, 'runCheck() must not throw when requirements are met');
    }

    public function testMemoizesCheckAcrossCalls(): void
    {
        $subject = new RequiresAnyTagModeRedisTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '8.0.0'];

        $subject->runCheck();
        $subject->runCheck();

        $this->assertSame(1, $subject->serverInfoCalls, 'Support check must be memoized after first run');
    }
}

/**
 * Test subject that uses the trait and exposes the necessary seams.
 */
class RequiresAnyTagModeRedisTestSubject
{
    use RequiresAnyTagModeRedis;

    public string $stubPhpredisVersion = '6.3.0';

    /** @var array<string, mixed> */
    public array $stubServerInfo = ['redis_version' => '8.0.0'];

    public int $serverInfoCalls = 0;

    /**
     * Reset the trait's memoized static state. Traits copy static
     * properties into the using class, so self::$anyTagModeSupported
     * here refers to this class's own copy.
     */
    public static function flushStaticState(): void
    {
        self::$anyTagModeSupported = null;
        self::$anyTagModeSkipReason = '';
    }

    public function runCheck(): void
    {
        $this->skipIfAnyTagModeUnsupported();
    }

    protected function detectedPhpredisVersion(): string
    {
        return $this->stubPhpredisVersion;
    }

    protected function detectedServerInfo(): array
    {
        ++$this->serverInfoCalls;

        return $this->stubServerInfo;
    }

    /**
     * Override markTestSkipped() so we can assert on the skip behaviour
     * without having PHPUnit actually mark this test as skipped.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}
