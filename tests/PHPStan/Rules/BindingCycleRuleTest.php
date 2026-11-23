<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\BindingCycleRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<BindingCycleRule> */
class BindingCycleRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BindingCycleRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $method, string $abstract, string $concrete, int $line): array => [
            "{$method}() binds '{$abstract}' to Hypervel\\{$concrete}, which is an alias of '{$abstract}', so resolving it never ends.",
            $line,
            'Bind a closure that creates ' . class_basename($concrete) . ' with new instead of its class name.',
        ];

        $this->analyse([__DIR__ . '/data/binding-cycles.php'], [
            $error('singleton', 'auth', 'Auth\AuthManager', 25),
            $error('bind', 'cache', 'Cache\CacheManager', 26),
            $error('scoped', 'auth', 'Auth\AuthManager', 27),
            $error('singleton', 'cache', 'Cache\CacheManager', 28),
            $error('bind', 'auth', 'Auth\AuthManager', 39),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
