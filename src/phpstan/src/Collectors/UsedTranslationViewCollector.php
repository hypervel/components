<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\View\Compilers\BladeCompiler;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\NodeFinder;
use PHPStan\DependencyInjection\AutowiredService;

use function array_any;
use function array_push;
use function array_slice;
use function is_array;
use function method_exists;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function token_get_all;
use function ucfirst;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * @phpstan-import-type TranslationLookup from CollectsTranslationLookups
 */
#[AutowiredService]
class UsedTranslationViewCollector
{
    use CollectsTranslationLookups;
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
     * Component tags, whose attributes are matched as Blade matches them.
     */
    private const string COMPONENT_TAG_REGEX = <<<'REGEXP'
    /
        <\s*x[-:][\w\-:.]*
        (?<attributes>
            (?:
                \s+
                (?:
                    @(?:class|style)(?<parentheses>\((?:[^()]++|(?&parentheses))*\))
                    |
                    \{\{\s*\$attributes(?:[^}]+?)?\s*\}\}
                    |
                    :\$\w+
                    |
                    [\w\-:.@%]+(?:=(?:"[^"]*"|'[^']*'|[^'"=<>]+))?
                )
            )*
            \s*
        )
        \/?>
    /x
    REGEXP;

    /**
     * Component attributes bound with a single colon, whose values are PHP expressions.
     */
    private const string BOUND_ATTRIBUTE_REGEX = '/(?<!\S):(?!:)[\w\-:.@]+=(?:"(?<double>[^"]*)"|\'(?<single>[^\']*)\')/';

    /**
     * The start of a directive with arguments, up to its opening parenthesis.
     *
     * Blade leaves an @@ prefixed directive, or one that follows a word character as in
     * an email address, as text.
     */
    private const string DIRECTIVE_REGEX = '/(?<![\w@])@(\w+)[ \t]*(?=\()/';

    /**
     * Echoes in Blade's compile order: raw, escaped, then regular. An @ prefix leaves an echo as text.
     *
     * @var list<non-empty-string>
     */
    private const array ECHO_REGEXES = [
        '/(@)?\{!!\s*(.+?)\s*!!\}/s',
        '/(@)?\{\{\{\s*(.+?)\s*\}\}\}/s',
        '/(@)?\{\{\s*(.+?)\s*\}\}/s',
    ];

    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ViewParser $viewParser,
        private ViewFileHelper $viewFileHelper,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Get the translations looked up by the root views, keyed by view file.
     *
     * @return array<string, TranslationLookup[]>
     */
    public function getUsedTranslations(): array
    {
        $translations = [];

        foreach ($this->viewFileHelper->getRootViewFilePaths() as $viewFile) {
            $translations[$viewFile] = $this->processView($this->filesystem->get($viewFile));
        }

        return $translations;
    }

    /**
     * Get the translations looked up by the given view source.
     *
     * Upstream matches translation calls anywhere in a view's text, so literal text,
     * JavaScript and string literals count as lookups, while native PHP does not. This
     * reads only the code Blade runs, following Blade's own passes.
     *
     * @return list<TranslationLookup>
     */
    private function processView(string $source): array
    {
        $fragments = [];

        $verbatimBlocks = $this->take(self::VERBATIM_REGEX, $source);
        $phpBlocks = $this->take(self::PHP_BLOCK_REGEX, $source);
        $comments = $this->take(self::COMMENT_REGEX, $source);

        // Blade sets verbatim and @php blocks aside before it removes comments, so a comment
        // around a block removes the block too.
        $commented = static fn (int $offset): bool => array_any(
            $comments,
            static fn (array $comment): bool => $offset >= $comment[0][1] && $offset < $comment[0][1] + strlen($comment[0][0]),
        );

        // Blade restores a verbatim block as written: its Blade syntax stays text, while its
        // PHP tags still run.
        foreach ($verbatimBlocks as $match) {
            if (! $commented($match[1][1])) {
                $fragments[] = [$match[1][0], 1 + substr_count($source, "\n", 0, $match[1][1])];
            }
        }

        foreach ($phpBlocks as $match) {
            if (! $commented($match[1][1])) {
                $fragments[] = ["<?php {$match[1][0]} ?>", 1 + substr_count($source, "\n", 0, $match[1][1])];
            }
        }

        preg_match_all(self::COMPONENT_TAG_REGEX, $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as $tag) {
            preg_match_all(self::BOUND_ATTRIBUTE_REGEX, $tag['attributes'][0], $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

            foreach ($attributes as $attribute) {
                [$value, $offset] = $attribute['double'][0] === null ? $attribute['single'] : $attribute['double'];

                $fragments[] = ["<?php {$value};", 1 + substr_count($source, "\n", 0, $tag['attributes'][1] + $offset)];
            }
        }

        $statements = $this->viewParser->parseCode($source);

        foreach ((new NodeFinder)->findInstanceOf($statements, InlineHTML::class) as $node) {
            foreach ($this->textFragments($node->value) as [$code, $offset]) {
                $fragments[] = [$code, $node->getStartLine() + substr_count($node->value, "\n", 0, $offset)];
            }
        }

        $translations = $this->lookups($statements, 0);

        foreach ($fragments as [$code, $line]) {
            // Most fragments make no translation call, so they are not parsed.
            if (preg_match('/trans|__|lang/i', $code) === 1) {
                array_push($translations, ...$this->lookups($this->viewParser->parseCode($code), $line - 1));
            }
        }

        return $translations;
    }

    /**
     * Get the PHP that Blade compiles from the directives and echoes in the given text, with each fragment's offset.
     *
     * Directives compile before echoes, as in Blade. Each echo is blanked as it is taken,
     * keeping offsets, so a later echo pattern cannot read it again.
     *
     * @return list<array{string, int}>
     */
    private function textFragments(string $html): array
    {
        $fragments = [];

        preg_match_all(self::DIRECTIVE_REGEX, $html, $directives, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $end = 0;

        foreach ($directives as $directive) {
            // Blade reads a directive inside another's arguments as part of those arguments.
            if ($directive[0][1] < $end) {
                continue;
            }

            $start = $directive[0][1] + strlen($directive[0][0]);
            $arguments = $this->argumentList($html, $start);

            if ($arguments === null) {
                continue;
            }

            $end = $start + strlen($arguments);
            $code = $this->directiveCode($directive[1][0], $arguments);

            if ($code !== null) {
                $fragments[] = [$code, $start];
            }
        }

        foreach (self::ECHO_REGEXES as $pattern) {
            foreach ($this->take($pattern, $html) as $match) {
                if ($match[1][0] === null) {
                    $fragments[] = ["<?php echo {$match[2][0]};", $match[2][1]];
                }
            }
        }

        return $fragments;
    }

    /**
     * Get the PHP that runs a directive's arguments, or null when Blade does not run them as written.
     *
     * Blade leaves an unknown directive as text, and a custom directive's handler decides
     * what its arguments mean. Built-in directives run their arguments as PHP: @lang and
     * @choice compile to the translator's get() and choice() methods, and loop headers
     * keep their own syntax.
     */
    private function directiveCode(string $name, string $arguments): ?string
    {
        /** @var BladeCompiler $compiler */
        $compiler = $this->resolve('blade.compiler');

        if (isset($compiler->getCustomDirectives()[$name]) || ! method_exists($compiler, 'compile' . ucfirst($name))) {
            return null;
        }

        return match (strtolower($name)) {
            'lang' => "<?php Lang::get{$arguments};",
            'choice' => "<?php Lang::choice{$arguments};",
            'for' => "<?php for{$arguments} {}",
            'foreach', 'forelse' => "<?php foreach{$arguments} {}",
            default => "<?php f{$arguments};",
        };
    }

    /**
     * Take the matches of the given pattern out of the view text, keeping every offset and line.
     *
     * @return list<array<array{null|string, int}>>
     */
    private function take(string $pattern, string &$html): array
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
     * Get the lookups made by PHP from a view.
     *
     * Upstream unescapes each matched key and ignores the other arguments. Parsing the
     * code reads keys exactly as PHP does, skips interpolated keys, and supplies the
     * locale and fallback arguments.
     *
     * @param Stmt[] $statements
     * @param int $lineOffset the number of view lines before the code's first line
     * @return list<TranslationLookup>
     */
    private function lookups(array $statements, int $lineOffset): array
    {
        $lookups = [];

        foreach ((new NodeFinder)->findInstanceOf($statements, CallLike::class) as $call) {
            // PHPStan turns a first-class callable into its own node, but views are parsed directly.
            if ($call->isFirstClassCallable()) {
                continue;
            }

            $name = match (true) {
                $call instanceof FuncCall && $call->name instanceof Name => $call->name->toLowerString(),
                $call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier => "{$call->class->toLowerString()}::{$call->name->toLowerString()}",
                default => null,
            };

            $operation = match ($name) {
                '__', 'trans' => [false, false],
                'trans_choice', 'lang::choice' => [true, false],
                'lang::get' => [false, true],
                default => null,
            };

            if ($operation === null) {
                continue;
            }

            $lookup = $this->translationLookup($call, choice: $operation[0], acceptsFallback: $operation[1], scope: null);

            if ($lookup !== null) {
                $lookup['line'] = $lineOffset + $call->getStartLine();
                $lookups[] = $lookup;
            }
        }

        return $lookups;
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
