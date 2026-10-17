<?php

declare(strict_types=1);

switch ($argv[1]) {
    case 'frames':
        fwrite(STDOUT, "first\nsecond\npartial");
        fgets(STDIN);
        fwrite(STDOUT, "-complete\n");
        break;
    case 'final':
        fwrite(STDOUT, "final\n");
        break;
    case 'incomplete':
        fwrite(STDERR, 'discarded-prefix:' . str_repeat('x', 9000) . ':diagnostic-tail');
        fwrite(STDOUT, 'incomplete');
        break;
    case 'silent':
        sleep(5);
        break;
    case 'protocol':
        $discovery = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $discovery['id'],
            'result' => [
                'resultType' => 'complete',
                'supportedVersions' => [$argv[2]],
                'capabilities' => ['tools' => new stdClass],
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'Stdio fixture', 'version' => '1.0']],
            ],
        ], JSON_THROW_ON_ERROR) . "\n");

        for ($index = 0; $index < 2; ++$index) {
            $request = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);

            if ($index === 0) {
                // The client must answer this before starting the next tool call.
                fwrite(STDOUT, "{\"jsonrpc\":\"2.0\",\"id\":\"server-ping\",\"method\":\"ping\"}\n");
                $ping = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
                if (($ping['id'] ?? null) !== 'server-ping' || ! array_key_exists('result', $ping)) {
                    throw new RuntimeException('Another request interrupted the active stdio exchange.');
                }
            }

            fwrite(STDOUT, json_encode([
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'result' => [
                    'resultType' => 'complete',
                    'content' => [['type' => 'text', 'text' => $request['params']['name']]],
                ],
            ], JSON_THROW_ON_ERROR) . "\n");
        }
        break;
}
