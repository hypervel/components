<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\RedisConnectionBoundMethodRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

use function sprintf;

/** @extends RuleTestCase<RedisConnectionBoundMethodRule> */
class RedisConnectionBoundMethodRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Redis connection method %s() must be called on a held Redis connection. Use Redis::withConnection() or Redis::withPinnedConnection().';

    protected function getRule(): Rule
    {
        return new RedisConnectionBoundMethodRule;
    }

    #[Test]
    public function itReportsConnectionBoundMethodsThatReachTheProxy(): void
    {
        $this->analyse([__DIR__ . '/data/redis-connection-bound-method.php'], [
            [sprintf(self::MESSAGE, 'client'), 36],
            [sprintf(self::MESSAGE, 'AUTH'), 37],
            [sprintf(self::MESSAGE, 'release'), 38],
            [sprintf(self::MESSAGE, 'getConnection'), 39],
        ]);
    }

    #[Test]
    public function itAllowsTheProxysOwnProtectedMethodCalls(): void
    {
        $this->analyse([__DIR__ . '/../../../src/redis/src/RedisProxy.php'], []);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
