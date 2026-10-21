<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\ValidationTest;

use Closure;
use Exception;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\ArrayType;
use Hypervel\Data\Attributes\Validation\Bail;
use Hypervel\Data\Attributes\Validation\BooleanType;
use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Attributes\Validation\In;
use Hypervel\Data\Attributes\Validation\IntegerType;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Present;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Attributes\Validation\RequiredUnless;
use Hypervel\Data\Attributes\Validation\RequiredWith;
use Hypervel\Data\Attributes\Validation\RequiredWithout;
use Hypervel\Data\Attributes\Validation\Rule as ValidationRule;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereInConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotInConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotNullConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNullConstraint;
use Hypervel\Data\Support\Validation\References\AuthenticatedUserReference;
use Hypervel\Data\Support\Validation\References\ContainerReference;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\References\RouteParameterReference;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Database\Query\Builder;
use Hypervel\Foundation\Auth\User;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Validator as ValidatorFacade;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\AbstractPropertyMorphableData;
use Hypervel\Tests\Data\Fixtures\CircData;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;
use Hypervel\Tests\Data\Fixtures\DataValidationAsserter;
use Hypervel\Tests\Data\Fixtures\DataWithMapper;
use Hypervel\Tests\Data\Fixtures\DataWithReferenceFieldValidationAttribute;
use Hypervel\Tests\Data\Fixtures\DummyDataWithContextOverwrittenValidationRules;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\Enums\PropertyMorphableEnum;
use Hypervel\Tests\Data\Fixtures\Models\DummyModel;
use Hypervel\Tests\Data\Fixtures\MultiData;
use Hypervel\Tests\Data\Fixtures\NestedData;
use Hypervel\Tests\Data\Fixtures\NestedNullableData;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithExplicitValidationRuleAttributeData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithOverwrittenRules;
use Hypervel\Tests\Data\Fixtures\Support\FakeInjectable;
use Hypervel\Tests\Data\Fixtures\ValidationAttributes\PassThroughCustomValidationAttribute;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Rules\Exists as HypervelExists;
use Hypervel\Validation\Rules\In as HypervelIn;
use Hypervel\Validation\Rules\Unique as HypervelUnique;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use InvalidArgumentException;

