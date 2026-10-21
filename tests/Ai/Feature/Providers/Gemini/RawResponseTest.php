<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use GeminiHelpers;

    public function testGeminiTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('Hello there', $response->raw->json('steps.0.content.0.text'));
    }
}
