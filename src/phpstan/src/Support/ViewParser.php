<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PhpParser\Node\Stmt;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;

use function array_key_exists;

#[AutowiredService]
final class ViewParser
{
    /** @var array<string, Stmt[]> */
    private array $nodes = [];

    /**
     * Create a new view parser instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '@currentPhpVersionSimpleDirectParser')]
        private Parser $parser,
    ) {
    }

    /**
     * Get the parsed statements of the given view file.
     *
     * @return Stmt[]
     */
    public function getNodes(string $path): array
    {
        if (! array_key_exists($path, $this->nodes)) {
            $this->nodes[$path] = $this->parseFile($path);
        }

        return $this->nodes[$path];
    }

    /**
     * Parse PHP code taken from a view, such as a call's argument list.
     *
     * @return Stmt[]
     */
    public function parseCode(string $code): array
    {
        try {
            return $this->parser->parseString($code);
        } catch (ParserErrorsException) {
            return [];
        }
    }

    /**
     * Parse the given view file.
     *
     * @return Stmt[]
     */
    private function parseFile(string $path): array
    {
        try {
            return $this->parser->parseFile($path);
        } catch (ParserErrorsException) {
            return [];
        }
    }
}
