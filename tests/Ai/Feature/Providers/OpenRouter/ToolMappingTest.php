<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return $function['parameters']['type'] === 'object'
                && array_key_exists('min', $function['parameters']['properties'])
                && array_key_exists('max', $function['parameters']['properties'])
                && in_array('min', $function['parameters']['required'], true)
                && in_array('max', $function['parameters']['required'], true)
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithEmptySchemaIncludesParameters(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('72019')]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return array_key_exists('parameters', $function)
                && $function['parameters']['type'] === 'object'
                && $function['parameters']['properties'] === []
                && $function['parameters']['required'] === []
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $function['parameters']['required'] ?? [], true);
        });
    }

    public function testWebFetchToolIsSentAsOpenRouterWebFetchType(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [new WebFetch])->prompt('Read https://hypervel.org', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

            return $tool !== null && ! array_key_exists('parameters', $tool);
        });
    }

    public function testWebFetchToolSendsMaxUsesWhenMaxSearchesIsSet(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [(new WebFetch)->max(5)])->prompt('Read https://hypervel.org', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

            return data_get($tool, 'parameters.max_uses') === 5;
        });
    }

    public function testWebFetchToolSendsAllowedDomains(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [(new WebFetch)->allow(['example.com', 'hypervel.org'])])->prompt('Read the docs', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

            return data_get($tool, 'parameters.allowed_domains') === ['example.com', 'hypervel.org'];
        });
    }

    public function testWebFetchToolForwardsProviderOptionsIntoParameters(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        $fetch = (new WebFetch)->withProviderOptions([
            'engine' => 'exa',
            'max_content_tokens' => 50000,
            'blocked_domains' => ['private.example.com'],
        ]);

        agent(tools: [$fetch])->prompt('Read the docs', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

            return data_get($tool, 'parameters.engine') === 'exa'
                && data_get($tool, 'parameters.max_content_tokens') === 50000
                && data_get($tool, 'parameters.blocked_domains') === ['private.example.com'];
        });
    }

    public function testWebFetchAndWebSearchCanBeUsedTogether(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [new WebSearch, new WebFetch])->prompt('Research this', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $types = collect(data_get(json_decode($request->body(), true), 'tools'))->pluck('type')->all();

            return in_array('openrouter:web_search', $types, true)
                && in_array('openrouter:web_fetch', $types, true);
        });
    }

    public function testWebSearchToolIsSentAsOpenRouterWebSearchType(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return $tool !== null && ! array_key_exists('parameters', $tool);
        });
    }

    public function testWebSearchToolSendsMaxUsesWhenMaxSearchesIsSet(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [(new WebSearch)->max(5)])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return data_get($tool, 'parameters.max_uses') === 5;
        });
    }

    public function testWebSearchToolForwardsMaxResultsThroughProviderOptions(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        $search = (new WebSearch)->max(3)->withProviderOptions(['max_results' => 10]);

        agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return data_get($tool, 'parameters.max_uses') === 3
                && data_get($tool, 'parameters.max_results') === 10;
        });
    }

    public function testWebSearchToolSendsAllowedDomains(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [(new WebSearch)->allow(['example.com', 'hypervel.org'])])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return data_get($tool, 'parameters.allowed_domains') === ['example.com', 'hypervel.org'];
        });
    }

    public function testWebSearchToolSendsUserLocation(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        agent(tools: [(new WebSearch)->location(city: 'San Francisco', country: 'US')])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return data_get($tool, 'parameters.user_location') === [
                'type' => 'approximate',
                'city' => 'San Francisco',
                'country' => 'US',
            ];
        });
    }

    public function testWebSearchToolForwardsProviderOptionsIntoParameters(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('done')]);

        $search = (new WebSearch)->withProviderOptions([
            'engine' => 'exa',
            'max_total_results' => 20,
            'search_context_size' => 'medium',
            'excluded_domains' => ['reddit.com'],
        ]);

        agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

            return data_get($tool, 'parameters.engine') === 'exa'
                && data_get($tool, 'parameters.max_total_results') === 20
                && data_get($tool, 'parameters.search_context_size') === 'medium'
                && data_get($tool, 'parameters.excluded_domains') === ['reddit.com'];
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('ok')]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }
}
