<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    /**
     * Configure the Azure OpenAI deployment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://my-resource.cognitiveservices.azure.com',
            'deployment' => 'gpt-4o',
        ]);
    }

    public function testToolWithParametersIncludesSchemaWithoutStrictMode(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return ! array_key_exists('strict', $tool)
                && $tool['parameters']['type'] === 'object'
                && array_key_exists('min', $tool['parameters']['properties'])
                && array_key_exists('max', $tool['parameters']['properties'])
                && in_array('min', $tool['parameters']['required'], true)
                && in_array('max', $tool['parameters']['required'], true)
                && ! array_key_exists('additionalProperties', $tool['parameters']);
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake(['*' => fakeAzureResponse('ok')]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolWithEmptySchemaOmitsParametersKey(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return ! array_key_exists('strict', $tool)
                && ! array_key_exists('parameters', $tool);
        });
    }

    public function testWebSearchToolSendsTypeWebSearch(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return $tool !== null;
        });
    }

    public function testWebSearchToolOmitsAzureSpecificOptionsByDefault(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('external_web_access', $tool);
        });
    }

    public function testWebSearchToolForwardsAzureProviderOptionsIntoTheToolPayload(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions([
                'external_web_access' => false,
                'search_context_size' => 'high',
            ]),
        ])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'external_web_access') === false
                && data_get($tool, 'search_context_size') === 'high';
        });
    }

    public function testWebSearchToolIgnoresProviderOptionsKeyedToAnotherProvider(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions(fn (Lab|string $provider): array => $provider === Lab::Anthropic
                ? ['external_web_access' => false]
                : []),
        ])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('external_web_access', $tool);
        });
    }

    public function testWebSearchToolSendsAllowedDomainsFilter(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [(new WebSearch)->allow(['example.com', 'docs.example.com'])])
            ->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'filters.allowed_domains') === ['example.com', 'docs.example.com'];
        });
    }

    public function testWebSearchToolSendsBlockedDomainsViaProviderOptions(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions([
                'filters' => ['blocked_domains' => ['spam.com', 'ads.example.com']],
            ]),
        ])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'filters.blocked_domains') === ['spam.com', 'ads.example.com'];
        });
    }

    public function testWebSearchToolMergesAllowWithBlockedDomainsProviderOption(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)
                ->allow(['good.com'])
                ->withProviderOptions(['filters' => ['blocked_domains' => ['bad.com']]]),
        ])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'filters.allowed_domains') === ['good.com']
                && data_get($tool, 'filters.blocked_domains') === ['bad.com'];
        });
    }

    public function testWebSearchToolOmitsFiltersWhenNoDomainsConfigured(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('filters', $tool);
        });
    }

    public function testWebSearchToolSendsUserLocationWhenLocationIsSet(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [(new WebSearch)->location(city: 'Warsaw', country: 'PL')])
            ->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'user_location.type') === 'approximate'
                && data_get($tool, 'user_location.city') === 'Warsaw'
                && data_get($tool, 'user_location.country') === 'PL';
        });
    }

    public function testWebSearchToolOmitsUserLocationWhenNoLocationSet(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('user_location', $tool);
        });
    }

    public function testCodeExecutionToolSendsTypeCodeInterpreterWithAutoContainer(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('result'),
        ]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'code_interpreter');

            return data_get($tool, 'container') === ['type' => 'auto'];
        });
    }

    public function testToolSearchEmitsAToolSearchEntryWithAzureOptionsAndDefersItsNestedTools(): void
    {
        Http::fake(['*' => fakeAzureResponse('ok')]);

        $search = (new ToolSearch(tools: [new DeferredTool]))
            ->withProviderOptions(fn (Lab|string $lab): array => $lab === Lab::Azure ? ['execution' => 'client'] : []);

        agent(tools: [$search])->prompt('Hi', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $tools = collect(data_get(json_decode($request->body(), true), 'tools'));

            return $tools->firstWhere('type', 'tool_search') === ['type' => 'tool_search', 'execution' => 'client']
                && ($tools->firstWhere('name', 'DeferredTool')['defer_loading'] ?? false) === true;
        });
    }
}
