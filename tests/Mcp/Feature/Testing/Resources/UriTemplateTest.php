<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources;

use Hypervel\Tests\Mcp\Fixtures\UriTemplateSummaryResource;
use Hypervel\Tests\Mcp\Fixtures\UriTemplateTestServer;
use Hypervel\Tests\Mcp\Fixtures\UriTemplateUserFileResource;
use Hypervel\Tests\Mcp\TestCase;
use InvalidArgumentException;
use Stringable;

class UriTemplateTest extends TestCase
{
    public function testResolvesUriTemplateVariablesFromArgumentsWhenTestingResources(): void
    {
        $response = UriTemplateTestServer::resource(UriTemplateSummaryResource::class, ['id' => 'abc']);

        $response->assertOk()
            ->assertSee('"id":"abc"')
            ->assertSee('"uri":"file://summary/abc"');
    }

    public function testResolvesMultipleUriTemplateVariablesWhenTestingResources(): void
    {
        $response = UriTemplateTestServer::resource(UriTemplateUserFileResource::class, [
            'userId' => '42',
            'fileId' => 'document.pdf',
        ]);

        $response->assertOk()
            ->assertSee('"userId":"42"')
            ->assertSee('"fileId":"document.pdf"')
            ->assertSee('"uri":"file://users/42/files/document.pdf"');
    }

    public function testDoesNotExpandPlaceholdersInsideVariableValues(): void
    {
        $response = UriTemplateTestServer::resource(UriTemplateUserFileResource::class, [
            'userId' => '{fileId}',
            'fileId' => 'report',
        ]);

        $response->assertOk()
            ->assertSee('"userId":"{fileId}"')
            ->assertSee('"fileId":"report"')
            ->assertSee('"uri":"file://users/{fileId}/files/report"');
    }

    public function testCastsNonStringScalarArgumentsWhenExpandingUriTemplates(): void
    {
        $response = UriTemplateTestServer::resource(UriTemplateSummaryResource::class, ['id' => 123]);

        $response->assertOk()
            ->assertSee('"uri":"file://summary/123"');
    }

    public function testAcceptsStringableArgumentsWhenExpandingUriTemplates(): void
    {
        $stringable = new class implements Stringable {
            /**
             * Return the template variable value.
             */
            public function __toString(): string
            {
                return 'abc';
            }
        };

        $response = UriTemplateTestServer::resource(UriTemplateSummaryResource::class, ['id' => $stringable]);

        $response->assertOk()
            ->assertSee('"uri":"file://summary/abc"');
    }

    public function testIgnoresExtraArgumentsThatDoNotAppearInTheUriTemplate(): void
    {
        $response = UriTemplateTestServer::resource(UriTemplateSummaryResource::class, [
            'id' => 'abc',
            'extra' => 'value',
        ]);

        $response->assertOk()
            ->assertSee('"uri":"file://summary/abc"');
    }

    public function testThrowsWhenARequiredUriTemplateVariableIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing value for URI template variable [fileId]');
        UriTemplateTestServer::resource(UriTemplateUserFileResource::class, ['userId' => '42']);
    }

    public function testThrowsWhenAUriTemplateVariableValueContainsASlash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("URI template variable [id] value must not contain '/'");
        UriTemplateTestServer::resource(UriTemplateSummaryResource::class, ['id' => 'a/b']);
    }

    public function testThrowsWhenAUriTemplateVariableValueIsNotScalarOrStringable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URI template variable [id] must be a scalar or Stringable value');
        UriTemplateTestServer::resource(UriTemplateSummaryResource::class, ['id' => ['array']]);
    }
}
