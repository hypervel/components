<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use MistralHelpers;

    public function testMistralTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'mistral',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('chatcmpl-123', $response->raw->json('id'));
    }
}
