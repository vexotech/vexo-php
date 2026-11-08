# vexo-php

Send events and identifies to [Vexo](https://vexo.co) from your PHP backend: purchases, subscription changes, background jobs, anything your app's SDK can't see. Server events land in the same app as your mobile and web events, bill as events, and join the person who has the same distinct ID.

- No runtime dependencies: PHP 8.1 or later with the `curl` and `json` extensions.
- Fits PHP's request model: queues messages in memory and sends them in batches at the end of the request, or sooner when the queue fills.
- Retries network errors, `429`, and `5xx` responses (and the messages the API reports as `failed`) with exponential backoff, and honors `Retry-After`.
- Keeps each request under the API's 5 MB body limit, and never holds your process longer than `shutdownTimeout`.

## Install

```bash
composer require vexo/vexo-php
```

## Usage

```php
use Vexo\Vexo;

$vexo = new Vexo(getenv('VEXO_API_KEY'));

// Attach traits to the person. Use the same ID that you pass to identifyDevice() in the app SDK.
$vexo->identify('user@example.com', ['plan' => 'pro', 'company' => 'Acme']);

// Record an event.
$vexo->capture('user@example.com', 'purchase_completed', ['amount' => 49.9, 'currency' => 'USD']);

// Nothing else to do: the client sends what's queued when the script ends.
```

Copy your app's API key from the app's [settings](https://docs.vexo.co/settings/app-settings/app-general-information) in the Vexo dashboard. It's the same key that your app SDK uses, so it isn't a secret; see [Security](https://docs.vexo.co/server-side/capture#security).

Create one client and reuse it, for example as a singleton in your container.

## How the client sends messages

The Node.js and Python SDKs send from a background timer or thread. PHP has neither: PHP-FPM, Apache `mod_php`, and the CLI run each request or script on one thread and throw away its state at the end. So this client sends on your process's time:

| When | What the client sends |
| --- | --- |
| A `capture()` or `identify()` brings the queue to `flushAt` messages | The queue, before the call returns. |
| A `capture()` or `identify()` finds the oldest queued message `flushInterval` seconds old | The queue, before the call returns. There's no timer, so a quiet process doesn't send on the interval. |
| You call `flush()` | The queue, before the call returns. |
| The script or request ends | The queue, from a shutdown function (`register_shutdown_function`). It also runs after a fatal error, such as running out of memory. |
| The client goes out of scope | The queue, from `__destruct()`. |

Each send gives up after `shutdownTimeout` seconds (default 10), including its retries and backoff waits, and reports what it couldn't send to `onError`. When a backoff or `Retry-After` wait would run past that deadline, the client stops retrying at once instead of waiting. In a typical web request, you capture a few events and the client sends one batch at the end.

### Send the response first

Under PHP-FPM, the client's end-of-request send keeps the connection open until it finishes, unless the response has already gone out. Call `fastcgi_finish_request()` to send the response first:

```php
echo $page;

// PHP-FPM: send the response now; the client sends its queue afterward.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
```

Laravel and Symfony already do this when they send a response under PHP-FPM, so you don't need to. Apache `mod_php` has no equivalent.

After the response goes out, the PHP-FPM worker is still busy until the send finishes. When the Vexo API is unreachable, each request spends up to `shutdownTimeout` retrying, and the retry state doesn't carry over to the next request. On a busy server, you can lower that cost:

```php
$vexo = new Vexo(getenv('VEXO_API_KEY'), timeout: 2.0, maxRetries: 1, shutdownTimeout: 3.0);
```

### Long-running workers

A worker that serves many requests or jobs in one process (Laravel Octane, RoadRunner, Swoole, FrankenPHP worker mode, queue workers) keeps the client alive between them. The client still sends when the queue reaches `flushAt` and when the process exits, but a message can wait in the queue until the next `capture()` or `identify()`. Call `flush()` after each request or job:

```php
$vexo->flush();
```

The framework sections below show where to call it.

When you fork worker processes with `pcntl_fork()`, each child starts with an empty queue; the parent sends what it queued before the fork.

## Frameworks

### Laravel

Bind one client in a service provider, and read the key from `config/services.php` (`'vexo' => ['key' => env('VEXO_API_KEY')]`):

```php
// app/Providers/AppServiceProvider.php
use Vexo\Vexo;

public function register(): void
{
    $this->app->singleton(Vexo::class, fn () => new Vexo(config('services.vexo.key')));
}
```

```php
app(Vexo::class)->capture($user->email, 'subscription_renewed', ['plan' => 'pro']);
```

Under PHP-FPM, that's all you need. For queue workers and Octane, flush after each job or request:

```php
// AppServiceProvider::boot()
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Octane\Events\RequestTerminated;

Queue::after(fn () => app(Vexo::class)->flush());
Event::listen(RequestTerminated::class, fn () => app(Vexo::class)->flush());
```

### Symfony

Register the client as a service:

```yaml
# config/services.yaml
services:
    Vexo\Vexo:
        arguments:
            $apiKey: '%env(VEXO_API_KEY)%'
```

Then inject `Vexo\Vexo` where you need it. Under PHP-FPM, that's all you need. With a worker runtime (RoadRunner, FrankenPHP worker mode, Swoole), flush when each request ends; for Messenger workers, listen to `WorkerMessageHandledEvent` the same way:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Vexo\Vexo;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final class FlushVexo
{
    public function __construct(private Vexo $vexo)
    {
    }

    public function __invoke(): void
    {
        $this->vexo->flush();
    }
}
```

### WordPress

Install the package with Composer in your plugin's folder, define `VEXO_API_KEY` in `wp-config.php`, and create the client on first use:

```php
require_once __DIR__ . '/vendor/autoload.php';

function vexo(): Vexo\Vexo
{
    static $vexo;
    return $vexo ??= new Vexo\Vexo(VEXO_API_KEY);
}

add_action('woocommerce_order_status_completed', function (int $order_id): void {
    $order = wc_get_order($order_id);
    vexo()->capture($order->get_billing_email(), 'order_completed', ['total' => (float) $order->get_total()]);
});

// Under PHP-FPM, send the page before the client sends its queue.
add_action('shutdown', function (): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}, PHP_INT_MAX);
```

## Serverless

On AWS Lambda with [Bref](https://bref.sh), and on other platforms that freeze or throttle the process once the response is sent, work after the response might never run. Call `flush()` before you return the response, and don't call `fastcgi_finish_request()` first.

## API

### `new Vexo(string $apiKey, ...options)`

Pass options as named arguments, for example `new Vexo($key, flushAt: 50)`. The constructor throws an `InvalidArgumentException` when the API key is empty.

| Option | Default | Description |
| --- | --- | --- |
| `host` | `https://api.vexo.co` | API base URL. |
| `flushAt` | `100` | Send a batch when this many messages are queued (1 to 500). |
| `flushInterval` | `10.0` | Send the queue when a `capture()` or `identify()` finds the oldest message this many seconds old. `0` turns this off, so messages go out only on `flushAt`, `flush()`, and the end of the script. |
| `maxRetries` | `3` | Retries for network errors, `429`, and `5xx` responses. |
| `maxQueueSize` | `10000` | Messages kept in memory; new messages are dropped once it's full. |
| `timeout` | `10.0` | Request timeout in seconds. |
| `shutdownTimeout` | `10.0` | How long each send, including the one at the end of the script, keeps trying, in seconds. |
| `onError` | Log with `error_log()` | Called with a `Vexo\VexoException` for each send failure and for the messages that Vexo dropped. |

### `$vexo->capture(?string $distinctId, ?string $event, array $properties = [], $timestamp = null, ?string $messageId = null)`

Queues an event. `$timestamp` is a `DateTimeInterface`, epoch milliseconds (not `time()`, which is seconds), or an ISO 8601 string with an offset, and defaults to the time you call `capture()`. A date or a number is sent as integer epoch milliseconds. `$messageId` defaults to a new UUID; pass your own, such as an order ID, to make a repeated capture count once.

### `$vexo->identify(?string $distinctId, array $traits = [])`

Queues an identify. Traits merge into the person's existing traits; a trait set to `null` is removed.

### `$vexo->flush()`

Sends everything queued and returns when it's done, or after `shutdownTimeout` seconds.

### `$vexo->shutdown()`

Sends everything queued, for up to `shutdownTimeout` seconds, and stops accepting messages. The client calls it when the script ends, so you only call it to stop early.

## Errors and dropped messages

The client never throws from `capture()`, `identify()`, `flush()`, or `shutdown()`. Failures go to `onError` as a `Vexo\VexoException` with `status` (the HTTP status, or `null`), `batch` (the messages lost), and `dropped` (a list of `['message' => ..., 'reason' => ...]`):

- A capture without a distinct ID or an event, or an identify without a distinct ID, is dropped when you queue it.
- Values are sent in the form every Vexo SDK uses. Dates (`DateTimeInterface`) are sent as ISO 8601 strings in UTC, such as `2026-11-07T12:00:00.000Z`; a `BcMath\Number` (PHP 8.4) as a plain decimal string with all its digits; enums as their value or name; and other `Stringable` objects as strings. Invalid UTF-8 is replaced with `U+FFFD`, and the message is sent. A message that still can't be encoded as JSON (for example, one with a `NAN` or `INF` value, or an object that refers to itself) or that is over 5 MB is dropped when you queue it; the rest of the queue is unaffected.
- A batch that still fails after the retries, or that the API rejects with a `4xx`, arrives with `status` and `batch`. After that, the next batches get a single attempt until a request succeeds, so an unreachable API doesn't hold up your process.
- A message the API dropped (for example, properties over 64 KB) arrives in `dropped` with the API's reason. Messages the API reports as `failed` are retried, and reported the same way if they still fail.
- Messages still queued when a send reaches `shutdownTimeout` arrive as one error with `batch`.

Every message carries a `messageId`, so a retried batch is stored once.

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests start a mock API with PHP's built-in web server, so they need no network. To run the shared Vexo SDK conformance scenarios, point the harness at the driver in this repo:

```bash
python3 ../vexo-sdk-conformance/run.py --driver "php conformance/driver.php" --skip batch_interval
```

Skip `batch_interval`: it expects a timer to send the queue while the process sleeps, and this client has none.

## License

MIT
