<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

enum TaggedUnionDiscriminatorType: string
{
    case Email = 'email';
    case Url = 'url';
}

class ValidationAnyOfRuleTest extends TestCase
{
    private array $taggedUnionRules;

    private array $dotNotationNestedRules;

    private array $nestedRules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(
            TranslatorContract::class,
            fn () => new Translator(
                new ArrayLoader,
                'en'
            )
        );

        $this->setUpRuleSets();
    }

    public function testBasicValidation(): void
    {
        $rule = Rule::anyOf([
            ['required', 'uuid:4'],
            ['required', 'email'],
        ]);
        $idRule = ['id' => $rule];
        $requiredIdRule = ['id' => ['required', $rule]];

        $validator = new Validator($this->app->make('translator'), [
            'id' => 'taylor@laravel.com',
        ], $idRule);

        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [], $requiredIdRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '3c8ff5cb-4bc1-457b-a477-1833c477b254',
        ], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => null,
        ], $idRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '',
        ], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '',
        ], $requiredIdRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => 'abc',
        ], $idRule);
        $this->assertFalse($validator->passes());
    }

    public function testBasicStringValidation(): void
    {
        $rule = Rule::anyOf([
            'required|uuid:4',
            'required|email',
        ]);
        $idRule = ['id' => $rule];
        $requiredIdRule = ['id' => ['required', $rule]];

        $validator = new Validator($this->app->make('translator'), [
            'id' => 'test@example.com',
        ], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [], $requiredIdRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '3c8ff5cb-4bc1-457b-a477-1833c477b254',
        ], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => null,
        ], $idRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '',
        ], $idRule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => '',
        ], $requiredIdRule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'id' => 'abc',
        ], $idRule);
        $this->assertFalse($validator->passes());
    }

    public function testAssociativeArrayValueAgainstPlainRules(): void
    {
        $rule = Rule::anyOf([
            ['string', 'email'],
            ['string', 'alpha_dash', 'min:6'],
        ]);

        $validator = new Validator($this->app->make('translator'), [
            'username' => ['x' => 1],
        ], ['username' => ['required', $rule]]);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'username' => 'taylor@hypervel.com',
        ], ['username' => ['required', $rule]]);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => ['x' => 1],
        ], ['data' => Rule::anyOf(['string', 'array'])]);
        $this->assertTrue($validator->passes());
    }

    #[DataProvider('alternativeRuleShapes')]
    public function testAlternativeRulesPreserveTheirDataShape(array $rules, mixed $value, bool $passes): void
    {
        $validator = new Validator($this->app->make('translator'), ['data' => $value], [
            'data' => Rule::anyOf([$rules]),
        ]);

        $this->assertSame($passes, $validator->passes());
    }

    /**
     * Provide keyed alternatives and values with different shapes.
     */
    public static function alternativeRuleShapes(): iterable
    {
        yield 'numeric field rejects a scalar' => [[1 => 'array'], [1 => 'invalid'], false];
        yield 'numeric field accepts an array' => [[1 => 'array'], [1 => ['valid']], true];
        yield 'wildcard rejects invalid list item' => [['*.email' => 'email'], [['email' => 'invalid']], false];
        yield 'wildcard accepts valid list item' => [['*.email' => 'email'], [['email' => 'test@example.com']], true];
        yield 'wildcard rejects a present null' => [['*' => 'required'], null, false];
        yield 'wildcard has no items in an empty array' => [['*' => 'required'], [], true];
        yield 'keyed rules reject a scalar missing required fields' => [['email' => 'required|email'], 'invalid', false];
    }

    public function testNestedArraysCanUseAnArrayAlternativeAfterExists(): void
    {
        $validator = new Validator($this->app->make('translator'), ['data' => ['a' => [1], 'b' => [2]]], [
            'data' => Rule::anyOf(['exists:users,id', 'array']),
        ]);

        $this->assertTrue($validator->passes());
    }

    public function testTaggedUnionObjects(): void
    {
        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => TaggedUnionDiscriminatorType::Email->value,
                'email' => 'taylor@laravel.com',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => TaggedUnionDiscriminatorType::Email->value,
                'email' => 'invalid-email',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => TaggedUnionDiscriminatorType::Url->value,
                'url' => 'http://laravel.com',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => TaggedUnionDiscriminatorType::Url->value,
                'url' => 'not-a-url',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => TaggedUnionDiscriminatorType::Email->value,
                'url' => 'url-should-not-be-present-with-email-discriminator',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'data' => [
                'type' => 'doesnt-exist',
                'email' => 'taylor@laravel.com',
            ],
        ], ['data' => Rule::anyOf($this->taggedUnionRules)]);
        $this->assertFalse($validator->passes());
    }

    public function testNestedValidation(): void
    {
        $validator = new Validator($this->app->make('translator'), [
            'user' => [
                'identifier' => 1,
                'properties' => [
                    'name' => 'Taylor',
                    'surname' => 'Otwell',
                ],
            ],
        ], $this->nestedRules);
        $this->assertTrue($validator->passes());
        $validator->setRules($this->dotNotationNestedRules);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'user' => [
                'identifier' => 'taylor@laravel.com',
                'properties' => [
                    'bio' => 'biography',
                    'name' => 'Taylor',
                    'surname' => 'Otwell',
                ],
            ],
        ], $this->nestedRules);
        $this->assertTrue($validator->passes());
        $validator->setRules($this->dotNotationNestedRules);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'user' => [
                'identifier' => 'taylor@laravel.com',
                'properties' => [
                    'name' => null,
                    'surname' => 'Otwell',
                ],
            ],
        ], $this->nestedRules);
        $this->assertFalse($validator->passes());
        $validator->setRules($this->dotNotationNestedRules);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'user' => [
                'properties' => [
                    'name' => 'Taylor',
                    'surname' => 'Otwell',
                ],
            ],
        ], $this->nestedRules);
        $this->assertFalse($validator->passes());
        $validator->setRules($this->dotNotationNestedRules);
        $this->assertFalse($validator->passes());
    }

    public function testStarRuleSimple(): void
    {
        $rule = [
            'persons.*.age' => ['required', Rule::anyOf([
                ['min:10'],
                ['integer'],
            ])],
        ];

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['age' => 12],
                ['age' => 'foobar'],
            ],
        ], $rule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['age' => 'foobarbazqux'],
                ['month' => 12],
            ],
        ], $rule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['age' => 12],
                ['age' => 'foobarbazqux'],
            ],
        ], $rule);
        $this->assertTrue($validator->passes());
    }

    public function testStarRuleNested(): void
    {
        $rule = [
            'persons.*.birth' => ['required', Rule::anyOf([
                ['year' => 'required|integer'],
                'required|min:10',
            ])],
        ];

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['age' => ['year' => 12]],
            ],
        ], $rule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['birth' => ['month' => 12]],
            ],
        ], $rule);
        $this->assertFalse($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['birth' => ['year' => 12]],
            ],
        ], $rule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['birth' => 'foobarbazqux'],
                ['birth' => [
                    'year' => 12,
                ]],
            ],
        ], $rule);
        $this->assertTrue($validator->passes());

        $validator = new Validator($this->app->make('translator'), [
            'persons' => [
                ['birth' => 'foobar'],
                ['birth' => [
                    'year' => 12,
                ]],
            ],
        ], $rule);
        $this->assertFalse($validator->passes());
    }

    public function testCustomMessageUsingDotNotationAndFqcnWorks(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'string' => 123,
                'string_fqcn' => 456,
            ],
            [
                'string' => Rule::anyOf(['string']),
                'string_fqcn' => Rule::anyOf(['string']),
            ],
            [
                'string.any_of' => 'Please choose a valid string (dot notation)',
                'string_fqcn.Hypervel\Validation\Rules\AnyOf' => 'Please choose a valid string (fqcn)',
            ]
        );

        $this->assertTrue($v->fails());

        $this->assertSame([
            'Please choose a valid string (dot notation)',
            'Please choose a valid string (fqcn)',
        ], $v->messages()->all());
    }

    protected function setUpRuleSets()
    {
        $this->taggedUnionRules = [
            [
                'type' => ['required', Rule::in([TaggedUnionDiscriminatorType::Email])],
                'email' => ['required', 'email:rfc'],
            ],
            [
                'type' => ['required', Rule::in([TaggedUnionDiscriminatorType::Url])],
                'url' => ['required', 'url:http,https'],
            ],
        ];

        // Using AnyOf as nesting feature
        $this->nestedRules = [
            'user' => Rule::anyOf([
                [
                    'identifier' => ['required', Rule::anyOf([
                        'email:rfc',
                        'integer',
                    ])],
                    'properties' => ['required', Rule::anyOf([
                        [
                            'bio' => 'nullable',
                            'name' => 'required',
                            'surname' => 'required',
                        ],
                    ])],
                ],
            ]),
        ];

        $this->dotNotationNestedRules = [
            'user.identifier' => ['required', Rule::anyOf([
                'email:rfc',
                'integer',
            ])],
            'user.properties.bio' => 'nullable',
            'user.properties.name' => 'required',
            'user.properties.surname' => 'required',
        ];
    }
}
