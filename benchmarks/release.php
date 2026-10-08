<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ReleaseDurableMessage
{
    public function __construct(public int $sequence) {}
}

/** @return array{status:int,latency:float,body:array<mixed>|null} */
function httpRequest(string $url): array
{
    $started = hrtime(true);
    $body = file_get_contents($url, false, stream_context_create([
        'http' => ['ignore_errors' => true, 'timeout' => 5.0],
    ]));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match) === 1) {
            $status = (int) $match[1];

            break;
        }
    }

    return [
        'status' => $status,
        'latency' => (hrtime(true) - $started) / 1_000_000,
        'body' => is_string($body) ? json_decode($body, true) : null,
    ];
}

/** @return array{attempted:int,successful:int,timeouts:int,latencies:list<float>} */
function httpClient(string $url, int $requests, int $status, array $body): array
{
    $successful = $timeouts = 0;
    $latencies = [];
    for ($i = 0; $i < $requests; $i++) {
        $response = httpRequest($url);
        $latencies[] = $response['latency'];
        if ($response['status'] === $status && $response['body'] === $body) {
            $successful++;
        } elseif ($response['status'] === 0) {
            $timeouts++;
        }
    }

    return [
        'attempted' => $requests,
        'successful' => $successful,
        'timeouts' => $timeouts,
        'latencies' => $latencies,
    ];
}

if (($argv[1] ?? '') === 'http-client') {
    fwrite(STDOUT, json_encode(httpClient(
        $argv[2],
        (int) $argv[3],
        (int) $argv[4],
        json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR),
    ), JSON_THROW_ON_ERROR));

    exit(0);
}

/** @param list<float> $values */
function percentile(array $values, float $fraction): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);

    return $values[max(0, (int) ceil(count($values) * $fraction) - 1)];
}

/** @param list<float> $latencies @return array<string,mixed> */
function workload(
    string $name,
    string $type,
    int $concurrency,
    int $attempted,
    int $successful,
    int $timeouts,
    float $seconds,
    array $latencies,
    array $metadata = [],
    int $warmup = 0,
): array {
    $failed = $attempted - $successful;
    $average = $latencies === [] ? null : array_sum($latencies) / count($latencies);

    return [
        'name' => $name,
        'type' => $type,
        'metadata' => ['source_revision' => getenv('OMNIBUS_BENCHMARK_REVISION') ?: 'unlabeled'] + $metadata,
        'repetitions' => max(1, $attempted),
        'warmup_operations' => $warmup,
        'duration_seconds' => $seconds,
        'concurrency' => $concurrency,
        'result' => [
            'attempted_operations' => $attempted,
            'successful_operations' => $successful,
            'failed_operations' => $failed,
            'timeouts' => $timeouts,
            'successful_rpm' => $seconds > 0.0 ? $successful / $seconds * 60 : 0.0,
            'error_rate' => $attempted > 0 ? $failed / $attempted : 0.0,
            'latency_ms' => [
                'minimum' => $latencies === [] ? null : min($latencies),
                'average' => $average,
                'p50' => percentile($latencies, 0.50),
                'p95' => percentile($latencies, 0.95),
                'p99' => percentile($latencies, 0.99),
                'maximum' => $latencies === [] ? null : max($latencies),
            ],
            'cpu' => ['average_percent' => null, 'peak_percent' => null],
            'memory' => [
                'average_mb' => null,
                'peak_mb' => null,
                'growth_mb' => null,
            ],
            'stability' => ['status' => 'unverified', 'spread_percent' => 0.0],
        ],
    ];
}

/** @return array{resource:resource,port:int} */
function reservePort(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    if (!is_resource($socket)) {
        throw new RuntimeException("Unable to reserve benchmark port: $error ($code).");
    }
    $name = stream_socket_get_name($socket, false);
    if (!is_string($name) || preg_match('/:(\d+)$/', $name, $match) !== 1) {
        throw new RuntimeException('Unable to resolve benchmark port.');
    }

    return ['resource' => $socket, 'port' => (int) $match[1]];
}

/** @return array<string,mixed> */
function concurrentHttp(string $name, string $url, int $concurrency, int $status, array $body): array
{
    $processes = [];
    $started = hrtime(true);
    for ($i = 0; $i < $concurrency; $i++) {
        $process = proc_open([
            PHP_BINARY, __FILE__, 'http-client', $url, (string) (getenv('OMNIBUS_BENCHMARK_REQUESTS_PER_CLIENT') ?: '100'), (string) $status,
            json_encode($body, JSON_THROW_ON_ERROR),
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start benchmark client.');
        }
        $processes[] = [$process, $pipes];
    }

    $attempted = $successful = $timeouts = 0;
    $latencies = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new RuntimeException('Benchmark client failed: ' . trim((string) $error));
        }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $attempted += $result['attempted'];
        $successful += $result['successful'];
        $timeouts += $result['timeouts'];
        array_push($latencies, ...$result['latencies']);
    }
    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    return workload($name, 'http', $concurrency, $attempted, $successful, $timeouts, $seconds, $latencies, [
        'expected_http_status' => $status,
        'validated_output' => true,
        'server_implementation' => 'php-cli-built-in-single-worker',
        'server_workers' => (int) (getenv('OMNIBUS_BENCHMARK_HTTP_WORKERS') ?: 1),
        'client_processes' => $concurrency,
        'router_reconstructs_bus_per_request' => true,
    ], 25);
}

