<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\ValidationAttribute;
use Hypervel\Data\Exceptions\CouldNotCreateValidationRule;
use Hypervel\Data\Support\Validation\ValidationRuleFactory;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class ValidationRuleFactoryTest extends TestCase
{
    public function testMapsEveryValidationAttributeByItsKeyword(): void
    {
        $expected = [];

        foreach (glob(dirname(__DIR__, 4) . '/src/data/src/Attributes/Validation/*.php') as $file) {
            $class = 'Hypervel\Data\Attributes\Validation\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->isSubclassOf(ValidationAttribute::class)) {
                $expected[$class::keyword()] = $class;
            }
        }

        $mapping = (new ReflectionClass(ValidationRuleFactory::class))->getConstant('MAPPING');
        ksort($expected);
        ksort($mapping);

        $this->assertSame($expected, $mapping);
    }

    public function testCreatesTheAttributeForARuleString(): void
    {
        $this->assertEquals(new Min('3'), (new ValidationRuleFactory)->create('min:3'));
    }

    public function testRejectsAnUnknownKeyword(): void
    {
        $this->expectException(CouldNotCreateValidationRule::class);
        $this->expectExceptionMessageIs('Could not create a validation rule for: `unknown:3`');

        (new ValidationRuleFactory)->create('unknown:3');
    }

    public function testCreatesThroughAnOverriddenMapping(): void
    {
        $factory = new class extends ValidationRuleFactory {
            /**
             * Map the min keyword to the max attribute.
             */
            protected function mapping(): array
            {
                return ['min' => Max::class];
            }
        };

        $this->assertEquals(new Max('3'), $factory->create('min:3'));
    }
}
