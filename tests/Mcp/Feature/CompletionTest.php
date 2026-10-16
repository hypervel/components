<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\CompletionTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Completions\CompletionHelper;
use Hypervel\Mcp\Server\Completions\CompletionResponse;
use Hypervel\Mcp\Server\Contracts\Completable;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Tests\Mcp\TestCase;

enum TestUnits: string
{
    case Celsius = 'celsius';
    case Fahrenheit = 'fahrenheit';
    case Kelvin = 'kelvin';
}

enum TestStatusEnum
{
    case Active;
    case Inactive;
    case Pending;
}

class TestCompletionServer extends Server
{
    protected string $name = 'Test Completion Server';

    protected array $capabilities = [
        'completions' => [],
    ];

    protected array $prompts = [
        LanguageCompletionPrompt::class,
        ProjectTaskCompletionPrompt::class,
        LocationPrompt::class,
        UnitsPrompt::class,
        StatusPrompt::class,
    ];

    protected array $resources = [
        UserFileCompletionResource::class,
    ];
}

class LanguageCompletionPrompt extends Prompt implements Completable
{
    protected string $description = 'Select a programming language';

    /**
     * Define the language argument.
     */
    public function arguments(): array
    {
        return [
            new Argument('language', 'Programming language', required: true),
        ];
    }

    /**
     * Complete a programming language.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        if ($argument !== 'language') {
            return CompletionResponse::empty();
        }

        $languages = ['php', 'python', 'javascript', 'typescript', 'go', 'rust'];
        $matches = CompletionHelper::filterByPrefix($languages, $value);

        return CompletionResponse::match($matches);
    }

    /**
     * Return the selected language.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Selected language: {$request->get('language')}");
    }
}

class ProjectTaskCompletionPrompt extends Prompt implements Completable
{
    protected string $description = 'Project and task selection';

    /**
     * Define the project and task arguments.
     */
    public function arguments(): array
    {
        return [
            new Argument('projectId', 'Project ID', required: true),
            new Argument('taskId', 'Task ID', required: true),
        ];
    }

    /**
     * Complete a project or task identifier.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return match ($argument) {
            'projectId' => CompletionResponse::match(['project-1', 'project-2', 'project-3']),
            'taskId' => $this->completeTaskId($context),
            default => CompletionResponse::empty(),
        };
    }

    /**
     * Complete tasks within the selected project.
     */
    protected function completeTaskId(array $context): CompletionResponse
    {
        $projectId = $context['projectId'] ?? null;

        if (! $projectId) {
            return CompletionResponse::empty();
        }

        $tasks = [
            'project-1' => ['task-1-1', 'task-1-2'],
            'project-2' => ['task-2-1', 'task-2-2'],
            'project-3' => ['task-3-1', 'task-3-2'],
        ];

        return CompletionResponse::match($tasks[$projectId] ?? []);
    }

    /**
     * Return the selected project and task.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Project: {$request->get('projectId')}, Task: {$request->get('taskId')}");
    }
}

class LocationPrompt extends Prompt implements Completable
{
    protected string $description = 'Select a location';

    /**
     * Define the location argument.
     */
    public function arguments(): array
    {
        return [
            new Argument('location', 'Location name', required: true),
        ];
    }

    /**
     * Complete a location.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return match ($argument) {
            'location' => CompletionResponse::match([
                'New York',
                'Los Angeles',
                'Chicago',
                'Houston',
                'Miami',
            ]),
            default => CompletionResponse::empty(),
        };
    }

    /**
     * Return the selected location.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Selected: {$request->get('location')}");
    }
}

class UnitsPrompt extends Prompt implements Completable
{
    protected string $description = 'Select temperature unit';

    /**
     * Define the temperature unit argument.
     */
    public function arguments(): array
    {
        return [
            new Argument('unit', 'Temperature unit', required: true),
        ];
    }

    /**
     * Complete a temperature unit.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return match ($argument) {
            'unit' => CompletionResponse::match(TestUnits::class),
            default => CompletionResponse::empty(),
        };
    }

    /**
     * Return the selected unit.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Unit: {$request->get('unit')}");
    }
}

class StatusPrompt extends Prompt implements Completable
{
    protected string $description = 'Select status';

    /**
     * Define the status argument.
     */
    public function arguments(): array
    {
        return [
            new Argument('status', 'Status value', required: true),
        ];
    }

    /**
     * Complete a status.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return match ($argument) {
            'status' => CompletionResponse::match(TestStatusEnum::class),
            default => CompletionResponse::empty(),
        };
    }

    /**
     * Return the selected status.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Status: {$request->get('status')}");
    }
}

class UserFileCompletionResource extends Resource implements Completable, HasUriTemplate
{
    protected string $mimeType = 'text/plain';

    protected string $description = 'Access user files';

    /**
     * Get the user file URI template.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://users/{userId}/files/{fileId}');
    }

    /**
     * Complete a user or file identifier.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return match ($argument) {
            'userId' => CompletionResponse::match(['user-1', 'user-2', 'user-3']),
            'fileId' => $this->completeFileId($context),
            default => CompletionResponse::empty(),
        };
    }

    /**
     * Complete files owned by the selected user.
     */
    protected function completeFileId(array $context): CompletionResponse
    {
        $userId = $context['userId'] ?? null;

        if (! $userId) {
            return CompletionResponse::empty();
        }

        $files = [
            'user-1' => ['file1.txt', 'file2.txt'],
            'user-2' => ['doc1.txt', 'doc2.txt'],
            'user-3' => ['report1.txt', 'report2.txt'],
        ];

        return CompletionResponse::match($files[$userId] ?? []);
    }

    /**
     * Return the selected user and file.
     */
    public function handle(Request $request): Response
    {
        return Response::text("User: {$request->get('userId')}, File: {$request->get('fileId')}");
    }
}

