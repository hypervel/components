<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RawResponseTest extends TestCase
{
    public function testGroqTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('chatcmpl-123', $response->raw->json('id'));
    }
}
