<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Features;

use Closure;
use Hypervel\Foundation\Testing\Concerns\HandlesAttributes;
use Hypervel\Support\Fluent;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Orchestrator for running default and attribute-based testing features.
 *
 * Simplified version without annotation/pest support (not needed for Hypervel).
 */
final class TestingFeature
{
    /**
     * Resolve available testing features for Testbench.
     *
     * @param object $testCase
     * @param (\Closure():void)|null $default
     * @param (\Closure(\Closure):mixed)|null $attribute
     * @return \Hypervel\Support\Fluent<string, FeaturesCollection>
     */
    public static function run(
        object $testCase,
        ?Closure $default = null,
        ?Closure $attribute = null
    ): Fluent {
        /** @var \Hypervel\Support\Fluent<string, FeaturesCollection> $result */
        $result = new Fluent(['attribute' => new FeaturesCollection()]);

        // Inline memoization - replaces Orchestra's once() helper
        $defaultHasRun = false;
        $defaultResolver = static function () use ($default, &$defaultHasRun) {
            if ($defaultHasRun || $default === null) {
                return;
            }
            $defaultHasRun = true;

            return $default();
        };

        if ($testCase instanceof PHPUnitTestCase) {
            /** @phpstan-ignore-next-line */
            if ($testCase::usesTestingConcern(HandlesAttributes::class)) {
                $result['attribute'] = value($attribute, $defaultResolver);
            }
        }

        // Safe to call - flag prevents double execution
        $defaultResolver();

        return $result;
    }
}
