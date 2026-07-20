<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Any;
use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Protocol\StatusCodec;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;

class StatusCodecTest extends TestCase
{
    public function testEncodesOkAndErrorStatuses(): void
    {
        $this->assertSame([
            'grpc-status' => '0',
            'grpc-message' => '',
        ], StatusCodec::encode(new Status(StatusCode::Ok)));

        $this->assertSame([
            'grpc-status' => '5',
            'grpc-message' => 'Not found%25',
        ], StatusCodec::encode(new Status(StatusCode::NotFound, 'Not found%')));
    }

    public function testPercentEncodesOnlyBytesRequiredByTheProtocol(): void
    {
        $visibleAscii = '';

        for ($byte = 0x20; $byte <= 0x7E; ++$byte) {
            $visibleAscii .= chr($byte);
        }

        $this->assertSame(
            str_replace('%', '%25', $visibleAscii),
            StatusCodec::encodeMessage($visibleAscii),
        );
        $this->assertSame(
            '%00%25%E7%B3%BB%E7%BB%9F',
            StatusCodec::encodeMessage("\x00%系统"),
        );
    }

    public function testReplacesEachInvalidUtf8ByteBeforeEncoding(): void
    {
        $this->assertSame(
            '%EF%BF%BD%EF%BF%BD',
            StatusCodec::encodeMessage("\xff\xfe"),
        );
        $this->assertSame(
            '%EF%BF%BD(%EF%BF%BD',
            StatusCodec::encodeMessage("\xe2\x28\xa1"),
        );
    }

    public function testTolerantlyDecodesValidAndMalformedPercentSequences(): void
    {
        $this->assertSame('A / %2G %', StatusCodec::decodeMessage('A%20%2F%20%2G%20%'));
    }

    public function testEncodesAndParsesRichStatusDetailsWithPaddedOrUnpaddedBase64(): void
    {
        $richStatus = (new RichStatus)
            ->setCode(StatusCode::InvalidArgument->value)
            ->setMessage('Rich message')
            ->setDetails([
                (new Any)->setTypeUrl('type.hypervel.org/example.Detail')->setValue('detail'),
            ]);
        $encoded = StatusCodec::encode(new Status(
            StatusCode::InvalidArgument,
            'Rich message',
            $richStatus,
        ));

        $this->assertArrayHasKey('grpc-status-details-bin', $encoded);
        $this->assertStringNotContainsString('=', $encoded['grpc-status-details-bin']);
        $unpaddedDetails = $encoded['grpc-status-details-bin'];
        $paddedDetails = $unpaddedDetails . str_repeat(
            '=',
            (4 - strlen($unpaddedDetails) % 4) % 4,
        );

        foreach ([$unpaddedDetails, $paddedDetails] as $details) {
            $status = StatusCodec::parse([
                'grpc-status' => '3',
                'grpc-message' => 'Outer%20message',
                'grpc-status-details-bin' => $details,
            ], 200, true);

            $this->assertNotNull($status);
            $this->assertSame(StatusCode::InvalidArgument, $status->code());
            $this->assertSame('Rich message', $status->message());
            $this->assertSame('detail', $status->details()?->getDetails()[0]->getValue());
        }
    }

    public function testIgnoresAbsentMalformedAndRepeatedRichDetails(): void
    {
        foreach ([null, '***', ['value'], 'dmFsdWU,dmFsdWU'] as $details) {
            $headers = [
                'grpc-status' => '5',
                'grpc-message' => 'Outer%20message',
            ];

            if ($details !== null) {
                $headers['grpc-status-details-bin'] = $details;
            }

            $status = StatusCodec::parse($headers, 200, true);

            $this->assertNotNull($status);
            $this->assertSame(StatusCode::NotFound, $status->code());
            $this->assertSame('Outer message', $status->message());
            $this->assertNull($status->details());
        }
    }

    public function testMapsContradictoryRichDetailsToInternal(): void
    {
        $details = (new RichStatus)
            ->setCode(StatusCode::Aborted->value)
            ->setMessage('Contradiction');
        $status = StatusCodec::parse([
            'grpc-status' => (string) StatusCode::Unavailable->value,
            'grpc-message' => 'Outer',
            'grpc-status-details-bin' => rtrim(base64_encode($details->serializeToString()), '='),
        ], 200, true);

        $this->assertNotNull($status);
        $this->assertSame(StatusCode::Internal, $status->code());
        $this->assertSame(
            'The peer returned rich status details with a mismatched gRPC status code.',
            $status->message(),
        );
        $this->assertNull($status->details());
    }

    public function testMapsMalformedAndUndefinedStatusValuesToUnknown(): void
    {
        foreach (['', '-1', 'not-a-code', '17', '1,2', ['1']] as $value) {
            $status = StatusCodec::parse(['grpc-status' => $value], 200, true);

            $this->assertNotNull($status);
            $this->assertSame(StatusCode::Unknown, $status->code());
            $this->assertSame(
                'The peer returned a malformed or undefined grpc-status value.',
                $status->message(),
            );
        }
    }

    public function testAppliesEveryOfficialHttpFallbackOnlyWhenStatusIsAbsent(): void
    {
        $expected = [
            400 => StatusCode::Internal,
            401 => StatusCode::Unauthenticated,
            403 => StatusCode::PermissionDenied,
            404 => StatusCode::Unimplemented,
            429 => StatusCode::Unavailable,
            502 => StatusCode::Unavailable,
            503 => StatusCode::Unavailable,
            504 => StatusCode::Unavailable,
            200 => StatusCode::Unknown,
            500 => StatusCode::Unknown,
        ];

        foreach ($expected as $httpStatus => $code) {
            $status = StatusCodec::parse([], $httpStatus, true);

            $this->assertNotNull($status);
            $this->assertSame($code, $status->code());
            $this->assertSame(
                "The peer returned HTTP status {$httpStatus} without grpc-status.",
                $status->message(),
            );
        }

        $explicit = StatusCodec::parse(['grpc-status' => '0'], 503, true);

        $this->assertNotNull($explicit);
        $this->assertSame(StatusCode::Ok, $explicit->code());
    }

    public function testDetectsTrailersOnlyResponses(): void
    {
        $headers = ['grpc-status' => '0'];

        $this->assertTrue(StatusCodec::isTrailersOnly($headers, true, true));
        $this->assertFalse(StatusCodec::isTrailersOnly($headers, false, true));
        $this->assertFalse(StatusCodec::isTrailersOnly($headers, true, false));
        $this->assertFalse(StatusCodec::isTrailersOnly([], true, true));
    }

    public function testRejectsStatusOnANonFinalResponseEvent(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage(
            'The peer sent grpc-status before the response stream ended.',
        );

        StatusCodec::parse(['grpc-status' => '0'], 200, false);
    }

    public function testReturnsNullForANonFinalEventWithoutStatus(): void
    {
        $this->assertNull(StatusCodec::parse(['x-tag' => 'value'], 200, false));
    }
}