/** @param array<mixed> $expected @return array<string,mixed> */
function observedHttp(
    string $name,
    string $url,
    int $concurrency,
    int $status,
    array $expected,
    int $hostPid,
    int $workerCount,
): array {
    $stop = tempnam(sys_get_temp_dir(), 'omnibus-monitor-stop-');
    $report = tempnam(sys_get_temp_dir(), 'omnibus-monitor-data-');
    if (!is_string($stop) || !is_string($report)) {
        throw new RuntimeException('Unable to allocate host metric paths.');
    }
    unlink($stop);
    $monitor = proc_open([
        PHP_BINARY, 'benchmarks/host-process-monitor.php', (string) $hostPid, $stop, $report,
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($monitor)) {
        throw new RuntimeException('Unable to launch host RSS/CPU sampling process.');
    }

    try {
        $workload = concurrentHttp($name, $url, $concurrency, $status, $expected);
    } finally {
        touch($stop);
        $monitorExit = proc_close($monitor);
    }

    try {
        if ($monitorExit !== 0) {
            throw new RuntimeException('Host RSS/CPU sampling failed.');
        }
        $data = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['peak_process_count'] ?? 0) < $workerCount) {
            throw new RuntimeException('Host workers were not all observed in the process tree.');
        }
        $success = $workload['result']['successful_operations'];
        $workload['metadata']['host_processes_peak'] = $data['peak_process_count'];
        $workload['metadata']['host_cpu_seconds_per_success'] = $success > 0 ? $data['cpu_seconds'] / $success : null;
        $workload['metadata']['host_sampling_interval_ms'] = $data['sampling_interval_ms'];
        $workload['result']['cpu'] = [
            'average_percent' => $data['cpu_seconds'] / $data['elapsed_seconds'] * 100,
            'peak_percent' => null,
            'seconds_per_successful_operation' => $success > 0 ? $data['cpu_seconds'] / $success : null,
        ];
        $workload['result']['memory'] = [
            'average_mb' => $data['average_rss_bytes'] / 1_048_576,
            'peak_mb' => $data['peak_rss_bytes'] / 1_048_576,
            'growth_mb' => $data['rss_growth_bytes'] / 1_048_576,
        ];

        return $workload;
    } finally {
        if (is_file($stop)) {
            unlink($stop);
        }
        if (is_file($report)) {
            unlink($report);
        }
    }
}

