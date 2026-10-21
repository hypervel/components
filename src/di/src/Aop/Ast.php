<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;

class Ast
{
    private ?Parser $astParser = null;

    private ?PrettyPrinterAbstract $printer = null;

    /**
     * Parse PHP code into an AST.
     */
    public function parse(string $code): ?array
    {
        $this->astParser ??= (new ParserFactory)->createForNewestSupportedVersion();

        return $this->astParser->parse($code);
    }

    /**
     * Generate proxy code for the given class.
     *
     * Parse the supplied class source, apply all registered AST visitors,
     * and return the modified PHP code.
     */
    public function proxy(string $className, string $sourceFilePath, string $sourceCode): string
    {
        $stmts = $this->parse($sourceCode) ?? [];
        $traverser = new NodeTraverser;
        $visitorMetadata = new VisitorMetadata($className, $sourceFilePath);

        // Users can modify or replace node visitors via AstVisitorRegistry.
        $queue = clone AstVisitorRegistry::getQueue();

        foreach ($queue as $string) {
            $visitor = new $string($visitorMetadata);
            $traverser->addVisitor($visitor);
        }

        $modifiedStmts = $traverser->traverse($stmts);
        $this->printer ??= new Standard;

        return $this->printer->prettyPrintFile($modifiedStmts);
    }
}
