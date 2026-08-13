<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Data;

use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Tests\TestCase;

class RecordedResponseTest extends TestCase
{
    public function testItRoundTripsTextAndContext(): void
    {
        $recorded = new RecordedResponse(
            201,
            ['X-Trace' => ['abc']],
            '{"name":"Taylor"}',
            ['provider' => 'example'],
        );

        $restored = RecordedResponse::fromFile($recorded->toFile());

        $this->assertSame(201, $restored->statusCode);
        $this->assertSame(['X-Trace' => ['abc']], $restored->headers);
        $this->assertSame('{"name":"Taylor"}', $restored->data);
        $this->assertSame(['provider' => 'example'], $restored->context);
        $this->assertSame('{"name":"Taylor"}', (string) $restored->toMockResponse()->createPsrResponse()->getBody());
    }

    public function testItRoundTripsBinaryResponseData(): void
    {
        $recorded = new RecordedResponse(200, data: "\xB1\x31");

        $contents = $recorded->toFile();
        $restored = RecordedResponse::fromFile($contents);

        $this->assertStringContainsString('"encoding": "base64"', $contents);
        $this->assertSame("\xB1\x31", $restored->data);
    }

    public function testItRejectsInvalidBase64Data(): void
    {
        $this->expectException(FixtureException::class);

        RecordedResponse::fromFile('{"statusCode":200,"headers":[],"data":"%","encoding":"base64"}');
    }
}
