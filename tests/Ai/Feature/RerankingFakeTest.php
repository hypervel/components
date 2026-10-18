<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Support\Collection;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class RerankingFakeTest extends TestCase
{
    public function testRerankRejectsEmptyDocumentList(): void
    {
        Reranking::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one document is required to rerank.');
        Reranking::of([])->rerank('What is Hypervel?');
    }

    public function testRerankRejectsEmptyCollectionOfDocuments(): void
    {
        Reranking::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one document is required to rerank.');
        Reranking::of(collect([]))->rerank('What is Hypervel?');
    }

    public function testRerankRejectsBlankDocumentStrings(): void
    {
        Reranking::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each document to rerank must be a non-blank string (index 0).');
        Reranking::of([''])->rerank('What is Hypervel?');
    }

    public function testRerankRejectsWhitespaceOnlyDocumentStrings(): void
    {
        Reranking::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each document to rerank must be a non-blank string (index 0).');
        Reranking::of([" \t\n"])->rerank('What is Hypervel?');
    }

    public function testRerankRejectsNonStringDocuments(): void
    {
        Reranking::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each document to rerank must be a non-blank string (index 0).');
        Reranking::of([123])->rerank('What is Hypervel?');
    }

    public function testCanFakeReranking(): void
    {
        Reranking::fake();

        $response = Reranking::of([
            'Hypervel is a PHP framework',
            'Python is a programming language',
            'React is a JavaScript library',
        ])->rerank('What is Hypervel?');

        $this->assertCount(3, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
    }

    public function testCanFakeRerankingWithLimit(): void
    {
        Reranking::fake();

        $response = Reranking::of([
            'Hypervel is a PHP framework',
            'Python is a programming language',
            'React is a JavaScript library',
            'Vue is a JavaScript framework',
            'Ruby is a programming language',
        ])->limit(3)->rerank('What is Hypervel?');

        $this->assertCount(3, $response);
    }

    public function testCanFakeRerankingWithCustomResponse(): void
    {
        Reranking::fake([
            [
                new RankedDocument(index: 0, document: 'First doc', score: 0.95),
                new RankedDocument(index: 1, document: 'Second doc', score: 0.75),
            ],
        ]);

        $response = Reranking::of(['First doc', 'Second doc'])->rerank('query');

        $this->assertCount(2, $response);
        $this->assertSame(0.95, $response->first()->score);
        $this->assertSame('First doc', $response->first()->document);
    }

    public function testCanFakeRerankingWithClosure(): void
    {
        Reranking::fake(fn (RerankingPrompt $prompt): array => (new Collection($prompt->documents))->map(fn (string $document, int $index): RankedDocument => new RankedDocument(
            index: $index,
            document: $document,
            score: 1.0 - ($index * 0.1),
        ))->all());

        $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])->rerank('test query');

        $this->assertCount(3, $response);
        $this->assertSame(1.0, $response->first()->score);
        $this->assertSame('Doc A', $response->first()->document);
    }

    public function testCanAssertReranked(): void
    {
        Reranking::fake();

        Reranking::of(['Hypervel is great', 'PHP is cool'])->rerank('Hypervel');

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->contains('Hypervel'));

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->documentsContain('Hypervel is great'));
    }

    public function testCanAssertNotReranked(): void
    {
        Reranking::fake();

        Reranking::of(['Hypervel is great'])->rerank('Hypervel');

        Reranking::assertNotReranked(fn (RerankingPrompt $prompt): bool => $prompt->contains('Python'));

        Reranking::assertNotReranked(fn (RerankingPrompt $prompt): bool => $prompt->documentsContain('Python is great'));
    }

    public function testCanAssertNothingReranked(): void
    {
        Reranking::fake();

        Reranking::assertNothingReranked();
    }

    public function testCanPreventStrayRerankings(): void
    {
        Reranking::fake()->preventStrayRerankings();

        $this->expectException(RuntimeException::class);
        Reranking::of(['Doc 1', 'Doc 2'])->rerank('query');
    }

    public function testFakeRerankingShufflesDocuments(): void
    {
        Reranking::fake();

        $documents = ['Doc A', 'Doc B', 'Doc C', 'Doc D', 'Doc E'];

        $response = Reranking::of($documents)->rerank('query');

        $this->assertCount(5, $response);

        foreach ($response as $result) {
            $this->assertContains($result->document, $documents);
            $this->assertSame($documents[$result->index], $result->document);
        }
    }

    public function testCanIterateOverResponse(): void
    {
        Reranking::fake();

        $response = Reranking::of(['Doc A', 'Doc B'])->rerank('query');

        $documents = [];

        foreach ($response as $result) {
            $documents[] = $result->document;
        }

        $this->assertCount(2, $documents);
    }

    public function testCanGetDocumentsInRerankedOrder(): void
    {
        Reranking::fake([
            [
                new RankedDocument(index: 1, document: 'Second', score: 0.9),
                new RankedDocument(index: 0, document: 'First', score: 0.5),
            ],
        ]);

        $response = Reranking::of(['First', 'Second'])->rerank('query');

        $this->assertSame(['Second', 'First'], $response->documents()->all());
    }

    public function testRerankAcceptsAiProviderEnum(): void
    {
        Reranking::fake();

        Reranking::of(['Hypervel is great', 'PHP is cool'])->rerank('Hypervel', provider: Lab::Cohere);

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->contains('Hypervel'));
    }

    public function testPromptRecordsLimit(): void
    {
        Reranking::fake();

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])->limit(2)->rerank('query');

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->limit === 2 && $prompt->count() === 3);
    }

    public function testPromptRecordsTimeout(): void
    {
        Reranking::fake();

        Reranking::of(['Doc A'])->timeout(45)->rerank('query');

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->timeout === 45);
    }

    public function testCollectionRerankMacroRecordsTimeout(): void
    {
        Reranking::fake();

        collect([['body' => 'Doc A']])->rerank(by: 'body', query: 'query', timeout: 45);

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->timeout === 45);
    }

    public function testEmptyResultsCanBeFaked(): void
    {
        Reranking::fake([[]]);

        $response = Reranking::of(['Document'])->rerank('query', provider: Lab::Jina);

        $this->assertCount(0, $response);
        $this->assertNull($response->first());
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Reranking failed.');
        Reranking::fake([fn (): never => throw $exception, [new RankedDocument(0, 'Document', 0.9)]]);

        try {
            Reranking::of(['Document'])->rerank('query', provider: Lab::Jina);
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('Document', Reranking::of(['Document'])->rerank('query', provider: Lab::Jina)->first()->document);
    }
}
