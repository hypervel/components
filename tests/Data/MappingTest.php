<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\MappingTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Mappers\CamelCaseMapper;
use Hypervel\Data\Mappers\LowerCaseMapper;
use Hypervel\Data\Mappers\ProvidedNameMapper;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Mappers\StudlyCaseMapper;
use Hypervel\Data\Mappers\UpperCaseMapper;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\DataWithMapper;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithMappedProperty;

class MappingTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanMapPropertyNamesWhenTransforming(): void
    {
        $data = new SimpleDataWithMappedProperty('hello');
        $dataCollection = SimpleDataWithMappedProperty::collect([
            ['description' => 'never'],
            ['description' => 'gonna'],
            ['description' => 'give'],
            ['description' => 'you'],
            ['description' => 'up'],
        ]);

        $dataClass = new class('hello', $data, $data, $dataCollection, $dataCollection) extends Data {
            /**
             * Create the data object with renamed and nested values.
             */
            public function __construct(
                #[MapOutputName('property')]
                public string $string,
                public SimpleDataWithMappedProperty $nested,
                #[MapOutputName('nested_other')]
                public SimpleDataWithMappedProperty $nested_renamed,
                #[DataCollectionOf(SimpleDataWithMappedProperty::class)]
                public array $nested_collection,
                #[MapOutputName('nested_other_collection'), DataCollectionOf(SimpleDataWithMappedProperty::class)]
                public array $nested_renamed_collection,
            ) {
            }
        };

        $collectionOutput = [
            ['description' => 'never'],
            ['description' => 'gonna'],
            ['description' => 'give'],
            ['description' => 'you'],
            ['description' => 'up'],
        ];

        $this->assertSame([
            'property' => 'hello',
            'nested' => [
                'description' => 'hello',
            ],
            'nested_other' => [
                'description' => 'hello',
            ],
            'nested_collection' => $collectionOutput,
            'nested_other_collection' => $collectionOutput,
        ], $dataClass->toArray());
    }

    public function testCanMapThePropertyNamesForTheWholeClassUsingOneAttributeWhenTransforming(): void
    {
        $data = DataWithMapper::from([
            'cased_property' => 'We are the knights who say, ni!',
            'data_cased_property' => ['string' => 'Bring us a, shrubbery!'],
            'data_collection_cased_property' => [
                ['string' => 'One that looks nice!'],
                ['string' => 'But not too expensive!'],
            ],
        ]);

        $this->assertSame([
            'cased_property' => 'We are the knights who say, ni!',
            'data_cased_property' => ['string' => 'Bring us a, shrubbery!'],
            'data_collection_cased_property' => [
                ['string' => 'One that looks nice!'],
                ['string' => 'But not too expensive!'],
            ],
        ], $data->toArray());
    }

    public function testCanTransformTheDataObjectWithoutMapping(): void
    {
        $data = new class('Freek') extends Data {
            /**
             * Create the data object from its name.
             */
            public function __construct(
                #[MapOutputName('snake_name')]
                public string $camelName
            ) {
            }
        };

        $this->assertSame(
            ['camelName' => 'Freek'],
            $data->transform(TransformationContextFactory::create()->withPropertyNameMapping(false)),
        );
    }

    public function testCanMapAnInputPropertyUsingStringWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something')]
            public string $mapped;
        };

        $data = $dataClass::from([
            'something' => 'We are the knights who say, ni!',
        ]);

        $this->assertSame('We are the knights who say, ni!', $data->mapped);
    }

    public function testCanMapAnInputPropertyInNestedObjectsUsingStringsWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('nested.something')]
            public string $mapped;
        };

        $data = $dataClass::from([
            'nested' => ['something' => 'We are the knights who say, ni!'],
        ]);

        $this->assertSame('We are the knights who say, ni!', $data->mapped);
    }

    public function testReplacesPropertiesWhenAMappedAlternativeExistsWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something')]
            public string $mapped;
        };

        $data = $dataClass::from([
            'mapped' => 'We are the knights who say, ni!',
            'something' => 'Bring us a, shrubbery!',
        ]);

        $this->assertSame('Bring us a, shrubbery!', $data->mapped);
    }

    public function testSkipsPropertiesItCannotFindWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something')]
            public string $mapped;
        };

        $data = $dataClass::from([
            'mapped' => 'We are the knights who say, ni!',
        ]);

        $this->assertSame('We are the knights who say, ni!', $data->mapped);
    }

    public function testCanUseIntegersToMapPropertiesWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName(1)]
            public string $mapped;
        };

        $data = $dataClass::from([
            'We are the knights who say, ni!',
            'Bring us a, shrubbery!',
        ]);

        $this->assertSame('Bring us a, shrubbery!', $data->mapped);
    }

    public function testCanUseIntegersToMapPropertiesInNestedDataWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('1.0')]
            public string $mapped;
        };

        $data = $dataClass::from([
            ['We are the knights who say, ni!'],
            ['Bring us a, shrubbery!'],
        ]);

        $this->assertSame('Bring us a, shrubbery!', $data->mapped);
    }

    public function testCanCombineIntegersAndStringsToMapPropertiesWhenCreating(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('lines.1')]
            public string $mapped;
        };

        $data = $dataClass::from([
            'lines' => [
                'We are the knights who say, ni!',
                'Bring us a, shrubbery!',
            ],
        ]);

        $this->assertSame('Bring us a, shrubbery!', $data->mapped);
    }

    public function testCanUseASpecialMappingClassWhichConvertsPropertyNamesBetweenStandards(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName(SnakeCaseMapper::class)]
            public string $mappedLine;
        };

        $data = $dataClass::from([
            'mapped_line' => 'We are the knights who say, ni!',
        ]);

        $this->assertSame('We are the knights who say, ni!', $data->mappedLine);
    }

    public function testCanUseMappedPropertiesToMagicallyCreateData(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something')]
            public SimpleData $mapped;
        };

        $data = $dataClass::from(new Collection([
            'something' => 'We are the knights who say, ni!',
        ]));

        $this->assertEquals(SimpleData::from('We are the knights who say, ni!'), $data->mapped);
    }

    public function testCanUseMappedPropertiesNestedToMagicallyCreateData(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something')]
            public SimpleDataWithMappedProperty $mapped;
        };

        $data = $dataClass::from(new Collection([
            'something' => [
                'description' => 'We are the knights who say, ni!',
            ],
        ]));

        $this->assertEquals(new SimpleDataWithMappedProperty('We are the knights who say, ni!'), $data->mapped);
    }

    public function testCanMapPropertiesWhenCreatingACollectionOfDataObjects(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something'), DataCollectionOf(SimpleData::class)]
            public array $mapped;
        };

        $data = $dataClass::from(new Collection([
            'something' => [
                'We are the knights who say, ni!',
                'Bring us a, shrubbery!',
            ],
        ]));

        $this->assertEquals(
            SimpleData::collect([
                'We are the knights who say, ni!',
                'Bring us a, shrubbery!',
            ]),
            $data->mapped,
        );
    }

    public function testCanMapPropertiesWhenCreatingANestedCollectionOfDataObjects(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('something'), DataCollectionOf(SimpleDataWithMappedProperty::class)]
            public array $mapped;
        };

        $data = $dataClass::from(new Collection([
            'something' => [
                ['description' => 'We are the knights who say, ni!'],
                ['description' => 'Bring us a, shrubbery!'],
            ],
        ]));

        $this->assertEquals(
            SimpleDataWithMappedProperty::collect([
                ['description' => 'We are the knights who say, ni!'],
                ['description' => 'Bring us a, shrubbery!'],
            ]),
            $data->mapped,
        );
    }

    public function testCanUseOneAttributeOnTheClassToMapPropertiesWhenCreating(): void
    {
        $data = DataWithMapper::from([
            'cased_property' => 'We are the knights who say, ni!',
            'data_cased_property' => ['string' => 'Bring us a, shrubbery!'],
            'data_collection_cased_property' => [
                ['string' => 'One that looks nice!'],
                ['string' => 'But not too expensive!'],
            ],
        ]);

        $this->assertSame('We are the knights who say, ni!', $data->casedProperty);
        $this->assertEquals(SimpleData::from('Bring us a, shrubbery!'), $data->dataCasedProperty);
        $this->assertEquals(
            SimpleData::collect([
                'One that looks nice!',
                'But not too expensive!',
            ]),
            $data->dataCollectionCasedProperty,
        );
    }

    public function testHasAMappersBuiltIn(): void
    {
        $data = new class extends Data {
            #[MapName(CamelCaseMapper::class)]
            public string $camel_case = 'camelCase';

            #[MapName(SnakeCaseMapper::class)]
            public string $snakeCase = 'snake_case';

            #[MapName(StudlyCaseMapper::class)]
            public string $studly_case = 'StudlyCase';

            #[MapName(LowerCaseMapper::class)]
            public string $lowercase = 'lowercase';

            #[MapName(UpperCaseMapper::class)]
            public string $uppercase = 'UPPERCASE';

            #[MapName(new ProvidedNameMapper('i_provided'))]
            public string $provided = 'provided';
        };

        $mapped = [
            'camelCase' => 'camelCase',
            'snake_case' => 'snake_case',
            'StudlyCase' => 'StudlyCase',
            'lowercase' => 'lowercase',
            'UPPERCASE' => 'UPPERCASE',
            'i_provided' => 'provided',
        ];

        $this->assertSame($mapped, $data->toArray());

        // Upstream supplies the defaults as input; distinct values show each mapped name is read.
        $created = $data::from(array_map(static fn (string $value): string => "{$value} input", $mapped));

        $this->assertSame('camelCase input', $created->camel_case);
        $this->assertSame('snake_case input', $created->snakeCase);
        $this->assertSame('StudlyCase input', $created->studly_case);
        $this->assertSame('lowercase input', $created->lowercase);
        $this->assertSame('UPPERCASE input', $created->uppercase);
        $this->assertSame('provided input', $created->provided);
    }
}
