<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Features;

use Closure;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\Concerns\HandlesAttributes;
use Hypervel\Testbench\Pest\WithPest;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * @internal
 */
final class TestingFeature
{
    /**
     * Resolve available testing features for Testbench.
     *
     * @param null|(Closure():void) $default
     * @param null|(Closure():void) $annotation
     * @param null|(Closure():mixed) $attribute
     * @param null|(Closure(null|Closure):mixed) $pest
     * @return Fluent<array-key, mixed>
     */
    public static function run(
        object $testCase,
        ?Closure $default = null,
        ?Closure $annotation = null,
        ?Closure $attribute = null,
        ?Closure $pest = null
    ): Fluent {
        /** @var Fluent<string, FeaturesCollection> $result */
        $result = new Fluent(['attribute' => new FeaturesCollection()]);

        $defaultResolver = self::once($default);

        if ($testCase instanceof PHPUnitTestCase) {
            /* @phpstan-ignore staticMethod.notFound */
            if ($testCase::usesTestingConcern(HandlesAttributes::class)) {
                $result['attribute'] = value($attribute, $defaultResolver);
            }
        }

        if (
            $testCase instanceof PHPUnitTestCase
            && $pest instanceof Closure
            && $testCase::usesTestingConcern(WithPest::class) /* @phpstan-ignore staticMethod.notFound, class.notFound */
        ) {
            value($pest, $defaultResolver);
        }

        $defaultResolver();

        return $result;
    }

    /**
     * Wrap a callback so it only executes once.
     *
     * @param null|(Closure():mixed) $callback
     * @return Closure():mixed
     */
    private static function once(?Closure $callback): Closure
    {
        $called = false;
        $response = null;

        return static function () use ($callback, &$called, &$response) {
            if (! $called) {
                $called = true;
                $response = value($callback);
            }

            return $response;
        };
    }
}
