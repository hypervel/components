<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\RequestOptionValidator;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestOptionValidatorTest extends TestCase
{
    #[DataProvider('reservedOptions')]
    public function testItRejectsOptionsOwnedByTheSaloonLifecycle(string $option): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessage("The [{$option}] option cannot be set in request options");

        RequestOptionValidator::validate([$option => null], 'request options');
    }

    /**
     * Provide options owned by the Saloon request lifecycle.
     *
     * @return iterable<string, array{string}>
     */
    public static function reservedOptions(): iterable
    {
        foreach ([
            'headers',
            'query',
            'cookies',
            'body',
            'json',
            'form_params',
            'multipart',
            'auth',
            'delay',
            'http_errors',
        ] as $option) {
            yield $option => [$option];
        }
    }

    public function testItAllowsTransportOptions(): void
    {
        RequestOptionValidator::validate([
            'allow_redirects' => false,
            'proxy' => 'http://proxy.example.com',
            'timeout' => 10,
            'verify' => true,
        ], 'request options');

        $this->addToAssertionCount(1);
    }
}