class ValidationTest extends TestCase
{
    use BindsRouteParameters;

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanValidateAString(): void
    {
        $dataClass = new class extends Data {
            public string $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => 'Hello World'])
            ->assertRules([
                'property' => ['required', 'string'],
            ]);
    }

    public function testCanValidateAFloat(): void
    {
        $dataClass = new class extends Data {
            public float $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => 10.0])
            ->assertRules([
                'property' => ['required', 'numeric'],
            ]);
    }

    public function testCanValidateAnInteger(): void
    {
        $dataClass = new class extends Data {
            public int $property;
        };

        // Hypervel infers integer rather than Spatie's numeric, so a fractional value fails instead of being truncated.
        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => 10.0])
            ->assertErrors(['property' => 10.5])
            ->assertRules([
                'property' => ['required', 'integer'],
            ]);
    }

    public function testCanValidateAnArray(): void
    {
        $dataClass = new class extends Data {
            public array $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => ['Hello World']])
            ->assertRules([
                'property' => ['required', 'array'],
            ]);
    }

    public function testCanValidateABool(): void
    {
        $dataClass = new class extends Data {
            public bool $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => true])
            ->assertOk(['property' => false])
            ->assertErrors([])
            ->assertRules([
                'property' => ['required', 'boolean'],
            ]);
    }

    public function testCanValidateANullableType(): void
    {
        $dataClass = new class extends Data {
            public ?array $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => ['Hello World']])
            ->assertOk(['property' => null])
            ->assertOk([])
            ->assertRules([
                'property' => ['nullable', 'array'],
            ]);
    }

    public function testCanValidatedAPropertyWithCustomRules(): void
    {
        $dataClass = new class extends Data {
            public ?array $property;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => ['array', 'min:5'],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['array', 'min:5'],
            ]);
    }

    public function testCanValidateAPropertyWithCustomRulesAsString(): void
    {
        $dataClass = new class extends Data {
            public ?array $property;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => 'array|min:5',
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['array', 'min:5'],
            ]);
    }

    public function testCanValidateAPropertyWithCustomRulesAsObject(): void
    {
        $dataClass = new class extends Data {
            public ?array $property;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => [new ArrayType, new Min(5)],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['array', 'min:5'],
            ]);
    }

    public function testCanValidateAPropertyWithAttributes(): void
    {
        $dataClass = new class extends Data {
            #[Min(5)]
            public ?array $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['nullable', 'array', 'min:5'],
            ]);
    }

    public function testCanValidateAnOptionalAttribute(): void
    {
        DataValidationAsserter::for(new class extends Data {
            public array|Optional $property;
        })
            ->assertOk([])
            ->assertOk(['property' => []])
            ->assertErrors(['property' => null])
            ->assertRules([
                'property' => ['sometimes', 'array'],
            ]);

        DataValidationAsserter::for(new class extends Data {
            public array|Optional|null $property;
        })
            ->assertOk([])
            ->assertOk(['property' => []])
            ->assertOk(['property' => null])
            ->assertRules([
                'property' => ['nullable', 'sometimes', 'array'],
            ]);

        DataValidationAsserter::for(new class extends Data {
            #[Max(10)]
            public array|Optional $property;
        })
            ->assertOk([])
            ->assertOk(['property' => []])
            ->assertErrors(['property' => null])
            ->assertRules([
                'property' => ['sometimes', 'array', 'max:10'],
            ]);
    }

    public function testCanValidateANativeEnum(): void
    {
        $dataClass = new class extends Data {
            public DummyBackedEnum $property;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['property' => 'foo'])
            ->assertRules([
                'property' => ['required', new Enum(DummyBackedEnum::class)],
            ]);
    }

    public function testWillNeverAddExtraRequireRulesWhenNotRequired(): void
    {
        DataValidationAsserter::for(new class extends Data {
            public ?string $property;
        })->assertRules([
            'property' => [new Nullable, 'string'],
        ]);

        DataValidationAsserter::for(new class extends Data {
            #[RequiredWith('other')]
            public string $property;
        })->assertRules([
            'property' => ['string', 'required_with:other'],
        ]);

        DataValidationAsserter::for(new class extends Data {
            #[ValidationRule('required_with:other')]
            public string $property;
        })->assertRules([
            'property' => ['string', 'required_with:other'],
        ]);
    }

    public function testIsPossibleToHaveMultipleRequiredRules(): void
    {
        // Upstream skips this case and expects a malformed required_unless rule; Hypervel adds no
        // inferred required rule beside declared presence rules.
        DataValidationAsserter::for(new class extends Data {
            #[RequiredUnless('is_required', false), RequiredWith('make_required')]
            public string $property;

            public string $make_required;

            public bool $is_required;
        })->assertRules([
            'property' => ['string', 'required_unless:is_required,false', 'required_with:make_required'],
            'make_required' => ['required', 'string'],
            'is_required' => ['required', 'boolean'],
        ]);
    }

    public function testWillTakeCareOfMapping(): void
    {
        DataValidationAsserter::for(new class extends Data {
            #[MapInputName('some_property')]
            public string $property;
        })
            ->assertOk(['some_property' => 'foo'])
            ->assertErrors(['property' => 'foo'])
            ->assertRules([
                'some_property' => ['required', 'string'],
            ]);

        DataValidationAsserter::for(new class extends Data {
            #[MapName('some_property')]
            public string $property;
        })
            ->assertOk(['some_property' => 'foo'])
            ->assertErrors(['property' => 'foo'])
            ->assertRules([
                'some_property' => ['required', 'string'],
            ]);

        DataValidationAsserter::for(new class extends Data {
            #[MapName('some_property')]
            public SimpleData $property;
        })
            ->assertOk(['some_property' => ['string' => 'hi']])
            ->assertErrors(['property' => ['string' => 'hi']])
            ->assertRules([
                'some_property' => ['required', 'array'],
                'some_property.string' => ['required', 'string'],
            ]);

        DataValidationAsserter::for(new class extends Data {
            #[DataCollectionOf(SimpleData::class), MapName('some_property')]
            public DataCollection $property;
        })
            ->assertOk(['some_property' => [['string' => 'hi']]])
            ->assertErrors(['property' => [['string' => 'hi']]])
            ->assertRules([
                'some_property' => ['present', 'array'],
                'some_property.0.string' => ['required', 'string'],
            ], payload: ['some_property' => [[]]]);

        DataValidationAsserter::for(new class extends Data {
            #[MapName('some_property')]
            public DataWithMapper $property;
        })
            ->assertOk([
                'some_property' => [
                    'cased_property' => 'Hi',
                    'data_cased_property' => ['string' => 'Hi'],
                    'data_collection_cased_property' => [
                        ['string' => 'Hi'],
                    ],
                ],
            ])
            ->assertErrors([
                'property' => [
                    'cased_property' => 'Hi',
                    'data_cased_property' => ['string' => 'Hi'],
                    'data_collection_cased_property' => [
                        ['string' => 'Hi'],
                    ],
                ],
            ])
            ->assertErrors([
                'some_property' => [
                    'casedProperty' => 'Hi',
                    'dataCasedProperty' => ['string' => 'Hi'],
                    'dataCollectionCasedProperty' => [
                        ['string' => 'Hi'],
                    ],
                ],
            ])
            ->assertRules([
                'some_property' => ['required', 'array'],
                'some_property.cased_property' => ['required', 'string'],
                'some_property.data_cased_property' => ['required', 'array'],
                'some_property.data_cased_property.string' => ['required', 'string'],
                'some_property.data_collection_cased_property' => ['present', 'array'],
                'some_property.data_collection_cased_property.0.string' => ['required', 'string'],
            ], payload: [
                'some_property' => [
                    'data_collection_cased_property' => [[]],
                ],
            ]);
    }

    public function testCanDisableValidation(): void
    {
        $dataClass = new class extends Data {
            #[WithoutValidation]
            public string $property;

            #[DataCollectionOf(SimpleData::class), WithoutValidation]
            public DataCollection $collection;

            #[WithoutValidation]
            public SimpleData $data;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([])
            ->assertRules([]);
    }

    public function testCanWriteCustomRulesBasedUponPayloads(): void
    {
        $dataClass = new class extends Data {
            public bool $strict;

            public string $property;

            #[MapInputName(SnakeCaseMapper::class)]
            public string $mappedProperty;

            /**
             * Get the validation rules for the payload.
             */
            public static function rules(ValidationContext $context): array
            {
                if ($context->payload['strict'] === true) {
                    return [
                        'property' => ['in:strict'],
                        'mapped_property' => ['in:strict'],
                    ];
                }

                return [];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules(
                rules: [
                    'strict' => ['required', 'boolean'],
                    'property' => ['in:strict'],
                    'mapped_property' => ['in:strict'],
                ],
                payload: [
                    'strict' => true,
                ]
            )
            ->assertRules(
                rules: [
                    'strict' => ['required', 'boolean'],
                    'property' => ['required', 'string'],
                    'mapped_property' => ['required', 'string'],
                ],
                payload: [
                    'strict' => false,
                ]
            );
    }

    public function testCanWriteCustomRulesBasedUponInjectedDependencies(): void
    {
        $dataClass = new class extends Data {
            public string $environment;

            /**
             * Get the validation rules for the application environment.
             */
            public static function rules(Application $app): array
            {
                return [
                    'environment' => [new Required, new StringType, In::create($app->environment())],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'environment' => ['required', 'string', 'in:"testing"'],
        ]);
    }

    public function testCanValidateNestedData(): void
    {
        $dataClass = new class extends Data {
            public SimpleData $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['nested' => ['string' => 'Hello World']])
            ->assertErrors(['nested' => []])
            ->assertErrors(['nested' => null])
            ->assertRules([
                'nested' => ['required', 'array'],
                'nested.string' => ['required', 'string'],
            ]);
    }

    public function testCanValidateNestedNullableData(): void
    {
        $dataClass = new class extends Data {
            public ?SimpleData $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['nested' => ['string' => 'Hello World']])
            ->assertOk(['nested' => null])
            ->assertErrors(['nested' => ['string' => null]])
            ->assertErrors(['nested' => []])
            ->assertRules(['nested' => ['nullable', 'array']], payload: [])
            ->assertRules([
                'nested' => ['nullable', 'array'],
                'nested.string' => ['required', 'string'],
            ], payload: ['nested' => []]);
    }

    public function testCanValidateNestedOptionalData(): void
    {
        $dataClass = new class extends Data {
            public SimpleData|Optional $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['nested' => ['string' => 'Hello World']])
            ->assertErrors(['nested' => null])
            ->assertErrors(['nested' => ['string' => null]])
            ->assertErrors(['nested' => []])
            ->assertRules([
                'nested' => ['sometimes', 'array'],
            ], payload: [])
            ->assertRules([
                'nested' => ['sometimes', 'array'],
                'nested.string' => ['required', 'string'],
            ], ['nested' => null]);
    }

    public function testCanAddAdditionalRulesToNestedData(): void
    {
        $dataClass = new class extends Data {
            #[Bail]
            public SimpleData $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'nested' => ['required', 'array', 'bail'],
                'nested.string' => ['required', 'string'],
            ]);
    }

    public function testWillUseNameMappingWithNestedObjects(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('some_nested')]
            public SimpleData $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['some_nested' => ['string' => 'Hello World']])
            ->assertRules([
                'some_nested' => ['required', 'array'],
                'some_nested.string' => ['required', 'string'],
            ]);
    }

    public function testCanUseNestedPayloadsInNestedData(): void
    {
        $dataClass = new class extends Data {
            public DummyDataWithContextOverwrittenValidationRules $some_nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules(
                rules: [
                    'some_nested' => ['required', 'array'],
                    'some_nested.validate_as_email' => ['boolean', 'required'],
                    'some_nested.string' => ['required', 'string', 'email'],
                ],
                payload: [
                    'some_nested' => [
                        'validate_as_email' => true,
                    ],
                ]
            )
            ->assertRules(
                rules: [
                    'some_nested' => ['required', 'array'],
                    'some_nested.validate_as_email' => ['boolean', 'required'],
                    'some_nested.string' => ['required', 'string'],
                ],
                payload: [
                    'some_nested' => [
                        'validate_as_email' => false,
                    ],
                ]
            );
    }

    public function testCanUseAReferenceToAnotherFieldInData(): void
    {
        DataValidationAsserter::for(DataWithReferenceFieldValidationAttribute::class)
            ->assertOk([
                'check_string' => '0',
            ])
            ->assertErrors([
                'check_string' => '1',
            ])
            ->assertRules(
                rules: [
                    'check_string' => ['required', 'boolean'],
                    // A boolean reference renders as true, which Laravel matches for a boolean field.
                    'string' => ['string', 'required_if:check_string,true'],
                ],
                payload: [
                    'check_string' => '1',
                ]
            );
    }

    public function testCanUseAReferenceToAnotherFieldInNestedData(): void
    {
        $dataClass = new class extends Data {
            public DataWithReferenceFieldValidationAttribute $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'nested' => ['check_string' => '0'],
            ])
            ->assertErrors([
                'nested' => ['check_string' => '1'],
            ])
            ->assertRules(
                rules: [
                    'nested' => ['required', 'array'],
                    'nested.check_string' => ['required', 'boolean'],
                    'nested.string' => ['string', 'required_if:nested.check_string,true'],
                ],
                payload: [
                    'nested' => ['check_string' => '1'],
                ]
            );
    }

    public function testCanUseAReferenceToAnotherFieldInACollection(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(DataWithReferenceFieldValidationAttribute::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['check_string' => '0'],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['check_string' => '1'],
                ],
            ])
            ->assertRules(
                rules: [
                    'collection' => ['present', 'array'],
                    'collection.0.check_string' => ['required', 'boolean'],
                    // A uniform collection references the item's sibling through a wildcard, which Laravel resolves per item.
                    'collection.0.string' => ['string', 'required_if:collection.*.check_string,true'],
                ],
                payload: [
                    'collection' => [['check_string' => '1']],
                ]
            );
    }

    public function testCanUseAReferenceToAnotherFieldInACollectionWithNestedData(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(TestValidationDataWithCollectionNestedDataWithFieldReference::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['nested' => ['check_string' => '0']],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['nested' => ['check_string' => '1']],
                ],
            ])
            ->assertRules(
                rules: [
                    'collection' => ['present', 'array'],
                    'collection.0.nested' => ['required', 'array'],
                    'collection.0.nested.check_string' => ['required', 'boolean'],
                    'collection.0.nested.string' => ['string', 'required_if:collection.*.nested.check_string,true'],
                ],
                payload: [
                    'collection' => [
                        ['nested' => ['check_string' => '1']],
                    ],
                ]
            );
    }

    public function testCanReferenceToTheRootValidatedObjectInNestedData(): void
    {
        $dataClass = new class extends Data {
            public bool $check_string;

            public TestDataWithRootReferenceFieldValidationAttribute $nested;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'check_string' => '0',
                'nested' => ['something'],
            ])
            ->assertErrors([
                'check_string' => '1',
                'nested' => ['something'],
            ])
            ->assertRules(
                rules: [
                    'check_string' => ['required', 'boolean'],
                    'nested' => ['required', 'array'],
                    'nested.string' => ['string', 'required_if:check_string,true'],
                ],
                payload: [
                    'nested' => ['check_string' => '1'],
                ]
            );
    }

    public function testWillValidateACollection(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                ],
            ])
            ->assertOk(['collection' => []])
            ->assertErrors(['collection' => null])
            ->assertErrors([])
            ->assertErrors(['collection' => ['strings', 'here', 'instead', 'of', 'arrays']])
            ->assertErrors([
                'collection' => [
                    ['other_string' => 'Hello World'],
                ],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.string' => ['required', 'string'],
            ], [
                'collection' => [[]],
            ]);
    }

    public function testWillValidateCollectionWithExplicitRequire(): void
    {
        $dataClass = new class extends Data {
            #[Required]
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                ],
            ])
            ->assertErrors(['collection' => []])
            ->assertErrors(['collection' => null])
            ->assertErrors([])
            ->assertErrors([
                'collection' => [
                    ['other_string' => 'Hello World'],
                ],
            ])
            // A declared requiring rule already requires the key, so no inferred present rule is added.
            ->assertRules([
                'collection' => ['array', 'required'],
            ])
            ->assertRules([
                'collection' => ['array', 'required'],
                'collection.0.string' => ['required', 'string'],
            ], [
                'collection' => [[]],
            ]);
    }

    public function testWillValidateACollectionWithExtraAttributes(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleDataWithExplicitValidationRuleAttributeData::class)]
            #[Min(2)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['email' => 'ruben@spatie.be'],
                    ['email' => 'freek@spatie.be'],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['email' => 'not-an'],
                    ['email' => 'email-address'],
                ],
            ])
            ->assertErrors(['collection' => []])
            ->assertRules([
                'collection' => ['present', 'array', 'min:2'],
            ])
            ->assertRules([
                'collection' => ['present', 'array', 'min:2'],
                'collection.0.email' => ['required', 'string', 'email:rfc'],
            ], [
                'collection' => [[]],
            ]);
    }

    public function testWillValidateANullableCollection(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public ?DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                ],
            ])
            ->assertOk(['collection' => []])
            ->assertOk(['collection' => null])
            ->assertOk([])
            ->assertErrors([
                'collection' => [
                    ['other_string' => 'Hello World'],
                ],
            ])
            ->assertRules(['collection' => ['nullable', 'array']], payload: [])
            ->assertRules(['collection' => ['nullable', 'array']], payload: ['collection' => null])
            // A nullable collection may be omitted, so it has no present rule (README).
            ->assertRules([
                'collection' => ['nullable', 'array'],
                'collection.0.string' => ['required', 'string'],
            ], payload: [
                'collection' => [[]],
            ]);
    }

    public function testWillValidateAnOptionalCollection(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public Optional|DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                ],
            ])
            ->assertOk(['collection' => []])
            ->assertOk([])
            ->assertErrors(['collection' => null])
            ->assertErrors([
                'collection' => [
                    ['other_string' => 'Hello World'],
                ],
            ])
            // With sometimes, a present rule would only run when the key exists, so none is added.
            ->assertRules([
                'collection' => ['sometimes', 'array'],
            ], payload: ['collection' => null])
            ->assertRules([
                'collection' => ['sometimes', 'array'],
                'collection.0.string' => ['required', 'string'],
            ], payload: [
                'collection' => [[]],
            ]);
    }

    public function testCanOverwriteCollectionClassRules(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $collection;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'collection' => ['array', 'min:1', 'max:2'],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                ],
            ])
            ->assertOk([
                'collection' => [
                    ['string' => 'Never Gonna'],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['string' => 'Never Gonna'],
                    ['string' => 'Give You Up'],
                    ['string' => 'Never Gonna'],
                ],
            ])
            ->assertErrors(['collection' => []])
            ->assertRules([
                'collection' => ['array', 'min:1', 'max:2'],
            ], payload: [])
            ->assertRules([
                'collection' => ['array', 'min:1', 'max:2'],
                'collection.0.string' => ['required', 'string'],
            ], payload: [
                'collection' => [[]],
            ]);
    }

    public function testCanOverwriteCollectionItemRulesWithWildcardRules(): void
    {
        // https://github.com/spatie/laravel-data/issues/1121
        $dataClass = new class extends Data {
            /** @var array<SimpleData> */
            public array $years;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'years' => ['required', 'array'],
                    'years.*' => ['date_format:Y', 'required'],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['years' => ['2025']])
            ->assertErrors(['years' => ['not-a-year']])
            ->assertRules([
                'years' => ['required', 'array'],
            ], payload: [])
            ->assertRules([
                'years' => ['required', 'array'],
                'years.0' => ['date_format:Y', 'required'],
            ], payload: [
                'years' => ['2025'],
            ]);
    }

    // Complex examples

    public function testCanNestDataInCollections(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(CollectionClassA::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['collection' => [['nested' => ['string' => 'Hello World']]]])
            ->assertErrors(['collection' => [['nested' => null]]])
            ->assertErrors(['collection' => [['nested' => []]]])
            ->assertRules([
                'collection' => ['present', 'array'],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['required', 'array'],
                'collection.0.nested.string' => ['required', 'string'],
            ], [
                'collection' => [[]],
            ]);
    }

    public function testCanNestNullableDataInCollections(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(CollectionClassTable::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['collection' => [['nested' => ['string' => 'Hello World']]]])
            ->assertRules([
                'collection' => ['present', 'array'],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['nullable', 'array'],
            ], [
                'collection' => [[]],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['nullable', 'array'],
            ], [
                'collection' => [['nested' => null]],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['nullable', 'array'],
                'collection.0.nested.string' => ['required', 'string'],
            ], [
                'collection' => [['nested' => []]],
            ]);
    }

    public function testCanNestOptionalDataInCollections(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(CollectionClassC::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['collection' => [['nested' => ['string' => 'Hello World']]]])
            ->assertErrors(['collection' => [['nested' => null]]])
            ->assertErrors(['collection' => [['nested' => []]]])
            ->assertRules([
                'collection' => ['present', 'array'],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['sometimes', 'array'],
            ], [
                'collection' => [[]],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['sometimes', 'array'],
                'collection.0.nested.string' => ['required', 'string'],
            ], [
                'collection' => [['nested' => null]],
            ])
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['sometimes', 'array'],
                'collection.0.nested.string' => ['required', 'string'],
            ], [
                'collection' => [['nested' => []]],
            ]);
    }

    public function testCanNestDataInCollectionsUsingRelativeRuleGeneration(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(DummyDataWithContextOverwrittenValidationRules::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([
                'collection' => [
                    ['string' => 'Hello World', 'validate_as_email' => false],
                    ['string' => 'hello@world.test', 'validate_as_email' => true],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['string' => 'Invalid Email', 'validate_as_email' => true],
                    ['string' => 'Hello World', 'validate_as_email' => false],
                    ['string' => 'Invalid Email', 'validate_as_email' => true],
                ],
            ], [
                'collection.0.string' => [__('validation.email', ['attribute' => 'collection.0.string'])],
                'collection.2.string' => [__('validation.email', ['attribute' => 'collection.2.string'])],
            ])
            ->assertRules(
                [
                    'collection' => ['present', 'array'],
                    'collection.0.string' => ['required', 'string'],
                    'collection.0.validate_as_email' => ['boolean', 'required'],
                    'collection.1.string' => ['required', 'string', 'email'],
                    'collection.1.validate_as_email' => ['boolean', 'required'],
                ],
                [
                    'collection' => [
                        ['string' => 'Hello World', 'validate_as_email' => false],
                        ['string' => 'hello@world.test', 'validate_as_email' => true],
                    ],
                ]
            );
    }

    public function testSupportsRequiredWithoutValidationForOptionalCollections(): void
    {
        $dataClass = new class extends Data {
            #[RequiredWithout('someOtherData')]
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection|Optional $someData;

            #[RequiredWithout('someData')]
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection|Optional $someOtherData;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules(
                [
                    'someData' => [
                        'array',
                        'required_without:someOtherData',
                    ],
                    'someOtherData' => [
                        'array',
                        'required_without:someData',
                    ],
                ],
            )
            ->assertOk([
                'someData' => [['string' => 'Hello World']],
            ])
            ->assertOk([
                'someOtherData' => [['string' => 'Hello World']],
            ])
            ->assertOk([
                'someData' => [['string' => 'Hello World']],
                'someOtherData' => [['string' => 'Hello World']],
            ])
            ->assertErrors([]);
    }

    public function testSupportsRequiredWithoutValidationForNullableCollections(): void
    {
        $dataClass = new class extends Data {
            #[RequiredWithout('someOtherData')]
            #[DataCollectionOf(SimpleData::class)]
            public ?DataCollection $someData;

            #[RequiredWithout('someData')]
            #[DataCollectionOf(SimpleData::class)]
            public ?DataCollection $someOtherData;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules(
                [
                    'someData' => [
                        'nullable',
                        'array',
                        'required_without:someOtherData',
                    ],
                    'someOtherData' => [
                        'nullable',
                        'array',
                        'required_without:someData',
                    ],
                ],
                []
            );
    }

    public function testCanNestDataInClassesInsideCollectionsUsingRelativeRuleGeneration(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(CollectionClassK::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.nested' => ['required', 'array'],
                'collection.0.nested.string' => ['required', 'string'],
                'collection.0.nested.validate_as_email' => ['boolean', 'required'],
                'collection.1.nested' => ['required', 'array'],
                'collection.1.nested.string' => ['required', 'string', 'email'],
                'collection.1.nested.validate_as_email' => ['boolean', 'required'],
            ], [
                'collection' => [
                    ['nested' => ['string' => 'Hello World', 'validate_as_email' => false]],
                    ['nested' => ['string' => 'hello@world.test', 'validate_as_email' => true]],
                ],
            ])
            ->assertOk([
                'collection' => [
                    ['nested' => ['string' => 'Hello World', 'validate_as_email' => false]],
                    ['nested' => ['string' => 'hello@world.test', 'validate_as_email' => true]],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    ['nested' => ['string' => 'Invalid Email', 'validate_as_email' => true]],
                    ['nested' => ['string' => 'Hello World', 'validate_as_email' => false]],
                    ['nested' => ['string' => 'Invalid Email', 'validate_as_email' => true]],
                ],
            ], [
                'collection.0.nested.string' => [__('validation.email', ['attribute' => 'collection.0.nested.string'])],
                'collection.2.nested.string' => [__('validation.email', ['attribute' => 'collection.2.nested.string'])],
            ]);
    }

    public function testCanNestDataInDeepCollectionsUsingRelativeRuleGeneration(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(ValidationTestNestedDataWithContextOverwrittenRules::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'collection' => ['present', 'array'],
                'collection.0.validate_as_email' => ['boolean', 'required'],
                'collection.0.string' => ['required', 'string'],
                'collection.0.items' => ['array', 'required'],
                'collection.0.items.0.deep_validate_as_email' => ['boolean', 'required'],
                'collection.0.items.0.deep_string' => ['required', 'string'],
                'collection.0.items.1.deep_validate_as_email' => ['boolean', 'required'],
                'collection.0.items.1.deep_string' => ['required', 'string', 'email'],
            ], [
                'collection' => [
                    [
                        'string' => 'Hello World',
                        'validate_as_email' => false,
                        'items' => [
                            ['deep_string' => 'Hello World', 'deep_validate_as_email' => false],
                            ['deep_string' => 'hello@world.test', 'deep_validate_as_email' => true],
                        ],
                    ],
                ],
            ])
            ->assertOk([
                'collection' => [
                    [
                        'string' => 'Hello World',
                        'validate_as_email' => false,
                        'items' => [
                            ['deep_string' => 'Hello World', 'deep_validate_as_email' => false],
                            ['deep_string' => 'hello@world.test', 'deep_validate_as_email' => true],
                        ],
                    ],
                ],
            ])
            ->assertErrors([
                'collection' => [
                    [
                        'string' => 'Hello World',
                        'validate_as_email' => false,
                        'items' => [
                            ['deep_string' => 'Invalid Email', 'deep_validate_as_email' => true],
                            ['deep_string' => 'Hello World', 'deep_validate_as_email' => false],
                            ['deep_string' => 'hello@world.test', 'deep_validate_as_email' => true],
                        ],
                    ],
                    [
                        'string' => 'Invalid Email',
                        'validate_as_email' => true,
                        'items' => [
                            ['deep_string' => 'Invalid Email', 'deep_validate_as_email' => true],
                            ['deep_string' => 'Hello World', 'deep_validate_as_email' => false],
                            ['deep_string' => 'hello@world.test', 'deep_validate_as_email' => true],
                        ],
                    ],
                ],
            ], [
                // Class rules apply in declaration order, so the rule that replaces collection.1.string follows the generated ones.
                'collection.0.items.0.deep_string' => [__('validation.email', ['attribute' => 'collection.0.items.0.deep string'])],
                'collection.1.items.0.deep_string' => [__('validation.email', ['attribute' => 'collection.1.items.0.deep string'])],
                'collection.1.string' => [__('validation.email', ['attribute' => 'collection.1.string'])],
            ]);
    }

    public function testCanNestDataUsingRelativeRuleGeneration(): void
    {
        $dataClass = new class extends Data {
            public DummyDataWithContextOverwrittenValidationRules $nested;
        };

        $payload = [
            'nested' => [
                'string' => 'Hello World',
                'validate_as_email' => true,
            ],
        ];

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'nested' => ['required', 'array'],
                'nested.string' => ['required', 'string', 'email'],
                'nested.validate_as_email' => ['boolean', 'required'],
            ], $payload)
            ->assertErrors($payload);
    }

    public function testCorrectlyInjectsContextInTheRulesMethod(): void
    {
        $dataClass = new class extends Data {
            public string $property;

            public NestedClassN $nested;

            /**
             * Check the context passed to the root rules.
             */
            public static function rules(ValidationContext $context): array
            {
                $correct = $context->payload['property'] === 'Root'
                    && $context->fullPayload['property'] === 'Root'
                    && $context->path->isRoot();

                if (! $correct) {
                    throw new Exception('Should not end up here');
                }

                return [];
            }
        };

        $payload = [
            'property' => 'Root',
            'nested' => [
                'property' => 'N',
                'data' => [
                    'property' => 'J',
                ],
                'collection' => [
                    [
                        'property' => 'M',
                        'nested' => [
                            'property' => 'K',
                        ],
                        'collection' => [
                            [
                                'property' => 'L',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['required', 'string'],
                'nested' => ['required', 'array'],
                'nested.property' => ['required', 'string'],
                'nested.data' => ['required', 'array'],
                'nested.data.property' => ['required', 'string'],
                'nested.collection' => ['present', 'array'],
                'nested.collection.0.property' => ['required', 'string'],
                'nested.collection.0.nested' => ['required', 'array'],
                'nested.collection.0.nested.property' => ['required', 'string'],
                'nested.collection.0.collection' => ['present', 'array'],
                'nested.collection.0.collection.0.property' => ['required', 'string'],
            ], $payload);
    }

    public function testWillMergeOverwrittenRulesOnInheritedDataObjects(): void
    {
        $data = new class extends Data {
            public SimpleDataWithOverwrittenRules $nested;

            /** @var DataCollection<SimpleDataWithOverwrittenRules> */
            public DataCollection $collection;
        };

        $payload = [
            'nested' => ['string' => 'test'],
            'collection' => [
                ['string' => 'test'],
            ],
        ];

        DataValidationAsserter::for($data)->assertRules([
            'nested' => ['required', 'array'],
            'nested.string' => ['string', 'required', 'min:10', 'max:100'],
            'collection' => ['present', 'array'],
            'collection.0.string' => ['string', 'required', 'min:10', 'max:100'],
        ], $payload)->assertErrors($payload);
    }

    public function testWillReduceAttributeRulesToHypervelRulesInTheEnd(): void
    {
        $dataClass = new class extends Data {
            public int $property;

            public static Closure $where;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => [
                        new IntegerType,
                        new Exists('table', where: self::$where),
                    ],
                ];
            }
        };

        $dataClass::$where = fn (Builder $query): Builder => $query->where('is_admin', true);

        DataValidationAsserter::for($dataClass)->assertRules([
            'property' => [
                'integer',
                (new HypervelExists('table'))->where($dataClass::$where),
            ],
        ]);
    }

    public function testCanUseDatabaseConstraintsWithExistsValidation(): void
    {
        $dataClass = new class extends Data {
            public int $property;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => [
                        new Exists('users', where: [
                            new WhereConstraint('status', 'active'),
                            new WhereNullConstraint('deleted_at'),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'property' => [
                (new HypervelExists('users'))
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
        ]);
    }

    public function testCanUseMultipleDatabaseConstraintsWithExistsValidation(): void
    {
        $dataClass = new class extends Data {
            public int $userId;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'userId' => [
                        new Exists('users', where: [
                            new WhereConstraint('active', true),
                            new WhereNotConstraint('name', 'Unlucky'),
                            new WhereInConstraint('role', ['admin', 'user']),
                            new WhereNotInConstraint('type', ['guest', 'temp']),
                            new WhereNullConstraint('deleted_at'),
                            new WhereNotNullConstraint('email_verified_at'),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'userId' => [
                (new HypervelExists('users'))
                    ->where('active', true)
                    ->whereNot('name', 'Unlucky')
                    ->whereIn('role', ['admin', 'user'])
                    ->whereNotIn('type', ['guest', 'temp'])
                    ->whereNull('deleted_at')
                    ->whereNotNull('email_verified_at'),
            ],
        ]);
    }

    public function testCanCombineDatabaseConstraintsWithClosureConstraintsInExistsValidation(): void
    {
        $this->freezeTime();

        $dataClass = new class extends Data {
            public int $userId;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'userId' => [
                        new Exists('users', where: [
                            new WhereConstraint('active', true),
                            fn (Builder $query): Builder => $query->where('created_at', '>', now()->subYear()),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'userId' => [
                (new HypervelExists('users'))
                    ->where('active', true)
                    ->where(fn (Builder $query): Builder => $query->where('created_at', '>', now()->subYear())),
            ],
        ]);
    }

    public function testThrowsExceptionForInvalidDatabaseConstraintInExistsValidation(): void
    {
        $dataClass = new class extends Data {
            public int $userId;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'userId' => [
                        new Exists('users', where: ['invalid_constraint']),
                    ],
                ];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Each where item must be a DatabaseConstraint or Closure');

        $dataClass::getValidationRules([]);
    }

    public function testCanUseDatabaseConstraintsWithUniqueValidation(): void
    {
        $dataClass = new class extends Data {
            public string $email;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'email' => [
                        new Unique('users', where: [
                            new WhereConstraint('active', true),
                            new WhereNullConstraint('deleted_at'),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'email' => [
                (new HypervelUnique('users'))
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
        ]);
    }

    public function testCanUseMultipleDatabaseConstraintsWithUniqueValidation(): void
    {
        $dataClass = new class extends Data {
            public string $email;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'email' => [
                        new Unique('users', where: [
                            new WhereConstraint('active', true),
                            new WhereNotConstraint('name', 'Unlucky'),
                            new WhereInConstraint('role', ['admin', 'user']),
                            new WhereNotInConstraint('type', ['guest', 'temp']),
                            new WhereNullConstraint('deleted_at'),
                            new WhereNotNullConstraint('email_verified_at'),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'email' => [
                (new HypervelUnique('users'))
                    ->where('active', true)
                    ->whereNot('name', 'Unlucky')
                    ->whereIn('role', ['admin', 'user'])
                    ->whereNotIn('type', ['guest', 'temp'])
                    ->whereNull('deleted_at')
                    ->whereNotNull('email_verified_at'),
            ],
        ]);
    }

    public function testCanCombineDatabaseConstraintsWithClosureConstraintsInUniqueValidation(): void
    {
        $this->freezeTime();

        $dataClass = new class extends Data {
            public string $email;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'email' => [
                        new Unique('users', where: [
                            new WhereConstraint('active', true),
                            fn (Builder $query): Builder => $query->where('created_at', '>', now()->subYear()),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'email' => [
                (new HypervelUnique('users'))
                    ->where('active', true)
                    ->where(fn (Builder $query): Builder => $query->where('created_at', '>', now()->subYear())),
            ],
        ]);
    }

    public function testCanUseDatabaseConstraintsWithUniqueValidationWhileMaintainingIgnoreFunctionality(): void
    {
        $dataClass = new class extends Data {
            public string $email;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'email' => [
                        new Unique('users', ignore: 5, where: [
                            new WhereConstraint('active', true),
                            new WhereNotConstraint('deleted_at', null),
                        ]),
                    ],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'email' => [
                (new HypervelUnique('users'))
                    ->ignore(5)
                    ->where('active', true)
                    ->whereNot('deleted_at', null),
            ],
        ]);
    }

    public function testThrowsExceptionForInvalidDatabaseConstraintInUniqueValidation(): void
    {
        $dataClass = new class extends Data {
            #[Unique('users', where: ['invalid_constraint'])]
            public string $email;
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Each where item must be a DatabaseConstraint or Closure');

        $dataClass::getValidationRules([]);
    }

    public function testCanUseExternalReferenceAsDatabaseConstraintValue(): void
    {
        $dataClass = new class extends Data {
            #[Unique('users', where: [
                new WhereConstraint('is_active', new RouteParameterReference('active')),
            ])]
            public string $email;
        };

        $this->bindRouteParameters(['active' => true]);

        DataValidationAsserter::for($dataClass)->assertRules([
            'email' => [
                'required',
                'string',
                'unique:users,NULL,NULL,id,is_active,"1"',
            ],
        ]);
    }

    public function testCanReferenceRouteParametersAsValuesWithinRules(): void
    {
        $dataClass = new class extends Data {
            #[Unique('posts', ignore: new RouteParameterReference('post_id'))]
            public int $property;
        };

        $this->bindRouteParameters(['post_id' => '69']);

        DataValidationAsserter::for($dataClass)->assertRules([
            'property' => [
                'required',
                'integer',
                'unique:posts,NULL,"69",id',
            ],
        ]);
    }

    public function testCanReferenceRouteModelsWithAPropertyAsValuesWithinRules(): void
    {
        $dataClass = new class extends Data {
            #[Unique('posts', ignore: new RouteParameterReference('post', 'id'))]
            public int $property;
        };

        $this->bindRouteParameters(['post' => new DummyModel(['id' => 69])]);

        DataValidationAsserter::for($dataClass)->assertRules([
            'property' => [
                'required',
                'integer',
                'unique:posts,NULL,"69",id',
            ],
        ]);
    }

    public function testCanReferenceTheCurrentLoggedInUserAsValuesWithinRules(): void
    {
        $this->actingAs($user = (new User)->forceFill(['id' => 69]));

        $dataClass = new class extends Data {
            #[Unique('users', ignore: new AuthenticatedUserReference)]
            public int $property;
        };

        $this->assertEquals([
            'property' => [
                'required',
                'integer',
                Rule::unique('users')->ignore($user),
            ],
        ], $dataClass::getValidationRules([]));
    }

    public function testCanReferenceAContainerDependencyAsValuesWithinRules(): void
    {
        $this->app->bind('max-allowed-size', fn (): int => 100);

        $dataClass = new class extends Data {
            #[Max(value: new ContainerReference('max-allowed-size'))]
            public int $property;
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'property' => [
                'required',
                'integer',
                'max:100',
            ],
        ]);
    }

    public function testCanSetTheValidatorToStopOnTheFirstFailure(): void
    {
        $dataClass = new class extends Data {
            #[Min(10)]
            public int $propertyA;

            #[Min(10)]
            public int $propertyB;

            /**
             * Stop validating after the first failure.
             */
            public static function stopOnFirstFailure(): bool
            {
                return true;
            }
        };

        DataValidationAsserter::for($dataClass)->assertRules([
            'propertyA' => ['required', 'integer', 'min:10'],
            'propertyB' => ['required', 'integer', 'min:10'],
        ])->assertErrors([
            'propertyA' => 0,
            'propertyB' => 0,
        ], ['propertyA' => [__('validation.min.numeric', ['attribute' => 'property a', 'min' => '10'])]]);
    }

    public function testCanManuallySetValidationMessages(): void
    {
        $data = new class extends Data {
            public string $name;

            public string $song;

            /**
             * Get the validation messages.
             */
            public static function messages(): array
            {
                return [
                    'name.required' => 'Fix it Rick!',
                ];
            }
        };

        DataValidationAsserter::for($data)
            ->assertMessages(
                messages: ['name.required' => 'Fix it Rick!'],
                payload: ['song' => 'Never Gonna Give You Up'],
            )
            ->assertErrors(
                payload: ['song' => 'Never Gonna Give You Up'],
                errors: ['name' => ['Fix it Rick!']]
            );

        $data = new class extends Data {
            public string $name;

            public string $song;

            /**
             * Get the validation messages.
             */
            public static function messages(): array
            {
                return [
                    'name' => ['required' => 'Fix it Rick!'],
                ];
            }
        };

        DataValidationAsserter::for($data)
            ->assertMessages(
                messages: ['name' => ['required' => 'Fix it Rick!']],
                payload: ['song' => 'Never Gonna Give You Up'],
            )
            ->assertErrors(
                payload: ['song' => 'Never Gonna Give You Up'],
                errors: ['name' => ['Fix it Rick!']]
            );

        $data = new class extends Data {
            public string $name;

            public string $song;

            /**
             * Get the validation messages.
             */
            public static function messages(): array
            {
                return [
                    'required' => 'Fix it Rick!',
                ];
            }
        };

        DataValidationAsserter::for($data)
            ->assertMessages(
                messages: ['*.required' => 'Fix it Rick!'],
                payload: [],
            )
            ->assertErrors(
                payload: [],
                errors: ['name' => ['Fix it Rick!'], 'song' => ['Fix it Rick!']]
            );
    }

    public function testCanManuallySetMessagesNested(): void
    {
        DataValidationAsserter::for(new class extends Data {
            public TestNestedValidationMessagesDataA $nested;
        })
            ->assertMessages(
                messages: ['nested.name.required' => 'Fix it Rick!'],
                payload: ['nested' => ['song' => 'Never Gonna Give You Up']],
            )
            ->assertErrors(
                payload: ['nested' => ['song' => 'Never Gonna Give You Up']],
                errors: ['nested.name' => ['Fix it Rick!']]
            );

        DataValidationAsserter::for(new class extends Data {
            public TestNestedValidationMessagesDataB $nested;
        })
            ->assertMessages(
                messages: ['nested.name' => ['required' => 'Fix it Rick!']],
                payload: ['nested' => ['song' => 'Never Gonna Give You Up']],
            )
            ->assertErrors(
                payload: ['nested' => ['song' => 'Never Gonna Give You Up']],
                errors: ['nested.name' => ['Fix it Rick!']]
            );

        DataValidationAsserter::for(new class extends Data {
            public TestNestedValidationMessagesDataC $nested;
        })
            ->assertMessages(
                messages: ['nested.*.required' => 'Fix it Rick!'],
                payload: ['nested' => []],
            )
            ->assertErrors(
                payload: ['nested' => []],
                errors: [
                    'nested' => [__('validation.required', ['attribute' => 'nested'])],
                    'nested.name' => ['Fix it Rick!'],
                    'nested.song' => ['Fix it Rick!'],
                ]
            );

        DataValidationAsserter::for(new class extends Data {
            public TestNestedValidationMessagesDataC $nested;

            /**
             * Get the validation messages.
             */
            public static function messages(mixed ...$args): array
            {
                return [
                    'required' => 'Fix it Rick root!',
                ];
            }
        })
            ->assertMessages(
                messages: [
                    '*.required' => 'Fix it Rick root!',
                    'nested.*.required' => 'Fix it Rick!',
                ],
                payload: ['nested' => []],
            )
            ->assertErrors(
                payload: ['nested' => []],
                errors: [
                    'nested' => ['Fix it Rick root!'],
                    'nested.name' => ['Fix it Rick!'],
                    'nested.song' => ['Fix it Rick!'],
                ]
            );
    }

    public function testCanManuallySetMessagesInCollections(): void
    {
        DataValidationAsserter::for(new class extends Data {
            #[DataCollectionOf(TestCollectionValidationMessagesDataA::class)]
            public DataCollection $collection;
        })
            ->assertMessages(
                messages: ['collection.*.name.required' => 'Fix it Rick!'],
                payload: ['collection' => [['song' => 'Never Gonna Give You Up']]],
            )
            ->assertErrors(
                payload: [
                    'collection' => [
                        ['song' => 'Never Gonna Give You Up'],
                        ['song' => 'Together Forever'],
                    ],
                ],
                errors: [
                    'collection.0.name' => ['Fix it Rick!'],
                    'collection.1.name' => ['Fix it Rick!'],
                ]
            );

        DataValidationAsserter::for(new class extends Data {
            #[DataCollectionOf(TestCollectionValidationMessagesDataB::class)]
            public DataCollection $collection;
        })
            ->assertMessages(
                messages: ['collection.*.name' => ['required' => 'Fix it Rick!']],
                payload: ['collection' => [['song' => 'Never Gonna Give You Up']]],
            )
            ->assertErrors(
                payload: [
                    'collection' => [
                        ['song' => 'Never Gonna Give You Up'],
                        ['song' => 'Together Forever'],
                    ],
                ],
                errors: [
                    'collection.0.name' => ['Fix it Rick!'],
                    'collection.1.name' => ['Fix it Rick!'],
                ]
            );

        DataValidationAsserter::for(new class extends Data {
            #[DataCollectionOf(TestCollectionValidationMessagesDataC::class)]
            public DataCollection $collection;
        })
            ->assertMessages(
                messages: ['collection.*.*.required' => 'Fix it Rick!'],
                payload: ['collection' => [['song' => 'Never Gonna Give You Up']]],
            )
            ->assertErrors(
                payload: [
                    'collection' => [
                        ['song' => 'Never Gonna Give You Up'],
                        ['song' => 'Together Forever'],
                    ],
                ],
                errors: [
                    'collection.0.name' => ['Fix it Rick!'],
                    'collection.1.name' => ['Fix it Rick!'],
                ]
            );
    }

    public function testCanManuallySetMessagesInDoubleNestedCollectionsYeahThisFailedOnce(): void
    {
        DataValidationAsserter::for(new class extends Data {
            #[DataCollectionOf(TestDoubleNestedCollectionValidationMessagesInitialDataA::class)]
            public DataCollection $collection;
        })
            ->assertMessages(
                messages: ['collection.*.nestedCollection.*.name.required' => 'Fix it Rick!'],
                payload: ['collection' => [['nestedCollection' => ['collection' => [['song' => 'Never Gonna Give You Up']]]]]],
            )
            ->assertErrors(
                payload: [
                    'collection' => [
                        [
                            'nestedCollection' => [
                                ['song' => 'Never Gonna Give You Up'],
                                ['song' => 'Giving up on love'],
                            ],
                        ],
                        ['nestedCollection' => [['song' => 'Together Forever']]],
                    ],
                ],
                errors: [
                    'collection.0.nestedCollection.0.name' => ['Fix it Rick!'],
                    'collection.0.nestedCollection.1.name' => ['Fix it Rick!'],
                    'collection.1.nestedCollection.0.name' => ['Fix it Rick!'],
                ]
            );
    }

    public function testCanResolveValidationDependenciesForMessages(): void
    {
        FakeInjectable::setup('Rick Astley');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the validation messages from an injected dependency.
             */
            public static function messages(FakeInjectable $injectable): array
            {
                return [
                    'name.required' => $injectable->value === 'Rick Astley' ? 'Fix it Rick!' : 'Fix it!',
                ];
            }
        };

        DataValidationAsserter::for($data)->assertErrors(
            payload: ['name' => null],
            errors: ['name' => ['Fix it Rick!']]
        );
    }

    public function testCanManuallySetValidationAttributes(): void
    {
        $data = new class extends Data {
            public string $name;

            /**
             * Get the validation attribute names.
             */
            public static function attributes(): array
            {
                return [
                    'name' => 'rickster',
                ];
            }
        };

        DataValidationAsserter::for($data)
            ->assertAttributes([
                'name' => 'rickster',
            ])
            ->assertErrors(
                payload: ['name' => null],
                errors: ['name' => [__('validation.required', ['attribute' => 'rickster'])]]
            );
    }

    public function testCanManuallySetNestedValidationAttributes(): void
    {
        $data = new class extends Data {
            public TestNestedValidationAttributesData $nested;
        };

        DataValidationAsserter::for($data)
            ->assertAttributes(
                ['nested.name' => 'rickster'],
                payload: ['nested' => ['name' => null]]
            )
            ->assertErrors(
                payload: ['nested' => ['name' => null]],
                errors: ['nested.name' => [__('validation.required', ['attribute' => 'rickster'])]]
            );
    }

    public function testCanManuallySetCollectedValidationAttributes(): void
    {
        // Upstream skips this case as unsupported; the validator applies wildcard attribute names to each item.
        $data = new class extends Data {
            #[DataCollectionOf(TestCollectedValidationAttributesData::class)]
            public DataCollection $collection;
        };

        DataValidationAsserter::for($data)
            ->assertAttributes(
                ['collection.*.name' => 'rickster'],
                payload: ['collection' => [['name' => null]]],
            )
            ->assertErrors(
                payload: ['collection' => [['name' => null]]],
                errors: ['collection.0.name' => [__('validation.required', ['attribute' => 'rickster'])]]
            );
    }

    public function testCanResolveValidationDependenciesForAttributes(): void
    {
        FakeInjectable::setup('Rick Astley');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the validation attribute names from an injected dependency.
             */
            public static function attributes(FakeInjectable $injectable): array
            {
                return [
                    'name' => $injectable->value === 'Rick Astley' ? 'rickster' : 'someone',
                ];
            }
        };

        DataValidationAsserter::for($data)->assertErrors(
            payload: ['name' => null],
            errors: ['name' => [__('validation.required', ['attribute' => 'rickster'])]]
        );
    }

    public function testCanManuallySetTheRedirectUrl(): void
    {
        $data = new class extends Data {
            public string $name;

            /**
             * Get the redirect URL for failed validation.
             */
            public static function redirect(): string
            {
                return '/never-given-up';
            }
        };

        // As with a Laravel FormRequest, the redirect is generated as an absolute URL.
        DataValidationAsserter::for($data)->assertRedirect(
            payload: ['name' => null],
            redirect: 'http://localhost/never-given-up'
        );
    }

    public function testCanResolveValidationDependenciesForRedirectUrl(): void
    {
        FakeInjectable::setup('Rick Astley');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the redirect URL from an injected dependency.
             */
            public static function redirect(FakeInjectable $injectable): string
            {
                return $injectable->value === 'Rick Astley' ? '/never-given-up' : '/given-up';
            }
        };

        DataValidationAsserter::for($data)->assertRedirect(
            payload: ['name' => null],
            redirect: 'http://localhost/never-given-up'
        );
    }

    public function testCanManuallySetTheRedirectRoute(): void
    {
        Route::get('/never-given-up', fn (): string => 'Never gonna give you up')->name('never-given-up');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the redirect route for failed validation.
             */
            public static function redirectRoute(): string
            {
                return 'never-given-up';
            }
        };

        DataValidationAsserter::for($data)->assertRedirect(
            payload: ['name' => null],
            redirect: 'http://localhost/never-given-up'
        );
    }

    public function testCanResolveValidationDependenciesForRedirectRoute(): void
    {
        FakeInjectable::setup('Rick Astley');

        Route::get('/never-given-up', fn (): string => 'Never gonna give you up')->name('never-given-up');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the redirect route from an injected dependency.
             */
            public static function redirectRoute(FakeInjectable $injectable): string
            {
                return $injectable->value === 'Rick Astley' ? 'never-given-up' : 'given-up';
            }
        };

        DataValidationAsserter::for($data)->assertRedirect(
            payload: ['name' => null],
            redirect: 'http://localhost/never-given-up'
        );
    }

    public function testCanManuallySpecifyTheValidator(): void
    {
        $dataClass = new class extends Data {
            public string $property;

            /**
             * Remove every rule from the validator.
             */
            public static function withValidator(Validator $validator): void
            {
                $validator->setRules([]);
            }
        };

        DataValidationAsserter::for($dataClass)->assertOk([]);
    }

    public function testCanResolveValidationDependenciesForErrorBag(): void
    {
        FakeInjectable::setup('Rick Astley');

        $data = new class extends Data {
            public string $name;

            /**
             * Get the error bag from an injected dependency.
             */
            public static function errorBag(FakeInjectable $injectable): string
            {
                return $injectable->value === 'Rick Astley' ? 'never-given-up' : 'given-up';
            }
        };

        DataValidationAsserter::for($data)->assertErrorBag(
            payload: ['name' => null],
            errorBag: 'never-given-up'
        );
    }

    public function testCanValidateAPayloadForADataObjectWithoutCreatingOne(): void
    {
        $this->assertSame(['string' => 'Hello World'], SimpleData::validate(['string' => 'Hello World']));

        try {
            SimpleData::validate(['string' => 10]);
            $this->fail('Expected the payload to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'string' => [__('validation.string', ['attribute' => 'string'])],
            ], $exception->errors());
        }
    }

    public function testCanValidateAPayloadForADataObjectAndCreateOne(): void
    {
        $data = SimpleData::validateAndCreate(['string' => 'Hello World']);

        $this->assertSame('Hello World', $data->string);

        try {
            SimpleData::validateAndCreate(['string' => 10]);
            $this->fail('Expected the payload to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'string' => [__('validation.string', ['attribute' => 'string'])],
            ], $exception->errors());
        }
    }

    public function testCanValidateAPayloadForADataObjectAndCreateOneUsingAMagicFromMethod(): void
    {
        $dataClass = new class extends Data {
            public string $string;

            /**
             * Create the data object from a request.
             */
            public static function fromRequest(Request $request): static
            {
                $self = new self;

                $self->string = strtoupper($request->input('string'));

                return $self;
            }
        };

        $data = $dataClass::validateAndCreate(
            (new Request)->merge(['string' => 'hello world']),
        );

        $this->assertSame('HELLO WORLD', $data->string);

        try {
            SimpleData::validateAndCreate(new Request);
            $this->fail('Expected the request to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'string' => [__('validation.required', ['attribute' => 'string'])],
            ], $exception->errors());
        }
    }

    public function testCanTheValidationRulesForADataObject(): void
    {
        $this->assertEquals([
            'first' => ['required', 'string'],
            'second' => ['required', 'string'],
        ], MultiData::getValidationRules([]));

        $this->assertEquals(['nested' => ['nullable', 'array']], NestedNullableData::getValidationRules(payload: []));

        $this->assertEquals([
            'nested' => ['nullable', 'array'],
            'nested.string' => ['required', 'string'],
        ], NestedNullableData::getValidationRules(payload: ['nested' => []]));
    }

    public function testCanApplyCustomRulesOntoArrayProperties(): void
    {
        // A readonly property must be promoted: PHP lets only the class itself assign it, so Hypervel
        // rejects an unpromoted one when the class is first used.
        $dataClass = new class([]) extends Data {
            /**
             * Create the data object.
             */
            public function __construct(
                #[Min(1)]
                #[Max(5)]
                public readonly array $emails,
            ) {
            }

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'emails.*' => ['email'],
                ];
            }
        };

        $this->assertEquals([
            'emails' => ['required', 'array', 'min:1', 'max:5'],
            'emails.*' => ['email'],
        ], $dataClass::getValidationRules([]));
    }

    public function testCanValidateDataWithCircularDependencies(): void
    {
        DataValidationAsserter::for(CircData::class)
            ->assertRules([
                'string' => ['required', 'string'],
                'ular' => ['nullable', 'array'],
            ]);

        DataValidationAsserter::for(CircData::class)
            ->assertOk([
                'string' => 'Hello World',
                'ular' => [
                    'string' => 'Hello World',
                    'circ' => [
                        'string' => 'Hello World',
                    ],
                ],
            ])
            ->assertRules([
                'string' => ['required', 'string'],
                'ular' => ['nullable', 'array'],
                'ular.string' => ['required', 'string'],
                'ular.circ' => ['nullable', 'array'],
                'ular.circ.string' => ['required', 'string'],
                'ular.circ.ular' => ['nullable', 'array'],
            ], payload: [
                'string' => 'Hello World',
                'ular' => [
                    'string' => 'Hello World',
                    'circ' => [
                        'string' => 'Hello World',
                    ],
                ],
            ]);
    }

    public function testCanValidateAPropertyWithCustomRulesAsArrayContainingRegexRuleWithPipe(): void
    {
        $dataClass = new class extends Data {
            public string $property;

            /**
             * Get the validation rules.
             */
            public static function rules(): array
            {
                return [
                    'property' => ['string', 'required', 'regex:/test|ok/'],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['string', 'required', 'regex:/test|ok/'],
            ])
            ->assertOk([
                'property' => 'ok',
            ]);
    }

    public function testCanHandleAStringAsWrongPayload(): void
    {
        DataValidationAsserter::for(NestedData::class)
            ->assertErrors(['hello world'])
            ->assertErrors([
                'simple' => 'hello-world',
            ]);
    }

    public function testCanUseHypervelDataValidationRulesInHypervelValidator(): void
    {
        $rules = [new Required, new StringType, new Max(10)];

        $validatorToPass = ValidatorFacade::make(
            [
                'property' => 'test',
            ],
            [
                'property' => $rules,
            ],
        );

        $validatorToFail = ValidatorFacade::make(
            [
                'property' => 'testLongerText',
            ],
            [
                'property' => $rules,
            ],
        );

        $this->assertTrue($validatorToPass->passes());
        $this->assertFalse($validatorToFail->passes());
    }

    public function testWontValidateDefaultValuesWhenTheyAreNotProvided(): void
    {
        $dataClass = new class extends Data {
            #[Min(10)]
            public string $default = 'Hello World';
        };

        // Absent, the default applies and only rules that skip a missing key are compiled, so declared
        // presence rules still work. Supplied, the value is required, so an empty string cannot skip them.
        DataValidationAsserter::for($dataClass)
            ->assertOk([])
            ->assertOk(['default' => 'Hi there in this world'])
            ->assertErrors(['default' => 'minimal'])
            ->assertErrors(['default' => null])
            ->assertErrors(['default' => ''])
            ->assertRules([
                'default' => ['string', 'min:10'],
            ], payload: [])
            ->assertRules([
                'default' => ['required', 'string', 'min:10'],
            ], ['default' => 'something']);
    }

    public function testWillValidateDefaultValuesWhenRulesAreOverwritten(): void
    {
        $dataClass = new class extends Data {
            public string $default = 'Hello World';

            /**
             * Get the validation rules.
             */
            public static function rules(ValidationContext $context): array
            {
                return [
                    'default' => ['required', 'string', 'min:10'],
                ];
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertErrors([])
            ->assertOk(['default' => 'Hi there in this world'])
            ->assertErrors(['default' => 'minimal'])
            ->assertErrors(['default' => null])
            ->assertRules([
                'default' => ['required', 'string', 'min:10'],
            ], payload: [])
            ->assertRules([
                'default' => ['required', 'string', 'min:10'],
            ], ['default' => 'something']);
    }

    public function testWillIgnoreDefaultValuesWhichAreOptional(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create the data object.
             */
            public function __construct(public string|Optional $property = new Optional)
            {
            }
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk([])
            ->assertOk(['property' => 'Hello World'])
            ->assertErrors(['property' => 123])
            ->assertErrors(['property' => null])
            ->assertRules(['property' => ['sometimes', 'string']]);
    }

    public function testAManualWrittenPresentAttributeRuleAlwaysOverwritesAGeneratedRequiredRule(): void
    {
        $dataClass = new class extends Data {
            #[Present]
            public array $array;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['array' => []])
            ->assertOk(['array' => ['a', 'b']])
            ->assertErrors(['array' => null])
            ->assertRules([
                'array' => ['array', 'present'],
            ], []);
    }

    public function testSupportsCustomValidationAttributes(): void
    {
        $dataClass = new class extends Data {
            #[PassThroughCustomValidationAttribute(['url'])]
            public string $url;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['url' => 'https://spatie.be'])
            ->assertErrors(['url' => 'nowp'])
            ->assertRules([
                'url' => ['required', 'string', 'url'],
            ], []);

        $dataClass = new class extends Data {
            #[PassThroughCustomValidationAttribute(['url', 'max:20'])]
            public string $url;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['url' => 'https://spatie.be'])
            ->assertErrors(['url' => 'nowp'])
            ->assertErrors(['url' => 'https://rubenvanassche.com'])
            ->assertRules([
                'url' => ['required', 'string', 'url', 'max:20'],
            ], []);

        $dataClass = new class extends Data {
            #[PassThroughCustomValidationAttribute([new HypervelIn(['a', 'b'])])]
            public string $something;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['something' => 'a'])
            ->assertOk(['something' => 'b'])
            ->assertErrors(['something' => 'c'])
            ->assertRules([
                'something' => ['required', 'string', new HypervelIn(['a', 'b'])],
            ], []);
    }

    public function testCanAddARequiringRuleOnAnAttributeWhichWillOverwriteTheOptionalType(): void
    {
        // Upstream skips this case until its rule inferrers are rewritten; Hypervel adds no
        // sometimes rule beside a declared requiring rule.
        $dataClass = new class extends Data {
            #[Required]
            #[BooleanType]
            public bool $success;

            #[RequiredIf('success', 'false')]
            #[StringType]
            public string $error = '';

            #[RequiredIf('success', 'true')]
            #[IntegerType]
            public Optional|int $id;
        };

        DataValidationAsserter::for($dataClass)
            ->assertOk(['success' => true, 'id' => 1])
            ->assertErrors(['success' => true]);
    }

    public function testCanValidateAnOptionalButNonexistsAttribute(): void
    {
        $dataClass = new class extends Data {
            public array|Optional|null $property;
        };

        $this->assertSame([], $dataClass::from()->toArray());
        $this->assertSame([], $dataClass::from([])->toArray());
        $this->assertSame(['property' => null], $dataClass::from(['property' => null])->toArray());
        $this->assertSame(['property' => []], $dataClass::from(['property' => []])->toArray());
        $this->assertSame([], $dataClass::validateAndCreate([])->toArray());
    }

    #[WithConfig('data.validation_strategy', ValidationStrategy::Always->value)]
    public function testIsPossibleToDefineTheValidationStrategyForEachDataObjectGloballyUsingConfig(): void
    {
        // The strategy is configuration read once at boot, so it is set before the application starts.
        // A factory still overrides it, as the default strategy does without the configuration.
        $dataClass = new class extends Data {
            #[In('Hello World')]
            public string $string;
        };

        $data = $dataClass::factory()->onlyValidateRequests()->from(['string' => 'Nowp']);

        $this->assertInstanceOf(Data::class, $data);
        $this->assertSame('Nowp', $data->string);

        $this->expectException(ValidationException::class);

        $dataClass::from(['string' => 'Nowp']);
    }

    public function testHandlesValidationWithMappedAttributes(): void
    {
        // Upstream skips this case because it generated rules for the mapped name; Hypervel validates the input name.
        $data = TestValidationWithClassMappedAttribute::factory()->alwaysValidate()->from([
            'some_property' => 1,
        ]);

        $this->assertSame(1, $data->someProperty);
    }

    public function testWillRemoveASometimesRuleGeneratedByAnOptionalTypeWhenManuallyRequiringSomething(): void
    {
        $dataClass = new class extends Data {
            #[RequiredWith('otherProperty')]
            public string|Optional $property;

            public ?string $otherProperty;
        };

        DataValidationAsserter::for($dataClass)
            ->assertRules([
                'property' => ['string', 'required_with:otherProperty'],
                'otherProperty' => ['nullable', 'string'],
            ], ['property' => 'Hello World', 'otherProperty' => 'Hello World'])
            ->assertOk([
                'property' => 'Hello World',
                'otherProperty' => 'Hello World',
            ])
            ->assertOk([
                'otherProperty' => null,
            ])
            ->assertErrors([
                'otherProperty' => 'Hello World',
            ]);
    }

    public function testItWillMergeValidationRules(): void
    {
        DataValidationAsserter::for(TestDataWithMergedRuleset::class)
            ->assertRules([
                'array_rules' => ['required', 'string', 'max:10', 'min:2', 'alpha'],
                'string_rules' => ['required', 'string', 'max:10', 'min:2', 'alpha'],
                'nested' => ['required', 'array'],
                'nested.string' => ['required', 'string', 'max:10', 'min:2'],
            ], [])
            ->assertOk([
                'array_rules' => 'Ruben',
                'string_rules' => 'Ruben',
                'nested' => ['string' => 'Ruben'],
            ])
            // Class rules apply in declaration order, so the properties they merge are listed after the nested rules.
            ->assertErrors([
                'array_rules' => 'r',
                'string_rules' => 'r',
                'nested' => ['string' => 'r'],
            ], [
                'nested.string' => [__('validation.min.string', ['attribute' => 'nested.string', 'min' => 2])],
                'array_rules' => [__('validation.min.string', ['attribute' => 'array rules', 'min' => 2])],
                'string_rules' => [__('validation.min.string', ['attribute' => 'string rules', 'min' => 2])],
            ])
            ->assertErrors([
                'array_rules' => 'rubenvanassche',
                'string_rules' => 'rubenvanassche',
                'nested' => ['string' => 'rubenvanassche'],
            ], [
                'nested.string' => [__('validation.max.string', ['attribute' => 'nested.string', 'max' => 10])],
                'array_rules' => [__('validation.max.string', ['attribute' => 'array rules', 'max' => 10])],
                'string_rules' => [__('validation.max.string', ['attribute' => 'string rules', 'max' => 10])],
            ])
            ->assertErrors([
                'array_rules' => null,
                'string_rules' => null,
                'nested' => ['string' => null],
            ], [
                'nested.string' => [__('validation.required', ['attribute' => 'nested.string'])],
                'array_rules' => [__('validation.required', ['attribute' => 'array rules'])],
                'string_rules' => [__('validation.required', ['attribute' => 'string rules'])],
            ]);
    }

    // Property-morphable validation

    public function testCanValidatePropertyMorphableData(): void
    {
        DataValidationAsserter::for(AbstractPropertyMorphableData::class)
            ->assertErrors([], [
                'variant' => ['The variant field is required.'],
            ])
            ->assertErrors([
                'variant' => 'c',
            ], [
                'variant' => [
                    'The selected variant is invalid.',
                    'The selected variant is invalid for morph.',
                ],
            ])
            ->assertErrors([
                'variant' => 'a',
            ], [
                'a' => ['The a field is required.'],
                'enum' => ['The enum field is required.'],
            ])
            ->assertErrors([
                'variant' => 'a',
                'a' => 'foo',
                'enum' => 'invalid',
            ], [
                'enum' => ['The selected enum is invalid.'],
            ])
            ->assertErrors([
                'variant' => 'b',
            ], [
                'b' => ['The b field is required.'],
            ])
            ->assertOk([
                'variant' => 'a',
                'a' => 'foo',
                'enum' => 'foo',
            ])
            ->assertOk([
                'variant' => 'b',
                'b' => 'foo',
            ]);
    }

    public function testAllowsNullablePropertyMorphableData(): void
    {
        DataValidationAsserter::for(new class extends Data {
            public ?AbstractPropertyMorphableData $morphable;
        })
            ->assertOk([])
            ->assertOk(['morphable' => null])
            ->assertErrors(['morphable' => []], [
                'morphable.variant' => ['The morphable.variant field is required.'],
            ]);
    }

    public function testCanValidateNestedPropertyMorphableData(): void
    {
        DataValidationAsserter::for(TestValidationNestedPropertyMorphableData::class)
            ->assertErrors([
                'nestedCollection' => [[]],
            ], [
                'nestedCollection.0.variant' => ['The nested collection.0.variant field is required.'],
            ])
            ->assertErrors([
                'nestedCollection' => [['variant' => 'c']],
            ], [
                'nestedCollection.0.variant' => [
                    'The selected nested collection.0.variant is invalid.',
                    'The selected nested collection.0.variant is invalid for morph.',
                ],
            ])
            ->assertErrors([
                'nestedCollection' => [['variant' => 'a'], ['variant' => 'b']],
            ], [
                'nestedCollection.0.a' => ['The nested collection.0.a field is required.'],
                'nestedCollection.0.enum' => ['The nested collection.0.enum field is required.'],
                'nestedCollection.1.b' => ['The nested collection.1.b field is required.'],
            ])
            ->assertErrors([
                'nestedCollection' => [['variant' => 'a', 'a' => 'foo', 'enum' => 'invalid']],
            ], [
                'nestedCollection.0.enum' => ['The selected nested collection.0.enum is invalid.'],
            ])
            ->assertOk([
                'nestedCollection' => [['variant' => 'a', 'a' => 'foo', 'enum' => 'foo'], ['variant' => 'b', 'b' => 'bar']],
            ]);
    }

    public function testCanValidatePropertyMorphableDataWithCustomMessagesAndAttributes(): void
    {
        DataValidationAsserter::for(TestValidationCustomMessagePropertyMorphableDataA::class)
            ->assertErrors([
                'variant' => 'a',
                'abstract_integer' => 2,
                'concrete_integer' => 2,
            ], [
                'concrete_integer' => ['Concrete class integer test message.'],
                'concrete_string' => ['The [Concrete String] field is required.'],
                'abstract_integer' => ['Abstract class integer test message.'],
                'abstract_string' => ['The [Abstract String] field is required.'],
            ]);
    }

    public function testCanValidateCollectionsOfPropertyMorphableDataWithCustomMessagesAndAttributes(): void
    {
        DataValidationAsserter::for(TestCustomMessagesInCollectionOfPropertyMorphableData::class)
            ->assertErrors([
                'items' => [
                    [
                        'variant' => 'a',
                        'abstract_integer' => 2,
                        'concrete_integer' => 2,
                    ],
                    [
                        'variant' => 'invalid',
                        'abstract_integer' => 2,
                        'concrete_integer' => 2,
                    ],
                    [
                        'variant' => 'b',
                        'abstract_integer' => 2,
                        'concrete_integer' => 2,
                    ],
                ]], [
                    'items.0.concrete_integer' => ['Concrete class A integer test message.'],
                    'items.0.concrete_string' => ['The [Concrete String A] field is required.'],
                    'items.0.abstract_integer' => ['Abstract class integer test message.'],
                    'items.0.abstract_string' => ['The [Abstract String] field is required.'],

                    'items.1.variant' => [
                        'The selected items.1.variant is invalid.',
                        'The selected items.1.variant is invalid for morph.',
                    ],
                    'items.1.abstract_integer' => ['Abstract class integer test message.'],
                    'items.1.abstract_string' => ['The [Abstract String] field is required.'],

                    'items.2.concrete_integer' => ['Concrete class B integer test message.'],
                    'items.2.concrete_string' => ['The [Concrete String B] field is required.'],
                    'items.2.abstract_integer' => ['Concrete Class B override of Abstract class integer test message.'],
                    'items.2.abstract_string' => ['The [Abstract String] field is required.'],
                ]);
    }

    public function testCanValidatePropertyMorphableDataWithADefault(): void
    {
        DataValidationAsserter::for(TestValidationAbstractPropertyMorphableDefaultData::class)
            ->assertErrors([], [
                'a' => ['The a field is required.'],
                'enum' => ['The enum field is required.'],
            ])
            ->assertOk([
                'a' => 'foo',
                'enum' => 'foo',
            ])
            ->assertOk([
                'variant' => 'a',
                'a' => 'foo',
                'enum' => 'foo',
            ]);
    }

    public function testCanValidatePropertyMorphableDataInsideAnArrayOfNonMorphableData(): void
    {
        DataValidationAsserter::for(TestValidationNestedMorphableCollectionData::class)
            ->assertOk([
                'items' => [
                    ['morphable' => ['type' => 'a', 'a_string' => 'foo', 'a_other_string' => 'bar']],
                    ['morphable' => null],
                    ['morphable' => ['type' => 'b', 'b_float' => 1500.5]],
                ],
            ])
            ->assertErrors([
                'items' => [
                    ['morphable' => ['type' => 'a']],
                    ['morphable' => ['type' => 'b']],
                    ['morphable' => ['type' => 'invalid']],
                ],
            ], [
                'items.0.morphable.a_string' => ['Data A string required test.'],
                'items.0.morphable.a_other_string' => ['The [A Other String] field is required.'],
                'items.1.morphable.b_float' => ['The items.1.morphable.b float field is required.'],
                'items.2.morphable.type' => ['The selected items.2.morphable.type is invalid for morph.'],
            ]);
    }
}

class TestValidationNestedPropertyMorphableData extends Data
{
    /**
     * Create the data object.
     */
    public function __construct(
        /** @var AbstractPropertyMorphableData[] */
        public ?DataCollection $nestedCollection,
    ) {
    }
}

abstract class TestValidationCustomMessageAbstractPropertyMorphableData extends Data implements PropertyMorphableData
{
    /**
     * Create the morphable data object.
     */
    public function __construct(
        #[PropertyForMorph]
        public PropertyMorphableEnum $variant,
        #[Max(1)]
        public int $abstract_integer,
        public string $abstract_string,
    ) {
    }

    /**
     * Resolve the concrete class for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant']) {
            PropertyMorphableEnum::A => TestValidationCustomMessagePropertyMorphableDataA::class,
            default => null,
        };
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'abstract_integer.max' => 'Abstract class integer test message.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            'abstract_string' => '[Abstract String]',
        ];
    }
}

class TestValidationCustomMessagePropertyMorphableDataA extends TestValidationCustomMessageAbstractPropertyMorphableData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(
        int $abstract_integer,
        string $abstract_string,
        #[Max(1)]
        public int $concrete_integer,
        public string $concrete_string,
    ) {
        parent::__construct(PropertyMorphableEnum::A, $abstract_integer, $abstract_string);
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            ...parent::messages(),
            'concrete_integer.max' => 'Concrete class integer test message.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            ...parent::attributes(),
            'concrete_string' => '[Concrete String]',
        ];
    }
}

class TestCustomMessagesInCollectionOfPropertyMorphableData extends Data
{
    /**
     * Create the data object.
     */
    public function __construct(
        /**
         * @var array<TestCustomMessageOfPropertyMorphableData>
         */
        public array $items,
    ) {
    }
}

abstract class TestCustomMessageOfPropertyMorphableData extends Data implements PropertyMorphableData
{
    /**
     * Create the morphable data object.
     */
    public function __construct(
        #[PropertyForMorph]
        public PropertyMorphableEnum $variant,
        #[Max(1)]
        public int $abstract_integer,
        public string $abstract_string,
    ) {
    }

    /**
     * Resolve the concrete class for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant']) {
            PropertyMorphableEnum::A => TestCustomMessagePropertyMorphableDataA::class,
            PropertyMorphableEnum::B => TestCustomMessagePropertyMorphableDataB::class,
            default => null,
        };
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'abstract_integer.max' => 'Abstract class integer test message.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            'abstract_string' => '[Abstract String]',
        ];
    }
}

class TestCustomMessagePropertyMorphableDataA extends TestCustomMessageOfPropertyMorphableData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(
        int $abstract_integer,
        string $abstract_string,
        #[Max(1)]
        public int $concrete_integer,
        public string $concrete_string,
    ) {
        parent::__construct(PropertyMorphableEnum::A, $abstract_integer, $abstract_string);
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            ...parent::messages(),
            'concrete_integer.max' => 'Concrete class A integer test message.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            ...parent::attributes(),
            'concrete_string' => '[Concrete String A]',
        ];
    }
}

class TestCustomMessagePropertyMorphableDataB extends TestCustomMessageOfPropertyMorphableData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(
        int $abstract_integer,
        string $abstract_string,
        #[Max(1)]
        public int $concrete_integer,
        public string $concrete_string,
    ) {
        parent::__construct(PropertyMorphableEnum::B, $abstract_integer, $abstract_string);
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            ...parent::messages(),
            'abstract_integer.max' => 'Concrete Class B override of Abstract class integer test message.',
            'concrete_integer.max' => 'Concrete class B integer test message.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            ...parent::attributes(),
            'concrete_string' => '[Concrete String B]',
        ];
    }
}

abstract class TestValidationAbstractPropertyMorphableDefaultData extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public PropertyMorphableEnum $variant = PropertyMorphableEnum::A;

    /**
     * Resolve the concrete class for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            PropertyMorphableEnum::A => TestValidationPropertyMorphableDefaultDataA::class,
            default => null,
        };
    }
}

class TestValidationPropertyMorphableDefaultDataA extends TestValidationAbstractPropertyMorphableDefaultData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(public string $a, public DummyBackedEnum $enum)
    {
        $this->variant = PropertyMorphableEnum::A;
    }
}

abstract class TestValidationNestedMorphableAbstractData extends Data implements PropertyMorphableData
{
    /**
     * Create the morphable data object.
     */
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete class for the type.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['type']) {
            'a' => TestValidationNestedMorphableDataA::class,
            'b' => TestValidationNestedMorphableDataB::class,
            default => null,
        };
    }
}

class TestValidationNestedMorphableDataA extends TestValidationNestedMorphableAbstractData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(
        public string $a_string,
        public string $a_other_string,
    ) {
        parent::__construct('a');
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'a_string.required' => 'Data A string required test.',
        ];
    }

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            'a_other_string' => '[A Other String]',
        ];
    }
}

