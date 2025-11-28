<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

use Hyperf\Database\Exception\MultipleRecordsFoundException;
use Hyperf\Database\Exception\RecordsNotFoundException;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hypervel\Context\Context;
use Hypervel\Filesystem\Filesystem;
use Hypervel\HttpMessage\Exceptions\HttpResponseException;
use Hypervel\Support\Str;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\ViewException;
use Throwable;

class CompilerEngine extends PhpEngine
{
    /**
     * The context key for a stack of the compiled template path.
     */
    protected const COMPILED_PATH_CONTEXT_KEY = 'compiled_path';

    /**
     * The view paths that were compiled or are not expired, keyed by the path.
     *
     * @var array<string, true>
     */
    protected $compiledOrNotExpired = [];

    /**
     * Create a new compiler engine instance.
     */
    public function __construct(
        protected CompilerInterface $compiler,
        ?Filesystem $files = null
    ) {
        parent::__construct($files ?: new Filesystem);
    }

    /**
     * Get the evaluated contents of the view.
     *
     * @throws ViewException
     */
    public function get(string $path, array $data = []): string
    {
        $this->pushCompiledPath($path);

        // If this given view has expired, which means it has simply been edited since
        // it was last compiled, we will re-compile the views so we can evaluate a
        // fresh copy of the view. We'll pass the compiler the path of the view.
        if (! isset($this->compiledOrNotExpired[$path]) && $this->compiler->isExpired($path)) {
            $this->compiler->compile($path);
        }

        // Once we have the path to the compiled file, we will evaluate the paths with
        // typical PHP just like any other templates. We also keep a stack of views
        // which have been rendered for right exception messages to be generated.

        try {
            $results = $this->evaluatePath($this->compiler->getCompiledPath($path), $data);
        } catch (ViewException $e) {
            if (! Str::of($e->getMessage())->contains(['No such file or directory', 'File does not exist at path'])) {
                throw $e;
            }

            if (! isset($this->compiledOrNotExpired[$path])) {
                throw $e;
            }

            $this->compiler->compile($path);

            $results = $this->evaluatePath($this->compiler->getCompiledPath($path), $data);
        }

        $this->compiledOrNotExpired[$path] = true;

        $this->popCompiledPath();

        return $results;
    }

    protected function pushCompiledPath(string $path): void
    {
        $stack = Context::get(static::COMPILED_PATH_CONTEXT_KEY, []);
        $stack[] = $path;
        Context::set(static::COMPILED_PATH_CONTEXT_KEY, $stack);
    }

    protected function popCompiledPath(): void
    {
        $stack = Context::get(static::COMPILED_PATH_CONTEXT_KEY, []);
        array_pop($stack);
        Context::set(static::COMPILED_PATH_CONTEXT_KEY, $stack);
    }

    /**
     * Handle a view exception.
     *
     * @throws Throwable
     */
    protected function handleViewException(Throwable $e, int $obLevel): void
    {
        if ($e instanceof HttpException ||
            $e instanceof HttpResponseException ||
            $e instanceof MultipleRecordsFoundException ||
            $e instanceof RecordsNotFoundException ||
            $e instanceof ModelNotFoundException
        ) {
            parent::handleViewException($e, $obLevel);
        }

        $e = new ViewException($this->getMessage($e), 0, 1, $e->getFile(), $e->getLine(), $e);

        parent::handleViewException($e, $obLevel);
    }

    /**
     * Get the exception message for an exception.
     */
    protected function getMessage(Throwable $e): string
    {
        $stack = Context::get(static::COMPILED_PATH_CONTEXT_KEY);

        return $e->getMessage().' (View: '.realpath(last($stack)).')';
    }

    /**
     * Get the compiler implementation.
     */
    public function getCompiler(): CompilerInterface
    {
        return $this->compiler;
    }

    /**
     * Clear the cache of views that were compiled or not expired.
     */
    public function forgetCompiledOrNotExpired(): void
    {
        $this->compiledOrNotExpired = [];
    }
}
