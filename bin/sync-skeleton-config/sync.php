<?php

declare(strict_types=1);

namespace Hypervel\Tools;

use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpToken;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

class SkeletonConfigSync
{
    /**
     * Generate or check the managed configuration files.
     */
    public function run(string $root, bool $check): int
    {
        $rules = require $root . '/bin/sync-skeleton-config/rules.php';
        $this->validateKeys($rules, ['exclude', 'stubs', 'skeletons'], 'rules');

        foreach (['stubs', 'skeletons'] as $section) {
            if (! array_key_exists($section, $rules)) {
                throw new InvalidArgumentException("Rules must define an array for {$section}.");
            }
        }

        $sources = [];

        foreach (glob($root . '/src/foundation/config/*.php') as $path) {
            $sources[basename($path)] = file_get_contents($path);
        }

        if ($sources === []) {
            throw new RuntimeException('No framework configuration files found.');
        }

        $excluded = $rules['exclude'] ?? [];

        foreach ($excluded as $name) {
            if (! array_key_exists($name, $sources)) {
                throw new InvalidArgumentException("Exclusion refers to missing framework config: {$name}");
            }
        }

        $expected = [];
        $published = [];

        foreach ($rules['stubs'] as $name => $operations) {
            if (! array_key_exists($name, $sources)) {
                throw new InvalidArgumentException("Stub rule refers to missing config: {$name}");
            }

            $published[$name] = $this->transform($sources[$name], $operations, "stubs/{$name}");
            $expected["src/foundation/config-stubs/{$name}"] = $published[$name];
        }

        foreach ($rules['skeletons'] as $skeleton => $files) {
            if (! is_array($files)) {
                throw new InvalidArgumentException("Skeleton rules must be an array: {$skeleton}");
            }

            if (! is_dir($root . '/skeletons/' . $skeleton)) {
                throw new InvalidArgumentException("Skeleton directory does not exist: {$skeleton}");
            }

            foreach ($files as $name => $operations) {
                if (! array_key_exists($name, $sources)) {
                    throw new InvalidArgumentException("Skeleton rule refers to missing config: {$skeleton}/{$name}");
                }

                if (in_array($name, $excluded, true)) {
                    throw new InvalidArgumentException("Skeleton rule refers to excluded config: {$skeleton}/{$name}");
                }
            }

            foreach ($sources as $name => $contents) {
                if (in_array($name, $excluded, true)) {
                    $expected["skeletons/{$skeleton}/config/{$name}"] = null;
                    continue;
                }

                $expected["skeletons/{$skeleton}/config/{$name}"] = $this->transform(
                    $published[$name] ?? $contents,
                    $files[$name] ?? [],
                    "{$skeleton}/{$name}"
                );
            }
        }

        // This directory contains only generated overrides. Removed rules must not
        // leave obsolete stubs that config:publish would continue to prefer.
        foreach (glob($root . '/src/foundation/config-stubs/*.php') as $path) {
            $relative = 'src/foundation/config-stubs/' . basename($path);

            if (! array_key_exists($relative, $expected)) {
                $expected[$relative] = null;
            }
        }

        $changed = false;

        // Build every output before writing, so invalid rules cannot partially sync.
        foreach ($expected as $relative => $contents) {
            $path = $root . '/' . $relative;
            $current = is_file($path) ? file_get_contents($path) : null;

            if ($contents === $current) {
                continue;
            }

            $changed = true;

            if ($check) {
                fwrite(STDERR, "Out of sync: {$relative}" . ($contents === null ? ' (must be absent)' : '') . "\n");
                continue;
            }

            if ($contents === null) {
                if (! unlink($path)) {
                    throw new RuntimeException("Unable to remove {$relative}");
                }
            } else {
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }

                if (file_put_contents($path, $contents) === false) {
                    throw new RuntimeException("Unable to write {$relative}");
                }
            }

            fwrite(STDOUT, ($contents === null ? 'Removed: ' : 'Updated: ') . $relative . "\n");
        }

        if ($check && $changed) {
            return 1;
        }

        fwrite(STDOUT, $changed ? "Configuration synced.\n" : "Configuration is up to date.\n");

