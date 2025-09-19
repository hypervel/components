<?php

declare(strict_types=1);

namespace Hypervel\Sentry\HttpClient;

use Hypervel\Coroutine\Coroutine;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;

class HttpClient extends \Sentry\HttpClient\HttpClient
{
    public function sendRequest(Request $request, Options $options): Response
    {
        Coroutine::create(fn () => parent::sendRequest($request, $options));

        return new Response(202, ['X-Sentry-Request-Status' => ['Queued']], '');
    }
}
