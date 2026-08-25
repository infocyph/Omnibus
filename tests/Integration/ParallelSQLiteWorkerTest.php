<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;

function omnibusParallelSQLiteSerializer(): JsonEnvelopeSerializer
{
    return new JsonEnvelopeSerializer(
        new MessageCodecRegistry([
            new CallbackMessageCodec(
                'parallel.sqlite.command.v1',
                TestCommand::class,
                static fn(TestCommand $message): array => ['value' => $message->value],
                static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
            ),
        ]),
        new StampCodecRegistry(CoreStampCodecs::all()),
    );
}

function omnibusTerminateParallelSQLiteChild(int $signal): never
{
    pcntl_sigprocmask(SIG_UNBLOCK, [$signal]);
    $pid = getmypid();
    if (!is_int($pid) || !posix_kill($pid, $signal)) {
        throw new RuntimeException('Unable to terminate a parallel SQLite test child.');
    }

    while (true) {
        usleep(10_000);
    }
}

test('parallel SQLite consumers reserve every message exactly once', function (): void {
    if (
        !function_exists('pcntl_fork')
        || !function_exists('pcntl_sigprocmask')
        || !function_exists('pcntl_waitpid')
        || !function_exists('posix_kill')
    ) {
        $this->markTestSkipped('Parallel SQLite integration requires ext-pcntl and ext-posix.');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $this->markTestSkipped('Parallel SQLite integration requires pdo_sqlite.');
    }

    $database = tempnam(sys_get_temp_dir(), 'omnibus-parallel-sqlite-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate a temporary SQLite database.');
    }

    $run = bin2hex(random_bytes(8));
    $reports = [
        sys_get_temp_dir() . '/omnibus-parallel-' . $run . '-0.json',
        sys_get_temp_dir() . '/omnibus-parallel-' . $run . '-1.json',
    ];
    $configuration = [
        'driver' => 'sqlite',
        'database' => $database,
        'options' => [PDO::ATTR_TIMEOUT => 5],
    ];
    $children = [];

    try {
        $connection = new Connection(ConnectionConfig::fromArray($configuration));
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $connection->statement($statement);
        }
        $transport = new DBLayerTransport(
            $connection,
            omnibusParallelSQLiteSerializer(),
            new SystemClock(),
        );
        $expected = [];
        for ($index = 0; $index < 40; $index++) {
            $value = 'parallel-' . $index;
            $expected[] = $value;
            $transport->send(new Envelope(new TestCommand($value)), 'parallel');
        }

        $connection->disconnect();
        unset($transport, $connection);

        foreach ($reports as $report) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a parallel SQLite test consumer.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }

            try {
                $workerConnection = new Connection(ConnectionConfig::fromArray($configuration));
                $workerTransport = new DBLayerTransport(
                    $workerConnection,
                    omnibusParallelSQLiteSerializer(),
                    new SystemClock(),
                );
                $handled = [];
                $emptyPasses = 0;
                $contentionFailures = 0;

                while ($emptyPasses < 5) {
                    try {
                        $reservations = [...$workerTransport->receive('parallel', 4, 10)];
                    } catch (TransactionException $failure) {
                        $contentionFailures++;
                        if ($contentionFailures > 100) {
                            throw $failure;
                        }
                        usleep(random_int(1_000, 10_000));

                        continue;
                    }
                    if ($reservations === []) {
                        $emptyPasses++;
                        usleep(10_000);

                        continue;
                    }

                    $emptyPasses = 0;
                    foreach ($reservations as $reservation) {
                        $message = $reservation->envelope()->message;
                        if (!$message instanceof TestCommand) {
                            throw new RuntimeException('Parallel SQLite test decoded an unexpected message.');
                        }
                        $handled[] = $message->value;
                        $workerTransport->acknowledge($reservation);
                    }
                }

                $workerConnection->disconnect();
                file_put_contents($report, json_encode([
                    'handled' => $handled,
                    'contention_failures' => $contentionFailures,
                ], JSON_THROW_ON_ERROR));
                omnibusTerminateParallelSQLiteChild(15);
            } catch (Throwable $failure) {
                file_put_contents(
                    $report,
                    json_encode(['error' => $failure->getMessage()], JSON_THROW_ON_ERROR),
                );
                omnibusTerminateParallelSQLiteChild(9);
            }
        }

        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null;
            $cleanSignal = pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 15;
            if ($exitCode !== 0 && !$cleanSignal) {
                $detail = 'no child report was written';
                $report = $reports[$index] ?? null;
                if (is_string($report) && is_file($report)) {
                    $decoded = json_decode((string) file_get_contents($report), true);
                    if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
                        $detail = $decoded['error'];
                    }
                }

                throw new RuntimeException(sprintf(
                    'Parallel SQLite child %d exited with %s: %s',
                    $pid,
                    $exitCode === null ? 'a signal' : 'code ' . $exitCode,
                    $detail,
                ));
            }
        }
        $children = [];

        $handled = [];
        $contentionFailures = 0;
        foreach ($reports as $report) {
            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || isset($decoded['error']) || !is_array($decoded['handled'] ?? null)) {
                throw new RuntimeException('Parallel SQLite worker returned an invalid report.');
            }
            foreach ($decoded['handled'] as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('Parallel SQLite worker returned an invalid message value.');
                }
                $handled[] = $value;
            }
            $contentionFailures += (int) ($decoded['contention_failures'] ?? 0);
        }

        sort($expected);
        sort($handled);
        $verificationConnection = new Connection(ConnectionConfig::fromArray($configuration));
        $verificationTransport = new DBLayerTransport(
            $verificationConnection,
            omnibusParallelSQLiteSerializer(),
            new SystemClock(),
        );

        expect($handled)->toBe($expected)
            ->and(array_unique($handled))->toHaveCount(count($expected))
            ->and($verificationTransport->size('parallel'))->toBe(0)
            ->and($contentionFailures)->toBeLessThanOrEqual(200);

        $verificationConnection->disconnect();
    } finally {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, 15);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
        }
        foreach ($reports as $report) {
            if (is_file($report)) {
                unlink($report);
            }
        }
        foreach ([$database, $database . '-journal', $database . '-shm', $database . '-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
