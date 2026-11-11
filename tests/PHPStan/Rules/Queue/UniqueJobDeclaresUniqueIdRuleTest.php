<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\UniqueJobDeclaresUniqueIdRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<UniqueJobDeclaresUniqueIdRule> */
class UniqueJobDeclaresUniqueIdRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(UniqueJobDeclaresUniqueIdRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/unique-jobs.php'], [
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\ParameterizedUniqueJobWithoutUniqueId has constructor parameters but does not declare uniqueId.\n    💡 Declare a public uniqueId() method or a public \$uniqueId property to identify distinct jobs.",
                66,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueUntilProcessingJob has constructor parameters but does not declare uniqueId.\n    💡 Declare a public uniqueId() method or a public \$uniqueId property to identify distinct jobs.",
                109,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\ParameterizedUniqueJobWithPrivateUniqueIdMethod declares uniqueId() but does not make it public.\n    💡 Make uniqueId() public so the unique lock can call it.",
                152,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\ParameterizedUniqueJobWithProtectedUniqueIdProperty declares \$uniqueId but does not make it public.\n    💡 Make \$uniqueId public so the unique lock can read it.",
                168,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
