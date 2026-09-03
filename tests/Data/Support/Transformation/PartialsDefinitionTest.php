<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Partials\PartialsDefinition;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class PartialsDefinitionTest extends TestCase
{
    /**
     * Test empty state tracks every definition group and consumed temporaries.
     */
    public function testDeterminesWhetherDefinitionsAreEmpty(): void
    {
        $definitions = new PartialsDefinition;

        $this->assertTrue($definitions->isEmpty());

        foreach (['include', 'exclude', 'only', 'except'] as $type) {
            $definitions->add($type, $type);

            $this->assertFalse($definitions->isEmpty());

            $definitions->resolve(new stdClass, consumeTemporary: true);

            $this->assertTrue($definitions->isEmpty());
        }
    }

    /**
     * Test temporary definitions apply once while permanent definitions persist.
     */
    public function testConsumesOnlyTemporaryDefinitions(): void
    {
        $definitions = new PartialsDefinition;
        $definitions->add('include', 'temporary');
        $definitions->add('include', 'permanent', permanent: true);
        $data = new stdClass;

        $resolved = $definitions->resolve($data, consumeTemporary: true)['include'];

        $this->assertSame(['temporary', 'permanent'], self::paths($resolved));
        $this->assertFalse($resolved[0]->permanent);
        $this->assertTrue($resolved[1]->permanent);
        $this->assertSame(
            ['permanent'],
            self::paths($definitions->resolve($data, consumeTemporary: true)['include']),
        );
    }

    /**
     * Test conditional definitions evaluate against the current object.
     */
    public function testResolvesConditionalDefinitions(): void
    {
        $definitions = new PartialsDefinition;
        $definitions->add(
            'only',
            'enabled',
            condition: static fn (object $data): bool => $data->enabled,
        );
        $enabled = (object) ['enabled' => true];
        $disabled = (object) ['enabled' => false];

        $this->assertSame(['enabled'], self::paths($definitions->resolve($enabled)['only']));
        $this->assertSame([], self::paths($definitions->resolve($disabled)['only']));
    }

    /**
     * Test class defaults are permanent and preserve familiar keyed conditions.
     */
    public function testAddsPermanentClassDefaults(): void
    {
        $definitions = new PartialsDefinition;
        $definitions->addDefaults('exclude', [
            'always',
            'enabled' => true,
            'disabled' => false,
            'conditional' => static fn (object $data): bool => $data->enabled,
        ]);
        $data = (object) ['enabled' => true];

        $this->assertSame(
            ['always', 'enabled', 'conditional'],
            self::paths($definitions->resolve($data, consumeTemporary: true)['exclude']),
        );
        $this->assertSame(
            ['always', 'enabled', 'conditional'],
            self::paths($definitions->resolve($data, consumeTemporary: true)['exclude']),
        );
    }

    /**
     * Test resolved definitions retain their individual lifetimes when merged.
     */
    public function testAddsDefinitionsResolvedByAnEnclosingObject(): void
    {
        $definitions = new PartialsDefinition;
        $definitions->addResolved([
            'include' => [
                new PartialDefinition('temporary'),
                new PartialDefinition('permanent', permanent: true),
            ],
            'exclude' => [],
            'only' => [],
            'except' => [],
        ]);
        $data = new stdClass;

        $this->assertSame(
            ['temporary', 'permanent'],
            self::paths($definitions->resolve($data, consumeTemporary: true)['include']),
        );
        $this->assertSame(
            ['permanent'],
            self::paths($definitions->resolve($data, consumeTemporary: true)['include']),
        );
    }

    /**
     * Test conditional definitions survive PHP serialization.
     */
    public function testSerializesConditionalDefinitions(): void
    {
        $definitions = new PartialsDefinition;
        $definitions->add(
            'except',
            'secret',
            permanent: true,
            condition: static fn (object $data): bool => $data->hide,
        );

        /** @var PartialsDefinition $restored */
        $restored = unserialize(serialize($definitions));

        $this->assertSame(
            ['secret'],
            self::paths($restored->resolve((object) ['hide' => true])['except']),
        );
        $this->assertSame(
            [],
            self::paths($restored->resolve((object) ['hide' => false])['except']),
        );
    }

    /**
     * Test nested definitions retain their lifetime without their owner condition.
     */
    #[DataProvider('nestedDefinitionProvider')]
    public function testResolvesDefinitionsForNestedProperties(
        string $path,
        string $property,
        ?string $expected,
    ): void {
        $definition = new PartialDefinition(
            $path,
            permanent: true,
            condition: static fn (): bool => true,
        );

        $nested = $definition->nested($property);

        if ($expected === null) {
            $this->assertNull($nested);

            return;
        }

        $this->assertSame($expected, $nested->path);
        $this->assertTrue($nested->permanent);
        $this->assertNull($nested->condition);
    }

    /**
     * Provide nested definition paths.
     */
    public static function nestedDefinitionProvider(): array
    {
        return [
            'terminal selection' => ['nested', 'nested', null],
            'nested wildcard' => ['nested.*', 'nested', '*'],
            'root wildcard' => ['*', 'nested', '*'],
            'nested group' => ['nested.{a,b}', 'nested', '{a,b}'],
            'root group' => ['{nested,other}', 'nested', null],
            'different property' => ['other.value', 'nested', null],
        ];
    }

    /**
     * Test unknown definition groups fail clearly.
     */
    public function testRejectsUnknownPartialTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown partial type [unknown].');

        (new PartialsDefinition)->add('unknown', 'field');
    }

    /**
     * Get paths from resolved partial definitions.
     *
     * @param list<PartialDefinition> $definitions
     * @return list<string>
     */
    private static function paths(array $definitions): array
    {
        return array_map(
            static fn (PartialDefinition $definition): string => $definition->path,
            $definitions,
        );
    }
}
