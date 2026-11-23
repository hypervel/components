<?php

declare(strict_types=1);

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;

use function Hypervel\Coroutine\run;

$base = dirname(__DIR__);

require_once $base . '/vendor/autoload.php';

$url = $argv[1] ?? null;
$scenario = getenv('MCP_CONFORMANCE_SCENARIO') ?: null;
$context = json_decode(getenv('MCP_CONFORMANCE_CONTEXT') ?: '{}', true, flags: JSON_THROW_ON_ERROR);

if ($url === null || $scenario === null) {
    fwrite(STDERR, "Usage: MCP_CONFORMANCE_SCENARIO=<scenario> php client.php <server-url>\n");

    exit(1);
}

Bootstrapper::bootstrap();
Application::createFromConfig(Bootstrapper::getConfiguration());

$outputDirectory = getenv('CONFORMANCE_OUTPUT_DIR') ?: sys_get_temp_dir() . '/hypervel-mcp-conformance';
$logDirectory = $outputDirectory . '/logs';

if (! is_dir($logDirectory)) {
    mkdir($logDirectory, 0777, true);
}

$log = function (string $message) use ($scenario, $logDirectory): void {
    file_put_contents(
        $logDirectory . '/client.log',
        sprintf("[%s] %s\n", $scenario, $message),
        FILE_APPEND,
    );
};

$log(sprintf('start url=%s', $url));

$status = 0;

run(function () use ($url, $scenario, $context, $log, &$status): void {
    $client = Client::web($url)->withTimeout(30)->withOAuth(
        clientId: $context['client_id'] ?? null,
        clientSecret: $context['client_secret'] ?? null,
        redirectUri: 'http://127.0.0.1/callback',
    );

    $authorizations = 0;
    $grantedScope = null;
    $execute = function (Closure $operation) use ($client, $scenario, &$authorizations, &$grantedScope): mixed {
        while (true) {
            try {
                return $operation();
            } catch (AuthorizationRequiredException $exception) {
                if ($authorizations++ >= 2) {
                    throw $exception;
                }

                $scope = $exception->scope();

                if ($grantedScope !== null) {
                    $scope = implode(' ', array_unique(explode(' ', trim($grantedScope . ' ' . ($scope ?? '')))));
                }

                $oauth = $client->oAuthClient($exception->resourceMetadataUrl(), $scope);

                // Pre-registration also supplies credentials but requires the authorization-code flow.
                if ($scenario === 'auth/client-credentials-basic') {
                    $token = $oauth->clientCredentials();
                } else {
                    $response = Http::timeout(5)->connectTimeout(2)->withoutRedirecting()
                        ->get($oauth->redirect()->getTargetUrl())->throw();

                    if (! $response->redirect()) {
                        throw new RuntimeException('The mock authorization endpoint did not redirect to the callback.');
                    }

                    RequestContext::set(Request::create($response->header('Location')));
                    $token = $oauth->exchangeCallback();
                }

                $client->withToken($token->accessToken);
                $grantedScope = $token->scope;
            }
        }
    };

    try {
        $execute(fn () => $client->connect());

        $log(sprintf('connected using %s', $client->protocolVersion()->value));

        $tools = $execute(fn () => $client->tools());

        $log(sprintf('listed %d tools', $tools->count()));

        $calls = match (true) {
            isset($context['toolCalls']) => $context['toolCalls'],
            $scenario === 'tools_call' => [['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]]],
            $scenario === 'json-schema-2020-12-preservation' => [[
                'name' => 'json_schema_echo',
                'arguments' => ['schema' => $tools->get('json_schema_2020_12_tool')?->inputSchema],
            ]],
            $scenario === 'initialize' || $tools->isEmpty() => [],
            default => [['name' => $tools->first()->name, 'arguments' => []]],
        };

        foreach ($calls as $call) {
            $execute(fn () => $client->callTool($tools->get($call['name']) ?? $call['name'], $call['arguments'] ?? []));

            $log(sprintf('called tool %s', $call['name']));
        }

        $client->disconnect();

        $log('disconnected');
    } catch (Throwable $throwable) {
        $status = 1;
        $log(sprintf('error: %s', $throwable->getMessage()));

        fwrite(STDERR, sprintf("Error: %s\n%s\n", $throwable->getMessage(), $throwable->getTraceAsString()));

        try {
            $client->disconnect();
        } catch (Throwable $cleanupFailure) {
            $log(sprintf('disconnect error: %s', $cleanupFailure->getMessage()));
            fwrite(STDERR, sprintf("Disconnect error: %s\n", $cleanupFailure->getMessage()));
        }
    }
});

exit($status);
