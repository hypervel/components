<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Attributes\AppMeta as AppMetaAttribute;
use Hypervel\Mcp\Server\Ui\AppMeta;
use Hypervel\Mcp\Server\Ui\Csp;
use Hypervel\Mcp\Server\Ui\Enums\Permission;
use Hypervel\Mcp\Server\Ui\Permissions;
use Hypervel\Testbench\TestCase;

class AppResourceTest extends TestCase
{
    public function testDefaultsToMcpAppMimeType(): void
    {
        $resource = new class extends AppResource {
            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $this->assertSame('text/html;profile=mcp-app', $resource->mimeType());
    }

    public function testDefaultsToUiUriScheme(): void
    {
        $resource = new class extends AppResource {
            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $this->assertStringStartsWith('ui://resources/', $resource->uri());
    }

    public function testIncludesMetaUiInToArrayOutput(): void
    {
        $resource = new class extends AppResource {
            /**
             * Get the application metadata.
             */
            public function appMeta(): AppMeta
            {
                return AppMeta::make()->prefersBorder();
            }

            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertArrayHasKey('_meta', $array);
        $this->assertArrayHasKey('ui', $array['_meta']);
        $this->assertTrue($array['_meta']['ui']['prefersBorder']);
    }

    public function testIncludesCustomUiMetaInToArray(): void
    {
        $resource = new class extends AppResource {
            /**
             * Get the application metadata.
             */
            public function appMeta(): AppMeta
            {
                return AppMeta::make()
                    ->csp(Csp::make()->connectDomains(['https://api.example.com']))
                    ->permissions(Permissions::make()->clipboardWrite())
                    ->domain('sandbox.example.com')
                    ->prefersBorder(false);
            }

            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertEquals([
            'csp' => ['connectDomains' => ['https://api.example.com']],
            'permissions' => ['clipboardWrite' => (object) []],
            'domain' => 'sandbox.example.com',
            'prefersBorder' => false,
        ], $array['_meta']['ui']);
    }

    public function testAllowsCustomUriOverride(): void
    {
        $resource = new class extends AppResource {
            protected string $uri = 'ui://custom/my-widget';

            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $this->assertSame('ui://custom/my-widget', $resource->uri());
    }

    public function testSerializesNameTitleDescriptionAndMimeType(): void
    {
        $resource = new class extends AppResource {
            protected string $description = 'A test UI resource';

            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertSame('A test UI resource', $array['description']);
        $this->assertSame('text/html;profile=mcp-app', $array['mimeType']);
        $this->assertArrayHasKey('uri', $array);
    }

    public function testUsesTheComputedClaudeDomainWhenNotSetOnAppResource(): void
    {
        $currentUrl = 'https://myapp.example.com/mcp/test';
        RequestContext::set(HttpRequest::create($currentUrl, 'GET'));

        $expectedDomain = str(hash('sha256', $currentUrl))
            ->limit(32, '')
            ->append(AppResource::CLAUDE_DOMAIN_SUFFIX)
            ->value();

        $resource = new class extends AppResource {
            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertSame($expectedDomain, $array['_meta']['ui']['domain']);
    }

    public function testAttributeDomainOverridesAutoResolvedDomain(): void
    {
        config(['app.url' => 'https://myapp.example.com']);

        $resource = new #[AppMetaAttribute(domain: 'custom.example.com')] class extends AppResource {
            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertSame('custom.example.com', $array['_meta']['ui']['domain']);
    }

    public function testConfiguresUiMetaViaAttribute(): void
    {
        $resource = new #[AppMetaAttribute(connectDomains: ['https://api.example.com'], permissions: [Permission::Camera, Permission::ClipboardWrite], prefersBorder: true, domain: 'sandbox.example.com', )] class extends AppResource {
            /**
             * Handle the resource request.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html></html>');
            }
        };

        $array = $resource->toArray();

        $this->assertEquals([
            'csp' => ['connectDomains' => ['https://api.example.com']],
            'permissions' => ['camera' => (object) [], 'clipboardWrite' => (object) []],
            'prefersBorder' => true,
            'domain' => 'sandbox.example.com',
        ], $array['_meta']['ui']);
    }
}
