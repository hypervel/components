# Hypervel Scout

Full-text search for Eloquent models using Meilisearch.

## Installation

Scout is included in the Hypervel components package. Register the service provider:

```php
// config/autoload/providers.php
return [
    // ...
    Hypervel\Scout\ScoutServiceProvider::class,
];
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=scout-config
```

## Configuration

```php
// config/scout.php
return [
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),
    'prefix' => env('SCOUT_PREFIX', ''),
    'queue' => [
        'enabled' => env('SCOUT_QUEUE', false),
        'connection' => env('SCOUT_QUEUE_CONNECTION'),
        'queue' => env('SCOUT_QUEUE_NAME'),
        'after_commit' => env('SCOUT_AFTER_COMMIT', false),
    ],
    'soft_delete' => false,
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
    ],
];
```

### Queueing & Transaction Safety

By default, Scout uses `Coroutine::defer()` to index models at coroutine exit (in HTTP requests, typically after the response is emitted). This is fast and works well for most use cases.

For production environments with high reliability requirements, enable queue-based indexing:

```php
'queue' => [
    'enabled' => true,
    'after_commit' => true,  // Recommended when using transactions
],
```

**`after_commit` option:** When enabled, queued indexing jobs are dispatched only after database transactions commit. This prevents indexing data that might be rolled back.

| Mode | When indexing runs | Transaction-aware |
|------|-------------------|-------------------|
| Defer (default) | At coroutine exit (typically after response) | No (timing-based) |
| Queue | Via queue worker | No |
| Queue + after_commit | Via queue worker, after commit | Yes |

Use `after_commit` when your application uses database transactions and you need to ensure search results never contain rolled-back data.

## Basic Usage

Add the `Searchable` trait and implement `SearchableInterface`:

```php
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

class Post extends Model implements SearchableInterface
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
```

### Searching

```php
// Basic search
$posts = Post::search('query')->get();

// With filters
$posts = Post::search('query')
    ->where('status', 'published')
    ->whereIn('category_id', [1, 2, 3])
    ->orderBy('created_at', 'desc')
    ->get();

// Pagination
$posts = Post::search('query')->paginate(15);

// Get raw results
$results = Post::search('query')->raw();
```

### Indexing

Models are automatically indexed when saved and removed when deleted.

```php
// Manually index a model
$post->searchable();

// Remove from index
$post->unsearchable();

// Batch operations
Post::query()->where('published', true)->searchable();
Post::query()->where('archived', true)->unsearchable();
```

### Disabling Sync

Temporarily disable search syncing (coroutine-safe):

```php
Post::withoutSyncingToSearch(function () {
    // Models won't be synced during this callback
    Post::create(['title' => 'Draft']);
});
```

## Artisan Commands

```bash
# Import all models
php artisan scout:import "App\Models\Post"

# Flush index
php artisan scout:flush "App\Models\Post"

# Create index
php artisan scout:index posts

# Delete index
php artisan scout:delete-index posts

# Sync index settings
php artisan scout:sync-index-settings
```

## Engines

### Meilisearch (default)

Production-ready full-text search engine.

```env
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=your-api-key
```

### Collection

In-memory search using database queries. Useful for testing.

```env
SCOUT_DRIVER=collection
```

### Null

Disables search entirely.

```env
SCOUT_DRIVER=null
```

## Soft Deletes

Enable soft delete support in config:

```php
'soft_delete' => true,
```

Then use the query modifiers:

```php
Post::search('query')->withTrashed()->get();
Post::search('query')->onlyTrashed()->get();
```

## Multi-Tenancy

Filter by tenant in your searches:

```php
public function toSearchableArray(): array
{
    return [
        'id' => $this->id,
        'title' => $this->title,
        'tenant_id' => $this->tenant_id,
    ];
}

// Search within tenant
Post::search('query')
    ->where('tenant_id', $tenantId)
    ->get();
```

For frontend-direct search with Meilisearch, generate tenant tokens:

```php
$engine = app(EngineManager::class)->engine('meilisearch');
$token = $engine->generateTenantToken([
    'posts' => ['filter' => "tenant_id = {$tenantId}"]
]);
```

## Index Settings

Configure per-model index settings:

```php
// config/scout.php
'meilisearch' => [
    'index-settings' => [
        Post::class => [
            'filterableAttributes' => ['status', 'category_id', 'tenant_id'],
            'sortableAttributes' => ['created_at', 'title'],
            'searchableAttributes' => ['title', 'body'],
        ],
    ],
],
```

Apply settings:

```bash
php artisan scout:sync-index-settings
```

## Customization

### Custom Index Name

```php
public function searchableAs(): string
{
    return 'posts_index';
}
```

### Custom Scout Key

```php
public function getScoutKey(): mixed
{
    return $this->uuid;
}

public function getScoutKeyName(): string
{
    return 'uuid';
}
```

### Conditional Indexing

```php
public function shouldBeSearchable(): bool
{
    return $this->status === 'published';
}
```

### Transform Before Indexing

```php
public function makeSearchableUsing(Collection $models): Collection
{
    return $models->load('author', 'tags');
}
```
