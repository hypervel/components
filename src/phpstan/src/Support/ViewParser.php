<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PhpParser\Node\Stmt;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;

#[AutowiredService]
class ViewParser
{
    /**
     * Create a new view parser instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '@currentPhpVersionSimpleDirectParser')]
        private Parser $parser,
    ) {
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
}
