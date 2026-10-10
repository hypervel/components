<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake([
            '*' => fakeDeepSeekResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'deepseek');

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
        Http::fake([
            '*' => fakeDeepSeekResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'deepseek');

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
        Http::fake([
            '*' => fakeDeepSeekResponse('done'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $function['parameters']['required'] ?? [], true);
        });
    }
}
