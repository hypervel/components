<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\Factory;
use Hypervel\Passport\Http\HttpsDestinationPolicy;
use LengthException;
use UnexpectedValueException;

class RemoteDocumentFetcher
{
    public const int MAX_DOCUMENT_BYTES = 65536;

    /**
     * Create the remote JSON document fetcher.
     */
    public function __construct(
        protected Factory $http,
        protected HttpsDestinationPolicy $destinations,
    ) {
    }

    /**
     * Fetch a bounded JSON document from a public HTTPS endpoint.
     */
    public function fetch(string $url): array
    {
        $this->destinations->validate($url);
        $buffer = Utils::streamFor();
        $sink = FnStream::decorate($buffer, [
            'write' => static function (string $data) use ($buffer): int {
                if ($buffer->getSize() + strlen($data) > self::MAX_DOCUMENT_BYTES) {
                    throw new LengthException('The remote document exceeds the maximum size.');
                }

                return $buffer->write($data);
            },
        ]);
        $response = null;

        try {
            // A bounded sink limits decompressed bytes as they arrive, including outside coroutines.
            $response = $this->http->acceptJson()
                ->withDestinationPolicy($this->destinations)
                ->connectTimeout(2)
                ->timeout(5)
                ->withOptions([
                    'sink' => $sink,
                    'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
                ])->get($url);
            $response->throw();

            $document = json_decode((string) $sink, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($document)) {
                throw new UnexpectedValueException('The remote document must contain a JSON object or array.');
            }

            return $document;
        } finally {
            $response?->close();
            $sink->close();
        }
    }
}
