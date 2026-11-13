<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Collectors\SwallowedCancellationCollector;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Rules\Coroutine\ExactCancellationCatchRule;
use Hypervel\PHPStan\Rules\Coroutine\SwallowedCancellationRule;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\CompositeRule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<CompositeRule> */
class SwallowedCancellationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // Both rules, so a catch the exact-catch rule reports gets exactly one diagnostic.
        return new CompositeRule([
            self::getContainer()->getByType(ExactCancellationCatchRule::class),
            self::getContainer()->getByType(SwallowedCancellationRule::class),
        ]);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(SwallowedCancellationCollector::class),
            self::getContainer()->getByType(WorkerStateCallCollector::class),
            self::getContainer()->getByType(WorkerStateClassCollector::class),
            self::getContainer()->getByType(WorkerStateParameterCollector::class),
        ];
    }

    public function testRule(): void
    {
        $swallowed = static fn (string $operation, int $line): array => [
            "This catch swallows the cancellation of {$operation} in its try block while running a job.",
            $line,
            'Rethrow the exception, or rethrow it when Coroutine::causedByCancellation() is true, so the canceled work stops.',
        ];

        $this->analyse([__DIR__ . '/data/swallowed-cancellations.php'], [
            $swallowed('a database query', 136),
            $swallowed('a wait', 142),
            $swallowed('a database query', 148),
            $swallowed('a Redis command', 154),
            $swallowed('a wait', 160),
            $swallowed('a database query', 166),
            $swallowed('a wait', 210),
            $swallowed('a wait', 216),
            $swallowed('a wait', 222),
            $swallowed('a wait', 236),
            $swallowed('a database query', 265),
            $swallowed('a database query', 277),
            $swallowed('a wait', 284),
            $swallowed('a database query', 307),
            $swallowed('a database query', 314),
            $swallowed('a database query', 321),
            $swallowed('a database query', 346),
            $swallowed('a database query', 352),
            $swallowed('a database query', 358),
            $swallowed('a database query', 364),
            $swallowed('a database query', 370),
            $swallowed('a database query', 376),
            $swallowed('a database query', 382),
            $swallowed('a database query', 388),
            $swallowed('a database query', 394),
            $swallowed('a database query', 400),
            $swallowed('a database query', 406),
            $swallowed('a wait', 423),
            $swallowed('a wait', 436),
            $swallowed('a database query', 442),
            $swallowed('a database query', 458),
            $swallowed('a database query', 464),
            [
                'Catching CanceledException misses cancellation carried by another exception, which the catch on line 480 handles as an ordinary failure. Check Coroutine::causedByCancellation() in that catch instead.',
                478,
                'A cleanup or callback failure in the try block can carry the cancellation as its previous exception.',
            ],
            $swallowed('a database query', 486),
            $swallowed('a database query', 496),
            $swallowed('a database query', 525),
            $swallowed('a Redis command', 553),
            $swallowed('a database query', 561),
            $swallowed('a database query', 568),
            $swallowed('a database query', 576),
            $swallowed('a database query', 584),
            $swallowed('a database query', 621),
            $swallowed('a wait', 629),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
