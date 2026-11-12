<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\BorrowedResourceEscapeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<BorrowedResourceEscapeRule> */
class BorrowedResourceEscapeRuleTest extends RuleTestCase
{
    private const string BORROW_TIP = 'Finish using it inside the callback, which holds the borrow.';

    private const string PIN_TIP = 'Finish using it inside the callback, which holds the pin.';

    private const string SPAWN_TIP = ' A child coroutine that must use it can run through wait() with waitForChildTermination: true.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BorrowedResourceEscapeRule::class);
    }

    public function testRule(): void
    {
        $borrowed = static fn (string $subject, string $how, int $line): array => [
            $subject . ' ' . $how . ', so it can be used after the borrow ends.',
            $line,
            self::BORROW_TIP . (str_starts_with($how, 'is captured') ? self::SPAWN_TIP : ''),
        ];
        $pinned = static fn (string $subject, string $how, int $line): array => [
            $subject . ' ' . $how . ', so it can be used after the pin ends.',
            $line,
            self::PIN_TIP . (str_starts_with($how, 'is captured') ? self::SPAWN_TIP : ''),
        ];
        $connection = 'The Redis connection borrowed by withConnection()';
        $client = 'The Redis client of the Redis connection borrowed by withConnection()';
        $captured = static fn (string $spawn, int $line): array => $borrowed($connection, "is captured by a closure passed to {$spawn}()", $line);

        $this->analyse([__DIR__ . '/data/borrowed-resources.php'], [
            $borrowed($connection, 'is returned', 69),
            $borrowed($client, 'is returned', 71),
            $borrowed($connection, 'is returned', 75),
            $borrowed('The SQS client of the SQS queue borrowed by withConnection()', 'is returned', 77),
            $borrowed('The Flysystem driver borrowed by withDriver()', 'is returned', 78),
            $borrowed('The filesystem client borrowed by withClient()', 'is returned', 79),
            $borrowed('The filesystem client borrowed by withClient()', 'is returned', 81),
            $borrowed($connection, 'is stored on a property', 90),
            $borrowed($client, 'is stored on a property', 91),
            $borrowed($connection, 'is stored on a property', 92),
            $borrowed($client, 'is stored in a by-reference variable', 93),
            $captured('go', 100),
            $captured('Coroutine::create', 102),
            $captured('parallel', 103),
            $captured('parallel', 106),
            $captured('Parallel::add', 108),
            $captured('Parallel::add', 109),
            $captured('Concurrency::run', 110),
            $captured('Coroutine::defer', 111),
            $captured('wait', 112),
            $captured('go', 123),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 136),
            $pinned('The read PDO taken inside withPinnedSession()', 'is returned', 138),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 139),
            $pinned('The PDO taken inside withPinnedSession()', 'is captured by a closure passed to go()', 143),
            $captured('Timer::after', 178),
            $captured('Concurrent::create', 179),
            $captured('Parallel::add', 180),
            $captured('wait', 184),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
