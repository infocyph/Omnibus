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

final readonly class ContentionMessage
{
    public function __construct(public int $sequence) {}
}

$driver = $argv[1] ?? 'mysql';
$consumers = filter_var($argv[2] ?? 2, FILTER_VALIDATE_INT);
$depth = filter_var($argv[3] ?? 10_000, FILTER_VALIDATE_INT);
if (!in_array($driver, ['mysql', 'pgsql'], true)) {
    throw new InvalidArgumentException('Driver must be mysql or pgsql.');
}
if (!is_int($consumers) || !in_array($consumers, [2, 4, 8], true)) {
    throw new InvalidArgumentException('Consumers must be 2, 4, or 8.');
}
if (!is_int($depth) || $depth < 10_000 || $depth > 1_000_000) {
    throw new InvalidArgumentException('Depth must be between 10000 and 1000000.');
}
if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('The DB contention benchmark requires pcntl.');
}

$database = getenv('IC_SERVICE_DATABASE');
$username = getenv('IC_SERVICE_USERNAME');
$password = getenv('IC_SERVICE_PASSWORD');
if (!is_string($database) || $database === '' || !is_string($username) || $username === '') {
    throw new RuntimeException('Configure IC_SERVICE_DATABASE and IC_SERVICE_USERNAME.');
}
$configuration = [
    'driver' => $driver,
    'host' => '127.0.0.1',
    'port' => $driver === 'mysql' ? 3306 : 5432,
    'database' => $database,
    'username' => $username,
    'password' => is_string($password) ? $password : '',
    'options' => [PDO::ATTR_TIMEOUT => 5],
];
$tables = [
    'queue' => 'omnibus_contention_messages',
    'failures' => 'omnibus_contention_failures',
    'workflows' => 'omnibus_contention_workflows',
    'items' => 'omnibus_contention_items',
];
$serializer = new JsonEnvelopeSerializer(
    new MessageCodecRegistry([
        new CallbackMessageCodec(
            'contention.v1',
            ContentionMessage::class,
            static fn(ContentionMessage $message): array => ['sequence' => $message->sequence],
            static fn(array $data): ContentionMessage => new ContentionMessage(
                (int) ($data['sequence'] ?? -1),
            ),
        ),
    ]),
    new StampCodecRegistry(CoreStampCodecs::all()),
);
$connection = new Connection(ConnectionConfig::fromArray($configuration));
foreach (array_reverse($tables) as $table) {
    $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
}

$reports = [];
$childProcess = false;

try {
    foreach (QueueSchema::statements(
        $driver,
        $tables['queue'],
        $tables['failures'],
        $tables['workflows'],
        $tables['items'],
    ) as $statement) {
        $connection->statement($statement);
    }
    $transport = new DBLayerTransport(
        $connection,
        $serializer,
        new SystemClock(),
        $tables['queue'],
    );
    for ($sequence = 0; $sequence < $depth; $sequence++) {
        $transport->send(new Envelope(new ContentionMessage($sequence)), 'contention');
    }

    $run = bin2hex(random_bytes(8));
    $started = hrtime(true);
    $children = [];
    for ($worker = 0; $worker < $consumers; $worker++) {
        $report = sys_get_temp_dir() . '/omnibus-contention-' . $run . '-' . $worker . '.json';
        $reports[] = $report;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a benchmark consumer.');
        }
        if ($pid > 0) {
            $children[] = $pid;

            continue;
        }
        $childProcess = true;

        try {
            $workerConnection = new Connection(ConnectionConfig::fromArray($configuration));
            $workerTransport = new DBLayerTransport(
                $workerConnection,
                $serializer,
                new SystemClock(),
                $tables['queue'],
            );
            $acknowledged = 0;
            $reservationNanoseconds = 0;
            while (true) {
                $reservationStarted = hrtime(true);
                $reservations = [...$workerTransport->receive('contention', 100, 30)];
                $reservationNanoseconds += hrtime(true) - $reservationStarted;
                if ($reservations === []) {
                    if ($workerTransport->size('contention') === 0) {
                        break;
                    }

                    continue;
                }
                foreach ($reservations as $reservation) {
                    $workerTransport->acknowledge($reservation);
                    $acknowledged++;
                }
            }
            file_put_contents($report, json_encode([
                'acknowledged' => $acknowledged,
                'reservation_nanoseconds' => $reservationNanoseconds,
                'transaction_stats' => $workerConnection->transactionStats(),
            ], JSON_THROW_ON_ERROR));

            return;
        } catch (Throwable $failure) {
            file_put_contents($report, json_encode(['error' => $failure->getMessage()], JSON_THROW_ON_ERROR));

            return;
        }
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException(sprintf('Contention worker %d failed.', $pid));
        }
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    $acknowledged = $reservationNanoseconds = 0;
    $transactionStats = [];
    foreach ($reports as $report) {
        $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new RuntimeException('A contention worker returned an invalid report.');
        }
        $acknowledged += (int) ($decoded['acknowledged'] ?? 0);
        $reservationNanoseconds += (int) ($decoded['reservation_nanoseconds'] ?? 0);
        $transactionStats[] = $decoded['transaction_stats'] ?? [];
    }

    fwrite(STDOUT, json_encode([
        'driver' => $driver,
        'consumers' => $consumers,
        'depth' => $depth,
        'duration_seconds' => $elapsed,
        'messages_per_second' => $acknowledged / $elapsed,
        'reservation_latency_ms_per_worker' => ($reservationNanoseconds / $consumers) / 1_000_000,
        'acknowledged' => $acknowledged,
        'duplicate_or_stale_settlements' => max(0, $acknowledged - $depth),
        'missing_settlements' => max(0, $depth - $acknowledged),
        'transaction_stats' => $transactionStats,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    if (!$childProcess) {
        foreach ($reports as $report) {
            if (is_file($report)) {
                unlink($report);
            }
        }
        foreach (array_reverse($tables) as $table) {
            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
}
