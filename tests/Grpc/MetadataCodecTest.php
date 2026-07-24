<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class MetadataCodecTest extends TestCase
{
    public function testEncodesBinaryValuesWithoutPaddingAndCombinesRepeatedValues(): void
    {
        $headers = MetadataCodec::encode(Metadata::make([
            'trace-bin' => ["\x00\xff", 'b'],
            'x-tag' => ['one', 'two'],
        ]));

        $this->assertSame('AP8,Yg', $headers['trace-bin']);
        $this->assertSame('one,two', $headers['x-tag']);
    }

    public function testLeavesAsciiMetadataUnencoded(): void
    {
        $headers = MetadataCodec::encode(Metadata::make([
            'x-path' => '/one two/%2F',
        ]));

        $this->assertSame('/one two/%2F', $headers['x-path']);
    }

    public function testDecodesPaddedUnpaddedAndCommaSeparatedBinaryValues(): void
    {
        $metadata = MetadataCodec::decode([
            'trace-bin' => ['YQ==', 'Yg, Yw=='],
        ]);

        $this->assertSame(['a', 'b', 'c'], $metadata->values('trace-bin'));
    }

    public function testRejectsMalformedBinaryMetadata(): void
    {
        foreach (['a', '***', 'YQ==='] as $value) {
            try {
                MetadataCodec::decode(['trace-bin' => $value]);
                $this->fail('Expected malformed binary metadata to be rejected.');
            } catch (ProtocolException $exception) {
                $this->assertSame(
                    'A binary gRPC metadata value is not valid base64.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testTrimsSurroundingSpacesAndDiscardsInvalidAsciiFields(): void
    {
        $metadata = MetadataCodec::decode([
            'x-trimmed' => '  value  ',
            'x-encoded' => '%2F',
            'x-invalid' => "valid\tHTTP",
        ]);

        $this->assertSame('value', $metadata->first('x-trimmed'));
        $this->assertSame('%2F', $metadata->first('x-encoded'));
        $this->assertFalse($metadata->has('x-invalid'));
    }

    public function testFiltersPseudoProtocolAndTransportOwnedFields(): void
    {
        $metadata = MetadataCodec::decode([
            ':scheme' => 'https',
            ':status' => '200',
            'content-type' => 'application/grpc+proto',
            'grpc-status' => '0',
            'grpc-future-field' => 'reserved',
            'cache-control' => 'no-cache, private',
            'cookie' => 'lost-by-swoole',
            'authorization' => 'Bearer token',
            'x-tag' => 'value',
            'invalid:key' => 'discarded',
        ]);

        $this->assertSame([
            'authorization' => ['Bearer token'],
            'x-tag' => ['value'],
        ], $metadata->all());
    }

    public function testCountsTheExactVisibleHttp2HeaderListSize(): void
    {
        $headers = [
            ':method' => 'POST',
            ':scheme' => 'https',
            ':path' => '/example.Service/Call',
            ':authority' => 'example.test:443',
            'content-type' => 'application/grpc+proto',
            'content-length' => '0',
            'trace-bin' => 'AP8',
        ];
        $expected = 0;

        foreach ($headers as $key => $value) {
            $expected += strlen($key) + strlen($value) + 32;
        }

        $this->assertSame($expected, MetadataCodec::wireSize($headers));
    }

    public function testCountsRepeatedFieldsSeparatelyAndCollapsedFieldsAsObserved(): void
    {
        $this->assertSame(
            2 * (strlen('x-tag') + 32) + strlen('one') + strlen('two'),
            MetadataCodec::wireSize(['x-tag' => ['one', 'two']]),
        );
        $this->assertSame(
            strlen('x-tag') + strlen('one,two') + 32,
            MetadataCodec::wireSize(['x-tag' => 'one,two']),
        );
    }

    public function testCalculatesTheEightKibibyteBoundaryExactly(): void
    {
        $this->assertSame(8192, MetadataCodec::wireSize([
            'x' => str_repeat('a', 8192 - 33),
        ]));
    }

    public function testReflectsSwooleLossOfOverwrittenRepetitionsAndScheme(): void
    {
        $metadata = MetadataCodec::decode([
            ':scheme' => 'https',
            'x-tag' => 'last-visible-value',
        ]);

        $this->assertSame(['last-visible-value'], $metadata->values('x-tag'));
        $this->assertSame(1, $metadata->count());
    }

    public function testRejectsInvalidTransportHeaderShapes(): void
    {
        try {
            MetadataCodec::decode(['x-tag' => [1]]);
            $this->fail('Expected the invalid inbound header value to be rejected.');
        } catch (ProtocolException $exception) {
            $this->assertSame('A transport header value is not a string.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A transport header must contain a non-empty list of string values.',
        );

        MetadataCodec::wireSize(['x-tag' => []]);
    }
}
