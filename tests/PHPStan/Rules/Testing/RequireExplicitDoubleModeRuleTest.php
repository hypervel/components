<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Testing;

use Hypervel\PHPStan\Rules\Testing\RequireExplicitDoubleModeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function array_map;

/** @extends RuleTestCase<RequireExplicitDoubleModeRule> */
class RequireExplicitDoubleModeRuleTest extends RuleTestCase
{
    /**
     * Create the test-only Double rule.
     */
    protected function getRule(): Rule
    {
        return new RequireExplicitDoubleModeRule;
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/explicit-double-mode.php'], array_map(
            static fn (int $line): array => [
                'Choose strict() or passthru() before using this double.',
                $line,
                'For an intentionally lenient double, use @phpstan-ignore hypervel.implicitDoubleMode (reason).',
            ],
            [21, 39, 43, 48, 52, 55, 58, 60, 64],
        ));
    }
}
