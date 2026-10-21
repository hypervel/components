<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Wrapping\Wrap;
use Hypervel\Data\Support\Wrapping\WrapType;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\TestCase;

class DataContextTest extends TestCase
{
    // Spatie's DataContext is not included; an object's partial definitions and wrap are its own state, so this
    // asserts they survive serialization with the object. Spatie's pointed partial has no equivalent, since
    // partial paths have no pointer.

    public function testCanSerializeAndDeserializeADataContext(): void
    {
        $data = (new SimpleData('Hello'))
            ->include('basic')
            ->includePermanently('permanent')
            ->includeWhen('conditional', static fn (SimpleData $data): bool => $data->string === 'Hello')
            ->include('nested.field', '*')
            ->wrap('key');

        /** @var SimpleData $restored */
        $restored = unserialize(serialize($data));

        $this->assertEquals(new Wrap(WrapType::Defined, 'key'), $restored->getWrap());
        $this->assertSame([
            ['basic', false],
            ['permanent', true],
            ['conditional', false],
            ['nested.field', false],
            ['*', false],
        ], self::includes($restored, $restored));
        $this->assertSame([
            ['basic', false],
            ['permanent', true],
            ['nested.field', false],
            ['*', false],
        ], self::includes($restored, new SimpleData('Other')));
    }

    /**
     * Get the include paths and lifetimes that apply to an object.
     *
     * @return list<array{string, bool}>
     */
    private static function includes(SimpleData $owner, object $data): array
    {
        return array_map(
            static fn (PartialDefinition $definition): array => [$definition->path, $definition->permanent],
            $owner->getPartialsDefinition()->resolve($data)['include'],
        );
    }
}