class TestValidationNestedMorphableDataB extends TestValidationNestedMorphableAbstractData
{
    /**
     * Create the concrete data object.
     */
    public function __construct(
        public float $b_float,
    ) {
        parent::__construct('b');
    }
}

class TestValidationNestedMorphableWrapperData extends Data
{
    /**
     * Create the wrapper data object.
     */
    public function __construct(
        public ?TestValidationNestedMorphableAbstractData $morphable,
    ) {
    }
}

class TestValidationNestedMorphableCollectionData extends Data
{
    /**
     * Create the collection data object.
     */
    public function __construct(
        /** @var TestValidationNestedMorphableWrapperData[] */
        public array $items,
    ) {
    }
}

class TestValidationDataWithCollectionNestedDataWithFieldReference extends Data
{
    public DataWithReferenceFieldValidationAttribute $nested;
}

class TestDataWithRootReferenceFieldValidationAttribute extends Data
{
    #[RequiredIf(new FieldReference('check_string', true), true)]
    public string $string;
}

class CollectionClassA extends Data
{
    public SimpleData $nested;
}

class CollectionClassTable extends Data
{
    public ?SimpleData $nested;
}

class CollectionClassC extends Data
{
    public Optional|SimpleData $nested;
}

class CollectionClassK extends Data
{
    public DummyDataWithContextOverwrittenValidationRules $nested;
}

