<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\StructuredStep;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Tests\TestCase;

class StructuredStepTest extends TestCase
{
    public function testStructuredStepStoresStructuredData(): void
    {
        $step = new StructuredStep(
            'test response',
            ['key' => 'value'],
            [],
            [],
            FinishReason::Stop,
            new TextUsage,
            new Meta('openai', 'gpt-4o'),
            'Structuring the answer.',
            [],
        );

        $this->assertSame('test response', $step->text);
        $this->assertSame('Structuring the answer.', $step->reasoning);
        $this->assertSame(['key' => 'value'], $step->structured);
    }

    public function testStructuredStepToArrayIncludesStructuredData(): void
    {
        $step = new StructuredStep(
            'text content',
            ['name' => 'test', 'count' => 5],
            [],
            [],
            FinishReason::Stop,
            new TextUsage,
            new Meta('anthropic', 'claude-3'),
            '',
            [],
        );

        $array = $step->toArray();

        $this->assertSame('text content', $array['text']);
        $this->assertSame(['name' => 'test', 'count' => 5], $array['structured']);
    }

    public function testStructuredStepJsonSerializeIncludesStructuredData(): void
    {
        $step = new StructuredStep(
            'response text',
            ['result' => true],
            [],
            [],
            FinishReason::Stop,
            new TextUsage,
            new Meta('openai', 'gpt-4o'),
            '',
            [],
        );

        $json = json_decode(json_encode($step), true);

        $this->assertSame(['result' => true], $json['structured']);
        $this->assertSame('response text', $json['text']);
    }
}
