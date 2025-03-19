<?php

declare(strict_types=1);

use Hyperf\HttpMessage\Stream\SwooleStream;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;

Route::get('/foo', function () {
    return 'foo';
});

Route::get('/server-params', function (Request $request, Response $response) {
    return $response->json(
        $request->getServerParams()
    );
});

Route::get('/stream', function (Request $request, Response $response) {
    return $response->stream(function () {
        return 'stream';
    });
});

Route::get('/headers', function (Request $request, Response $response) {
    foreach ($request->getHeaders() as $key => $value) {
        $response = $response->withHeader($key, $value);
    }

    return $response->withBody(
        new SwooleStream('hello')
    );
});
