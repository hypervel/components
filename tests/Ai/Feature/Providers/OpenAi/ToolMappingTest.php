<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testToolWithParametersIncludesStrictCompliantSchema(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === true
                && $tool['parameters']['type'] === 'object'
                && array_key_exists('min', $tool['parameters']['properties'])
                && array_key_exists('max', $tool['parameters']['properties'])
                && in_array('min', $tool['parameters']['required'], true)
                && in_array('max', $tool['parameters']['required'], true)
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('ok'),
        ]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolWithoutANameMethodFallsBackToClassBasenameForOpenai(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('ok'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Hi', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('name')->all();

            return in_array('FixedNumberGenerator', $names, true);
        });
    }

    public function testToolWithoutStrictAttributeSendsStrictFalseAndHonorsDeveloperDeclaredRequiredFields(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('ok'),
        ]);

        agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === false
                && $tool['parameters']['required'] === ['query']
                && array_key_exists('limit', $tool['parameters']['properties']);
        });
    }

    public function testToolWithEmptySchemaIncludesStrictCompliantParameters(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === true
                && array_key_exists('parameters', $tool)
                && $tool['parameters']['type'] === 'object'
                && $tool['parameters']['properties'] === []
                && $tool['parameters']['required'] === []
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function testWebSearchToolSendsTypeWebSearch(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return $tool !== null;
        });
    }

    public function testWebSearchToolOmitsOpenaiSpecificOptionsByDefault(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('external_web_access', $tool);
        });
    }

    public function testWebSearchToolForwardsOpenaiProviderOptionsIntoTheToolPayload(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions([
                'external_web_access' => false,
                'search_context_size' => 'high',
            ]),
        ])->prompt('Search', provider: 'openai');

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
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions(fn (Lab|string $provider): array => $provider === Lab::Anthropic
                ? ['external_web_access' => false]
                : []),
        ])->prompt('Search', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('external_web_access', $tool);
        });
    }

    public function testWebSearchToolSendsAllowedDomainsFilter(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [(new WebSearch)->allow(['example.com', 'docs.example.com'])])
            ->prompt('Search', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'filters.allowed_domains') === ['example.com', 'docs.example.com'];
        });
    }

    public function testWebSearchToolSendsBlockedDomainsViaProviderOptions(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions([
                'filters' => ['blocked_domains' => ['spam.com', 'ads.example.com']],
            ]),
        ])->prompt('Search', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return data_get($tool, 'filters.blocked_domains') === ['spam.com', 'ads.example.com'];
        });
    }

    public function testWebSearchToolMergesAllowWithBlockedDomainsProviderOption(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [
            (new WebSearch)
                ->allow(['good.com'])
                ->withProviderOptions(['filters' => ['blocked_domains' => ['bad.com']]]),
        ])->prompt('Search', provider: 'openai');

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
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('filters', $tool);
        });
    }

    public function testWebSearchToolSendsUserLocationWhenLocationIsSet(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [(new WebSearch)->location(city: 'Warsaw', country: 'PL')])
            ->prompt('Search', provider: 'openai');

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
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

            return ! array_key_exists('user_location', $tool);
        });
    }

    public function testCodeExecutionToolSendsTypeCodeInterpreterWithAutoContainer(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'code_interpreter');

            return data_get($tool, 'container') === ['type' => 'auto'];
        });
    }

    public function testCodeExecutionToolProviderOptionsMayOverrideTheContainer(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('result'),
        ]);

        agent(tools: [
            (new CodeExecution)->withProviderOptions([
                'container' => ['type' => 'auto', 'file_ids' => ['file_123']],
            ]),
        ])->prompt('Run some code', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'code_interpreter');

            return data_get($tool, 'container.file_ids') === ['file_123'];
        });
    }
}
