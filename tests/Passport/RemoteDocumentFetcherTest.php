<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\RequestException;
use Hypervel\Passport\Http\HttpsDestinationPolicy;
use Hypervel\Passport\RemoteDocumentFetcher;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use UnexpectedValueException;

class RemoteDocumentFetcherTest extends TestCase
{
    public function testFetchesJsonAndClosesTheResponseBody(): void
    {
        $body = Utils::streamFor('{"keys":[]}');
        Http::fake(['https://keys.example/jwks' => Http::response($body)]);

        $this->assertSame(['keys' => []], $this->app->make(RemoteDocumentFetcher::class)->fetch('https://keys.example/jwks'));
        $this->assertFalse($body->isReadable());
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAreRejectedAndTheirBodiesAreClosed(string $json, int $status, string $exception): void
    {
        $body = Utils::streamFor($json);
        Http::fake(['https://keys.example/jwks' => Http::response($body, $status)]);

        try {
            $this->expectException($exception);
            $this->app->make(RemoteDocumentFetcher::class)->fetch('https://keys.example/jwks');
        } finally {
            $this->assertFalse($body->isReadable());
        }
    }

    /**
     * List invalid response formats and unsuccessful responses.
     */
    public static function invalidDocuments(): array
    {
        return [
            ['{invalid', 200, JsonException::class],
            ['null', 200, UnexpectedValueException::class],
            ['"not a document"', 200, UnexpectedValueException::class],
            ['{}', 503, RequestException::class],
        ];
    }

    public function testOversizedBodiesAreRejectedWithoutDependingOnContentLength(): void
    {
        Http::fake(['https://keys.example/jwks' => Http::response(str_repeat(' ', 65537), 200, ['Content-Length' => '2'])]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('maximum size');
        $this->app->make(RemoteDocumentFetcher::class)->fetch('https://keys.example/jwks');
    }

    #[TestWith(['http://keys.example/jwks'])]
    #[TestWith(['https://127.0.0.1/jwks'])]
    #[TestWith(['https://[::1]/jwks'])]
    #[TestWith(['https://user:password@keys.example/jwks'])]
    public function testDisallowedUrlsAreRejectedBeforeFetching(string $url): void
    {
        Http::fake();

        try {
            $this->expectException(DisallowedDestinationException::class);
            $this->app->make(RemoteDocumentFetcher::class)->fetch($url);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testTheRuntimePolicyRejectsPlaintextRedirectTargets(): void
    {
        $this->expectException(DisallowedDestinationException::class);
        $this->app->make(HttpsDestinationPolicy::class)->resolve('http://keys.example/jwks', 1);
    }
}
