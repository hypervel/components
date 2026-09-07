<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories\Body;

use Hypervel\Saloon\Exceptions\BodyException;
use Hypervel\Saloon\Repositories\Body\FormBodyRepository;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

class SerializationTest extends TestCase
{
    public function testJsonBodyRepositoryEncodesSupportedNestedValuesOnce(): void
    {
        $body = new JsonBodyRepository([
            'name' => new Stringable('Sam'),
            'metadata' => new Collection([
                'sidekick' => new class implements JsonSerializable {
                    public function jsonSerialize(): string
                    {
                        return 'Mantas';
                    }
                },
            ]),
        ]);

        $this->assertSame(
            '{"name":"Sam","metadata":{"sidekick":"Mantas"}}',
            (string) $body,
        );
        $this->assertSame((string) $body, (string) $body->toStream());
    }

    public function testJsonBodyRepositoryHonorsCustomEncodingFlags(): void
    {
        $body = new JsonBodyRepository(['url' => 'https://hypervel.org']);

        $body->setJsonFlags(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertSame(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, $body->getJsonFlags());
        $this->assertSame('{"url":"https://hypervel.org"}', (string) $body);
    }

    public function testJsonBodyRepositoryTranslatesThrownEncodingErrors(): void
    {
        $body = new JsonBodyRepository(['invalid' => "\xB1\x31"]);

        $this->expectException(BodyException::class);

        (string) $body;
    }

    public function testJsonBodyRepositoryTranslatesFalseEncodingResults(): void
    {
        $body = new JsonBodyRepository(['invalid' => "\xB1\x31"]);
        $body->setJsonFlags(0);

        $this->expectException(BodyException::class);

        (string) $body;
    }

    public function testFormBodyRepositoryEncodesSupportedNestedValues(): void
    {
        $body = new FormBodyRepository([
            'name' => new Stringable('Sam Smith'),
            'metadata' => new Collection([
                'active' => true,
                'limit' => INF,
            ]),
        ]);

        $this->assertSame(
            'name=Sam+Smith&metadata%5Bactive%5D=1&metadata%5Blimit%5D=INF',
            (string) $body,
        );
        $this->assertSame((string) $body, (string) $body->toStream());
    }

    public function testStructuredRepositoriesRejectUnresolvedObjects(): void
    {
        $body = new FormBodyRepository(['invalid' => new stdClass]);

        $this->expectException(InvalidArgumentException::class);

        (string) $body;
    }
}
