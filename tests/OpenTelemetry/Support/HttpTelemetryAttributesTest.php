<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use GuzzleHttp\Psr7\Uri;
use Hypervel\OpenTelemetry\Support\HttpTelemetryAttributes;
use Hypervel\Tests\TestCase;

class HttpTelemetryAttributesTest extends TestCase
{
    public function testCapturesOnlyAllowlistedHeadersAndAlwaysRedactsSensitiveValues(): void
    {
        $attributes = new HttpTelemetryAttributes(
            false,
            [],
            ['x-private'],
            ['x-request-id', 'authorization', 'x-private'],
            ['x-response-id', 'set-cookie'],
        );

        $this->assertSame([
            'http.request.header.x-request-id' => ['123'],
            'http.request.header.authorization' => ['REDACTED'],
            'http.request.header.x-private' => ['REDACTED'],
        ], $attributes->requestHeaderAttributes([
            'x-request-id' => ['123'],
            'authorization' => ['Bearer secret'],
            'x-private' => ['secret'],
            'x-ignored' => ['ignored'],
        ]));
        $this->assertSame([
            'http.response.header.x-response-id' => ['456'],
            'http.response.header.set-cookie' => ['REDACTED'],
        ], $attributes->responseHeaderAttributes([
            'x-response-id' => ['456'],
            'set-cookie' => ['session=secret'],
        ]));
    }

    public function testRedactsDefaultAndConfiguredQueryParametersWithoutChangingOtherEncoding(): void
    {
        $attributes = new HttpTelemetryAttributes(true, ['tenant_secret'], [], [], []);

        $this->assertSame(
            'q=hello+world&ToKeN=REDACTED;x=1&tenant_secret=REDACTED&encoded%5Fname=value',
            $attributes->query('q=hello+world&ToKeN=secret;x=1&tenant_secret=private&encoded%5Fname=value'),
        );
    }

    public function testOmitsQueryWhenCaptureIsDisabledOrNoQueryExists(): void
    {
        $disabled = new HttpTelemetryAttributes(false, [], [], [], []);
        $enabled = new HttpTelemetryAttributes(true, [], [], [], []);

        $this->assertNull($disabled->query('token=secret'));
        $this->assertNull($enabled->query(null));
        $this->assertNull($enabled->query(''));
    }

    public function testBuildsAFullUrlWithoutCredentialsOrDisabledQueryDetail(): void
    {
        $attributes = new HttpTelemetryAttributes(false, [], [], [], []);

        $this->assertSame(
            'https://REDACTED:REDACTED@example.test:8443/users/1#profile',
            $attributes->fullUrl(new Uri('https://alice:secret@example.test:8443/users/1?token=secret#profile')),
        );
        $this->assertSame(
            'https://REDACTED@example.test/users',
            $attributes->fullUrl(new Uri('https://alice@example.test/users')),
        );
    }

    public function testBuildsAFullUrlWithRedactedEnabledQueryDetail(): void
    {
        $attributes = new HttpTelemetryAttributes(true, [], [], [], []);

        $this->assertSame(
            'https://example.test/users?q=hello&Signature=REDACTED',
            $attributes->fullUrl(new Uri('https://example.test/users?q=hello&Signature=secret')),
        );
    }

    public function testBuildsStandardSpanNamesForKnownAndUnknownMethods(): void
    {
        $attributes = new HttpTelemetryAttributes(false, [], [], [], []);

        $this->assertSame('GET', $attributes->spanName('GET'));
        $this->assertSame('GET /users/{user}', $attributes->spanName('GET', '/users/{user}'));
        $this->assertSame('HTTP', $attributes->spanName('_OTHER'));
        $this->assertSame('HTTP /users/{user}', $attributes->spanName('_OTHER', '/users/{user}'));
    }

    public function testNormalizesServerProtocolVersions(): void
    {
        $attributes = new HttpTelemetryAttributes(false, [], [], [], []);

        $this->assertSame('1.1', $attributes->protocolVersion('HTTP/1.1'));
        $this->assertSame('2', $attributes->protocolVersion('HTTP/2'));
        $this->assertSame('1.1', $attributes->protocolVersion('1.1'));
        $this->assertNull($attributes->protocolVersion(null));
    }

    public function testAcceptsOnlyNonNegativeIntegerContentLengths(): void
    {
        $attributes = new HttpTelemetryAttributes(false, [], [], [], []);

        $this->assertSame(0, $attributes->contentLength('0'));
        $this->assertSame(42, $attributes->contentLength('42'));
        $this->assertNull($attributes->contentLength(null));
        $this->assertNull($attributes->contentLength(''));
        $this->assertNull($attributes->contentLength('-1'));
        $this->assertNull($attributes->contentLength('1.5'));
        $this->assertNull($attributes->contentLength('invalid'));
    }
}
