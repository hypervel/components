<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
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

    public function testSerializationPreservesClassificationAnswersAndDropsTheRawResponse(): void
    {
        $response = (new ClassificationResponse(['is_urgent' => new BooleanAnswer(0.9)], new TextUsage(1, 2), new Meta('openai', 'gpt-6-luna')))
            ->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame(0.9, $restored->answer('is_urgent')->probability);
        $this->assertSame(2, $restored->usage->outputTokens);
        $this->assertSame('gpt-6-luna', $restored->meta->model);
        $this->assertNull($restored->raw);
    }
}
