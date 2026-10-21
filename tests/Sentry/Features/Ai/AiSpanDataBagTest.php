<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features\Ai;

use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Sentry\Features\Ai\AiSpanDataBag;
use Hypervel\Tests\TestCase;

class AiSpanDataBagTest extends TestCase
{
    public function testTokenUsageIsRecorded(): void
    {
        $usage = new TextUsage(100, 50, 20, 5, 15);

        $data = new AiSpanDataBag;
        $data->setTokenUsage($usage);

        $this->assertSame(100, $data->get('gen_ai.usage.input_tokens'));
        $this->assertSame(50, $data->get('gen_ai.usage.output_tokens'));
        $this->assertSame(150, $data->get('gen_ai.usage.total_tokens'));
        $this->assertSame(20, $data->get('gen_ai.usage.input_tokens.cached'));
        $this->assertSame(5, $data->get('gen_ai.usage.input_tokens.cache_write'));
        $this->assertSame(15, $data->get('gen_ai.usage.output_tokens.reasoning'));
    }

    public function testTokenUsageWithoutCacheOrReasoningCountsIsRecorded(): void
    {
        // Embeddings, audio and reranking responses use the plain Usage class.
        $data = new AiSpanDataBag;
        $data->setTokenUsage(new Usage(100, 50));

        $this->assertSame(100, $data->get('gen_ai.usage.input_tokens'));
        $this->assertSame(50, $data->get('gen_ai.usage.output_tokens'));
        $this->assertSame(150, $data->get('gen_ai.usage.total_tokens'));
        $this->assertNull($data->get('gen_ai.usage.input_tokens.cached'));
        $this->assertNull($data->get('gen_ai.usage.input_tokens.cache_write'));
        $this->assertNull($data->get('gen_ai.usage.output_tokens.reasoning'));
    }
}
