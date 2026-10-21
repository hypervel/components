<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;

trait ConvertsPsrResponses
{
    /**
     * Convert a PSR-7 response to a Symfony response.
     */
    public function convertResponse(ResponseInterface $psrResponse): Response
    {
        return (new HttpFoundationFactory)->createResponse($psrResponse);
    }
}
