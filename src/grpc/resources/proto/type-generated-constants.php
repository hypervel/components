<?php

declare(strict_types=1);

// Protoc emits enum constants without native types. The gRPC health generation
// workflow runs this adaptation before formatting and committing its PHP output.

/**
 * Report a generated constant typing failure.
 */
function failGeneratedConstantTyping(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);

    exit(1);
}

if ($argc !== 2) {
    failGeneratedConstantTyping('Usage: php type-generated-constants.php <generated-directory>');
}

$generatedDirectory = $argv[1];

if (! is_dir($generatedDirectory)) {
    failGeneratedConstantTyping("Generated directory [{$generatedDirectory}] does not exist.");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($generatedDirectory, FilesystemIterator::SKIP_DOTS)
);

$typedConstants = 0;

foreach ($files as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);

    if ($contents === false) {
        failGeneratedConstantTyping("Unable to read generated file [{$path}].");
    }

    $updatedContents = preg_replace(
        '/^(\s*(?:(?:final|public|protected|private)\s+)*const )([A-Z][A-Z0-9_]*) = (-?\d+);$/m',
        '${1}int ${2} = ${3};',
        $contents,
        -1,
        $replacementCount
    );

    if ($updatedContents === null) {
        failGeneratedConstantTyping("Unable to type constants in generated file [{$path}].");
    }

    if (preg_match('/^\s*(?:(?:final|public|protected|private)\s+)*const [A-Z][A-Z0-9_]* = /m', $updatedContents) === 1) {
        failGeneratedConstantTyping("Generated file [{$path}] contains a class constant without a supported type.");
    }

    if ($replacementCount === 0) {
        continue;
    }

    if (file_put_contents($path, $updatedContents) === false) {
        failGeneratedConstantTyping("Unable to update generated file [{$path}].");
    }

    $typedConstants += $replacementCount;
}

fwrite(STDOUT, "Typed {$typedConstants} generated protobuf constants." . PHP_EOL);
