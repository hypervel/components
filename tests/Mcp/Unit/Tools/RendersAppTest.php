<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Tools\RendersAppTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Attributes\RendersApp;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Ui\Enums\Visibility;
use Hypervel\Testbench\TestCase;

class RendersAppTest extends TestCase
{
    public function testIncludesMetaUiResourceUriWhenRendersAppAttributeIsPresent(): void
    {
        $array = (new RendersAppSpecTool)->toArray();

        $this->assertArrayHasKey('_meta', $array);
        $this->assertArrayHasKey('resourceUri', $array['_meta']['ui']);
        $this->assertStringStartsWith('ui://resources/', $array['_meta']['ui']['resourceUri']);
    }

    public function testIncludesDefaultVisibilityWhenRendersAppAttributeIsPresent(): void
    {
        $array = (new RendersAppSpecTool)->toArray();

        $this->assertSame(['model', 'app'], $array['_meta']['ui']['visibility']);
    }

    public function testSupportsCustomVisibility(): void
    {
        $array = (new AppOnlyTool)->toArray();

        $this->assertSame(['app'], $array['_meta']['ui']['visibility']);
    }

    public function testDoesNotIncludeMetaUiWhenRendersAppAttributeIsAbsent(): void
    {
        $tool = new class extends Tool {
            /**
             * Handle the tool request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $array = $tool->toArray();

        $this->assertArrayNotHasKey('_meta', $array);
    }

    public function testComputedUiMetadataDoesNotMutateTheSharedTool(): void
    {
        $tool = $this->app->make(RendersAppSpecTool::class);
        $tool->setMeta(['custom' => 'value']);

        $this->assertArrayHasKey('ui', $tool->toArray()['_meta']);
        $this->assertSame(['custom' => 'value'], $tool->meta());
        $this->assertSame('value', $tool->toArray()['_meta']['custom']);
    }
}

class RendersAppSpecWidgetResource extends AppResource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::text('<html></html>');
    }
}

#[RendersApp(resource: RendersAppSpecWidgetResource::class)]
class RendersAppSpecTool extends Tool
{
    protected string $description = 'A tool linked to a UI resource';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}

#[RendersApp(resource: RendersAppSpecWidgetResource::class, visibility: [Visibility::App])]
class AppOnlyTool extends Tool
{
    protected string $description = 'An app-only tool';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}
