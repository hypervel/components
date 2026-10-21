<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Inertia\DevTools\IncomingEntryBuilder;
use Hypervel\Inertia\DevTools\SourceLocator;
use Hypervel\Tests\Inertia\TestCase;

class IncomingEntryBuilderTest extends TestCase
{
    /**
     * Create a builder that exposes its protected helpers.
     */
    protected function makeBuilder(): ExposedIncomingEntryBuilder
    {
        return new ExposedIncomingEntryBuilder(new SourceLocator);
    }

    public function testCaptureBodyValueKeepsEncodablePayloads(): void
    {
        $result = $this->makeBuilder()->exposeCaptureBodyValue(['name' => 'John', 'items' => [1, 2, 3]]);

        $this->assertSame('present', $result['status']);
        $this->assertSame(['name' => 'John', 'items' => [1, 2, 3]], $result['value']);
    }

    public function testCaptureBodyValueOmitsUnserializablePayloads(): void
    {
        $result = $this->makeBuilder()->exposeCaptureBodyValue(['blob' => "\xB1\x31"]);

        $this->assertSame('omitted', $result['status']);
        $this->assertSame('unserializable', $result['reason']);
        $this->assertArrayNotHasKey('value', $result);
    }

    public function testSanitizeForJsonMarksUnserializableLeavesAndKeepsSiblings(): void
    {
        $sanitized = $this->makeBuilder()->exposeSanitizeForJson([
            'name' => 'John',
            'user' => ['email' => 'john@example.com', 'avatar' => "\xB1\x31"],
            'items' => [1, 2, 3],
        ]);

        $this->assertSame([
            'name' => 'John',
            'user' => ['email' => 'john@example.com', 'avatar' => '[UNSERIALIZABLE]'],
            'items' => [1, 2, 3],
        ], $sanitized);
    }
}

class ExposedIncomingEntryBuilder extends IncomingEntryBuilder
{
    /**
     * Capture the given body value when it can be encoded.
     *
     * @return array{status: string, value?: mixed, reason?: string}
     */
    public function exposeCaptureBodyValue(mixed $value): array
    {
        return $this->captureBodyValue($value);
    }

    /**
     * Replace the leaf values that cannot be JSON encoded with a marker.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function exposeSanitizeForJson(array $data): array
    {
        return $this->sanitizeForJson($data);
    }
}
