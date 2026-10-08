# Batch 3 dependency and portability evidence

Batch 3 adopts the Omnibus 3.0 dependency floor and removes universal PCNTL/POSIX requirements from the portable core.

## Resolved dependency floor

The strict PHP 8.4/8.5 QA matrix resolves the released integration floor to:

- `infocyph/uid 6.0`
- `infocyph/cachelayer 4.0`
- `infocyph/dblayer 6.0`
- `infocyph/runwire 2.1.1`

The matrix runs both prefer-lowest and prefer-stable resolution. Composer conflicts reject unsupported CacheLayer, DBLayer, and Runwire generations without making those optional integrations production requirements.

## Production installation

The clean-install lane runs `composer install --no-dev`. On the certified candidate it installed `infocyph/uid 6.0` from the Infocyph stack and did not install CacheLayer, DBLayer, or Runwire.

`composer check-platform-reqs --no-dev` reports UID 6's production platform requirements, including `ext-ctype` and 64-bit PHP. PCNTL/POSIX are absent from the production platform requirement set.

## Genuine no-process-extension execution

`tests/Integration/PortableCoreRuntimeTest.php` runs on GitHub Actions inside an Alpine PHP 8.4 container that installs PHP and Ctype but does not install the PCNTL or POSIX extension packages.

The probe:

- asserts `extension_loaded('pcntl') === false`;
- asserts `extension_loaded('posix') === false`;
- executes the ordinary default `Worker` path successfully;
- verifies explicit `WorkerOptions(handleSignals: true)` fails with an actionable PCNTL capability error;
- verifies the native `WorkerPool` fails with an actionable PCNTL/POSIX capability error.

This is a real runtime without those extensions, not a mock or disabled-function simulation.

## UID 6 fork safety

`tests/Integration/UidForkSafetyTest.php` warms monotonic ULID state, forks, and then generates parent and child IDs at the same fixed timestamp. Parent and child remain distinct, valid canonical 26-character ULIDs. This proves UID 6 resets inherited monotonic state after fork while preserving Omnibus's durable identifier format.

## Integration regression matrix

The existing strict integration suite continues to cover DBLayer batching, writer affinity, transaction/query retry ownership, nested after-commit/rollback callbacks, portable binary storage, CacheLayer token leases, retry lease extension, atomic counters, overlap/rate-limit/circuit behavior, and real Redis/Valkey/Memcached service paths.

## Certification

GitHub Actions run `37651523235` on `cbe521d1f701cd779d7c3669770532c645fdba5b` completed successfully:

- PHP 8.4 analysis: passed.
- PHP 8.5 analysis: passed.
- PHP 8.4 prefer-lowest QA: passed.
- PHP 8.4 prefer-stable QA: passed.
- PHP 8.5 prefer-lowest QA: passed.
- PHP 8.5 prefer-stable QA: passed.
- representative benchmark PHP 8.4: passed.
- representative benchmark PHP 8.5: passed.
- clean install: passed.
- replica writer-affinity: passed.

Batch 3 is complete. Batch 4 is the next implementation gate.
