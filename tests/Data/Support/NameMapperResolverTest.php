<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Mappers\CamelCaseMapper;
use Hypervel\Data\Mappers\ProvidedNameMapper;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Mappers\StudlyCaseMapper;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Factories\DataAttributesCollectionFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use ReflectionProperty;

class NameMapperResolverTest extends TestCase
{
    /**
     * Get package providers for the name mapper test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetAnInputAndOutputMapper(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new ProvidedNameMapper('input'),
            'outputNameMapper' => new ProvidedNameMapper('output'),
        ], $this->mappers(new class {
            #[MapInputName('input'), MapOutputName('output')]
            public string $property;
        }));
    }

    public function testCanHaveNoMappers(): void
    {
        $this->assertSame([
            'inputNameMapper' => null,
            'outputNameMapper' => null,
        ], $this->mappers(new class {
            public string $property;
        }));
    }

    public function testCanHaveASingleMapAttribute(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new ProvidedNameMapper('input'),
            'outputNameMapper' => new ProvidedNameMapper('output'),
        ], $this->mappers(new class {
            #[MapName('input', 'output')]
            public string $property;
        }));
    }

    public function testCanOverwriteAGeneralMapAttribute(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new ProvidedNameMapper('input_overwritten'),
            'outputNameMapper' => new ProvidedNameMapper('output'),
        ], $this->mappers(new class {
            #[MapName('input', 'output'), MapInputName('input_overwritten')]
            public string $property;
        }));
    }

    public function testCanMapAnInt(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new ProvidedNameMapper(0),
            'outputNameMapper' => new ProvidedNameMapper(3),
        ], $this->mappers(new class {
            #[MapName(0, 3)]
            public string $property;
        }));
    }

    public function testCanMapAString(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new ProvidedNameMapper('hello'),
            'outputNameMapper' => new ProvidedNameMapper('world'),
        ], $this->mappers(new class {
            #[MapName('hello', 'world')]
            public string $property;
        }));
    }

    public function testCanMapAMapperClass(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new CamelCaseMapper,
            'outputNameMapper' => new SnakeCaseMapper,
        ], $this->mappers(new class {
            #[MapName(CamelCaseMapper::class, SnakeCaseMapper::class)]
            public string $property;
        }));
    }

    #[WithConfig('data.name_mapping_strategy.input', CamelCaseMapper::class)]
    #[WithConfig('data.name_mapping_strategy.output', SnakeCaseMapper::class)]
    public function testCanHaveDefaultMappers(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new CamelCaseMapper,
            'outputNameMapper' => new SnakeCaseMapper,
        ], $this->mappers(new class {
            public string $property;
        }));
    }

    #[WithConfig('data.name_mapping_strategy.input', CamelCaseMapper::class)]
    #[WithConfig('data.name_mapping_strategy.output', SnakeCaseMapper::class)]
    public function testInputNameMappersOnlyWorkWhenNoMappersAreSpecified(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new StudlyCaseMapper,
            'outputNameMapper' => new SnakeCaseMapper,
        ], $this->mappers(new class {
            #[MapInputName(StudlyCaseMapper::class)]
            public string $property;
        }));
    }

    #[WithConfig('data.name_mapping_strategy.input', CamelCaseMapper::class)]
    #[WithConfig('data.name_mapping_strategy.output', SnakeCaseMapper::class)]
    public function testOutputNameMappersOnlyWorkWhenNoMappersAreSpecified(): void
    {
        $this->assertEquals([
            'inputNameMapper' => new CamelCaseMapper,
            'outputNameMapper' => new StudlyCaseMapper,
        ], $this->mappers(new class {
            #[MapOutputName(StudlyCaseMapper::class)]
            public string $property;
        }));
    }

    public function testCanIgnoreCertainMapperTypes(): void
    {
        // Spatie ignores provided names through a resolver option; a class-level name is dropped by the class metadata.
        $property = $this->app->make(DataClassRepository::class)
            ->get(ClassLevelProvidedNameData::class)
            ->properties['someProperty'];

        $this->assertNull($property->inputMappedName);
        $this->assertSame('some_property', $property->outputMappedName);
    }

    /**
     * Resolve a property's input and output mappers with the configured defaults.
     *
     * @return array{inputNameMapper: mixed, outputNameMapper: mixed}
     */
    private function mappers(object $class): array
    {
        $resolver = $this->app->make(NameMapperResolver::class);
        $config = $this->app->make(DataConfig::class);
        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(new ReflectionProperty($class, 'property'));

        return [
            'inputNameMapper' => $resolver->resolveInput($attributes, $resolver->resolveConfigured($config->inputNameMapper)),
            'outputNameMapper' => $resolver->resolveOutput($attributes, $resolver->resolveConfigured($config->outputNameMapper)),
        ];
    }
}

#[MapInputName('input'), MapOutputName(SnakeCaseMapper::class)]
class ClassLevelProvidedNameData extends Data
{
    public string $someProperty;
}
