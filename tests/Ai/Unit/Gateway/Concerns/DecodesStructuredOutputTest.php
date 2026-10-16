<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns;

use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Tests\TestCase;

class DecodesStructuredOutputTest extends TestCase
{
    use DecodesStructuredOutput;

    public function testDecodesPlainJsonObject(): void
    {
        $this->assertSame(['name' => 'Ada', 'age' => 36], $this->decodeStructuredOutput('{"name":"Ada","age":36}'));
    }

    public function testStripsJsonFenceAndDecodes(): void
    {
        $this->assertSame(['ok' => true], $this->decodeStructuredOutput("```json\n{\"ok\":true}\n```"));
    }

    public function testStripsBareFenceAndDecodes(): void
    {
        $this->assertSame(['ok' => true], $this->decodeStructuredOutput("```\n{\"ok\":true}\n```"));
    }

    public function testToleratesLeadingAndTrailingWhitespaceAroundTheFence(): void
    {
        $this->assertSame(['value' => 42], $this->decodeStructuredOutput("  \n\n```json\n{\"value\":42}\n```  \n"));
    }

    public function testHandlesUppercaseJsonFenceLanguageTag(): void
    {
        $this->assertSame(['ok' => true], $this->decodeStructuredOutput("```JSON\n{\"ok\":true}\n```"));
    }

    public function testHandlesMixedCaseJsonFenceLanguageTag(): void
    {
        $this->assertSame(['ok' => true], $this->decodeStructuredOutput("```Json\n{\"ok\":true}\n```"));
        $this->assertSame(['ok' => true], $this->decodeStructuredOutput("```jSoN\n{\"ok\":true}\n```"));
    }

    public function testStripsAFenceWrappedInConversationalProse(): void
    {
        $this->assertSame(['symbol' => 'Au'], $this->decodeStructuredOutput("Here's the JSON you asked for:\n\n```json\n{\"symbol\":\"Au\"}\n```"));
        $this->assertSame(['symbol' => 'Au'], $this->decodeStructuredOutput("```json\n{\"symbol\":\"Au\"}\n```\n\nHope that helps!"));
    }

    public function testReturnsEmptyArrayForInvalidJson(): void
    {
        $this->assertSame([], $this->decodeStructuredOutput('not json at all'));
        $this->assertSame([], $this->decodeStructuredOutput("```json\n{invalid}\n```"));
    }

    public function testReturnsEmptyArrayForNullOrEmptyInput(): void
    {
        $this->assertSame([], $this->decodeStructuredOutput(null));
        $this->assertSame([], $this->decodeStructuredOutput(''));
        $this->assertSame([], $this->decodeStructuredOutput('   '));
    }

    public function testDoesNotMangleJsonContainingTripleBacktickSubstringsInsideStringValues(): void
    {
        $payload = '{"snippet":"```js\nconsole.log(1)\n```","ok":true}';

        $this->assertSame([
            'snippet' => "```js\nconsole.log(1)\n```",
            'ok' => true,
        ], $this->decodeStructuredOutput($payload));
    }

    public function testReturnsEmptyArrayWhenJsonDecodesToANonArrayScalar(): void
    {
        $this->assertSame([], $this->decodeStructuredOutput('"just a string"'));
        $this->assertSame([], $this->decodeStructuredOutput('42'));
    }
}
