<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\JobWithModelPropertyDeclaresSerializesModelsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<JobWithModelPropertyDeclaresSerializesModelsRule> */
class JobWithModelPropertyDeclaresSerializesModelsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(JobWithModelPropertyDeclaresSerializesModelsRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/serializes-models.php'], [
            // Hypervel: protected and private model state is serialized too.
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobWithProtectedModelProperty has model properties (\$product) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                62,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobWithModelPropertyWithoutSerializesModels has model properties (\$product) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                69,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobWithNullableModelPropertyWithoutSerializesModels has model properties (\$product) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                76,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobWithMultipleModelPropertiesWithoutSerializesModels has model properties (\$product, \$invoice) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                81,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobInheritingModelProperty has model properties (\$product) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                88,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobInheritingPrivateModelProperty has model properties (\$product) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                106,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobWithModelCollectionProperty has model properties (\$products) but does not use SerializesModels.\n    💡 Use the Hypervel\\Queue\\SerializesModels trait.",
                110,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
