<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ModelAppendsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ModelAppendsRule> */
class ModelAppendsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ModelAppendsRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/ModelAppends.php'], [
            ["Property 'non_existent' does not exist in model.", 15],
            ["Property 'email' is not a computed property, remove from \$appends.", 16],
            // Hypervel: an appended column with a get accessor serializes through it, so line 17 is valid.
        ]);
    }

    public function testHypervelDeclarationsAndComputedProperties(): void
    {
        $this->analyse([__DIR__ . '/data/model-appends-hypervel.php'], [
            ["Property 'missing' does not exist in model.", 15],
            ["Property 'email' is not a computed property, remove from #[Appends].", 16],
            ["Property 'password' is not a computed property, remove from \$appends.", 23],
            ["Property 'backed_enum' is not a computed property, remove from \$appends.", 24],
            ["Property 'name' is not a computed property, remove from \$appends.", 51],
            ["Property 'full_name' does not exist in model.", 90],
            ["Property 'trait_name' does not exist in model.", 99],
            ["Property 'shared_missing' does not exist in model.", 122],
            ["Property 'email_verified_at' is not a computed property, remove from \$appends.", 129],
            ["Property 'missing_spread' does not exist in model.", 153],
            ["Property 'missing_item' does not exist in model.", 154],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
