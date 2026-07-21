<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use ValueError;

class StatusCodeTest extends TestCase
{
    public function testDefinesEveryCanonicalStatusCode(): void
    {
        $this->assertSame([
            'Ok' => 0,
            'Cancelled' => 1,
            'Unknown' => 2,
            'InvalidArgument' => 3,
            'DeadlineExceeded' => 4,
            'NotFound' => 5,
            'AlreadyExists' => 6,
            'PermissionDenied' => 7,
            'ResourceExhausted' => 8,
            'FailedPrecondition' => 9,
            'Aborted' => 10,
            'OutOfRange' => 11,
            'Unimplemented' => 12,
            'Internal' => 13,
            'Unavailable' => 14,
            'DataLoss' => 15,
            'Unauthenticated' => 16,
        ], array_column(StatusCode::cases(), 'value', 'name'));
    }

    public function testRejectsUndefinedStatusCodes(): void
    {
        $this->assertNull(StatusCode::tryFrom(17));

        $this->expectException(ValueError::class);

        StatusCode::from(17);
    }
}
