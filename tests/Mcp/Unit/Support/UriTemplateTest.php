<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Support;

use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class UriTemplateTest extends TestCase
{
    public function testRequiresAValidUriScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI template: must be a valid URI template with at least one placeholder.');
        new UriTemplate('/invalid/no-scheme/{id}');
    }

    public function testRequiresAtLeastOnePlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI template: must be a valid URI template with at least one placeholder.');
        new UriTemplate('file://path/without/placeholder');
    }

    public function testAcceptsValidUriTemplates(): void
    {
        $this->assertInstanceOf(UriTemplate::class, new UriTemplate('file://users/{id}'));
        $this->assertInstanceOf(UriTemplate::class, new UriTemplate('https://api.example.com/{endpoint}'));
        $this->assertInstanceOf(UriTemplate::class, new UriTemplate('https://example.com/path/{var}'));
    }

    public function testExtractsVariablesFromSimpleStrings(): void
    {
        $template = new UriTemplate('https://example.com/users/{username}');

        $this->assertSame(['username' => 'fred'], $template->match('https://example.com/users/fred'));
    }

    public function testExtractsMultipleVariables(): void
    {
        $template = new UriTemplate('file://users/{username}/posts/{postId}');

        $this->assertSame(['username' => 'fred', 'postId' => '123'], $template->match('file://users/fred/posts/123'));
    }

    public function testExposesVariableNames(): void
    {
        $template = new UriTemplate('file://users/{userId}/files/{fileId}/copies/{fileId}');

        $this->assertSame(['userId', 'fileId'], $template->variableNames());
    }

    public function testRepeatedVariablesMustHaveTheSameValue(): void
    {
        $template = new UriTemplate('file://files/{fileId}/copies/{fileId}');

        $this->assertSame(['fileId' => 'report'], $template->match('file://files/report/copies/report'));
        $this->assertNull($template->match('file://files/report/copies/invoice'));
    }

    public function testReturnsNullForNonMatchingUris(): void
    {
        $template = new UriTemplate('file://users/{username}');

        $this->assertNull($template->match('file://posts/123'));
    }

    public function testMatchesNestedPathSegments(): void
    {
        $template = new UriTemplate('https://api.example.com/{version}/{resource}/{id}');

        $this->assertSame([
            'version' => 'v1',
            'resource' => 'users',
            'id' => '123',
        ], $template->match('https://api.example.com/v1/users/123'));
    }

    public function testRejectsPartialMatchesAndIncompleteUris(): void
    {
        $template = new UriTemplate('file://users/{id}');

        $this->assertNull($template->match('file://users/123/extra'));
        $this->assertNull($template->match('file://users'));
    }

    public function testDoesNotMatchVariablesAcrossSlashes(): void
    {
        $template = new UriTemplate('file://files/{path}');

        $this->assertNull($template->match('file://files/foo/bar'));
        $this->assertSame(['path' => 'simple.txt'], $template->match('file://files/simple.txt'));
    }

    public function testHandlesEmptySegments(): void
    {
        $template = new UriTemplate('file://////{a}////{b}////');

        $this->assertSame(['a' => '1', 'b' => '2'], $template->match('file://////1////2////'));
    }

    public function testHandlesExtremelyLongInputStrings(): void
    {
        $longString = str_repeat('x', 100000);
        $template = new UriTemplate('https://api.example.com/{param}');

        $this->assertSame(['param' => $longString], $template->match('https://api.example.com/' . $longString));
    }

    public function testThrowsWhenTheTemplateExceedsTheMaximumLength(): void
    {
        $longTemplate = str_repeat('{x}', 1000001);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Template exceeds the maximum length');
        new UriTemplate($longTemplate);
    }

    public function testThrowsWhenUriExceedsMaximumLength(): void
    {
        $template = new UriTemplate('https://api.example.com/{param}');
        $longUri = 'https://api.example.com/' . str_repeat('x', 1000001);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URI exceeds the maximum length');
        $template->match($longUri);
    }

    public function testThrowsWhenTheTemplateHasTooManyExpressions(): void
    {
        $tooManyExpressions = 'https://example.com/' . str_repeat('{a}', 10001);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Template contains too many expressions');
        new UriTemplate($tooManyExpressions);
    }

    public function testThrowsForUnclosedTemplateExpressions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UriTemplate('https://example.com/{unclosed');
    }

    public function testHandlesPathologicalRegexPatterns(): void
    {
        $template = new UriTemplate('https://api.example.com/{param}');
        $input = 'https://api.example.com/' . str_repeat('a', 100000);

        $this->assertSame(['param' => str_repeat('a', 100000)], $template->match($input));
    }

    public function testHandlesInvalidUtf8Sequences(): void
    {
        $template = new UriTemplate('https://api.example.com/{param}');
        $invalidUtf8 = "\xFF\xFE\xFD";

        $this->assertSame(['param' => $invalidUtf8], $template->match('https://api.example.com/' . $invalidUtf8));
    }

    public function testHandlesMaximumTemplateExpressionLimit(): void
    {
        $expressions = 'https://example.com/' . str_repeat('{param}', 10000);

        $this->assertSame($expressions, (string) new UriTemplate($expressions));
    }

    public function testCastsToString(): void
    {
        $template = new UriTemplate('file://users/{id}');

        $this->assertSame('file://users/{id}', (string) $template);
    }
}
