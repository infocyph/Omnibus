<?php

declare(strict_types=1);

if (getenv('GITHUB_ACTIONS') !== 'true') {
    return;
}

test('portable core executes without PCNTL or POSIX in a real PHP runtime', function (): void {
    $root = dirname(__DIR__, 2);
    $mount = $root . ':/workspace:ro';
    $script = <<<'SH'
set -eu
apk add --no-cache php84 php84-ctype >/dev/null
php84 -r '
foreach (["pcntl", "posix"] as $extension) {
    if (extension_loaded($extension)) {
        fwrite(STDERR, $extension . " unexpectedly loaded\n");
        exit(1);
    }
}
'
php84 tests/Package/PortableCoreProbe.php --source
SH;

    $process = proc_open(
        ['docker', 'run', '--rm', '-v', $mount, '-w', '/workspace', 'alpine:3.22', 'sh', '-lc', $script],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the portable-core container probe.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, $stderr)
        ->and($stdout)->toContain('portable-core-ok');
});
