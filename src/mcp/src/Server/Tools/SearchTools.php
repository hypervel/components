<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Tools;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchTools extends Tool
{
    protected string $name = 'search_tools';

    protected string $title = 'Search Tools';

    /**
     * Create the catalog search tool.
     */
    public function __construct(protected ToolSearch $catalog)
    {
    }

    /**
     * Describe tool search.
     */
    public function description(): string
    {
        return 'Search the tools available through execute_tools. Returns exact tool names, descriptions, and complete input schemas. An empty query browses the catalog.';
    }

    /**
     * Define the search arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(4096)->description('Search terms. An empty query browses the catalog.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum results to return. Defaults to 10.'),
        ];
    }

    /**
     * Search the catalog with validated arguments.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $result = $this->catalog->search(
            (string) $request->get('query', ''),
            (int) ($request->get('limit') ?? 10),
        );

        return $this->catalog->response($result, ! $result['ok']);
    }
}
