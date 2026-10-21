<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Exceptions;

use Hypervel\Http\Client\Response;
use Hypervel\Saloon\Exceptions\Request\RequestException;

class ConnectorRequestException extends RequestException
{
    /**
     * Prepare the exception message.
     */
    protected function prepareMessage(Response $response): string
    {
        return 'Oh yee-naw.';
    }
}
