<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\XaiHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use XaiHelpers;

    public function testXaiTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'xai',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('resp_123', $response->raw->json('id'));
    }
}
