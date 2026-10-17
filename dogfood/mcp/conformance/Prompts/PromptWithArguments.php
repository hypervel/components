<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Prompts;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Completions\CompletionResponse;
use Hypervel\Mcp\Server\Contracts\Completable;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;

class PromptWithArguments extends Prompt implements Completable
{
    protected string $name = 'test_prompt_with_arguments';

    protected string $description = 'A prompt with required arguments';

    /**
     * Return the supplied prompt arguments.
     */
    public function handle(Request $request): Response
    {
        $arg1 = $request->get('arg1');
        $arg2 = $request->get('arg2');

        return Response::text("Prompt with arguments: arg1=\"{$arg1}\", arg2=\"{$arg2}\"");
    }

    /**
     * Define the required prompt arguments.
     *
     * @return list<Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('arg1', 'First test argument', true),
            new Argument('arg2', 'Second test argument', true),
        ];
    }

    /**
     * Suggest values for the first prompt argument.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return $argument === 'arg1'
            ? CompletionResponse::match(['test', 'testing', 'testable'])
            : CompletionResponse::empty();
    }
}
