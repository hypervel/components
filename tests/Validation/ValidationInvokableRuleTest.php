<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\DataAwareRule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\InvokableValidationRule;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ValidationInvokableRuleTest extends TestCase
{
    public function testItCanPass()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->messages()->messages());
    }

    public function testItCanFail()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail("The {$attribute} attribute is not 'foo'. Got '{$value}' instead.");
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                "The foo attribute is not 'foo'. Got 'bar' instead.",
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanReturnMultipleErrorMessages()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('Error message 1.');
                $fail('Error message 2.');
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                'Error message 1.',
                'Error message 2.',
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanTranslateMessages()
    {
        $trans = $this->getArrayTranslator();
        $trans->addLines(['validation.translated-error' => 'Translated error message.'], 'en');
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('validation.translated-error')->translate();
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                'Translated error message.',
            ],
        ], $validator->messages()->messages());
    }

    public function testItPerformsReplacementsWhenTranslating()
    {
        $trans = $this->getArrayTranslator();
        $trans->addLines(['validation.translated-error' => 'attribute: :attribute input: :input position: :position index: :index baz: :baz'], 'en');
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                if ($value !== null) {
                    $fail('validation.translated-error')->translate([
                        'baz' => 'xxxx',
                    ]);
                }
            }
        };

        $validator = new Validator($trans, ['foo' => [null, 'bar']], ['foo.*' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo.1' => [
                'attribute: foo.1 input: bar position: 2 index: 1 baz: xxxx',
            ],
        ], $validator->messages()->messages());
    }

    public function testItLooksForLanguageFileCustomisations()
    {
        $trans = $this->getArrayTranslator();
        $trans->addLines(['validation.translated-error' => 'attribute: :attribute'], 'en');
        $trans->addLines(['validation.attributes.foo' => 'email address'], 'en');
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                if ($value !== null) {
                    $fail('validation.translated-error')->translate();
                }
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                'attribute: email address',
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanSpecifyLocaleWhenTranslating()
    {
        $trans = $this->getArrayTranslator();
        $trans->addLines(['validation.translated-error' => 'English'], 'en');
        $trans->addLines(['validation.translated-error' => 'French'], 'fr');
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('validation.translated-error')->translate([], 'en');
                $fail('validation.translated-error')->translate([], 'fr');
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                'English',
                'French',
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanAccessDataDuringValidation()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule, DataAwareRule {
            public $data = [];

            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate($attribute, $value, $fail): void
            {
                if ($this->data === []) {
                    $fail('xxxx');
                }
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar', 'bar' => 'baz'], ['foo' => $rule]);

        $this->assertTrue($validator->passes());
        $this->assertSame([
            'foo' => 'bar',
            'bar' => 'baz',
        ], $rule->data);
    }

    public function testItCanAccessValidatorDuringValidation()
    {
        $trans = $this->getArrayTranslator();

        $rule = new class implements ValidationRule, ValidatorAwareRule {
            public $validator;

            public function setValidator(ValidatorContract $validator): static
            {
                $this->validator = $validator;

                return $this;
            }

            public function validate($attribute, $value, $fail): void
            {
                if ($this->validator === null) {
                    $fail('xxxx');
                }
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar', 'bar' => 'baz'], ['foo' => $rule]);

        $this->assertTrue($validator->passes());
        $this->assertSame($validator, $rule->validator);
    }

    public function testItCanBeExplicit()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public $implicit = false;

            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $validator = new Validator($trans, ['foo' => ''], ['foo' => $rule]);

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->messages()->messages());
    }

    public function testItCanBeImplicit()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public $implicit = true;

            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $validator = new Validator($trans, ['foo' => ''], ['foo' => $rule]);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'foo' => [
                'xxxx',
            ],
        ], $validator->messages()->messages());
    }

    public function testItIsExplicitByDefault()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $validator = new Validator($trans, ['foo' => ''], ['foo' => $rule]);

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->messages()->messages());
    }

    public function testItCanSpecifyTheValidationErrorKeyForTheErrorMessage()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('bar.baz', 'Another attribute error.');
                $fail('This attribute error.');
            }
        };

        $validator = new Validator($trans, ['foo' => 'xxxx'], ['foo' => $rule]);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'bar.baz' => [
                'Another attribute error.',
            ],
            'foo' => [
                'This attribute error.',
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanTranslateWithChoices()
    {
        $trans = $this->getArrayTranslator();
        $trans->addLines(['validation.translated-error' => 'There is one error.|There are many errors.'], 'en');
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('validation.translated-error')->translateChoice(2);
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertSame([
            'foo' => [
                'There are many errors.',
            ],
        ], $validator->messages()->messages());
    }

    public function testExplicitRuleCanUseInlineValidationMessages()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public $implicit = false;

            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule], [$rule::class => ':attribute custom.']);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'foo' => [
                'foo custom.',
            ],
        ], $validator->messages()->messages());

        $validator = new Validator($trans, ['foo' => 'bar'], ['foo' => $rule], ['foo.' . $rule::class => ':attribute custom with key.']);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'foo' => [
                'foo custom with key.',
            ],
        ], $validator->messages()->messages());
    }

    public function testImplicitRuleCanUseInlineValidationMessages()
    {
        $trans = $this->getArrayTranslator();
        $rule = new class implements ValidationRule {
            public $implicit = true;

            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $validator = new Validator($trans, ['foo' => ''], ['foo' => $rule], [$rule::class => ':attribute custom.']);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'foo' => [
                'foo custom.',
            ],
        ], $validator->messages()->messages());

        $validator = new Validator($trans, ['foo' => ''], ['foo' => $rule], ['foo.' . $rule::class => ':attribute custom with key.']);

        $this->assertFalse($validator->passes());
        $this->assertSame([
            'foo' => [
                'foo custom with key.',
            ],
        ], $validator->messages()->messages());
    }

    public function testItCanReturnInvokableRule()
    {
        $rule = new class implements ValidationRule {
            public function validate($attribute, $value, $fail): void
            {
                $fail('xxxx');
            }
        };

        $invokableValidationRule = InvokableValidationRule::make($rule);

        $this->assertSame($rule, $invokableValidationRule->invokable());
    }

    private function getArrayTranslator()
    {
        return new Translator(
            new ArrayLoader(),
            'en'
        );
    }
}
