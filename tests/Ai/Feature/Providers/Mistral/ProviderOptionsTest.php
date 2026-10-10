<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ProviderOptionsTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testProviderOptionsAreIncludedInMistralRequestBody(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
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

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'mistral');

        $requests = Http::recorded(fn (Request $r): true => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }
}