class ValidationTestDeepNestedDataWithContextOverwrittenRules extends Data
{
    public string $deep_string;

    #[Required]
    public bool $deep_validate_as_email;

    /**
     * Require an email when the item asks for it.
     */
    public static function rules(ValidationContext $context): array
    {
        return $context->payload['deep_validate_as_email'] ?? false
            ? ['deep_string' => ['required', 'string', 'email']]
            : [];
    }
}

class ValidationTestNestedDataWithContextOverwrittenRules extends Data
{
    public string $string;

    #[Required]
    public bool $validate_as_email;

    #[DataCollectionOf(ValidationTestDeepNestedDataWithContextOverwrittenRules::class), Required]
    public DataCollection $items;

    /**
     * Require an email when the item asks for it.
     */
    public static function rules(ValidationContext $context): array
    {
        return $context->payload['validate_as_email'] ?? false
            ? ['string' => ['required', 'string', 'email']]
            : [];
    }
}

class NestedClassJ extends Data
{
    public string $property;

    /**
     * Check the context passed to the nested data rules.
     */
    public static function rules(ValidationContext $context): array
    {
        $correct = $context->payload['property'] === 'J'
            && $context->fullPayload['property'] === 'Root'
            && $context->path->equals('nested.data');

        if (! $correct) {
            throw new Exception('Should not end up here');
        }

        return [];
    }
}

