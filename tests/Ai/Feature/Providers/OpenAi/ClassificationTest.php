<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Closure;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Files\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class ClassificationTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testClassificationPostsQuestionsToTheDecisionsEndpointInTheOpenAiWireFormat(): void
    {
        Http::fake(['*' => Http::response($this->fakeOpenAiDecisionsResponse())]);
        Classification::of('I was charged twice for my order.')->questions([
            'is_urgent' => new Boolean('Is this urgent?', ['true' => 'Money was lost.', 'false' => 'A question only.']),
            'department' => new Choice('Which department?', ['billing' => 'Payments and refunds.', 'shipping' => null]),
            'severity' => new Score(['ask' => 'How severe?'], [
                'Cosmetic', ['label' => 'Workaround available', 'description' => 'Usable with extra steps.'], ['impact' => 'total'],
            ]),
        ])->classify(provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.openai.com/v1/decisions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $body === [
                    'model' => 'gpt-6-luna', 'input' => 'I was charged twice for my order.',
                    'questions' => [
                        ['type' => 'predicate', 'name' => 'is_urgent', 'instructions' => "Is this urgent?\n\nTrue: Money was lost.\n\nFalse: A question only."],
                        ['type' => 'choice', 'name' => 'department', 'instructions' => 'Which department?', 'choices' => [
                            ['value' => 'billing', 'description' => 'Payments and refunds.'], ['value' => 'shipping'],
                        ]],
                        ['type' => 'score', 'name' => 'severity', 'instructions' => '{"ask":"How severe?"}', 'levels' => [
                            ['label' => 'Cosmetic'],
                            ['label' => 'Workaround available', 'description' => 'Usable with extra steps.'],
                            ['label' => '{"impact":"total"}'],
                        ]],
                    ],
                ];
        });
    }

    public function testStructuredStateIsSentAsJsonText(): void
    {
        Http::fake(['*' => Http::response($this->fakeOpenAiDecisionsResponse())]);
        Classification::of(['subject' => 'Refund', 'body' => 'Charged twice'])
            ->question('is_urgent', new Boolean('Is this urgent?'))->classify(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === '{"subject":"Refund","body":"Charged twice"}');
    }

    public function testImageAttachmentsAreSentInlineWithTheStateAsAUserMessage(): void
    {
        Http::fake(['*' => Http::response($this->fakeOpenAiDecisionsResponse())]);
        $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');
        $png = file_get_contents(__DIR__ . '/../../../Fixtures/Images/red.png');
        Classification::of('Inspect the product in this photo.', [
            Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg')->withProviderOptions(['custom' => 'value']),
            Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg'),
            UploadedFile::fake()->createWithContent('upload.webp', 'upload-bytes')->mimeType('image/webp'),
            Image::fromBase64(base64_encode($png)),
        ])->question('visible_damage', new Boolean('Does the product have visible damage?'))->classify(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [[
            'role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => 'Inspect the product in this photo.'],
                ['custom' => 'value', 'type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode('base64-bytes')],
                ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)],
                ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,' . base64_encode('upload-bytes')],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode($png)],
            ],
        ]]);
    }

    public function testRemoteAndStoredImagesAreDownloadedAndSentInline(): void
    {
        Http::fake([
            'example.com/*' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/gif']),
            '*' => Http::response($this->fakeOpenAiDecisionsResponse()),
        ]);
        Storage::fake('photos')->put('product.png', 'stored-bytes');
        Classification::of('Inspect this.', [
            'front' => Image::fromUrl('https://example.com/product.gif'),
            'back' => Image::fromStorage('product.png', 'photos'),
        ])->question('visible_damage', new Boolean('Damaged?'))->classify(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
            && array_slice(json_decode($request->body(), true)['input'][0]['content'], 1) === [
                ['type' => 'input_image', 'image_url' => 'data:image/gif;base64,' . base64_encode('remote-bytes')],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode('stored-bytes')],
            ]);
    }

    #[DataProvider('unsupportedAttachments')]
    public function testAttachmentsThatAreNotSupportedImagesAreRejectedBeforeAnyRequest(Closure $attachment, string $message): void
    {
        Http::fake(['example.com/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
        $error = null;

        try {
            Classification::of('Inspect this.', [$attachment()])->question('visible_damage', new Boolean('Damaged?'))->classify(provider: 'openai');
        } catch (InvalidArgumentException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $error);
        $this->assertStringContainsString($message, $error->getMessage());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    /**
     * Supply unsupported classification attachments.
     */
    public static function unsupportedAttachments(): array
    {
        return [
            'provider image' => [fn () => Image::fromId('file_123'), 'OpenAI decisions only accept images with inline content; [Hypervel\Ai\Files\ProviderImage] given.'],
            'pdf upload' => [fn () => UploadedFile::fake()->createWithContent('invoice.pdf', 'pdf-bytes')->mimeType('application/pdf'), '[application/pdf] given.'],
            'heic upload' => [fn () => UploadedFile::fake()->createWithContent('photo.heic', 'heic-bytes')->mimeType('image/heic'), '[image/heic] given.'],
            'heic file' => [fn () => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/red.png', 'image/heic'), '[image/heic] given.'],
            'html served as a remote image' => [fn () => Image::fromUrl('https://example.com/product.png'), '[text/html] given.'],
        ];
    }

    public function testDecisionsAnswersAreMatchedToQuestionsByName(): void
    {
        Http::fake(['*' => Http::response($this->fakeOpenAiDecisionsResponse())]);
        $response = Classification::of('text')->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Department?', ['billing' => null, 'shipping' => null]),
            'severity' => new Score('Severity?', ['Cosmetic issue', 'Has workaround', 'Blocked']),
            'refused' => new Boolean('Refused?'),
        ])->classify(provider: 'openai');

        $this->assertCount(3, $response);
        $this->assertSame(0.92, $response['is_urgent']->probability);
        $this->assertSame('billing', $response['department']->choice);
        $this->assertSame(['billing' => 0.95, 'shipping' => 0.05], $response['department']->probabilities);
        $this->assertSame(0.93, $response['department']->confidence);
        $this->assertSame(1.1, $response['severity']->score);
        $this->assertSame([0 => 0.1, 1 => 0.7, 2 => 0.2], $response['severity']->probabilities);
        $this->assertSame('Has workaround', $response['severity']->label());
        $this->assertSame(0.55, $response['severity']->confidence);
        $this->assertFalse(isset($response['refused']));
        $this->assertSame(120, $response->usage->inputTokens);
        $this->assertSame('openai', $response->meta->provider);
        $this->assertSame('gpt-6-luna', $response->meta->model);
    }

    public function testClassificationUsesTheConfiguredClassificationModel(): void
    {
        config(['ai.providers.openai.models.classification.default' => 'gpt-6-luna-preview']);
        Http::fake(['*' => Http::response($this->fakeOpenAiDecisionsResponse())]);
        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gpt-6-luna-preview');
    }

    public function testAttachmentsFailLoudlyInsteadOfFailingOverToAProviderThatCannotClassifyThem(): void
    {
        config(['ai.providers.typesafe.key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response([], 503)]);
        $error = null;

        try {
            Classification::of('Inspect this.', [Image::fromBase64(base64_encode('photo'), 'image/png')])
                ->question('damaged', new Boolean('Damaged?'))->classify(provider: ['openai', 'typesafe']);
        } catch (LogicException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(LogicException::class, $error);
        $this->assertStringContainsString('Provider [typesafe] does not support classification attachments.', $error->getMessage());
        Http::assertSentCount(1);
    }

    /**
     * Create a decisions response with every supported answer type.
     */
    private function fakeOpenAiDecisionsResponse(): array
    {
        return [
            'model' => 'gpt-6-luna',
            'answers' => [
                ['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.92],
                ['type' => 'choice', 'name' => 'department', 'choice' => 'billing', 'probabilities' => [
                    ['value' => 'billing', 'probability' => 0.95], ['value' => 'shipping', 'probability' => 0.05],
                ], 'confidence' => 0.93],
                ['type' => 'score', 'name' => 'severity', 'score' => 1.1, 'probabilities' => [
                    ['value' => 0, 'label' => 'Cosmetic', 'probability' => 0.1],
                    ['value' => 1, 'label' => 'Workaround available', 'probability' => 0.7],
                    ['value' => 2, 'label' => 'Fully blocked', 'probability' => 0.2],
                ], 'confidence' => 0.55],
                ['type' => 'refusal', 'name' => 'refused'],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 0],
        ];
    }
}
