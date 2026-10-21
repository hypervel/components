<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use OllamaHelpers;

    public function testOllamaTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'ollama',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('llama3.1:8b', $response->raw->json('model'));
    }
}
