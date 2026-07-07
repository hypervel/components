---
home: true
title: Hypervel
heroImage: icon.svg
actions:
  - text: Get Started
    link: /docs/introduction
    type: primary
  - text: GitHub
    link: https://github.com/hypervel/hypervel
    type: secondary
features:
  - title: Laravel Friendly
    details: By porting Laravel's core infrastructure and fundamental components, this framework enables Laravel developers to quickly adapt to it.
  - title: High Performance
    details: Leveraging Swoole's native coroutine support, it delivers exceptional performance and efficient concurrency handling.
  - title: Ecosystem Compatibility
    details: Hypervel is compatible with the Hyperf ecosystem, sharing the same community resources and packages.
footer: MIT Licensed | Copyright © 2024-present Hypervel
---

## Coding Like in Laravel

Laravel artisans can enjoy a familiar development experience that mirrors the original framework. The simple, elegant syntax remains intact, enabling developers to stay productive while leveraging enhanced performance.

<div class="code-examples-table">
<div class="code-nav">
<ul>
<li class="category-item" data-category="authentication">Authentication</li>
<li class="category-item" data-category="authorization">Authorization</li>
<li class="category-item" data-category="eloquent">Eloquent ORM</li>
<li class="category-item" data-category="migrations">Database Migrations</li>
<li class="category-item" data-category="validation">Validation</li>
<li class="category-item" data-category="notification">Notification and Email</li>
<li class="category-item" data-category="storage">File Storage</li>
<li class="category-item" data-category="queues">Job Queues</li>
<li class="category-item" data-category="scheduling">Task Scheduling</li>
<li class="category-item" data-category="testing">Testing</li>
<li class="category-item" data-category="events">Events</li>
</ul>
</div>

<div class="code-content">
<div class="code-block authentication">

### Authentication

Authenticating users is as simple as adding an authentication middleware to your Hypervel route definition:

```php
Route::get('/profile', ProfileController::class, [
  'middleware' => 'auth'
]);
```

Once the user is authenticated, you can access the authenticated user via the Auth facade:

```php
use Hypervel\Support\Facades\Auth;

$user = Auth::user();
```

Of course, you may define your own authentication middleware, allowing you to customize the authentication process.

[Read Authentication docs](/docs/authentication)

</div>

<div class="code-block authorization">

### Authorization

You'll often need to check whether an authenticated user is authorized to perform a specific action. Hypervel's model policies make it a breeze:

```shell:no-line-numbers
php artisan make:policy UserPolicy
```

Once you've defined your authorization rules in the generated policy class, you can authorize the user's request in your controller methods:

```php
public function update(Request $request, Invoice $invoice)
{
    Gate::authorize('update', $invoice);

    $invoice->update(/* ... */);
}
```

[Read Authorization docs](/docs/authorization)

</div>

<div class="code-block eloquent">

### Eloquent

Scared of databases? Don't be. Hypervel’s Eloquent ORM makes it painless to interact with your application's data, and models, migrations, and relationships can be quickly scaffolded:

```shell:no-line-numbers
php artisan make:model Invoice --migration
```

Once you've defined your model structure and relationships, you can interact with your database using Eloquent's powerful, expressive syntax:

```php
// Create a related model...
$user->invoices()->create(['amount' => 100]);

// Update a model...
$invoice->update(['amount' => 200]);

// Retrieve models...
$invoices = Invoice::unpaid()->where('amount', '>=', 100)->get();

// Rich API for model interactions...
$invoices->each->pay();
```

[Read Eloquent docs](/docs/eloquent)

</div>

<div class="code-block migrations">

### Database Migrations

Migrations are like version control for your database, allowing your team to define and share your application's database schema definition:


```php
return new class extends Migration {
    public function up()
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->uuid()->primary();
            $table->foreignUuid('airline_id')->constrained();
            $table->string('name');
            $table->timestamps();
        });
    }
};
```

[Read Migration docs](/docs/migrations)

</div>

<div class="code-block validation">

### Validation

Laravel has over 90 powerful, built-in validation rules and, using Hypervel Precognition, can provide live validation on your frontend:

