#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage: php request-fetcher.php --root=/path/to/components [--smoke] > results.txt
// Component timings: HypervelRequestFetcher::fetchRequest() and RequestIntegration::processEvent() on a
// transaction event, using the fetcher and autoloader of the checkout at --root. Not HTTP request latency.

$options = getopt('', ['root:', 'smoke', 'idle-confirmed']);
$smoke = isset($options['smoke']);
$repetitions = $smoke ? 1 : 3;

// Full-size runs are benchmarks, which need the owner's confirmation that the machine is idle.
if (! $smoke && ! isset($options['idle-confirmed'])) {
    fwrite(STDERR, "Full-size runs need --idle-confirmed; use --smoke otherwise.\n");
    exit(1);
}

require $options['root'] . '/vendor/autoload.php';

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestIntegration;
use Sentry\SentrySdk;

Container::getInstance()->instance('config', new Repository(['session' => ['cookie' => 'session']]));

/**
 * Build a representative request outside the measurement window.
 */
function makeRequest(int $bodyBytes, string $contentType): Request
{
    $content = '';
    if ($bodyBytes > 0) {
        $item = ['id' => 0, 'name' => str_repeat('x', 40), 'tags' => ['a', 'b', 'c']];
        $content = json_encode(['items' => array_fill(0, intdiv($bodyBytes, strlen(json_encode($item)) + 1) + 1, $item)]);
    }

    return Request::create('https://example.com/api/orders?page=2', $bodyBytes > 0 ? 'POST' : 'GET', [], ['session' => 'abc', 'theme' => 'dark', 'XSRF-TOKEN' => 'tok'], [], [
        'CONTENT_TYPE' => $contentType,
        'CONTENT_LENGTH' => (string) strlen($content),
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 bench',
        'HTTP_AUTHORIZATION' => 'Bearer secret',
        'HTTP_X_REQUEST_ID' => 'req-123',
        'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        'HTTP_ACCEPT_ENCODING' => 'gzip',
        'HTTP_REFERER' => 'https://example.com/',
        'HTTP_ORIGIN' => 'https://example.com',
    ], $content);
}

$cases = [
    'GET, no body' => [0, 'application/json'],
    'POST 2 KB JSON' => [2_000, 'application/json'],
];

printf(
    "root=%s revision=%s php=%s opcache=%s jit=%s\n",
    $options['root'],
    trim((string) shell_exec('git -C ' . escapeshellarg($options['root']) . ' rev-parse --short HEAD')),
    PHP_VERSION,
    var_export(opcache_get_status(false) !== false, true),
    ini_get('opcache.jit') ?: 'off',
);

foreach (['legacy' => null, 'data_collection' => []] as $mode => $dataCollection) {
    $fetcher = new HypervelRequestFetcher;
    $integration = new RequestIntegration($fetcher);
    $client = ClientBuilder::create(['data_collection' => $dataCollection, 'integrations' => [$integration], 'default_integrations' => false])->getClient();
    SentrySdk::getCurrentHub()->bindClient($client);
    $process = (new ReflectionMethod(RequestIntegration::class, 'processEvent'))->getClosure($integration);

    foreach ($cases as $label => [$bytes, $contentType]) {
        RequestContext::set(makeRequest($bytes, $contentType));
        $iterations = $smoke ? 20 : 20_000;
        $fetchRuns = $processRuns = [];

        // Both revisions must produce the request and attach its data to the event.
        $fetched = $fetcher->fetchRequest();
        $event = Event::createTransaction();
        $process($event, $client->getOptions());

        if ($fetched?->getMethod() !== ($bytes > 0 ? 'POST' : 'GET')
            || ($event->getRequest()['url'] ?? null) !== 'https://example.com/api/orders?page=2'
            || ($event->getRequest()['method'] ?? null) !== $fetched->getMethod()
        ) {
            fwrite(STDERR, "{$mode} {$label}: unexpected request data " . json_encode($event->getRequest()) . PHP_EOL);
            exit(1);
        }

        for ($run = 0; $run < (int) $repetitions; ++$run) {
            $start = hrtime(true);
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                $fetcher->fetchRequest();
            }
            $fetchRuns[] = (hrtime(true) - $start) / 1000 / $iterations;

            $start = hrtime(true);
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                $process(Event::createTransaction(), $client->getOptions());
            }
            $processRuns[] = (hrtime(true) - $start) / 1000 / $iterations;
        }

        sort($fetchRuns);
        sort($processRuns);

        // Smoke runs check correctness only.
        if ($smoke) {
            printf("%-16s %-22s ok\n", $mode, $label);

            continue;
        }

        printf(
            "%-16s %-22s iterations=%-6d fetch_us=[%s] median=%.1f | processor_us=[%s] median=%.1f\n",
            $mode,
            $label,
            $iterations,
            implode(', ', array_map(fn (float $value): string => sprintf('%.1f', $value), $fetchRuns)),
            $fetchRuns[intdiv(count($fetchRuns), 2)],
            implode(', ', array_map(fn (float $value): string => sprintf('%.1f', $value), $processRuns)),
            $processRuns[intdiv(count($processRuns), 2)],
        );
    }
}
