<?php

// The router script of MockApi: records the request, then answers with the next scripted response.
$dir = sys_get_temp_dir() . '/vexo-mock-' . $_SERVER['SERVER_PORT'];
$raw = file_get_contents('php://input');
file_put_contents("$dir/requests.jsonl", json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders(),
    'raw' => $raw,
    'at' => microtime(true),
]) . "\n", FILE_APPEND);

$responses = json_decode(file_get_contents("$dir/responses.json"), true);
$next = array_shift($responses) ?? [];
file_put_contents("$dir/responses.json", json_encode($responses));

usleep((int) (($next['delay'] ?? 0) * 1_000_000));
http_response_code($next['status'] ?? 200);
header('Content-Type: application/json');
foreach ($next['headers'] ?? [] as $name => $value) {
    header("$name: $value");
}
echo $next['raw'] ?? json_encode($next['body'] ?? [
    'success' => true,
    'code' => 200,
    'message' => null,
    'data' => ['accepted' => count(json_decode($raw, true)['batch'] ?? []), 'dropped' => [], 'failed' => []],
]);
