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
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithoutUniqueFor implements ShouldBeUnique but does not declare uniqueFor.\n    💡 Add a #[UniqueFor] attribute, or a public uniqueFor() method for a computed duration.",
                48,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueUntilProcessingJob implements ShouldBeUnique but does not declare uniqueFor.\n    💡 Add a #[UniqueFor] attribute, or a public uniqueFor() method for a computed duration.",
                109,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithProtectedUniqueForMethod declares uniqueFor() but does not make it public.\n    💡 Make uniqueFor() public so the unique lock can call it.",
                138,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithProtectedUniqueForProperty declares \$uniqueFor but does not make it public.\n    💡 Make \$uniqueFor public so the unique lock can read it, or use a #[UniqueFor] attribute.",
                147,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
