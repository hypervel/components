<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\OllamaStructuredProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OllamaTopLevelOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ProviderOptionsTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testProviderOptionsAreIncludedInOllamaOptionsObject(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return array_key_exists('options', $body);
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('options', $body);
        });
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'ollama');

        $requests = Http::recorded(fn (Request $r): true => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertArrayHasKey('options', $followUpBody);
    }

    public function testTopLevelProviderOptionsArePlacedAtTheBodyRootNotInsideOptions(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new OllamaTopLevelOptionsAgent)->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['format'] ?? null) === 'json'
                && ($body['keep_alive'] ?? null) === '10m'
                && ($body['logprobs'] ?? null) === true
                && ! array_key_exists('format', $body['options'] ?? [])
                && ! array_key_exists('keep_alive', $body['options'] ?? [])
                && ! array_key_exists('logprobs', $body['options'] ?? []);
        });
    }

    public function testModelParametersFromProviderOptionsRemainInsideTheOptionsObject(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new OllamaTopLevelOptionsAgent)->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['options']['num_ctx'] ?? null) === 8192
                && ! array_key_exists('num_ctx', $body);
        });
    }

    public function testStructuredOutputSchemaIsNotOverwrittenByAProviderOptionsFormat(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        (new OllamaStructuredProviderOptionsAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return array_key_exists('format', $body)
                && is_array($body['format']);
        });
    }
}
