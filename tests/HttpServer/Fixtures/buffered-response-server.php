<?php

declare(strict_types=1);

use Hypervel\HttpServer\ResponseBridge;
use Swoole\Constant;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (posix_setsid() === -1) {
    throw new RuntimeException('Unable to isolate the fixture process group.');
}

define('BASE_PATH', __DIR__);
$config = require dirname(__DIR__, 3) . '/src/foundation/config/server.php';
$settings = $config['settings'];
unset($settings[Constant::OPTION_PID_FILE], $settings['document_root']);
$settings['enable_static_handler'] = false;
$settings[Constant::OPTION_WORKER_NUM] = 1;
$settings[Constant::OPTION_REACTOR_NUM] = 1;
$settings[Constant::OPTION_LOG_FILE] = '/dev/stderr';

$server = new Server('127.0.0.1', 0, $config['mode']);
$server->set($settings);
$server->on('WorkerStart', static function (Server $server): void {
    fwrite(STDOUT, 'READY ' . $server->ports[0]->port . "\n");
});
$server->on('Request', static function (Request $request, Response $response): void {
    ResponseBridge::send(
        new HttpFoundationResponse(str_repeat('0123456789', 300000), headers: ['Content-Type' => 'text/plain']),
        $response,
    );
});
$server->start();
