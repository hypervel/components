<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Config\Repository;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use ValueError;

class DataConfigTest extends TestCase
{
    public function testShippedConfigurationIsNormalized(): void
    {
        $config = $this->makeConfig();

        $this->assertSame([DATE_ATOM], $config->dateFormats);
        $this->assertNull($config->dateTimezone);
        $this->assertSame(ValidationStrategy::OnlyRequests, $config->validationStrategy);
        $this->assertNull($config->inputNameMapper);
        $this->assertNull($config->outputNameMapper);
        $this->assertSame([], $config->casts);
        $this->assertSame([], $config->transformers);
        $this->assertSame([], $config->normalizers);
        $this->assertNull($config->wrap);
        $this->assertNull($config->maxTransformationDepth);
    }

    public function testCustomConfigurationIsNormalizedAndValidated(): void
    {
        $config = $this->makeConfig([
            'date_format' => ['Y-m-d', DATE_ATOM],
            'date_timezone' => 'UTC',
            'validation_strategy' => ValidationStrategy::Always->value,
            'name_mapping_strategy' => [
                'input' => ConfigNameMapper::class,
                'output' => ConfigNameMapper::class,
            ],
            'casts' => [DateTimeInterface::class => ConfigCast::class],
            'transformers' => [BackedEnum::class => ConfigTransformer::class],
            'normalizers' => [ConfigNormalizer::class],
            'wrap' => 'payload',
            'max_transformation_depth' => 8,
        ]);

        $this->assertSame(['Y-m-d', DATE_ATOM], $config->dateFormats);
        $this->assertSame('UTC', $config->dateTimezone);
        $this->assertSame(ValidationStrategy::Always, $config->validationStrategy);
        $this->assertSame(ConfigNameMapper::class, $config->inputNameMapper);
        $this->assertSame(ConfigNameMapper::class, $config->outputNameMapper);
        $this->assertSame([DateTimeInterface::class => ConfigCast::class], $config->casts);
        $this->assertSame([BackedEnum::class => ConfigTransformer::class], $config->transformers);
        $this->assertSame([ConfigNormalizer::class], $config->normalizers);
        $this->assertSame('payload', $config->wrap);
        $this->assertSame(8, $config->maxTransformationDepth);
    }

    #[DataProvider('invalidScalarConfigurationProvider')]
    public function testInvalidScalarConfigurationFailsFast(array $overrides, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $this->makeConfig($overrides);
    }

    public static function invalidScalarConfigurationProvider(): iterable
    {
        yield 'empty date formats' => [
            ['date_format' => []],
            'Configuration [data.date_format] must be a string or a non-empty array of strings.',
        ];

        yield 'invalid date format member' => [
            ['date_format' => ['Y-m-d', false]],
            'Configuration [data.date_format] must be a string or a non-empty array of strings.',
        ];

        yield 'invalid timezone' => [
            ['date_timezone' => false],
            'Configuration [data.date_timezone] must be a string or null.',
        ];

        yield 'invalid wrapper' => [
            ['wrap' => []],
            'Configuration [data.wrap] must be a string or null.',
        ];

        yield 'invalid maximum depth' => [
            ['max_transformation_depth' => 0],
            'Configuration [data.max_transformation_depth] must be a positive integer or null.',
        ];

        yield 'missing output mapper' => [
            ['name_mapping_strategy' => ['input' => null]],
            'Configuration [data.name_mapping_strategy.output] is required.',
        ];
    }

    public function testInvalidValidationStrategyFailsFast(): void
    {
        $this->expectException(ValueError::class);

        $this->makeConfig(['validation_strategy' => 'sometimes']);
    }

    #[DataProvider('invalidExtensionProvider')]
    public function testInvalidExtensionsFailFast(array $overrides, string $key, string $contract): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            "Configuration [{$key}] extension [" . stdClass::class . "] must implement [{$contract}].",
        );

        $this->makeConfig($overrides);
    }

    public static function invalidExtensionProvider(): iterable
    {
        yield 'input mapper' => [
            ['name_mapping_strategy' => ['input' => stdClass::class, 'output' => null]],
            'data.name_mapping_strategy.input',
            NameMapper::class,
        ];

        yield 'cast' => [
            ['casts' => ['string' => stdClass::class]],
            'data.casts',
            Cast::class,
        ];

        yield 'transformer' => [
            ['transformers' => ['string' => stdClass::class]],
            'data.transformers',
            Transformer::class,
        ];

        yield 'normalizer' => [
            ['normalizers' => [stdClass::class]],
            'data.normalizers',
            Normalizer::class,
        ];
    }

    public function testMorphMapSupportsForwardAndReverseLookups(): void
    {
        $config = $this->makeConfig();

        $config->enforceMorphMap(['example' => ConfigMorphData::class]);
        $config->enforceMorphMap(['example' => ConfigMorphData::class]);

        $this->assertSame(ConfigMorphData::class, $config->getMorphedDataClass('example'));
        $this->assertSame('example', $config->getDataClassAlias(ConfigMorphData::class));
        $this->assertNull($config->getMorphedDataClass('missing'));
        $this->assertNull($config->getDataClassAlias(ConfigOtherMorphData::class));
    }

    public function testMorphMapRejectsAliasCollisionsAtomically(): void
    {
        $config = $this->makeConfig();
        $config->enforceMorphMap(['example' => ConfigMorphData::class]);

        try {
            $config->enforceMorphMap([
                'other' => ConfigOtherMorphData::class,
                'example' => ConfigOtherMorphData::class,
            ]);
            $this->fail('Expected the duplicate morph alias to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Data morph alias [example] is already mapped to [' . ConfigMorphData::class . '].',
                $exception->getMessage(),
            );
        }

        $this->assertSame(ConfigMorphData::class, $config->getMorphedDataClass('example'));
        $this->assertNull($config->getMorphedDataClass('other'));
    }

    public function testMorphMapRejectsClassCollisions(): void
    {
        $config = $this->makeConfig();
        $config->enforceMorphMap(['example' => ConfigMorphData::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Data morph class [' . ConfigMorphData::class . '] is already mapped to alias [example].',
        );

        $config->enforceMorphMap(['duplicate' => ConfigMorphData::class]);
    }

    #[DataProvider('invalidMorphMapProvider')]
    public function testInvalidMorphMapsFailFast(array $map, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $this->makeConfig()->enforceMorphMap($map);
    }

    public static function invalidMorphMapProvider(): iterable
    {
        yield 'numeric alias' => [
            [0 => ConfigMorphData::class],
            'Data morph aliases must be non-empty strings.',
        ];

        yield 'invalid class' => [
            ['example' => stdClass::class],
            'Data morph class [' . stdClass::class . '] must implement [' . BaseData::class . '].',
        ];
    }

    private function makeConfig(array $overrides = []): DataConfig
    {
        $defaults = require __DIR__ . '/../../src/data/config/data.php';

        return new DataConfig(new Repository([
            'data' => array_replace($defaults, $overrides),
        ]));
    }
}

abstract class ConfigCast implements Cast
{
}

abstract class ConfigMorphData implements BaseData
{
}

abstract class ConfigNameMapper implements NameMapper
{
}

abstract class ConfigNormalizer implements Normalizer
{
}

abstract class ConfigOtherMorphData implements BaseData
{
}

abstract class ConfigTransformer implements Transformer
{
}
