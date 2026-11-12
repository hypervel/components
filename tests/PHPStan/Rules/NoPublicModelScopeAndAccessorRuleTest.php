<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ModelRuleHelper;
use Hypervel\PHPStan\Rules\NoPublicModelScopeAndAccessorRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoPublicModelScopeAndAccessorRule> */
class NoPublicModelScopeAndAccessorRuleTest extends RuleTestCase
{
    /**
     * Hypervel: upstream's cases run without the legacy scope rule, which otherwise reports the legacy scopes.
     */
    private bool $noLegacyModelScopes = false;

    protected function getRule(): Rule
    {
        return new NoPublicModelScopeAndAccessorRule(self::getContainer()->getByType(ModelRuleHelper::class), $this->noLegacyModelScopes);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/no-public-model-scope-and-accessor.php'], [
            ["Local query scope method 'scopePublic' should be declared as protected.", 19],
            ["Model accessor method 'lastName' should be declared as protected.", 32],
            ["Local query scope method 'publicWithAttribute' should be declared as protected.", 46],
            ["Local query scope method 'scopePrivate' should be declared as protected.", 62],
            ["Model accessor method 'privateAccessor' should be declared as protected.", 67],
        ]);
    }

    public function testFix(): void
    {
        // Hypervel: only the private methods are fixed, since a public method can have callers in other files.
        $this->fix(__DIR__ . '/data/no-public-model-scope-and-accessor.php', __DIR__ . '/data/no-public-model-scope-and-accessor-fixed.php');
    }

    public function testLegacyScopesConvertibleToAttributedScopesAreLeftToTheLegacyScopeRule(): void
    {
        $this->noLegacyModelScopes = true;

        $this->analyse([__DIR__ . '/data/model-scope-methods.php'], [
            ["Local query scope method 'scopeRead' should be declared as protected.", 42],
            ["Model accessor method 'summary' should be declared as protected.", 70],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
