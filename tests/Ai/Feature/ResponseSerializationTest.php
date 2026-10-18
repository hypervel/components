<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Http\Client\Response;
use Hypervel\Tests\Ai\Fixtures\Responses\SubclassedStep;
use Hypervel\Tests\Ai\Fixtures\Responses\SubclassedTextResponse;
use Hypervel\Tests\TestCase;

class ResponseSerializationTest extends TestCase
{
    public function testSerializationPreservesPrivatePropertiesDeclaredOnTextResponseSubclasses(): void
    {
        $response = new SubclassedTextResponse('Hello', new TextUsage(1, 2), new Meta('anthropic', 'claude'));
        $response->rememberSecret('changed');
        $response->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame('changed', $restored->secret());
        $this->assertSame('Hello', $restored->text);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesPrivatePropertiesDeclaredOnStepSubclasses(): void
    {
        $step = new SubclassedStep('Hello', [], [], FinishReason::Stop, new TextUsage(1, 2), new Meta('anthropic', 'claude'), '', []);
        $step->rememberSecret('changed');
        $step->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($step));

        $this->assertSame('changed', $restored->secret());
        $this->assertSame('Hello', $restored->text);
        $this->assertNull($restored->raw);
    }
}
