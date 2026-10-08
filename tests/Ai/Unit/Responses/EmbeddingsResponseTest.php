<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Tests\TestCase;

class EmbeddingsResponseTest extends TestCase
{
    public function testEmbeddingsResponseSerializesItsUsageUnderAUsageKey(): void
    {
        $response = new EmbeddingsResponse([[0.1, 0.2]], new Usage(10), new Meta('openai', 'text-embedding-3-small'));

        $serialized = json_decode(json_encode($response), true);

        $this->assertArrayNotHasKey('tokens', $serialized);
        $this->assertSame(10, $serialized['usage']['input_tokens']);
    }
}
