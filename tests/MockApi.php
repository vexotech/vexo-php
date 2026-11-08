<?php

declare(strict_types=1);

namespace Vexo\Tests;

/**
 * A local Vexo API for the tests: PHP's built-in web server running mock-server.php, which records each request and
 * answers with the next scripted response. The state lives in files, because the built-in server runs each request
 * in a fresh script.
 */
final class MockApi
{
    public readonly string $host;
    private readonly string $dir;
    /** @var resource */
    private $process;

    /**
     * @param list<array{status?: int, headers?: array<string, string>, body?: mixed, raw?: string, delay?: float}> $responses the
     *     responses to the next requests, in order; after them, every request gets a 200 that accepts the batch
     */
    public function __construct(array $responses = [])
    {
        $port = self::freePort();
        $this->dir = sys_get_temp_dir() . '/vexo-mock-' . $port;
        @mkdir($this->dir);
        file_put_contents($this->dir . '/responses.json', json_encode($responses));
        file_put_contents($this->dir . '/requests.jsonl', '');
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'post_max_size=64M', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", __DIR__ . '/mock-server.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->host = "http://127.0.0.1:$port";
        $deadline = microtime(true) + 5;
        while (!$socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The mock API did not start');
            }
            usleep(10_000);
        }
        fclose($socket);
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, raw: string, body: mixed, at: float}> */
    public function requests(): array
    {
        return array_map(static function (string $line): array {
            $request = json_decode($line, true);
            $request['body'] = json_decode($request['raw'], true);
            return $request;
        }, file($this->dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function close(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        array_map('unlink', glob($this->dir . '/*'));
        @rmdir($this->dir);
    }

    // A port that nothing listens on, so connections to it are refused.
    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
