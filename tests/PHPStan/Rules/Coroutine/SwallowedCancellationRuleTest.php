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
        $swallowed = self::swallowed(...);

        $this->analyse([__DIR__ . '/data/swallowed-cancellations.php'], [
            $swallowed('a database query', 137),
            $swallowed('a wait', 143),
            $swallowed('a database query', 149),
            $swallowed('a Redis command', 155),
            $swallowed('a wait', 161),
            $swallowed('a database query', 167),
            $swallowed('a wait', 211),
            $swallowed('a wait', 217),
            $swallowed('a wait', 223),
            $swallowed('a wait', 237),
            $swallowed('a database query', 266),
            $swallowed('a database query', 278),
            $swallowed('a wait', 285),
            $swallowed('a database query', 308),
            $swallowed('a database query', 315),
            $swallowed('a database query', 322),
            $swallowed('a database query', 347),
            $swallowed('a database query', 353),
            $swallowed('a database query', 359),
            $swallowed('a database query', 365),
            $swallowed('a database query', 371),
            $swallowed('a database query', 377),
            $swallowed('a database query', 383),
            $swallowed('a database query', 389),
            $swallowed('a database query', 395),
            $swallowed('a database query', 401),
            $swallowed('a database query', 407),
            $swallowed('a wait', 424),
            $swallowed('a wait', 437),
            $swallowed('a database query', 443),
            $swallowed('a database query', 459),
            $swallowed('a database query', 465),
            [
                'Catching CanceledException misses cancellation carried by another exception, which the catch on line 481 handles as an ordinary failure. Check Coroutine::causedByCancellation() in that catch instead.',
                479,
                'A cleanup or callback failure in the try block can carry the cancellation as its previous exception.',
            ],
            $swallowed('a database query', 487),
            $swallowed('a database query', 497),
            $swallowed('a database query', 526),
            $swallowed('a Redis command', 554),
            $swallowed('a database query', 562),
            $swallowed('a database query', 569),
            $swallowed('a database query', 577),
            $swallowed('a database query', 585),
            $swallowed('a database query', 622),
            $swallowed('a wait', 630),
            $swallowed('a database query', 636),
            $swallowed('a database query', 646),
            $swallowed('a wait', 658),
            $swallowed('a database query', 695),
            $swallowed('a database query', 707),
            $swallowed('a database query', 727),
            $swallowed('a database query', 757),
        ]);
    }

    public function testConcurrencyWaitsAndPropertyReceivers(): void
    {
        $this->analyse([__DIR__ . '/data/swallowed-cancellation-waits.php'], [
            self::swallowed('a wait', 23),
            self::swallowed('a wait', 30),
            self::swallowed('a wait', 36),
            self::swallowed('a database query', 44),
            self::swallowed('a database query', 51),
        ]);
    }

    /**
     * Build the diagnostic for a catch swallowing the cancellation of the operation in a job.
     *
     * @return array{string, int, string}
     */
    private static function swallowed(string $operation, int $line): array
    {
        return [
            "This catch swallows the cancellation of {$operation} in its try block while running a job.",
            $line,
            'Rethrow the exception, or rethrow it when Coroutine::causedByCancellation() is true, so the canceled work stops.',
        ];
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
