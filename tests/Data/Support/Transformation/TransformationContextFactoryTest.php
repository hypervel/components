<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Data\Support\Wrapping\WrapExecutionType;
use Hypervel\Testbench\TestCase;
use stdClass;

class TransformationContextFactoryTest extends TestCase
{
    /**
     * Get package providers for the transformation test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test the fluent factory builds one immutable operation context.
     */
    public function testBuildsConfiguredTransformationContext(): void
    {
        $data = (object) ['enabled' => true];
        $context = TransformationContextFactory::create()
            ->withoutValueTransformation()
            ->withoutPropertyNameMapping()
            ->include('profile.avatar')
            ->includeWhen(
                'profile.permanent',
                static fn (object $owner): bool => $owner->enabled,
                permanent: true,
            )
            ->exclude('profile.secret')
            ->only('profile.{name,email}')
            ->except('profile.password')
            ->maxDepth(4)
            ->get($data);

        $this->assertFalse($context->transformValues);
        $this->assertFalse($context->mapPropertyNames);
        $this->assertSame(4, $context->maxDepth);
        $this->assertTrue($context->hasPartials());
        $this->assertTrue($context->include?->child('profile')?->selects('avatar'));
        $this->assertTrue($context->exclude?->child('profile')?->selects('secret'));
        $this->assertTrue($context->only?->child('profile')?->selects('name'));
        $this->assertTrue($context->only?->child('profile')?->selects('email'));
        $this->assertTrue($context->except?->child('profile')?->selects('password'));

        $nested = $context->partialsForNestedProperty('profile');

        $this->assertSame(
            ['avatar', 'permanent'],
            array_map(
                static fn (PartialDefinition $definition): string => $definition->path,
                $nested['include'],
            ),
        );
        $this->assertFalse($nested['include'][0]->permanent);
        $this->assertTrue($nested['include'][1]->permanent);
        $this->assertNull($nested['include'][1]->condition);
    }

    /**
     * Test each static factory call resolves a fresh transient instance.
     */
    public function testCreateReturnsFreshFactories(): void
    {
        $first = TransformationContextFactory::create()->maxDepth(1);
        $second = TransformationContextFactory::create();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $first->get(new stdClass)->maxDepth);
        $this->assertNull($second->get(new stdClass)->maxDepth);
    }

    public function testPersistenceFactoryDerivesACompleteConstructableView(): void
    {
        $data = (new PersistenceContextData('Taylor', 'secret'))->only('name');

        $context = TransformationContextFactory::forPersistence()
            ->withoutValueTransformation()
            ->withPropertyNameMapping()
            ->withWrapping()
            ->only('name')
            ->maxDepth(5)
            ->get($data);

        $this->assertTrue($context->transformValues);
        $this->assertFalse($context->mapPropertyNames);
        $this->assertTrue($context->constructable);
        $this->assertTrue($context->include?->all);
        $this->assertNull($context->exclude);
        $this->assertNull($context->only);
        $this->assertNull($context->except);
        $this->assertSame(WrapExecutionType::Disabled, $context->wrapExecutionType);
        $this->assertNull($context->maxDepth);
        $this->assertFalse($data->getPartialsDefinition()->isEmpty());
        $this->assertSame(['name' => 'Taylor'], $data->toArray());
    }
}

class PersistenceContextData extends Data
{
    public function __construct(
        public string $name,
        public string $secret,
    ) {
    }
}
