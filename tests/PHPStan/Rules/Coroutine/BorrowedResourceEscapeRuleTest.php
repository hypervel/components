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
            $borrowed($connection, 'is returned', 197),
            $borrowed($connection, 'is returned', 199),
            $borrowed($connection, 'is stored on a property', 201),
            $borrowed($connection, 'is stored on a property', 202),
            $borrowed($connection, 'is stored on a property', 206),
            $borrowed($connection, 'is stored on a property', 211),
            $borrowed($connection, 'is stored on a property', 214),
            $borrowed($connection, 'is returned', 214),
            $captured('parallel', 217),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 247),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 249),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 272),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 277),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 285),
            $borrowed($connection, 'is stored on a property', 334),
            $captured('go', 343),
            $captured('go', 348),
            $captured('go', 354),
            $borrowed($connection, 'is stored in a by-reference variable', 361),
            $borrowed($client, 'is stored on a property', 367),
            $borrowed($client, 'is captured by a closure passed to go()', 381),
            $borrowed($connection, 'is stored on a property', 385),
            $borrowed($client, 'is captured by a closure passed to go()', 394),
            $captured('go', 403),
            $borrowed($client, 'is captured by a closure passed to go()', 414),
            $borrowed($connection, 'is stored in a global variable', 569),
            $borrowed($client, 'is stored in a global variable', 572),
            $borrowed($client, 'is stored on a property', 576),
            $borrowed($connection, 'is stored in a global variable', 581),
            $borrowed($connection, 'is stored in a by-reference variable', 590),
            $borrowed($client, 'is stored on a property', 598),
            $borrowed($client, 'is stored on a property', 604),
            $borrowed($client, 'is stored on a property', 610),
            $borrowed($client, 'is captured by a closure passed to go()', 620),
            $borrowed($client, 'is captured by a closure passed to go()', 629),
            $borrowed($client, 'is captured by a closure passed to go()', 640),
            $borrowed($client, 'is stored in a global variable', 648),
            $borrowed($client, 'is returned', 659),
            $borrowed($client, 'is returned', 668),
            $borrowed($client, 'is captured by a closure passed to go()', 680),
            $borrowed($client, 'is returned', 692),
            $borrowed($client, 'is returned', 704),
            $borrowed($client, 'is returned', 716),
            $borrowed($client, 'is returned', 730),
            $captured('go', 740),
            $borrowed($client, 'is returned', 752),
            $borrowed($client, 'is stored on a property', 984),
            $borrowed($client, 'is stored in a global variable', 985),
            $borrowed($client, 'is stored in a by-reference variable', 988),
            $borrowed($client, 'is stored on a property', 992),
            $borrowed($client, 'is stored on a property', 993),
            $borrowed($client, 'is stored on a property', 994),
            $borrowed($client, 'is stored on a property', 995),
            $captured('go', 999),
            $captured('go', 1003),
            $borrowed($client, 'is stored on a property', 1007),
            $borrowed($client, 'is stored on a property', 1008),
            $borrowed($connection, 'is stored in a by-reference variable', 1012),
            $borrowed($connection, 'is stored in a global variable', 1016),
            $borrowed($connection, 'is stored in a global variable', 1018),
            $borrowed($connection, 'is stored in a global variable', 1021),
            $borrowed($connection, 'is stored in a global variable', 1026),
            $borrowed($client, 'is returned', 1035),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
