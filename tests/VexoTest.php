<?php

declare(strict_types=1);

namespace Vexo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Vexo\Vexo;
use Vexo\VexoException;

enum Plan
{
    case Pro;
}

enum Currency: string
{
    case Usd = 'USD';
}

final class VexoTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const RETRY_NOW = ['Retry-After' => '0'];

    private ?MockApi $api = null;
    private ?Vexo $vexo = null;
    /** @var list<VexoException> */
    private array $errors = [];

    protected function tearDown(): void
    {
        $this->vexo?->shutdown();
        $this->api?->close();
    }

    public function test_sends_queued_messages_as_one_batch_with_the_api_key(): void
    {
        $vexo = $this->client();
        $vexo->identify('user_1', ['plan' => 'pro']);
        $vexo->capture('user_1', 'purchase', ['amount' => 42], new \DateTimeImmutable('2026-11-07T12:00:00Z'));
        $this->assertSame([], $this->api->requests(), 'capture() and identify() only queue');
        $vexo->shutdown();

        $requests = $this->api->requests();
        $this->assertCount(1, $requests);
        [$request] = $requests;
        $this->assertSame(['POST', '/v1/batch'], [$request['method'], $request['path']]);
        $this->assertSame('test-key', $request['headers']['X-Api-Key']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('vexo-php/' . Vexo::VERSION, $request['headers']['User-Agent']);
        [$identify, $capture] = $request['body']['batch'];
        $this->assertSame(['type', 'distinctId', 'traits', 'messageId'], array_keys($identify));
        $this->assertSame(['identify', 'user_1', ['plan' => 'pro']], [$identify['type'], $identify['distinctId'], $identify['traits']]);
        $this->assertSame(['capture', 'user_1', 'purchase', ['amount' => 42], 1794052800000],
            [$capture['type'], $capture['distinctId'], $capture['event'], $capture['properties'], $capture['timestamp']]);
        $this->assertMatchesRegularExpression(self::UUID, $identify['messageId']);
        $this->assertMatchesRegularExpression(self::UUID, $capture['messageId']);
        $this->assertNotSame($identify['messageId'], $capture['messageId']);
        $this->assertSame([], $this->errors);
    }

    public function test_sends_timestamps_as_integer_epoch_milliseconds_and_your_message_id(): void
    {
        $vexo = $this->client();
        $before = (int) floor(microtime(true) * 1000);
        $vexo->capture('u', 'now');
        $vexo->capture('u', 'int', [], 1794052800123);
        $vexo->capture('u', 'float', [], 1794052800123.9);
        $vexo->capture('u', 'iso', [], '2026-11-07T12:00:00+02:00');
        $vexo->capture('u', 'own_id', [], null, 'order-1234-paid');
        $vexo->flush();

        [$now, $int, $float, $iso, $own] = $this->api->requests()[0]['body']['batch'];
        $this->assertIsInt($now['timestamp']);
        $this->assertGreaterThanOrEqual($before, $now['timestamp']);
        $this->assertSame(1794052800123, $int['timestamp']);
        $this->assertSame(1794052800123, $float['timestamp']);
        $this->assertSame('2026-11-07T12:00:00+02:00', $iso['timestamp']);
        $this->assertSame('order-1234-paid', $own['messageId']);
    }

    public function test_sends_empty_and_list_properties_and_traits_as_json_objects(): void
    {
        $vexo = $this->client();
        $vexo->capture('u', 'none');
        $vexo->identify('u');
        $vexo->capture('u', 'list', ['a', 'b']);
        $vexo->flush();

        $raw = $this->api->requests()[0]['raw'];
        $this->assertStringContainsString('"properties":{}', $raw);
        $this->assertStringContainsString('"traits":{}', $raw);
        $this->assertStringContainsString('"properties":{"0":"a","1":"b"}', $raw);
    }

    public function test_sends_dates_enums_and_stringable_values_as_strings(): void
    {
        $vexo = $this->client();
        $vexo->capture('u', 'typed', [
            'at' => new \DateTimeImmutable('2026-01-02T03:04:05.678+00:00'),
            'nested' => ['when' => new \DateTime('2026-01-02T00:00:00+02:00')],
            'plan' => Plan::Pro,
            'currency' => Currency::Usd,
            'id' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'id-1';
                }
            },
            'name' => "caf\xE9", // invalid UTF-8
        ]);
        $vexo->flush();

        $this->assertSame([
            'at' => '2026-01-02T03:04:05.678+00:00',
            'nested' => ['when' => '2026-01-02T00:00:00.000+02:00'],
            'plan' => 'Pro',
            'currency' => 'USD',
            'id' => 'id-1',
            'name' => "caf\u{FFFD}",
        ], $this->api->requests()[0]['body']['batch'][0]['properties']);
        $this->assertSame([], $this->errors);
    }

    public function test_drops_only_the_messages_that_cannot_be_encoded_including_nan_and_infinity(): void
    {
        $vexo = $this->client();
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $vexo->capture('u', 'ok');
        $vexo->capture('u', 'not_a_number', ['amount' => NAN]);
        $vexo->capture('u', 'infinite', ['nested' => ['amount' => -INF]]);
        $vexo->identify('u', ['score' => NAN]);
        $vexo->capture('u', 'cycle', ['node' => $cycle]);
        $vexo->capture('u', 'ok_too');
        $vexo->flush();

        $this->assertSame(['ok', 'ok_too'], $this->events(0));
        $this->assertCount(4, $this->errors);
        foreach ($this->errors as $error) {
            $this->assertStringContainsString("can't be sent as JSON", $error->getMessage());
        }
    }

    public function test_drops_a_message_larger_than_5_mb(): void
    {
        $vexo = $this->client();
        $vexo->capture('u', 'too_big', ['blob' => str_repeat('x', 5 * 1024 * 1024)]);
        $vexo->capture('u', 'ok');
        $vexo->flush();

        $this->assertSame(['ok'], $this->events(0));
        $this->assertStringContainsString('larger than 5 MB', $this->errors[0]->getMessage());
    }

    public function test_reports_messages_without_ids_and_messages_after_shutdown(): void
    {
        $vexo = $this->client();
        $vexo->capture('', 'a');
        $vexo->capture(null, 'a');
        $vexo->capture('u', null);
        $vexo->identify(null);
        $vexo->shutdown();
        $vexo->capture('u', 'late');
        $vexo->identify('u');

        $this->assertSame([], $this->api->requests());
        $this->assertCount(6, $this->errors);
        $this->assertStringContainsString('shut down', $this->errors[5]->getMessage());
    }

    public function test_requires_an_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Vexo('');
    }

    public function test_flushes_when_the_queue_reaches_flush_at(): void
    {
        $vexo = $this->client(flushAt: 2);
        $vexo->capture('u', 'a');
        $this->assertSame([], $this->api->requests());
        $vexo->capture('u', 'b');

        $this->assertSame(['a', 'b'], $this->events(0));
    }

    public function test_clamps_flush_at_to_500(): void
    {
        $vexo = $this->client(flushAt: 1000);
        for ($i = 0; $i < 499; $i++) {
            $vexo->capture('u', "e$i");
        }
        $this->assertSame([], $this->api->requests());
        $vexo->capture('u', 'e499');

        $this->assertCount(500, $this->api->requests()[0]['body']['batch']);
    }

    public function test_flushes_on_enqueue_once_the_oldest_message_is_flush_interval_old(): void
    {
        $vexo = $this->client(flushInterval: 0.05);
        $vexo->capture('u', 'a');
        usleep(100_000);
        $this->assertSame([], $this->api->requests(), 'no timer: the interval is checked on enqueue');
        $vexo->capture('u', 'b');

        $this->assertSame(['a', 'b'], $this->events(0));
    }

    public function test_flush_interval_0_turns_the_interval_off(): void
    {
        $vexo = $this->client(flushInterval: 0.0);
        $vexo->capture('u', 'a');
        $vexo->capture('u', 'b');
        $this->assertSame([], $this->api->requests(), 'flushInterval 0 sent without flushAt or flush()');
        $vexo->flush();

        $this->assertSame(['a', 'b'], $this->events(0));
    }

    public function test_keeps_each_request_under_5_mb(): void
    {
        $vexo = $this->client();
        $blob = str_repeat('x', 2 * 1024 * 1024);
        for ($i = 0; $i < 3; $i++) {
            $vexo->capture('u', "e$i", ['blob' => $blob]);
        }
        $vexo->flush();

        $requests = $this->api->requests();
        $this->assertSame([2, 1], array_map(static fn (array $r): int => count($r['body']['batch']), $requests));
        foreach ($requests as $request) {
            $this->assertLessThanOrEqual(5 * 1024 * 1024, strlen($request['raw']));
        }
        $this->assertSame([], $this->errors);
    }

    public function test_drops_new_messages_once_the_queue_is_full(): void
    {
        $vexo = $this->client(maxQueueSize: 1);
        $vexo->capture('u', 'a');
        $vexo->capture('u', 'b');
        $vexo->flush();

        $this->assertSame(['a'], $this->events(0));
        $this->assertStringContainsString('queue is full', $this->errors[0]->getMessage());
    }

    public function test_retries_503_and_429_with_the_same_message_ids(): void
    {
        $vexo = $this->client([
            ['status' => 503, 'headers' => self::RETRY_NOW, 'body' => ['success' => false]],
            ['status' => 429, 'headers' => self::RETRY_NOW, 'body' => ['success' => false]],
        ]);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $requests = $this->api->requests();
        $this->assertCount(3, $requests);
        $this->assertSame($requests[0]['raw'], $requests[2]['raw']);
        $this->assertSame([], $this->errors);
    }

    public function test_retries_a_request_that_times_out(): void
    {
        $vexo = $this->client([['delay' => 0.5]], timeout: 0.2);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $requests = $this->api->requests();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]['raw'], $requests[1]['raw']);
        $this->assertSame([], $this->errors);
    }

    public function test_waits_for_retry_after_instead_of_the_backoff(): void
    {
        $vexo = $this->client([['status' => 429, 'headers' => ['Retry-After' => '2'], 'body' => ['success' => false]]]);
        $vexo->capture('u', 'a');
        $vexo->flush();

        [$first, $second] = $this->api->requests();
        $this->assertGreaterThanOrEqual(2.0, $second['at'] - $first['at']);
        $this->assertSame([], $this->errors);
    }

    public function test_caps_retry_after_at_60_seconds_and_backs_off_exponentially_with_jitter(): void
    {
        $retryAfter = new \ReflectionMethod(Vexo::class, 'retryAfter');
        $this->assertSame(60.0, $retryAfter->invoke(null, ' 120 '));
        $this->assertSame(2.0, $retryAfter->invoke(null, '2'));
        $this->assertNull($retryAfter->invoke(null, 'Wed, 21 Oct 2026 07:28:00 GMT'));

        $backoff = new \ReflectionMethod(Vexo::class, 'backoff');
        foreach ([0 => 1.0, 1 => 2.0, 2 => 4.0, 10 => 30.0] as $attempt => $base) {
            for ($i = 0; $i < 50; $i++) {
                $wait = $backoff->invoke(null, $attempt);
                $this->assertGreaterThanOrEqual($base / 2, $wait);
                $this->assertLessThanOrEqual($base, $wait);
            }
        }
    }

    /** @return list<array{int}> */
    public static function otherClientErrors(): array
    {
        return [[400], [401], [406], [413]];
    }

    #[DataProvider('otherClientErrors')]
    public function test_does_not_retry_other_4xx_and_reports_them(int $status): void
    {
        $vexo = $this->client([['status' => $status, 'body' => ['success' => false, 'message' => 'no']]]);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $this->assertCount(1, $this->api->requests());
        $this->assertCount(1, $this->errors);
        $this->assertSame($status, $this->errors[0]->status);
        $this->assertSame('a', $this->errors[0]->batch[0]['event']);
    }

    public function test_gives_up_after_max_retries(): void
    {
        $vexo = $this->client(array_fill(0, 5, ['status' => 500, 'headers' => self::RETRY_NOW]), maxRetries: 2);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $this->assertCount(3, $this->api->requests());
        $this->assertSame(500, $this->errors[0]->status);
        $this->assertCount(1, $this->errors[0]->batch);
    }

    public function test_reports_the_messages_the_api_dropped(): void
    {
        $vexo = $this->client([['body' => self::apiResult(dropped: [['index' => 1, 'reason' => 'event exceeds 255 characters']])]]);
        $vexo->capture('u', 'ok');
        $vexo->capture('u', str_repeat('x', 256));
        $vexo->flush();

        $this->assertCount(1, $this->api->requests(), 'dropped messages are not resent');
        $this->assertSame([[
            'message' => $this->api->requests()[0]['body']['batch'][1],
            'reason' => 'event exceeds 255 characters',
        ]], $this->errors[0]->dropped);
    }

    public function test_sends_again_only_the_messages_the_api_reports_as_failed(): void
    {
        $vexo = $this->client([['body' => self::apiResult(failed: [['index' => 1, 'reason' => 'Storing failed; retry this message']])]]);
        $vexo->capture('a', 'stored');
        $vexo->capture('b', 'failed_once');
        $started = hrtime(true);
        $vexo->flush();

        $requests = $this->api->requests();
        $this->assertCount(2, $requests);
        $this->assertSame([$requests[0]['body']['batch'][1]], $requests[1]['body']['batch']);
        $this->assertLessThan(3.0, (hrtime(true) - $started) / 1e9);
        $this->assertSame([], $this->errors);
    }

    public function test_reports_failed_messages_once_the_retries_are_used_up(): void
    {
        $failed = ['body' => self::apiResult(failed: [['index' => 0, 'reason' => 'Storing failed; retry this message']])];
        $vexo = $this->client([$failed, $failed], maxRetries: 1);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $this->assertCount(2, $this->api->requests());
        $this->assertStringContainsString('could not store', $this->errors[0]->getMessage());
        $this->assertSame('a', $this->errors[0]->dropped[0]['message']['event']);
        $this->assertSame('Storing failed; retry this message', $this->errors[0]->dropped[0]['reason']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function waitsPastTheDeadline(): array
    {
        return [
            '429 with Retry-After: 30' => [['status' => 429, 'headers' => ['Retry-After' => '30'], 'body' => ['success' => false]]],
            '503 with a backoff of 0.5 s or more' => [['status' => 503, 'body' => ['success' => false]]],
        ];
    }

    #[DataProvider('waitsPastTheDeadline')]
    public function test_starts_no_attempt_after_the_deadline(array $response): void
    {
        $vexo = $this->client([$response], shutdownTimeout: 0.3);
        $vexo->capture('u', 'a');
        $started = hrtime(true);
        $vexo->shutdown();

        $this->assertCount(1, $this->api->requests());
        $this->assertLessThan(0.3, (hrtime(true) - $started) / 1e9, 'a wait that reaches the deadline is not started');
        $this->assertCount(1, $this->errors);
        $this->assertSame($response['status'], $this->errors[0]->status);
        $this->assertSame('a', $this->errors[0]->batch[0]['event']);
    }

    public function test_counts_a_2xx_body_that_is_not_the_envelope_as_a_success(): void
    {
        $vexo = $this->client([['raw' => '<html>ok</html>']]);
        $vexo->capture('u', 'a');
        $vexo->flush();

        $this->assertCount(1, $this->api->requests());
        $this->assertSame([], $this->errors);
    }

    public function test_gives_later_batches_one_attempt_once_a_batch_used_up_its_retries(): void
    {
        $vexo = $this->client(array_fill(0, 20, ['status' => 500, 'headers' => self::RETRY_NOW]), flushAt: 1, maxRetries: 2);
        for ($i = 0; $i < 3; $i++) {
            $vexo->capture('u', "e$i");
        }

        // 3 attempts for the first batch, then 1 for each of the others.
        $this->assertCount(5, $this->api->requests());
        $this->assertCount(3, $this->errors);
    }

    public function test_restores_the_retries_after_a_request_succeeds(): void
    {
        $error = ['status' => 500, 'headers' => self::RETRY_NOW];
        $vexo = $this->client([$error, $error, [], $error], flushAt: 1, maxRetries: 1);
        $vexo->capture('u', 'e0'); // 2 attempts, then the circuit opens
        $vexo->capture('u', 'e1'); // 1 attempt, which succeeds and closes it
        $vexo->capture('u', 'e2'); // a 500, then a retry that succeeds

        $events = array_map(static fn (array $r): string => $r['body']['batch'][0]['event'], $this->api->requests());
        $this->assertSame(['e0', 'e0', 'e1', 'e2', 'e2'], $events);
        $this->assertCount(1, $this->errors);
    }

    public function test_flush_gives_up_at_the_deadline_when_the_api_is_unreachable(): void
    {
        $vexo = $this->client(host: 'http://127.0.0.1:' . MockApi::freePort(), maxRetries: 10, timeout: 0.5, shutdownTimeout: 1.0);
        for ($i = 0; $i < 5; $i++) {
            $vexo->capture('u', "e$i");
        }
        $started = hrtime(true);
        $vexo->shutdown();

        $this->assertLessThan(1.5, (hrtime(true) - $started) / 1e9);
        $this->assertSame(5, array_sum(array_map(static fn (VexoException $e): int => count($e->batch), $this->errors)));
    }

    public function test_shutdown_aborts_a_slow_request_at_the_deadline_and_reports_what_is_still_queued(): void
    {
        $vexo = $this->client([['delay' => 5]], shutdownTimeout: 1.0);
        $blob = str_repeat('x', 2 * 1024 * 1024);
        for ($i = 0; $i < 3; $i++) {
            $vexo->capture('u', "e$i", ['blob' => $blob]);
        }
        $started = hrtime(true);
        $vexo->shutdown();

        $this->assertLessThan(1.5, (hrtime(true) - $started) / 1e9);
        $this->assertCount(2, $this->errors);
        $this->assertCount(2, $this->errors[0]->batch);
        $this->assertStringContainsString('deadline', $this->errors[1]->getMessage());
        $this->assertSame('e2', $this->errors[1]->batch[0]['event']);
    }

    public function test_flushes_when_the_client_goes_out_of_scope(): void
    {
        $this->api = new MockApi();
        (static function (string $host): void {
            $vexo = new Vexo('test-key', host: $host, flushInterval: 60.0);
            $vexo->capture('u', 'scoped');
        })($this->api->host);

        $this->assertSame(['scoped'], $this->events(0));
    }

    public function test_flushes_at_the_end_of_the_script_even_after_a_fatal_error(): void
    {
        $this->api = new MockApi();
        // Destructors don't run after a fatal error such as running out of memory; shutdown functions do.
        $output = self::runPhp(sprintf(
            '$vexo = new Vexo\Vexo("test-key", host: %s); $vexo->capture("u", "before_crash"); str_repeat("x", 64 * 1024 * 1024);',
            var_export($this->api->host, true),
        ), '-d', 'memory_limit=32M');

        $this->assertStringContainsString('Allowed memory size', $output['stdout'] . $output['stderr']);
        $this->assertSame(['before_crash'], $this->events(0));
    }

    public function test_the_end_of_the_script_waits_no_longer_than_shutdown_timeout(): void
    {
        $output = self::runPhp(sprintf(
            '$vexo = new Vexo\Vexo("test-key", host: %s, maxRetries: 10, timeout: 0.5, shutdownTimeout: 1.0); $vexo->capture("u", "lost");',
            var_export('http://127.0.0.1:' . MockApi::freePort(), true),
        ));

        $this->assertLessThan(2.5, $output['seconds']);
        $this->assertStringContainsString('vexo: request failed', $output['stderr'], 'the default onError logs with error_log()');
    }

    #[RequiresFunction('pcntl_fork')]
    #[RequiresFunction('posix_kill')]
    public function test_a_forked_child_sends_only_its_own_messages(): void
    {
        $vexo = $this->client();
        $vexo->capture('parent', 'before_fork');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $vexo->capture('child', 'from_child');
            $vexo->flush();
            posix_kill(getmypid(), SIGKILL); // ends the child without running PHPUnit's shutdown
        }
        pcntl_waitpid($pid, $status);
        $vexo->flush();

        $this->assertSame([['from_child'], ['before_fork']], [$this->events(0), $this->events(1)]);
    }

    public function test_an_on_error_callback_that_captures_does_not_flush_reentrantly(): void
    {
        $inside = false;
        $reentered = false;
        $calls = 0;
        $vexo = null;
        $vexo = $this->vexo = new Vexo('test-key', host: 'http://127.0.0.1:' . MockApi::freePort(), flushAt: 1, maxRetries: 0,
            onError: static function () use (&$vexo, &$inside, &$reentered, &$calls): void {
                $reentered = $reentered || $inside;
                if (++$calls <= 5) {
                    $inside = true;
                    $vexo->capture('u', 'error_seen');
                    $inside = false;
                }
            });
        $vexo->capture('u', 'first');

        $this->assertFalse($reentered);
        $this->assertSame(6, $calls);
    }

    private function client(array $responses = [], mixed ...$options): Vexo
    {
        $this->api = new MockApi($responses);
        return $this->vexo = new Vexo('test-key', ...[
            'host' => $this->api->host,
            'flushInterval' => 60.0,
            'onError' => function (VexoException $error): void {
                $this->errors[] = $error;
            },
            ...$options,
        ]);
    }

    /** @return list<string> the events of the i-th request */
    private function events(int $i): array
    {
        return array_column($this->api->requests()[$i]['body']['batch'], 'event');
    }

    private static function apiResult(array $dropped = [], array $failed = []): array
    {
        return ['success' => true, 'code' => 200, 'message' => null, 'data' => ['accepted' => 0, 'dropped' => $dropped, 'failed' => $failed]];
    }

    /** @return array{stdout: string, stderr: string, seconds: float} */
    private static function runPhp(string $code, string ...$iniOptions): array
    {
        $started = hrtime(true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=', ...$iniOptions, '-r', "require $autoload; $code"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'seconds' => (hrtime(true) - $started) / 1e9];
    }
}
