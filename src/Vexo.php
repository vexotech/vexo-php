<?php

declare(strict_types=1);

namespace Vexo;

/**
 * Sends server-side events and identifies to Vexo in batches.
 *
 * PHP has no background thread, so the client queues messages in memory and sends them on your process's time: when
 * flushAt messages are queued, when a capture() or identify() finds the oldest queued message older than
 * flushInterval, when you call flush(), and when the script ends. Each of those flushes gives up after
 * shutdownTimeout seconds. capture() and identify() never throw: failures go to onError.
 */
class Vexo
{
    public const VERSION = '0.1.0';
    public const DEFAULT_HOST = 'https://api.vexo.co';

    // The API rejects a batch of more than 500 messages or a body over 5 MB.
    private const MAX_BATCH_SIZE = 500;
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;
    private const BATCH_ENVELOPE_BYTES = 12; // strlen('{"batch":[]}')
    private const MAX_BACKOFF_SECONDS = 30.0;
    private const MAX_RETRY_AFTER_SECONDS = 60.0;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly string $host;
    private readonly int $flushAt;
    private readonly \Closure $onError;

    /** @var list<string> each queued message, already encoded as JSON */
    private array $queue = [];
    private float $firstQueuedAt = 0.0;
    private bool $closed = false;
    private bool $flushing = false;
    // Set by a batch that used up its retries: later batches get one attempt until a request succeeds.
    private bool $circuitOpen = false;
    private int|false $pid;

    /**
     * @param string $apiKey your app's API key
     * @param string $host API base URL
     * @param int $flushAt send a batch when this many messages are queued (1 to 500)
     * @param float $flushInterval send what is queued once the oldest message is this many seconds old; 0 turns it off
     * @param int $maxRetries retries for network errors, 429, and 5xx responses
     * @param int $maxQueueSize messages kept in memory; new messages are dropped once it's full
     * @param float $timeout request timeout in seconds
     * @param float $shutdownTimeout how long a flush, including the one at shutdown, keeps sending, in seconds
     * @param ?callable(VexoException): void $onError called with send failures and the messages that Vexo dropped
     */
    public function __construct(
        private readonly string $apiKey,
        string $host = self::DEFAULT_HOST,
        int $flushAt = 100,
        private readonly float $flushInterval = 10.0,
        private readonly int $maxRetries = 3,
        private readonly int $maxQueueSize = 10000,
        private readonly float $timeout = 10.0,
        private readonly float $shutdownTimeout = 10.0,
        ?callable $onError = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('vexo: an API key is required');
        }
        $this->host = rtrim($host, '/');
        $this->flushAt = max(1, min($flushAt, self::MAX_BATCH_SIZE));
        $this->onError = $onError === null
            ? static fn (VexoException $error) => error_log($error->getMessage())
            : \Closure::fromCallable($onError);
        $this->pid = getmypid();

