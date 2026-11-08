<?php

// The vexo-sdk-conformance driver: reads one scenario as JSON on stdin, runs its steps against the SDK in this repo,
// and prints a JSON line for each step and each error. See the harness's README for the protocol.

declare(strict_types=1);

require __DIR__ . '/../src/VexoException.php';
require __DIR__ . '/../src/Vexo.php';

use Vexo\Vexo;
use Vexo\VexoException;

function emit(array $line): void
{
    fwrite(STDOUT, json_encode($line) . "\n");
    fflush(STDOUT);
}

$scenario = json_decode(stream_get_contents(STDIN), true);
$options = [];
foreach (['flushAt' => 'flushAt', 'maxRetries' => 'maxRetries', 'maxQueueSize' => 'maxQueueSize'] as $from => $to) {
    if (isset($scenario['options'][$from])) {
        $options[$to] = $scenario['options'][$from];
    }
}
foreach (['flushIntervalMs' => 'flushInterval', 'timeoutMs' => 'timeout', 'shutdownTimeoutMs' => 'shutdownTimeout'] as $from => $to) {
    if (isset($scenario['options'][$from])) {
        $options[$to] = $scenario['options'][$from] / 1000;
    }
}

$options['host'] = $scenario['host'];
$options['onError'] = static fn (VexoException $error) => emit(['type' => 'error', 'message' => $error->getMessage(), 'status' => $error->status]);
$vexo = new Vexo($scenario['apiKey'], ...$options);

foreach ($scenario['steps'] as $step) {
    $started = hrtime(true);
    switch ($step['op']) {
        case 'capture':
            $properties = $step['properties'] ?? [];
            if (!empty($step['poison'])) {
                // An object that refers to itself: json_encode refuses it. (The SDK replaces invalid UTF-8 rather
                // than refusing it, so that isn't a poison value here.)
                $properties['poison'] = new stdClass();
                $properties['poison']->self = $properties['poison'];
            }
            if (!empty($step['nan'])) {
                $properties['nan'] = NAN;
            }
            if (isset($step['dateTime'])) {
                $properties['dateTime'] = new DateTimeImmutable($step['dateTime']);
            }
            if (isset($step['decimal'])) {
                // PHP before 8.4 has no decimal type: the string itself.
                $properties['decimal'] = class_exists(BcMath\Number::class) ? new BcMath\Number($step['decimal']) : $step['decimal'];
            }
            if (!empty($step['invalidUnicode'])) {
                $properties['invalidUnicode'] = "ok\xFF";
            }
            $timestamp = isset($step['at']) ? new DateTimeImmutable($step['at']) : ($step['timestamp'] ?? null);
            $vexo->capture($step['distinctId'], $step['event'], $properties, $timestamp, $step['messageId'] ?? null);
            break;
        case 'identify':
            $vexo->identify($step['distinctId'], $step['traits'] ?? []);
            break;
        case 'flush':
            $vexo->flush();
            break;
        case 'shutdown':
            $vexo->shutdown();
            break;
        case 'sleep':
            usleep($step['ms'] * 1000);
            break;
        case 'exit':
            posix_kill(getmypid(), 9); // SIGKILL: no exit hooks
    }
    emit(['type' => 'step', 'op' => $step['op'], 'ms' => (int) round((hrtime(true) - $started) / 1e6)]);
}
