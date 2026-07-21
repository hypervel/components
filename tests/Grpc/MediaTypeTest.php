<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Protocol\MediaType;
use Hypervel\Tests\TestCase;

class MediaTypeTest extends TestCase
{
    public function testRecognizesImplicitAndExplicitProtobufMediaTypes(): void
    {
        $implicit = MediaType::parse('application/grpc');
        $explicit = MediaType::parse('application/grpc+proto');

        $this->assertNotNull($implicit);
        $this->assertNotNull($explicit);
        $this->assertTrue($implicit->isProtobuf());
        $this->assertTrue($explicit->isProtobuf());
        $this->assertNull($implicit->subtype());
        $this->assertSame('proto', $explicit->subtype());
        $this->assertSame('application/grpc+proto', MediaType::PROTOBUF);
    }

    public function testRecognizesUnsupportedGrpcSubtypes(): void
    {
        $json = MediaType::parse('application/grpc+json');
        $custom = MediaType::parse('application/grpc+custom-codec');

        $this->assertNotNull($json);
        $this->assertNotNull($custom);
        $this->assertFalse($json->isProtobuf());
        $this->assertFalse($custom->isProtobuf());
        $this->assertSame('json', $json->subtype());
        $this->assertSame('custom-codec', $custom->subtype());
    }

    public function testParsingIsCaseInsensitiveAndAllowsParameters(): void
    {
        $mediaType = MediaType::parse(' Application/GRPC+PROTO ; charset=utf-8; version=1 ');

        $this->assertNotNull($mediaType);
        $this->assertTrue($mediaType->isProtobuf());
        $this->assertSame('proto', $mediaType->subtype());
    }

    public function testRejectsNonGrpcAndMalformedMediaTypes(): void
    {
        foreach ([
            '',
            'application/json',
            'application/grpcfoo',
            'application/grpc+',
            'application/grpc/json',
            'text/application/grpc',
        ] as $mediaType) {
            $this->assertNull(MediaType::parse($mediaType));
        }
    }
}