        // A weak reference, so a client that goes out of scope is freed (and flushed by __destruct) instead of living
        // until the process exits. The shutdown function still runs after a fatal error, when destructors don't.
        $client = \WeakReference::create($this);
        register_shutdown_function(static function () use ($client): void {
            $client->get()?->shutdown();
        });
    }

    /**
     * Queues an event.
     *
     * @param array<array-key, mixed> $properties
     * @param \DateTimeInterface|int|float|string|null $timestamp a date, epoch milliseconds, or an ISO 8601 string
     *     with an offset; defaults to now
     * @param ?string $messageId a unique ID that makes retries safe; defaults to a new UUID
     */
    public function capture(
        ?string $distinctId,
        ?string $event,
        array $properties = [],
        \DateTimeInterface|int|float|string|null $timestamp = null,
        ?string $messageId = null,
    ): void {
        if ((string) $distinctId === '' || (string) $event === '') {
            $this->error(new VexoException('vexo: capture needs a distinctId and an event'));
            return;
        }
        $this->enqueue(static fn (): array => [
            'type' => 'capture',
            'distinctId' => $distinctId,
            'event' => $event,
            'properties' => self::jsonObject($properties),
            'timestamp' => self::epochMillis($timestamp),
            'messageId' => $messageId ?: self::uuid(),
        ]);
    }

    /**
     * Queues an identify: merges the traits into the person with this distinct ID. A trait set to null is removed.
     *
     * @param array<array-key, mixed> $traits
     */
    public function identify(?string $distinctId, array $traits = []): void
    {
        if ((string) $distinctId === '') {
            $this->error(new VexoException('vexo: identify needs a distinctId'));
            return;
        }
        $this->enqueue(static fn (): array => [
            'type' => 'identify',
            'distinctId' => $distinctId,
            'traits' => self::jsonObject($traits),
            'messageId' => self::uuid(),
        ]);
    }

    /**
     * Sends everything queued, for up to shutdownTimeout seconds; what is still queued then is dropped and reported to
     * onError. Call it after each job or request in a long-running worker.
     */
    public function flush(): void
    {
        $this->forgetParentQueue();
        // A flush further up the stack (an onError callback, a signal handler, another coroutine) sends what's queued.
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            $this->drain(self::now() + $this->shutdownTimeout);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Sends everything queued, for up to shutdownTimeout seconds, and stops accepting messages. It runs on its own when
     * the script ends.
     */
    public function shutdown(): void
    {
        $this->closed = true;
        $this->flush();
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /** @param \Closure(): array<string, mixed> $message builds the message, so a failure drops only this message */
    private function enqueue(\Closure $message): void
    {
        $this->forgetParentQueue();
        if ($this->closed) {
            $this->error(new VexoException('vexo: the client is shut down; message dropped'));
            return;
        }
        if (count($this->queue) >= $this->maxQueueSize) {
            $this->error(new VexoException('vexo: the queue is full; message dropped'));
            return;
        }
        try {
            $json = json_encode($message(), self::JSON_FLAGS);
        } catch (\Throwable $e) {
            $this->error(new VexoException("vexo: message dropped, it can't be sent as JSON: " . $e->getMessage(), previous: $e));
            return;
        }
        if (strlen($json) + self::BATCH_ENVELOPE_BYTES > self::MAX_BODY_BYTES) {
            $this->error(new VexoException('vexo: message dropped, it is larger than 5 MB'));
            return;
        }
        if ($this->queue === []) {
            $this->firstQueuedAt = self::now();
        }
        $this->queue[] = $json;
        // flushInterval 0 turns the interval off: the queue then goes out on flushAt, flush(), and shutdown only.
        $intervalDue = $this->flushInterval > 0 && self::now() - $this->firstQueuedAt >= $this->flushInterval;
        if (count($this->queue) >= $this->flushAt || $intervalDue) {
            $this->flush();
        }
    }

    private function drain(float $deadline): void
    {
        while ($this->queue !== []) {
            if (self::now() >= $deadline) {
                $dropped = $this->queue;
                $this->queue = [];
                $this->error(new VexoException(
                    sprintf('vexo: flush deadline reached; %d message(s) dropped', count($dropped)),
                    batch: self::decode($dropped),
                ));
                return;
            }
            $this->send($this->nextBatch(), $deadline);
        }
    }

    /** @return list<string> up to flushAt messages that fit in one request body */
    private function nextBatch(): array
    {
        $bytes = self::BATCH_ENVELOPE_BYTES;
        $count = 0;
        foreach ($this->queue as $json) {
            // + $count: the commas between messages.
            if ($count === $this->flushAt || $bytes + strlen($json) + $count > self::MAX_BODY_BYTES) {
                break;
            }
            $bytes += strlen($json);
            $count++;
        }
        return array_splice($this->queue, 0, $count);
    }

    /**
     * POST /v1/batch. Network errors, 429 and 5xx are retried with exponential backoff (or the API's Retry-After);
     * messages the API reports as failed are sent again the same way. Dropped messages are reported, not retried.
     * No attempt starts at or after the deadline: a wait that would reach it ends the retries at once.
     *
     * @param list<string> $batch
     */
    private function send(array $batch, float $deadline): void
    {
        $maxRetries = $this->circuitOpen ? 0 : $this->maxRetries;
        for ($attempt = 0; ; $attempt++) {
            [$status, $body, $retryAfter] = $this->post('{"batch":[' . implode(',', $batch) . ']}', $deadline);
            $wait = $retryAfter ?? self::backoff($attempt);
            $lastAttempt = $attempt >= $maxRetries || self::now() + $wait >= $deadline;
            if ($status >= 200 && $status < 300) {
                $this->circuitOpen = false;
                // A 2xx body that isn't the API's envelope counts as a success with nothing dropped or failed.
                $result = json_decode($body, true)['data'] ?? [];
                $this->report($batch, $result['dropped'] ?? [], 'the API dropped');
                $failed = array_values(array_filter(array_map(
                    static fn (array $f): ?string => $batch[$f['index']] ?? null,
                    $result['failed'] ?? [],
                )));
                if ($failed === []) {
                    return;
                }
                if ($lastAttempt) {
                    $this->report($batch, $result['failed'], 'the API could not store');
                    return;
                }
                $batch = $failed;
                usleep((int) ($wait * 1_000_000));
                continue;
            }
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable || $lastAttempt) {
                $this->circuitOpen = $this->circuitOpen || $retryable;
                $this->error(new VexoException(
                    $status === 0 ? "vexo: request failed: $body" : "vexo: batch rejected with HTTP $status: $body",
                    $status === 0 ? null : $status,
                    self::decode($batch),
                ));
                return;
            }
            usleep((int) ($wait * 1_000_000));
        }
    }

    /** @return array{int, string, ?float} the HTTP status (0 when the request failed), the body or error, Retry-After */
    private function post(string $body, float $deadline): array
    {
        $retryAfter = null;
        $milliseconds = max(1, (int) (min($this->timeout, $deadline - self::now()) * 1000));
        $curl = curl_init($this->host . '/v1/batch');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $this->apiKey,
                'User-Agent: vexo-php/' . self::VERSION,
                'Expect:', // no 100-continue round trip before a large body
            ],
            CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $milliseconds,
            CURLOPT_NOSIGNAL => true, // lets timeouts under a second work without SIGALRM
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = self::retryAfter(substr($header, 12));
                }
                return strlen($header);
            },
        ]);
        $response = curl_exec($curl);
        return $response === false
            ? [0, curl_error($curl), null]
            : [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response, $retryAfter];
    }

    /**
     * @param list<string> $batch
     * @param list<array{index: int, reason: string}> $results the dropped or failed list of an API response
     */
    private function report(array $batch, array $results, string $what): void
    {
        if ($results === []) {
            return;
        }
        $this->error(new VexoException(
            sprintf('vexo: %s %d message(s): %s', $what, count($results), $results[0]['reason']),
            dropped: array_map(static fn (array $r): array => [
                'message' => isset($batch[$r['index']]) ? json_decode($batch[$r['index']], true) : null,
                'reason' => $r['reason'],
            ], $results),
        ));
    }

    private function error(VexoException $error): void
    {
        ($this->onError)($error);
    }

    // A forked child (pcntl_fork) starts with an empty queue; the parent sends what it queued before the fork.
    private function forgetParentQueue(): void
    {
        if ($this->pid !== getmypid()) {
            $this->pid = getmypid();
            $this->queue = [];
        }
    }

    /**
     * Properties or traits as a JSON object: an empty or list array would encode as a JSON array, which the API drops.
     * Dates, enums, and Stringable objects (BcMath\Number among them) become strings, in the form every Vexo SDK uses
     * (SPEC.md M11): a date as "2026-11-07T12:00:00.000Z", in UTC.
     *
     * @param array<array-key, mixed> $values
     */
    private static function jsonObject(array $values): object
    {
        array_walk_recursive($values, static function (mixed &$value): void {
            $value = match (true) {
                $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \Stringable && !$value instanceof \JsonSerializable => (string) $value,
                default => $value,
            };
        });
        return (object) $values;
    }

    // Integer epoch milliseconds; an ISO 8601 string is sent as is.
    private static function epochMillis(\DateTimeInterface|int|float|string|null $timestamp): int|string
    {
        return match (true) {
            $timestamp === null => (int) floor(microtime(true) * 1000),
            $timestamp instanceof \DateTimeInterface => (int) $timestamp->format('Uv'),
            is_string($timestamp) => $timestamp,
            default => (int) $timestamp,
        };
    }

    // A random (version 4) UUID.
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param list<string> $batch
     * @return list<array<string, mixed>>
     */
    private static function decode(array $batch): array
    {
        return array_map(static fn (string $json): array => json_decode($json, true), $batch);
    }

    // Retry-After in seconds, at most 60; null when it isn't a number of seconds.
    private static function retryAfter(string $value): ?float
    {
        $value = trim($value);
        return is_numeric($value) ? min(max((float) $value, 0.0), self::MAX_RETRY_AFTER_SECONDS) : null;
    }

    // Exponential backoff with jitter: half the base delay plus a random part of the other half.
    private static function backoff(int $attempt): float
    {
        $base = min(2 ** $attempt, self::MAX_BACKOFF_SECONDS);
        return $base / 2 + mt_rand() / mt_getrandmax() * $base / 2;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
