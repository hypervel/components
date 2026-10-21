<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RawResponseTest extends TestCase
{
    public function testDeepSeekTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            'api.deepseek.com/*' => fakeDeepSeekResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('chatcmpl-deepseek-123', $response->raw->json('id'));
    }
}
