<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\View\Compilers\BladeCompiler;
use PHPStan\DependencyInjection\AutowiredService;

use function array_any;
use function array_slice;
use function is_array;
use function method_exists;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function strlen;
use function strpos;
use function substr;
use function token_get_all;
use function ucfirst;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Finds the code Blade compiles in a view by following Blade's own passes, without compiling the view.
 */
#[AutowiredService]
class BladeScanner
{
    use HasContainer;

    /**
     * Verbatim blocks, which Blade sets aside before compiling anything else.
     */
    private const string VERBATIM_REGEX = '/(?<!@)@verbatim(.*?)@endverbatim/s';

    /**
     * Blocks of PHP statements, which Blade sets aside next.
     */
    private const string PHP_BLOCK_REGEX = '/(?<!@)@php(.*?)@endphp/s';

    private const string COMMENT_REGEX = '/\{\{--.*?--\}\}/s';

    /**
     * The start of a directive with arguments, up to its opening parenthesis.
     *
     * Blade leaves an @@ prefixed directive, or one that follows a word character as in
     * an email address, as text.
     */
    private const string DIRECTIVE_REGEX = '/(?<![\w@])@(\w+)[ \t]*(?=\()/';

    /**
     * Set aside the verbatim and @php blocks and remove the comments, as Blade does before compiling anything else.
     *
     * Each is blanked in the source, keeping every offset and line. Blade sets the blocks
     * aside before it removes comments, so a comment around a block removes the block too.
     *
     * @return array{list<array{string, int}>, list<array{string, int}>} the content and offset of each remaining verbatim block, then of each remaining @php block
     */
    public function setAside(string &$source): array
    {
        $verbatimBlocks = $this->take(self::VERBATIM_REGEX, $source);
        $phpBlocks = $this->take(self::PHP_BLOCK_REGEX, $source);
        $comments = $this->take(self::COMMENT_REGEX, $source);

        $commented = static fn (int $offset): bool => array_any(
            $comments,
            static fn (array $comment): bool => $offset >= $comment[0][1] && $offset < $comment[0][1] + strlen($comment[0][0]),
        );

        $remaining = [[], []];

        foreach ([$verbatimBlocks, $phpBlocks] as $index => $blocks) {
            foreach ($blocks as $match) {
                if (! $commented($match[1][1])) {
                    $remaining[$index][] = $match[1];
                }
            }
        }

        return $remaining;
    }

    /**
     * Get the directives with arguments that Blade compiles in the given text, with each one's argument list and its offset.
     *
     * Blade leaves an unknown directive as text, and a custom directive's handler decides
     * what its arguments mean, so neither is included.
     *
     * @return list<array{string, string, int}>
     */
    public function directives(string $html): array
    {
        /** @var BladeCompiler $compiler */
        $compiler = $this->resolve('blade.compiler');

        preg_match_all(self::DIRECTIVE_REGEX, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $directives = [];
        $end = 0;

        foreach ($matches as $match) {
            // Blade reads a directive inside another's arguments as part of those arguments.
            if ($match[0][1] < $end) {
                continue;
            }

            $start = $match[0][1] + strlen($match[0][0]);
            $arguments = $this->argumentList($html, $start);

            if ($arguments === null) {
                continue;
            }

            $end = $start + strlen($arguments);
            $name = $match[1][0];

            if (! isset($compiler->getCustomDirectives()[$name]) && method_exists($compiler, 'compile' . ucfirst($name))) {
                $directives[] = [$name, $arguments, $start];
            }
        }

        return $directives;
    }

    /**
     * Take the matches of the given pattern out of the view text, keeping every offset and line.
     *
     * @return list<array<array{null|string, int}>>
     */
    public function take(string $pattern, string &$html): array
    {
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $html = (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]),
            $html,
        );

        return $matches;
    }

    /**
     * Get the parenthesized argument list starting at the given offset of the view text.
     */
    private function argumentList(string $html, int $offset): ?string
    {
        // Try each closing parenthesis in turn, so only the call itself is tokenized.
        // PHP's tokenizer keeps parentheses inside strings and comments out of the count.
        for ($end = strpos($html, ')', $offset); $end !== false; $end = strpos($html, ')', $end + 1)) {
            $arguments = substr($html, $offset, $end + 1 - $offset);
            $depth = 0;

            foreach (array_slice(token_get_all('<?php ' . $arguments), 1) as $token) {
                $value = is_array($token) ? $token[1] : $token;

                if ($value === '(') {
                    ++$depth;
                } elseif ($value === ')') {
                    --$depth;
                }
            }

            if ($depth === 0) {
                return $arguments;
            }
        }

        return null;
    }
}
