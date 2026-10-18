<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class ProviderOptionsTest extends TestCase
{
    use AnthropicHelpers;

    public function testProviderOptionsAreIncludedInAnthropicRequestBody(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new ProviderOptionsAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['thinking'])
                && $body['thinking']['type'] === 'enabled'
                && $body['thinking']['budget_tokens'] === 10000;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => ! isset($request->data()['thinking']));
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ProviderOptionsWithToolsAgent)->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertSame('The number is 72019', $response->text);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $firstBody = $recorded[0][0]->data();
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 10000], array_intersect_key($firstBody['thinking'], ['type' => true, 'budget_tokens' => true]));

        $secondBody = $recorded[1][0]->data();
        $this->assertArrayHasKey('thinking', $secondBody);
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 10000], array_intersect_key($secondBody['thinking'], ['type' => true, 'budget_tokens' => true]));
    }
}
