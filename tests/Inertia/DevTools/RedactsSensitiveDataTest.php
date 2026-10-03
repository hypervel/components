<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Http\UploadedFile;
use Hypervel\Inertia\DevTools\RedactsSensitiveData;
use Hypervel\Tests\Inertia\TestCase;

class RedactsSensitiveDataTest extends TestCase
{
    protected ExposedRedactsSensitiveData $redactor;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new ExposedRedactsSensitiveData;
    }

    public function testNestedBodyKeysAreRedactedCaseInsensitively(): void
    {
        $redacted = $this->redactor->exposeRedact([
            'Password' => 'secret',
            'profile' => [
                'name' => 'John',
                'AUTH' => [
                    'Api_Key' => 'key-1',
                ],
            ],
        ], ['password', 'api_key']);

        $this->assertSame('[REDACTED]', $redacted['Password']);
        $this->assertSame('John', $redacted['profile']['name']);
        $this->assertSame('[REDACTED]', $redacted['profile']['AUTH']['Api_Key']);
    }

    public function testQueryStringSecretsAreRedactedCaseInsensitively(): void
    {
        $redacted = $this->redactor->exposeRedactUrls([
            '__meta' => [
                'url' => 'https://app.test/users?Token=abc&safe=1&filter[CLIENT_SECRET]=xyz#section',
                'redirectLocation' => '/next?api_key=key-1',
            ],
        ], ['token', 'client_secret', 'api_key']);

        $this->assertSame(
            'https://app.test/users?Token=%5BREDACTED%5D&safe=1&filter[CLIENT_SECRET]=%5BREDACTED%5D#section',
            $redacted['__meta']['url'],
        );
        $this->assertSame('/next?api_key=%5BREDACTED%5D', $redacted['__meta']['redirectLocation']);
    }

    public function testRelativeAndMalformedUrlsAreRedacted(): void
    {
        $redacted = $this->redactor->exposeRedactUrls([
            '__meta' => [
                'url' => '/search?password=secret&q=hi',
                'redirectLocation' => 'http://exa mple.test/?token=abc',
            ],
        ], ['password', 'token']);

        $this->assertSame('/search?password=%5BREDACTED%5D&q=hi', $redacted['__meta']['url']);
        $this->assertSame('http://exa mple.test/?token=%5BREDACTED%5D', $redacted['__meta']['redirectLocation']);
    }

    public function testUrlRedactionKeepsEveryOtherParameterAsRecorded(): void
    {
        $redacted = $this->redactor->exposeRedactUrls([
            'unchanged' => ['url' => '/search?q=a+b&filter.name=x&tags[]=1&tags[]=2&encoded=%ZZ&flag'],
            'redacted' => ['url' => '/search?q=a+b&token=abc&filter.name=x&token=def&tags[]=1#results?token=kept'],
            'fragment' => ['url' => '/page#section?token=kept'],
        ], ['token']);

        $this->assertSame('/search?q=a+b&filter.name=x&tags[]=1&tags[]=2&encoded=%ZZ&flag', $redacted['unchanged']['url']);
        $this->assertSame(
            '/search?q=a+b&token=%5BREDACTED%5D&filter.name=x&token=%5BREDACTED%5D&tags[]=1#results?token=kept',
            $redacted['redacted']['url'],
        );
        $this->assertSame('/page#section?token=kept', $redacted['fragment']['url']);
    }

    public function testHeaderBagsRedactCookiesAndAuthHeadersCaseInsensitively(): void
    {
        config()->set('inertia.devtools.redact.headers', ['authorization', 'cookie', 'set-cookie']);

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload([
            'http' => [
                'requestHeaders' => [
                    'Authorization' => ['Bearer secret'],
                    'Cookie' => ['session=abc'],
                    'Accept' => ['application/json'],
                ],
                'responseHeaders' => [
                    'Set-Cookie' => ['session=abc'],
                    'Content-Type' => ['application/json'],
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['http']['requestHeaders']['Authorization']);
        $this->assertSame('[REDACTED]', $redacted['http']['requestHeaders']['Cookie']);
        $this->assertSame('application/json', $redacted['http']['requestHeaders']['Accept']);
        $this->assertSame('[REDACTED]', $redacted['http']['responseHeaders']['Set-Cookie']);
        $this->assertSame('application/json', $redacted['http']['responseHeaders']['Content-Type']);
    }

    public function testUrlHeadersRedactSensitiveQueryParameters(): void
    {
        config()->set('inertia.devtools.redact.keys', ['token']);
        config()->set('inertia.devtools.redact.headers', ['x-inertia-location']);

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload([
            'http' => [
                'requestHeaders' => [
                    'referer' => ['https://app.test/reset?token=abc&step=2'],
                ],
                'responseHeaders' => [
                    'location' => ['/reset?token=abc'],
                    'x-inertia-location' => ['/elsewhere'],
                    'content-location' => ['/reset?token=abc'],
                ],
            ],
        ]);

        $this->assertSame('https://app.test/reset?token=%5BREDACTED%5D&step=2', $redacted['http']['requestHeaders']['referer']);
        $this->assertSame('/reset?token=%5BREDACTED%5D', $redacted['http']['responseHeaders']['location']);
        // A header configured as sensitive is redacted whole.
        $this->assertSame('[REDACTED]', $redacted['http']['responseHeaders']['x-inertia-location']);
        $this->assertSame('/reset?token=abc', $redacted['http']['responseHeaders']['content-location']);
    }

    public function testStoragePayloadLeavesPropMetadataAndValuesNamedLikeHeaderBagsIntact(): void
    {
        config()->set('inertia.devtools.redact.keys', ['token']);

        $props = [
            'token' => ['shared' => false, 'inertiaType' => null],
            'requestHeaders' => ['shared' => false, 'inertiaType' => null],
        ];

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload([
            'props' => $props,
            'propValues' => [
                'token' => 'secret',
                'requestHeaders' => ['enabled' => false, 'limit' => 5],
            ],
        ]);

        $this->assertSame($props, $redacted['props']);
        $this->assertSame('[REDACTED]', $redacted['propValues']['token']);
        $this->assertSame(['enabled' => false, 'limit' => 5], $redacted['propValues']['requestHeaders']);
    }

    public function testStoragePayloadRedactsEntryUrlsWholeUnderAConfiguredKey(): void
    {
        config()->set('inertia.devtools.redact.keys', ['url', 'token']);

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload([
            '__meta' => [
                'url' => '/reset-password/secret-token',
                'redirectLocation' => '/login?token=abc',
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['__meta']['url']);
        $this->assertSame('/login?token=%5BREDACTED%5D', $redacted['__meta']['redirectLocation']);
    }

    public function testStoragePayloadRedactsResponseBodiesCustomKeysUploadsInvalidUtf8AndLargeArrays(): void
    {
        config()->set('inertia.devtools.redact.keys', ['password', 'token', 'client_secret', 'internal_flag']);
        config()->set('inertia.devtools.redact.headers', ['authorization', 'cookie']);

        $items = array_map(fn (int $i): array => [
            'name' => 'John ' . $i,
            'Internal_Flag' => 'flag-' . $i,
        ], range(1, 1200));

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload([
            '__meta' => [
                'url' => 'https://app.test/users?token=abc&name=Jane',
            ],
            'http' => [
                'requestHeaders' => [
                    'Authorization' => ['Bearer secret'],
                    'Cookie' => ['session=abc'],
                ],
                'requestBody' => [
                    'status' => 'present',
                    'value' => [
                        'Password' => 'secret',
                        'avatar' => UploadedFile::fake()->create('avatar.pdf', 10),
                        'invalid' => "\xB1\x31",
                    ],
                ],
                'responseBody' => [
                    'status' => 'present',
                    'value' => [
                        'CLIENT_SECRET' => 'response-secret',
                        'items' => $items,
                    ],
                ],
            ],
        ]);

        $this->assertSame('https://app.test/users?token=%5BREDACTED%5D&name=Jane', $redacted['__meta']['url']);
        $this->assertSame('[REDACTED]', $redacted['http']['requestHeaders']['Authorization']);
        $this->assertSame('[REDACTED]', $redacted['http']['requestHeaders']['Cookie']);
        $this->assertSame('[REDACTED]', $redacted['http']['requestBody']['value']['Password']);
        $this->assertSame('[UNSERIALIZABLE]', $redacted['http']['requestBody']['value']['avatar']);
        $this->assertSame('[UNSERIALIZABLE]', $redacted['http']['requestBody']['value']['invalid']);
        $this->assertSame('[REDACTED]', $redacted['http']['responseBody']['value']['CLIENT_SECRET']);
        $this->assertCount(1200, $redacted['http']['responseBody']['value']['items']);
        $this->assertSame('John 1', $redacted['http']['responseBody']['value']['items'][0]['name']);
        $this->assertSame('[REDACTED]', $redacted['http']['responseBody']['value']['items'][1199]['Internal_Flag']);
    }

    public function testOmittedRedactListsUseTheDefaultsAndEmptyListsDisableRedaction(): void
    {
        config()->set('inertia.devtools', ['enabled' => true]);

        $payload = [
            'http' => [
                'requestHeaders' => ['Authorization' => ['Bearer secret']],
                'requestBody' => ['status' => 'present', 'value' => ['password' => 'secret']],
            ],
        ];

        $redacted = $this->redactor->exposeRedactSensitiveStoragePayload($payload);

        $this->assertSame('[REDACTED]', $redacted['http']['requestHeaders']['Authorization']);
        $this->assertSame('[REDACTED]', $redacted['http']['requestBody']['value']['password']);

        config()->set('inertia.devtools.redact', ['keys' => [], 'headers' => []]);

        $unredacted = $this->redactor->exposeRedactSensitiveStoragePayload($payload);

        $this->assertSame('Bearer secret', $unredacted['http']['requestHeaders']['Authorization']);
        $this->assertSame('secret', $unredacted['http']['requestBody']['value']['password']);
    }
}

class ExposedRedactsSensitiveData
{
    use RedactsSensitiveData;

    /**
     * Redact the values of the given keys throughout the data.
     *
     * @param array<array-key, mixed> $data
     * @param array<int, string> $keys
     * @return array<array-key, mixed>
     */
    public function exposeRedact(array $data, array $keys): array
    {
        return $this->redact($data, $keys);
    }

    /**
     * Redact sensitive query parameters from the URLs in the data.
     *
     * @param array<array-key, mixed> $data
     * @param array<int, string> $keys
     * @return array<array-key, mixed>
     */
    public function exposeRedactUrls(array $data, array $keys): array
    {
        return $this->redactUrls($data, $keys);
    }

    /**
     * Apply the final storage redaction pass to the payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function exposeRedactSensitiveStoragePayload(array $payload): array
    {
        return $this->redactSensitiveStoragePayload($payload);
    }
}
