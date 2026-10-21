<?php

declare(strict_types=1);

use Hypervel\Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new StdioTransport)->send('{"jsonrpc":"2.0","id":1,"result":{}}');
