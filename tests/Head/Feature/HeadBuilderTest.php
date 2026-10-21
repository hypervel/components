<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\HeadData;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Head\Schema\SchemaObject;
use Hypervel\Head\Tags\Schemas;
use Hypervel\Head\Tags\Title;
use Hypervel\Tests\Head\Fixtures\JobPosting;
use Hypervel\Tests\Head\TestCase;

class HeadBuilderTest extends TestCase
{
    public function testBuildsHeadDataWithoutTheContainer(): void
    {
        $data = new HeadData;

        (new HeadBuilder($data))
            ->title('About')
            ->description('About Acme.')
            ->schema(fn (SchemaFactory $schema): SchemaObject => $schema->make('webPage')->set('name', 'About'));

        $this->assertFalse($data->isEmpty());
        $this->assertNotNull($data->get(Title::class));
        $this->assertNotNull($data->get(Schemas::class));
    }

    public function testAppliesConditionalDefinitionsWithWhenAndUnless(): void
    {
        $data = new HeadData;

        (new HeadBuilder($data))
            ->when(true, fn (HeadBuilder $head): HeadBuilder => $head->title('Conditional'))
            ->unless(true, fn (HeadBuilder $head): HeadBuilder => $head->description('Skipped'));

        $this->assertNotNull($data->get(Title::class));
        $this->assertFalse($data->isEmpty());
    }

    public function testSupportsConditionalRuntimeDefinitionsThroughTheFacade(): void
    {
        Head::when(true, fn (HeadManager $head): HeadManager => $head->title('Conditional'));
        Head::unless(true, fn (HeadManager $head): HeadManager => $head->description('Skipped'));

        $html = Head::toHtml();
        $this->assertStringContainsString('<title>Conditional</title>', $html);
        $this->assertStringNotContainsString('Skipped', $html);
    }

    public function testReceivesAHeadBuilderInDefaultsCallbacks(): void
    {
        Head::defaults(function (HeadBuilder $head): void {
            $head->title('Acme', suffix: ' - Acme');
        });

        Head::title('About');

        $this->assertStringContainsString('<title>About - Acme</title>', Head::toHtml());
    }

    public function testChainsRuntimeDefinitionsIntoRendering(): void
    {
        $html = Head::title('About')
            ->description('About Acme.')
            ->toHtml();

        $this->assertStringContainsString('<title>About</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="About Acme.">', $html);
    }

    public function testDirectBuildersUseTheRegisteredSchemaFactory(): void
    {
        $factory = $this->app->make(SchemaFactory::class);
        $factory->register(JobPosting::class);

        (new HeadBuilder(new HeadData))->schema(function (SchemaFactory $resolved) use ($factory): SchemaObject {
            $this->assertSame($factory, $resolved);
            $this->assertInstanceOf(JobPosting::class, $resolved->make('jobPosting'));

            return $resolved->make('jobPosting')->title('Developer');
        });
    }

    public function testExplicitSchemaFactoryTakesPrecedence(): void
    {
        $factory = new SchemaFactory;

        (new HeadBuilder(new HeadData, $factory))->schema(function (SchemaFactory $resolved) use ($factory): SchemaObject {
            $this->assertSame($factory, $resolved);

            return $resolved->make('webPage')->set('name', 'About');
        });
    }
}
