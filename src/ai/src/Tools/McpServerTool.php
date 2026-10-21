<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use Generator;
use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Concerns\NormalizesMcpResult;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request as McpRequest;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool as ServerTool;

class McpServerTool implements Approvable, Tool
{
    use InteractsWithApprovals;
    use NormalizesMcpResult;

    /**
     * The MCP server tool class name.
     */
    protected const string MCP_SERVER_TOOL = ServerTool::class;

    /**
     * The MCP request class name.
     */
    protected const string MCP_REQUEST = McpRequest::class;

    /**
     * The MCP response class name.
     */
    protected const string MCP_RESPONSE = Response::class;

    /**
     * The MCP response factory class name.
     */
    protected const string MCP_RESPONSE_FACTORY = ResponseFactory::class;

    /**
     * Create a new MCP server tool wrapper instance.
     */
    public function __construct(protected object $tool)
    {
    }

    /**
     * Determine whether the given value is an MCP server tool.
     */
    public static function supports(mixed $tool): bool
    {
        return $tool instanceof ServerTool;
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->tool->name();
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return $this->tool->description();
    }

    /**
     * Get the MCP annotations describing the tool's behavior.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return $this->tool->annotations();
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $container = Container::getInstance();

        $previous = CoroutineContext::get(self::MCP_REQUEST);

        // The MCP request binding resolves this entry without sharing it between calls.
        CoroutineContext::set(self::MCP_REQUEST, new (self::MCP_REQUEST)($request->toArray()));

        try {
            return $this->convertResponse($container->call([$this->tool, 'handle']));
        } finally {
            $previous !== null
                ? CoroutineContext::set(self::MCP_REQUEST, $previous)
                : CoroutineContext::forget(self::MCP_REQUEST);
        }
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    /**
     * Convert an MCP server response into tool output.
     */
    protected function convertResponse(mixed $response): string
    {
        if (is_a($response, self::MCP_RESPONSE_FACTORY)) {
            $structured = $response->getStructuredContent();

            if (is_array($structured) && $structured !== []) {
                return $this->json($structured);
            }

            return $this->finalResponse($response->responses()->all());
        }

        $items = $response instanceof Generator
            ? iterator_to_array($response, false)
            : [$response];

        return $this->finalResponse($this->normalize($items));
    }

    /**
     * Flatten Response, ResponseFactory, string, and nested array items into a flat list of Response instances.
     *
     * @param array<int, mixed> $items
     * @return array<int, object>
     */
    protected function normalize(array $items): array
    {
        return collect($items)->flatMap(fn (mixed $item): array => match (true) {
            is_a($item, self::MCP_RESPONSE) => [$item],
            is_a($item, self::MCP_RESPONSE_FACTORY) => $item->responses()->all(),
            is_string($item) => [(self::MCP_RESPONSE)::text($item)],
            is_array($item) => $this->normalize($item),
            default => [],
        })->all();
    }

    /**
     * Reduce a list of responses to the last non-notification response's text.
     *
     * @param array<int, object> $responses
     */
    protected function finalResponse(array $responses): string
    {
        $final = collect($responses)->last(fn (object $response): bool => ! $response->isNotification());

        if ($final === null) {
            return '';
        }

        $text = (string) $final->content();

        return $final->isError()
            ? $this->errorMessage($text)
            : $text;
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