/** @return list<array<string,mixed>> */
function httpBaselines(): array
{
    $reservation = reservePort();
    fclose($reservation['resource']);
    $port = $reservation['port'];
    $log = tempnam(sys_get_temp_dir(), 'omnibus-http-');
    if (!is_string($log)) {
        throw new RuntimeException('Unable to allocate benchmark host log.');
    }
    $workerCount = filter_var(getenv('OMNIBUS_BENCHMARK_HTTP_WORKERS') ?: 1, FILTER_VALIDATE_INT);
    if (!is_int($workerCount) || $workerCount < 1 || $workerCount > 16) {
        throw new InvalidArgumentException('HTTP benchmark worker count must be between 1 and 16.');
    }

    // PHP_CLI_SERVER_WORKERS creates actual independent accepting server processes.
    $environment = getenv();
    $environment['PHP_CLI_SERVER_WORKERS'] = (string) $workerCount;
    $process = proc_open([
        PHP_BINARY, '-S', "127.0.0.1:$port", 'benchmarks/release-host.php',
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start benchmark host.');
    }

    try {
        $deadline = microtime(true) + 5.0;
        $ready = false;
        usleep(100_000);
        do {
            $probe = stream_socket_client("tcp://127.0.0.1:$port", $code, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;

                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        if (!$ready) {
            throw new RuntimeException('Benchmark host did not become ready.');
        }

        $base = "http://127.0.0.1:$port";
        $started = hrtime(true);
        $cold = httpClient($base . '/', 1, 200, ['ok' => true, 'value' => 42]);
        $coldSeconds = (hrtime(true) - $started) / 1_000_000_000;
        if ($cold['successful'] !== 1) {
            throw new RuntimeException('Cold benchmark response was invalid.');
        }
        $warmup = httpClient($base . '/', 25, 200, ['ok' => true, 'value' => 42]);
        if ($warmup['successful'] !== 25) {
            throw new RuntimeException('Benchmark warmup response was invalid.');
        }

        $hostStatus = proc_get_status($process);
        $hostPid = $hostStatus['pid'] ?? null;
        if (!is_int($hostPid)) {
            throw new RuntimeException('Unable to resolve benchmark server PID for monitoring.');
        }
        $workloads = [workload('request_host_cold', 'http', 1, 1, 1, 0, $coldSeconds, $cold['latencies'])];
        foreach ([1, 2, 4] as $concurrency) {
            $workloads[] = observedHttp("request_host_warm_c$concurrency", $base . '/', $concurrency, 200, ['ok' => true, 'value' => 42], $hostPid, $workerCount);
        }
        $workloads[] = observedHttp('request_host_expected_failure', $base . '/failure', 1, 503, ['ok' => false, 'error' => 'expected'], $hostPid, $workerCount);

        return $workloads;
    } finally {
        proc_terminate($process);
        proc_close($process);
        if (is_file($log)) {
            unlink($log);
        }
    }
}

/** @return list<array<string,mixed>> */
function durableBaselines(): array
{
    $path = tempnam(sys_get_temp_dir(), 'omnibus-durable-');
    if (!is_string($path)) {
        throw new RuntimeException('Unable to allocate durable benchmark database.');
    }

    try {
        $config = ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => $path]);
        $first = new Connection($config);
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $first->statement($statement);
        }
        $serializer = new JsonEnvelopeSerializer(
            new MessageCodecRegistry([new CallbackMessageCodec(
                'release.v1',
                ReleaseDurableMessage::class,
                static fn(ReleaseDurableMessage $message): array => ['sequence' => $message->sequence],
                static fn(array $data): ReleaseDurableMessage => new ReleaseDurableMessage((int) ($data['sequence'] ?? -1)),
            )]),
            new StampCodecRegistry(CoreStampCodecs::all()),
        );
        $clock = new SystemClock();
        $transports = [
            new DBLayerTransport($first, $serializer, $clock),
            new DBLayerTransport(new Connection($config), $serializer, $clock),
        ];
        for ($i = 0; $i < 2_000; $i++) {
            $transports[0]->send(new Envelope(new ReleaseDurableMessage($i)), 'release');
        }

        $latencies = [];
        $handled = 0;
        $started = hrtime(true);
        while ($handled < 2_000) {
            $transport = $transports[intdiv($handled, 100) % 2];
            $batchStarted = hrtime(true);
            $reservations = [...$transport->receive('release', 100, 30.0)];
            if ($reservations === []) {
                throw new RuntimeException('Durable benchmark stalled.');
            }
            $batchMs = (hrtime(true) - $batchStarted) / 1_000_000;
            foreach ($reservations as $reservation) {
                $transport->acknowledge($reservation);
                $latencies[] = $batchMs / count($reservations);
                $handled++;
            }
        }
        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        if ($transports[0]->size('release') !== 0) {
            throw new RuntimeException('Durable benchmark left queued messages.');
        }

        return [workload('durable_delivery', 'queue-worker', 2, $handled, $handled, 0, $seconds, $latencies, [
            'driver' => 'sqlite',
            'batch_size' => 100,
            'queue_depth_after' => 0,
            'connections' => 2,
            'latency_phase' => 'receive',
        ])];
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function cpuModel(): string
{
    $contents = is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : false;
    if (is_string($contents) && preg_match('/^model name\s*:\s*(.+)$/m', $contents, $match) === 1) {
        return trim($match[1]);
    }

    return php_uname('m');
}

$output = $argv[1] ?? 'build/benchmark-result.json';
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0777, true) && !is_dir(dirname($output))) {
    throw new RuntimeException('Unable to create benchmark output directory.');
}
$document = [
    'schema_version' => 1,
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'environment' => [
        'stable' => getenv('OMNIBUS_BENCHMARK_STABLE') === '1',
        'fingerprint' => hash('sha256', PHP_VERSION . '|' . PHP_OS_FAMILY . '|' . php_uname('r') . '|' . cpuModel()),
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'operating_system' => php_uname('s') . ' ' . php_uname('r'),
        'cpu_model' => cpuModel(),
        'memory_limit' => ini_get('memory_limit') ?: 'unknown',
        'opcache' => extension_loaded('Zend OPcache') ? (string) ini_get('opcache.enable_cli') : false,
        'jit' => (string) (ini_get('opcache.jit') ?: 'off'),
        'xdebug' => extension_loaded('xdebug'),
        'extensions' => get_loaded_extensions(),
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local',
        'runner_environment' => getenv('RUNNER_ENVIRONMENT') ?: 'local',
        'release' => getenv('OMNIBUS_BENCHMARK_RELEASE') ?: 'unlabeled',
        'source_revision' => getenv('OMNIBUS_BENCHMARK_REVISION') ?: 'unlabeled',
        'http_server_implementation' => 'php-cli-built-in-multiworker-when-configured',
        'http_server_workers' => (int) (getenv('OMNIBUS_BENCHMARK_HTTP_WORKERS') ?: 1),
    ],
    'workloads' => [...httpBaselines(), ...durableBaselines()],
];
file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
fwrite(STDOUT, json_encode([$document], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
