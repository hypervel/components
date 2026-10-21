<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Cohere;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Cohere\Concerns\ParsesEmbeddings;
use Hypervel\Tests\TestCase;

function cohereEmbeddingsParser(): object
{
    return new class {
        use ParsesEmbeddings;

        /**
         * Parse the response embeddings.
         */
        public function parse(mixed $embeddings): array
        {
            return $this->parseCohereEmbeddings($embeddings);
        }
    };
}

class ParsesEmbeddingsTest extends TestCase
{
    public function testParsesEmbeddingsReturnedAsBareListOfVectors(): void
    {
        $embeddings = cohereEmbeddingsParser()->parse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);

        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $embeddings);
    }

    public function testUnwrapsFloatTypeWhenEmbeddingsAreKeyedByType(): void
    {
        $embeddings = cohereEmbeddingsParser()->parse(['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]);

        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $embeddings);
    }

    public function testThrowsWhenResponseCarriesNoFloatEmbeddings(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Cohere returned [int8, binary] embeddings, but only float embeddings are supported.');
        cohereEmbeddingsParser()->parse(['int8' => [[1, 2, 3]], 'binary' => [[1, 0, 1]]]);
    }

    public function testReturnsEmptyListWhenEmbeddingsAreAbsent(): void
    {
        $this->assertSame([], cohereEmbeddingsParser()->parse([]));
    }

    public function testReturnsEmptyListWhenResponseBodyCouldNotBeDecoded(): void
    {
        $this->assertSame([], cohereEmbeddingsParser()->parse(null));
    }
}
