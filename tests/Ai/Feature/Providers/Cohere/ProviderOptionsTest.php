<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ProviderOptionsTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testProviderOptionsAreIncludedInCohereRequestBody(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'k') === 40
                && data_get($body, 'safety_mode') === 'CONTEXTUAL';
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('k', $body)
                && ! array_key_exists('safety_mode', $body);
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

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'cohere');

        $requests = Http::recorded();

        $this->assertCount(2, $requests);
        $this->assertSame(40, data_get(json_decode((string) $requests[1][0]->body(), true), 'k'));
    }
}
