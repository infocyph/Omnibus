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

test('parallel SQLite consumers reserve every message exactly once', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('Parallel SQLite integration requires ext-pcntl.');
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

        foreach ($reports as $worker => $report) {
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

                while ($emptyPasses < 5) {
                    $reservations = [...$workerTransport->receive('parallel', 4, 10)];
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
                file_put_contents($report, json_encode(['handled' => $handled], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $failure) {
                file_put_contents(
                    $report,
                    json_encode(['error' => $failure->getMessage()], JSON_THROW_ON_ERROR),
                );
                exit(1);
            }
        }

        foreach ($children as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $children = [];

        $handled = [];
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
            ->and($verificationTransport->size('parallel'))->toBe(0);

        $verificationConnection->disconnect();
    } finally {
        foreach ($children as $pid) {
            posix_kill($pid, 15);
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
