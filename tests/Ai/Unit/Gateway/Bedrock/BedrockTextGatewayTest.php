<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\S3Document;
use Hypervel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Testing\File as TestingFile;
use Hypervel\Support\Collection;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

function textGateway(): object
{
    return new class extends BedrockTextGateway {
        /**
         * Map attachments into content blocks.
         */
        public function callMapAttachments(Collection $attachments): array
        {
            return $this->mapAttachments($attachments);
        }

        /**
         * Format conversation messages.
         */
        public function callFormatMessages(array $messages): array
        {
            return $this->formatMessages($messages);
        }

        /**
         * Format tool definitions.
         */
        public function callFormatTools(array $tools): array
        {
            return $this->formatTools($tools);
        }

        /**
         * Build structured-output tool definitions.
         */
        public function callBuildSchemaTools(array $schema, array $tools): array
        {
            return $this->buildSchemaTools($schema, $tools);
        }

        /**
         * Build the step's tool configuration.
         */
        public function callBuildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
        {
            return $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);
        }

        /**
         * Build inference options.
         */
        public function callBuildInferenceConfig(?TextGenerationOptions $options): array
        {
            return $this->buildInferenceConfig($options);
        }

        /**
         * Build an assistant message.
         */
        public function callBuildAssistantConversationMessage(string $text, array $toolCalls): array
        {
            return $this->buildAssistantConversationMessage($text, $toolCalls);
        }

        /**
         * Build a tool result message.
         */
        public function callBuildToolResultConversationMessage(array $toolResults): array
        {
            return $this->buildToolResultConversationMessage($toolResults);
        }

        /**
         * Build the Converse request parameters.
         */
        public function callBuildConverseParameters(
            string $model,
            ?string $instructions,
            array $conversationMessages,
            ?array $schemaTools,
            ?array $formattedTools,
            bool $toolsEmpty,
            ?TextGenerationOptions $options,
            bool $isFinalStep,
        ): array {
            return $this->buildConverseParameters(
                $model,
                $instructions,
                $conversationMessages,
                $schemaTools,
                $formattedTools,
                $toolsEmpty,
                $options,
                $isFinalStep,
            );
        }

        /**
         * Resolve the step limit.
         */
        public function callResolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
        {
            return $this->resolveMaxSteps($tools, $options);
        }

        /**
         * Resolve a document format.
         */
        public function callGetDocumentFormat(Document $document): ?string
        {
            return $this->getDocumentFormat($document);
        }

        /**
         * Format a user message and its attachments.
         */
        public function callFormatUserMessage(UserMessage $message): array
        {
            return $this->formatUserMessage($message);
        }

        /**
         * Resolve an image format.
         */
        public function callGetImageFormat(Image $image): string
        {
            return $this->getImageFormat($image);
        }
    };
}

