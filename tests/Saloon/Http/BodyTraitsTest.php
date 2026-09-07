<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasFormBody;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Saloon\Traits\Body\HasMultipartBody;
use Hypervel\Saloon\Traits\Body\HasStreamBody;
use Hypervel\Saloon\Traits\Body\HasStringBody;
use Hypervel\Saloon\Traits\Body\HasXmlBody;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\StreamInterface;

class BodyTraitsTest extends TestCase
{
    public function testStructuredBodyTraitsKeepTheirDefaultContentTypes(): void
    {
        $cases = [
            [new BodyTraitsJsonRequest, 'application/json', '{"name":"Taylor"}'],
            [new BodyTraitsFormRequest, 'application/x-www-form-urlencoded', 'name=Taylor'],
            [new BodyTraitsXmlRequest, 'application/xml', '<name>Taylor</name>'],
        ];

        foreach ($cases as [$request, $contentType, $body]) {
            $pendingRequest = $this->prepare($request);

            $this->assertSame($contentType, $pendingRequest->headers()['Content-Type']);
            $this->assertSame($body, (string) $pendingRequest->preparedBody());
        }
    }

    public function testExplicitContentTypeWinsTheBodyTraitDefault(): void
    {
        $pendingRequest = $this->prepare(
            (new BodyTraitsJsonRequest)->withHeader('Content-Type', 'application/vnd.api+json'),
        );

        $this->assertSame('application/vnd.api+json', $pendingRequest->headers()['Content-Type']);
    }

    public function testRawBodyTraitsKeepExactBytesWithoutInventingAContentType(): void
    {
        foreach ([new BodyTraitsStringRequest, new BodyTraitsStreamRequest] as $request) {
            $pendingRequest = $this->prepare($request);

            $this->assertArrayNotHasKey('Content-Type', $pendingRequest->headers());
            $this->assertSame('Taylor', (string) $pendingRequest->preparedBody());
        }
    }

    public function testMultipartDefaultsUseTheMergedRepositoryAndItsBoundary(): void
    {
        $pendingRequest = $this->prepare(
            new BodyTraitsMultipartRequest,
            new BodyTraitsMultipartConnector,
        );

        $contentType = $pendingRequest->headers()['Content-Type'];
        $body = (string) $pendingRequest->preparedBody();

        $this->assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $this->assertStringContainsString('--' . substr($contentType, strlen('multipart/form-data; boundary=')), $body);
        $this->assertStringContainsString('name="connector"', $body);
        $this->assertStringContainsString("\r\nconnector\r\n", $body);
        $this->assertStringContainsString('name="request"', $body);
        $this->assertStringContainsString("\r\nrequest\r\n", $body);
    }

    public function testCustomBodyRepositoriesExposeTheirResolvedValue(): void
    {
        $this->assertSame('custom', (new BodyTraitsCustomRequest)->body());
    }

    /**
     * Prepare a request with isolated framework dependencies.
     */
    protected function prepare(Request $request, ?Connector $connector = null): PendingRequest
    {
        return (new PendingRequest(
            $connector ?? new BodyTraitsConnector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        ))->bootPlugins()->prepareBody();
    }
}

class BodyTraitsConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class BodyTraitsMultipartConnector extends BodyTraitsConnector
{
    use HasMultipartBody;

    protected function defaultBody(): array
    {
        return [new MultipartValue('connector', 'connector')];
    }
}

class BodyTraitsJsonRequest extends Request
{
    use HasJsonBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): array
    {
        return ['name' => 'Taylor'];
    }
}

class BodyTraitsFormRequest extends Request
{
    use HasFormBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): array
    {
        return ['name' => 'Taylor'];
    }
}

class BodyTraitsXmlRequest extends Request
{
    use HasXmlBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): ?string
    {
        return '<name>Taylor</name>';
    }
}

class BodyTraitsStringRequest extends Request
{
    use HasStringBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): ?string
    {
        return 'Taylor';
    }
}

class BodyTraitsStreamRequest extends Request
{
    use HasStreamBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): StreamInterface
    {
        return Utils::streamFor('Taylor');
    }
}

class BodyTraitsMultipartRequest extends Request
{
    use HasMultipartBody;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBody(): array
    {
        return [new MultipartValue('request', 'request')];
    }
}

class BodyTraitsCustomRequest extends Request
{
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBodyRepository(): BodyRepository
    {
        return new CustomBodyRepository('custom');
    }
}

class CustomBodyRepository implements BodyRepository
{
    public function __construct(protected mixed $value)
    {
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function all(): mixed
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === null;
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function toStream(): StreamInterface
    {
        return Utils::streamFor((string) $this->value);
    }
}