```php
public function update(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email|unique:users',
        'password' => Password::required()->min(8),
    ]);

    $request->user()->update($validated);
}
```

[Read Validation docs](/docs/validation)

</div>

<div class="code-block notification">

### Notifications & Mail

Use Hypervel to quickly send beautifully styled notifications to your users via email, Slack, SMS, in-app, and more:

```shell:no-line-numbers
php artisan make:notification InvoicePaid
```

Once you have generated a notification, you can easily send the message to one of your application's users:

```php
$user->notify(new InvoicePaid($invoice));
```

[Read Notification and Mail docs](/docs/notifications)

</div>

<div class="code-block storage">

### File Storage

Hypervel provides a robust filesystem abstraction layer, providing a single, unified API for interacting with local filesystems and cloud based filesystems like Amazon S3:

```php
$path = Storage::disk('s3')
    ->put('avatars/1', $request->file('avatar'));
```

Regardless of where your files are stored, interact with them using Hypervel's simple, elegant syntax:

```php
$content = Storage::get('photo.jpg');

Storage::put('photo.jpg', $content);
```

[Read File Storage docs](/docs/filesystem)

</div>

<div class="code-block queues">

### Job Queues

Hypervel lets you to offload slow jobs to a background queue, keeping your web requests snappy:

```php
$podcast = Podcast::create(/* ... */);

ProcessPodcast::dispatch($podcast)->onQueue('podcasts');
```

You can run as many queue workers as you need to handle your workload:

```shell:no-line-numbers
php artisan queue:work redis --queue=podcasts
```

[Read Queues docs](/docs/queues)

</div>

<div class="code-block scheduling">

### Task Scheduling

Schedule recurring jobs and commands with an expressive syntax and say goodbye to complicated configuration files:

```php
$schedule->job(NotifySubscribers::class)->hourly();
```

Hypervel's scheduler can even handle multiple servers and offers built-in overlap prevention:

```php
$schedule->job(NotifySubscribers::class)
    ->dailyAt('9:00')
    ->onOneServer()
    ->withoutOverlapping();
```

[Read Task Scheduling docs](/docs/scheduling)

</div>

<div class="code-block testing">

### Testing

Hypervel is built for testing. From unit tests to feature tests, you’ll feel more confident in deploying your application:

```php
class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function testCreateUser()
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
```

[Read Testing docs](/docs/testing)

</div>

<div class="code-block events">

### Events & Websockets

Hypervel's events allow you to send and listen for events across your application, and listeners can easily be dispatched to a background queue:

```php
OrderShipped::dispatch($order);
```

```php
class SendShipmentNotification implements ShouldQueue
{
    public function handle(OrderShipped $event): void
    {
        // ...
    }
}
```

Your frontend application can even subscribe to your Hypervel events using [Laravel Echo](/docs/broadcasting) and WebSockets, allowing you to build real-time, dynamic applications:

```js
Echo.private(`orders.${orderId}`)
    .listen('OrderShipped', (e) => {
        console.log(e.order);
    });
```

[Read Events docs](/docs/events)

</div>

</div>
</div>

## Blazing Fast

The concurrency capability of Hypervel is at least ten times better than traditional Laravel Octane and also offers lower response latency, which makes it an excellent choice for building web applications in high concurrency use cases.