class BedrockSampleTool implements Tool
{
    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Sample description';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class BedrockTextGatewayTest extends TestCase
{
    public function testUserMessageIsFormattedWithTextBlock(): void
    {
        $formatted = textGateway()->callFormatMessages([
            new UserMessage('hello'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'hello']]],
        ], $formatted);
    }

    #[DataProvider('assistantText')]
    public function testAssistantMessageIsFormattedWithTextBlock(string $text): void
    {
        $formatted = textGateway()->callFormatMessages([
            new AssistantMessage($text),
        ]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => [['text' => $text]]],
        ], $formatted);
    }

    /**
     * Provide ordinary and numeric assistant replies.
     */
    public static function assistantText(): array
    {
        return [['hi there'], ['0']];
    }

    public function testAssistantMessageWithToolCallsIsFormattedWithToolUseBlocks(): void
    {
        $assistant = new AssistantMessage('calling tool', new Collection([
            new ToolCall('tool-1', 'RandomGenerator', ['n' => 5]),
        ]));

        $formatted = textGateway()->callFormatMessages([$assistant]);

        $this->assertSame([
            'role' => 'assistant',
            'content' => [
                ['text' => 'calling tool'],
                ['toolUse' => [
                    'toolUseId' => 'tool-1',
                    'name' => 'RandomGenerator',
                    'input' => ['n' => 5],
                ]],
            ],
        ], $formatted[0]);
    }

    #[DataProvider('objectArguments')]
    public function testAssistantMessageToolInputIsFormattedAsJsonObject(array $arguments, string $json): void
    {
        $assistant = new AssistantMessage('', new Collection([
            new ToolCall('tool-1', 'NoArgGenerator', $arguments),
        ]));

        $formatted = textGateway()->callFormatMessages([$assistant]);
        $input = $formatted[0]['content'][0]['toolUse']['input'];

        $this->assertInstanceOf(stdClass::class, $input);
        $this->assertSame($json, json_encode($input));
    }

    /**
     * Provide argument objects that PHP otherwise encodes as lists.
     */
    public static function objectArguments(): array
    {
        return [
            'empty' => [[], '{}'],
            'numeric names' => [['0' => 'zero', '1' => 'one'], '{"0":"zero","1":"one"}'],
        ];
    }

    public function testAssistantMessageWithReplayBlocksIsFormattedVerbatim(): void
    {
        $message = new AssistantMessage(
            'Hello',
            new Collection([new ToolCall('tool-1', 'doIt', [])]),
            replayBlocks: [
                ['reasoningContent' => ['reasoningText' => ['text' => 'think', 'signature' => 'sig']]],
                ['text' => 'Hello'],
                ['toolUse' => ['toolUseId' => 'tool-1', 'name' => 'doIt', 'input' => []]],
            ],
        );

        $formatted = textGateway()->callFormatMessages([$message]);
        $content = $formatted[0]['content'];

        $this->assertSame('assistant', $formatted[0]['role']);
        $this->assertSame(['reasoningContent' => ['reasoningText' => ['text' => 'think', 'signature' => 'sig']]], $content[0]);
        $this->assertSame(['text' => 'Hello'], $content[1]);
        $this->assertSame('tool-1', $content[2]['toolUse']['toolUseId']);
        $this->assertSame('doIt', $content[2]['toolUse']['name']);
        $this->assertInstanceOf(stdClass::class, $content[2]['toolUse']['input']);
        $this->assertSame('{}', json_encode($content[2]['toolUse']['input']));
    }

    public function testToolResultMessageIsFormattedWithToolResultBlocks(): void
    {
        $message = new ToolResultMessage(new Collection([
            new ToolResult('tool-1', 'RandomGenerator', [], 'the result'),
        ]));

        $formatted = textGateway()->callFormatMessages([$message]);

        $this->assertSame([
            'role' => 'user',
            'content' => [[
                'toolResult' => [
                    'toolUseId' => 'tool-1',
                    'content' => [['text' => 'the result']],
                ],
            ]],
        ], $formatted[0]);
    }

    public function testToolResultNonStringResultsAreJsonEncoded(): void
    {
        $message = new ToolResultMessage(new Collection([
            new ToolResult('tool-1', 'RandomGenerator', [], ['answer' => 42]),
        ]));

        $formatted = textGateway()->callFormatMessages([$message]);

        $this->assertSame('{"answer":42}', $formatted[0]['content'][0]['toolResult']['content'][0]['text']);
    }

    public function testGenericNonAssistantMessageFallsBackToUserRole(): void
    {
        $formatted = textGateway()->callFormatMessages([
            new Message('tool_result', 'tool output'),
        ]);

        $this->assertSame([
            'role' => 'user',
            'content' => [['text' => 'tool output']],
        ], $formatted[0]);
    }

    public function testArrayShapedMessageIsFormatted(): void
    {
        $formatted = textGateway()->callFormatMessages([
            ['role' => 'assistant', 'content' => 'hello'],
        ]);

        $this->assertSame([
            'role' => 'assistant',
            'content' => [['text' => 'hello']],
        ], $formatted[0]);
    }

    public function testUserMessageWithPdfDocumentAttachmentProducesDocumentBlock(): void
    {
        $user = new UserMessage('here', [new Base64Document(base64_encode('pdf-bytes'), 'application/pdf')]);

        $formatted = textGateway()->callFormatUserMessage($user);

        $this->assertSame('user', $formatted['role']);
        $this->assertSame(['text' => 'here'], $formatted['content'][0]);
        $this->assertSame('pdf', $formatted['content'][1]['document']['format']);
        $this->assertSame('document', $formatted['content'][1]['document']['name']);
        $this->assertSame('pdf-bytes', $formatted['content'][1]['document']['source']['bytes']);
    }

    #[DataProvider('documentNames')]
    public function testDocumentNamesAreUniqueAndWithinTheServiceLimit(array $names, array $expected): void
    {
        $documents = array_map(
            static fn (?string $name): Document => $name === null
                ? Document::fromString('document contents', 'text/plain')
                : Document::fromString('document contents', 'text/plain')->as($name),
            $names,
        );
        $blocks = textGateway()->callMapAttachments(new Collection($documents));

        $this->assertSame($expected, array_column(array_column($blocks, 'document'), 'name'));
    }

    /**
     * Provide colliding names and names at the service length limit.
     */
    public static function documentNames(): array
    {
        $longName = str_repeat('a', 200);

        return [
            'unnamed and explicit' => [[null, null, 'document'], ['document', 'document (2)', 'document (3)']],
            'existing suffix' => [['report (2).pdf', 'report.pdf', 'report.docx'], ['report (2)', 'report', 'report (3)']],
            'long names' => [[$longName . 'a.pdf', $longName . 'b.pdf'], [$longName, str_repeat('a', 196) . ' (2)']],
        ];
    }

    public function testS3DocumentAttachmentIsSentAsS3LocationReference(): void
    {
        $document = (new S3Document('s3://my-bucket/path/report.pdf', null, 'application/pdf'))->as('report');
        $user = new UserMessage('summarize this', [$document]);

        $formatted = textGateway()->callFormatUserMessage($user);

        $this->assertSame('pdf', $formatted['content'][1]['document']['format']);
        $this->assertSame('report', $formatted['content'][1]['document']['name']);
        $this->assertSame([
            's3Location' => ['uri' => 's3://my-bucket/path/report.pdf'],
        ], $formatted['content'][1]['document']['source']);
    }

    public function testS3DocumentAttachmentIncludesBucketOwnerWhenSet(): void
    {
        $document = (new S3Document('s3://my-bucket/path/report.pdf', '123456789012', 'application/pdf'))->as('report');
        $user = new UserMessage('summarize this', [$document]);

        $formatted = textGateway()->callFormatUserMessage($user);

        $this->assertSame([
            's3Location' => [
                'uri' => 's3://my-bucket/path/report.pdf',
                'bucketOwner' => '123456789012',
            ],
        ], $formatted['content'][1]['document']['source']);
    }

    public function testS3DocumentCanBeCreatedWithConstructorFromS3Url(): void
    {
        $document = new S3Document('s3://my-bucket/path/report.pdf');

        $this->assertInstanceOf(S3Document::class, $document);
        $this->assertSame('s3://my-bucket/path/report.pdf', $document->url);
    }

    public function testS3DocumentContentThrowsUnsupportedException(): void
    {
        $document = new S3Document('s3://my-bucket/path/report.pdf');

        $this->expectException(InvalidArgumentException::class);
        $document->content();
    }

    public function testDocumentFormatMapsCommonMimeTypes(): void
    {
        $gateway = textGateway();

        $this->assertSame('pdf', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/pdf')));
        $this->assertSame('csv', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/csv')));
        $this->assertSame('doc', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/msword')));
        $this->assertSame('docx', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')));
        $this->assertSame('xls', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.ms-excel')));
        $this->assertSame('xlsx', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')));
        $this->assertSame('html', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/html')));
        $this->assertSame('md', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/markdown')));
        $this->assertSame('md', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/x-markdown')));
        $this->assertSame('txt', $gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/plain; charset=utf-8')));
        $this->assertNull($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'))));
    }

    public function testUserMessageWithBase64ImageAttachmentProducesImageBlock(): void
    {
        $user = new UserMessage('see this', [new Base64Image(base64_encode('image-bytes'), 'image/png')]);

        $formatted = textGateway()->callFormatUserMessage($user);

        $this->assertSame('user', $formatted['role']);
        $this->assertSame(['text' => 'see this'], $formatted['content'][0]);
        $this->assertSame([
            'image' => [
                'format' => 'png',
                'source' => ['bytes' => 'image-bytes'],
            ],
        ], $formatted['content'][1]);
    }

    public function testLocalImageAttachmentIsReadFromDiskIntoBytes(): void
    {
        $directory = ParallelTesting::tempDir('BedrockTextGatewayTest');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);

        try {
            $path = $directory . '/image.jpg';
            file_put_contents($path, 'local-image-bytes');
            $mapped = textGateway()->callMapAttachments(new Collection([
                new LocalImage($path, 'image/jpeg'),
            ]));

            $this->assertSame([
                'image' => [
                    'format' => 'jpeg',
                    'source' => ['bytes' => 'local-image-bytes'],
                ],
            ], $mapped[0]);
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function testRemoteImageAttachmentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote attachments are not supported by Bedrock');
        textGateway()->callMapAttachments(new Collection([
            new RemoteImage('https://example.com/cat.png', 'image/png'),
        ]));
    }

    public function testProviderImageAttachmentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider-stored attachments are not supported by Bedrock');
        textGateway()->callMapAttachments(new Collection([
            new ProviderImage('img_123'),
        ]));
    }

    public function testUploadedFileAttachmentIsRejectedAsUnsupported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported attachment type');
        textGateway()->callMapAttachments(new Collection([
            TestingFile::create('cat.png'),
        ]));
    }

    public function testImageFormatMapsCommonImageMimeTypes(): void
    {
        $gateway = textGateway();

        $this->assertSame('png', $gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/png')));
        $this->assertSame('jpeg', $gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/jpeg')));
        $this->assertSame('jpeg', $gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/jpg')));
        $this->assertSame('gif', $gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/gif')));
        $this->assertSame('webp', $gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/webp')));
    }

    public function testImageFormatThrowsWhenMimeTypeIsUnsupported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type [image/unsupported]');
        textGateway()->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/unsupported'));
    }

    public function testImageFormatThrowsWhenMimeTypeIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine MIME type');
        textGateway()->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), null));
    }

    public function testFormatToolsProducesConverseToolSpecs(): void
    {
        $tools = [new BedrockSampleTool];

        $formatted = textGateway()->callFormatTools($tools);

        $this->assertCount(1, $formatted);
        $this->assertSame('BedrockSampleTool', $formatted[0]['toolSpec']['name']);
        $this->assertSame('Sample description', $formatted[0]['toolSpec']['description']);
        $this->assertIsArray($formatted[0]['toolSpec']['inputSchema']['json']);
    }

    public function testFormatToolsUsesAToolNameMethodWhenPresent(): void
    {
        $formatted = textGateway()->callFormatTools([new NamedTool('aliased_tool')]);

        $this->assertCount(1, $formatted);
        $this->assertSame('aliased_tool', $formatted[0]['toolSpec']['name']);
    }

    public function testFormatToolsIgnoresNonToolValues(): void
    {
        $formatted = textGateway()->callFormatTools([new BedrockSampleTool, 'not-a-tool']);

        $this->assertCount(1, $formatted);
    }

    public function testBuildSchemaToolsPrependsStructuredOutputTool(): void
    {
        $schemaTools = textGateway()->callBuildSchemaTools([], [new BedrockSampleTool]);

        $this->assertCount(2, $schemaTools);
        $this->assertSame('structured_output', $schemaTools[0]['toolSpec']['name']);
        $this->assertIsArray($schemaTools[0]['toolSpec']['inputSchema']['json']);
        $this->assertSame('BedrockSampleTool', $schemaTools[1]['toolSpec']['name']);
    }

    public function testBuildToolConfigReturnsNullWhenNoToolsPresent(): void
    {
        $this->assertNull(textGateway()->callBuildToolConfig(null, null, true, false));
    }

    public function testBuildToolConfigReturnsFormattedToolsWhenNoSchemaPresent(): void
    {
        $formatted = [['toolSpec' => ['name' => 'X']]];

        $this->assertSame(['tools' => $formatted], textGateway()->callBuildToolConfig(null, $formatted, false, false));
    }

    public function testBuildToolConfigUsesAutoToolChoiceOnNonFinalSchemaStep(): void
    {
        $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

        $config = textGateway()->callBuildToolConfig($schemaTools, null, false, false);

        $this->assertSame($schemaTools, $config['tools']);
        $this->assertArrayHasKey('auto', $config['toolChoice']);
    }

    public function testBuildToolConfigForcesStructuredToolOnFinalSchemaStep(): void
    {
        $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

        $config = textGateway()->callBuildToolConfig($schemaTools, null, false, true);

        $this->assertSame(['tool' => ['name' => 'structured_output']], $config['toolChoice']);
    }

    public function testBuildToolConfigForcesStructuredToolWhenNoRealToolsProvided(): void
    {
        $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

        $config = textGateway()->callBuildToolConfig($schemaTools, null, true, false);

        $this->assertSame(['tool' => ['name' => 'structured_output']], $config['toolChoice']);
    }

    public function testBuildInferenceConfigIsEmptyWithoutOptions(): void
    {
        $this->assertSame([], textGateway()->callBuildInferenceConfig(null));
    }

    public function testBuildInferenceConfigMapsMaxTokensAndTemperature(): void
    {
        $options = new TextGenerationOptions(maxTokens: 500, temperature: 0.7);

        $this->assertSame([
            'maxTokens' => 500,
            'temperature' => 0.7,
        ], textGateway()->callBuildInferenceConfig($options));
    }

    public function testBuildInferenceConfigIncludesTemperatureOfZero(): void
    {
        $options = new TextGenerationOptions(temperature: 0.0);

        $this->assertSame([
            'temperature' => 0.0,
        ], textGateway()->callBuildInferenceConfig($options));
    }

    public function testBuildAssistantConversationMessageOmitsTextBlockWhenEmpty(): void
    {
        $message = textGateway()->callBuildAssistantConversationMessage('', [
            new ToolCall('t-1', 'X', []),
        ]);

        $this->assertCount(1, $message['content']);
        $this->assertSame('X', $message['content'][0]['toolUse']['name']);
    }

    public function testBuildAssistantConversationMessageIncludesTextAndToolCalls(): void
    {
        $message = textGateway()->callBuildAssistantConversationMessage('thinking', [
            new ToolCall('t-1', 'X', ['a' => 1]),
        ]);

        $this->assertSame([
            ['text' => 'thinking'],
            ['toolUse' => ['toolUseId' => 't-1', 'name' => 'X', 'input' => ['a' => 1]]],
        ], $message['content']);
    }

    public function testBuildToolResultConversationMessageUsesArrayForm(): void
    {
        $message = textGateway()->callBuildToolResultConversationMessage([
            new ToolResult('t-1', 'X', [], ['out' => true]),
        ]);

        $this->assertSame([
            'role' => 'user',
            'content' => [[
                'toolResult' => [
                    'toolUseId' => 't-1',
                    'content' => [['text' => '{"out":true}']],
                ],
            ]],
        ], $message);
    }

    public function testResolveMaxStepsReturnsOneWhenNoTools(): void
    {
        $this->assertSame(1, textGateway()->callResolveMaxSteps([], null));
    }

    public function testResolveMaxStepsHonorsExplicitOption(): void
    {
        $options = new TextGenerationOptions(maxSteps: 10);

        $this->assertSame(10, textGateway()->callResolveMaxSteps([new BedrockSampleTool], $options));
    }

    public function testResolveMaxStepsFallsBackToToolCountTimesOneAndAHalf(): void
    {
        $tools = [new BedrockSampleTool, new BedrockSampleTool];

        $this->assertSame(3, textGateway()->callResolveMaxSteps($tools, null));
    }

    public function testBuildConverseParametersAttachesSystemInstructions(): void
    {
        $params = textGateway()->callBuildConverseParameters(
            'claude-sonnet',
            'you are helpful',
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            null,
            null,
            true,
            null,
            false,
        );

        $this->assertSame('claude-sonnet', $params['modelId']);
        $this->assertSame([['text' => 'you are helpful']], $params['system']);
        $this->assertSame([['role' => 'user', 'content' => [['text' => 'hi']]]], $params['messages']);
        $this->assertArrayNotHasKey('toolConfig', $params);
        $this->assertArrayNotHasKey('inferenceConfig', $params);
    }

    public function testBuildConverseParametersIncludesToolConfigAndInferenceConfigWhenPresent(): void
    {
        $formattedTools = [['toolSpec' => ['name' => 'X']]];
        $options = new TextGenerationOptions(maxTokens: 100);

        $params = textGateway()->callBuildConverseParameters(
            'claude-sonnet',
            null,
            [],
            null,
            $formattedTools,
            false,
            $options,
            false,
        );

        $this->assertArrayNotHasKey('system', $params);
        $this->assertSame(['tools' => $formattedTools], $params['toolConfig']);
        $this->assertSame(['maxTokens' => 100], $params['inferenceConfig']);
    }

    public function testBuildConverseParametersFlatMergesAgentProviderOptionsForBedrock(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $params = textGateway()->callBuildConverseParameters(
            'claude-sonnet',
            null,
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            null,
            null,
            true,
            $options,
            false,
        );

        $this->assertSame([
            'thinking' => [
                'type' => 'adaptive',
            ],
            'output_config' => [
                'effort' => 'high',
            ],
        ], $params['additionalModelRequestFields']);
        $this->assertSame([
            'guardrailIdentifier' => 'gr-1',
            'guardrailVersion' => '1',
        ], $params['guardrailConfig']);
    }

    public function testBuildConverseParametersOmitsProviderOptionsWhenAgentHasNone(): void
    {
        $params = textGateway()->callBuildConverseParameters(
            'claude-sonnet',
            null,
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            null,
            null,
            true,
            null,
            false,
        );

        $this->assertArrayNotHasKey('additionalModelRequestFields', $params);
        $this->assertArrayNotHasKey('guardrailConfig', $params);
    }
}
