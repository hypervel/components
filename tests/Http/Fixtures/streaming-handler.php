<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Hypervel\Http\Client\Factory;

require __DIR__ . '/../../../vendor/autoload.php';

$factory = new Factory;
$request = $factory->withOptions(['stream' => true]);

switch ($argv[1]) {
    case 'handler':
        $request->setHandler(new MockHandler([new Response(body: 'custom handler')]));
        break;
    case 'client':
        $request->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(body: 'custom client')]))]));
        break;
    case 'stack':
        $stack = $request->pushHandlers(HandlerStack::create(new MockHandler([new Response(body: 'custom stack')])));
        $request->setClient(new Client(['handler' => $stack]));
        break;
    case 'fake':
        $request = $factory->fake(['*' => Factory::response('fake stream')])->withOptions(['stream' => true]);
        break;
    case 'buffered':
        $request = $factory->fake(['*' => Factory::response('buffered')])->withOptions(['stream' => false]);
        break;
}

try {
    echo $request->get('http://example.test')->body();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
