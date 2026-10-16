<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Files;

use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class RemoteFileTest extends TestCase
{
    public function testMimeTypeFallsBackToTheResponseContentType(): void
    {
        Http::fake([
            'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/webp; charset=binary']),
        ]);

        $this->assertSame('image/webp', (new RemoteImage('https://example.com/photo'))->mimeType());
    }

    public function testMimeTypeIsNullWhenTheResponseDeclaresNone(): void
    {
        Http::fake([
            'example.com/*' => Http::response('bytes', 200),
        ]);

        $this->assertNull((new RemoteImage('https://example.com/photo'))->mimeType());
    }

    public function testDeclaredMimeTypeWinsWithoutFetchingTheUrl(): void
    {
        Http::fake();

        $this->assertSame('image/png', (new RemoteImage('https://example.com/photo', 'image/png'))->mimeType());

        Http::assertNothingSent();
    }

    public function testAFailedFetchThrowsInsteadOfInliningTheErrorBody(): void
    {
        Http::fake([
            'example.com/*' => Http::response('Not Found', 404),
        ]);

        $this->expectException(RequestException::class);

        (new RemoteImage('https://example.com/missing.png'))->content();
    }

    public function testTheRemoteUrlIsOnlyFetchedOnce(): void
    {
        Http::fake([
            'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $image = new RemoteImage('https://example.com/photo.png');

        $image->mimeType();
        $image->content();

        Http::assertSentCount(1);
    }

    public function testAFetchedRemoteFileCanBeSerializedAndFetchedAgain(): void
    {
        Http::fake(['example.com/*' => Http::response('bytes')]);
        $image = (new RemoteImage('https://example.com/photo.png'))
            ->as('photo.png')
            ->withMimeType('image/png')
            ->withProviderOptions(['detail' => 'high']);

        $this->assertSame('bytes', $image->content());

        $restored = unserialize(serialize($image));

        $this->assertSame($image->toArray(), $restored->toArray());
        $this->assertSame(['detail' => 'high'], $restored->providerOptions('openai'));
        $this->assertSame('bytes', $restored->content());
        Http::assertSentCount(2);
    }
}
