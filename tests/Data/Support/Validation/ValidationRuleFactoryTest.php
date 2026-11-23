<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\ValidationAttribute;
use Hypervel\Data\Exceptions\CouldNotCreateValidationRule;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Data\Support\Validation\ValidationRuleFactory;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rule;
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

    public function testCreatesMembershipAttributesWithoutLosingNormalizedValues(): void
    {
        foreach (['in' => 'in', 'notIn' => 'not_in', 'contains' => 'contains', 'doesntContain' => 'doesnt_contain'] as $method => $keyword) {
            $attribute = (new ValidationRuleFactory)->create(Rule::{$method}([false, 1, null, 1.25]));

            $this->assertSame([$keyword, '0', '1', null, '1.25'], $attribute->getRule(ValidationPath::create())->toArray());
        }
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
