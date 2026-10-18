<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Tests\TestCase;
use JsonException;

class StructuredAgentResponseTest extends TestCase
{
    public function testJsonEncodingFailureDoesNotSilentlyDiscardTheStructuredResponse(): void
    {
        $text = '{"amount":1e999}';
        $response = new StructuredAgentResponse(
            'invocation',
            json_decode($text, true, flags: JSON_THROW_ON_ERROR),
            $text,
            new TextUsage,
            new Meta,
        );

        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_INF_OR_NAN);

        $response->toJson();
    }
}
