<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\UniqueJobDeclaresUniqueForRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<UniqueJobDeclaresUniqueForRule> */
class UniqueJobDeclaresUniqueForRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(UniqueJobDeclaresUniqueForRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/unique-jobs.php'], [
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithoutUniqueFor implements ShouldBeUnique but does not declare uniqueFor.\n    💡 Add a #[UniqueFor] attribute, or a uniqueFor() method for a computed duration.",
                48,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueUntilProcessingJob implements ShouldBeUnique but does not declare uniqueFor.\n    💡 Add a #[UniqueFor] attribute, or a uniqueFor() method for a computed duration.",
                109,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
