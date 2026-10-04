<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\CreationFactoryTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\Validation\In;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Casts\MeaningOfLifeCast;
use Hypervel\Tests\Data\Fixtures\Casts\StringToUpperCast;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDto;
use Hypervel\Validation\ValidationException;

class CreationFactoryTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanDisableTheUseOfMagicalMethods(): void
    {
        $data = new class('', '') extends Data {
            /**
             * Create the data object from an identifier and a name.
             */
            public function __construct(
                public ?string $id,
                public string $name,
            ) {
            }

            /**
             * Create the data object from a payload with a hashed identifier.
             */
            public static function fromArray(array $payload): self
            {
                return new self(
                    id: $payload['hash_id'] ?? null,
                    name: $payload['name'],
                );
            }
        };

        $this->assertEquals(
            new $data(null, 'Taylor'),
            $data::factory()->withoutMagicalCreation()->from(['hash_id' => '1', 'name' => 'Taylor']),
        );
        $this->assertEquals(new $data('1', 'Taylor'), $data::from(['hash_id' => '1', 'name' => 'Taylor']));
    }

    public function testCanCreateDataIgnoringCertainMagicalMethods(): void
    {
        $data = new class('', '') extends Data {
            /**
             * Create the data object from an identifier and a name.
             */
            public function __construct(
                public ?string $id,
                public string $name,
            ) {
            }

            /**
             * Create the data object from a payload with a hashed identifier.
             */
            public static function fromArray(array $payload): self
            {
                return new self(
                    id: $payload['hash_id'] ?? null,
                    name: $payload['name'],
                );
            }
        };

        $this->assertEquals(
            new $data(null, 'Taylor'),
            $data::factory()->ignoreMagicalMethod('fromArray')->from(['hash_id' => '1', 'name' => 'Taylor']),
        );
        $this->assertEquals(
            new $data('1', 'Taylor'),
            $data::factory()->from(['hash_id' => '1', 'name' => 'Taylor']),
        );
    }

    public function testCanEnableTheValidationOfNonRequestPayloads(): void
    {
        $dataClass = new class extends Data {
            #[In('Hello World')]
            public string $string;
        };

        $payload = [
            'string' => 'nowp',
        ];

        $data = $dataClass::factory()->from($payload);

        $this->assertInstanceOf(Data::class, $data);
        $this->assertSame('nowp', $data->string);

        $this->expectException(ValidationException::class);

        $dataClass::factory()->alwaysValidate()->from($payload);
    }

    public function testCanDisableTheValidationRequestPayloads(): void
    {
        $dataClass = new class extends Data {
            #[In('Hello World')]
            public string $string;
        };

        $request = Request::create('/', 'POST', [
            'string' => 'nowp',
        ]);

        try {
            $dataClass::factory()->from($request);
            $this->fail('Expected the request payload to be validated.');
        } catch (ValidationException) {
        }

        $data = $dataClass::factory()->withoutValidation()->from($request);

        $this->assertInstanceOf(Data::class, $data);
        $this->assertSame('nowp', $data->string);
    }

    public function testCanDisablePropertyMapping(): void
    {
        $dataClass = new class extends Data {
            #[MapInputName('firstName')]
            public string $first_name;
        };

        // Upstream leaves the unmapped property uninitialized; Hypervel rejects a missing required property.
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('::$first_name] is missing');

        $dataClass::factory()->withoutPropertyNameMapping()->from(['firstName' => 'Taylor']);
    }

    public function testCanAddANewGlobalCast(): void
    {
        $data = SimpleData::factory()->withCast('string', new StringToUpperCast)->from([
            'string' => 'Hello World',
        ]);

        $this->assertSame('HELLO WORLD', $data->string);
    }

    public function testCanAddACollectionOfGlobalCasts(): void
    {
        $dataClass = new class extends Data {
            public string $string;

            public int $int;
        };

        $data = $dataClass::factory()->withCastCollection([
            'string' => new StringToUpperCast,
            'int' => new MeaningOfLifeCast,
        ])->from([
            'string' => 'Hello World',
            'int' => '123',
        ]);

        $this->assertSame('HELLO WORLD', $data->string);
        $this->assertSame(42, $data->int);
    }

    public function testCanCollectUsingAFactory(): void
    {
        $collection = SimpleData::factory()->withCast('string', new StringToUpperCast)->collect([
            ['string' => 'Hello World'],
            ['string' => 'Hello You'],
        ], Collection::class);

        $this->assertCount(2, $collection);
        $this->assertSame('HELLO WORLD', $collection->first()->string);
        $this->assertSame('HELLO YOU', $collection->last()->string);
    }

    public function testIsPossibleToPassAnotherCreationContextToAFactoryAsBase(): void
    {
        $baseCreationContext = SimpleData::factory()
            ->alwaysValidate()
            ->get();

        $creationContext = SimpleData::factory($baseCreationContext)->get();

        $this->assertSame(ValidationStrategy::Always, $creationContext->validationStrategy);
    }

    public function testABaseCreationContextSuppliesItsOptionsButNotItsHooks(): void
    {
        $normalizer = new class implements Normalizer {
            /**
             * Leave every value for the next normalizer.
             */
            public function normalize(mixed $value): ?array
            {
                return null;
            }
        };

        $base = SimpleData::factory()
            ->withoutValidation()
            ->withoutPropertyNameMapping()
            ->withoutMagicalCreation()
            ->ignoreMagicalMethod('fromString')
            ->withCast('string', StringToUpperCast::class)
            ->withNormalizers($normalizer)
            ->prepareData(static fn (array $payload): array => $payload)
            ->get();

        $factory = SimpleDto::factory($base);
        $context = $factory->get();

        $this->assertSame(SimpleDto::class, $context->dataClass);
        $this->assertSame(ValidationStrategy::Disabled, $context->validationStrategy);
        $this->assertFalse($context->mapPropertyNames);
        $this->assertTrue($context->disableMagicalCreation);
        $this->assertSame(['fromString'], $context->ignoredMagicalMethods);
        $this->assertSame(['string' => StringToUpperCast::class], $context->casts);
        $this->assertSame([$normalizer], $context->normalizers);
        $this->assertSame([], $context->prepareDataHooks);

        $customized = $factory->withCast('int', MeaningOfLifeCast::class)->get();

        $this->assertSame(ValidationStrategy::Disabled, $customized->validationStrategy);
        $this->assertSame(['string' => StringToUpperCast::class, 'int' => MeaningOfLifeCast::class], $customized->casts);

        $default = SimpleDto::factory()->get();

        $this->assertSame(ValidationStrategy::OnlyRequests, $default->validationStrategy);
        $this->assertSame([], $default->casts);
    }
}
