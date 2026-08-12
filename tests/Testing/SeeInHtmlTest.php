<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\Constraints\SeeInHtml;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

class SeeInHtmlTest extends TestCase
{
    public function testCollapsesUnicodeWhitespaceFromHtmlEntities(): void
    {
        $constraint = new SeeInHtml('Hello World');

        $this->assertTrue($constraint->matches(['<p>Hello&nbsp;World</p>']));
        $this->assertTrue($constraint->matches(['<p>Hello&#8195;World</p>']));
        $this->assertTrue($constraint->matches(['<p>Hello&#12288;World</p>']));
        $this->assertTrue((new SeeInHtml('<p>Hello&nbsp;World</p>'))->matches(['Hello World']));
    }

    #[DataProvider('unicodeWhitespaceCharacters')]
    public function testCollapsesRawUnicodeWhitespace(string $whitespace): void
    {
        $constraint = new SeeInHtml('Hello World');

        $this->assertTrue($constraint->matches(["<p>Hello{$whitespace}World</p>"]));
    }

    /**
     * Provide Unicode whitespace characters.
     *
     * @return array<string, array{string}>
     */
    public static function unicodeWhitespaceCharacters(): array
    {
        return [
            'no-break space (U+00A0)' => ["\u{00A0}"],
            'en space (U+2002)' => ["\u{2002}"],
            'em space (U+2003)' => ["\u{2003}"],
            'thin space (U+2009)' => ["\u{2009}"],
            'ideographic space (U+3000)' => ["\u{3000}"],
        ];
    }

    public function testCollapsesMultipleAsciiWhitespace(): void
    {
        $constraint = new SeeInHtml('Hello World');

        $this->assertTrue($constraint->matches(['<p>Hello   World</p>']));
        $this->assertTrue($constraint->matches(["<p>Hello\tWorld</p>"]));
        $this->assertTrue($constraint->matches(["<p>Hello\nWorld</p>"]));
        $this->assertTrue($constraint->matches(["<p>Hello \t\n World</p>"]));
    }

    public function testFailsWhenValueIsAbsent(): void
    {
        $constraint = new SeeInHtml('Hello World');

        $this->assertFalse($constraint->matches(['<p>Goodbye World</p>']));
    }

    public function testNegateInvertsTheAssertion(): void
    {
        $constraint = new SeeInHtml('Hello World', ordered: false, negate: true);

        $this->assertTrue($constraint->matches(['<p>Goodbye World</p>']));
        $this->assertFalse($constraint->matches(['<p>Hello&nbsp;World</p>']));
    }

    public function testOrderedRespectsSequenceAcrossUnicodeWhitespace(): void
    {
        $constraint = new SeeInHtml('Hello&nbsp;beautiful&#8195;World', ordered: true);

        $this->assertTrue($constraint->matches(['Hello', 'beautiful', 'World']));
        $this->assertFalse($constraint->matches(['World', 'Hello']));
    }

    public function testAssertsStringZeroAndSkipsOnlyTheRawEmptyString(): void
    {
        $constraint = new SeeInHtml('<p>0</p>');

        $this->assertTrue($constraint->matches(['', '0']));
        $this->assertFalse((new SeeInHtml('<p>one</p>'))->matches(['0']));
    }

    public function testRejectsExpectedValuesWithoutVisibleText(): void
    {
        $constraint = new SeeInHtml('<p>Hello World</p>');

        $this->assertFalse($constraint->matches([" \t\n "]));
        $this->assertFalse($constraint->matches(['<strong></strong>']));
        $this->assertFalse((new SeeInHtml('<p>Hello World</p>', negate: true))->matches(['<br>']));
    }

    public function testRetainsByteWiseMatchingForMalformedUtf8(): void
    {
        $constraint = new SeeInHtml("<p>Hello \xFF World</p>");

        $this->assertTrue($constraint->matches(["Hello \xFF World"]));
        $this->assertFalse($constraint->matches(["Goodbye \xFF World"]));
    }

    public function testFailureMessagesContainOnePrefixAndOneTerminalPeriod(): void
    {
        $this->assertConstraintFailure(
            new SeeInHtml('Hello World'),
            ['Goodbye World'],
            'Failed asserting that \'Hello World\' contains "Goodbye World".',
        );
        $this->assertConstraintFailure(
            new SeeInHtml('Hello World', ordered: true),
            ['World', 'Hello'],
            'Failed asserting that \'Hello World\' contains "Hello" in specified order.',
        );
        $this->assertConstraintFailure(
            new SeeInHtml('Hello World', negate: true),
            ['Hello World'],
            'Failed asserting that \'Hello World\' does not contain "Hello World".',
        );
        $this->assertConstraintFailure(
            new SeeInHtml('Hello World'),
            ['<strong></strong>'],
            'Failed asserting that the expected value "<strong></strong>" contains visible text.',
        );
    }

    /**
     * Assert a constraint fails with the expected complete PHPUnit message.
     *
     * @param list<string> $values
     */
    protected function assertConstraintFailure(SeeInHtml $constraint, array $values, string $message): void
    {
        try {
            $constraint->evaluate($values);
            $this->fail('The constraint did not fail.');
        } catch (ExpectationFailedException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