        return 0;
    }

    /**
     * Apply explicit changes without evaluating config expressions.
     */
    protected function transform(string $source, array $operations, string $context): string
    {
        $this->validateKeys($operations, ['omit', 'replace'], $context);

        // App classes do exist in skeletons; this suppression is needed only when
        // PHPStan analyzes the framework's defaults outside an application.
        foreach (array_reverse(PhpToken::tokenize($source)) as $token) {
            if ($token->id === T_COMMENT && trim($token->text) === '// @phpstan-ignore class.notFound') {
                $start = $token->pos;

                while ($start > 0 && $source[$start - 1] === ' ') {
                    --$start;
                }

                $source = substr_replace($source, '', $start, $token->pos + strlen($token->text) - $start);
            }
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        try {
            $original = $parser->parse($source);
        } catch (Error $exception) {
            throw new InvalidArgumentException("Invalid config syntax for {$context}: " . $exception->getMessage(), previous: $exception);
        }
        $tokens = $parser->getTokens();
        $statements = (new NodeTraverser(new CloningVisitor))->traverse($original);
        $returns = array_values(array_filter($statements, fn (Node $node): bool => $node instanceof Return_));

        if (count($returns) !== 1 || ! $returns[0]->expr instanceof Array_) {
            throw new InvalidArgumentException("Config must return a literal array: {$context}");
        }

        foreach ($operations['omit'] ?? [] as $key) {
            $this->changeKey($returns[0]->expr, explode('.', $key), null, $context . ':' . $key);
        }

        foreach ($operations['replace'] ?? [] as $key => $expression) {
            try {
                $replacement = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php return ' . $expression . ';');
            } catch (Error $exception) {
                throw new InvalidArgumentException("Invalid replacement for {$context}:{$key}: " . $exception->getMessage(), previous: $exception);
            }

            if (count($replacement) !== 1 || ! $replacement[0] instanceof Return_ || $replacement[0]->expr === null) {
                throw new InvalidArgumentException("Replacement must be one PHP expression: {$context}:{$key}");
            }

            $this->changeKey($returns[0]->expr, explode('.', $key), $replacement[0]->expr, $context . ':' . $key);
        }

        if ($operations === []) {
            return $source;
        }

        $statements = $this->removeUnusedImports($statements);

        $output = (new Standard)->printFormatPreserving($statements, $original, $tokens);

        // Removing the first imports can make the format-preserving printer
        // consume the blank line separating strict_types from the import block.
        $printed = $parser->parse($output);

        foreach ($printed as $index => $statement) {
            if (($statement instanceof Use_ || $statement instanceof GroupUse) && ($printed[$index - 1] ?? null) instanceof Declare_) {
                $start = $printed[$index - 1]->getEndFilePos() + 1;
                $length = $statement->getStartFilePos() - $start;

                if (substr($output, $start, $length) === "\n") {
                    $output = substr_replace($output, "\n\n", $start, $length);
                }

                break;
            }
        }

        return $output;
    }

    /**
     * Remove or replace an existing literal array key.
     */
    protected function changeKey(Array_ $array, array $segments, ?Node\Expr $replacement, string $context): void
    {
        $segment = array_shift($segments);

        foreach ($array->items as $index => $item) {
            if ($item === null || ! $item->key instanceof String_ || $item->key->value !== $segment) {
                continue;
            }

            if ($segments !== []) {
                if (! $item->value instanceof Array_) {
                    throw new InvalidArgumentException("Rule traverses a non-literal array: {$context}");
                }

                $this->changeKey($item->value, $segments, $replacement, $context);
            } elseif ($replacement === null) {
                array_splice($array->items, $index, 1);
            } else {
                $item->value = $replacement;
            }

            return;
        }

        throw new InvalidArgumentException("Rule refers to missing key: {$context}");
    }

    /**
     * Remove imports no longer referenced by the retained config or its comments.
     */
    protected function removeUnusedImports(array $statements): array
    {
        $body = array_filter($statements, fn (Node $node): bool => ! $node instanceof Use_ && ! $node instanceof GroupUse);
        $finder = new NodeFinder;
        $used = [];
        $comments = '';

        foreach ($finder->findInstanceOf($body, Name::class) as $name) {
            if (! $name->isFullyQualified()) {
                $used[strtolower($name->getFirst())] = true;
            }
        }

        foreach ($finder->find($body, fn (Node $node): bool => $node->getComments() !== []) as $node) {
            foreach ($node->getComments() as $comment) {
                $comments .= $comment->getText() . "\n";
            }
        }

        foreach ($statements as $index => $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }

            $statement->uses = array_values(array_filter($statement->uses, function (Node\UseItem $import) use ($used, $comments): bool {
                $alias = $import->getAlias()->toString();

                return isset($used[strtolower($alias)]) || preg_match('/\b' . preg_quote($alias, '/') . '\b/', $comments) === 1;
            }));

            if ($statement->uses === []) {
                unset($statements[$index]);
            }
        }

        return array_values($statements);
    }

    /**
     * Reject misspelled or malformed rule sections instead of ignoring them.
     */
    protected function validateKeys(array $rules, array $allowed, string $context): void
    {
        foreach (array_keys($rules) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unknown rule in {$context}: {$key}");
            }

            if (! is_array($rules[$key])) {
                throw new InvalidArgumentException("Rule must be an array: {$context}:{$key}");
            }
        }
    }
}

$root = dirname(__DIR__, 2);
$check = false;

try {
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') {
            $check = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $root = rtrim(substr($argument, strlen('--root=')), '/');
        } else {
            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }
    }

    $status = (new SkeletonConfigSync)->run($root, $check);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\nAt: " . $exception->getFile() . ':' . $exception->getLine() . "\n");
    $status = 1;
}

if ($status !== 0) {
    fwrite(STDERR, <<<'TEXT'

Read bin/sync-skeleton-config/README.md before fixing this failure.
Source configs: src/foundation/config/
Rules: bin/sync-skeleton-config/rules.php
Sync/check script: bin/sync-skeleton-config/sync.php
From the repository root, run composer sync:skeleton-config, review and commit
the generated changes, then verify with composer check:skeleton-config.
Fix invalid rules or source syntax first; do not hand-edit generated configs.

TEXT);
}

exit($status);
