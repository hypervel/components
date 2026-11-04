<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoAuthFacadeInRequestScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoAuthFacadeInRequestScopeRule> */
class NoAuthFacadeInRequestScopeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoAuthFacadeInRequestScopeRule;
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/no-auth-facade-in-request-scope-rule.php'], [
            ['Do not use Auth::check() in a class that has access to the request. Use $request->user() !== null instead.', 17],
            ['Do not use Auth::user() in a class that has access to the request. Use $request->user() instead.', 22],
            ['Do not use Auth::guest() in a class that has access to the request. Use $request->user() === null instead.', 27],
            ['Do not use Auth::check() in a class that has access to the request. Use $this->user() !== null instead.', 40],
            ['Do not use Auth::check() in a class that has access to the request. Use $request->user() !== null instead.', 53],
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
