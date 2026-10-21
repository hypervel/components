<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Schema;

use BadMethodCallException;
use Hypervel\Head\Enums\OfferAvailability;
use Hypervel\Head\Exceptions\InvalidSchema;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\Facades\Schema;
use Hypervel\Head\Schema\Article;
use Hypervel\Head\Schema\GenericSchemaObject;
use Hypervel\Tests\Head\Fixtures\JobPosting;
use Hypervel\Tests\Head\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

class SchemaTest extends TestCase
{
    public function testRendersBuiltInSchemaObjectsAsJsonLd(): void
    {
        Head::schema(
            Schema::article()
                ->headline('Introducing Hypervel Head')
                ->description('A fluent API for the document head.')
                ->author(Schema::person()->name('Taylor Otwell'))
                ->publishedAt('2026-05-13')
                ->modifiedAt('2026-05-14')
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@context":"https://schema.org"', $html);
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('"description":"A fluent API for the document head."', $html);
        $this->assertStringContainsString('"@type":"Person"', $html);
        $this->assertStringContainsString('"datePublished":"2026-05-13"', $html);
        $this->assertStringContainsString('"dateModified":"2026-05-14"', $html);
    }

    public function testEscapesHtmlSensitiveCharactersInJsonLdToPreventScriptBreakout(): void
    {
        Head::schema(
            Schema::article()->headline('</script><script>alert(1)</script>')
        );

        $html = Head::toHtml();
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        $this->assertStringContainsString('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E', $html);
    }

    public function testResolvesFactoryMethodsForClassesWhoseSchemaTypeDiffersFromTheClassName(): void
    {
        Head::schema(
            Schema::breadcrumbs()
                ->item('Home', 'https://example.com')
                ->item('Shop', 'https://example.com/shop')
        )->schema(
            Schema::faq()->question('What is Hypervel Head?', 'A fluent API for managing the document head.')
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"itemListElement"', $html);
        $this->assertStringContainsString('"item":"https://example.com/shop"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"Question"', $html);
        $this->assertStringContainsString('"@type":"Answer"', $html);
    }

    public function testSetsBreadcrumbItemsInBulkWithSequentialPositions(): void
    {
        Head::schema(
            Schema::breadcrumbs()
                ->item('Home', 'https://example.com')
                ->items([
                    'Shop' => 'https://example.com/shop',
                    'Shoes' => 'https://example.com/shop/shoes',
                ])
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"position":1,"name":"Home"', $html);
        $this->assertStringContainsString('"position":2,"name":"Shop"', $html);
        $this->assertStringContainsString('"position":3,"name":"Shoes"', $html);
        $this->assertStringContainsString('"item":"https://example.com/shop/shoes"', $html);
    }

    public function testSetsFaqQuestionsInBulk(): void
    {
        Head::schema(
            Schema::faq()->questions([
                'What is Hypervel Head?' => 'A fluent API for managing the document head.',
                'Is it free?' => 'Yes, it is open source.',
            ])
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"name":"What is Hypervel Head?"', $html);
        $this->assertStringContainsString('"name":"Is it free?"', $html);
        $this->assertStringContainsString('"text":"Yes, it is open source."', $html);
    }

    public function testRegistersCustomSchemaTypesAsFirstClassFactoryMethods(): void
    {
        Schema::register(JobPosting::class);

        Head::schema(
            Schema::jobPosting()
                ->title('Senior Hypervel Developer')
                ->datePosted('2026-05-13')
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"JobPosting"', $html);
        $this->assertStringContainsString('"title":"Senior Hypervel Developer"', $html);
        $this->assertStringContainsString('"datePosted":"2026-05-13"', $html);
    }

    public function testSetsOfferAvailabilityFromSchemaOrgEnumValues(): void
    {
        Head::schema(
            Schema::product()
                ->name('Desk')
                ->brand(Schema::brand()->name('Acme'))
                ->offers(
                    Schema::offer()
                        ->price(125)
                        ->currency('USD')
                        ->availability(OfferAvailability::InStock)
                )
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"brand":{"@type":"Brand","name":"Acme"}', $html);
        $this->assertStringContainsString('"priceCurrency":"USD"', $html);
        $this->assertStringContainsString('"availability":"https://schema.org/InStock"', $html);
    }

    public function testIncludesAllSchemaOrgItemAvailabilityValues(): void
    {
        $this->assertSame('https://schema.org/MadeToOrder', OfferAvailability::MadeToOrder->url());
        $this->assertSame('https://schema.org/Reserved', OfferAvailability::Reserved->url());
    }

    public function testThrowsWhenAMagicSchemaPropertySetterReceivesMultipleArguments(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Magic schema property setters accept a single value, and [' . GenericSchemaObject::class . '] does not define a [name] method.');
        Schema::make('videoObject')->name('Introducing Hypervel Head', 'https://example.com/video');
    }

    public function testMakesSchemaObjectsDirectlyFromClassNamesWithoutRegistration(): void
    {
        Head::schema(
            Schema::make(JobPosting::class)
                ->title('Senior Hypervel Developer')
                ->datePosted('2026-05-13')
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"JobPosting"', $html);
        $this->assertStringContainsString('"title":"Senior Hypervel Developer"', $html);
    }

    public function testExplicitTypesBypassTheInferredTypeCache(): void
    {
        $this->assertSame('Article', (new Article)->toArray()['@type']);
        $this->assertSame('NewsArticle', (new Article('NewsArticle'))->toArray()['@type']);
        $this->assertSame('Article', (new Article)->toArray()['@type']);
    }

    #[DataProvider('invalidSchemas')]
    public function testRejectsInvalidSchemasOutsideProduction(array $schema, string $message): void
    {
        Head::schema($schema);

        $this->expectException(InvalidSchema::class);
        $this->expectExceptionMessage($message);
        Head::toHtml();
    }

    /**
     * Supply invalid schema definitions and their diagnostics.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSchemas(): iterable
    {
        yield 'context' => [['@context' => 'https://invalid.test', '@type' => 'WebPage'], 'JSON-LD schemas must use the https://schema.org context.'];
        yield 'type' => [['@context' => 'https://schema.org'], 'JSON-LD schemas must define an @type.'];
        yield 'nested null' => [['@context' => 'https://schema.org', '@type' => 'WebPage', 'author' => ['name' => null]], 'JSON-LD schemas may not contain null or empty string values.'];
        yield 'empty string' => [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => ''], 'JSON-LD schemas may not contain null or empty string values.'];
    }

    public function testLogsInvalidSchemasAndContinuesRenderingInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $schema = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => ''];
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with(
            'JSON-LD schemas may not contain null or empty string values.',
            ['schema' => $schema],
        );
        $this->app->instance(LoggerInterface::class, $logger);

        Head::schema($schema);

        $this->assertStringContainsString('<script type="application/ld+json">', Head::toHtml());
    }
}
