<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Validator;

class ValidationPrimaryAttributeTest extends TestCase
{
    public function testItPreservesTheFirstMatchingWildcard(): void
    {
        $validator = $this->validator(['items' => [['email' => 'a']]], [
            'items.*.email' => 'string',
            'items.0.*' => 'string',
        ]);

        $this->assertSame('items.*.email', $validator->getPrimaryAttribute('items.0.email'));
        $this->assertSame('missing', $validator->getPrimaryAttribute('missing'));
    }

    public function testItPreservesStrictMatchingForNumericAttributes(): void
    {
        $validator = $this->validator(['a'], ['*' => 'string']);

        // Hypervel attribute names are always strings, so the expanded "0" key belongs to its wildcard.
        $this->assertSame('*', $validator->getPrimaryAttribute('0'));
    }

    public function testItRefreshesPrimaryAttributesWhenRulesAreAdded(): void
    {
        $validator = $this->validator(['items' => [['email' => 'a']]], []);

        $this->assertSame('items.0.email', $validator->getPrimaryAttribute('items.0.email'));

        $validator->addRules(['items.*.email' => 'string']);

        $this->assertSame('items.*.email', $validator->getPrimaryAttribute('items.0.email'));
    }

    public function testItRefreshesPrimaryAttributesWhenRulesAreReplacedOrAppended(): void
    {
        $validator = $this->validator(['items' => [['email' => 'a', 'name' => 'b']]], []);

        $this->assertSame('items.0.email', $validator->getPrimaryAttribute('items.0.email'));

        $validator->setRules(['items.*.email' => 'string']);

        $this->assertSame('items.*.email', $validator->getPrimaryAttribute('items.0.email'));
        $this->assertSame('items.0.name', $validator->getPrimaryAttribute('items.0.name'));

        $validator->appendRules(['items.*.name' => 'string']);

        $this->assertSame('items.*.name', $validator->getPrimaryAttribute('items.0.name'));
    }

    public function testItRefreshesPrimaryAttributesWhenDataChanges(): void
    {
        $validator = $this->validator(['items' => [['email' => 'a']]], ['items.*.email' => 'string']);

        $this->assertSame('items.1.email', $validator->getPrimaryAttribute('items.1.email'));

        $validator->setData(['items' => [['email' => 'a'], ['email' => 'b']]]);

        $this->assertSame('items.*.email', $validator->getPrimaryAttribute('items.1.email'));
    }

    public function testSometimesPreservesItsWildcardPrecedenceEvenWhenNoRulesAreAdded(): void
    {
        $validator = $this->validator(['items' => [['email' => 'a']]], ['items.*.email' => 'string']);

        $this->assertSame('items.*.email', $validator->getPrimaryAttribute('items.0.email'));

        $validator->sometimes('items.0.*', 'required', fn () => false);

        $this->assertSame('items.0.*', $validator->getPrimaryAttribute('items.0.email'));
    }

    public function testCrossFieldRulesStillResolveNestedWildcards(): void
    {
        $validator = $this->validator(['groups' => [
            ['items' => [['email' => 'a', 'confirmation' => 'a']]],
            ['items' => [['email' => 'b', 'confirmation' => 'wrong']]],
        ]], ['groups.*.items.*.confirmation' => 'same:groups.*.items.*.email']);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'groups.1.items.0.confirmation' => ['Same' => ['groups.1.items.0.email']],
        ], $validator->failed());
    }

    private function validator(array $data, array $rules): Validator
    {
        return new class(new Translator(new ArrayLoader, 'en'), $data, $rules) extends Validator {
            /**
             * Get the primary attribute name.
             */
            public function getPrimaryAttribute(string $attribute): string
            {
                return parent::getPrimaryAttribute($attribute);
            }
        };
    }
}
