<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Http\Request;
use Hypervel\Support\Stringable;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasFormBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasMultipartBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasStringBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\StreamInterface;

class BodyTraitTest extends TestCase
{
    // REMOVED: upstream's three ChecksForHasBody cases. Every request has the body methods and connectors take their
    // body from defaultBodyRepository(), so the body traits need no HasBody contract.

    public function testWithBodyUsesAStringBodyWithAJsonContentTypeByDefault(): void
    {
        $request = (new UserRequest)->withBody(new Stringable('{"name":"Sam"}'));

        $this->assertSame('{"name":"Sam"}', $request->body());
        $this->assertSame('application/json', $request->headers()['Content-Type']);
    }

    public function testWithBodyUsesAStreamBodyForStreamsAndResources(): void
    {
        $stream = Utils::streamFor('Howdy');
        $resource = fopen('php://memory', 'rb');

        try {
            $streamRequest = (new UserRequest)->withBody($stream, 'text/plain');
            $resourceRequest = (new UserRequest)->withBody($resource, null);

            $this->assertSame($stream, $streamRequest->body());
            $this->assertSame('text/plain', $streamRequest->headers()['Content-Type']);
            $this->assertSame($resource, $resourceRequest->body());
            $this->assertFalse($resourceRequest->hasHeader('Content-Type'));
        } finally {
            fclose($resource);
        }
    }

    public function testAsJsonAndAsFormReplaceTheBodyAndContentType(): void
    {
        $request = (new UserRequest)->asForm(['name' => 'Sam']);

        $this->assertSame(['name' => 'Sam'], $request->body());
        $this->assertSame('application/x-www-form-urlencoded', $request->headers()['Content-Type']);

        $request->asJson(['drink' => 'Moonshine']);

        $this->assertSame(['drink' => 'Moonshine'], $request->body());
        $this->assertSame('application/json', $request->headers()['Content-Type']);
    }

    public function testWithDataMergesIntoAStructuredBody(): void
    {
        $request = (new HasFormBodyRequest)->withData(['name' => 'Gareth', 'drink' => 'Moonshine']);

        $this->assertSame([
            'name' => 'Gareth',
            'catchphrase' => 'Yeehaw!',
            'drink' => 'Moonshine',
        ], $request->body());
    }

    public function testWithDataStartsAJsonBodyWhenTheBodyIsNotStructured(): void
    {
        $request = (new HasStringBodyRequest)->withData(['name' => 'Sam']);

        $this->assertSame(['name' => 'Sam'], $request->body());
        $this->assertSame('application/json', $request->headers()['Content-Type']);
    }

    public function testAttachReplacesABodyThatIsNotMultipart(): void
    {
        $request = (new UserRequest)
            ->asJson(['name' => 'Sam'])
            ->attach('avatar', 'contents', 'avatar.png', ['Content-Type' => 'image/png']);

        $this->assertEquals([
            new MultipartValue('avatar', 'contents', 'avatar.png', ['Content-Type' => 'image/png']),
        ], $request->body());
    }

    public function testAttachAcceptsAnArrayOfParts(): void
    {
        $request = (new HasMultipartBodyRequest)->attach([
            ['avatar', 'contents', 'avatar.png', ['Content-Type' => 'image/png']],
            ['description', 'Profile picture'],
        ]);

        $this->assertEquals([
            new MultipartValue('nickname', 'Sam', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
            new MultipartValue('avatar', 'contents', 'avatar.png', ['Content-Type' => 'image/png']),
            new MultipartValue('description', 'Profile picture'),
        ], $request->body());
    }

    public function testHasBodyReportsWhetherTheRequestHasABody(): void
    {
        $this->assertFalse((new UserRequest)->hasBody());
        $this->assertNull((new UserRequest)->body());
        $this->assertTrue((new UserRequest)->asMultipart()->hasBody());
    }

    public function testCustomBodyRepositoriesExposeTheirResolvedValue(): void
    {
        $this->assertSame('custom', (new CustomBodyRequestStub)->body());
    }
}

class CustomBodyRequestStub extends Request
{
    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): BodyRepository
    {
        return new CustomBodyRepositoryStub('custom');
    }
}

class CustomBodyRepositoryStub implements BodyRepository
{
    /**
     * Create a custom body repository.
     */
    public function __construct(protected mixed $value)
    {
    }

    /**
     * Set the raw data in the repository.
     */
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get the raw data in the repository.
     */
    public function all(): mixed
    {
        return $this->value;
    }

    /**
     * Determine if the repository is empty.
     */
    public function isEmpty(): bool
    {
        return $this->value === null;
    }

    /**
     * Determine if the repository is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Convert the body repository into a stream.
     */
    public function toStream(): StreamInterface
    {
        return Utils::streamFor((string) $this->value);
    }
}
