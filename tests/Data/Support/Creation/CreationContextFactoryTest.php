<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation\CreationContextFactoryTest;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Creation\CreationContextFactory;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Casts\StringToUpperCast;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use PHPUnit\Framework\Attributes\DataProvider;

class CreationContextFactoryTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    #[DefineEnvironment('alwaysValidateByDefault')]
    public function testCreatesAContextFromTheConfig(): void
    {
        $context = SimpleData::factory()->get();

        $this->assertSame(SimpleData::class, $context->dataClass);
        $this->assertSame(ValidationStrategy::Always, $context->validationStrategy);
        $this->assertTrue($context->mapPropertyNames);
        $this->assertFalse($context->disableMagicalCreation);
        $this->assertTrue($context->useOptionalValues);
        $this->assertSame([], $context->ignoredMagicalMethods);
        $this->assertSame([], $context->casts);
    }

    #[DataProvider('factoryOptions')]
    public function testFactoryOptionsRebuildTheCreateContext(Closure $configure, string $option, mixed $expected): void
    {
        $factory = SimpleData::factory();
        $before = $factory->get();

        $configure($factory);
        $context = $factory->get();

        $this->assertNotSame($before, $context);
        $this->assertSame($context, $factory->get());
        $this->assertSame($expected, $context->{$option});
    }

    /**
     * Get each factory option with the context property it sets and the value it expects.
     */
    public static function factoryOptions(): iterable
    {
        $cast = new StringToUpperCast;
        $normalizer = new class implements Normalizer {
            /**
             * Leave every value for the next normalizer.
             */
            public function normalize(mixed $value): ?array
            {
                return null;
            }
        };
        $hook = static fn (mixed $value): mixed => $value;

        yield 'is possible to override the validation strategy' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->validationStrategy(ValidationStrategy::Disabled),
            'validationStrategy',
            ValidationStrategy::Disabled,
        ];

        yield 'is possible to disable validation' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutValidation(),
            'validationStrategy',
            ValidationStrategy::Disabled,
        ];

        yield 'is possible to only validate requests' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->alwaysValidate()->onlyValidateRequests(),
            'validationStrategy',
            ValidationStrategy::OnlyRequests,
        ];

        yield 'is possible to always validate' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->alwaysValidate(),
            'validationStrategy',
            ValidationStrategy::Always,
        ];

        yield 'is possible to disable property name mapping' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutPropertyNameMapping(),
            'mapPropertyNames',
            false,
        ];

        yield 'is possible to enable property name mapping' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutPropertyNameMapping()->withPropertyNameMapping(),
            'mapPropertyNames',
            true,
        ];

        yield 'is possible to disable magical creation' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutMagicalCreation(),
            'disableMagicalCreation',
            true,
        ];

        yield 'is possible to enable magical creation' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutMagicalCreation()->withMagicalCreation(),
            'disableMagicalCreation',
            false,
        ];

        yield 'is possible to disable optional values' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutOptionalValues(),
            'useOptionalValues',
            false,
        ];

        yield 'is possible to enable optional values' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withoutOptionalValues()->withOptionalValues(),
            'useOptionalValues',
            true,
        ];

        yield 'is possible to set ignored magical methods' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->ignoreMagicalMethod('foo', 'bar'),
            'ignoredMagicalMethods',
            ['foo', 'bar'],
        ];

        yield 'is possible to add a cast' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withCast('string', StringToUpperCast::class),
            'casts',
            ['string' => StringToUpperCast::class],
        ];

        yield 'is possible to add a cast collection' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory
                ->withCast(Stringable::class, StringToUpperCast::class)
                ->withCastCollection(['string' => $cast]),
            'casts',
            [Stringable::class => StringToUpperCast::class, 'string' => $cast],
        ];

        yield 'is possible to add normalizers' => [
            static fn (CreationContextFactory $factory): CreationContextFactory => $factory->withNormalizers($normalizer),
            'normalizers',
            [$normalizer],
        ];

        foreach ([
            'prepareData' => 'prepareDataHooks',
            'beforeValidation' => 'beforeValidationHooks',
            'beforeRules' => 'beforeRulesHooks',
            'afterRules' => 'afterRulesHooks',
            'withValidator' => 'withValidatorHooks',
            'afterValidation' => 'afterValidationHooks',
            'beforeCreation' => 'beforeCreationHooks',
            'afterCreation' => 'afterCreationHooks',
        ] as $method => $option) {
            yield "is possible to add a {$method} hook" => [
                static fn (CreationContextFactory $factory): CreationContextFactory => $factory->{$method}($hook),
                $option,
                [$hook],
            ];
        }
    }

    /**
     * Validate every source by default.
     */
    protected function alwaysValidateByDefault(Application $app): void
    {
        $app->make('config')->set('data.validation_strategy', ValidationStrategy::Always->value);
    }
}
