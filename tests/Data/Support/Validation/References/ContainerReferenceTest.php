<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\References;

use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Validation\References\ContainerReference;
use Hypervel\Testbench\TestCase;

class ContainerReferenceTest extends TestCase
{
    /**
     * Get package providers for the container reference tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testResolvesAPropertyOfTheDependency(): void
    {
        $this->app->bind('upload-limits', static fn (): array => ['size' => 512]);

        $this->assertSame(512, (new ContainerReference('upload-limits', 'size'))->getValue());
    }

    public function testUnresolvableDependencyThrowsInsteadOfResolvingToNull(): void
    {
        $this->expectException(BindingResolutionException::class);

        ContainerReferenceMaxData::getValidationRules(['property' => 1]);
    }
}

class ContainerReferenceMaxData extends Data
{
    /**
     * Create a fixture limited by a container dependency.
     */
    public function __construct(
        #[Max(value: new ContainerReference('max-allowed-size'))]
        public int $property,
    ) {
    }
}
