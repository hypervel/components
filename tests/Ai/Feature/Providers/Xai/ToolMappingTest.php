<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;

class ToolMappingTest extends TestCase
{
    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake([
            '*' => fakeXaiToolMappingResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['parameters']['type'] === 'object'
                && array_key_exists('min', $tool['parameters']['properties'])
                && array_key_exists('max', $tool['parameters']['properties'])
                && in_array('min', $tool['parameters']['required'])
                && in_array('max', $tool['parameters']['required'])
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithoutStrictAttributeSendsStrictFalse(): void
    {
        Http::fake([
            '*' => fakeXaiToolMappingResponse('ok'),
        ]);

        agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === false
                && $tool['parameters']['required'] === ['query']
                && array_key_exists('limit', $tool['parameters']['properties']);
        });
    }

    public function testToolWithEmptySchemaIncludesParameters(): void
    {
        Http::fake([
            '*' => fakeXaiToolMappingResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return array_key_exists('parameters', $tool)
                && $tool['parameters']['type'] === 'object'
                && $tool['parameters']['properties'] === []
                && $tool['parameters']['required'] === []
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('ok')]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        Http::fake([
            '*' => fakeXaiToolMappingResponse('done'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return ! array_key_exists('schema_definition', $tool['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $tool['parameters']['required'] ?? []);
        });
    }

    public function testWebSearchToolSendsTypeWebSearch(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return $tool !== null;
        });
    }

    public function testWebSearchToolSendsAllowedDomains(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [(new WebSearch)->allow(['example.com', 'docs.example.com'])])
            ->prompt('Search', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'allowed_domains') === ['example.com', 'docs.example.com'];
        });
    }

    public function testWebSearchToolForwardsXaiProviderOptionsIntoTheToolPayload(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [
            (new WebSearch)->withProviderOptions([
                'excluded_domains' => ['spam.example.com'],
                'enable_image_understanding' => true,
                'enable_image_search' => true,
            ]),
        ])->prompt('Search', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'excluded_domains') === ['spam.example.com']
                && data_get($tool, 'enable_image_understanding') === true
                && data_get($tool, 'enable_image_search') === true;
        });
    }

    public function testFileSearchToolSendsFileSearchWithVectorStoreIds(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [new FileSearch(['collection-id'])])->prompt('Search my docs', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'file_search');

            return data_get($tool, 'vector_store_ids') === ['collection-id'];
        });
    }

    public function testFileSearchMetadataFiltersThrowAnException(): void
    {
        $search = new FileSearch(['collection-id'], where: ['company' => 'hypervel']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('xAI does not support file search metadata filters.');
        Ai::textProvider('xai')->fileSearchToolOptions($search);
    }

    public function testFileSearchToolForwardsXaiProviderOptionsIntoTheToolPayload(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [
            (new FileSearch(['collection-id']))->withProviderOptions([
                'max_num_results' => 5,
            ]),
        ])->prompt('Search my docs', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'file_search');

            return data_get($tool, 'max_num_results') === 5;
        });
    }

    public function testProviderToolsXaiDoesNotSupportAreDroppedAfterFailingOverToIt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limited']], 429),
            'api.x.ai/*' => fakeXaiToolMappingResponse('result'),
        ]);

        agent(tools: [new WebFetch, new WebSearch])
            ->prompt('Search', provider: ['anthropic', 'xai']);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'api.x.ai')
                && data_get(json_decode($request->body(), true), 'tools') === [['type' => 'web_search']];
        });
    }

    public function testCodeExecutionToolSendsTypeCodeInterpreter(): void
    {
        Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return collect(data_get($body, 'tools'))->firstWhere('type', 'code_interpreter') !== null;
        });
    }
}

/**
 * Create a completed text response.
 */
function fakeXaiToolMappingResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}