class NestedClassK extends Data
{
    public string $property;

    /**
     * Check the context passed to the rules of data nested in a collection item.
     */
    public static function rules(ValidationContext $context): array
    {
        $correct = $context->payload['property'] === 'K'
            && $context->fullPayload['property'] === 'Root'
            && $context->path->equals('nested.collection.0.nested');

        if (! $correct) {
            throw new Exception('Should not end up here');
        }

        return [];
    }
}

class NestedClassL extends Data
{
    public string $property;

    /**
     * Check the context passed to the rules of a nested collection item.
     */
    public static function rules(ValidationContext $context): array
    {
        $correct = $context->payload['property'] === 'L'
            && $context->fullPayload['property'] === 'Root'
            && $context->path->equals('nested.collection.0.collection.0');

        if (! $correct) {
            throw new Exception('Should not end up here');
        }

        return [];
    }
}

class NestedClassM extends Data
{
    public string $property;

    public NestedClassK $nested;

    #[DataCollectionOf(NestedClassL::class)]
    public DataCollection $collection;

    /**
     * Check the context passed to the collection item rules.
     */
    public static function rules(ValidationContext $context): array
    {
        $correct = $context->payload['property'] === 'M'
            && $context->fullPayload['property'] === 'Root'
            && $context->path->equals('nested.collection.0');

        if (! $correct) {
            throw new Exception('Should not end up here');
        }

        return [];
    }
}

