<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features\Ai;

use Hypervel\Sentry\Features\Ai\AiDataSanitizer;
use Hypervel\Tests\TestCase;

class AiDataSanitizerTest extends TestCase
{
    public function testTruncateStringKeepsValuesWithinTheByteLimit(): void
    {
        $this->assertNull(AiDataSanitizer::truncateString(null));
        $this->assertSame('short', AiDataSanitizer::truncateString('short'));
        $this->assertSame(
            str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES),
            AiDataSanitizer::truncateString(str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES))
        );
    }

    public function testTruncateStringCutsValuesOverTheByteLimit(): void
    {
        $this->assertSame(
            str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES) . '...(truncated)',
            AiDataSanitizer::truncateString(str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES + 1))
        );
        $this->assertSame('abc...(truncated)', AiDataSanitizer::truncateString('abcdef', 3));
        $this->assertSame('€...(truncated)', AiDataSanitizer::truncateString('€€', 4));
    }

    public function testTruncateContentStringCountsBytesWithoutSplittingCharacters(): void
    {
        $withinLimit = str_repeat('ü', AiDataSanitizer::MAX_SINGLE_MESSAGE_CONTENT_BYTES / 2);
        $this->assertSame($withinLimit, AiDataSanitizer::truncateContentString($withinLimit));

        $this->assertSame(
            $withinLimit . '...',
            AiDataSanitizer::truncateContentString($withinLimit . 'ü')
        );
        $this->assertSame(
            str_repeat('€', 3333) . '...',
            AiDataSanitizer::truncateContentString(str_repeat('€', 10000))
        );
    }

    public function testRedactBinaryInStringReplacesDataUrisAndBase64(): void
    {
        $this->assertSame(
            AiDataSanitizer::BLOB_SUBSTITUTE,
            AiDataSanitizer::redactBinaryInString('data:image/png;base64,iVBORw0KGgo=')
        );
        $this->assertSame(
            AiDataSanitizer::BLOB_SUBSTITUTE,
            AiDataSanitizer::redactBinaryInString(str_repeat('QUJD', 25))
        );
    }

    public function testRedactBinaryInStringKeepsRegularText(): void
    {
        $this->assertSame('What is the weather in Paris?', AiDataSanitizer::redactBinaryInString('What is the weather in Paris?'));
        // Base64 alphabet, but shorter than the 100 character threshold
        $this->assertSame('QUJD', AiDataSanitizer::redactBinaryInString('QUJD'));
    }

    public function testEncodeIfNotString(): void
    {
        $this->assertNull(AiDataSanitizer::encodeIfNotString(null));
        $this->assertSame('already a string', AiDataSanitizer::encodeIfNotString('already a string'));
        $this->assertSame('{"city":"Paris"}', AiDataSanitizer::encodeIfNotString(['city' => 'Paris']));
        $this->assertSame('42', AiDataSanitizer::encodeIfNotString(42));
        $this->assertSame('["�1"]', AiDataSanitizer::encodeIfNotString(["\xB1\x31"]));
    }

    public function testStructuredValuesRemainValidJsonWithinTheByteBudget(): void
    {
        $text = AiDataSanitizer::truncateContentString(str_repeat('€', 10000));
        $encoded = AiDataSanitizer::encode(['content' => $text, 'url' => 'https://example.com']);

        $this->assertSame(['content' => $text, 'url' => 'https://example.com'], json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(AiDataSanitizer::MAX_MESSAGE_BYTES, strlen($encoded));
        $this->assertNull(AiDataSanitizer::encode(['content' => str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES)]));
        $this->assertNull(AiDataSanitizer::encode(INF));
    }
}
