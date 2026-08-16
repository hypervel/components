<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\RequiresHashFieldExpiration;
use Hypervel\Tests\TestCase;
use RuntimeException;

class RequiresHashFieldExpirationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the trait's memoized static state on the subject class
        // so each test starts with a clean slate.
        RequiresHashFieldExpirationTestSubject::flushState();
    }

    public function testSkipsWhenPhpredisBelowMinimum(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.2.0';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/phpredis >= 6\.3\.0/');

        $subject->runCheck();
    }

    public function testSkipsWhenRedisVersionBelowMinimum(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '7.9.0'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Redis >= 8\.0\.0/');

        $subject->runCheck();
    }

    public function testSkipsWhenValkeyVersionBelowMinimum(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['valkey_version' => '8.0.0'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Valkey >= 9\.0\.0/');

        $subject->runCheck();
    }

    public function testValkeyVersionTakesPrecedenceOverRedisVersion(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';

        // Valkey 9.0.0 meets its minimum; redis_version is well below its
        // own minimum. Because valkey_version is checked first, this must
        // not skip — the trait uses valkey_version when present.
        $subject->stubServerInfo = [
            'valkey_version' => '9.0.0',
            'redis_version' => '7.0.0',
        ];

        $subject->runCheck();

        $this->assertSame(1, $subject->serverInfoCalls);
    }

    public function testDoesNotSkipWhenRequirementsMet(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '8.0.0'];

        $subject->runCheck();

        $this->assertSame(1, $subject->serverInfoCalls);
    }

    public function testMemoizesCheckAcrossCalls(): void
    {
        $subject = new RequiresHashFieldExpirationTestSubject;
        $subject->stubPhpredisVersion = '6.3.0';
        $subject->stubServerInfo = ['redis_version' => '8.0.0'];

        $subject->runCheck();
        $subject->runCheck();

        $this->assertSame(1, $subject->serverInfoCalls, 'Support check must be memoized after first run');
    }
}

class RequiresHashFieldExpirationTestSubject
{
    use RequiresHashFieldExpiration;

    public string $stubPhpredisVersion = '6.3.0';

    /** @var array<string, mixed> */
    public array $stubServerInfo = ['redis_version' => '8.0.0'];

    public int $serverInfoCalls = 0;

    public function runCheck(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
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
     * Override markTestSkipped() so we can assert on the skip behavior
     * without having PHPUnit actually mark this test as skipped.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}
