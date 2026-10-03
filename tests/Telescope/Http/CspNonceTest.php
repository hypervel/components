<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Support\Str;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Telescope;
use Hypervel\Tests\Telescope\FeatureTestCase;

use function Hypervel\Coroutine\parallel;

class CspNonceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([Authorize::class]);
    }

    public function testCspNonceIsNotRenderedInStyleAndScriptTagsIfNotSet(): void
    {
        $response = $this->get('/telescope');

        $response->assertOk()
            ->assertSeeHtml('<style>')
            ->assertSeeHtml('<script type="module">');
    }

    public function testCspNonceIsRenderedInStyleAndScriptTagsIfSet(): void
    {
        $nonce = Str::random(40);

        $this->assertInstanceOf(Telescope::class, Telescope::cspNonce($nonce));

        $response = $this->get('/telescope');

        $response->assertOk()
            ->assertSeeHtml("<style nonce=\"{$nonce}\">")
            ->assertSeeHtml("<script type=\"module\" nonce=\"{$nonce}\">");

        $this->assertSame(2, substr_count($response->getContent(), "<style nonce=\"{$nonce}\">"));
    }

    public function testCspNonceValueIsEscapedWhenRendered(): void
    {
        Telescope::cspNonce('"><script>alert(1)</script>');

        $response = $this->get('/telescope');
        $attribute = ' nonce="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"';

        $response->assertOk()
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml("<script type=\"module\"{$attribute}>");

        $this->assertSame(2, substr_count($response->getContent(), "<style{$attribute}>"));
    }

    public function testCspNonceIsIsolatedBetweenConcurrentCoroutines(): void
    {
        [$first, $second] = parallel([
            function (): array {
                Telescope::cspNonce('first-nonce');
                usleep(5000);

                return [(string) Telescope::css(), (string) Telescope::js()];
            },
            function (): array {
                Telescope::cspNonce('second-nonce');
                usleep(5000);

                return [(string) Telescope::css(), (string) Telescope::js()];
            },
        ]);

        $this->assertStringContainsString(' nonce="first-nonce"', $first[0]);
        $this->assertStringContainsString(' nonce="first-nonce"', $first[1]);
        $this->assertStringNotContainsString('second-nonce', $first[0] . $first[1]);

        $this->assertStringContainsString(' nonce="second-nonce"', $second[0]);
        $this->assertStringContainsString(' nonce="second-nonce"', $second[1]);
        $this->assertStringNotContainsString('first-nonce', $second[0] . $second[1]);
    }
}
