<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use GuzzleHttp\Psr7\NoSeekStream;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * A MockResponse that uses an unseekable body stream so we can test
 * that the response debugger buffers the body and shows it correctly.
 */
class UnseekableBodyMockResponse extends MockResponse
{
    /**
     * Create the PSR response with an unseekable body, like a network stream.
     */
    public function createPsrResponse(): ResponseInterface
    {
        $response = parent::createPsrResponse();

        // Guzzle's NoSeekStream replaces upstream's hand-written stream class.
        return $response->withBody(new NoSeekStream($response->getBody()));
    }
}
