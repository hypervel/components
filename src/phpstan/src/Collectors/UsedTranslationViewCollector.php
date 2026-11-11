<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use Hypervel\PHPStan\Support\BladeScanner;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\NodeFinder;
use PHPStan\DependencyInjection\AutowiredService;

use function array_push;
use function preg_match;
use function preg_match_all;
use function strtolower;
use function substr_count;

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
        private BladeScanner $bladeScanner,
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

        [$verbatimBlocks, $phpBlocks] = $this->bladeScanner->setAside($source);

        // Blade restores a verbatim block as written: its Blade syntax stays text, while its
        // PHP tags still run.
        foreach ($verbatimBlocks as [$code, $offset]) {
            $fragments[] = [$code, 1 + substr_count($source, "\n", 0, $offset)];
        }

        foreach ($phpBlocks as [$code, $offset]) {
            $fragments[] = ["<?php {$code} ?>", 1 + substr_count($source, "\n", 0, $offset)];
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

        foreach ($this->bladeScanner->directives($html) as [$name, $arguments, $offset]) {
            $fragments[] = [$this->directiveCode($name, $arguments), $offset];
        }

        foreach (self::ECHO_REGEXES as $pattern) {
            foreach ($this->bladeScanner->take($pattern, $html) as $match) {
                if ($match[1][0] === null) {
                    $fragments[] = ["<?php echo {$match[2][0]};", $match[2][1]];
                }
            }
        }

        return $fragments;
    }

    /**
     * Get the PHP that runs a built-in directive's arguments.
     *
     * Built-in directives run their arguments as PHP: @lang and @choice compile to the
     * translator's get() and choice() methods, and loop headers keep their own syntax.
     */
    private function directiveCode(string $name, string $arguments): string
    {
        return match (strtolower($name)) {
            'lang' => "<?php Lang::get{$arguments};",
            'choice' => "<?php Lang::choice{$arguments};",
            'for' => "<?php for{$arguments} {}",
            'foreach', 'forelse' => "<?php foreach{$arguments} {}",
            default => "<?php f{$arguments};",
        };
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
}
