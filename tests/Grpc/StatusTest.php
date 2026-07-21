<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Any;
use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class StatusTest extends TestCase
{
    public function testExposesSuccessfulStatusValues(): void
    {
        $status = new Status(StatusCode::Ok);

        $this->assertSame(StatusCode::Ok, $status->code());
        $this->assertSame('', $status->message());
        $this->assertNull($status->details());
        $this->assertTrue($status->isOk());
    }

    public function testRejectsRichDetailsForSuccessfulStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An OK gRPC status cannot contain rich error details.');

        new Status(StatusCode::Ok, '', new RichStatus);
    }

    public function testRejectsMismatchedRichStatusCode(): void
    {
        $details = (new RichStatus)
            ->setCode(StatusCode::NotFound->value)
            ->setMessage('Missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The rich status code must match the gRPC status code.');

        new Status(StatusCode::InvalidArgument, 'Missing', $details);
    }

    public function testRejectsMismatchedRichStatusMessage(): void
    {
        $details = (new RichStatus)
            ->setCode(StatusCode::InvalidArgument->value)
            ->setMessage('Different');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The rich status message must match the gRPC status message.');

        new Status(StatusCode::InvalidArgument, 'Invalid', $details);
    }

    public function testRichDetailsAreIsolatedFromInputAndAccessorMutations(): void
    {
        $richStatus = (new RichStatus)
            ->setCode(StatusCode::InvalidArgument->value)
            ->setMessage('Invalid')
            ->setDetails([
                (new Any)
                    ->setTypeUrl('type.hypervel.org/example.Detail')
                    ->setValue('original'),
            ]);

        $status = new Status(StatusCode::InvalidArgument, 'Invalid', $richStatus);

        $richStatus->setMessage('mutated input');
        $richStatus->getDetails()[0]->setValue('mutated input');

        $firstCopy = $status->details();

        $this->assertNotNull($firstCopy);
        $this->assertSame('Invalid', $firstCopy->getMessage());
        $this->assertSame('original', $firstCopy->getDetails()[0]->getValue());

        $firstCopy->setMessage('mutated accessor');
        $firstCopy->getDetails()[0]->setValue('mutated accessor');

        $secondCopy = $status->details();

        $this->assertNotNull($secondCopy);
        $this->assertSame('Invalid', $secondCopy->getMessage());
        $this->assertSame('original', $secondCopy->getDetails()[0]->getValue());
        $this->assertFalse($status->isOk());
    }
}
