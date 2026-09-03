<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Context;

use Hypervel\Grpc\Metadata;
use Hypervel\OpenTelemetry\Context\GrpcMetadataSetter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class GrpcMetadataSetterTest extends TestCase
{
    public function testReplacesOneFieldOnImmutableMetadata(): void
    {
        $metadata = Metadata::make([
            'traceparent' => ['old', 'duplicate'],
            'application' => 'preserved',
        ]);

        (new GrpcMetadataSetter)->set($metadata, 'traceparent', 'new');

        $this->assertSame(['new'], $metadata->values('traceparent'));
        $this->assertSame(['preserved'], $metadata->values('application'));
    }

    public function testRejectsUnsupportedCarrierTypes(): void
    {
        $carrier = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported gRPC metadata carrier type [array].');

        (new GrpcMetadataSetter)->set($carrier, 'traceparent', 'value');
    }
}
