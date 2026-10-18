<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use CohereHelpers;

    public function testCohereTextResponsesExposeTheRawHttpResponse(): void
    {
        config(['ai.providers.cohere' => [
            ...config('ai.providers.cohere'),
            'key' => 'test-key',
        ]]);

        Http::fake(['*' => $this->fakeTextResponse('Hello there')]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'cohere');

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('08067b1d-d35b-427c-a287-d913f35bd6ca', $response->raw->json('id'));
    }
}
