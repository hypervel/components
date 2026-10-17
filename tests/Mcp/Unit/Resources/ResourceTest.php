<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;

class ResourceTest extends TestCase
{
    public function testReturnsAValidResourceResultForTextResources(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'A test text resource.';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::text('This is a test resource.');
            }
        };

        $result = $resource->handle();

        $expected = [
            'text' => 'This is a test resource.',
            'uri' => $resource->uri(),
            'mimeType' => $resource->mimeType(),
        ];

        $this->assertEquals($expected, $result->content()->toResource($resource));
    }

    public function testReturnsAValidResourceResultForBinaryResources(): void
    {
        $binaryData = file_get_contents(__DIR__ . '/../../Fixtures/binary.png');

        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'A test blob resource.';
            }

            /**
             * Return the resource URI.
             */
            public function uri(): string
            {
                return 'file://resources/I_CAN_BE_OVERRIDDEN';
            }

            /**
             * Return the resource MIME type.
             */
            public function mimeType(): string
            {
                return 'image/png';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::blob(file_get_contents(__DIR__ . '/../../Fixtures/binary.png'));
            }
        };

        $result = $resource->handle();

        $expected = [
            'blob' => base64_encode($binaryData),
            'uri' => 'file://resources/I_CAN_BE_OVERRIDDEN',
            'mimeType' => 'image/png',
        ];

        $this->assertEquals($expected, $result->content()->toResource($resource));
    }

    public function testHandlesATextContentObjectReturnedFromRead(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'A test resource.';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::text('This is a test resource.');
            }
        };

        $result = $resource->handle();

        $expected
                = [
                    'text' => 'This is a test resource.',

                    'uri' => $resource->uri(),
                    'mimeType' => $resource->mimeType(),
                ];

        $this->assertEquals($expected, $result->content()->toResource($resource));
    }

    public function testHandlesABlobContentObjectReturnedFromRead(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'A test resource.';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::blob('This is a test resource.');
            }
        };

        $result = $resource->handle();

        $expected = [
            'blob' => base64_encode('This is a test resource.'),

            'uri' => $resource->uri(),
            'mimeType' => $resource->mimeType(),
        ];

        $this->assertEquals($expected, $result->content()->toResource($resource));
    }

    public function testDescriptionPropertyWorksAsExpected(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'A test resource.';
            }

            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'This is a test resource.';
            }
        };
        $this->assertSame('A test resource.', $resource->description());
    }

    public function testReturnsNoMetaByDefault(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'Test resource';
            }

            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'Content';
            }
        };

        $this->assertNull($resource->meta());
        $this->assertArrayNotHasKey('_meta', $resource->toArray());
    }

    public function testCanHaveCustomMeta(): void
    {
        $resource = new class extends Resource {
            protected ?array $meta = [
                'author' => 'John Doe',
                'version' => '1.0',
            ];

            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'Test resource';
            }

            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'Content';
            }
        };

        $this->assertSame([
            'author' => 'John Doe',
            'version' => '1.0',
        ], $resource->toArray()['_meta']);
    }

    public function testIncludesIconsInToArrayWhenDeclaredOnAResource(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'Test resource';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::text('content');
            }

            /**
             * Return the resource icons.
             */
            public function icons(): array
            {
                return [new Icon('https://example.com/resource.png', mimeType: 'image/png')];
            }
        };

        $this->assertSame([
            ['src' => 'https://example.com/resource.png', 'mimeType' => 'image/png'],
        ], $resource->toArray()['icons']);
    }

    public function testOmitsIconsInToArrayWhenNoneAreDeclared(): void
    {
        $resource = new class extends Resource {
            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return 'Test resource';
            }

            /**
             * Return the resource content.
             */
            public function handle(): Response
            {
                return Response::text('content');
            }
        };

        $this->assertArrayNotHasKey('icons', $resource->toArray());
    }
}
