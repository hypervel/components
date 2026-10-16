<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Generator;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Methods\ReadResource;
use Hypervel\Mcp\Server\Ui\AppMeta;
use Hypervel\Mcp\Server\Ui\Csp;
use Hypervel\Mcp\Server\Ui\Enums\Library;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReadAppResourceTest extends TestCase
{
    public function testIncludesUiMetaOnContentItemsForUiResources(): void
    {
        $resource = new class extends AppResource {
            /**
             * Describe the application's sandbox requirements.
             */
            public function appMeta(): AppMeta
            {
                return AppMeta::make()
                    ->csp(Csp::make()->connectDomains(['https://api.example.com']))
                    ->prefersBorder()
                    ->domain('sandbox.example.com');
            }

            /**
             * Return the application document.
             */
            public function handle(Request $request): Response
            {
                return Response::text('<html><body>Hello</body></html>');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [$resource],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $resource->uri()]
        );

        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray()['result'];

        $this->assertArrayHasKey('_meta', $payload['contents'][0]);
        $this->assertEquals([
            'csp' => ['connectDomains' => ['https://api.example.com']],
            'prefersBorder' => true,
            'domain' => 'sandbox.example.com',
        ], $payload['contents'][0]['_meta']['ui']);
    }

    public function testIncludesAutoResolvedClaudeDomainInUiMetaContentWhenNoAppMetaIsSet(): void
    {
        $currentUrl = 'https://myapp.example.com/mcp';
        $previousRequest = RequestContext::getOrNull();

        try {
            RequestContext::set(HttpRequest::create($currentUrl, 'GET'));

            $expectedDomain = str(hash('sha256', $currentUrl))
                ->limit(32, '')
                ->append(AppResource::CLAUDE_DOMAIN_SUFFIX)
                ->value();

            $resource = new class extends AppResource {
                /**
                 * Return the application document.
                 */
                public function handle(Request $request): Response
                {
                    return Response::text('<html><body>Hello</body></html>');
                }
            };

            $readResource = new ReadResource;
            $context = $this->getServerContext([
                'resources' => [$resource],
            ]);

            $jsonRpcRequest = new JsonRpcRequest(
                id: 1,
                method: 'resources/read',
                params: ['uri' => $resource->uri()]
            );

            $result = $readResource->handle($jsonRpcRequest, $context);
            $payload = $result->toArray()['result'];

            $this->assertArrayHasKey('_meta', $payload['contents'][0]);
            $this->assertEquals([
                'domain' => $expectedDomain,
                'prefersBorder' => true,
            ], $payload['contents'][0]['_meta']['ui']);
        } finally {
            $previousRequest === null ? RequestContext::forget() : RequestContext::set($previousRequest);
        }
    }

    public function testDoesNotIncludeUiMetaOnContentItemsForRegularResources(): void
    {
        $resource = $this->makeResource('regular content');

        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [$resource],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $resource->uri()]
        );

        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray()['result'];

        $this->assertArrayNotHasKey('_meta', $payload['contents'][0]);
    }

    #[DataProvider('responseModes')]
    public function testResourceMetadataAndCacheHintsUseTheSameRequestAsTheHandler(bool $lazy): void
    {
        $resource = new class($lazy) extends AppResource implements HasUriTemplate {
            /**
             * Create an eager or lazy document resource.
             */
            public function __construct(private bool $lazy)
            {
            }

            /**
             * Match a document format.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('ui://documents/{format}');
            }

            /**
             * Select the libraries required by the requested format.
             */
            public function appMeta(): AppMeta
            {
                $meta = AppMeta::make()->domain('example.test');

                return app(Request::class)->get('format') === 'html'
                    ? $meta->libraries(Library::Alpine)
                    : $meta;
            }

            /**
             * Return the requested document and its library scripts.
             */
            public function handle(Request $request): Response|Generator
            {
                if ($this->lazy) {
                    return (function () use ($request): Generator {
                        yield $this->document($request);
                    })();
                }

                return $this->document($request);
            }

            /**
             * Describe the requested format.
             */
            public function mimeType(): string
            {
                return 'text/' . app(Request::class)->get('format', 'plain');
            }

            /**
             * Cache the selected HTML document.
             */
            public function cacheable(): ?Cacheable
            {
                return app(Request::class)->uri() === 'ui://documents/html' ? new Cacheable(500) : null;
            }

            /**
             * Capture the document's rendering inputs.
             */
            private function document(Request $request): Response
            {
                return Response::json(['uri' => $request->uri(), 'scripts' => app('mcp.library_scripts')]);
            }
        };

        $transport = new ArrayTransport;
        $server = new class($transport, $resource) extends Server {
            /**
             * Register the document resource.
             */
            public function __construct(Transport $transport, AppResource $resource)
            {
                parent::__construct($transport);
                $this->resources = [$resource];
            }
        };

        $server->handle('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"ui://documents/html"}}');

        $payload = json_decode($transport->sent[0], true, flags: JSON_THROW_ON_ERROR)['result'];
        $content = $payload['contents'][0];
        $document = json_decode($content['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ui://documents/html', $document['uri']);
        $this->assertStringContainsString('https://cdn.jsdelivr.net/npm/alpinejs', $document['scripts']);
        $this->assertSame('text/html', $content['mimeType']);
        $this->assertSame(['https://cdn.jsdelivr.net'], $content['_meta']['ui']['csp']['resourceDomains']);
        $this->assertSame(500, $payload['ttlMs']);
    }

    /**
     * Supply eager and lazy response production.
     */
    public static function responseModes(): array
    {
        return [[false], [true]];
    }
}