class NestedClassN extends Data
{
    public string $property;

    public NestedClassJ $data;

    #[DataCollectionOf(NestedClassM::class)]
    public DataCollection $collection;

    /**
     * Check the context passed to the nested rules.
     */
    public static function rules(ValidationContext $context): array
    {
        $correct = $context->payload['property'] === 'N'
            && $context->fullPayload['property'] === 'Root'
            && $context->path->equals('nested');

        if (! $correct) {
            throw new Exception('Should not end up here');
        }

        return [];
    }
}

class TestNestedValidationMessagesDataA extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Fix it Rick!',
        ];
    }
}

class TestNestedValidationMessagesDataB extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'name' => ['required' => 'Fix it Rick!'],
        ];
    }
}

class TestNestedValidationMessagesDataC extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'required' => 'Fix it Rick!',
        ];
    }
}

class TestCollectionValidationMessagesDataA extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Fix it Rick!',
        ];
    }
}

class TestCollectionValidationMessagesDataB extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'name' => ['required' => 'Fix it Rick!'],
        ];
    }
}

class TestCollectionValidationMessagesDataC extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'required' => 'Fix it Rick!',
        ];
    }
}

class TestDoubleNestedCollectionValidationMessagesDataA extends Data
{
    public string $name;

