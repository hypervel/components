<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server;

use Hypervel\Tests\Mcp\Fixtures\MirroredHeaderServer;
use Hypervel\Tests\Mcp\Fixtures\MirroredHeaderTool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Mcp\Fixtures\mcpHeaders;
use function Hypervel\Tests\Mcp\Fixtures\mirroredHeaderToolMessage;

class MirroredParameterHeadersTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     */
    #[DataProvider('matchingHeaders')]
    public function testAcceptsMirroredParameterHeadersThatMatchTheBody(array $arguments, array $headers): void
    {
        $message = mirroredHeaderToolMessage($arguments);

        $response = $this->postJson('test-mcp-mirrored-headers', $message, [...mcpHeaders($message), ...$headers]);

        $response->assertOk()->assertJsonPath('result.content.0.text', "Region: {$arguments['region']}");
    }

    /**
     * Provide mirrored parameter arguments and matching headers.
     *
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function matchingHeaders(): array
    {
        return [
            'plain value' => [['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']],
            'base64 value' => [['region' => 'Hello, 世界'], ['Mcp-Param-Region' => '=?base64?SGVsbG8sIOS4lueVjA==?=']],
            'value without the base64 suffix is literal' => [['region' => '=?base64?SGVsbG8='], ['Mcp-Param-Region' => '=?base64?SGVsbG8=']],
            'lowercase header name and whitespace' => [['region' => 'us-west1'], ['mcp-param-region' => "  us-west1\t"]],
            'integer value' => [['region' => 'us-west1', 'replicas' => 3], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3']],
            'integer with a decimal point' => [['region' => 'us-west1', 'replicas' => 3], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3.0']],
            'integer in scientific notation' => [['region' => 'us-west1', 'replicas' => 3], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3e0']],
            'integer body sent as a whole float' => [['region' => 'us-west1', 'replicas' => 3.0], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3']],
            'maximum safe integer' => [['region' => 'us-west1', 'replicas' => 9007199254740991], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '9007199254740991']],
            'null body value without a header' => [['region' => 'us-west1', 'replicas' => null], ['Mcp-Param-Region' => 'us-west1']],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     */
    #[DataProvider('mismatchedHeaders')]
    public function testRejectsMirroredParameterHeadersThatAreMissingMalformedOrContradictTheBody(array $arguments, array $headers, string $error): void
    {
        $message = mirroredHeaderToolMessage($arguments);

        $response = $this->postJson('test-mcp-mirrored-headers', $message, [...mcpHeaders($message), ...$headers]);

        $response->assertStatus(400);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32020, 'message' => "Header mismatch: {$error}"],
        ], $response->json());
    }

    /**
     * Provide mirrored parameter arguments and invalid headers.
     *
     * @return array<string, array{array<string, mixed>, array<string, string>, string}>
     */
    public static function mismatchedHeaders(): array
    {
        return [
            'missing header' => [['region' => 'us-west1'], [], 'The [Mcp-Param-Region] header is required.'],
            'invalid base64 padding' => [['region' => 'Hello'], ['Mcp-Param-Region' => '=?base64?SGVsbG8?='], 'The [Mcp-Param-Region] header value does not match the request body value at [/region].'],
            'invalid base64 characters' => [['region' => 'Hello'], ['Mcp-Param-Region' => '=?base64?SGVs!!!bG8=?='], 'The [Mcp-Param-Region] header value does not match the request body value at [/region].'],
            'base64 with whitespace' => [['region' => 'Hello'], ['Mcp-Param-Region' => '=?base64?S G V s bG8=?='], 'The [Mcp-Param-Region] header value does not match the request body value at [/region].'],
            'raw non-ascii value' => [['region' => 'Hello, 世界'], ['Mcp-Param-Region' => 'Hello, 世界'], 'The [Mcp-Param-Region] header value contains invalid characters.'],
            'different value' => [['region' => 'us-west1'], ['Mcp-Param-Region' => 'eu-west1'], 'The [Mcp-Param-Region] header value does not match the request body value at [/region].'],
            'different integer' => [['region' => 'us-west1', 'replicas' => 3], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3.5'], 'The [Mcp-Param-Replicas] header value does not match the request body value at [/replicas].'],
            'integer with float-rounding fraction' => [['region' => 'us-west1', 'replicas' => 1], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '1.0000000000000001'], 'The [Mcp-Param-Replicas] header value does not match the request body value at [/replicas].'],
            'fractional value adjacent to maximum safe integer' => [['region' => 'us-west1', 'replicas' => 9007199254740991], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '9007199254740991.0001'], 'The [Mcp-Param-Replicas] header value does not match the request body value at [/replicas].'],
            'non-numeric integer header' => [['region' => 'us-west1', 'replicas' => 3], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => 'three'], 'The [Mcp-Param-Replicas] header value does not match the request body value at [/replicas].'],
            'header without a body value' => [['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => '3'], 'The [Mcp-Param-Replicas] header is present but the request body has no [/replicas] argument.'],
            'body value of the wrong type' => [['region' => 'us-west1', 'replicas' => 'three'], ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Replicas' => 'three'], 'The [/replicas] argument is not a valid [integer].'],
        ];
    }

    public function testAllowsLegacyHttpCallsWithoutMirroredParameterHeaders(): void
    {
        $response = $this->postJson('test-mcp-mirrored-headers', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'mirrored-header-tool',
                'arguments' => ['region' => 'us-west1'],
            ],
        ], ['MCP-Protocol-Version' => '2025-11-25']);

        $response->assertOk()->assertJsonPath('result.content.0.text', 'Region: us-west1');
    }

    public function testDoesNotExpectMirroredParameterHeadersOutsideOfHttp(): void
    {
        MirroredHeaderServer::tool(MirroredHeaderTool::class, ['region' => 'us-west1'])
            ->assertOk()
            ->assertSee('Region: us-west1');
    }
}
