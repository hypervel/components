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

    private const string GENERATOR_TIP = 'A generator callback only runs once it is iterated, after the %s ends, so return the results instead of yielding them.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BorrowedResourceEscapeRule::class);
    }

    public function testRule(): void
    {
        $borrowed = static fn (string $subject, string $how, int $line): array => [
            $subject . ' ' . $how . ', so it can be used after the borrow ends.',
            $line,
            $how === 'is used in a generator callback'
                ? sprintf(self::GENERATOR_TIP, 'borrow')
                : self::BORROW_TIP . (str_starts_with($how, 'is captured') ? self::SPAWN_TIP : ''),
        ];
        $pinned = static fn (string $subject, string $how, int $line): array => [
            $subject . ' ' . $how . ', so it can be used after the pin ends.',
            $line,
            $how === 'is used in a generator callback'
                ? sprintf(self::GENERATOR_TIP, 'pin')
                : self::PIN_TIP . (str_starts_with($how, 'is captured') ? self::SPAWN_TIP : ''),
        ];
        $connection = 'The Redis connection borrowed by withConnection()';
        $client = 'The Redis client of the Redis connection borrowed by withConnection()';
        $captured = static fn (string $spawn, int $line): array => $borrowed($connection, "is captured by a closure passed to {$spawn}()", $line);

        $this->analyse([__DIR__ . '/data/borrowed-resources.php'], [
            $borrowed($connection, 'is returned', 71),
            $borrowed($client, 'is returned', 73),
            $borrowed($connection, 'is returned', 77),
            $borrowed('The SQS client of the SQS queue borrowed by withConnection()', 'is returned', 79),
            $borrowed('The Flysystem driver borrowed by withDriver()', 'is returned', 80),
            $borrowed('The filesystem client borrowed by withClient()', 'is returned', 81),
            $borrowed('The filesystem client borrowed by withClient()', 'is returned', 83),
            $borrowed($connection, 'is stored on a property', 92),
            $borrowed($client, 'is stored on a property', 93),
            $borrowed($connection, 'is stored on a property', 94),
            $borrowed($client, 'is stored in a by-reference variable', 95),
            $captured('go', 102),
            $captured('Coroutine::create', 104),
            $captured('parallel', 105),
            $captured('parallel', 108),
            $captured('Parallel::add', 110),
            $captured('Parallel::add', 111),
            $captured('Concurrency::run', 112),
            $captured('Coroutine::defer', 113),
            $captured('wait', 114),
            $captured('go', 125),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 138),
            $pinned('The read PDO taken inside withPinnedSession()', 'is returned', 140),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 141),
            $pinned('The PDO taken inside withPinnedSession()', 'is captured by a closure passed to go()', 145),
            $captured('Timer::after', 180),
            $captured('Concurrent::create', 181),
            $captured('Parallel::add', 182),
            $captured('wait', 186),
            $borrowed($connection, 'is returned', 199),
            $borrowed($connection, 'is returned', 201),
            $borrowed($connection, 'is stored on a property', 203),
            $borrowed($connection, 'is stored on a property', 204),
            $borrowed($connection, 'is stored on a property', 208),
            $borrowed($connection, 'is stored on a property', 213),
            $borrowed($connection, 'is stored on a property', 216),
            $borrowed($connection, 'is returned', 216),
            $captured('parallel', 219),
            $pinned('The PDO taken inside withPinnedSession()', 'is returned', 249),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 251),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 274),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 279),
            $pinned('The PDO taken inside withPinnedSession()', 'is stored on a property', 287),
            $borrowed($connection, 'is stored on a property', 336),
            $captured('go', 345),
            $captured('go', 350),
            $captured('go', 356),
            $borrowed($connection, 'is stored in a by-reference variable', 363),
            $borrowed($client, 'is stored on a property', 369),
            $borrowed($client, 'is captured by a closure passed to go()', 383),
            $borrowed($connection, 'is stored on a property', 387),
            $borrowed($client, 'is captured by a closure passed to go()', 396),
            $captured('go', 405),
            $borrowed($client, 'is captured by a closure passed to go()', 416),
            $borrowed($connection, 'is stored in a global variable', 571),
            $borrowed($client, 'is stored in a global variable', 574),
            $borrowed($client, 'is stored on a property', 578),
            $borrowed($connection, 'is stored in a global variable', 583),
            $borrowed($connection, 'is stored in a by-reference variable', 592),
            $borrowed($client, 'is stored on a property', 600),
            $borrowed($client, 'is stored on a property', 606),
            $borrowed($client, 'is stored on a property', 612),
            $borrowed($client, 'is captured by a closure passed to go()', 622),
            $borrowed($client, 'is captured by a closure passed to go()', 631),
            $borrowed($client, 'is captured by a closure passed to go()', 642),
            $borrowed($client, 'is stored in a global variable', 650),
            $borrowed($client, 'is returned', 661),
            $borrowed($client, 'is returned', 670),
            $borrowed($client, 'is captured by a closure passed to go()', 682),
            $borrowed($client, 'is returned', 694),
            $borrowed($client, 'is returned', 706),
            $borrowed($client, 'is returned', 718),
            $borrowed($client, 'is returned', 732),
            $captured('go', 742),
            $borrowed($client, 'is returned', 754),
            $borrowed($client, 'is returned', 766),
            $borrowed($client, 'is returned', 781),
            $borrowed($client, 'is returned', 793),
            $borrowed($client, 'is returned', 809),
            $borrowed($client, 'is returned', 822),
            $borrowed($client, 'is returned', 832),
            $borrowed($client, 'is stored on a property', 1167),
            $borrowed($client, 'is stored in a global variable', 1168),
            $borrowed($client, 'is stored in a by-reference variable', 1171),
            $borrowed($client, 'is stored on a property', 1175),
            $borrowed($client, 'is stored on a property', 1176),
            $borrowed($client, 'is stored on a property', 1177),
            $borrowed($client, 'is stored on a property', 1178),
            $captured('go', 1182),
            $captured('go', 1186),
            $borrowed($client, 'is stored on a property', 1190),
            $borrowed($client, 'is stored on a property', 1191),
            $borrowed($connection, 'is stored in a by-reference variable', 1195),
            $borrowed($connection, 'is stored in a global variable', 1199),
            $borrowed($connection, 'is stored in a global variable', 1201),
            $borrowed($connection, 'is stored in a global variable', 1204),
            $borrowed($connection, 'is stored in a global variable', 1209),
            $borrowed($client, 'is returned', 1218),
            $borrowed($client, 'is returned', 1268),
            $borrowed($client, 'is returned', 1282),
            $borrowed($connection, 'is stored in a global variable', 1288),
            $borrowed($client, 'is captured by a closure passed to go()', 1301),
            $borrowed($client, 'is returned', 1311),
            $borrowed($client, 'is captured by a closure passed to go()', 1320),
            $borrowed($client, 'is returned', 1331),
            $borrowed($client, 'is stored on a property', 1343),
            $borrowed($connection, 'is stored on a property', 1355),
            $borrowed($client, 'is returned', 1367),
            $borrowed($client, 'is returned', 1382),
            $borrowed($client, 'is returned', 1397),
            $borrowed($client, 'is returned', 1406),
            $borrowed($client, 'is returned', 1415),
            $borrowed($client, 'is returned', 1424),
            $borrowed($connection, 'is returned', 1439),
            $borrowed($connection, 'is returned', 1450),
            $borrowed($connection, 'is returned', 1461),
            $borrowed($connection, 'is returned', 1473),
            $borrowed($client, 'is returned', 1483),
            $borrowed($client, 'is returned', 1493),
            $borrowed($client, 'is returned', 1503),
            $borrowed($client, 'is returned', 1512),
            $borrowed($client, 'is returned', 1523),
            $borrowed($client, 'is stored on a property', 1538),
            $borrowed($client, 'is stored on a property', 1550),
            $borrowed($client, 'is stored on a property', 1564),
            $borrowed($client, 'is stored on a property', 1581),
            $borrowed($client, 'is returned', 1588),
            $borrowed($client, 'is returned', 1594),
            $borrowed($client, 'is returned', 1600),
            $borrowed($connection, 'is returned', 1610),
            $borrowed($client, 'is returned', 1616),
            $borrowed($connection, 'is used in a generator callback', 1621),
            $borrowed($connection, 'is used in a generator callback', 1624),
            $pinned('The PDO taken inside withPinnedSession()', 'is used in a generator callback', 1625),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