> For more detailed benchmark, please see [here](/docs/introduction#benchmark).

<div class="benchmark-tabs">
<div class="benchmark-nav">
<ul>
<li class="benchmark-item active" data-benchmark="simple">Simple API Test</li>
<li class="benchmark-item" data-benchmark="io-wait">Simulated I/O Wait Test</li>
</ul>
</div>

<div class="benchmark-content">
<div class="benchmark-block simple active">

* Laravel Octane (8 workers)
```text:no-line-numbers
Running 10s test @ http://127.0.0.1:8000/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    15.93ms   16.86ms 155.82ms   87.02%
    Req/Sec     2.07k   420.46     3.10k    66.00%
  82661 requests in 10.04s, 16.95MB read
Requests/sec:   8230.97
Transfer/sec:      1.69MB
```

* Hypervel (8 workers)

```text:no-line-numbers
Running 10s test @ http://127.0.0.1:9501/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     7.66ms   17.85ms 249.92ms   90.25%
    Req/Sec    24.42k    10.47k   54.37k    68.53%
  971692 requests in 10.06s, 151.98MB read
Requests/sec:  96562.80
Transfer/sec:     15.10MB
```

::: chart

```json
{
  "type": "bar",
  "data": {
    "labels": ["Simple API Test"],
    "datasets": [
      {
        "label": "Laravel Octane",
        "data": [8230.97],
        "backgroundColor": "rgba(255, 182, 193, 0.8)",
        "borderColor": "rgba(255, 182, 193, 1)",
        "borderWidth": 1
      },
      {
        "label": "Hypervel",
        "data": [96562.80],
        "backgroundColor": "rgba(173, 216, 230, 0.8)",
        "borderColor": "rgba(173, 216, 230, 1)",
        "borderWidth": 1
      }
    ]
  },
  "options": {
    "responsive": true,
    "scales": {
      "x": {
        "stacked": false
      },
      "y": {
        "stacked": false,
        "title": {
          "display": true,
          "text": "QPS"
        }
      }
    },
    "plugins": {
      "title": {
        "display": true,
        "text": "Laravel Octane vs Hypervel - Simple API Test"
      },
      "legend": {
        "position": "top"
      }
    }
  }
}
```
:::

</div>

<div class="benchmark-block io-wait">

* Laravel Octane (8 workers)
```text:no-line-numbers
Running 10s test @ http://127.0.0.1:8000/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.03s   184.92us   1.03s    87.50%
    Req/Sec     1.52      1.29     5.00     54.84%
  80 requests in 10.10s, 16.80KB read
  Socket errors: connect 0, read 0, write 0, timeout 72
Requests/sec:      7.92
Transfer/sec:      1.66KB
```

* Hypervel (8 workers)
```text:no-line-numbers
Running 10s test @ http://10.10.4.12:9501/api
  16 threads and 15000 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.02s    64.72ms   1.87s    93.62%
    Req/Sec     1.16k     1.68k    9.15k    87.59%
  109401 requests in 10.09s, 19.82MB read
Requests/sec:  10842.71
Transfer/sec:      1.96MB
```

::: chart

```json
{
  "type": "bar",
  "data": {
    "labels": ["Simulated I/O Wait Test"],
    "datasets": [
      {
        "label": "Laravel Octane",
        "data": [7.92],
        "backgroundColor": "rgba(255, 182, 193, 0.8)",
        "borderColor": "rgba(255, 182, 193, 1)",
        "borderWidth": 1
      },
      {
        "label": "Hypervel",
        "data": [10842.71],
        "backgroundColor": "rgba(173, 216, 230, 0.8)",
        "borderColor": "rgba(173, 216, 230, 1)",
        "borderWidth": 1
      }
    ]
  },
  "options": {
    "responsive": true,
    "scales": {
      "x": {
        "stacked": false
      },
      "y": {
        "stacked": false,
        "title": {
          "display": true,
          "text": "QPS"
        }
      }
    },
    "plugins": {
      "title": {
        "display": true,
        "text": "Laravel Octane vs Hypervel - Simulated I/O Wait Test"
      },
      "legend": {
        "position": "top"
      }
    }
  }
}
```
:::

</div>

</div>
</div>

## Native Coroutine Support

Unlike other async libraries such as AMPHP or ReactPHP, where developers must rewrite every blocking I/O client in their applications to avoid blocking the event loop, this means you can't utilize the current PHP ecosystem, such as Guzzle, PDO, Redis clients, or other native PHP functions.

> See: [How can I use blocking functions?](https://github.com/reactphp/reactphp/wiki/FAQ#how-can-i-use-blocking-functions) in ReactPHP.

All components in Hypervel support coroutines out of the box. Even better, Hypervel can seamlessly transform PHP's built-in blocking I/O functions into coroutines, thanks to [Swoole's runtime hooks](https://wiki.swoole.com/en/#/runtime?id=runtime).

```php
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Http;

Coroutine::create(function () {
    // It won't block the main process
    $response = Http::get('https://hypervel.org');
    // Even built-in blocking functions will become coroutines
    sleep(1);
    echo $response->body();
});

// This line will be printed first
echo 'Hello world!' . PHP_EOL;
```

> See [Coroutine](/docs/coroutine) for more detailed information.

## Frequently Asked Questions

<div class="custom-container tip">
<p><strong>Is Hypervel compatible with Laravel packages?</strong></p>
<p>No! While Hypervel maintains the similar development experience like Laravel, unfortunately, you can't install Laravel packages on this framework due to the fundamental differences in architecture. Developers need to migrate these Laravel packages on their own. We encourage developers to contribute to Hypervel's ecosystem together.</p>
</div>

<div class="custom-container tip">
<p><strong>How does Hypervel achieve high performance?</strong></p>
<p>Hypervel achieves high performance through Swoole's coroutine system, which enables non-blocking I/O operations and efficient concurrency handling. This allows the framework to handle more concurrent connections with fewer resources compared to traditional PHP-FPM.</p>
</div>

<div class="custom-container tip">
<p><strong>Why not use Octane directly?</strong></p>
<p> Octane accelerates Laravel by maintaining the framework in a persistent application state, resetting request-scoped resources between requests. However, it doesn't introduce non-blocking I/O capabilities to Laravel's architecture. Furthermore, all components in Laravel weren't designed with coroutines in mind. It will cause states bleeding while context switching among coroutines.

For I/O-intensive scenarios, even with Octane's improvements, your application's ability to handle concurrent requests is still limited by the duration of these I/O operations.
</p>
</div>

<div class="custom-container tip">
<p><strong>Should I migrate my current Laravel projects to Hypervel?</strong></p>
<p>If you don't have performance issues in your current projects, I would suggest remaining in Laravel because of the mature community and rich ecosystem. There will be necessary refactorings for the migration process. You can also consider migrating only the APIs and modules with performance issues to Hypervel, and adopt architecture like API Gateway to allow Laravel and Hypervel projects to work simultaneously.</p>
</div>

<script>
export default {
  mounted() {
    // Handle code examples tabs
    const categoryItems = document.querySelectorAll('.category-item');
    const codeBlocks = document.querySelectorAll('.code-block');
    let lastActiveItem = categoryItems[0];

    // Show first code block and category by default
    if (lastActiveItem) {
      lastActiveItem.classList.add('active');
      const firstBlock = codeBlocks[0];
      if (firstBlock) {
        firstBlock.classList.add('active');
      }
    }

    categoryItems.forEach(item => {
      item.addEventListener('click', () => {
        const category = item.getAttribute('data-category');

        // Update code blocks
        codeBlocks.forEach(block => {
          block.classList.remove('active');
        });

        const selectedBlock = document.querySelector(`.${category}`);
        if (selectedBlock) {
          selectedBlock.classList.add('active');
        }

        // Update category items
        if (lastActiveItem) {
          lastActiveItem.classList.remove('active');
        }
        item.classList.add('active');
        lastActiveItem = item;
      });
    });

    // Handle benchmark tabs
    const benchmarkItems = document.querySelectorAll('.benchmark-item');
    const benchmarkBlocks = document.querySelectorAll('.benchmark-block');
    let lastActiveBenchmarkItem = benchmarkItems[0];

    // Show first benchmark block and tab by default
    if (lastActiveBenchmarkItem) {
      lastActiveBenchmarkItem.classList.add('active');
      const firstBenchmarkBlock = benchmarkBlocks[0];
      if (firstBenchmarkBlock) {
        firstBenchmarkBlock.classList.add('active');
      }
    }

    benchmarkItems.forEach(item => {
      item.addEventListener('click', () => {
        const benchmark = item.getAttribute('data-benchmark');

        // Update benchmark blocks
        benchmarkBlocks.forEach(block => {
          block.classList.remove('active');
        });

        const selectedBenchmarkBlock = document.querySelector(`.${benchmark}`);
        if (selectedBenchmarkBlock) {
          selectedBenchmarkBlock.classList.add('active');
        }

        // Update benchmark items
        if (lastActiveBenchmarkItem) {
          lastActiveBenchmarkItem.classList.remove('active');
        }
        item.classList.add('active');
        lastActiveBenchmarkItem = item;
      });
    });
  }
}
</script>