class CompletionTest extends TestCase
{
    public function testFiltersByPrefixAndReturnsAllWhenEmpty(): void
    {
        TestCompletionServer::completion(LanguageCompletionPrompt::class, 'language', 'py')
            ->assertHasCompletions(['python'])
            ->assertCompletionCount(1);

        TestCompletionServer::completion(LanguageCompletionPrompt::class, 'language', '')
            ->assertCompletionCount(6);

        TestCompletionServer::completion(LanguageCompletionPrompt::class, 'language', 'xyz')
            ->assertCompletionCount(0);
    }

    public function testRefinesCompletionsAsUserTypes(): void
    {
        TestCompletionServer::completion(LanguageCompletionPrompt::class, 'language', 'j')
            ->assertHasCompletions(['javascript'])
            ->assertCompletionCount(1);

        TestCompletionServer::completion(LanguageCompletionPrompt::class, 'language', 'ja')
            ->assertCompletionValues(['javascript'])
            ->assertCompletionCount(1);
    }

    public function testReturnsAndFiltersLocations(): void
    {
        TestCompletionServer::completion(LocationPrompt::class, 'location', '')
            ->assertCompletionCount(5);

        TestCompletionServer::completion(LocationPrompt::class, 'location', 'New')
            ->assertHasCompletions(['New York'])
            ->assertCompletionCount(1);

        TestCompletionServer::completion(LocationPrompt::class, 'location', 'los')
            ->assertHasCompletions(['Los Angeles'])
            ->assertCompletionCount(1);

        TestCompletionServer::completion(LocationPrompt::class, 'location', 'xyz')
            ->assertCompletionCount(0);
    }

    public function testReturnsBackedEnumValuesWithFiltering(): void
    {
        TestCompletionServer::completion(UnitsPrompt::class, 'unit', '')
            ->assertHasCompletions(['celsius', 'fahrenheit', 'kelvin'])
            ->assertCompletionCount(3);

        TestCompletionServer::completion(UnitsPrompt::class, 'unit', 'kel')
            ->assertHasCompletions(['kelvin'])
            ->assertCompletionCount(1);
    }

    public function testReturnsNonBackedEnumNamesWithFiltering(): void
    {
        TestCompletionServer::completion(StatusPrompt::class, 'status', '')
            ->assertHasCompletions(['Active', 'Inactive', 'Pending'])
            ->assertCompletionCount(3);

        TestCompletionServer::completion(StatusPrompt::class, 'status', 'Pen')
            ->assertHasCompletions(['Pending'])
            ->assertCompletionCount(1);
    }

    public function testCompletesProjectIdAndTaskIdWithContextDependency(): void
    {
        TestCompletionServer::completion(ProjectTaskCompletionPrompt::class, 'projectId', '')
            ->assertHasCompletions(['project-1', 'project-2', 'project-3'])
            ->assertCompletionCount(3);

        TestCompletionServer::completion(ProjectTaskCompletionPrompt::class, 'taskId', '')
            ->assertCompletionCount(0);

        TestCompletionServer::completion(
            ProjectTaskCompletionPrompt::class,
            'taskId',
            '',
            ['projectId' => 'project-1']
        )
            ->assertCompletionValues(['task-1-1', 'task-1-2'])
            ->assertCompletionCount(2);

        TestCompletionServer::completion(
            ProjectTaskCompletionPrompt::class,
            'taskId',
            '',
            ['projectId' => 'project-2']
        )
            ->assertCompletionValues(['task-2-1', 'task-2-2'])
            ->assertCompletionCount(2);
    }

    public function testCompletesUserIdAndFileIdWithContextDependency(): void
    {
        TestCompletionServer::completion(UserFileCompletionResource::class, 'userId')
            ->assertHasCompletions(['user-1', 'user-2', 'user-3'])
            ->assertCompletionCount(3);

        TestCompletionServer::completion(UserFileCompletionResource::class, 'fileId')
            ->assertCompletionCount(0);

        TestCompletionServer::completion(
            UserFileCompletionResource::class,
            'fileId',
            '',
            ['userId' => 'user-1']
        )
            ->assertCompletionValues(['file1.txt', 'file2.txt'])
            ->assertCompletionCount(2);

        TestCompletionServer::completion(
            UserFileCompletionResource::class,
            'fileId',
            '',
            ['userId' => 'user-2']
        )
            ->assertCompletionValues(['doc1.txt', 'doc2.txt'])
            ->assertCompletionCount(2);
    }

    public function testReturnsRawArrayWithoutFiltering(): void
    {
        ResultTestServer::completion(RawArrayPrompt::class, 'item', 'ap')
            ->assertHasCompletions(['apple', 'apricot', 'banana'])
            ->assertCompletionCount(3);

        ResultTestServer::completion(RawArrayPrompt::class, 'item', 'xyz')
            ->assertHasCompletions(['apple', 'apricot', 'banana'])
            ->assertCompletionCount(3);
    }
}

class RawArrayPrompt extends Prompt implements Completable
{
    protected string $description = 'Raw array completion without filtering';

    /**
     * Define the item argument.
     */
    public function arguments(): array
    {
        return [
            new Argument('item', 'Item', required: true),
        ];
    }

    /**
     * Return unfiltered item completions.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        if ($argument !== 'item') {
            return CompletionResponse::empty();
        }

        return CompletionResponse::result(['apple', 'apricot', 'banana']);
    }

    /**
     * Return the selected item.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Item: {$request->get('item')}");
    }
}

class ResultTestServer extends Server
{
    protected string $name = 'Result Test Server';

    protected array $capabilities = [
        'completions' => [],
    ];

    protected array $prompts = [
        RawArrayPrompt::class,
    ];
}
