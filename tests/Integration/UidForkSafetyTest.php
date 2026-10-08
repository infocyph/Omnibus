<?php

declare(strict_types=1);

use Infocyph\UID\ULID;

test('UID 6 resets monotonic state after fork without changing canonical IDs', function (): void {
    $fixed = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $warm = ULID::generateMonotonic($fixed);
    $report = tempnam(sys_get_temp_dir(), 'omnibus-uid-fork-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate UID fork report.');
    }
    unlink($report);

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork UID safety fixture.');
    }
    if ($pid === 0) {
        $childId = ULID::generateMonotonic($fixed);
        file_put_contents($report, $childId, LOCK_EX);
        $self = getmypid();
        if (!is_int($self) || !posix_kill($self, SIGKILL)) {
            throw new RuntimeException('Unable to terminate UID safety child.');
        }
        while (true) {
            usleep(10_000);
        }
    }

    try {
        $parentId = ULID::generateMonotonic($fixed);
        $status = 0;
        expect(pcntl_waitpid($pid, $status))->toBe($pid)
            ->and(pcntl_wifsignaled($status))->toBeTrue()
            ->and(pcntl_wtermsig($status))->toBe(SIGKILL);

        $childId = trim((string) file_get_contents($report));
        expect(ULID::isValid($warm))->toBeTrue()
            ->and(ULID::isValid($parentId))->toBeTrue()
            ->and(ULID::isValid($childId))->toBeTrue()
            ->and(strlen($warm))->toBe(26)
            ->and(strlen($parentId))->toBe(26)
            ->and(strlen($childId))->toBe(26)
            ->and($parentId)->not->toBe($childId);
    } finally {
        if (is_file($report)) {
            unlink($report);
        }
    }
});
