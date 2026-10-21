<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    public function testOpenaiTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_123',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello there',
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 1,
                    'output_tokens' => 1,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('resp_123', $response->raw->json('id'));
    }
}