    public string $song;

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Fix it Rick!',
        ];
    }
}

class TestDoubleNestedCollectionValidationMessagesInitialDataA extends Data
{
    #[DataCollectionOf(TestDoubleNestedCollectionValidationMessagesDataA::class)]
    public DataCollection $nestedCollection;
}

class TestNestedValidationAttributesData extends Data
{
    public string $name;

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            'name' => 'rickster',
        ];
    }
}

#[MergeValidationRules]
class TestNestedDataWithMergedRules extends SimpleData
{
    /**
     * Get the validation rules merged with the inferred rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'string' => ['max:10', 'min:2'],
        ];
    }
}

#[MergeValidationRules]
class TestDataWithMergedRuleset extends Data
{
    /**
     * Create the data object.
     */
    public function __construct(
        #[Max(10)]
        public string $array_rules,
        #[Max(10)]
        public string $string_rules,
        #[WithoutValidation]
        public string $without_validation,
        public TestNestedDataWithMergedRules $nested
    ) {
    }

    /**
     * Get the validation rules merged with the inferred rules.
     */
    public static function rules(): array
    {
        return [
            'array_rules' => ['min:2', 'alpha'],
            'string_rules' => 'min:2|alpha',
        ];
    }
}

#[MapInputName(SnakeCaseMapper::class)]
class TestValidationWithClassMappedAttribute extends Data
{
    /**
     * Create the mapped data object.
     */
    public function __construct(
        #[Required]
        public readonly int $someProperty,
    ) {
    }
}

class TestCollectedValidationAttributesData extends Data
{
    public string $name;

    /**
     * Get the validation attribute names.
     */
    public static function attributes(): array
    {
        return [
            'name' => 'rickster',
        ];
    }
}
