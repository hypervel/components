<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Resources\Proto;

use RuntimeException;
use Throwable;

const GRPC_HEALTH_PROTOCOL_PATH = 'grpc/health/v1/health.proto';

/**
 * Request an upstream gRPC resource.
 */
function requestGrpcHealthResource(string $url, bool $authenticate = false): string
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: hypervel-components-grpc-health-sync',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = getenv('GITHUB_TOKEN');

    if ($authenticate && is_string($token) && $token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
        ],
    ]);
    $contents = @file_get_contents($url, false, $context);

    if ($contents === false) {
        throw new RuntimeException("Unable to fetch gRPC health resource [{$url}].");
    }

    return $contents;
}

/**
 * Return the latest revision that changed the canonical health protocol.
 */
function latestGrpcHealthProtocolRevision(): string
{
    $path = rawurlencode(GRPC_HEALTH_PROTOCOL_PATH);
    $response = requestGrpcHealthResource(
        "https://api.github.com/repos/grpc/grpc-proto/commits?path={$path}&per_page=1",
        authenticate: true,
    );
    $commits = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    $revision = $commits[0]['sha'] ?? null;

    if (! is_string($revision) || preg_match('/\A[0-9a-f]{40}\z/', $revision) !== 1) {
        throw new RuntimeException('The gRPC health protocol response did not contain a valid revision.');
    }

    return $revision;
}

/**
 * Download the canonical health protocol at a pinned revision.
 */
function downloadGrpcHealthProtocol(string $revision): string
{
    if (preg_match('/\A[0-9a-f]{40}\z/', $revision) !== 1) {
        throw new RuntimeException("Invalid gRPC health protocol revision [{$revision}].");
    }

    return requestGrpcHealthResource(
        "https://raw.githubusercontent.com/grpc/grpc-proto/{$revision}/" . GRPC_HEALTH_PROTOCOL_PATH,
    );
}

/**
 * Add the PHP namespaces required by Hypervel's generated health classes.
 */
function addHypervelPhpNamespaces(string $protocol): string
{
    $protocol = str_replace(["\r\n", "\r"], "\n", $protocol);

    if (! str_ends_with($protocol, "\n")) {
        $protocol .= "\n";
    }

    if (preg_match('/^option php_(?:metadata_)?namespace\s*=/m', $protocol) === 1) {
        throw new RuntimeException('The upstream gRPC health protocol already declares a PHP namespace.');
    }

    if (preg_match('/(?:^option [^\n]+;\n)+/m', $protocol, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('The upstream gRPC health protocol does not contain an option block.');
    }

    [$options, $offset] = $matches[0];
    $phpNamespaces = <<<'PROTO'
option php_metadata_namespace = "Hypervel\\Grpc\\Health\\V1\\Metadata";
option php_namespace = "Hypervel\\Grpc\\Health\\V1";
PROTO;

    return substr_replace(
        $protocol,
        $options . $phpNamespaces . "\n",
        $offset,
        strlen($options),
    );
}

/**
 * Replace the pinned health protocol revision in the maintainer documentation.
 */
function replaceGrpcHealthProtocolRevision(string $readme, string $revision): string
{
    if (preg_match('/\A[0-9a-f]{40}\z/', $revision) !== 1) {
        throw new RuntimeException("Invalid gRPC health protocol revision [{$revision}].");
    }

    $updated = preg_replace_callback(
        '/(`grpc\/grpc-proto` revision\s+`)[0-9a-f]{40}(`\.)/',
        static fn (array $matches): string => $matches[1] . $revision . $matches[2],
        $readme,
        1,
        $replacements,
    );

    if (! is_string($updated) || $replacements !== 1) {
        throw new RuntimeException('Unable to replace the pinned gRPC health protocol revision.');
    }

    return $updated;
}

/**
 * Publish one synchronized health protocol file atomically.
 */
function publishGrpcHealthProtocolFile(string $destination, string $contents): void
{
    $temporary = @tempnam(dirname($destination), '.grpc-health-');

    if ($temporary === false) {
        throw new RuntimeException("Unable to create a temporary file for [{$destination}].");
    }

    try {
        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException("Unable to write the complete file [{$destination}].");
        }

        $permissions = is_file($destination) ? fileperms($destination) & 0777 : 0644;

        if (! @chmod($temporary, $permissions)) {
            throw new RuntimeException("Unable to set permissions for [{$destination}].");
        }

        if (! @rename($temporary, $destination)) {
            throw new RuntimeException("Unable to publish [{$destination}].");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * Synchronize and regenerate the health protocol.
 *
 * @param callable(): void $generate
 */
function synchronizeGrpcHealthProtocol(
    string $upstreamProtocol,
    string $revision,
    string $protocolPath,
    string $readmePath,
    callable $generate,
): bool {
    $protocol = addHypervelPhpNamespaces($upstreamProtocol);
    $currentProtocol = @file_get_contents($protocolPath);

    if ($currentProtocol === false) {
        throw new RuntimeException("Unable to read the current gRPC health protocol [{$protocolPath}].");
    }

    // A repository revision is provenance, not an update by itself.
    if ($protocol === $currentProtocol) {
        return false;
    }

    $readme = @file_get_contents($readmePath);

    if ($readme === false) {
        throw new RuntimeException("Unable to read the gRPC protocol README [{$readmePath}].");
    }

    try {
        publishGrpcHealthProtocolFile($protocolPath, $protocol);
        publishGrpcHealthProtocolFile(
            $readmePath,
            replaceGrpcHealthProtocolRevision($readme, $revision),
        );
        $generate();
    } catch (Throwable $exception) {
        publishGrpcHealthProtocolFile($protocolPath, $currentProtocol);
        publishGrpcHealthProtocolFile($readmePath, $readme);

        throw $exception;
    }

    return true;
}

/**
 * Regenerate the committed health protocol classes.
 */
function generateGrpcHealthProtocol(string $repositoryRoot): void
{
    $process = proc_open(
        ['bash', $repositoryRoot . '/bin/generate-grpc-health.sh'],
        [STDIN, STDOUT, STDERR],
        $pipes,
        $repositoryRoot,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the gRPC health protocol generator.');
    }

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("The gRPC health protocol generator failed with exit code {$exitCode}.");
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$repositoryRoot = dirname(__DIR__, 4);
$protocolPath = __DIR__ . '/' . GRPC_HEALTH_PROTOCOL_PATH;
$readmePath = __DIR__ . '/README.md';
$revision = latestGrpcHealthProtocolRevision();
$upstreamProtocol = downloadGrpcHealthProtocol($revision);

if (! synchronizeGrpcHealthProtocol(
    $upstreamProtocol,
    $revision,
    $protocolPath,
    $readmePath,
    static function () use ($repositoryRoot): void {
        generateGrpcHealthProtocol($repositoryRoot);
    },
)) {
    fwrite(STDOUT, "The gRPC health protocol is already current.\n");

    exit(0);
}

fwrite(STDOUT, "Synchronized the gRPC health protocol at revision {$revision}.\n");
