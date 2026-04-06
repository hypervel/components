<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Support\Composer;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;

class Ast
{
    private Parser $astParser;

    private PrettyPrinterAbstract $printer;

    public function __construct()
    {
        $this->astParser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->printer = new Standard;
    }

    /**
     * Parse PHP code into an AST.
     */
    public function parse(string $code): ?array
    {
        return $this->astParser->parse($code);
    }

    /**
     * Generate proxy code for the given class.
     *
     * Reads the class source file, applies all registered AST visitors
     * (via AstVisitorRegistry), and returns the modified PHP code.
     */
    public function proxy(string $className): string
    {
        $code = $this->getCodeByClassName($className);
        $stmts = $this->astParser->parse($code);
        $traverser = new NodeTraverser;
        $visitorMetadata = new VisitorMetadata($className);
        // Users can modify or replace node visitors via AstVisitorRegistry.
        $queue = clone AstVisitorRegistry::getQueue();
        foreach ($queue as $string) {
            $visitor = new $string($visitorMetadata);
            $traverser->addVisitor($visitor);
        }
        $modifiedStmts = $traverser->traverse($stmts);
        return $this->printer->prettyPrintFile($modifiedStmts);
    }

    /**
     * Extract the fully qualified class name from parsed statements.
     */
    public function parseClassByStmts(array $stmts): string
    {
        $namespace = $className = '';
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name) {
                $namespace = $stmt->name->toString();
                foreach ($stmt->stmts as $node) {
                    if (($node instanceof ClassLike) && $node->name) {
                        $className = $node->name->toString();
                        break;
                    }
                }
            }
        }
        return ($namespace && $className) ? $namespace . '\\' . $className : '';
    }

    /**
     * Read the source code for a class from its file.
     */
    private function getCodeByClassName(string $className): string
    {
        $file = Composer::getLoader()->findFile($className);
        if (! $file) {
            return '';
        }
        return file_get_contents($file);
    }
}
