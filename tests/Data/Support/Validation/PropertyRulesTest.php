<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Prohibited;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Support\Validation\PropertyRules;
use Hypervel\Tests\TestCase;

class PropertyRulesTest extends TestCase
{
    public function testCanAddRules(): void
    {
        $rules = PropertyRules::create()
            ->add(new Required)
            ->add(new Prohibited, new Min(0));

        $this->assertEquals([new Required, new Prohibited, new Min(0)], $rules->all());
    }

    public function testWillRemoveTheRuleIfANewVersionIsAdded(): void
    {
        $rules = PropertyRules::create()
            ->add(new Min(10))
            ->add(new Min(314));

        $this->assertEquals([new Min(314)], $rules->all());
    }

    public function testCanRemoveRulesByType(): void
    {
        $rules = PropertyRules::create()
            ->add(new Min(10))
            ->removeType(new Min(314));

        $this->assertSame([], $rules->all());
    }

    public function testCanRemoveRulesByClass(): void
    {
        $rules = PropertyRules::create()
            ->add(new Min(10))
            ->removeType(Min::class);

        $this->assertSame([], $rules->all());
    }

    public function testPrependsSeveralRulesInOrder(): void
    {
        $rules = PropertyRules::create(new StringType)
            ->prepend(new Required, new Nullable);

        $this->assertEquals([new Required, new Nullable, new StringType], $rules->all());
    }

    public function testAnyRequiringRuleReplacesEveryRequiringRule(): void
    {
        $rules = PropertyRules::create(new Required, new StringType)
            ->add(new RequiredIf('other', 'value'));

        $this->assertEquals([new StringType, new RequiredIf('other', 'value')], $rules->all());
        $this->assertTrue($rules->hasType(RequiredIf::class));
        $this->assertFalse($rules->hasType(Required::class));
    }
}
