<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoAuthHelperInRequestScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoAuthHelperInRequestScopeRule> */
class NoAuthHelperInRequestScopeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoAuthHelperInRequestScopeRule($this->createReflectionProvider());
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/no-auth-helper-in-request-scope-rule.php'], [
            ['Do not use auth()->check() in a class that has access to the request. Use $request->user() !== null instead.', 23],
            ['Do not use auth()->user() in a class that has access to the request. Use $request->user() instead.', 28],
            ['Do not use auth()->guest() in a class that has access to the request. Use $request->user() === null instead.', 33],
            ['Do not use auth()->check() in a class that has access to the request. Use $this->user() !== null instead.', 51],
            ['Do not use auth()->check() in a class that has access to the request. Use $request->user() !== null instead.', 64],
        ]);
    }

    // REMOVED: testFix; the replacement depends on request user resolvers, so the rule offers no fix.

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/phpstan-rules.neon',
        ];
    }
}
